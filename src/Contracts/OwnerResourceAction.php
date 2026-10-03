<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Contracts;

use Illuminate\Database\Eloquent\Model;

interface OwnerResourceAction
{
    /** @param array<string, mixed> $arguments @return array<int, Model> */
    public function execute(array $arguments): array;
}
