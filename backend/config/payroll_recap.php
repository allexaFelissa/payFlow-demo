<?php

return [
    'template_path' => resource_path('templates/rekap_payroll.xlsx'),
    'employee_division_path' => resource_path('templates/pembagian_karyawan.xlsx'),

    'division_sheets' => [
        'DPL' => 'DPL',
        'TOPI' => 'TOPI',
        'SRT SBY' => 'SR SBY',
        'SRT SMRG' => 'SR SMRG',
        'SRT CKRG' => 'SR CKRG',
        'SRT PM' => 'SR PM',
        'KORP SMRG' => 'KORP SMRG',
        'KORP BKS' => 'KORP BKS',
    ],

    'category_names' => [
        'back office' => 'Back Office',
        'operation' => 'Operasional',
        'operational' => 'Operasional',
        'ops' => 'Operasional',
        'sga' => 'SGA',
    ],

    /*
     * Pemetaan jabatan dapat ditambahkan tanpa mengubah layanan ekspor.
     * Gunakan nama PT atau "*" untuk aturan yang berlaku pada semua PT.
     */
    'position_categories' => [
        '*' => [
            'Operations Staff' => 'Operasional',
            'Warehouse Staff' => 'Operasional',
            'Finance Staff' => 'Back Office',
            'HR Staff' => 'SGA',
        ],
    ],

    /*
     * Pemetaan kategori langsung berdasarkan nama karyawan. Aturan ini
     * didahulukan dari workbook pembagian karyawan dan pemetaan jabatan.
     */
    'employee_categories' => [
        '*' => [],
    ],

    /*
     * Alias hanya menjembatani perbedaan ejaan antara master karyawan dan
     * workbook pembagian karyawan. Kunci dan nilai dibandingkan tanpa
     * membedakan huruf besar, tanda baca, dan spasi ganda.
     */
    'employee_aliases' => [],

    'fallback_category' => 'Belum Dipetakan',
];
