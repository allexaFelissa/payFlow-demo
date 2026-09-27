<?php

return [
    'jkk_jkm_rates_by_pt' => [
        // JKK follows each company's workplace risk classification. PTs not
        // listed here continue to use the active Master Baseline percentage.
        'TOPI' => (float) env('PAYROLL_JKK_JKM_RATE_TOPI', 1.19),
    ],
    'bpjs_tk' => [
        // JP uses a statutory wage ceiling, while JHT, JKK, and JKM use the full
        // employee BPJS TK base. The combined percentages remain configurable in
        // Master Baseline; these values identify the JP portion of each total.
        'pension_wage_cap' => (float) env('BPJS_PENSION_WAGE_CAP', 11086300),
        'company_pension_rate' => (float) env('BPJS_COMPANY_PENSION_RATE', 2),
        'employee_pension_rate' => (float) env('BPJS_EMPLOYEE_PENSION_RATE', 1),
    ],
];
