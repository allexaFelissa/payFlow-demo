export default function Loading({ label = 'Memuat…', fullScreen = false }) {
  if (fullScreen) return (
    <main className="loading-screen" role="status" aria-live="polite">
      <div className="loading-screen__glow" aria-hidden="true" />
      <div className="loading-screen__content">
        <div className="loading-screen__mark" aria-hidden="true"><span>PF</span><i /></div>
        <div><p className="loading-screen__brand">PayFlow</p><p className="loading-screen__label">{label}</p></div>
        <div className="loading-screen__progress" aria-hidden="true"><span /></div>
      </div>
    </main>
  );

  return <div className="flex items-center gap-2 text-sm text-slate-600" role="status"><span className="inline-block h-5 w-5 animate-spin rounded-full border-2 border-slate-300 border-t-sky-600" aria-hidden="true" />{label}</div>;
}
