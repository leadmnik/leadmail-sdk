<?php

namespace LeadM\LeadMail\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use LeadM\LeadMail\Events\LeadMailWebhookReceived;
use LeadM\LeadMail\Exceptions\InvalidWebhookSignatureException;
use LeadM\LeadMail\Webhooks\LeadMailWebhook;

/**
 * Default receiver for LeadMail webhooks, auto-registered by the service
 * provider. Verifies the signatures (and, with LeadMail's HMAC v1, the
 * timestamp and that the delivery wasn't handled before), logs failures,
 * and dispatches a LeadMailWebhookReceived event for custom handling.
 */
class LeadMailWebhookController
{
    public function __invoke(Request $request, LeadMailWebhook $webhook): Response
    {
        try {
            $event = $webhook->parse($request);
        } catch (InvalidWebhookSignatureException) {
            Log::warning('LeadMail webhook rejected: invalid or missing signature.');

            return response('Invalid signature.', 403);
        }

        if ($event->isFailure()) {
            Log::warning('LeadMail reported a failed email.', [
                'log_id' => $event->logId,
                'to' => $event->to,
                'error_code' => $event->errorCode,
                'error_message' => $event->errorMessage,
            ]);
        }

        event(new LeadMailWebhookReceived($event));

        // Handled: a replay of this delivery within its signature's window is refused.
        $webhook->markHandled($request);

        return response()->noContent();
    }
}
