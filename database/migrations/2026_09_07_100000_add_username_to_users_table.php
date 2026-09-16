<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 20)->nullable()->after('name');
            $table->string('email')->nullable()->change();
        });

        // Backfill any existing rows (fresh installs will have none) so the
        // unique index can be added safely.
        DB::table('users')->whereNull('username')->orderBy('id')->cursor()->each(function ($user) {
            $base = Str::slug($user->name, '_') ?: 'user';
            $username = substr($base, 0, 15);
            $suffix = 0;
            while (DB::table('users')->where('username', $username)->where('id', '!=', $user->id)->exists()) {
                $suffix++;
                $username = substr($base, 0, 15).$suffix;
            }
            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 20)->nullable(false)->change();
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
            $table->string('email')->nullable(false)->change();
        });
    }
};
