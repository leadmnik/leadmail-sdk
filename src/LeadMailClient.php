<?php

namespace LeadM\LeadMail;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class LeadMailClient
{
    protected Client $http;

    public function __construct(
        protected readonly string $baseUrl,
        protected readonly string $token,
        protected readonly int $timeout = 30,
        protected readonly bool $verifySsl = true,
        protected readonly bool $autoTenant = true,
    ) {
        $this->http = new Client([
            'base_uri' => rtrim($this->baseUrl, '/').'/api/v1/',
            'timeout' => $this->timeout,
            'verify' => $this->verifySsl,
        ]);
    }

    /**
     * Send an email through the leadMail service.
     *
     * @param  array{from: array, to: array, cc?: array, bcc?: array, reply_to?: array, subject: string, html_body?: string, text_body?: string, attachments?: array, metadata?: array, options?: array}  $data
     * @return array{success: bool, data: array{log_id: int, status: string}}
     *
     * @throws GuzzleException
     */
    public function sendEmail(array $data): array
    {
        $response = $this->http->post('emails/send', [
            'headers' => $this->headers(),
            'json' => $data,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get the allowed sender domains for this client app.
     *
     * @return string[]
     *
     * @throws GuzzleException
     */
    public function getDomains(): array
    {
        $response = $this->http->get('domains', [
            'headers' => $this->headers(),
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return $data['data']['domains'] ?? [];
    }

    /**
     * Verify an email address through the leadMail service.
     *
     * @return array{success: bool, data: array{email: string, valid: bool, status: string, reason: ?string, cached: bool}}
     */
    public function verifyEmail(string $email, bool $allowDisposable = false): array
    {
        try {
            $response = $this->http->post('emails/verify', [
                'headers' => $this->headers(),
                'json' => [
                    'email' => $email,
                    'allow_disposable' => $allowDisposable,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
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
