import { useEffect, useRef, useState } from 'react';
import { extraTimeEmployeeDetails, importOvertime, overtimeEmployeeDetails, overtimeRows, saveExtraTimeEmployeeDetails, saveOvertimeEmployeeDetails } from '../../services/overtimeApi';
import Button from '../../components/UI/Button';
import Input from '../../components/UI/Input';
import Loading from '../../components/UI/Loading';
import Modal from '../../components/UI/Modal';
import Pagination from '../../components/UI/Pagination';
import Toast from '../../components/UI/Toast';
import CompactAlert from '../../components/UI/CompactAlert';
import PayrollPeriodSelect, { dashboardPayrollPeriodValue, periodSummary } from '../../components/UI/PayrollPeriodSelect';

const formatDate = date => new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' }).format(new Date(`${date}T00:00:00`));
const rupiah = value => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0);
const isEmptyOvertimeTime = value => !value || value === '0' || value === '00:00' || value === '00:00:00';
const hasNoOvertime = (start, end) => isEmptyOvertimeTime(start) && isEmptyOvertimeTime(end);
const time24Pattern = /^(?:[01]\d|2[0-3]):[0-5]\d$/;
const isTime24 = value => time24Pattern.test(value || '');

const sanitizeTime24 = value => {
  const sanitized = value.replace(/[^\d:]/g, '').slice(0, 5);
  if (sanitized.includes(':') || sanitized.length <= 2) return sanitized;
  return `${sanitized.slice(0, 2)}:${sanitized.slice(2)}`;
};

function Time24Input({ label, value, onChange }) {
  const invalid = Boolean(value) && !isTime24(value);
  return <input
    aria-label={label}
    aria-invalid={invalid}
    autoComplete="off"
    className={`w-24 rounded border px-2 py-1 font-mono tabular-nums ${invalid ? 'border-rose-500 bg-rose-50' : 'border-slate-300'}`}
    inputMode="numeric"
    maxLength={5}
    pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]"
    placeholder="HH:mm"
    title="Gunakan format 24 jam HH:mm, contoh 20:00"
    type="text"
    value={value || ''}
    onChange={event => onChange(sanitizeTime24(event.target.value))}
  />;
}

const durationLabel = (start, end) => {
  if (hasNoOvertime(start, end)) return '0 jam';
  if (!start || !end) return 'Lengkapi format HH:mm';
  if (!isTime24(start) || !isTime24(end)) return 'Gunakan format HH:mm';
  const [startHour, startMinute] = start.split(':').map(Number);
  const [endHour, endMinute] = end.split(':').map(Number);
  const difference = (endHour * 60 + endMinute) - (startHour * 60 + startMinute);
  const minutes = difference < 0 ? difference + (24 * 60) : difference;
  return minutes > 0 ? `${Math.floor(minutes / 60)} jam ${minutes % 60} menit` : 'Jam mulai dan selesai tidak boleh sama';
};

const rateHoursFromTime = (start, end) => {
  if (hasNoOvertime(start, end) || !isTime24(start) || !isTime24(end)) return 0;
  const [startHour, startMinute] = start.split(':').map(Number);
  const [endHour, endMinute] = end.split(':').map(Number);
  const difference = (endHour * 60 + endMinute) - (startHour * 60 + startMinute);
  const durationMinutes = difference < 0 ? difference + (24 * 60) : difference;
  const payableMinutes = Math.max(0, durationMinutes - 60);
  const minutes = payableMinutes % 60;
  const decimal = minutes < 15 ? 0 : minutes < 30 ? 0.25 : minutes < 45 ? 0.5 : 0.75;
  return Math.floor(payableMinutes / 60) + decimal;
};

const extraTimeAmount = (row, dailyRate) => {
  if (!row.selected) return 0;
  return row.is_holiday ? 2 * Number(dailyRate || 0) : 25000;
};

export default function Overtime() {
  const fileInput = useRef(null);
  const [period, setPeriod] = useState(dashboardPayrollPeriodValue);
  const [year, month] = period.split('-').map(Number);
  const [branch, setBranch] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [result, setResult] = useState({ data: [], branches: [] });
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState(null);
  const [detailRows, setDetailRows] = useState([]);
  const [overtimeRates, setOvertimeRates] = useState({ workday_rate: 0, holiday_multiplier: 0, daily_rate: 0 });
  const [extraTimeRows, setExtraTimeRows] = useState([]);
  const [extraTimeDailyRate, setExtraTimeDailyRate] = useState(0);
  const [detailLoading, setDetailLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [importing, setImporting] = useState(false);
  const [error, setError] = useState('');
  const [importWarning, setImportWarning] = useState('');
  const [toast, setToast] = useState('');
  const selectedPeriod = periodSummary(year, month);
  const periodLabel = `Periode ${selectedPeriod.label} · ${selectedPeriod.start} – ${selectedPeriod.end}`;

  const load = async (targetPage = page) => {
    setLoading(true); setError('');
    try {
      const response = await overtimeRows({ period: month, year, branch: branch || undefined, search: search || undefined, page: targetPage });
      setResult(response.data);
      if (response.data.meta?.last_page && targetPage > response.data.meta.last_page) setPage(response.data.meta.last_page);
    } catch (e) {
      setResult({ data: [], branches: [] });
      setError(e.response?.data?.message || 'Data lembur tidak dapat dimuat.');
    } finally { setLoading(false); }
  };
  useEffect(() => { load(); }, [period, branch, page]);

  const upload = async event => {
    const file = event.target.files?.[0];
    if (!file) return;
    setImporting(true); setError(''); setImportWarning('');
    try {
      const form = new FormData();
      form.append('period', month);
      form.append('year', year);
      form.append('file', file);
      const response = await importOvertime(form);
      const skipped = response.data.skipped_employees || [];
      const imported = response.data.imported_employees || 0;
      const skippedMessage = skipped.length
        ? skipped.map(item => item.reason).join(' ')
        : '';
      setToast(`${imported} data karyawan berhasil diimpor.`);
      await load(1); setPage(1);
      if (skippedMessage) setImportWarning(skippedMessage);
    } catch (e) {
      const messages = e.response?.data?.errors?.file;
      setError(messages ? messages.join(' ') : (e.response?.data?.message || 'File lembur tidak dapat diimpor.'));
    } finally {
      setImporting(false);
      event.target.value = '';
    }
  };

  const openDetail = async row => {
    if (!result.period) return;
    setSelected(row); setDetailRows([]); setOvertimeRates({ workday_rate: 0, holiday_multiplier: 0, daily_rate: 0 }); setExtraTimeRows([]); setExtraTimeDailyRate(0); setDetailLoading(true); setError('');
    try {
      if (row.extra_time_eligible) {
        const extraTimeResponse = await extraTimeEmployeeDetails(result.period.id, row.employee_id);
        setExtraTimeRows(extraTimeResponse.data.data);
        setExtraTimeDailyRate(extraTimeResponse.data.daily_rate);
      } else {
        const overtimeResponse = await overtimeEmployeeDetails(result.period.id, row.employee_id);
        setSelected(current => ({ ...current, ...overtimeResponse.data.employee }));
        setDetailRows(overtimeResponse.data.data);
        setOvertimeRates(overtimeResponse.data.rates);
      }
    } catch (e) {
      setError(e.response?.data?.message || (row.extra_time_eligible ? 'Rincian Extra Time tidak dapat dimuat.' : 'Rincian lembur tidak dapat dimuat.'));
      setSelected(null);
    } finally { setDetailLoading(false); }
  };

  const updateDetail = (date, field, value) => {
    setError('');
    setDetailRows(rows => rows.map(row => {
      if (row.date !== date) return row;
      const next = { ...row, [field]: value };
      return { ...next, duration_label: durationLabel(next.starts_at, next.ends_at), rate_hours: rateHoursFromTime(next.starts_at, next.ends_at) };
    }));
  };
  const toggleHoliday = (date, isHoliday) => setDetailRows(rows => rows.map(row => row.date === date ? { ...row, is_holiday: isHoliday } : row));
  const updateExtraTime = (date, values) => setExtraTimeRows(rows => rows.map(row => row.date === date ? { ...row, ...values } : row));

  const saveDetail = async () => {
    if (!selected || !result.period) return;
    const invalidRow = detailRows.find(row => !hasNoOvertime(row.starts_at, row.ends_at) && (!isTime24(row.starts_at) || !isTime24(row.ends_at)));
    if (invalidRow) {
      setError(`Jam lembur tanggal ${formatDate(invalidRow.date)} harus menggunakan format 24 jam HH:mm, contoh 20:00.`);
      return;
    }
    const invalidSequence = detailRows.find(row => {
      if (hasNoOvertime(row.starts_at, row.ends_at)) return false;
      const [startHour, startMinute] = row.starts_at.split(':').map(Number);
      const [endHour, endMinute] = row.ends_at.split(':').map(Number);
      return (endHour * 60 + endMinute) === (startHour * 60 + startMinute);
    });
    if (invalidSequence) {
      setError(`Jam mulai dan selesai lembur tanggal ${formatDate(invalidSequence.date)} tidak boleh sama.`);
      return;
    }
    setSaving(true); setError('');
    try {
      await saveOvertimeEmployeeDetails(result.period.id, selected.employee_id, detailRows.map(({ date, starts_at, ends_at, is_holiday }) => {
        const noOvertime = hasNoOvertime(starts_at, ends_at);
        return { date, starts_at: noOvertime ? null : (starts_at || null), ends_at: noOvertime ? null : (ends_at || null), is_holiday };
      }));
      setToast('Rincian lembur berhasil disimpan.'); setSelected(null); await load();
    } catch (e) {
      const messages = e.response?.data?.errors;
      setError(messages ? Object.values(messages).flat().join(' ') : (e.response?.data?.message || 'Rincian lembur tidak dapat disimpan.'));
    } finally { setSaving(false); }
  };
  const saveExtraTime = async () => {
    if (!selected || !result.period || !selected.extra_time_eligible) return;
    setSaving(true); setError('');
    try {
      await saveExtraTimeEmployeeDetails(result.period.id, selected.employee_id, extraTimeRows.map(({ date, selected: checked, is_holiday, notes }) => ({ date, selected: checked, is_holiday, notes: notes || null })));
      setToast('Extra Time berhasil disimpan.'); setSelected(null); await load();
    } catch (e) {
      const messages = e.response?.data?.errors;
      setError(messages ? Object.values(messages).flat().join(' ') : (e.response?.data?.message || 'Extra Time tidak dapat disimpan.'));
    } finally { setSaving(false); }
  };
  const totalRateHours = detailRows.reduce((total, row) => total + Number(row.rate_hours || 0), 0);
  const overtimeAmount = row => row.is_holiday
    ? (Number(row.rate_hours || 0) / 7) * Number(overtimeRates.holiday_multiplier || 0) * Number(overtimeRates.daily_rate || 0)
    : Number(row.rate_hours || 0) * Number(overtimeRates.workday_rate || 0);
  const totalOvertimeAmount = detailRows.reduce((total, row) => total + overtimeAmount(row), 0);
  const totalExtraTime = extraTimeRows.reduce((total, row) => total + extraTimeAmount(row, extraTimeDailyRate), 0);

  return <section className="feature-page overtime-page">
    <div className="mb-5"><h1 className="text-2xl font-bold">Manajemen Lembur & Extra Time</h1><p className="text-sm text-slate-600">{periodLabel} · karyawan biasa menggunakan Lembur, sedangkan Koordinator hanya menggunakan Extra Time.</p></div>
    <div className="operation-toolbar"><p className="operation-toolbar-label">Filter karyawan dan periode</p><div className="grid gap-3 md:grid-cols-4">
      <PayrollPeriodSelect value={period} onChange={event => setPeriod(event.target.value)} />
      <label className="block text-sm font-medium text-slate-700">Cabang<select value={branch} onChange={event => setBranch(event.target.value)} className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"><option value="">Semua Cabang</option>{(result.branches || []).map(value => <option value={value} key={value}>{value}</option>)}</select></label>
      <Input label="Cari Karyawan" placeholder="Nama atau ID karyawan" value={search} onChange={event => setSearch(event.target.value)} onKeyDown={event => { if (event.key === 'Enter') load(); }} />
      <div className="flex items-end gap-2"><Button type="button" className="bg-slate-600" disabled={loading} onClick={load}>Cari</Button><input ref={fileInput} className="hidden" type="file" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onChange={upload}/><Button type="button" disabled={importing} onClick={() => fileInput.current?.click()}>{importing ? 'Mengimpor…' : 'Impor Lembur'}</Button></div>
    </div></div>
    <CompactAlert message={error || importWarning} title={error ? 'Terjadi kesalahan' : 'Sebagian data dilewati'} variant={error ? 'error' : 'warning'} />
    {loading ? <Loading label="Memuat data lembur dan Extra Time..." /> : !result.period ? <div className="rounded-lg border border-dashed p-8 text-center text-slate-600">Belum ada periode absensi untuk periode cut-off yang dipilih.</div> : <div className="overflow-x-auto rounded-lg border bg-white"><table className="min-w-full text-left text-sm"><thead className="bg-slate-50 text-slate-600"><tr><th className="px-4 py-3">ID Karyawan</th><th className="px-4 py-3">Nama Karyawan</th><th className="px-4 py-3">Cabang</th><th className="px-4 py-3">Jenis</th><th className="px-4 py-3">Total</th><th className="px-4 py-3">Terakhir Diperbarui</th></tr></thead><tbody>{result.data.map(row => <tr className="cursor-pointer border-t border-slate-100 hover:bg-sky-50" key={row.employee_id} onClick={() => openDetail(row)} tabIndex="0" onKeyDown={event => { if (event.key === 'Enter') openDetail(row); }}><td className="whitespace-nowrap px-4 py-3 text-slate-600">{row.employee_number}</td><td className="whitespace-nowrap px-4 py-3 font-medium text-sky-700">{row.employee_name}</td><td className="px-4 py-3">{row.branch || '-'}</td><td className="px-4 py-3">{row.extra_time_eligible ? 'Extra Time' : 'Lembur'}</td><td className="px-4 py-3 font-medium">{row.extra_time_eligible ? rupiah(row.extra_time_amount) : <span className="grid gap-1"><span>{Number(row.hours || 0).toFixed(2)} jam</span><span className="text-xs font-normal text-slate-500">{rupiah(row.amount)}</span></span>}</td><td className="whitespace-nowrap px-4 py-3 text-xs text-slate-500">{row.updated_at ? new Date(row.updated_at).toLocaleString('id-ID') : 'Belum disimpan'}</td></tr>)}</tbody></table>{result.data.length === 0 && <p className="p-8 text-center text-slate-500">Tidak ada data kehadiran karyawan yang sesuai filter.</p>}</div>}
    <Modal open={!!selected} title={selected ? `${selected.extra_time_eligible ? 'Extra Time' : 'Rincian Lembur'} - ${selected.employee_name}` : 'Rincian'} onClose={() => !saving && setSelected(null)}>
      {detailLoading ? <Loading label="Memuat rincian..." /> : <div className="space-y-4">
        {!selected?.extra_time_eligible && <>
          <p className="text-sm text-slate-600">Setiap durasi dikurangi 1 jam terlebih dahulu. Sisa menit dikonversi menjadi 0.00, 0.25, 0.50, atau 0.75 sebelum masuk rumus uang lembur.</p>
          <div className="max-h-[50vh] overflow-auto rounded-lg border"><table className="min-w-full text-sm"><thead className="sticky top-0 bg-slate-50 text-slate-600"><tr><th className="px-3 py-2 text-left">Tanggal Kehadiran</th><th className="px-3 py-2 text-left">Jenis Hari</th><th className="px-3 py-2 text-left">Mulai Lembur (HH:mm)</th><th className="px-3 py-2 text-left">Selesai Lembur (HH:mm)</th><th className="px-3 py-2 text-left">Durasi</th><th className="px-3 py-2 text-left">Nilai (-1 jam)</th></tr></thead><tbody>{detailRows.map(row => <tr className="border-t" key={row.date}><td className="whitespace-nowrap px-3 py-2 font-medium">{formatDate(row.date)}</td><td className="px-3 py-2"><select aria-label={`Jenis hari ${row.date}`} value={row.is_holiday ? 'holiday' : 'workday'} onChange={event => toggleHoliday(row.date, event.target.value === 'holiday')} className="rounded border border-slate-300 bg-white px-2 py-1.5 text-xs"><option value="workday">Hari Kerja</option><option value="holiday">Hari Libur</option></select></td><td className="px-3 py-2"><Time24Input label={`Mulai lembur ${row.date} format HH:mm`} value={row.starts_at} onChange={value => updateDetail(row.date, 'starts_at', value)} /></td><td className="px-3 py-2"><Time24Input label={`Selesai lembur ${row.date} format HH:mm`} value={row.ends_at} onChange={value => updateDetail(row.date, 'ends_at', value)} /></td><td className="whitespace-nowrap px-3 py-2">{row.duration_label}</td><td className="whitespace-nowrap px-3 py-2 font-semibold">{Number(row.rate_hours || 0).toFixed(2)}</td></tr>)}</tbody></table>{detailRows.length === 0 && <p className="p-6 text-center text-sm text-slate-500">Tidak ada tanggal kehadiran.</p>}</div>
          <div aria-live="polite" aria-atomic="true" className="grid gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 sm:grid-cols-2"><div><p className="text-xs text-slate-500">Total nilai lembur · diperbarui otomatis</p><p className="mt-1 text-sm font-semibold text-slate-700">{totalRateHours.toFixed(2)} jam</p><p className="mt-1 text-xs text-slate-500">Hari kerja {rupiah(overtimeRates.workday_rate)}/jam · hari libur {Number(overtimeRates.holiday_multiplier || 0).toLocaleString('id-ID')}× uang harian</p></div><div className="sm:text-right"><p className="text-xs text-slate-500">Total nominal dari Master Baseline</p><p className="mt-1 text-base font-bold text-slate-800">{rupiah(totalOvertimeAmount)}</p></div></div>
          <div className="flex justify-end gap-2"><Button type="button" className="bg-slate-200 text-slate-800" disabled={saving} onClick={() => setSelected(null)}>Batal</Button><Button disabled={saving} onClick={saveDetail}>{saving ? 'Menyimpan...' : 'Simpan Lembur'}</Button></div>
        </>}

        {selected?.extra_time_eligible && <>
          <p className="text-sm text-slate-600">Centang tanggal yang memperoleh Extra Time. Hari Kerja bernilai Rp25.000 dan Hari Libur bernilai dua kali Uang Harian.</p>
          <div className="max-h-[50vh] overflow-auto rounded-lg border"><table className="min-w-full text-sm"><thead className="sticky top-0 bg-slate-50 text-slate-600"><tr><th className="px-3 py-2 text-left">Pilih</th><th className="px-3 py-2 text-left">Tanggal</th><th className="px-3 py-2 text-left">Hari</th><th className="px-3 py-2 text-left">Jenis Hari</th><th className="px-3 py-2 text-left">Nominal Extra Time</th><th className="px-3 py-2 text-left">Catatan</th></tr></thead><tbody>{extraTimeRows.map(row => <tr className="border-t" key={row.date}><td className="px-3 py-2"><input aria-label={`Pilih Extra Time ${row.date}`} type="checkbox" checked={Boolean(row.selected)} onChange={event => updateExtraTime(row.date, { selected: event.target.checked })} /></td><td className="whitespace-nowrap px-3 py-2 font-medium">{formatDate(row.date)}</td><td className="whitespace-nowrap px-3 py-2">{row.day}</td><td className="px-3 py-2"><select aria-label={`Jenis hari ${row.date}`} disabled={!row.selected} value={row.is_holiday ? 'holiday' : 'workday'} onChange={event => updateExtraTime(row.date, { is_holiday: event.target.value === 'holiday' })} className="rounded border border-slate-300 px-2 py-1"><option value="workday">Hari Kerja</option><option value="holiday">Hari Libur</option></select></td><td className="whitespace-nowrap px-3 py-2 font-semibold">{rupiah(extraTimeAmount(row, extraTimeDailyRate))}</td><td className="px-3 py-2"><input aria-label={`Catatan ${row.date}`} disabled={!row.selected} maxLength="500" placeholder="Tambahkan catatan" value={row.notes || ''} onChange={event => updateExtraTime(row.date, { notes: event.target.value })} className="w-48 rounded border border-slate-300 px-2 py-1" /></td></tr>)}</tbody></table>{extraTimeRows.length === 0 && <p className="p-6 text-center text-sm text-slate-500">Tidak ada tanggal pada periode penggajian.</p>}</div>
          <p className="text-right text-sm font-semibold text-slate-700">Total Extra Time: {rupiah(totalExtraTime)}</p>
          <div className="flex justify-end gap-2"><Button type="button" className="bg-slate-200 text-slate-800" disabled={saving} onClick={() => setSelected(null)}>Batal</Button><Button disabled={saving} onClick={saveExtraTime}>{saving ? 'Menyimpan...' : 'Simpan Extra Time'}</Button></div>
        </>}
      </div>}
    </Modal>
    <Pagination meta={result.meta} onPage={setPage}/>
    <Toast message={toast} />
  </section>;
}
