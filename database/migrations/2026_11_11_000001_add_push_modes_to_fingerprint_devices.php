<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fingerprint_devices', function (Blueprint $table): void {
            $table->string('connection_mode', 30)->default('server_pull')->after('driver');
            // Only the sha256 hash of the per-device push secret is stored.
            // The plain secret is shown once at generation and never persisted.
            $table->string('push_token_hash', 64)->nullable()->unique()->after('serial_number');
            $table->timestamp('push_token_set_at')->nullable()->after('push_token_hash');
            $table->timestamp('push_last_seen_at')->nullable()->after('push_token_set_at');
            $table->index(['company_id', 'connection_mode']);
        });

        // Push/bridge devices have no server-reachable address.
        Schema::table('fingerprint_devices', function (Blueprint $table): void {
            $table->string('ip_address')->nullable()->change();
            $table->unsignedInteger('port')->nullable()->change();
        });

        Schema::create('fingerprint_device_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('fingerprint_devices')->cascadeOnDelete();
            $table->string('command');
            $table->string('status', 20)->default('pending');
            $table->text('result')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_device_commands');

        Schema::table('fingerprint_devices', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'connection_mode']);
            $table->dropColumn(['connection_mode', 'push_token_hash', 'push_token_set_at', 'push_last_seen_at']);
            $table->string('ip_address')->nullable(false)->change();
            $table->unsignedInteger('port')->nullable(false)->default(4370)->change();
        });
    }
};
