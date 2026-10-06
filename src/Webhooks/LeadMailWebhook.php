<?php

namespace LeadM\LeadMail\Webhooks;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use LeadM\LeadMail\Exceptions\InvalidWebhookSignatureException;

/**
 * Entry point for receiving LeadMail webhooks in a client application.
 *
 * Resolve it from the container (the secret is wired from config) and call
 * {@see parse()} inside your webhook route:
 *
 *     public function __invoke(Request $request, LeadMailWebhook $webhook)
 *     {
 *         $event = $webhook->parse($request); // throws on a bad signature
 *
 *         if ($event->isFailure()) {
 *             // react to the failed send ($event->logId, $event->errorMessage, ...)
 *         }
 *
 *         $webhook->markHandled($request); // a replay of it is refused from now on
 *
 *         return response()->noContent();
 *     }
 *
 * `X-LeadMail-Signature` must always match. When the request also carries
 * LeadMail's HMAC v1 signature (`X-LM-Signature`, LeadMail since MAIL-20b),
 * that must match too: its timestamp within 300 seconds, compared in
 * constant time, and a delivery id (`X-LeadMail-Delivery`) already handled
 * is refused. Requests from a LeadMail without v1 are checked as before,
 * unless `leadmail.webhook_require_v1` is on.
 */
final class LeadMailWebhook
{
    public const DELIVERY_HEADER = 'X-LeadMail-Delivery';

    public function __construct(
        #[\SensitiveParameter] private readonly string $secret,
        private readonly ?Cache $cache = null,
        private readonly bool $requireV1 = false,
    ) {}

    /**
     * Verify the request's signatures without throwing.
     */
    public function verify(Request $request): bool
    {
        $body = $request->getContent();

        if (! WebhookSignature::isValid($body, $request->header(WebhookSignature::HEADER), $this->secret)) {
            return false;
        }

        $v1 = $request->header(WebhookSignature::V1_HEADER);

        if ($v1 === null || $v1 === '') {
            return ! $this->requireV1;
        }

        $uri = $request->getRequestUri();

        if (! WebhookSignature::isValidV1(
            (string) (parse_url($uri, PHP_URL_PATH) ?: '/'),
            (string) (parse_url($uri, PHP_URL_QUERY) ?? ''),
            $request->header(WebhookSignature::V1_TIMESTAMP_HEADER),
            $body,
            $v1,
            $this->secret,
        )) {
            return false;
        }

        return ! $this->seen($request);
    }

    /**
     * Verify the signature and return the typed event.
     *
     * @throws InvalidWebhookSignatureException when the signature is missing,
     *                                          invalid, replayed, or the body cannot be decoded.
     */
    public function parse(Request $request): WebhookEvent
    {
        if (! $this->verify($request)) {
            throw new InvalidWebhookSignatureException(
                'The LeadMail webhook signature is missing, invalid or replayed.',
            );
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            throw new InvalidWebhookSignatureException(
                'The LeadMail webhook payload could not be decoded.',
            );
        }

        return WebhookEvent::fromArray($payload);
    }

    /**
     * Remember a v1-signed request's delivery id once it was handled, so a
     * replay within the signature's window is refused. LeadMail's own retry
     * of a delivery that failed here carries the same id and still passes.
     */
    public function markHandled(Request $request): void
    {
        $key = $this->deliveryKey($request);

        if ($key !== null) {
            $this->cache?->put($key, true, 2 * WebhookSignature::V1_TOLERANCE_SECONDS + 60);
        }
    }

    private function seen(Request $request): bool
    {
        $key = $this->deliveryKey($request);

        return $key !== null && $this->cache?->has($key) === true;
    }

    private function deliveryKey(Request $request): ?string
    {
        $delivery = $request->header(self::DELIVERY_HEADER);

        if (! is_string($delivery) || $delivery === '' || $request->header(WebhookSignature::V1_HEADER) === null) {
            return null;
        }

        return 'leadmail-webhook:delivery:'.hash('sha256', $delivery);
    }
}
