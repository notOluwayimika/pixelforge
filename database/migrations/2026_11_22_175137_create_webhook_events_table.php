<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every Pixelforge webhook event we have processed. The unique event_id is what makes a
 * duplicate delivery a no-op: it is inserted in the same transaction that applies the event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->string('provider_generation_id')->nullable()->index();
            $table->unsignedSmallInteger('delivery_attempt')->default(1);
            $table->jsonb('payload');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
