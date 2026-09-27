<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'username')) {
            Schema::table('users', fn (Blueprint $table) => $table->string('username')->nullable()->unique());

            DB::table('users')->orderBy('id')->get()->each(function ($user): void {
                $base = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $user->name ?? 'user')).'.'.$user->id;
                DB::table('users')->where('id', $user->id)->update(['username' => trim($base, '.')]);
            });
        }

        DB::table('users')->where('role', 'administrator')->update(['role' => 'admin']);
        DB::table('users')->where('role', 'hr')->update(['role' => 'staff']);

        $legacyColumns = array_values(array_filter(['name', 'email', 'email_verified_at', 'remember_token'], fn ($column) => Schema::hasColumn('users', $column)));
        if ($legacyColumns !== []) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn($legacyColumns));
        }

        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        // Internal username accounts are intentionally not converted back to public-style accounts.
    }
};
