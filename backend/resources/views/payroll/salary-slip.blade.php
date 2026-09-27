@php
    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    $period = $months[$slip['period']['month']] . ' ' . $slip['period']['year'];
    $date = function (?string $value) use ($months) {
        if (! $value) return null;
        $parts = explode('-', $value);
        return (int) $parts[2] . ' ' . $months[(int) $parts[1]] . ' ' . $parts[0];
    };
    $cutoff = $slip['period']['starts_on'] && $slip['period']['ends_on']
        ? $date($slip['period']['starts_on']) . ' s/d ' . $date($slip['period']['ends_on'])
        : '-';
    $money = fn ($value) => (float) $value == 0.0 ? '-' : 'Rp ' . number_format((float) $value, 0, ',', '.');
    $manualIncome = array_values(array_filter($slip['manual_adjustments'], fn ($item) => $item['type'] === 'income'));
    $manualDeduction = array_values(array_filter($slip['manual_adjustments'], fn ($item) => $item['type'] === 'deduction'));
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Salary Slip {{ $slip['employee']['name'] }}</title>
    <style>
        @page { margin: 18px 22px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #000;
            font-family: Courier, monospace;
            font-size: 9px;
            line-height: 1.25;
        }
        h1 { margin: 0; font-family: Helvetica, sans-serif; font-size: 16px; text-decoration: underline; }
        h2 { margin: 2px 0 13px; font-family: Helvetica, sans-serif; font-size: 13px; font-weight: normal; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        .personal { border-top: 3px double #111; padding-top: 5px; }
        .personal-title { padding: 4px 3px 5px; font-weight: bold; }
        .personal-data td { padding: 2px 3px; }
        .label { width: 26%; font-weight: bold; }
        .colon { width: 2%; }
        .value { width: 22%; }
        .body-grid { margin-top: 23px; table-layout: fixed; }
        .body-grid > tbody > tr > td { width: 50%; padding: 0 16px 0 3px; }
        .body-grid > tbody > tr > td + td { padding-left: 16px; padding-right: 3px; }
        .section-title { height: 28px; font-weight: bold; font-size: 9.5px; }
        .line td { padding: 2px 0; }
        .line .number { width: 8%; text-align: right; padding-right: 7px; }
        .line .description { width: 49%; }
        .line .separator { width: 4%; text-align: center; }
        .line .amount { width: 39%; text-align: right; white-space: nowrap; font-weight: bold; }
        .subline .description { padding-left: 13px; }
        .spacer td { height: 10px; }
        .attendance { margin-top: 14px; border-top: 1px solid #777; border-bottom: 1px solid #777; }
        .attendance td { padding: 4px 8px; text-align: center; }
        .totals { margin-top: 16px; border-top: 3px double #111; border-bottom: 3px double #111; }
        .totals td { padding: 4px 3px; }
        .totals .total-label { width: 22%; font-weight: bold; }
        .totals .total-value { width: 28%; text-align: right; font-weight: bold; }
        .net td { padding-top: 7px; padding-bottom: 7px; font-size: 11px; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $slip['company_name'] }}</h1>
    <h2>Salary Slip</h2>

    <div class="personal">
        <div class="personal-title">Personal Data</div>
        <table class="personal-data">
            <tr>
                <td class="label">Employee Name</td><td class="colon">:</td><td class="value">{{ $slip['employee']['name'] }}</td>
                <td class="label">Payroll Period</td><td class="colon">:</td><td class="value">{{ $period }}</td>
            </tr>
            <tr>
                <td class="label">Employee ID</td><td class="colon">:</td><td class="value">{{ $slip['employee']['number'] ?: '-' }}</td>
                <td class="label">Cut Off Period</td><td class="colon">:</td><td class="value">{{ $cutoff }}</td>
            </tr>
            <tr>
                <td class="label">Title</td><td class="colon">:</td><td class="value">{{ $slip['employee']['title'] ?: '-' }}</td>
                <td class="label">Sisa Cuti</td><td class="colon">:</td><td class="value">-</td>
            </tr>
            <tr>
                <td class="label">Location</td><td class="colon">:</td><td class="value">{{ $slip['employee']['location'] ?: '-' }}</td>
                <td class="label">Family Status</td><td class="colon">:</td><td class="value">{{ $slip['employee']['family_status'] ?: '-' }}</td>
            </tr>
        </table>
    </div>

    <table class="body-grid">
        <tr>
            <td>
                <div class="section-title">A. Fix Income</div>
                <table>
                    <tr class="line"><td class="number">1</td><td class="description">Basic Salary</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['gaji_pokok']) }}</td></tr>
                </table>

                <div class="section-title" style="margin-top: 20px;">B. Other Variable Incomes</div>
                <table>
                    <tr class="line"><td class="number">2</td><td class="description">Tunjangan PPh21</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['tunj_pph21']) }}</td></tr>
                    <tr class="line"><td class="number">3</td><td class="description">Uang Harian</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['uang_harian']) }}</td></tr>
                    <tr class="line"><td class="number">4</td><td class="description">Inctv Kerajinan</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['insentif']) }}</td></tr>
                    <tr class="line"><td class="number">5</td><td class="description">Tunj. Antar Cabang</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['tunj_antar_cabang']) }}</td></tr>
                    <tr class="line"><td class="number">6</td><td class="description">Tunj. Komunikasi</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['tunj_komunikasi']) }}</td></tr>
                    <tr class="line"><td class="number">7</td><td class="description">Tunj. Kost/Obat</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['tunj_kost']) }}</td></tr>
                    <tr class="line"><td class="number">8</td><td class="description">Tunj. Jabatan</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['tunj_jabatan']) }}</td></tr>
                    <tr class="line"><td class="number">9</td><td class="description">Lembur & Perdin</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['overtime_amount']) }}</td></tr>
                    @if (($slip['income']['extra_time_amount'] ?? 0) > 0)
                        <tr class="line"><td class="number">10</td><td class="description">Extra Time</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['extra_time_amount']) }}</td></tr>
                    @endif
                    <tr class="line"><td class="number">{{ ($slip['income']['extra_time_amount'] ?? 0) > 0 ? 11 : 10 }}</td><td class="description">Tunjangan BPJS</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['tunj_bpjs_beban_pt']) }}</td></tr>
                    <tr class="line subline"><td class="number"></td><td class="description">- BPJS Kesehatan PT</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['bpjs_kes_pt']) }}</td></tr>
                    <tr class="line subline"><td class="number"></td><td class="description">- JKK & JKM PT</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['jkk_jkm_pt']) }}</td></tr>
                    <tr class="line subline"><td class="number"></td><td class="description">- JHT & Pensiun PT</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['jht_pens_pt']) }}</td></tr>
                    <tr class="line"><td class="number">{{ ($slip['income']['extra_time_amount'] ?? 0) > 0 ? 12 : 11 }}</td><td class="description">THR Lebaran</td><td class="separator">:</td><td class="amount">{{ $money($slip['income']['thr']) }}</td></tr>
                    @foreach ($manualIncome as $index => $item)
                        <tr class="line"><td class="number">{{ (($slip['income']['extra_time_amount'] ?? 0) > 0 ? 13 : 12) + $index }}</td><td class="description">{{ $item['component_name'] }}</td><td class="separator">:</td><td class="amount">{{ $money($item['amount']) }}</td></tr>
                    @endforeach
                </table>
            </td>
            <td>
                <div class="section-title">C. Deductible</div>
                <table>
                    <tr class="line"><td class="number">1</td><td class="description">Potongan Absensi</td><td class="separator">:</td><td class="amount">{{ $money(($slip['deductions']['potongan_absensi_is_alpha'] ?? false) ? ($slip['deductions']['potongan_terlambat'] ?? 0) : ($slip['deductions']['potongan_absensi'] ?? 0)) }}</td></tr>
                    <tr class="line"><td class="number">2</td><td class="description">Potongan Alpha</td><td class="separator">:</td><td class="amount">{{ $money($slip['deductions']['potongan_alpha'] ?? 0) }}</td></tr>
                    <tr class="spacer"><td colspan="4"></td></tr>
                    <tr class="line"><td class="number">3</td><td class="description">Potongan BPJS</td><td class="separator"></td><td class="amount"></td></tr>
                    <tr class="line subline"><td class="number"></td><td class="description">- Tunj. Perusahaan</td><td class="separator">:</td><td class="amount">{{ $money($slip['deductions']['potongan_tunj_pt']) }}</td></tr>
                    <tr class="line subline"><td class="number"></td><td class="description">- JHT & Pensiun Karyawan</td><td class="separator">:</td><td class="amount">{{ $money($slip['deductions']['potongan_jht_pens']) }}</td></tr>
                    <tr class="line subline"><td class="number"></td><td class="description">- BPJS Kesehatan Karyawan</td><td class="separator">:</td><td class="amount">{{ $money($slip['deductions']['potongan_bpjs_karyawan']) }}</td></tr>
                    <tr class="line"><td class="number">4</td><td class="description">Potongan PPh21</td><td class="separator">:</td><td class="amount">{{ $money($slip['deductions']['potongan_tunj_pph21']) }}</td></tr>
                    <tr class="spacer"><td colspan="4"></td></tr>
                    <tr class="line"><td class="number">5</td><td class="description">Cicilan Pinjaman</td><td class="separator">:</td><td class="amount">{{ $money($slip['deductions']['potongan_pinjaman']) }}</td></tr>
                    @foreach ($manualDeduction as $index => $item)
                        <tr class="line"><td class="number">{{ 6 + $index }}</td><td class="description">{{ $item['component_name'] }}</td><td class="separator">:</td><td class="amount">{{ $money($item['amount']) }}</td></tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    <table class="attendance">
        <tr>
            <td>Hadir: {{ $slip['attendance']['hadir'] }}</td>
            <td>Izin: {{ $slip['attendance']['izin'] }}</td>
            <td>Sakit: {{ $slip['attendance']['sakit'] }}</td>
            <td>Cuti: {{ $slip['attendance']['cuti'] }}</td>
            <td>Alpa: {{ $slip['attendance']['alpha'] }}</td>
        </tr>
    </table>

    <table class="totals">
        <tr>
            <td colspan="2" class="total-label">Penghasilan Bruto</td>
            <td colspan="2" class="total-value">{{ $money($slip['totals']['total_income']) }}</td>
        </tr>
        <tr class="net">
            <td colspan="2">Pendapatan NETT</td>
            <td colspan="2" style="text-align: right;">{{ $money($slip['totals']['take_home_pay']) }}</td>
        </tr>
    </table>
</body>
</html>
