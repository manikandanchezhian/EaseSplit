<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            // upi_collect | upi_deep_link | payment_gateway | bank_reconciliation
            $table->string('method');
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->decimal('amount', 12, 2);
            // initiated | processing | verified | failed
            $table->string('status')->default('initiated');
            $table->json('raw_response')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['settlement_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_verifications');
    }
};
