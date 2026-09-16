<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->unique()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->string('avatar')->nullable()->after('phone_verified_at');
            $table->string('upi_id')->nullable()->after('avatar');
            $table->string('google_id')->nullable()->unique()->after('upi_id');
            $table->string('otp_code')->nullable()->after('google_id');
            $table->timestamp('otp_expires_at')->nullable()->after('otp_code');
            $table->string('role')->default('user')->after('otp_expires_at');
            $table->timestamp('last_login_at')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'phone_verified_at', 'avatar', 'upi_id',
                'google_id', 'otp_code', 'otp_expires_at', 'role', 'last_login_at',
            ]);
        });
    }
};
