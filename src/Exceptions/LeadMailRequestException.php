<?php

namespace LeadM\LeadMail\Exceptions;

use Throwable;

/**
 * Thrown when the LeadMail service returned an HTTP error response (4xx/5xx).
 *
 * The structured details from the API envelope are parsed onto the exception so
 * callers can branch on them without re-parsing the response body themselves.
 */
class LeadMailRequestException extends LeadMailException
{
    /**
     * Build the exception from a decoded error response, tolerating the
     * several envelope shapes the API can emit:
     *
     *  - send errors:       {"success": false, "error": {"code", "message"}, "data": {"log_id"}}
     *  - auth errors:       {"error": "Invalid API token."}
     *  - validation errors: {"message": "...", "errors": {"field": ["..."]}}
     *
     * @param  array<string, mixed>|null  $body
     */
    public static function fromResponse(int $statusCode, ?array $body, ?Throwable $previous = null): self
    {
        $errorCode = null;
        $message = null;
        $validationErrors = [];

        if (is_array($body)) {
            $error = $body['error'] ?? null;

            if (is_array($error)) {
                $errorCode = isset($error['code']) ? (string) $error['code'] : null;
                $message = isset($error['message']) ? (string) $error['message'] : null;
            } elseif (is_string($error)) {
                $message = $error;
            }

            if ($message === null && isset($body['message']) && is_string($body['message'])) {
                $message = $body['message'];
            }

            if (isset($body['errors']) && is_array($body['errors'])) {
                /** @var array<string, array<int, string>> $validationErrors */
                $validationErrors = $body['errors'];
            }
        }

        $logId = is_array($body) && isset($body['data']['log_id'])
            ? (int) $body['data']['log_id']
            : null;

        $message ??= "The LeadMail service returned an error (HTTP {$statusCode}).";

        return new self(
            message: $message,
            statusCode: $statusCode,
            errorCode: $errorCode,
            logId: $logId,
            validationErrors: $validationErrors,
            response: is_array($body) ? $body : null,
            previous: $previous,
        );
    }

    /**
     * Whether the request failed because of input validation (HTTP 422 with field errors).
     */
    public function isValidationError(): bool
    {
        return $this->statusCode() === 422 && $this->validationErrors() !== [];
    }

    /**
     * Whether the request failed because of authentication/authorization (HTTP 401/403).
     */
    public function isAuthenticationError(): bool
    {
        return in_array($this->statusCode(), [401, 403], true);
    }
}
