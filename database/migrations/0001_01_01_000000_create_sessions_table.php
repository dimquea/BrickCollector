<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel ships this migration creating users, password_reset_tokens and
 * sessions. BrickCollector has no authentication, so only sessions remains:
 * the session driver is "database" and needs it.
 *
 * user_id stays despite there being no users. Laravel's DatabaseSessionHandler
 * writes that column whenever a Guard is bound in the container, and the
 * framework binds one regardless of our configuration. Dropping the column
 * makes every request fail with "no such column: user_id". It is always null
 * here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->integer('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
