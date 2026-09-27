const labels = ['Jan–Feb', 'Feb–Mar', 'Mar–Apr', 'Apr–Mei', 'Mei–Jun', 'Jun–Jul', 'Jul–Agu', 'Agu–Sep', 'Sep–Okt', 'Okt–Nov', 'Nov–Des', 'Des–Jan'];
const months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const dashboardPeriodKey = 'payflow_dashboard_period';
const validPeriodValue = value => /^\d{4}-(0[1-9]|1[0-2])$/.test(String(value || ''));

export function periodSummary(year, period) {
  const start = new Date(year, period - 1, 22); const end = new Date(year, period, 21);
  return { label: labels[period - 1], start: `22 ${months[start.getMonth()]} ${start.getFullYear()}`, end: `21 ${months[end.getMonth()]} ${end.getFullYear()}` };
}

export function currentPayrollPeriodValue(today = new Date()) {
  const activeStart = new Date(today.getFullYear(), today.getMonth() - (today.getDate() <= 21 ? 1 : 0), 1);
  return `${activeStart.getFullYear()}-${String(activeStart.getMonth() + 1).padStart(2, '0')}`;
}

export function dashboardPayrollPeriodValue() {
  try {
    const saved = localStorage.getItem(dashboardPeriodKey);
    return validPeriodValue(saved) ? saved : currentPayrollPeriodValue();
  } catch {
    return currentPayrollPeriodValue();
  }
}

export function saveDashboardPayrollPeriod(value) {
  if (!validPeriodValue(value)) return;
  try { localStorage.setItem(dashboardPeriodKey, value); } catch {}
}

export default function PayrollPeriodSelect({ value, onChange, className = '' }) {
  const [year, period] = value.split('-').map(Number);
  const emit = (nextYear, nextPeriod) => onChange({ target: { value: `${nextYear}-${String(nextPeriod).padStart(2, '0')}` } });
  return <div className={`grid grid-cols-2 gap-2 ${className}`}><label className="block text-sm font-medium text-slate-700">Tahun<input type="number" step="1" value={year} onChange={event => emit(Number(event.target.value), period)} className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2" /></label><label className="block text-sm font-medium text-slate-700">Periode<select value={period} onChange={event => emit(year, Number(event.target.value))} className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">{labels.map((label, index) => <option value={index + 1} key={label}>{label}</option>)}</select></label></div>;
}
