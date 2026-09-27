<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');

        DB::statement('ALTER TABLE users ALTER COLUMN username TYPE citext USING username::citext');
        DB::statement('ALTER TABLE employees ALTER COLUMN employee_number TYPE citext USING employee_number::citext');
        DB::statement('ALTER TABLE employees ALTER COLUMN name TYPE citext USING name::citext');
        DB::statement('ALTER TABLE employees ALTER COLUMN email TYPE citext USING email::citext');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users ALTER COLUMN username TYPE varchar(255) USING username::text');
        DB::statement('ALTER TABLE employees ALTER COLUMN employee_number TYPE varchar(255) USING employee_number::text');
        DB::statement('ALTER TABLE employees ALTER COLUMN name TYPE varchar(255) USING name::text');
        DB::statement('ALTER TABLE employees ALTER COLUMN email TYPE varchar(255) USING email::text');
    }
};
