import { useEffect, useRef, useState } from 'react';
import { attendance, editCell, exportAttendance, importAttendance, lock } from '../../services/attendanceApi';
import Button from '../../components/UI/Button';
import Input from '../../components/UI/Input';
import Loading from '../../components/UI/Loading';
import Toast from '../../components/UI/Toast';
import CompactAlert from '../../components/UI/CompactAlert';
import PayrollPeriodSelect, { dashboardPayrollPeriodValue, periodSummary } from '../../components/UI/PayrollPeriodSelect';

const statuses = ['M', 'L', 'S', 'I', 'C', 'A'];
const labels = { M: 'Tepat Waktu', L: 'Terlambat', S: 'Sakit', I: 'Izin', C: 'Cuti', A: 'Alpa' };
const symbols = { M: '✔', L: 'l', S: 's', I: 'i', C: 'c', A: 'a' };
const statusColors = {
  M: 'attendance-status attendance-status--present',
  L: 'attendance-status attendance-status--late',
  S: 'attendance-status attendance-status--sick',
  I: 'attendance-status attendance-status--permit',
  C: 'attendance-status attendance-status--leave',
  A: 'attendance-status attendance-status--alpha',
};

const checklistOptions = [
  { value: '', status: 'M', remarks: null, label: '—', title: 'Tepat Waktu' },
  { value: 'LT30', status: 'L', remarks: 'LT30', label: '<30', title: 'Terlambat Kurang dari 30 Menit' },
  { value: 'GT30', status: 'L', remarks: 'GT30', label: '>30', title: 'Terlambat Lebih dari 30 Menit' },
  { value: 'S', status: 'S', remarks: null, label: 'S', title: labels.S },
  { value: 'I', status: 'I', remarks: null, label: 'I', title: labels.I },
  { value: 'C', status: 'C', remarks: null, label: 'C', title: labels.C },
  { value: 'A', status: 'A', remarks: null, label: 'A', title: labels.A },
];

const primaryOptions = [
  { value: '', label: '', title: 'Kosong' },
  { value: 'M', label: '✔', title: 'Hadir' },
  { value: 'S', label: 'S', title: 'Sakit' },
  { value: 'I', label: 'I', title: 'Izin' },
  { value: 'C', label: 'C', title: 'Cuti' },
  { value: 'A', label: 'A', title: 'Alpa' },
];

const doctorLetterOptions = [
  { value: '', label: '—' },
  { value: 'SICK_YES', label: 'Ya' },
  { value: 'SICK_NO', label: 'Tidak' },
];

const lateOptions = [
  { value: '', label: '—' },
  { value: 'LT30', label: '< 30 mnt' },
  { value: 'GT30', label: '> 30 mnt' },
];

function summarize(records) {
  const counts = { M: 0, L: 0, S: 0, I: 0, C: 0, A: 0 };
  Object.values(records).forEach(record => {
    if (counts[record.status] !== undefined) counts[record.status] += 1;
  });
  return { present: counts.M, late: counts.L, sick: counts.S, leave: counts.I, vacation: counts.C, alpha: counts.A, working_days: counts.M + counts.L };
}

function lateRemarkValue(record) {
  const raw = record?.remarks;
  return raw === 'LT30' || raw === 'GT30' ? raw : '';
}

function checklistValue(record) {
  if (!record || record.status === 'M') return '';
  return record.status === 'L' ? lateRemarkValue(record) : record.status;
}

function doctorLetterValue(record) {
  return ['SICK_YES', 'SICK_NO'].includes(record?.remarks) ? record.remarks : 'SICK_YES';
}

export default function Attendance() {
  const [month, setMonth] = useState(dashboardPayrollPeriodValue);
  const [result, setResult] = useState();
  const [page, setPage] = useState(1);
  const [busy, setBusy] = useState(false);
  const [importing, setImporting] = useState(false);
  const [error, setError] = useState('');
  const [importReminder, setImportReminder] = useState('');
  const [toast, setToast] = useState('');
  const [pt, setPt] = useState('');
  const [employeeSearch, setEmployeeSearch] = useState('');
  const fileInput = useRef();

  const isClosed = result?.period?.status === 'closed';
  const [selectedYear, selectedPeriod] = month.split('-').map(Number);
  const selectedSummary = periodSummary(selectedYear, selectedPeriod);
  const [periodYear, periodMonth] = month.split('-').map(Number);
  const periodCutoff = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(periodYear, periodMonth - 2, 22))+' – '+new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(periodYear, periodMonth - 1, 21));

  const load = async () => {
    setBusy(true);
    setError('');
    try {
      const [year, period] = month.split('-').map(Number); const response = await attendance({ year, period, branch: pt || undefined, search: employeeSearch || undefined, page });
      setResult(response.data);
    } catch (e) {
      setError(e.response?.data?.message || 'Tidak dapat memuat data absensi. Pastikan server berjalan pada port 8000.');
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => { load(); }, [month, page, pt]);

  const upload = async event => {
    const file = event.target.files?.[0];
    if (!file) return;
    const [year, period] = month.split('-').map(Number);
    setImporting(true);
    setError('');
    setImportReminder('');
    try {
      const form = new FormData();
      form.append('period', period);
      form.append('year', year);
      form.append('file', file);
      const response = await importAttendance(form);
      const skipped = response.data.skipped_employees || [];
      const skippedDates = response.data.skipped_dates || [];
      const partialErrors = [
        ...skipped.map(employee => employee.reason || `${employee.name} (${employee.number}) tidak ditemukan`),
        ...skippedDates.map(item => item.reason),
      ];
      if (partialErrors.length) {
        setImportReminder(`Error impor sebagian: ${partialErrors.join(' ')}`);
      }
      setToast(partialErrors.length ? 'Data absensi yang valid berhasil diimpor.' : 'Absensi berhasil diimpor. Periksa dan ubah tabel di bawah.');
      await load();
    } catch (e) {
      const messages = e.response?.data?.errors?.file;
      setError(messages ? messages.join(' ') : (e.response?.data?.message || 'Absensi tidak dapat diimpor.'));
    } finally {
      setImporting(false);
      event.target.value = '';
    }
  };

  const applyLocalCell = (employeeId, date, patch) => {
    setResult(current => ({
      ...current,
      data: current.data.map(row => {
        if (row.id !== employeeId) return row;
        const records = { ...row.records };
        if (patch === null) delete records[date];
        else records[date] = { ...records[date], ...patch };
        return { ...row, records, summary: summarize(records) };
      }),
    }));
  };

  const saveCell = async (employeeId, date, status, remarks) => {
    const response = await editCell({
      attendance_period_id: result.period.id,
      employee_id: employeeId,
      attendance_date: date,
      status: status || null,
      remarks: remarks || null,
    });
    if (!response.data.record) {
      applyLocalCell(employeeId, date, null);
      return;
    }
    applyLocalCell(employeeId, date, { id: response.data.record.id, date, status, remarks: response.data.record.remarks });
  };

  const changeChecklist = async (employee, date, value) => {
    setError('');
    try {
      const option = checklistOptions.find(item => item.value === value);
      await saveCell(employee.id, date, option.status, option.remarks);
    } catch (e) {
      setError(e.response?.data?.message || 'Absensi tidak dapat disimpan.');
    }
  };

  const changePrimaryStatus = async (employee, date, status) => {
    setError('');
    try {
      await saveCell(employee.id, date, status, status === 'S' ? doctorLetterValue(employee.records[date]) : null);
    } catch (e) {
      setError(e.response?.data?.message || 'Absensi tidak dapat disimpan.');
    }
  };

  const changeLateDetail = async (employee, date, value) => {
    setError('');
    try {
      await saveCell(employee.id, date, value ? 'L' : 'M', value || null);
    } catch (e) {
      setError(e.response?.data?.message || 'Detail keterlambatan tidak dapat disimpan.');
    }
  };

  const changeDoctorLetter = async (employee, date, value) => {
    setError('');
    try {
      await saveCell(employee.id, date, 'S', value || null);
    } catch (e) {
      setError(e.response?.data?.message || 'Detail surat dokter tidak dapat disimpan.');
    }
  };

  const saveTemporarily = async () => {
    setError('');
    try {
      await lock({ attendance_period_id: result.period.id });
      setToast('Berhasil disimpan.');
      setResult(current => ({ ...current, period: { ...current.period, status: 'locked' } }));
    } catch (e) {
      setError(e.response?.data?.message || 'Data tidak dapat disimpan.');
    }
  };

  const download = async () => {
    try { const [year, period] = month.split('-').map(Number); const response = await exportAttendance({ year, period }); const url = URL.createObjectURL(response.data); const link = document.createElement('a'); link.href = url; link.download = `absensi-${month}.xlsx`; link.click(); URL.revokeObjectURL(url); }
    catch { setError('File absensi tidak dapat diekspor.'); }
  };

  const dates = result?.dates || [];
  const dateLabel = date => Number(date.split('-')[2]);
  const isWeekend = date => [0, 6].includes(new Date(`${date}T00:00:00`).getDay());

  return <section className="feature-page attendance-page">
    <header className="operation-header"><div><p className="operation-kicker">Kehadiran · input bulanan</p><h1>Absensi</h1><p>Pilih periode dan PT, lalu perbarui status kehadiran langsung pada matriks.</p></div><span className="operation-step">Perubahan tersimpan langsung</span></header>
    <div className="operation-toolbar"><p className="operation-toolbar-label">Filter dan tindakan periode</p><div className="flex flex-wrap items-end justify-between gap-3">
      <div className="flex flex-1 flex-wrap items-end gap-2">
        <PayrollPeriodSelect className="w-full sm:w-[21rem]" value={month} onChange={e => { setPage(1); setMonth(e.target.value); }} />
        <select aria-label="PT" value={pt} onChange={event => { setPage(1); setPt(event.target.value); }} className="rounded border border-slate-300 px-3 py-2 text-sm"><option value="">Semua PT</option>{['DPL','TOPI','KORP BKS','KORP SMRG','SRT PM','SRT CKRG','SRT SMRG','SRT SBY'].map(value => <option key={value}>{value}</option>)}</select>
        <Input aria-label="Nama Karyawan" placeholder="Cari nama karyawan" value={employeeSearch} onChange={event => setEmployeeSearch(event.target.value)} onKeyDown={event => { if (event.key === 'Enter') { setPage(1); load(); } }} className="w-48"/>
      </div><div className="operation-actions"><input ref={fileInput} className="hidden" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onChange={upload} />
        <Button className="whitespace-nowrap px-3" disabled={importing} onClick={() => fileInput.current?.click()}>{importing ? 'Mengimpor…' : 'Impor'}</Button>
        {result?.period && <Button type="button" className="whitespace-nowrap px-3" onClick={download}>Ekspor</Button>}
        {result?.period && <Button className="whitespace-nowrap px-3" disabled={isClosed} onClick={saveTemporarily}>Simpan</Button>}
      </div></div></div>
    <CompactAlert message={error} />
    {importReminder && <p role="alert" className="mb-3 rounded border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{importReminder}</p>}
    {result?.period && <><p className="mb-2 text-sm font-medium text-slate-700">{result.period.label} · {result.period.cutoff_label || periodCutoff}</p><div className="mb-3 flex flex-wrap items-center gap-2 text-xs">
      {['M', 'S', 'I', 'C', 'A'].map(status => <span className={`rounded-md border px-2 py-1 font-semibold ${statusColors[status]}`} key={status}>{status === 'M' ? '—' : status} = {labels[status]}</span>)}
      <span className={`rounded-md border px-2 py-1 font-semibold ${statusColors.L}`}>&lt;30 / &gt;30 = Terlambat</span>
    </div></>}
    {busy ? <Loading label="Memuat absensi…" /> : !result?.period ? <div className="rounded-lg border border-dashed p-8 text-center text-slate-600">Belum ada periode untuk {month}. Impor templat absensi atau buat periode bulanan.</div> : <div className="max-h-[70vh] overflow-auto rounded-lg border bg-white">
      <table className="min-w-max text-xs">
        <thead className="sticky top-0 z-20 bg-slate-50"><tr><th className="sticky left-0 z-30 w-44 min-w-44 max-w-44 border-r border-slate-200 bg-slate-50 px-3 py-2 text-left">Nama Karyawan</th><th className="sticky left-44 z-30 w-28 min-w-28 max-w-28 border-r border-slate-200 bg-slate-50 px-3 py-2 text-left">ID Karyawan</th>{dates.map(date => <th key={date} className={`min-w-14 px-1 py-2 ${isWeekend(date) ? 'bg-amber-100 text-amber-900' : ''}`}>{dateLabel(date)}</th>)}<th className="px-2">Tepat Waktu</th><th className="px-2">Terlambat</th><th className="px-2">Sakit</th><th className="px-2">Izin</th><th className="px-2">Cuti</th><th className="px-2">Alpa</th></tr></thead>
        <tbody>{result.data.map(employee => <tr key={employee.id} className="border-t">
          <td className="sticky left-0 z-10 w-44 min-w-44 max-w-44 overflow-hidden text-ellipsis border-r border-slate-200 bg-white px-3 py-2 whitespace-nowrap font-medium">{employee.name}</td><td className="sticky left-44 z-10 w-28 min-w-28 max-w-28 overflow-hidden text-ellipsis border-r border-slate-200 bg-white px-3 py-2 whitespace-nowrap text-slate-600">{employee.employee_number}</td>
          {dates.map(date => {
            const record = employee.records[date];
            if (!record) return <td className={`p-1 ${isWeekend(date) ? 'bg-amber-50' : ''}`} key={date}><select aria-label={`Absensi ${employee.name} tanggal ${dateLabel(date)}`} disabled={isClosed} defaultValue="" onChange={e => changePrimaryStatus(employee, date, e.target.value)} className="w-14 appearance-none rounded border border-transparent bg-transparent p-1 text-center hover:border-slate-300 focus:border-sky-500 focus:outline-none" title="Tambah absensi"><option value="" /><option value="M">✔</option><option value="S">S</option><option value="I">I</option><option value="C">C</option><option value="A">A</option></select></td>;
            const status = record?.status || 'M';
            const primaryStatus = ['M', 'L'].includes(status) ? 'M' : status;
            const lateValue = lateRemarkValue(record);
            const doctorLetter = doctorLetterValue(record);
            return <td className={`p-1 ${isWeekend(date) ? 'bg-amber-50' : ''}`} key={date}>
              <div className="flex w-14 flex-col gap-1">
                <select
                  aria-label={`${employee.name} attendance ${dateLabel(date)}`}
                  disabled={isClosed}
                  value={primaryStatus}
                  onChange={e => changePrimaryStatus(employee, date, e.target.value)}
                  className={`w-14 rounded border p-1 text-center font-semibold ${statusColors[status]}`}
                  title={primaryOptions.find(option => option.value === primaryStatus)?.title}
                >
                  {primaryOptions.map(option => <option value={option.value} key={option.value}>{option.label}</option>)}
                </select>
                {primaryStatus === 'M' && <select
                  aria-label={`${employee.name} lateness ${dateLabel(date)}`}
                  disabled={isClosed}
                  value={lateValue}
                  onChange={e => changeLateDetail(employee, date, e.target.value)}
                  className="w-14 rounded border border-slate-300 bg-white p-1 text-center text-[10px] text-slate-700"
                  title="Keterlambatan"
                >
                  <option value="">—</option><option value="LT30">&lt;30</option><option value="GT30">&gt;30</option>
                </select>}
                {primaryStatus === 'S' && <select
                  aria-label={`${employee.name} doctor letter ${dateLabel(date)}`}
                  disabled={isClosed}
                  value={doctorLetter}
                  onChange={e => changeDoctorLetter(employee, date, e.target.value)}
                  className="w-14 rounded border border-sky-300 bg-sky-50 p-1 text-center text-[10px] text-sky-900"
                  title="Surat Dokter"
                >
                  {doctorLetterOptions.filter(option => option.value).map(option => <option value={option.value} key={option.value}>{option.label}</option>)}
                </select>}
              </div>
            </td>;
            /* Legacy two-control layout removed.
            const status = record?.status || '';
            const lateValue = lateRemarkValue(record);
            return <td className="p-1" key={date}>
              <div className="flex flex-col gap-1">
                <select
                  aria-label={`${employee.name} tanggal ${dateLabel(date)}`}
                  disabled={isClosed}
                  value={status}
                  onChange={e => changeStatus(employee, date, e.target.value)}
                  className={`w-12 rounded border p-1 text-center font-semibold ${statusColors[status] || 'border-slate-300 bg-white text-slate-600'}`}
                  title={status ? labels[status] : 'Belum tercatat'}
                >
                  <option value="" disabled>—</option>
                  {statuses.map(option => <option value={option} key={option}>{symbols[option]}</option>)}
                </select>
                {status === 'L' && <select
                  aria-label={`${employee.name} detail telat ${dateLabel(date)}`}
                  disabled={isClosed}
                  value={lateValue}
                  onChange={e => changeLateDetail(employee, date, e.target.value)}
                  className="w-16 rounded border border-slate-300 bg-white p-1 text-[10px] text-slate-700"
                  title="Detail telat"
                >
                  {lateOptions.map(option => <option value={option.value} key={option.value}>{option.label}</option>)}
                </select>}
              </div>
            </td>;
            */
          })}
          {['present', 'late', 'sick', 'leave', 'vacation', 'alpha'].map(key => <td className="px-2 text-center" key={key}>{employee.summary[key]}</td>)}
        </tr>)}</tbody>
      </table>
    </div>}
    {result?.meta?.last_page > 1 && <div className="mt-3 flex items-center justify-end gap-3 text-sm"><span className="text-slate-600">{result.meta.total} karyawan · halaman {result.meta.current_page} dari {result.meta.last_page}</span><Button className="bg-slate-600" disabled={result.meta.current_page === 1} onClick={() => setPage(page - 1)}>Sebelumnya</Button><Button className="bg-slate-600" disabled={result.meta.current_page === result.meta.last_page} onClick={() => setPage(page + 1)}>Berikutnya</Button></div>}
    <Toast message={toast} />
  </section>;
}
