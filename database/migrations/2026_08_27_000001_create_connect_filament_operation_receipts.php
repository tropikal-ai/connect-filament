<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_filament_operation_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 80)->unique();
            $table->foreignId('installation_id')
                ->constrained('connect_filament_installations')
                ->cascadeOnDelete();
            $table->string('idempotency_key', 160);
            $table->string('operation', 160);
            $table->string('resource_slug', 120);
            $table->string('request_hash', 64);
            $table->string('status', 40)->index();
            $table->string('result_ref', 255)->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_json')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['installation_id', 'idempotency_key'],
                'connect_filament_receipt_installation_key_unique',
            );
            $table->index(
                ['installation_id', 'resource_slug', 'created_at'],
                'connect_filament_receipt_resource_created_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_filament_operation_receipts');
    }
};
