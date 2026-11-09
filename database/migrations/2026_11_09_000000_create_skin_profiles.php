<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skins__profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('minecraft_uuid', 32)->index();
            $table->string('skin_path')->nullable();
            $table->string('cape_path')->nullable();
            $table->string('variant', 7)->default('classic');
            $table->boolean('skin_public')->default(false);
            $table->boolean('cape_public')->default(false);
            $table->boolean('auto_sync')->default(false);
            $table->timestamps();
        });

        Schema::create('skins__synchronizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('skins__profiles')->cascadeOnDelete();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('connector_token_hash', 64);
            $table->uuid('revision')->unique();
            $table->string('action', 12);
            $table->string('status', 12)->default('pending');
            $table->boolean('managed')->default(false);
            $table->string('target_fingerprint', 64);
            $table->string('last_auto_fingerprint', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['profile_id', 'server_id']);
            $table->index(['server_id', 'status']);
        });

        Schema::create('skins__server_integrations', function (Blueprint $table): void {
            $table->foreignId('server_id')->primary()->constrained('servers')->cascadeOnDelete();
            $table->boolean('sync_enabled')->default(false);
            $table->string('connector_token_hash', 64)->nullable();
            $table->timestamp('polled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skins__server_integrations');
        Schema::dropIfExists('skins__synchronizations');
        Schema::dropIfExists('skins__profiles');
    }
};
