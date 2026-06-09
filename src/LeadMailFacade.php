<?php

namespace LeadM\LeadMail;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array sendEmail(array $data)
 * @method static string[] getDomains()
 * @method static array verifyEmail(string $email, bool $allowDisposable = false)
 * @method static array registerWebhook(string $url, bool $regenerateSecret = false)
 * @method static array getWebhook()
 * @method static array deleteWebhook()
 *
 * @see \LeadM\LeadMail\LeadMailClient
 */
class LeadMailFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LeadMailClient::class;
    }
}
