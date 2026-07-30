<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use TropikalAI\Connect\Domain\Security\SignedRequest;
use TropikalAI\ConnectFilament\Models\Installation;

/**
 * Hands one change envelope to the Job that is triggered by it.
 *
 * Signed with the installation key rather than an OAuth token: this runs on a
 * queue worker minutes after the owner's session, where a refresh token is the
 * wrong instrument. The signing key is exactly the server-to-server credential
 * this call needs.
 *
 * Retries carry the SAME envelope, event_id included, so a redelivery after a
 * timeout is recognisable as the same event rather than a second one. The
 * receiving end dedupes on it — retrying must not run the owner's Job twice.
 */
class DeliverChangeEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(
        public readonly int|string $installationId,
        public readonly string $routeKey,
        public readonly array $envelope,
    ) {}

    /**
     * A change event is one-way. This POSTs the record to the Job and ignores
     * whatever comes back: the Job never writes to site content, so there is no
     * response worth applying here.
     */
    public function handle(): void
    {
        $installation = Installation::query()->find($this->installationId);
        if (! $installation || ! $installation->isApiReady()) {
            return;
        }

        $base = rtrim((string) config('connect-filament.control_plane.base_url', ''), '/');
        $template = (string) config(
            'connect-filament.control_plane.workflow_routes_path',
            '/api/connect-filament/job-routes',
        );
        if ($base === '') {
            return;
        }

        $path = rtrim($template, '/').'/'.rawurlencode($this->routeKey).'/invoke';
        $body = json_encode(['trigger' => $this->envelope], JSON_THROW_ON_ERROR);

        $response = Http::withHeaders([
            ...SignedRequest::headers(
                (string) $installation->server_signing_key_encrypted,
                (string) $installation->public_id,
                'POST',
                $path,
                '',
                $body,
            ),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])
            ->timeout(max(5, (int) config('connect-filament.control_plane.timeout_seconds', 30)))
            ->withBody($body, 'application/json')
            ->post($base.$path);

        if ($response->failed()) {
            Log::warning('Connect change event delivery failed.', [
                'route_key' => $this->routeKey,
                'event_id' => $this->envelope['event_id'] ?? null,
                'status' => $response->status(),
            ]);

            // Let the queue retry with the same event_id, so the receiving end
            // can tell a redelivery from a second change.
            $this->release($this->backoff[$this->attempts() - 1] ?? 60);
        }
    }
}
