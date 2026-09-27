import { useEffect, useMemo, useState } from 'react';
import { clearEmployeeLoan, employeeLoanHistory, employees, removeEmployee, saveEmployee } from '../../services/employeeApi';
import Button from '../../components/UI/Button';
import Input from '../../components/UI/Input';
import Modal from '../../components/UI/Modal';
import Pagination from '../../components/UI/Pagination';
import Toast from '../../components/UI/Toast';
import CompactAlert from '../../components/UI/CompactAlert';
import { useAuth } from '../../context/AuthContext';

const blank = { employee_number: '', name: '', department: '', branch: '', pts: [], active: true, loan_amount: '', loan_balance: '', loan_installment: '', loan_start_date: '', loan_notes: '' };
const rupiah = value => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value || 0));
const initials = name => name?.split(' ').slice(0, 2).map(word => word[0]).join('').toUpperCase() || '—';
const tanggal = value => value ? new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${value}T00:00:00`)) : '—';
const tanggalWaktu = value => value ? new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value)) : '—';
const periodePayroll = row => new Intl.DateTimeFormat('id-ID', { month: 'short', year: 'numeric' }).format(new Date(row.period_year, row.period_month - 1, 1));
const control = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-100';

function Icon({ name, className = 'h-5 w-5' }) {
  const paths = {
    search: <><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></>, users: <><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></>,
    edit: <><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></>, plus: <path d="M12 5v14M5 12h14"/>,
    briefcase: <><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18"/></>,
    wallet: <><path d="M20 7V6a2 2 0 0 0-2-2H5a3 3 0 0 0 0 6h15v10H5a3 3 0 0 1-3-3V7"/><path d="M16 14h2"/></>,
  };
  return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className={className}>{paths[name]}</svg>;
}
function Detail({ label, value }) { return <div className="employee-detail-item"><dt>{label === 'Divisi' ? 'PT' : label}</dt><dd>{value || '—'}</dd></div>; }
function FormSection({ title, children }) { return <fieldset><legend className="mb-3 text-sm font-bold uppercase tracking-wide text-slate-500">{title}</legend><div className="grid gap-4 rounded-xl border border-slate-200 bg-slate-50/50 p-4 md:grid-cols-2">{children}</div></fieldset>; }
function Field({ label, children, className = '' }) { return <label className={`block text-sm font-medium text-slate-700 ${className}`}><span className="mb-1 block">{label}</span>{children}</label>; }

export default function Employees() {
  const { isAdmin } = useAuth();
  const [rows, setRows] = useState([]), [meta, setMeta] = useState();
  const [search, setSearch] = useState(''), [pt, setPt] = useState(''), [page, setPage] = useState(1);
  const division = pt;
  const [selectedId, setSelectedId] = useState(), [form, setForm] = useState(null), [loanForm, setLoanForm] = useState(null), [loading, setLoading] = useState(true);
  const [loanAmountLocked, setLoanAmountLocked] = useState(false);
  const [error, setError] = useState(''), [toast, setToast] = useState('');
  const [formError, setFormError] = useState(''), [formErrors, setFormErrors] = useState({}), [saving, setSaving] = useState(false);
  const [loanHistory, setLoanHistory] = useState([]), [historyLoading, setHistoryLoading] = useState(false), [historyError, setHistoryError] = useState('');
  const selected = useMemo(() => rows.find(row => row.id === selectedId) || rows[0], [rows, selectedId]);

  const load = async () => {
    setLoading(true); setError('');
    try { const response = await employees({ search: search || undefined, branch: pt || undefined, page }); setRows(response.data.data); setMeta(response.data.meta); setSelectedId(current => response.data.data.some(row => row.id === current) ? current : response.data.data[0]?.id); }
    catch { setError('Data karyawan tidak dapat dimuat.'); } finally { setLoading(false); }
  };
  useEffect(() => { const timer = setTimeout(load, 250); return () => clearTimeout(timer); }, [search, pt, page]);
  useEffect(() => {
    if (!isAdmin || !selected?.id) { setLoanHistory([]); return; }
    let active = true;
    setHistoryLoading(true); setHistoryError('');
    employeeLoanHistory(selected.id)
      .then(response => { if (active) setLoanHistory(response.data.data || []); })
      .catch(() => { if (active) { setLoanHistory([]); setHistoryError('Riwayat pinjaman tidak dapat dimuat.'); } })
      .finally(() => { if (active) setHistoryLoading(false); });
    return () => { active = false; };
  }, [isAdmin, selected?.id]);

  const choosePt = value => { setPt(value); setPage(1); };
  const openForm = employee => {
    setFormError(''); setFormErrors({});
    setForm({
      ...employee,
      pts: employee.pts?.length ? employee.pts : [employee.branch].filter(Boolean),
    });
  };
  const openLoanForm = employee => {
    setFormError(''); setFormErrors({});
    const hasActiveLoan = Number(employee.loan_balance) > 0;
    setLoanAmountLocked(hasActiveLoan);
    setLoanForm({
      ...employee,
      pts: employee.pts?.length ? employee.pts : [employee.branch].filter(Boolean),
      loan_amount: hasActiveLoan ? employee.loan_amount : '',
      loan_balance: hasActiveLoan ? employee.loan_balance : '',
      loan_installment: hasActiveLoan ? employee.loan_installment : '',
      loan_start_date: hasActiveLoan ? employee.loan_start_date : '',
      loan_notes: hasActiveLoan ? employee.loan_notes : '',
    });
  };
  const submit = async event => {
    event.preventDefault(); setFormError(''); setFormErrors({}); setSaving(true);
    try { const selectedPts = form.pts || []; const primaryPt = selectedPts.includes(form.branch) ? form.branch : selectedPts[0]; const completeForm = { ...form, pts: selectedPts, branch: primaryPt, department: primaryPt }; const { loan_balance: _systemLoanBalance, ...adminForm } = completeForm; const normalizedAdminForm = { ...adminForm, loan_amount: adminForm.loan_amount === '' ? 0 : adminForm.loan_amount, loan_installment: adminForm.loan_installment === '' ? 0 : adminForm.loan_installment }; const staffCreateForm = { employee_number: completeForm.employee_number, name: completeForm.name, pts: completeForm.pts, branch: completeForm.branch, department: completeForm.department, active: completeForm.active }; const payload = isAdmin ? normalizedAdminForm : (completeForm.id ? { id: completeForm.id, active: completeForm.active } : staffCreateForm); const response = await saveEmployee(payload); setForm(null); setToast('Data karyawan berhasil disimpan.'); setSelectedId(response.data.data.id); await load(); }
    catch (e) { const validationErrors = e.response?.data?.errors || {}; const messages = [...new Set(Object.values(validationErrors).flat())]; setFormErrors(validationErrors); setFormError(messages[0] || e.response?.data?.message || 'Perubahan tidak dapat disimpan. Periksa kembali data yang diisi.'); }
    finally { setSaving(false); }
  };
  const submitLoan = async event => {
    event.preventDefault(); setFormError(''); setFormErrors({}); setSaving(true);
    try {
      const { loan_balance: _systemLoanBalance, ...payload } = loanForm;
      const response = await saveEmployee({
        ...payload,
        loan_amount: payload.loan_amount === '' ? 0 : payload.loan_amount,
        loan_installment: payload.loan_installment === '' ? 0 : payload.loan_installment,
      });
      setLoanForm(null);
      setToast('Data pinjaman berhasil disimpan.');
      setSelectedId(response.data.data.id);
      await load();
    }
    catch (e) {
      const validationErrors = e.response?.data?.errors || {};
      const messages = [...new Set(Object.values(validationErrors).flat())];
      setFormErrors(validationErrors);
      setFormError(messages[0] || e.response?.data?.message || 'Data pinjaman tidak dapat disimpan. Periksa kembali nilai yang diisi.');
    }
    finally { setSaving(false); }
  };
  const destroy = async () => {
    if (!selected || !confirm(`Hapus ${selected.name}?`)) return;
    try { await removeEmployee(selected.id); setSelectedId(); setToast('Karyawan berhasil dihapus.'); await load(); }
    catch (e) { setError(e.response?.data?.message || 'Karyawan tidak dapat dihapus.'); }
  };
  const clearLoan = async employee => {
    if (!employee?.id || !confirm('Pinjaman akan dibatalkan dan semua nilai akan hilang')) return;
    setError(''); setSaving(true);
    try {
      const response = await clearEmployeeLoan(employee.id);
      const clearedEmployee = response.data.data;
      setRows(current => current.map(row => row.id === clearedEmployee.id ? { ...row, ...clearedEmployee } : row));
      setToast('Pinjaman berhasil dibatalkan dan semua nilai telah dihapus.');
    }
    catch (e) { setError(e.response?.data?.message || 'Pinjaman tidak dapat dibatalkan.'); }
    finally { setSaving(false); }
  };
  const pts = ['DPL', 'TOPI', 'KORP BKS', 'KORP SMRG', 'SRT PM', 'SRT CKRG', 'SRT SMRG', 'SRT SBY'];

  return <section className="feature-page employees-page">
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4"><div><p className="mb-1 text-sm font-semibold text-sky-600">Direktori karyawan</p><h1 className="text-3xl font-bold tracking-tight text-slate-900">Karyawan</h1><p className="mt-1 text-sm text-slate-500">Informasi PT mengikuti Baseline Payroll.</p></div><Button onClick={() => { setFormError(''); setFormErrors({}); setLoanAmountLocked(false); setForm({ ...blank }); }}>Tambah karyawan</Button></div>
    <div className="mb-5 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="flex max-w-xl items-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50 focus-within:border-sky-500 focus-within:bg-white focus-within:ring-2 focus-within:ring-sky-100"><span className="grid h-11 w-11 shrink-0 place-items-center text-slate-400"><Icon name="search"/></span><input aria-label="Cari karyawan" className="h-11 min-w-0 flex-1 border-0 bg-transparent pr-4 text-sm outline-none" placeholder="Cari berdasarkan nama atau ID karyawan..." value={search} onChange={e => { setSearch(e.target.value); setPt(''); setPage(1); }}/></div>
      <div className="mt-4 flex flex-wrap gap-2"><button onClick={() => choosePt('')} className={`whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium ${!pt ? 'bg-sky-800 text-white' : 'bg-slate-100 text-slate-600'}`}>Semua PT</button>{pts.map(value => <button key={value} onClick={() => choosePt(value)} className={`whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium ${pt === value ? 'bg-sky-800 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>{value}</button>)}</div>
    </div>
    <CompactAlert message={error} />
    <div className="grid min-h-[540px] min-w-0 gap-5 lg:grid-cols-[320px_minmax(0,1fr)] 2xl:grid-cols-[360px_minmax(0,1fr)]">
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"><div className="flex items-center justify-between border-b border-slate-100 px-5 py-4"><h2 className="font-semibold text-slate-800">{division || 'Semua karyawan'}</h2><span className="text-xs text-slate-400">{meta?.total || 0} orang</span></div><div className="max-h-[620px] divide-y divide-slate-100 overflow-auto">{loading ? <div className="p-10 text-center text-sm text-slate-400">Memuat karyawan...</div> : rows.length ? rows.map(row => <button key={row.id} onClick={() => setSelectedId(row.id)} className={`flex w-full items-center gap-3 p-4 text-left ${selected?.id === row.id ? 'bg-sky-50' : 'hover:bg-slate-50'}`}><span className={`grid h-11 w-11 shrink-0 place-items-center rounded-full text-sm font-bold ${selected?.id === row.id ? 'bg-sky-600 text-white' : 'bg-slate-100 text-slate-600'}`}>{initials(row.name)}</span><span className="min-w-0 flex-1"><span className="block truncate font-semibold text-slate-800">{row.name}</span><span className="block truncate text-xs text-slate-500">{row.employee_number} · {(row.pts || [row.department]).filter(Boolean).join(', ') || 'Tanpa PT'}</span></span><span className={`h-2.5 w-2.5 rounded-full ${row.active ? 'bg-emerald-500' : 'bg-slate-300'}`}/></button>) : <div className="p-10 text-center"><Icon name="users" className="mx-auto h-9 w-9 text-slate-300"/><p className="mt-3 text-sm font-medium text-slate-500">Karyawan tidak ditemukan</p></div>}</div><div className="px-4 pb-4"><Pagination meta={meta} onPage={setPage}/></div></div>
      {selected ? <div className="employee-profile overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"><div className="border-b border-slate-100 bg-gradient-to-r from-slate-50 to-sky-50/60 p-6"><div className="flex flex-wrap items-center gap-4"><span className="grid h-16 w-16 place-items-center rounded-2xl bg-sky-600 text-xl font-bold text-white">{initials(selected.name)}</span><div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h2 className="text-2xl font-bold text-slate-900">{selected.name}</h2><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${selected.active ? 'bg-sky-100 text-sky-700' : 'bg-slate-200 text-slate-600'}`}>{selected.active ? 'Aktif' : 'Tidak aktif'}</span></div><p className="mt-1 text-sm text-slate-500">{selected.employee_number} · {(selected.pts || [selected.department]).filter(Boolean).join(', ')}</p></div><div className="flex gap-2"><button onClick={() => openForm(selected)} className="flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700"><Icon name="edit"/> Ubah</button>{isAdmin && <button onClick={destroy} className="rounded-lg px-3 py-2 text-sm text-rose-600 hover:bg-rose-50">Hapus</button>}</div></div></div>
        <div className="grid gap-6 p-6 lg:grid-cols-2"><article className="rounded-xl border border-slate-100 p-5"><h3 className="mb-5 flex items-center gap-2 font-semibold text-slate-800"><span className="rounded-lg bg-sky-50 p-2 text-sky-700"><Icon name="briefcase"/></span>Informasi pekerjaan</h3><dl className="grid gap-5 sm:grid-cols-2"><Detail label="Nama" value={selected.name}/><Detail label="ID Karyawan" value={selected.employee_number}/><Detail label="PT" value={(selected.pts || [selected.department]).filter(Boolean).join(', ')}/><Detail label="Status karyawan" value={selected.active ? 'Karyawan aktif' : 'Karyawan tidak aktif'}/></dl></article>
        {isAdmin && <article className="rounded-xl border border-amber-200 bg-amber-50/40 p-5"><div className="mb-5 flex items-start justify-between"><h3 className="flex items-center gap-2 font-semibold text-slate-800"><span className="rounded-lg bg-amber-100 p-2 text-amber-700"><Icon name="wallet"/></span>Pinjaman</h3><button onClick={() => openLoanForm(selected)} className="text-xs font-semibold text-sky-700 hover:underline">Kelola pinjaman</button></div><dl className="grid gap-5 sm:grid-cols-2"><Detail label="Jumlah Pinjaman Awal" value={Number(selected.loan_balance) > 0 ? rupiah(selected.loan_amount) : '—'}/><Detail label="Sisa Pinjaman" value={Number(selected.loan_balance) > 0 ? rupiah(selected.loan_balance) : '—'}/><Detail label="Angsuran Default" value={Number(selected.loan_balance) > 0 ? rupiah(selected.loan_installment) : '—'}/><Detail label="Tanggal Mulai" value={Number(selected.loan_balance) > 0 ? tanggal(selected.loan_start_date) : '—'}/><Detail label="Catatan" value={Number(selected.loan_balance) > 0 ? selected.loan_notes : '—'}/></dl>{Number(selected.loan_balance) > 0 && <div className="mt-5 border-t border-amber-200 pt-4"><button type="button" disabled={saving} onClick={() => clearLoan(selected)} className="w-full rounded-lg border border-rose-300 bg-white px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-50">{saving ? 'Memproses...' : 'Clear'}</button></div>}</article>}</div>
        {isAdmin && <article className="mx-6 mb-6 overflow-hidden rounded-xl border border-slate-200"><div className="border-b border-slate-200 bg-slate-50 px-5 py-4"><h3 className="font-semibold text-slate-800">Riwayat Pinjaman</h3></div>{historyError ? <p className="p-5 text-sm text-rose-700">{historyError}</p> : historyLoading ? <p className="p-5 text-sm text-slate-500">Memuat riwayat pinjaman...</p> : loanHistory.length === 0 ? <p className="p-5 text-sm text-slate-500">Belum ada riwayat pinjaman.</p> : <div className="overflow-x-auto"><table className="min-w-full text-left text-sm"><thead className="bg-white text-slate-600"><tr><th className="whitespace-nowrap px-4 py-3 font-semibold">Periode Payroll</th><th className="whitespace-nowrap px-4 py-3 font-semibold">Jumlah Cicilan</th><th className="whitespace-nowrap px-4 py-3 font-semibold">Sisa Pinjaman Setelah Potongan</th><th className="whitespace-nowrap px-4 py-3 font-semibold">Status Payroll</th><th className="whitespace-nowrap px-4 py-3 font-semibold">Tanggal Diproses</th></tr></thead><tbody>{loanHistory.map((row, index) => <tr key={`${row.period_year}-${row.period_month}-${index}`} className="border-t border-slate-100"><td className="whitespace-nowrap px-4 py-3">{periodePayroll(row)}</td><td className="whitespace-nowrap px-4 py-3">{rupiah(row.installment_amount)}</td><td className="whitespace-nowrap px-4 py-3">{rupiah(row.remaining_after)}</td><td className="whitespace-nowrap px-4 py-3"><span className="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">{row.payroll_status}</span></td><td className="whitespace-nowrap px-4 py-3">{tanggalWaktu(row.processed_at)}</td></tr>)}</tbody></table></div>}</article>}</div> : <div className="grid place-items-center rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center"><div><Icon name="users" className="mx-auto h-12 w-12 text-slate-300"/><h2 className="mt-4 font-semibold text-slate-700">Pilih karyawan</h2><p className="mt-1 text-sm text-slate-500">Pilih karyawan untuk melihat informasinya.</p></div></div>}
    </div>
    <Modal open={!!form} wide title={form?.id ? `Ubah ${form.name}` : 'Tambah karyawan'} onClose={() => { if (!saving) setForm(null); }}>{form && <form onSubmit={submit}>{formError && <div id="employee-form-error" role="alert" aria-live="assertive" className="sticky top-0 z-40 mb-5 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800 shadow-lg"><p className="font-bold">Perubahan belum tersimpan</p><p className="mt-1">{formError}</p></div>}<div className="space-y-7"><FormSection title="Informasi pekerjaan"><Input label="Nama" required error={formErrors.name?.[0]} readOnly={!!form.id && !isAdmin} value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} className={form.id && !isAdmin ? 'bg-slate-100 text-slate-500' : ''}/><Input label="ID Karyawan" required error={formErrors.employee_number?.[0]} readOnly={!!form.id && !isAdmin} value={form.employee_number} onChange={e => setForm({ ...form, employee_number: e.target.value })} className={form.id && !isAdmin ? 'bg-slate-100 text-slate-500' : ''}/><Field label="PT" className="md:col-span-2"><div className={`grid gap-2 rounded-lg border bg-white p-3 sm:grid-cols-2 ${formErrors.pts ? 'border-rose-500' : 'border-slate-300'}`}>{pts.map(pt => <label key={pt} className="flex items-center gap-2 text-sm font-normal"><input type="checkbox" disabled={!!form.id && !isAdmin} checked={(form.pts || []).includes(pt)} onChange={e => { const current = form.pts || []; const next = e.target.checked ? [...current, pt] : current.filter(value => value !== pt); setForm({ ...form, pts: next, branch: next.includes(form.branch) ? form.branch : next[0] || '', department: next.includes(form.branch) ? form.branch : next[0] || '' }); }} className="h-4 w-4 accent-sky-600"/>{pt}{form.branch === pt && <span className="text-xs text-sky-600">(utama)</span>}</label>)}</div>{formErrors.pts?.[0] && <span className="mt-1.5 block text-xs font-medium text-rose-600">{formErrors.pts[0]}</span>}<span className="mt-1 block text-xs font-normal text-slate-500">Pilih satu atau beberapa PT. PT pertama menjadi PT utama payroll.</span></Field><label className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-700"><input type="checkbox" className="h-4 w-4 accent-sky-600" checked={!!form.active} onChange={e => setForm({ ...form, active: e.target.checked })}/>Karyawan aktif</label></FormSection>
      </div><div className="sticky bottom-0 z-30 -mx-6 -mb-6 mt-7 flex flex-wrap justify-end gap-3 border-t border-slate-200 bg-white px-6 py-4 shadow-[0_-8px_20px_rgba(15,23,42,0.06)]"><Button type="button" disabled={saving} className="bg-slate-100 text-slate-700" onClick={() => setForm(null)}>Batal</Button><Button disabled={saving}>{saving ? 'Menyimpan...' : 'Simpan karyawan'}</Button></div></form>}</Modal>
    <Modal open={!!loanForm} wide title={loanForm ? `Kelola Pinjaman - ${loanForm.name}` : 'Kelola Pinjaman'} onClose={() => { if (!saving) setLoanForm(null); }}>
      {loanForm && <form onSubmit={submitLoan} className="space-y-5">
        <p className="text-sm text-slate-600">Atur nilai pinjaman dan angsuran default karyawan. Sisa pinjaman dihitung otomatis oleh sistem payroll.</p>
        {formError && <div role="alert" aria-live="assertive" className="rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800"><p className="font-bold">Pinjaman belum tersimpan</p><p className="mt-1">{formError}</p></div>}
        <div className="grid gap-4 rounded-xl border border-slate-200 bg-slate-50/50 p-4 sm:grid-cols-2">
          <Input label="Jumlah Pinjaman Awal" error={formErrors.loan_amount?.[0]} type="number" min="0" step="0.01" readOnly={loanAmountLocked} value={loanForm.loan_amount ?? ''} className={loanAmountLocked ? 'bg-slate-100 text-slate-500' : ''} onChange={e => setLoanForm({ ...loanForm, loan_amount: e.target.value })}/>
          <Input label="Sisa Pinjaman (otomatis)" type="number" readOnly value={loanForm.loan_balance ?? ''} className="bg-slate-100 text-slate-500"/>
          <Input label="Angsuran Default" error={formErrors.loan_installment?.[0]} type="number" min="0.01" max={Number(loanForm.loan_amount || 0) || undefined} step="0.01" required={Number(loanForm.loan_amount || 0) > 0} value={loanForm.loan_installment ?? ''} onChange={e => setLoanForm({ ...loanForm, loan_installment: e.target.value })}/>
          <Input label="Tanggal Mulai" error={formErrors.loan_start_date?.[0]} type="date" value={loanForm.loan_start_date || ''} onChange={e => setLoanForm({ ...loanForm, loan_start_date: e.target.value })}/>
          <Field label="Catatan Pinjaman" className="sm:col-span-2"><textarea rows="3" className={control} value={loanForm.loan_notes || ''} onChange={e => setLoanForm({ ...loanForm, loan_notes: e.target.value })}/></Field>
        </div>
        <div className="flex justify-end gap-2"><Button type="button" className="bg-slate-200 text-slate-800" disabled={saving} onClick={() => setLoanForm(null)}>Batal</Button><Button disabled={saving}>{saving ? 'Menyimpan...' : 'Simpan Pinjaman'}</Button></div>
      </form>}
    </Modal>
    <Toast message={toast}/>
  </section>;
}
