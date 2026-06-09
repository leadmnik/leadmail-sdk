# LeadMail SDK for Laravel

Laravel package for sending emails and verifying email addresses through the [leadMail](https://mail.leadmagnet.dev) service.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13

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

# Retry transient failures (connection errors, 429/5xx) on idempotent calls.
# Sends are never retried automatically, to avoid duplicate emails.
LEADMAIL_RETRIES=2
LEADMAIL_RETRY_DELAY_MS=200

# Required only if you receive failure webhooks. Must match the webhook secret
# shown for this client app in the leadMail admin dashboard.
LEADMAIL_WEBHOOK_SECRET=whsec_...
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

## Error Handling

Every API failure is raised as a typed exception so you can handle it reliably from the client side. `LeadMail::verifyEmail()` is the exception — it fails open (returns `status: "unknown"`) so a verification outage never blocks sign-ups.

| Exception | When |
|-----------|------|
| `LeadM\LeadMail\Exceptions\LeadMailRequestException` | The service returned an error response (4xx/5xx). |
| `LeadM\LeadMail\Exceptions\LeadMailConnectionException` | The service could not be reached (DNS/connection/timeout). |
| `LeadM\LeadMail\Exceptions\LeadMailException` | Base class for both of the above; catch this to handle any failure. |

```php
use LeadM\LeadMail\Exceptions\LeadMailRequestException;
use LeadM\LeadMail\Exceptions\LeadMailConnectionException;

try {
    LeadMail::sendEmail([...]);
} catch (LeadMailRequestException $e) {
    $e->statusCode();        // e.g. 422, 502
    $e->errorCode();         // e.g. "TRANSPORT_ERROR" (from the API envelope)
    $e->logId();             // the email log id, when available
    $e->validationErrors();  // ['from.email' => ['...']] on a 422
    $e->isValidationError();
    $e->isAuthenticationError();
} catch (LeadMailConnectionException $e) {
    // Service unreachable — safe to queue and retry yourself.
}
```

Idempotent calls (`getDomains`, `verifyEmail`) automatically retry transient failures (connection errors and `429`/`5xx`) with exponential backoff. **Sends are never retried automatically** — a retry after a dropped connection could deliver the same email twice. Retry sends yourself via a queued job if you need to.

## Receiving Failure Webhooks

leadMail POSTs a signed `email.failed` webhook to your app when a send ultimately fails. **This works out of the box — no route or config required.**

### Setup: one command

With `LEADMAIL_TOKEN` set in your `.env`, run:

```bash
php artisan leadmail:install
```

It runs without prompts (safe for Ploi/CI) and:

1. publishes the config,
2. registers your webhook URL with the leadMail service over the API (authenticated by your token), derived from `APP_URL` + the configured webhook route,
3. writes the generated `LEADMAIL_WEBHOOK_SECRET` into your `.env`.

That's it. The SDK **auto-registers the receiving route** (`/webhooks/leadmail` by default), which verifies the HMAC signature and **logs every failure by default**. Nothing else to wire up.

Options:

- `--url=https://your-app.com/custom/path` — override the derived URL.
- `--rotate` — generate and store a fresh signing secret.

The secret is generated server-side and returned only once, at registration.

### Custom handling

To do more than log (e.g. flag a contact, alert a channel), listen for the `LeadMailWebhookReceived` event:

```php
use LeadM\LeadMail\Events\LeadMailWebhookReceived;

Event::listen(function (LeadMailWebhookReceived $received) {
    $event = $received->event;

    if ($event->isFailure()) {
        // $event->logId, $event->errorCode, $event->errorMessage,
        // $event->from, $event->to, $event->subject, $event->metadata
    }
});
```

### Customising or replacing the route

- `LEADMAIL_WEBHOOK_ROUTE` — change the path (keep it in sync with the registered URL via `leadmail:install`).
- Set `leadmail.webhook_route` to `null` to disable auto-registration and handle the request yourself with `LeadMailWebhook::parse($request)` (verifies the signature against the raw body, throws `InvalidWebhookSignatureException` on mismatch; `verify()` returns a boolean instead).

## Multi-Tenancy

If your app uses [stancl/tenancy](https://tenancyforlaravel.com), the SDK automatically includes the current tenant ID in API requests via the `X-Tenant-Id` header. Disable this with:

```env
LEADMAIL_AUTO_TENANT=false
```

## License

MIT
