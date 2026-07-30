<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Domain;

use Illuminate\Http\Request;

/**
 * Where the write that is about to fire an event came from.
 *
 * TROPIKAL can change site content through the resource API. Those writes hit
 * the same Eloquent models the observer watches, so without this every
 * TROPIKAL-made edit would be reported back to TROPIKAL as a site change — and
 * a Job that edits on change would then re-trigger itself, forever.
 *
 * The signal is set once, by VerifySignedConnectRequest, on the only requests
 * that can carry a TROPIKAL write. It deliberately reads the request rather
 * than a static: a queued job or console command has no inbound request and is
 * therefore, correctly, site-origin.
 */
final readonly class ChangeOrigin
{
    public const SITE = 'site';

    public const TROPIKAL = 'tropikal';

    /** Request attribute stamped by the signed-request middleware. */
    public const REQUEST_ATTRIBUTE = 'connect_filament_origin';

    public static function stamp(Request $request): void
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, self::TROPIKAL);
    }

    public static function current(?Request $request = null): string
    {
        $request ??= app()->bound('request') ? app('request') : null;

        if (! $request instanceof Request) {
            return self::SITE;
        }

        return $request->attributes->get(self::REQUEST_ATTRIBUTE) === self::TROPIKAL
            ? self::TROPIKAL
            : self::SITE;
    }

    public static function isTropikal(?Request $request = null): bool
    {
        return self::current($request) === self::TROPIKAL;
    }
}
