<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests\Fixtures;

use Illuminate\Validation\ValidationException;
use TropikalAI\ConnectFilament\Contracts\OwnerResourceAction;

final class ReviewedPostAction implements OwnerResourceAction
{
    public static int $calls = 0;

    public static ?string $failure = null;

    public function execute(array $arguments): array
    {
        $records = [];
        foreach ($arguments['ids'] as $id) {
            if (($arguments['expected_revisions'][$id] ?? null) !== 1) {
                throw ValidationException::withMessages(['expected_revisions' => 'The selection changed.']);
            }
            $records[] = Post::query()->findOrFail($id);
        }
        self::$calls++;
        foreach ($records as $record) {
            $record->update(['title' => 'Reviewed']);
        }

        if (self::$failure === 'validation') {
            throw ValidationException::withMessages(['internal' => 'private-native-source-detail']);
        }
        if (self::$failure === 'runtime') {
            throw new \RuntimeException('private-native-source-detail');
        }

        return $records;
    }
}
