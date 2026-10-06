<?php

namespace LeadM\LeadMail\Webhooks;

use Symfony\Component\HttpFoundation\Request;

/**
 * Computes and verifies the signatures LeadMail attaches to every webhook.
 *
 * - `X-LeadMail-Signature` (every LeadMail version): "sha256=" .
 *   hash_hmac('sha256', <raw request body>, <webhook secret>). No timestamp.
 * - HMAC v1 (LeadMail since MAIL-20b): `X-LM-Peer: leadmail`,
 *   `X-LM-Timestamp` (unix seconds) and `X-LM-Signature: v1=<hex>`, the
 *   HMAC-SHA256 with the webhook secret of the canonical string
 *   "v1\nleadmail\nPOST\n<path>\n<normalised query>\n<timestamp>\n<hex sha256(body)>"
 *   (the Lead Magnet apps' shared signing scheme). The timestamp lets a
 *   receiver refuse a captured request replayed later.
 *
 * Always verify against the raw, unparsed request body.
 */
final class WebhookSignature
{
    /**
     * The header the service sends the body signature in.
     */
    public const HEADER = 'X-LeadMail-Signature';

    public const V1_PEER_HEADER = 'X-LM-Peer';

    public const V1_TIMESTAMP_HEADER = 'X-LM-Timestamp';

    public const V1_HEADER = 'X-LM-Signature';

    public const V1_PEER = 'leadmail';

    /**
     * How far a v1 timestamp may be from this server's clock, in seconds.
     */
    public const V1_TOLERANCE_SECONDS = 300;

    public static function compute(string $payload, #[\SensitiveParameter] string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Constant-time comparison of the expected signature against the received one.
     */
    public static function isValid(string $payload, ?string $signature, #[\SensitiveParameter] string $secret): bool
    {
        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals(self::compute($payload, $secret), $signature);
    }

    /**
     * The HMAC v1 canonical string of a webhook request.
     */
    public static function canonicalV1(string $path, string $query, int $timestamp, string $body): string
    {
        return implode("\n", [
            'v1',
            self::V1_PEER,
            'POST',
            '/'.ltrim($path, '/'),
            Request::normalizeQueryString($query),
            (string) $timestamp,
            hash('sha256', $body),
        ]);
    }

    public static function computeV1(string $path, string $query, int $timestamp, string $body, #[\SensitiveParameter] string $secret): string
    {
        return 'v1='.hash_hmac('sha256', self::canonicalV1($path, $query, $timestamp, $body), $secret);
    }

    /**
     * Check an HMAC v1 signature: the timestamp within
     * {@see V1_TOLERANCE_SECONDS} of `$now`, and a constant-time compare.
     */
    public static function isValidV1(string $path, string $query, ?string $timestamp, string $body, ?string $signature, #[\SensitiveParameter] string $secret, ?int $now = null): bool
    {
        if ($secret === '' || $signature === null || $signature === '' || $timestamp === null || ! ctype_digit($timestamp) || strlen($timestamp) > 12) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > self::V1_TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(self::computeV1($path, $query, (int) $timestamp, $body, $secret), $signature);
    }
}
