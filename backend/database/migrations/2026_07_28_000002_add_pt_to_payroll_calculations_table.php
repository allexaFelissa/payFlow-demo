<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_calculations', 'pt')) {
            Schema::table('payroll_calculations', function (Blueprint $table) {
                $table->string('pt', 100)->nullable();
            });
        }

        DB::table('payroll_calculations')->whereNull('pt')->orderBy('id')->chunkById(200, function ($calculations) {
            $branches = DB::table('employees')
                ->whereIn('id', $calculations->pluck('employee_id'))
                ->pluck('branch', 'id');
            foreach ($calculations as $calculation) {
                DB::table('payroll_calculations')->where('id', $calculation->id)->update([
                    'pt' => $branches[$calculation->employee_id] ?? null,
                ]);
            }
        });

        // Give each foreign key a dedicated index before replacing the old unique index.
        if (! Schema::hasIndex('payroll_calculations', 'payroll_calculation_period_fk_index')) {
            Schema::table('payroll_calculations', fn (Blueprint $table) => $table->index('payroll_period_id', 'payroll_calculation_period_fk_index'));
        }
        if (! Schema::hasIndex('payroll_calculations', 'payroll_calculation_employee_fk_index')) {
            Schema::table('payroll_calculations', fn (Blueprint $table) => $table->index('employee_id', 'payroll_calculation_employee_fk_index'));
        }
        if (Schema::hasIndex('payroll_calculations', 'payroll_calculations_payroll_period_id_employee_id_unique')) {
            Schema::table('payroll_calculations', fn (Blueprint $table) => $table->dropUnique(['payroll_period_id', 'employee_id']));
        }
        if (! Schema::hasIndex('payroll_calculations', 'payroll_period_employee_pt_unique')) {
            Schema::table('payroll_calculations', fn (Blueprint $table) => $table->unique(['payroll_period_id', 'employee_id', 'pt'], 'payroll_period_employee_pt_unique'));
        }
        if (! Schema::hasIndex('payroll_calculations', 'payroll_calculation_period_pt_index')) {
            Schema::table('payroll_calculations', fn (Blueprint $table) => $table->index(['payroll_period_id', 'pt'], 'payroll_calculation_period_pt_index'));
        }
    }

    public function down(): void
    {
        Schema::table('payroll_calculations', function (Blueprint $table) {
            $table->dropUnique('payroll_period_employee_pt_unique');
            $table->dropIndex('payroll_calculation_period_pt_index');
            $table->unique(['payroll_period_id', 'employee_id']);
            $table->dropColumn('pt');
        });
    }
};
