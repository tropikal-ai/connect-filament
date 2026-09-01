<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Domain;

final readonly class PublicChatActor
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,39}\z/', $type) !== 1 || trim($id) === '' || strlen($id) > 160) {
            throw new \InvalidArgumentException('Public chat actor is invalid.');
        }
    }
}
