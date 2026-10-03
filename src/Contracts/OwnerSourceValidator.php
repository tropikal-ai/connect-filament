<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Contracts;

/** Application-owned admission of private original bytes; it must not transform the file. */
interface OwnerSourceValidator
{
    /** @return array{mime_type: string, extension: string} */
    public function validate(string $absolutePath, array $allowedMimeTypes): array;
}
