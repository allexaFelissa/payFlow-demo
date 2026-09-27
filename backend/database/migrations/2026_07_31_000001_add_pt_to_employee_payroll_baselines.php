<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'master_data_komponen_gaji';

    private const UNIQUE_INDEX = 'master_data_komponen_gaji_employee_pt_unique';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->string('pt')->nullable()->after('employee_id');
            $table->dropUnique(['employee_id']);
        });

        DB::table(self::TABLE)->orderBy('id')->eachById(function ($baseline) {
            $employee = DB::table('employees')->where('id', $baseline->employee_id)->first();
            $pts = $this->employeePts($employee);
            if ($pts === []) {
                $pts = [config('employees.pts.0')];
            }

            DB::table(self::TABLE)->where('id', $baseline->id)->update(['pt' => $pts[0]]);

            foreach (array_slice($pts, 1) as $pt) {
                $copy = (array) $baseline;
                unset($copy['id']);
                $copy['pt'] = $pt;
                DB::table(self::TABLE)->insert($copy);
            }
        });

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->string('pt')->nullable(false)->change();
            $table->unique(['employee_id', 'pt'], self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        DB::table(self::TABLE)
            ->select('employee_id')
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('employee_id')
            ->each(function ($employeeId) {
                $employee = DB::table('employees')->where('id', $employeeId)->first();
                $preferredPt = $employee?->branch;
                $baselines = DB::table(self::TABLE)->where('employee_id', $employeeId)->orderBy('id')->get();
                $keep = $baselines->firstWhere('pt', $preferredPt) ?? $baselines->first();

                DB::table(self::TABLE)
                    ->where('employee_id', $employeeId)
                    ->where('id', '!=', $keep->id)
                    ->delete();
            });

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropUnique(self::UNIQUE_INDEX);
            $table->dropColumn('pt');
            $table->unique('employee_id');
        });
    }

    private function employeePts(?object $employee): array
    {
        if (! $employee) {
            return [];
        }

        $pts = is_string($employee->pts ?? null)
            ? json_decode($employee->pts, true)
            : ($employee->pts ?? []);
        $pts = is_array($pts) ? $pts : [];
        if ($pts === [] && ! empty($employee->branch)) {
            $pts = [$employee->branch];
        } elseif (! empty($employee->branch) && in_array($employee->branch, $pts, true)) {
            $pts = [
                $employee->branch,
                ...array_values(array_filter($pts, fn ($pt) => $pt !== $employee->branch)),
            ];
        }

        return array_values(array_unique(array_filter($pts)));
    }
};
