<?php

namespace LeadM\LeadMail\Webhooks;

/**
 * Typed representation of a LeadMail webhook payload.
 *
 * Mirrors the payload emitted by the service's SendWebhookJob. Unknown or
 * missing fields degrade gracefully to null so a payload change never fatals
 * the receiver; the untouched payload is always available via {@see $payload}.
 */
final class WebhookEvent
{
    /**
     * @param  array<int, string>  $to
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $event,
        public readonly ?string $deliveryId,
        public readonly ?string $occurredAt,
        public readonly ?int $logId,
        public readonly ?string $tenantId,
        public readonly ?string $status,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly ?string $from,
        public readonly array $to,
        public readonly ?string $subject,
        public readonly ?array $metadata,
        public readonly array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];

        return new self(
            event: (string) ($payload['event'] ?? 'unknown'),
            deliveryId: isset($payload['delivery_id']) ? (string) $payload['delivery_id'] : null,
            occurredAt: isset($payload['occurred_at']) ? (string) $payload['occurred_at'] : null,
            logId: isset($payload['log_id']) ? (int) $payload['log_id'] : null,
            tenantId: isset($payload['tenant_id']) ? (string) $payload['tenant_id'] : null,
            status: isset($payload['status']) ? (string) $payload['status'] : null,
            errorCode: isset($error['code']) ? (string) $error['code'] : null,
            errorMessage: isset($error['message']) ? (string) $error['message'] : null,
            from: isset($payload['from']) ? (string) $payload['from'] : null,
            to: is_array($payload['to'] ?? null) ? array_values($payload['to']) : [],
            subject: isset($payload['subject']) ? (string) $payload['subject'] : null,
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
            payload: $payload,
        );
    }

    /**
     * Whether this event reports a failed send (event "email.failed").
     */
    public function isFailure(): bool
    {
        return $this->event === 'email.failed';
    }
}
