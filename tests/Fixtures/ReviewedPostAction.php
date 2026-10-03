<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests\Fixtures;

use Illuminate\Validation\ValidationException;
use TropikalAI\ConnectFilament\Contracts\OwnerResourceAction;

final class ReviewedPostAction implements OwnerResourceAction
{
    public static int $calls = 0;

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

        return $records;
    }
}
