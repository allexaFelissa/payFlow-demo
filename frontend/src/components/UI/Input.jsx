export default function Input({ label, className = '', error = '', ...props }) {
  return <label className="block text-sm font-medium text-slate-700">
    {label && <span className="mb-1 block">{label}</span>}
    <input aria-invalid={!!error} className={`w-full rounded-md border px-3 py-2 outline-none focus:ring-2 ${error ? 'border-rose-500 bg-rose-50/40 focus:border-rose-500 focus:ring-rose-100' : 'border-slate-300 focus:border-sky-500 focus:ring-sky-100'} ${className}`} {...props} />
    {error && <span className="mt-1.5 block text-xs font-medium text-rose-600">{error}</span>}
  </label>;
}
