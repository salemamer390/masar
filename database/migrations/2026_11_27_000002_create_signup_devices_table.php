<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('signup_devices')) {
            return;
        }

        Schema::create('signup_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->index();
            $table->string('ip_hash', 64)->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signup_devices');
    }
};
