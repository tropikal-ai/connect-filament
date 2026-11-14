<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Contracts;

use TropikalAI\ConnectFilament\Models\NotificationDelivery;

interface NotificationEmailSender
{
    public function send(NotificationDelivery $delivery): void;
}
