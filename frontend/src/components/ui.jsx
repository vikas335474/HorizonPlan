// Shared UI primitives for the fintech design system. Kept in one file since
// each is small; all derive their colors from the CSS tokens in index.css.

export function Card({ children, className = '', ...props }) {
  return (
    <div
      className={`bg-[var(--color-surface)] border border-[var(--color-line)] rounded-[var(--radius-card)] ${className}`}
      {...props}
    >
      {children}
    </div>
  );
}

// Dashboard header metric — big tabular number, small uppercase label above.
export function StatCard({ label, value, sublabel, accent = 'ink' }) {
  const valueColor =
    accent === 'teal' ? 'var(--color-teal-ink)' : accent === 'amber' ? 'var(--color-amber)' : 'var(--color-ink)';
  return (
    <Card className="p-5">
      <div className="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-3)]">
        {label}
      </div>
      <div className="tnum mt-2 text-2xl font-semibold" style={{ color: valueColor }}>
        {value}
      </div>
      {sublabel && <div className="mt-1 text-xs text-[var(--color-ink-3)]">{sublabel}</div>}
    </Card>
  );
}

export function Badge({ children, fg, bg }) {
  return (
    <span
      className="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide"
      style={{ color: fg, backgroundColor: bg }}
    >
      {children}
    </span>
  );
}

export function EmptyState({ title, children, action }) {
  return (
    <div className="flex flex-col items-center justify-center text-center py-16 px-6">
      <div
        className="mb-4 h-12 w-12 rounded-full flex items-center justify-center"
        style={{ backgroundColor: 'var(--color-surface-2)' }}
      >
        <div className="h-5 w-5 rounded-sm" style={{ backgroundColor: 'var(--color-line-2)' }} />
      </div>
      <h3 className="text-base font-semibold text-[var(--color-ink)]">{title}</h3>
      {children && <p className="mt-1.5 max-w-sm text-sm text-[var(--color-ink-2)]">{children}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}

export function Spinner({ label }) {
  return (
    <div className="flex items-center gap-2.5 text-sm text-[var(--color-ink-3)] py-8 justify-center">
      <span
        className="h-4 w-4 rounded-full border-2 animate-spin"
        style={{ borderColor: 'var(--color-line-2)', borderTopColor: 'var(--color-teal)' }}
      />
      {label || 'Loading…'}
    </div>
  );
}

export function Button({ children, variant = 'primary', className = '', ...props }) {
  const styles = {
    primary: 'text-white',
    ghost: 'text-[var(--color-ink-2)] hover:text-[var(--color-ink)] hover:bg-[var(--color-surface-2)]',
    outline: 'border border-[var(--color-line-2)] text-[var(--color-ink)] hover:bg-[var(--color-surface-2)]',
  };
  return (
    <button
      className={`inline-flex items-center justify-center gap-1.5 rounded-[var(--radius-ctrl)] px-4 py-2 text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${styles[variant]} ${className}`}
      style={variant === 'primary' ? { backgroundColor: 'var(--color-ink)' } : undefined}
      {...props}
    >
      {children}
    </button>
  );
}
