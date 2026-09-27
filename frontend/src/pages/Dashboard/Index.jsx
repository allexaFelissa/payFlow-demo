import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import PayrollPeriodSelect, { dashboardPayrollPeriodValue, periodSummary, saveDashboardPayrollPeriod } from '../../components/UI/PayrollPeriodSelect';
import CompactAlert from '../../components/UI/CompactAlert';
import { useAuth } from '../../context/AuthContext';
import { dashboardSummary } from '../../services/dashboardApi';

const rupiah = value => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0);
const clamp = value => Math.max(0, Math.min(100, Math.round(value || 0)));
const emptyData = {
  employees: { total: 0, active: 0, in_period: 0 },
  attendance: { period: null, recorded: 0, expected: 0, progress: 0 },
  overtime: { total: 0, updated: 0, progress: 0 },
  payroll: { total: 0, completed: 0, pt_total: 8, pt_completed: 0, progress: 0, take_home: 0 },
  recent_overtime: [],
};

function Metric({ label, value, note, accent = false }) {
  return <article className={`recap-metric ${accent ? 'is-accent' : ''}`}><span>{label}</span><strong>{value}</strong><small>{note}</small></article>;
}
function ProgressRow({ label, value, note, to }) {
  return <Link className="progress-row" to={to}><div><b>{label}</b><span>{note}</span></div><div className="progress-track"><i style={{ width: `${clamp(value)}%` }}/></div><strong>{clamp(value)}%</strong></Link>;
}
function ActionItem({ to, label, detail, state }) {
  return <Link to={to} className="dashboard-action"><span className={`action-state ${state}`}/><div><b>{label}</b><small>{detail}</small></div><span aria-hidden="true">↗</span></Link>;
}

export default function Dashboard() {
  const { user, isAdmin } = useAuth();
  const [data, setData] = useState(emptyData);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [selectedPeriod, setSelectedPeriod] = useState(dashboardPayrollPeriodValue);
  const [year, period] = selectedPeriod.split('-').map(Number);
  const summary = periodSummary(year, period);
  const periodName = `${summary.label} ${year}`;

  useEffect(() => {
    let live = true;
    setLoading(true);
    setError('');
    dashboardSummary({ year, period })
      .then(response => { if (live) setData(response.data); })
      .catch(() => { if (live) { setData(emptyData); setError('Rekap periode ini tidak dapat dimuat. Coba pilih kembali atau muat ulang halaman.'); } })
      .finally(() => { if (live) setLoading(false); });
    return () => { live = false; };
  }, [year, period]);

  const attendance = data.attendance || emptyData.attendance;
  const overtime = data.overtime || emptyData.overtime;
  const payroll = data.payroll || emptyData.payroll;
  const activity = data.recent_overtime || [];

  return <section className="dashboard-control-room">
    <header className="control-hero dark-light-surface"><div><p className="page-eyebrow">Control room · {periodName}</p><h1>Selamat datang,<br/>{user.username}</h1><p>Pantau kesiapan data sebelum payroll diproses.</p></div><div className="hero-progress"><div className="hero-progress-ring" style={{ '--progress': `${clamp(payroll.progress)}%` }}><span>{clamp(payroll.progress)}%</span></div><div><small>Progres penyelesaian PT</small><b>{payroll.pt_completed} dari {payroll.pt_total} PT selesai</b><span>{attendance.period?.cutoff_label || 'Belum ada periode absensi'}</span></div></div></header>
    <section className="dashboard-period-toolbar" aria-label="Pilih periode rekap"><div><p className="operation-kicker">Rekap beranda</p><h2>{periodName}</h2><span>{summary.start}—{summary.end}</span></div><PayrollPeriodSelect value={selectedPeriod} onChange={event => { saveDashboardPayrollPeriod(event.target.value); setSelectedPeriod(event.target.value); }} /></section>
    <CompactAlert message={error} />
    <div className="recap-strip"><Metric label="Karyawan periode" value={loading ? '—' : data.employees.in_period} note={`karyawan bekerja pada ${periodName}`}/><Metric label="Absensi tercatat" value={loading ? '—' : `${attendance.recorded}/${attendance.expected}`} note="status harian terisi dari total jadwal kerja"/><Metric label="Lembur diperbarui" value={loading ? '—' : overtime.updated} note={`dari ${overtime.total} karyawan`}/>{isAdmin && <Metric accent label="Estimasi payroll" value={loading ? '—' : rupiah(payroll.take_home)} note={`${payroll.total} draf tersedia`}/>}</div>
    <div className="dashboard-work-grid"><section className="progress-panel"><div className="panel-heading"><div><p className="operation-kicker">Progres periode</p><h2>Kesiapan alur payroll</h2></div><span>{periodName}</span></div><div className="progress-list"><ProgressRow label="Absensi" value={attendance.progress} note="Status kehadiran sudah terisi" to="/attendance"/><ProgressRow label="Lembur & Extra Time" value={overtime.progress} note="Rincian karyawan sudah diperbarui" to="/overtime"/>{isAdmin && <ProgressRow label="Penyelesaian payroll PT" value={payroll.progress} note={`${payroll.pt_completed} dari ${payroll.pt_total} PT selesai`} to="/payroll/calculation"/>}</div></section><aside className="next-actions"><div className="panel-heading"><div><p className="operation-kicker">Tindakan pengguna</p><h2>Langkah berikutnya</h2></div></div><ActionItem to="/attendance" label={attendance.period ? 'Lengkapi absensi' : 'Siapkan periode absensi'} detail={attendance.period ? `${clamp(attendance.progress)}% data tercatat` : 'Belum ada periode aktif'} state={attendance.progress >= 100 ? 'done' : 'pending'}/><ActionItem to="/overtime" label="Periksa lembur" detail={`${overtime.updated} karyawan diperbarui`} state={overtime.progress >= 100 ? 'done' : 'pending'}/>{isAdmin && <ActionItem to="/payroll/calculation" label="Tinjau payroll" detail={`${payroll.pt_completed} dari ${payroll.pt_total} PT selesai`} state={payroll.progress >= 100 ? 'done' : payroll.pt_completed ? 'active' : 'pending'}/>}</aside></div>
    <div className="dashboard-lower-grid"><section className="activity-panel"><div className="panel-heading"><div><p className="operation-kicker">Aktivitas terbaru</p><h2>Pembaruan lembur</h2></div><Link to="/overtime">Lihat semua</Link></div>{activity.length ? <div className="activity-list">{activity.map(row => <Link to="/overtime" key={row.employee_id}><span className="activity-avatar">{row.employee_name?.split(' ').map(word => word[0]).slice(0,2).join('')}</span><div><b>{row.employee_name}</b><small>{row.type} · {row.branch || 'Tanpa PT'}</small></div><time>{new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(row.updated_at))}</time></Link>)}</div> : <div className="dashboard-empty"><b>Belum ada pembaruan</b><p>Simpan rincian lembur untuk melihat aktivitas periode ini.</p></div>}</section></div>
  </section>;
}
