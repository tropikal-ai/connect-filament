<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Mockery\MockInterface;
use TropikalAI\ConnectFilament\Services\ControlPlaneClient;

final class SyncCommandTest extends TestCase
{
    public function test_it_synchronizes_every_connected_installation(): void
    {
        $installation = $this->connectedInstallation();
        $this->mock(ControlPlaneClient::class, function (MockInterface $mock) use ($installation): void {
            $mock->shouldReceive('syncCapabilities')->once()->withArgs(
                fn ($candidate): bool => $candidate->is($installation),
            )->andReturn([]);
            $mock->shouldReceive('syncEmbedStatus')->once()->andReturn([]);
        });

        $this->artisan('connect-filament:sync')
            ->expectsOutputToContain('Synchronized 1 TROPIKAL Connect installation')
            ->assertSuccessful();
    }

    public function test_it_fails_delivery_when_a_connected_installation_cannot_sync(): void
    {
        $this->connectedInstallation();
        $this->mock(ControlPlaneClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('syncCapabilities')->once()->andThrow(new \RuntimeException('Reconnect required.'));
            $mock->shouldNotReceive('syncEmbedStatus');
        });

        $this->artisan('connect-filament:sync')
            ->expectsOutputToContain('Reconnect required.')
            ->assertFailed();
    }
}
