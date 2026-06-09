<?php

namespace LeadM\LeadMail\Exceptions;

use Throwable;

/**
 * Thrown when the LeadMail service could not be reached at all
 * (DNS failure, connection refused, timeout) — i.e. no HTTP response.
 *
 * These are the failures worth retrying; the client already retries them
 * automatically up to the configured limit before throwing.
 */
class LeadMailConnectionException extends LeadMailException
{
    public static function from(Throwable $e): self
    {
        return new self(
            'Could not reach the LeadMail service: '.$e->getMessage(),
            previous: $e,
        );
    }
}
