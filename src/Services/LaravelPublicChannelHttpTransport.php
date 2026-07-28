<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Support\Facades\Http;
use TropikalAI\Connect\Application\PublicChannels\Ports\HttpTransport;
use TropikalAI\Connect\Application\PublicChannels\RemoteResponse;

final class LaravelPublicChannelHttpTransport implements HttpTransport
{
    public function send(
        string $method,
        string $url,
        array $headers,
        string $body,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): RemoteResponse {
        $request = Http::timeout($timeoutSeconds)
            ->withOptions(['allow_redirects' => false])
            ->withHeaders($headers);
        if ($body !== '') {
            $request = $request->withBody($body, $headers['Content-Type'] ?? 'application/json');
        }
        $response = $request->send($method, $url);
        $responseBody = $response->body();
        if (strlen($responseBody) > $maxResponseBytes) {
            throw new \RuntimeException('The public-channel response exceeded its safe size limit.');
        }

        return new RemoteResponse($response->status(), $responseBody, $response->headers());
    }
}
