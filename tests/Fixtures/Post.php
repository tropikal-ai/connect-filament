<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $table = 'test_posts';

    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function publish(): void
    {
        $this->forceFill(['published_at' => now()])->save();
    }
}
