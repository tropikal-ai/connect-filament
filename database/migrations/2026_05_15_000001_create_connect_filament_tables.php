<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_filament_installations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 80)->unique();
            $table->string('status', 40)->default('not_connected')->index();
            $table->string('account_id', 160)->nullable()->index();
            $table->string('account_email', 255)->nullable();
            $table->string('workspace_id', 160)->nullable()->index();
            $table->string('site_url', 500);
            $table->string('control_plane_url', 500)->nullable();
            $table->string('oauth_client_id', 160)->nullable();
            $table->text('oauth_refresh_token_encrypted')->nullable();
            $table->string('oauth_state_hash', 64)->nullable()->index();
            $table->text('oauth_code_verifier_encrypted')->nullable();
            $table->timestamp('oauth_state_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->string('control_plane_installation_id', 160)->nullable()->index();
            $table->text('server_signing_key_encrypted')->nullable();
            $table->json('allowed_resources')->nullable();
            $table->json('resource_permissions')->nullable();
            $table->string('embed_status', 40)->default('not_enabled')->index();
            $table->string('embed_public_id', 160)->nullable();
            $table->string('embed_display_name', 160)->nullable();
            $table->timestamp('embed_enabled_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('connect_filament_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('installation_id')
                ->nullable()
                ->constrained('connect_filament_installations')
                ->nullOnDelete();
            $table->string('resource_slug', 120)->index();
            $table->string('record_id', 120)->nullable();
            $table->string('action', 120)->index();
            $table->json('changes_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['installation_id', 'created_at']);
            $table->index(['resource_slug', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_filament_audit_logs');
        Schema::dropIfExists('connect_filament_installations');
    }
};
