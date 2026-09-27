import { useEffect, useMemo, useState } from 'react';
import { attendance } from '../../services/attendanceApi';
import { calculatePayroll, completePayroll, createManualAdjustment, deleteManualAdjustment, downloadPayrollRecap, downloadPayrollSalarySlips, downloadPph21Data, payrollCompletionStatus, payrollDrafts, payrollRecapSummary, undoPayrollCompletion, updateManualAdjustment } from '../../services/payrollCalculationApi';
import Button from '../../components/UI/Button';
import Input from '../../components/UI/Input';
import Modal from '../../components/UI/Modal';
import Toast from '../../components/UI/Toast';
import CompactAlert from '../../components/UI/CompactAlert';
import PayrollPeriodSelect, { dashboardPayrollPeriodValue } from '../../components/UI/PayrollPeriodSelect';

const rupiah = value => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0);
const number = value => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(value || 0);
const pageSize = 10;
const normalizeEmployeeSearch = value => String(value || '').toLocaleLowerCase('id-ID').replace(/[^a-z0-9]/g, '');

function Skeleton() { return <div className="overflow-hidden rounded-lg border bg-white"><div className="h-12 animate-pulse bg-slate-100" />{Array.from({ length: 6 }).map((_, index) => <div className="h-14 animate-pulse border-t bg-slate-50" key={index} />)}</div>; }
function SummaryCard({ label, value }) { return <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200"><p className="text-sm text-slate-500">{label}</p><p className="mt-1 text-xl font-semibold text-slate-800">{value}</p></div>; }
function DetailRows({ rows }) { return <div className="payroll-detail-rows">{rows.map(([label, value, plain]) => <div className="payroll-detail-row" key={label}><span>{label}</span><strong>{plain ? value : rupiah(value)}</strong></div>)}</div>; }

function ManualAdjustmentRows({ adjustments = [], type, editable, onEdit, onDelete }) {
  const rows = adjustments.filter(adjustment => adjustment.type === type);
  return rows.length === 0 ? null : <div className="payroll-manual-rows">{rows.map(adjustment => <div className="payroll-detail-row" key={adjustment.id}><span>{adjustment.component_name}<small>Komponen manual</small></span><strong>{rupiah(adjustment.amount)}{editable && <span className="payroll-row-actions"><button type="button" onClick={() => onEdit({ ...adjustment })}>Ubah</button><button type="button" className="is-danger" onClick={() => onDelete(adjustment)}>Hapus</button></span>}</strong></div>)}</div>;
}

export default function PayrollCalculation() {
  const [month, setMonth] = useState(dashboardPayrollPeriodValue);
  const [pt, setPt] = useState('');
  const [employeeQuery, setEmployeeQuery] = useState('');
  const [rows, setRows] = useState([]);
  const [selected, setSelected] = useState(null);
  const [salaryExpanded, setSalaryExpanded] = useState(false);
  const [adjustmentForm, setAdjustmentForm] = useState(null);
  const [savingAdjustment, setSavingAdjustment] = useState(false);
  const [loading, setLoading] = useState(false);
  const [calculating, setCalculating] = useState(false);
  const [error, setError] = useState('');
  const [toast, setToast] = useState('');
  const [sort, setSort] = useState({ key: 'employee_name', direction: 'asc' });
  const [page, setPage] = useState(1);
  const [completed, setCompleted] = useState(false);
  const [completing, setCompleting] = useState(false);
  const [downloadingSlips, setDownloadingSlips] = useState(false);
  const [downloadingRecap, setDownloadingRecap] = useState(false);
  const [downloadingPph21, setDownloadingPph21] = useState(false);
  const [recapForm, setRecapForm] = useState(null);
  const [recapError, setRecapError] = useState('');
  const [periodYear, periodMonth] = month.split('-').map(Number);
  const periodLabel = `Periode ${new Intl.DateTimeFormat('id-ID', { month: 'long' }).format(new Date(periodYear, periodMonth - 2, 1))}-${new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date(periodYear, periodMonth - 1, 1))}`;

  const resolvePeriod = async () => {
    const [year, period] = month.split('-').map(Number);
    const response = await attendance({ year, period });
    if (!response.data.period?.id) throw new Error('Belum ada data absensi untuk periode yang dipilih.');
    return response.data.period.id;
  };
  const loadCompletionStatus = async () => {
    if (!pt) { setCompleted(false); return; }
    try { const periodId = await resolvePeriod(); const response = await payrollCompletionStatus(periodId, pt); setCompleted(response.data.completed); }
    catch { setCompleted(false); }
  };
  useEffect(() => { loadCompletionStatus(); }, [month, pt]);

  const refresh = async () => {
    setLoading(true); setError('');
    try { const periodId = await resolvePeriod(); const response = await payrollDrafts(periodId); setRows(response.data.data || []); setPage(1); }
    catch (e) { setRows([]); setError(e.response?.data?.message || e.message || 'Draf payroll tidak dapat dimuat.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { refresh(); }, [month]);
  const calculate = async () => {
    setCalculating(true); setError('');
    try { const periodId = await resolvePeriod(); const response = await calculatePayroll(periodId, { search: employeeQuery || undefined, pt: pt || undefined }); setRows(response.data.data || []); setPage(1); setToast('Draf payroll berhasil dihitung.'); }
    catch (e) { const messages = e.response?.data?.errors; setError(messages ? Object.values(messages).flat().join(' ') : (e.response?.data?.message || e.message || 'Payroll tidak dapat dihitung.')); }
    finally { setCalculating(false); }
  };
  const toggleCompletion = async () => {
    if (!pt) { setError('Pilih PT terlebih dahulu untuk menyelesaikan payroll.'); return; }
    const action = completed ? 'membatalkan status selesai' : 'menyelesaikan payroll';
    if (!confirm(`Yakin ingin ${action} untuk PT ${pt}?`)) return;
    setCompleting(true); setError('');
    try {
      const periodId = await resolvePeriod();
      const response = completed ? await undoPayrollCompletion({ payroll_period_id: periodId, pt }) : await completePayroll({ payroll_period_id: periodId, pt });
      setCompleted(response.data.completed); setToast(completed ? 'Status selesai payroll dibatalkan dan pinjaman dipulihkan.' : 'Payroll PT berhasil diselesaikan.'); await refresh();
    } catch (e) { const messages = e.response?.data?.errors; setError(messages ? Object.values(messages).flat().join(' ') : (e.response?.data?.message || 'Status payroll tidak dapat diubah.')); }
    finally { setCompleting(false); }
  };
  const downloadSlips = async () => {
    if (!pt || !completed) return;
    setDownloadingSlips(true); setError('');
    try {
      const periodId = await resolvePeriod();
      const response = await downloadPayrollSalarySlips(periodId, { pt, search: employeeQuery || undefined });
      const disposition = response.headers['content-disposition'] || '';
      const encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
      const plainName = disposition.match(/filename="?([^";]+)"?/i)?.[1];
      const fileName = encodedName ? decodeURIComponent(encodedName) : (plainName || `Slip Gaji ${pt}.zip`);
      const url = URL.createObjectURL(response.data);
      const link = document.createElement('a');
      link.href = url;
      link.download = fileName;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
      setToast('ZIP slip gaji berhasil dibuat dan diunduh.');
    } catch (e) {
      let message = e.response?.data?.message || e.message || 'Slip gaji tidak dapat diunduh.';
      if (e.response?.data instanceof Blob) {
        try {
          const payload = JSON.parse(await e.response.data.text());
          const messages = payload.errors ? Object.values(payload.errors).flat() : [];
          message = messages.join(' ') || payload.message || message;
        } catch {}
      }
      setError(message);
    } finally { setDownloadingSlips(false); }
  };
  const downloadPph21 = async () => {
    if (!pt) { setError('Pilih satu PT terlebih dahulu untuk membuat DATA PPh21.'); return; }
    setDownloadingPph21(true); setError('');
    try {
      const periodId = await resolvePeriod();
      const response = await downloadPph21Data(periodId, pt);
      const disposition = response.headers['content-disposition'] || '';
      const encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
      const plainName = disposition.match(/filename="?([^";]+)"?/i)?.[1];
      const fileName = encodedName ? decodeURIComponent(encodedName) : (plainName || `DATA PPh21 - ${pt}.xlsx`);
      const url = URL.createObjectURL(response.data);
      const link = document.createElement('a');
      link.href = url; link.download = fileName; document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
      setToast('Sheet DATA PPh21 berhasil dibuat dan diunduh.');
    } catch (e) {
      let message = e.response?.data?.message || e.message || 'DATA PPh21 tidak dapat dibuat.';
      if (e.response?.data instanceof Blob) {
        try { const payload = JSON.parse(await e.response.data.text()); message = Object.values(payload.errors || {}).flat().join(' ') || payload.message || message; } catch {}
      }
      setError(message);
    } finally { setDownloadingPph21(false); }
  };
  const openRecap = async () => {
    if (!pt || !completed) {
      setError('Payroll harus diselesaikan terlebih dahulu sebelum Rekap Payroll dapat diunduh.');
      return;
    }
    setDownloadingRecap(true); setError(''); setRecapError('');
    try {
      const periodId = await resolvePeriod();
      const response = await payrollRecapSummary(periodId, { pt, search: employeeQuery || undefined });
      const total = Number(response.data.data.total || 0);
      setRecapForm({
        ...response.data.data,
        periodId,
        transfer_amount: total,
        cash_amount: 0,
        transfer_count: response.data.data.employee_count,
        cash_count: 0,
      });
    } catch (e) {
      const messages = e.response?.data?.errors;
      setError(messages ? Object.values(messages).flat().join(' ') : (e.response?.data?.message || e.message || 'Total Rekap Payroll tidak dapat dimuat.'));
    } finally { setDownloadingRecap(false); }
  };
  const downloadRecap = async event => {
    event.preventDefault();
    if (!recapForm) return;
    const transferAmount = Number(recapForm.transfer_amount || 0);
    const cashAmount = Number(recapForm.cash_amount || 0);
    const transferCount = Number(recapForm.transfer_count || 0);
    const cashCount = Number(recapForm.cash_count || 0);
    const employeeCount = Number(recapForm.employee_count || 0);
    if (!Number.isInteger(transferCount) || !Number.isInteger(cashCount)) {
      setRecapError('Jumlah karyawan transfer dan tunai harus berupa bilangan bulat.');
      return;
    }
    if (transferCount + cashCount !== employeeCount) {
      setRecapError(`Jumlah karyawan transfer dan tunai harus sama dengan total ${employeeCount} karyawan.`);
      return;
    }
    if (transferAmount + cashAmount > Number(recapForm.total) + 0.01) {
      setRecapError(`Jumlah transfer dan tunai tidak boleh melebihi total ${rupiah(recapForm.total)}.`);
      return;
    }
    if (transferAmount + cashAmount < Number(recapForm.total) - 0.01) {
      setRecapError(`Jumlah transfer dan tunai harus sama dengan total ${rupiah(recapForm.total)}.`);
      return;
    }
    setDownloadingRecap(true); setError('');
    try {
      const response = await downloadPayrollRecap(recapForm.periodId, { pt, search: employeeQuery || undefined, transfer_amount: transferAmount, cash_amount: cashAmount, transfer_count: transferCount, cash_count: cashCount });
      const disposition = response.headers['content-disposition'] || '';
      const encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
      const plainName = disposition.match(/filename="?([^";]+)"?/i)?.[1];
      const fileName = encodedName ? decodeURIComponent(encodedName) : (plainName || `Rekap Payroll - ${pt}.xlsx`);
      const url = URL.createObjectURL(response.data);
      const link = document.createElement('a');
      link.href = url;
      link.download = fileName;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
      setRecapForm(null);
      setToast('Rekap Payroll berhasil dibuat dan diunduh.');
    } catch (e) {
      let message = e.response?.data?.message || e.message || 'Rekap Payroll tidak dapat diunduh.';
      if (e.response?.data instanceof Blob) {
        try {
          const payload = JSON.parse(await e.response.data.text());
          const messages = payload.errors ? Object.values(payload.errors).flat() : [];
          message = messages.join(' ') || payload.message || message;
        } catch {}
      }
      setRecapError(message);
    } finally { setDownloadingRecap(false); }
  };

  const filtered = useMemo(() => {
    const query = normalizeEmployeeSearch(employeeQuery);
    return rows.filter(row => (!pt || row.pt === pt) && normalizeEmployeeSearch(`${row.employee_name || ''}${row.employee_number || ''}`).includes(query));
  }, [rows, pt, employeeQuery]);
  const sorted = useMemo(() => [...filtered].sort((a, b) => { const left = a[sort.key] ?? ''; const right = b[sort.key] ?? ''; const comparison = typeof left === 'number' ? left - right : String(left).localeCompare(String(right)); return sort.direction === 'asc' ? comparison : -comparison; }), [filtered, sort]);
  const paged = sorted.slice((page - 1) * pageSize, page * pageSize);
  const totalPages = Math.max(1, Math.ceil(sorted.length / pageSize));
  const summary = useMemo(() => filtered.reduce((total, row) => ({ employees: total.employees + 1, income: total.income + Number(row.total_income || row.gross_income || 0), deductions: total.deductions + Number(row.total_deduction || 0), takeHome: total.takeHome + Number(row.take_home_pay || 0) }), { employees: 0, income: 0, deductions: 0, takeHome: 0 }), [filtered]);
  const toggleSort = key => { setSort(current => ({ key, direction: current.key === key && current.direction === 'asc' ? 'desc' : 'asc' })); setPage(1); };
  const sortLabel = key => sort.key === key ? (sort.direction === 'asc' ? ' ↑' : ' ↓') : '';
  const applyCalculation = response => { const calculation = response.data.data; setRows(current => current.map(row => row.id === calculation.id ? calculation : row)); setSelected(calculation); };
  const openCalculation = calculation => { setSalaryExpanded(false); setSelected(calculation); };
  const saveAdjustment = async event => {
    event.preventDefault(); if (!selected || !adjustmentForm) return;
    setSavingAdjustment(true); setError('');
    try {
      const data = { type: adjustmentForm.type, component_name: adjustmentForm.component_name, amount: Number(adjustmentForm.amount) };
      const response = adjustmentForm.id ? await updateManualAdjustment(selected.id, adjustmentForm.id, data) : await createManualAdjustment(selected.id, data);
      applyCalculation(response); setAdjustmentForm(null); setToast('Komponen manual berhasil disimpan.');
    } catch (e) { const messages = e.response?.data?.errors; setError(messages ? Object.values(messages).flat().join(' ') : (e.response?.data?.message || 'Komponen manual tidak dapat disimpan.')); }
    finally { setSavingAdjustment(false); }
  };
  const removeAdjustment = async adjustment => {
    if (!selected || !confirm(`Hapus ${adjustment.component_name}?`)) return;
    setError('');
    try { applyCalculation(await deleteManualAdjustment(selected.id, adjustment.id)); setToast('Komponen manual berhasil dihapus.'); }
    catch (e) { setError(e.response?.data?.message || 'Komponen manual tidak dapat dihapus.'); }
  };

  return <section className="feature-page payroll-calculation-page">
    <div className="mb-5"><h1 className="text-2xl font-bold">Perhitungan Payroll</h1><p className="text-sm text-slate-600">{periodLabel} · Hitung dan periksa draf payroll berdasarkan periode absensi.</p></div>
    <div className="mb-5 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200"><div className="grid gap-3 md:grid-cols-4"><PayrollPeriodSelect value={month} onChange={event => setMonth(event.target.value)} /><label className="block text-sm font-medium text-slate-700">PT<select value={pt} onChange={event => { setPt(event.target.value); setPage(1); }} className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"><option value="">Semua PT</option>{['DPL', 'TOPI', 'KORP BKS', 'KORP SMRG', 'SRT PM', 'SRT CKRG', 'SRT SMRG', 'SRT SBY'].map(value => <option key={value}>{value}</option>)}</select></label><Input label="Nama Karyawan" placeholder="Cari nama karyawan" value={employeeQuery} onChange={event => { setEmployeeQuery(event.target.value); setPage(1); }} /><div className="flex items-end gap-2"><Button disabled={calculating || !month} onClick={calculate}>{calculating ? 'Menghitung…' : 'Hitung Payroll'}</Button><Button type="button" className="bg-slate-600" disabled={loading || !month} onClick={refresh}>Muat Ulang</Button></div></div></div>
    <CompactAlert message={error} />
    <div className="mb-4 flex flex-wrap justify-end gap-2"><Button type="button" className="bg-slate-700" disabled={!pt || downloadingPph21} onClick={downloadPph21}>{downloadingPph21 ? 'Membuat DATA PPh21...' : 'Unduh DATA PPh21'}</Button>{completed && <><Button type="button" className="bg-emerald-700" disabled={downloadingRecap} onClick={openRecap}>{downloadingRecap ? 'Memuat Total...' : 'Unduh Rekap Payroll'}</Button><Button type="button" className="bg-emerald-700" disabled={downloadingSlips} onClick={downloadSlips}>{downloadingSlips ? 'Membuat ZIP…' : 'Unduh Slip Gaji'}</Button></>}<Button type="button" className={completed ? 'bg-amber-600' : ''} disabled={!pt || completing} onClick={toggleCompletion}>{completing ? 'Memproses…' : completed ? 'Batalkan Selesai' : 'Selesai'}</Button></div>
    <div className="mb-5 grid gap-3 md:grid-cols-4"><SummaryCard label="Total Karyawan" value={number(summary.employees)} /><SummaryCard label="Total Pendapatan" value={rupiah(summary.income)} /><SummaryCard label="Total Pengurangan" value={rupiah(summary.deductions)} /><SummaryCard label="Pendapatan Bersih" value={rupiah(summary.takeHome)} /></div>
    {loading ? <Skeleton /> : rows.length === 0 ? <div className="rounded-lg border border-dashed p-8 text-center text-slate-500">Belum ada draf payroll. Pilih periode lalu tekan Hitung Payroll.</div> : <><div className="overflow-x-auto rounded-lg border bg-white"><table className="min-w-full text-left text-sm"><thead className="bg-slate-50 text-slate-600"><tr>{[['employee_name', 'Nama Karyawan'], ['pt', 'PT'], ['total_income', 'Total Pendapatan'], ['total_deduction', 'Total Pengurangan'], ['take_home_pay', 'Pendapatan Bersih']].map(([key, label]) => <th key={key} className="whitespace-nowrap px-4 py-3 font-semibold"><button onClick={() => toggleSort(key)}>{label}{sortLabel(key)}</button></th>)}<th className="px-4 py-3 font-semibold">Status</th><th className="px-4 py-3 font-semibold">Tindakan</th></tr></thead><tbody>{paged.map(row => <tr key={row.id} className="border-t border-slate-100"><td className="whitespace-nowrap px-4 py-3 font-medium">{row.employee_name}</td><td className="px-4 py-3">{row.pt || '-'}</td><td className="whitespace-nowrap px-4 py-3">{rupiah(row.total_income)}</td><td className="whitespace-nowrap px-4 py-3">{rupiah(row.total_deduction)}</td><td className="whitespace-nowrap px-4 py-3 font-semibold">{rupiah(row.take_home_pay)}</td><td className="px-4 py-3"><span className={`rounded px-2 py-1 text-xs font-medium ${row.status === 'completed' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-900'}`}>{row.status === 'completed' ? 'Selesai' : 'Draf'}</span></td><td className="px-4 py-3"><button className="text-sky-700" onClick={() => openCalculation(row)}>Lihat</button></td></tr>)}</tbody></table></div>{totalPages > 1 && <div className="mt-3 flex items-center justify-end gap-3 text-sm"><span className="text-slate-600">{sorted.length} karyawan · halaman {page} dari {totalPages}</span><Button className="bg-slate-600" disabled={page === 1} onClick={() => setPage(page - 1)}>Sebelumnya</Button><Button className="bg-slate-600" disabled={page === totalPages} onClick={() => setPage(page + 1)}>Berikutnya</Button></div>}</>}
    <Modal open={!!selected} wide title="Rincian Payroll" onClose={() => { setSelected(null); setSalaryExpanded(false); setAdjustmentForm(null); }}>
      {selected && <div className="payroll-detail">
        <header className="payroll-detail-employee"><div><p className="payroll-detail-kicker">Karyawan · {selected.pt || 'Tanpa PT'}</p><h3>{selected.employee_name}</h3><p>ID Karyawan {selected.employee_number || '-'}</p></div>{selected.is_editable && <Button type="button" onClick={() => setAdjustmentForm({ type: 'income', component_name: '', amount: '' })}>Ubah Perhitungan</Button>}</header>
        <section className="payroll-attendance" aria-labelledby="attendance-heading"><div className="payroll-section-heading"><p>Rekap periode</p><h3 id="attendance-heading">Ringkasan Absensi</h3></div><div className="payroll-attendance-grid">{[['Hadir', selected.hadir], ['Izin', selected.izin], ['Sakit', selected.sakit], ['Cuti', selected.cuti], ['Alpa', selected.alpha]].map(([label, value]) => <div className={`payroll-attendance-item payroll-attendance-item--${label.toLowerCase()}`} key={label}><span>{label}</span><strong>{number(value)}</strong><small>hari</small></div>)}</div></section>
        <section className="payroll-salary-card"><div className="payroll-section-heading"><p>Komponen utama</p><h3>Komponen Gaji</h3></div><DetailRows rows={[["Gaji Pokok", selected.gaji_pokok], ["Uang Harian", selected.uang_harian], [selected.uses_extra_time ? 'Extra Time' : 'Lembur', selected.uses_extra_time ? selected.extra_time_amount : selected.overtime_amount]]}/>{salaryExpanded && <div className="payroll-salary-more"><DetailRows rows={[["Tunj. Antar Cabang", selected.tunj_antar_cabang], ["Tunj. Komunikasi", selected.tunj_komunikasi], ["Kost / OBT", selected.tunj_kost], ["Tunj. Jabatan", selected.tunj_jabatan], ["Insentif Kerajinan", selected.insentif], ["THR", selected.thr], ["Tunj. PPh21", selected.tunj_pph21]]}/></div>}<button className="payroll-view-more" type="button" aria-expanded={salaryExpanded} onClick={() => setSalaryExpanded(value => !value)}>{salaryExpanded ? 'Tampilkan lebih sedikit' : 'Lihat komponen lainnya'}<span aria-hidden="true">{salaryExpanded ? '↑' : '↓'}</span></button></section>
        <div className="payroll-income-deduction-grid"><section className="payroll-finance-card"><div className="payroll-section-heading"><p>Penambah pendapatan</p><h3>Pendapatan</h3></div><DetailRows rows={[["Tunj BPJS Beban PT", selected.tunj_bpjs_beban_pt], ["BPJS Kesehatan PT", selected.bpjs_kes_pt], ["JKK & JKM PT", selected.jkk_jkm_pt], ["JHT & Pensiun PT", selected.jht_pens_pt]]}/><ManualAdjustmentRows adjustments={selected.manual_adjustments} type="income" editable={selected.is_editable} onEdit={setAdjustmentForm} onDelete={removeAdjustment}/></section>
          <section className="payroll-finance-card payroll-finance-card--deduction"><div className="payroll-section-heading"><p>Pengurang pendapatan</p><h3>Pengurangan</h3></div><DetailRows rows={[['Potongan Absensi', selected.potongan_absensi], ['Potongan Alpha', selected.potongan_alpha], ['Potongan JHT & Pensiun Karyawan', selected.potongan_jht_pens], ['Tunj PT', selected.potongan_tunj_pt], ['BPJS Kesehatan Karyawan', selected.potongan_bpjs_karyawan], ['Potongan Tunj PPh21', selected.potongan_tunj_pph21]]}/>
          <DetailRows rows={[['Potongan Pinjaman', selected.potongan_pinjaman]]}/>
          <ManualAdjustmentRows adjustments={selected.manual_adjustments} type="deduction" editable={selected.is_editable} onEdit={setAdjustmentForm} onDelete={removeAdjustment}/>
          </section></div>
        <footer className="payroll-detail-summary"><div><DetailRows rows={[['Bruto I', selected.gross_i], ['Bruto II', selected.gross_ii], ['Total Pendapatan', selected.total_income], ['Total Pengurangan', selected.total_deduction]]}/></div><div className="payroll-net-pay"><span>Pendapatan Bersih</span><strong>{rupiah(selected.take_home_pay)}</strong><small>Nominal akhir yang diterima karyawan</small></div></footer>
      </div>}
    </Modal>
    <Modal open={!!adjustmentForm} title={adjustmentForm?.id ? 'Ubah Komponen Manual' : 'Ubah Perhitungan'} onClose={() => !savingAdjustment && setAdjustmentForm(null)}>{adjustmentForm && <form className="space-y-4" onSubmit={saveAdjustment}><label className="block text-sm font-medium text-slate-700">Jenis<select required value={adjustmentForm.type} onChange={event => setAdjustmentForm({ ...adjustmentForm, type: event.target.value })} className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"><option value="income">Pendapatan</option><option value="deduction">Pengurangan</option></select></label><Input label="Nama Komponen" required maxLength="150" placeholder="Contoh: Bonus Proyek" value={adjustmentForm.component_name} onChange={event => setAdjustmentForm({ ...adjustmentForm, component_name: event.target.value })}/><Input label="Nominal" type="number" required min="0.01" step="0.01" value={adjustmentForm.amount} onChange={event => setAdjustmentForm({ ...adjustmentForm, amount: event.target.value })}/><div className="flex justify-end gap-2"><Button type="button" className="bg-slate-200 text-slate-800" disabled={savingAdjustment} onClick={() => setAdjustmentForm(null)}>Batal</Button><Button disabled={savingAdjustment}>{savingAdjustment ? 'Menyimpan…' : 'Simpan'}</Button></div></form>}</Modal>
    <Modal open={!!recapForm} title={`Konfirmasi Rekap Payroll ${pt}`} onClose={() => !downloadingRecap && setRecapForm(null)}>{recapForm && <form className="space-y-5" onSubmit={downloadRecap}>{recapError && <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{recapError}</div>}<div className="rounded-xl border border-sky-200 bg-sky-50 p-5 text-center"><p className="text-sm font-medium text-sky-800">Total payroll {recapForm.employee_count} karyawan</p><p className="mt-1 text-3xl font-bold text-sky-950">{rupiah(recapForm.total)}</p></div><p className="text-sm text-slate-600">Tentukan pembagian nominal dan jumlah karyawan sebelum mengunduh. Total nominal dan jumlah karyawan harus sesuai dengan data rekap.</p><div className="grid gap-4 sm:grid-cols-2"><Input label="Nominal Transfer" type="number" required min="0" max={recapForm.total} step="0.01" value={recapForm.transfer_amount} onChange={event => { const total = Number(recapForm.total); const transfer = Math.min(total, Math.max(0, Number(event.target.value || 0))); setRecapError(''); setRecapForm({ ...recapForm, transfer_amount: transfer, cash_amount: total - transfer }); }}/><Input label="Nominal Tunai" type="number" required min="0" max={recapForm.total} step="0.01" value={recapForm.cash_amount} onChange={event => { const total = Number(recapForm.total); const cash = Math.min(total, Math.max(0, Number(event.target.value || 0))); setRecapError(''); setRecapForm({ ...recapForm, cash_amount: cash, transfer_amount: total - cash }); }}/><Input label="Jumlah Karyawan Transfer" type="number" required min="0" max={recapForm.employee_count} step="1" value={recapForm.transfer_count} onChange={event => { const total = Number(recapForm.employee_count); const transfer = Math.min(total, Math.max(0, Math.trunc(Number(event.target.value || 0)))); setRecapError(''); setRecapForm({ ...recapForm, transfer_count: transfer, cash_count: total - transfer }); }}/><Input label="Jumlah Karyawan Tunai" type="number" required min="0" max={recapForm.employee_count} step="1" value={recapForm.cash_count} onChange={event => { const total = Number(recapForm.employee_count); const cash = Math.min(total, Math.max(0, Math.trunc(Number(event.target.value || 0)))); setRecapError(''); setRecapForm({ ...recapForm, cash_count: cash, transfer_count: total - cash }); }}/></div><div className="space-y-2 rounded-lg bg-slate-100 px-4 py-3 text-sm"><div className="flex items-center justify-between"><span>Total nominal pembayaran</span><strong>{rupiah(Number(recapForm.transfer_amount || 0) + Number(recapForm.cash_amount || 0))}</strong></div><div className="flex items-center justify-between"><span>Total karyawan pembayaran</span><strong>{Number(recapForm.transfer_count || 0) + Number(recapForm.cash_count || 0)} dari {recapForm.employee_count}</strong></div></div><div className="flex justify-end gap-2"><Button type="button" className="bg-slate-200 text-slate-800" disabled={downloadingRecap} onClick={() => setRecapForm(null)}>Batal</Button><Button className="bg-emerald-700" disabled={downloadingRecap}>{downloadingRecap ? 'Membuat Rekap...' : 'Konfirmasi & Download'}</Button></div></form>}</Modal>
    <Toast message={toast} />
  </section>;
}
