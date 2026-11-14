<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Domain;

final readonly class NotificationRequest
{
    public function __construct(
        public string $eventId,
        public string $kind,
        public string $locale,
        public string $phone,
        public bool $whatsapp,
        public bool $sms,
        public array $variables = [],
        public string $smsBody = '',
        public bool $emailAvailable = false,
    ) {
        if (! preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $eventId)
            || ! preg_match('/^[a-z][a-z0-9_.-]{0,119}$/D', $kind)
            || ! in_array($locale, ['de', 'fr', 'it', 'en'], true)
            || ! preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)
            || mb_strlen($smsBody) > 1600
            || count($variables) > 50) {
            throw new \InvalidArgumentException('Invalid customer notification request.');
        }
        foreach ($variables as $key => $value) {
            if ((! is_string($key) && ! is_int($key)) || ! preg_match('/^[A-Za-z0-9_]{1,120}$/D', (string) $key)
                || ! is_string($value) || mb_strlen($value) > 2000) {
                throw new \InvalidArgumentException('Notification variables must be a bounded string map.');
            }
        }
    }

    public function payload(): array
    {
        return ['event_id' => $this->eventId, 'kind' => $this->kind, 'locale' => $this->locale,
            'recipient' => ['phone' => $this->phone],
            'permissions' => ['whatsapp' => $this->whatsapp, 'sms' => $this->sms, 'email' => $this->emailAvailable],
            'variables' => (object) $this->variables, 'sms_body' => $this->smsBody];
    }
}
