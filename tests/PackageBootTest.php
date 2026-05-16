<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use TropikalAI\ConnectFilament\ConnectFilamentPlugin;

final class PackageBootTest extends TestCase
{
    public function test_service_provider_loads_package_assets(): void
    {
        $this->assertSame('TROPIKAL Connect', config('connect-filament.filament.label'));
        $this->assertTrue(Route::has('connect-filament.oauth.connect'));
        $this->assertTrue(Route::has('connect-filament.api.schema'));
        $this->assertTrue(View::exists('connect-filament::filament.resources.installation-resource.pages.dashboard'));
        $this->assertTrue(Schema::hasTable('connect_filament_installations'));
        $this->assertTrue(Schema::hasTable('connect_filament_audit_logs'));
    }

    public function test_filament_plugin_is_named_and_registerable(): void
    {
        $this->assertSame('tropikal-connect-filament', ConnectFilamentPlugin::make()->getId());
        $this->assertSame('TROPIKAL Connect', config('connect-filament.filament.label'));
    }
}
