export default function CompactAlert({ message, title = 'Terjadi kesalahan', variant = 'error', className = '' }) {
  if (!message) return null;

  const warning = variant === 'warning';
  const colors = warning ? 'border-amber-200 bg-amber-50/70 text-amber-900' : 'border-rose-200 bg-rose-50/70 text-rose-900';
  const icon = warning ? 'bg-amber-200/70 text-amber-900' : 'bg-rose-200/70 text-rose-900';

  return <details role="alert" className={`group mb-3 rounded-lg border font-[inherit] ${colors} ${className}`}>
    <summary className="flex cursor-pointer list-none items-center gap-2.5 px-3 py-2 text-xs font-semibold leading-5 outline-none focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:ring-offset-2 [&::-webkit-details-marker]:hidden">
      <span aria-hidden="true" className={`grid h-5 w-5 shrink-0 place-items-center rounded-full text-[11px] font-bold ${icon}`}>!</span>
      <span className="min-w-0 flex-1 truncate">{title}</span>
      <span aria-hidden="true" className="text-[9px] opacity-50 transition-transform group-open:rotate-180">▼</span>
    </summary>
    <div className="border-t border-current/10 px-3 py-2.5 pl-[2.875rem] text-xs leading-5 text-slate-600">{message}</div>
  </details>;
}
