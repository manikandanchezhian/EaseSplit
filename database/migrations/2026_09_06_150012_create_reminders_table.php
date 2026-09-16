<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // due_soon | due_today | overdue_3 | overdue_7 | overdue_30
            $table->string('type');
            // push | email | whatsapp
            $table->string('channel');
            $table->timestamp('scheduled_for');
            $table->timestamp('sent_at')->nullable();
            // pending | sent | failed | skipped
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
