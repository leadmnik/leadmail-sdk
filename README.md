# LeadMail SDK for Laravel

Laravel package for sending emails and verifying email addresses through the [leadMail](https://mail.leadmagnet.dev) service.

## Requirements

- PHP 8.2+
- Laravel 11 or 12

## Installation

```bash
composer require leadm/leadmail
```

Publish the config file:

```bash
php artisan vendor:publish --provider="LeadM\LeadMail\LeadMailServiceProvider"
```

## Configuration

Add these to your `.env`:

```env
LEADMAIL_URL=https://mail.leadmagnet.dev
LEADMAIL_TOKEN=lm_your_api_token_here
```

Optional settings:

```env
LEADMAIL_TIMEOUT=30
LEADMAIL_VERIFY_SSL=true
LEADMAIL_AUTO_TENANT=true
```

## Usage

### Send Emails via Mail Driver

Set `leadmail` as your mail driver in `.env`:

```env
MAIL_MAILER=leadmail
```

Then use Laravel's `Mail` facade as usual:

```php
Mail::to('user@example.com')->send(new WelcomeMail());
```

### Send Emails via API

```php
LeadMail::sendEmail([
    'from' => ['email' => 'hello@yourdomain.com', 'name' => 'Your App'],
    'to' => [['email' => 'user@example.com', 'name' => 'User']],
    'subject' => 'Welcome!',
    'html_body' => '<h1>Welcome to our app</h1>',
]);
```

### Verify Email Addresses

```php
$result = LeadMail::verifyEmail('user@example.com');

if ($result['data']['valid']) {
    // Email is deliverable
}
```

### Validation Rule

Use the `leadmail_verify` rule in your form requests:

```php
public function rules(): array
{
    return [
        'email' => ['required', 'email', 'leadmail_verify'],
    ];
}
```

### Get Allowed Sender Domains

```php
$domains = LeadMail::getDomains();
// ['yourdomain.com', 'anotherdomain.com']
```

## Multi-Tenancy

If your app uses [stancl/tenancy](https://tenancyforlaravel.com), the SDK automatically includes the current tenant ID in API requests via the `X-Tenant-Id` header. Disable this with:

```env
LEADMAIL_AUTO_TENANT=false
```

## License

MIT
