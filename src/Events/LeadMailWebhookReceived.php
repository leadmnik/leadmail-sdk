<?php

namespace LeadM\LeadMail\Events;

use LeadM\LeadMail\Webhooks\WebhookEvent;

/**
 * Dispatched after the auto-registered webhook route verifies and parses an
 * incoming LeadMail webhook. Listen for this to add custom handling:
 *
 *     Event::listen(LeadMailWebhookReceived::class, function ($received) {
 *         if ($received->event->isFailure()) {
 *             // ...
 *         }
 *     });
 */
class LeadMailWebhookReceived
{
    public function __construct(public readonly WebhookEvent $event) {}
}
