<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organization_login_logs')) {
            return;
        }

        try {
            Schema::create('organization_login_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->string('session_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('login_at')->nullable()->index();
                $table->timestamp('logout_at')->nullable()->index();
                $table->timestamps();
            });
        } catch (\Throwable $e) {
            if (! Schema::hasTable('organization_login_logs')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_login_logs');
    }
};
