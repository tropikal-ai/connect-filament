<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_filament_staged_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 80)->unique();
            $table->foreignId('installation_id')
                ->constrained('connect_filament_installations')
                ->cascadeOnDelete();
            $table->string('prepare_idempotency_key', 160);
            $table->string('request_hash', 64);
            $table->string('resource_slug', 120);
            $table->string('field_name', 120);
            $table->text('upload_token_encrypted');
            $table->string('status', 40)->index();
            $table->string('disk', 80);
            $table->string('directory', 255);
            $table->string('original_filename', 255)->nullable();
            $table->string('stored_path', 500)->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('input_sha256', 64);
            $table->string('stored_sha256', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['installation_id', 'prepare_idempotency_key'],
                'connect_filament_asset_installation_prepare_unique',
            );
            $table->index(
                ['installation_id', 'resource_slug', 'field_name', 'status'],
                'connect_filament_asset_owner_field_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_filament_staged_assets');
    }
};
