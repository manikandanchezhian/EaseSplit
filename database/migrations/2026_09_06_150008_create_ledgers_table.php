<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `ledgers` table holds exactly ONE normalized balance row per unordered
     * pair of users (user_a_id is always the smaller id). This is the heart of
     * the Smart Debt Engine: every accepted expense share and every verified
     * settlement nets into this single row instead of storing separate,
     * duplicate "A owes B" / "B owes A" records.
     *
     * Convention: `balance` is signed from user_a's perspective.
     *   balance > 0  => user_b owes user_a `balance`
     *   balance < 0  => user_a owes user_b abs(balance)
     *   balance == 0 => settled up
     */
    public function up(): void
    {
        Schema::create('ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_a_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_b_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('balance', 12, 2)->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['user_a_id', 'user_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledgers');
    }
};
