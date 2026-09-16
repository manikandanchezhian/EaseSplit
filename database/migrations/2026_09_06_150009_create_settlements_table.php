<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            // upi | cash | other
            $table->string('method')->default('upi');
            $table->string('upi_app')->nullable();
            // initiated | processing | verified | failed
            $table->string('status')->default('initiated');
            $table->string('reference_id')->nullable()->unique();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['from_user_id', 'to_user_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
