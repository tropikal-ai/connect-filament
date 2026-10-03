<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests\Fixtures;

use TropikalAI\ConnectFilament\Contracts\OwnerSourceValidator;

final class ReviewedSourceValidator implements OwnerSourceValidator
{
    public static bool $mutate = false;

    public function validate(string $absolutePath, array $allowedMimeTypes): array
    {
        $info = @getimagesize($absolutePath);
        if (! $info || ! in_array($info['mime'], $allowedMimeTypes, true)) {
            throw new \InvalidArgumentException('private-validator-detail');
        }

        if (self::$mutate) {
            file_put_contents($absolutePath, 'changed');
        }

        return ['mime_type' => $info['mime'], 'extension' => 'png'];
    }
}
