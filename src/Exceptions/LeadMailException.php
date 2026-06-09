<?php

namespace LeadM\LeadMail\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base exception for every error surfaced by the LeadMail SDK.
 *
 * Catch this to handle any LeadMail failure, or catch the more specific
 * {@see LeadMailRequestException} / {@see LeadMailConnectionException} subtypes.
 */
class LeadMailException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $validationErrors
     * @param  array<string, mixed>|null  $response
     */
    public function __construct(
        string $message,
        protected readonly ?int $statusCode = null,
        protected readonly ?string $errorCode = null,
        protected readonly ?int $logId = null,
        protected readonly array $validationErrors = [],
        protected readonly ?array $response = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The HTTP status code returned by the service, when the failure was a response.
     */
    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * The machine-readable error code from the API envelope (e.g. "TRANSPORT_ERROR").
     */
    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The email log id, when the API associated the failure with a log row.
     */
    public function logId(): ?int
    {
        return $this->logId;
    }

    /**
     * Field-level validation errors from a 422 response.
     *
     * @return array<string, array<int, string>>
     */
    public function validationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * The full decoded response body, when available.
     *
     * @return array<string, mixed>|null
     */
    public function response(): ?array
    {
        return $this->response;
    }
}
