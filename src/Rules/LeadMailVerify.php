<?php

namespace LeadM\LeadMail\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use LeadM\LeadMail\LeadMailClient;

class LeadMailVerify implements ValidationRule
{
    public function __construct(
        protected readonly LeadMailClient $client,
        protected readonly bool $allowDisposable = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $fail('The :attribute must be a valid email address.');

            return;
        }

        try {
            $result = $this->client->verifyEmail($value, $this->allowDisposable);

            if (! ($result['data']['valid'] ?? true)) {
                $reason = $result['data']['reason'] ?? 'verification failed';
                $fail("The :attribute email address is not deliverable ({$reason}).");
            }
        } catch (\Throwable) {
            // Fail open — if the verification service is down, allow the email through
        }
    }
}
