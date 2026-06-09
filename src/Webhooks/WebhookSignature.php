<?php

namespace LeadM\LeadMail\Webhooks;

/**
 * Computes and verifies the HMAC signature LeadMail attaches to every webhook.
 *
 * The scheme mirrors the service exactly: the signature is
 * "sha256=" . hash_hmac('sha256', <raw request body>, <webhook secret>).
 * Always verify against the raw, unparsed request body.
 */
final class WebhookSignature
{
    /**
     * The header the service sends the signature in.
     */
    public const HEADER = 'X-LeadMail-Signature';

    public static function compute(string $payload, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Constant-time comparison of the expected signature against the received one.
     */
    public static function isValid(string $payload, ?string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals(self::compute($payload, $secret), $signature);
    }
}
