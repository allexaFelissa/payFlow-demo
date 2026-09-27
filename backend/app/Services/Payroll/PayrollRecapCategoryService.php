<?php

namespace App\Services\Payroll;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class PayrollRecapCategoryService
{
    private static array $mappingCache = [];

    public function group(Collection $snapshots, string $pt): array
    {
        $mapping = $this->mapping($pt);
        $groups = [];

        foreach ($snapshots as $snapshot) {
            $payload = $snapshot->payload;
            $employee = $payload['employee'] ?? [];
            $name = (string) ($employee['name'] ?? $snapshot->employee_name);
            $position = (string) ($employee['title'] ?? '');
            $normalizedName = $this->normalizedAlias($pt, $name);
            $assignment = $mapping['employees'][$normalizedName] ?? null;
            $explicitCategory = $this->categoryForEmployee($pt, $name);
            $category = $explicitCategory
                ?? $assignment['category']
                ?? $this->categoryForPosition($pt, $position)
                ?? config('payroll_recap.fallback_category', 'Belum Dipetakan');
            $categoryOrder = $explicitCategory === null
                ? ($assignment['category_order'] ?? PHP_INT_MAX)
                : PHP_INT_MAX;
            $employeeOrder = $assignment['employee_order'] ?? PHP_INT_MAX;

            if (! isset($groups[$category])) {
                $groups[$category] = [
                    'name' => $category,
                    'order' => $categoryOrder,
                    'snapshots' => [],
                ];
            }

            $groups[$category]['order'] = min($groups[$category]['order'], $categoryOrder);
            $groups[$category]['snapshots'][] = [
                'snapshot' => $snapshot,
                'order' => $employeeOrder,
                'name' => $name,
            ];
        }

        foreach ($groups as &$group) {
            usort($group['snapshots'], fn (array $left, array $right) => [
                $left['order'],
                Str::lower($left['name']),
            ] <=> [
                $right['order'],
                Str::lower($right['name']),
            ]);
            $group['snapshots'] = array_column($group['snapshots'], 'snapshot');
        }
        unset($group);

        uasort($groups, fn (array $left, array $right) => [
            $left['order'],
            Str::lower($left['name']),
        ] <=> [
            $right['order'],
            Str::lower($right['name']),
        ]);

        return array_values($groups);
    }

    private function mapping(string $pt): array
    {
        if (isset(self::$mappingCache[$pt])) {
            return self::$mappingCache[$pt];
        }

        $path = config('payroll_recap.employee_division_path');
        $sheetName = config('payroll_recap.division_sheets.'.$pt);
        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException('File pembagian karyawan untuk Rekap Payroll tidak tersedia.');
        }
        if (! is_string($sheetName) || $sheetName === '') {
            throw new RuntimeException('Sheet pembagian karyawan untuk PT '.$pt.' belum dikonfigurasi.');
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);
        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getSheetByName($sheetName);
        if (! $worksheet) {
            $spreadsheet->disconnectWorksheets();
            throw new RuntimeException('Sheet pembagian karyawan '.$sheetName.' tidak ditemukan.');
        }

        $employees = [];
        $pending = [];
        $categoryOrder = 0;
        $employeeOrder = 0;

        for ($row = 1; $row <= $worksheet->getHighestDataRow(); $row++) {
            $columnA = trim((string) $worksheet->getCell('A'.$row)->getValue());
            $columnB = trim((string) $worksheet->getCell('B'.$row)->getValue());
            $columnC = trim((string) $worksheet->getCell('C'.$row)->getValue());
            if ($columnA === '' && $columnB === '' && $columnC === '') {
                continue;
            }

            $marker = $this->categoryMarker($columnA, $columnB, $columnC);
            if ($marker !== null) {
                $categoryOrder++;
                foreach ($pending as $employee) {
                    $employees[$this->normalize($employee['name'])] = [
                        'category' => $marker,
                        'category_order' => $categoryOrder,
                        'employee_order' => $employee['order'],
                    ];
                }
                $pending = [];

                continue;
            }

            [$name, $position] = is_numeric($columnA)
                ? [$columnB, $columnC]
                : [$columnA, $columnB];
            if ($name === '') {
                continue;
            }

            $pending[] = [
                'name' => $name,
                'position' => $position,
                'order' => ++$employeeOrder,
            ];
        }

        $spreadsheet->disconnectWorksheets();

        return self::$mappingCache[$pt] = ['employees' => $employees];
    }

    private function categoryMarker(string $columnA, string $columnB, string $columnC): ?string
    {
        if ($columnC !== '') {
            return null;
        }

        $value = '';
        if (preg_match('/^\s*\d+[a-z]?\s*[\.,)]\s*\S+/iu', $columnA)) {
            $value = $columnA;
        } elseif ($columnA === '' && preg_match('/^\s*\d+[a-z]?\s*[\.,)]\s*\S+/iu', $columnB)) {
            $value = $columnB;
        } elseif ($this->normalize($columnA) === 'ops' && $this->normalize($columnB) === 'operation') {
            $value = $columnB;
        }

        if ($value === '') {
            return null;
        }

        $label = trim((string) preg_replace('/^\s*\d+[a-z]?\s*[\.,)]\s*/iu', '', $value));
        $normalized = $this->normalize($label);

        return config('payroll_recap.category_names.'.$normalized, Str::title(Str::lower($label)));
    }

    private function categoryForPosition(string $pt, string $position): ?string
    {
        $normalizedPosition = $this->normalize($position);
        $mappings = array_merge(
            config('payroll_recap.position_categories.*', []),
            config('payroll_recap.position_categories.'.$pt, [])
        );

        foreach ($mappings as $mappedPosition => $category) {
            if ($this->normalize((string) $mappedPosition) === $normalizedPosition) {
                return (string) $category;
            }
        }

        return null;
    }

    private function categoryForEmployee(string $pt, string $name): ?string
    {
        $normalizedName = $this->normalize($name);
        $mappings = array_merge(
            config('payroll_recap.employee_categories.*', []),
            config('payroll_recap.employee_categories.'.$pt, [])
        );

        foreach ($mappings as $mappedName => $category) {
            if ($this->normalize((string) $mappedName) === $normalizedName) {
                return (string) $category;
            }
        }

        return null;
    }

    private function normalizedAlias(string $pt, string $name): string
    {
        $normalizedName = $this->normalize($name);
        foreach (config('payroll_recap.employee_aliases.'.$pt, []) as $source => $target) {
            if ($this->normalize((string) $source) === $normalizedName) {
                return $this->normalize((string) $target);
            }
        }

        return $normalizedName;
    }

    private function normalize(string $value): string
    {
        $ascii = Str::ascii(Str::lower($value));
        $plain = preg_replace('/[^a-z0-9]+/', ' ', $ascii);

        return trim((string) preg_replace('/\s+/', ' ', $plain));
    }
}
