<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('upkeep_errors', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 40)->unique();
            $table->string('level', 16);
            $table->string('class');
            $table->text('message');
            $table->string('file', 1024);
            $table->unsignedInteger('line')->nullable();
            $table->string('method', 10)->nullable();
            $table->text('url')->nullable();
            $table->json('frames');
            $table->unsignedBigInteger('occurrences')->default(0);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at')->index();
            $table->timestamps();
        });

        Schema::create('upkeep_agent_state', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->dateTime('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('upkeep_agent_state');
        Schema::dropIfExists('upkeep_errors');
    }
};
