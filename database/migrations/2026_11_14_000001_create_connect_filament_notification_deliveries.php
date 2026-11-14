<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_filament_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('installation_id')->constrained('connect_filament_installations')->cascadeOnDelete();
            $table->string('event_id', 128);
            $table->string('request_hash', 64);
            $table->longText('request_payload');
            $table->text('context');
            $table->string('status', 40)->default('queued')->index();
            $table->string('channel', 20)->nullable();
            $table->string('email_status', 40)->default('pending');
            $table->text('email_claim_token')->nullable();
            $table->timestamp('submission_started_at')->nullable();
            $table->timestamp('processing_until')->nullable();
            $table->timestamps();
            $table->unique(['installation_id', 'event_id'], 'connect_notification_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_filament_notification_deliveries');
    }
};
