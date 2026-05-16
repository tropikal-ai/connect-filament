<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Article extends Model
{
    protected $table = 'test_articles';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Article $article): void {
            if (! $article->slug && $article->title) {
                $article->slug = Str::slug((string) $article->title);
            }
        });
    }
}
