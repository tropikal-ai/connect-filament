<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Domain;

use RuntimeException;

final class PublicChatCapabilityException extends RuntimeException
{
    public function __construct(public readonly string $error, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
