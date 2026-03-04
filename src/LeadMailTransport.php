<?php

namespace LeadM\LeadMail;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class LeadMailTransport extends AbstractTransport
{
    public function __construct(
        protected readonly LeadMailClient $client,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $payload = $this->buildPayload($email);

        $result = $this->client->sendEmail($payload);

        if (isset($result['data']['log_id'])) {
            $message->getOriginalMessage()->getHeaders()->addTextHeader(
                'X-LeadMail-Log-Id',
                (string) $result['data']['log_id'],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(Email $email): array
    {
        $payload = [
            'from' => $this->formatAddress($email->getFrom()[0] ?? null),
            'to' => array_map(fn (Address $a) => $this->formatAddress($a), $email->getTo()),
            'subject' => $email->getSubject() ?? '',
        ];

        if ($email->getCc()) {
            $payload['cc'] = array_map(fn (Address $a) => $this->formatAddress($a), $email->getCc());
        }

        if ($email->getBcc()) {
            $payload['bcc'] = array_map(fn (Address $a) => $this->formatAddress($a), $email->getBcc());
        }

        if ($email->getReplyTo()) {
            $replyTo = $email->getReplyTo()[0];
            $payload['reply_to'] = $this->formatAddress($replyTo);
        }

        if ($email->getHtmlBody()) {
            $payload['html_body'] = $email->getHtmlBody();
        }

        if ($email->getTextBody()) {
            $payload['text_body'] = $email->getTextBody();
        }

        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $attachments[] = [
                'content' => base64_encode($attachment->getBody()),
                'filename' => $attachment->getFilename() ?? 'attachment',
                'mime_type' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
            ];
        }

        if ($attachments) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * @return array{email: string, name?: string}
     */
    protected function formatAddress(?Address $address): array
    {
        if ($address === null) {
            return ['email' => ''];
        }

        $result = ['email' => $address->getAddress()];

        if ($address->getName()) {
            $result['name'] = $address->getName();
        }

        return $result;
    }

    public function __toString(): string
    {
        return 'leadmail';
    }
}
