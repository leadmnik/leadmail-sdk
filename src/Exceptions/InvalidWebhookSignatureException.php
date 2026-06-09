<?php

namespace LeadM\LeadMail\Exceptions;

/**
 * Thrown when an incoming webhook request fails signature verification,
 * meaning it cannot be trusted to have originated from the LeadMail service.
 */
class InvalidWebhookSignatureException extends LeadMailException {}
