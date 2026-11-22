<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per image the user asked for. Money columns are BIGINT nanodollars (1 = $0.000000001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->index();

            // What the user asked for
            $table->text('prompt');
            $table->string('size', 20);
            $table->string('quality', 20);
            $table->jsonb('reference_images');

            // Our side of the Pixelforge request. The idempotency key is reused on every retry of
            // the POST, so retries can never create a second provider generation for this row.
            $table->uuid('idempotency_key')->unique();
            $table->string('provider_id')->nullable()->unique();
            $table->unsignedSmallInteger('submit_attempts')->default(0);

            // Money, in nanodollars. Estimates are set at creation; actuals only on completion.
            $table->unsignedBigInteger('estimated_typical_charge');
            $table->unsignedBigInteger('estimated_max_charge');
            $table->unsignedBigInteger('provider_cost')->nullable();
            $table->unsignedBigInteger('charge')->nullable();
            $table->jsonb('rates')->nullable();  // snapshot from Pixelforge, fixed at creation
            $table->jsonb('usage')->nullable();  // token counts, known only on success

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->string('image_path')->nullable();          // our stored copy
            $table->text('provider_image_url')->nullable();    // signed, expires after 24h
            $table->timestamp('image_url_expires_at')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
