<?php

namespace LeadM\LeadMail;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Log;
use LeadM\LeadMail\Exceptions\LeadMailConnectionException;
use LeadM\LeadMail\Exceptions\LeadMailException;
use LeadM\LeadMail\Exceptions\LeadMailRequestException;

class LeadMailClient
{
    protected Client $http;

    public function __construct(
        protected readonly string $baseUrl,
        protected readonly string $token,
        protected readonly int $timeout = 30,
        protected readonly bool $verifySsl = true,
        protected readonly bool $autoTenant = true,
        protected readonly int $retries = 2,
        protected readonly int $retryDelayMs = 200,
        ?HandlerStack $handlerStack = null,
    ) {
        $config = [
            'base_uri' => rtrim($this->baseUrl, '/').'/api/v1/',
            'timeout' => $this->timeout,
            'verify' => $this->verifySsl,
        ];

        if ($handlerStack !== null) {
            $config['handler'] = $handlerStack;
        }

        $this->http = new Client($config);
    }

    /**
     * Send an email through the leadMail service.
     *
     * @param  array{from: array, to: array, cc?: array, bcc?: array, reply_to?: array, subject: string, html_body?: string, text_body?: string, attachments?: array, metadata?: array, options?: array}  $data
     * @return array{success: bool, data: array{log_id: int, status: string}}
     *
     * @throws LeadMailRequestException when the service returns an error response
     * @throws LeadMailConnectionException when the service cannot be reached
     */
    public function sendEmail(array $data): array
    {
        // Sends are never retried automatically: a retry after a 5xx or a
        // dropped connection could deliver the same email twice.
        return $this->request('POST', 'emails/send', [
            'headers' => $this->headers(),
            'json' => $data,
        ], retryable: false);
    }

    /**
     * Get the allowed sender domains for this client app.
     *
     * @return string[]
     *
     * @throws LeadMailRequestException when the service returns an error response
     * @throws LeadMailConnectionException when the service cannot be reached
     */
    public function getDomains(): array
    {
        $data = $this->request('GET', 'domains', [
            'headers' => $this->headers(),
        ], retryable: true);

        return $data['data']['domains'] ?? [];
    }

    /**
     * Register (or update) the URL leadMail should POST failure webhooks to.
     *
     * The service returns the signing secret in plaintext exactly once — on the
     * first registration, or when $regenerateSecret is true. Read it from the
     * returned array's "secret" key when present.
     *
     * @return array{url: string, has_secret: bool, secret?: string}
     *
     * @throws LeadMailRequestException when the service returns an error response
     * @throws LeadMailConnectionException when the service cannot be reached
     */
    public function registerWebhook(string $url, bool $regenerateSecret = false): array
    {
        $response = $this->request('PUT', 'webhook', [
            'headers' => $this->headers(),
            'json' => [
                'url' => $url,
                'regenerate_secret' => $regenerateSecret,
            ],
        ], retryable: false);

        return $response['data'] ?? [];
    }

    /**
     * Get the current webhook configuration (never includes the secret).
     *
     * @return array{url: ?string, has_secret: bool}
     *
     * @throws LeadMailRequestException when the service returns an error response
     * @throws LeadMailConnectionException when the service cannot be reached
     */
    public function getWebhook(): array
    {
        $response = $this->request('GET', 'webhook', [
            'headers' => $this->headers(),
        ], retryable: true);

        return $response['data'] ?? [];
    }

    /**
     * Remove the registered webhook URL and signing secret.
     *
     * @return array{url: ?string, has_secret: bool}
     *
     * @throws LeadMailRequestException when the service returns an error response
     * @throws LeadMailConnectionException when the service cannot be reached
     */
    public function deleteWebhook(): array
    {
        $response = $this->request('DELETE', 'webhook', [
            'headers' => $this->headers(),
        ], retryable: false);

        return $response['data'] ?? [];
    }

    /**
     * Verify an email address through the leadMail service.
     *
     * Fails open: if the verification service is unreachable or errors, the
     * address is treated as valid (status "unknown") so sign-ups are never
     * blocked by a verification outage.
     *
     * @return array{success: bool, data: array{email: string, valid: bool, status: string, reason: ?string, cached: bool}}
     */
    public function verifyEmail(string $email, bool $allowDisposable = false): array
    {
        try {
            return $this->request('POST', 'emails/verify', [
                'headers' => $this->headers(),
                'json' => [
                    'email' => $email,
                    'allow_disposable' => $allowDisposable,
                ],
            ], retryable: true);
        } catch (LeadMailException $e) {
            Log::warning('LeadMail verification failed, failing open', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => true,
                'data' => [
                    'email' => $email,
                    'valid' => true,
                    'status' => 'unknown',
                    'reason' => null,
                    'cached' => false,
                ],
            ];
        }
    }

    /**
     * Perform a request, retrying transient failures, and normalise every
     * failure into a typed {@see LeadMailException}.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws LeadMailException
     */
    protected function request(string $method, string $uri, array $options, bool $retryable = false): array
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->http->request($method, $uri, $options + ['http_errors' => true]);

                return $this->decode((string) $response->getBody());
            } catch (ConnectException $e) {
                if ($retryable && $attempt < $this->retries) {
                    $this->pause($attempt++);

                    continue;
                }

                throw LeadMailConnectionException::from($e);
            } catch (RequestException $e) {
                $response = $e->getResponse();

                if ($response === null) {
                    throw LeadMailConnectionException::from($e);
                }

                $status = $response->getStatusCode();

                if ($retryable && $this->isRetryable($status) && $attempt < $this->retries) {
                    $this->pause($attempt++);

                    continue;
                }

                throw LeadMailRequestException::fromResponse(
                    $status,
                    $this->tryDecode((string) $response->getBody()),
                    $e,
                );
            } catch (GuzzleException $e) {
                throw LeadMailConnectionException::from($e);
            }
        }
    }

    protected function isRetryable(int $status): bool
    {
        return in_array($status, [429, 500, 502, 503, 504], true);
    }

    protected function pause(int $attempt): void
    {
        if ($this->retryDelayMs <= 0) {
            return;
        }

        usleep($this->retryDelayMs * 1000 * (2 ** $attempt));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LeadMailException when the body is not valid JSON
     */
    protected function decode(string $raw): array
    {
        $decoded = $this->tryDecode($raw);

        if ($decoded === null) {
            throw new LeadMailException('Received a malformed response from the LeadMail service.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function tryDecode(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($this->autoTenant && $this->hasTenancy()) {
            $tenantId = $this->resolveTenantId();
            if ($tenantId !== null) {
                $headers['X-Tenant-Id'] = $tenantId;
            }
        }

        return $headers;
    }

    protected function hasTenancy(): bool
    {
        return class_exists(\Stancl\Tenancy\Tenancy::class);
    }

    protected function resolveTenantId(): ?string
    {
        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            return null;
        }

        return (string) tenant()->getTenantKey();
    }
}
