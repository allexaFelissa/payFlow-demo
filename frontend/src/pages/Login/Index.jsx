import { useState } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import Button from '../../components/UI/Button';
import CompactAlert from '../../components/UI/CompactAlert';
import Input from '../../components/UI/Input';
import { useAuth } from '../../context/AuthContext';

export default function Login() {
  const { user, login } = useAuth();
  const [form, setForm] = useState({ username: '', password: '' });
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const navigate = useNavigate();
  const location = useLocation();

  if (user) return <Navigate to="/" replace/>;

  const submit = async event => {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      await login(form);
      navigate(location.state?.from || '/', { replace: true });
    } catch (e) {
      setError(e.response?.data?.errors?.username?.[0] || 'Login gagal. Periksa username dan password.');
    } finally {
      setBusy(false);
    }
  };

  return <main className="login-shell">
    <section className="login-intro">
      <div className="app-brand app-brand--login"><span className="app-brand-mark">PF</span><span><b>PayFlow</b><small>Payroll workspace</small></span></div>
      <div><p className="page-eyebrow">Sistem HR perusahaan</p><h1>Payroll yang tertata.<br/>Keputusan yang jelas.</h1><p>Ruang kerja internal untuk mengelola karyawan, kehadiran, lembur, dan penggajian secara konsisten.</p></div>
      <small>Terbatas untuk personel perusahaan yang berwenang.</small>
    </section>
    <section className="login-panel"><div className="login-form">
      <p className="page-eyebrow">Akses workspace</p>
      <h2>Masuk ke akun Anda</h2>
      <p className="login-copy">Gunakan akun yang diberikan Administrator HR.</p>
      <CompactAlert message={error} title="Login gagal" />
      <form className="space-y-5" onSubmit={submit}>
        <Input label="Username" autoComplete="username" required value={form.username} onChange={event => setForm({ ...form, username: event.target.value })}/>
        <Input label="Password" type="password" autoComplete="current-password" required value={form.password} onChange={event => setForm({ ...form, password: event.target.value })}/>
        <Button className="w-full py-3" disabled={busy}>{busy ? 'Memproses…' : 'Masuk ke PayFlow'}</Button>
      </form>
      <p className="login-help">Tidak memiliki akses? Hubungi Administrator HR perusahaan.</p>
    </div></section>
  </main>;
}
