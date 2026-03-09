<?php

namespace LeadM\LeadMail\Rules;

use Illuminate\Contracts\Validation\Rule;
use LeadM\LeadMail\LeadMailClient;

class LeadMailVerify implements Rule
{
    protected string $failMessage = 'The :attribute email address could not be verified.';

    public function __construct(
        protected readonly LeadMailClient $client,
        protected readonly bool $allowDisposable = false,
    ) {}

    public function passes($attribute, $value): bool
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->failMessage = 'The :attribute must be a valid email address.';

            return false;
        }

        try {
            $result = $this->client->verifyEmail($value, $this->allowDisposable);

            if (! ($result['data']['valid'] ?? true)) {
                $reason = $result['data']['reason'] ?? 'verification failed';
                $this->failMessage = "The :attribute email address is not deliverable ({$reason}).";

                return false;
            }

            return true;
        } catch (\Throwable) {
            // Fail open — if the verification service is down, allow the email through
            return true;
        }
    }

    public function message(): string
    {
        return $this->failMessage;
    }
}
