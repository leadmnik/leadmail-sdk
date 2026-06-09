<?php

namespace LeadM\LeadMail\Webhooks;

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
 *         return response()->noContent();
 *     }
 */
final class LeadMailWebhook
{
    public function __construct(private readonly string $secret) {}

    /**
     * Verify the request signature without throwing.
     */
    public function verify(Request $request): bool
    {
        return WebhookSignature::isValid(
            $request->getContent(),
            $request->header(WebhookSignature::HEADER),
            $this->secret,
        );
    }

    /**
     * Verify the signature and return the typed event.
     *
     * @throws InvalidWebhookSignatureException when the signature is missing,
     *                                          invalid, or the body cannot be decoded.
     */
    public function parse(Request $request): WebhookEvent
    {
        if (! $this->verify($request)) {
            throw new InvalidWebhookSignatureException(
                'The LeadMail webhook signature is missing or invalid.',
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
}
