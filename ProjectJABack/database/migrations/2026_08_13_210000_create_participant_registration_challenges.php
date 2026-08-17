<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participant_registration_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('persona_id')->nullable()->constrained('personas')->cascadeOnDelete();
            $table->string('identifier_hash', 64)->index();
            $table->string('otp_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->string('verification_token_hash', 64)->nullable();
            $table->unique('verification_token_hash', 'prc_verification_token_hash_uq');
            $table->timestamp('verification_expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_registration_challenges');
    }
};
