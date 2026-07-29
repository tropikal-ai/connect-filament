<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A business model whose own name trips the server-only-key guard: the derived
 * slug is "booking_tokens", which contains the "token" marker. Discovery must
 * skip it rather than throw, so that one such model cannot take down every
 * caller of discover().
 */
class BookingToken extends Model
{
    protected $table = 'test_booking_tokens';

    protected $guarded = [];
}
