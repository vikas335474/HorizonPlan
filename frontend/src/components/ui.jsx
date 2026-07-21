// Shared UI primitives for the fintech design system. Kept in one file since
// each is small; all derive their colors and elevation from the CSS tokens in
// index.css. Props are additive/backward-compatible — existing call sites keep
// working, they just render with more depth and motion now.

export function Card({ children, className = '', hover = false, elevated = true, style, ...props }) {
  return (
    <div
      className={`bg-[var(--color-surface)] border border-[var(--color-line)] rounded-[var(--radius-card)] ${hover ? 'lift cursor-pointer' : ''} ${className}`}
      style={{ boxShadow: elevated ? 'var(--shadow-sm)' : undefined, ...style }}
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
      style={{
        color: fg,
        backgroundColor: bg,
        // A hairline inset ring the color of the text gives soft-fill badges a
        // crisp edge against light cards — subtle, but it reads as intentional.
        boxShadow: fg ? `inset 0 0 0 1px color-mix(in srgb, ${fg} 18%, transparent)` : undefined,
      }}
    >
      {children}
    </span>
  );
}

export function EmptyState({ title, children, action, icon }) {
  return (
    <div className="flex flex-col items-center justify-center text-center py-16 px-6 animate-rise">
      <div
        className="mb-4 h-14 w-14 rounded-2xl flex items-center justify-center"
        style={{ background: 'linear-gradient(135deg, var(--color-teal-soft), #d5eae5)', boxShadow: 'var(--shadow-sm)' }}
      >
        {icon || (
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M4 18V10M9 18V5M14 18v-6M19 18V8" stroke="var(--color-teal-ink)" strokeWidth="1.8" strokeLinecap="round" />
          </svg>
        )}
      </div>
      <h3 className="text-base font-semibold text-[var(--color-ink)]">{title}</h3>
      {children && <p className="mt-1.5 max-w-sm text-sm text-[var(--color-ink-2)] leading-relaxed">{children}</p>}
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

const BUTTON_SIZES = {
  sm: 'px-3 py-1.5 text-[13px] gap-1',
  md: 'px-4 py-2 text-sm gap-1.5',
  lg: 'px-5 py-2.5 text-[15px] gap-2',
};

export function Button({ children, variant = 'primary', size = 'md', className = '', style, ...props }) {
  // Base classes shared by every variant. Depth + a 1px press translate give
  // clicks a physical response; the transition keeps it smooth.
  const base =
    'inline-flex items-center justify-center rounded-[var(--radius-ctrl)] font-medium transition-all duration-150 ' +
    'active:translate-y-px disabled:opacity-50 disabled:cursor-not-allowed disabled:active:translate-y-0 ' +
    'focus-visible:outline-2 focus-visible:outline-offset-2';

  const variants = {
    // Ink primary with a subtle top-lit gradient + soft shadow — reads premium,
    // like the CTAs in Ramp/Linear, not a flat filled rectangle.
    primary: 'text-white hover:brightness-110',
    // Growth-accent CTA for the moments where teal is the right emphasis.
    teal: 'text-white hover:brightness-108',
    ghost: 'text-[var(--color-ink-2)] hover:text-[var(--color-ink)] hover:bg-[var(--color-surface-2)]',
    outline: 'border border-[var(--color-line-2)] text-[var(--color-ink)] bg-[var(--color-surface)] hover:bg-[var(--color-surface-2)] hover:border-[var(--color-ink-3)] shadow-[var(--shadow-xs)]',
  };

  const variantStyle =
    variant === 'primary'
      ? { background: 'var(--grad-ink)', boxShadow: 'var(--shadow-sm)' }
      : variant === 'teal'
        ? { background: 'var(--grad-teal)', boxShadow: 'var(--shadow-teal)' }
        : undefined;

  return (
    <button
      className={`${base} ${BUTTON_SIZES[size]} ${variants[variant]} ${className}`}
      style={{ ...variantStyle, ...style }}
      {...props}
    >
      {children}
    </button>
  );
}
