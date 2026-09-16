<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            // monthly | weekly
            $table->string('period_type')->default('monthly');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->decimal('total_paid', 12, 2)->default(0);
            $table->decimal('total_owed', 12, 2)->default(0);
            $table->decimal('total_received', 12, 2)->default(0);
            $table->json('category_breakdown')->nullable();
            $table->json('friend_breakdown')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'group_id', 'period_type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_snapshots');
    }
};
