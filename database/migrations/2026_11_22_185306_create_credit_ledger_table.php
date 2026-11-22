<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only credit ledger. A user's balance is SUM(amount); rows are never updated or deleted.
 * Amounts are signed nanodollars: purchases and releases are positive, holds and charges negative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->bigInteger('amount');
            $table->timestamp('created_at');

            $table->index(['user_id', 'id']);
            // A generation gets at most one hold, one release and one charge, enforced by the database
            // rather than by hoping every caller runs exactly once. Purchases have no generation (NULLs
            // never collide in a Postgres unique index).
            $table->unique(['generation_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
    }
};
