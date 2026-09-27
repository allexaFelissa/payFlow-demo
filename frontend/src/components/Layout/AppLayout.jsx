import { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';

const links = [
  ['Beranda', '/', 'home'],
  ['Karyawan', '/employees', 'users'],
  ['Absensi', '/attendance', 'calendar'],
  ['Lembur', '/overtime', 'clock'],
];
const payrollLinks = [
  ['Baseline Payroll', '/payroll/baseline', 'ledger'],
  ['Perhitungan Payroll', '/payroll/calculation', 'calculator'],
];

function Icon({ name }) {
  const paths = {
    home: <><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v10h13V10M9 20v-6h6v6"/></>,
    users: <><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></>,
    calendar: <><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></>,
    clock: <><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></>,
    ledger: <><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h3M15 14v4M13 16h4"/></>,
    calculator: <><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 11h.01M12 11h.01M16 11h.01M8 15h.01M12 15h.01M16 15h.01"/></>,
    exit: <><path d="M10 17l5-5-5-5M15 12H3"/><path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/></>,
    collapse: <><path d="m14 7-5 5 5 5"/><path d="M20 4v16"/></>,
  };
  return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">{paths[name]}</svg>;
}

function NavLink({ label, to, icon, active }) {
  return <Link to={to} aria-label={label} title={label} className={`app-nav-link ${active ? 'is-active' : ''}`}><span className="app-nav-icon"><Icon name={icon}/></span><span>{label}</span></Link>;
}

export default function AppLayout({ children }) {
  const { pathname } = useLocation();
  const { user, isAdmin, logout } = useAuth();
  const [collapsed, setCollapsed] = useState(() => localStorage.getItem('payflow_sidebar_collapsed') === 'true');
  const today = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());
  const setSidebar = value => { setCollapsed(value); localStorage.setItem('payflow_sidebar_collapsed', String(value)); };

  return <div className={`app-shell ${collapsed ? 'sidebar-collapsed' : ''}`}>
    <aside className="app-sidebar dark-light-surface">
      <div className="app-brand"><button className="app-brand-mark" type="button" onClick={() => collapsed && setSidebar(false)} aria-label={collapsed ? 'Buka navigasi' : 'Logo PayFlow'} title={collapsed ? 'Buka navigasi' : 'PayFlow'}>PF</button><span><b>PayFlow</b><small>Payroll workspace</small></span><button className="sidebar-toggle" type="button" onClick={() => setSidebar(true)} aria-label="Tutup navigasi" title="Tutup navigasi"><Icon name="collapse"/></button></div>
      <nav className="app-navigation" aria-label="Navigasi utama">
        <p className="app-nav-label">Workspace</p>
        {links.map(([label, to, icon]) => <NavLink key={to} label={label} to={to} icon={icon} active={pathname === to}/>)}
        {isAdmin && <div className="app-nav-group"><p className="app-nav-label">Payroll</p>{payrollLinks.map(([label, to, icon]) => <NavLink key={to} label={label} to={to} icon={icon} active={pathname === to}/>)}</div>}
      </nav>
      <div className="app-user-card"><span className="app-user-avatar">{user.username?.slice(0, 2).toUpperCase()}</span><span className="min-w-0 flex-1"><b>{user.username}</b><small>{user.role === 'admin' ? 'Administrator HR' : 'Staf HR'}</small></span><button onClick={logout} title="Keluar" aria-label="Keluar"><Icon name="exit"/></button></div>
    </aside>
    <main className="app-main"><header className="app-topbar"><div><span className="app-topbar-label">Sistem payroll internal</span><span className="app-topbar-date">{today}</span></div><div className="app-status"><span/> Sistem aktif</div></header><div className="app-content">{children}</div></main>
  </div>;
}
