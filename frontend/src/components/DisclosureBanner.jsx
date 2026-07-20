// docs/02 Section 3.6: this string is compliance-relevant, not cosmetic —
// treat changes to it with the same care as changes to the auth logic.
// All MVP tenants are distribution-mode, so this always renders the
// distribution-mode copy. When advisory_mode becomes readable from the API,
// branch on it here instead of hardcoding.
const DISTRIBUTION_MODE_COPY =
  'This is an illustration to help you think through your goals, not personalized investment advice.';

export default function DisclosureBanner({ compact = false }) {
  return (
    <div
      role="note"
      className={`flex items-start gap-2 rounded-[var(--radius-ctrl)] ${
        compact ? 'px-3 py-2' : 'px-3.5 py-2.5'
      }`}
      style={{ backgroundColor: 'var(--color-surface-2)' }}
    >
      <svg width="15" height="15" viewBox="0 0 15 15" fill="none" className="mt-0.5 shrink-0" aria-hidden="true">
        <circle cx="7.5" cy="7.5" r="6.5" stroke="var(--color-ink-3)" strokeWidth="1.2" />
        <path d="M7.5 6.8v4" stroke="var(--color-ink-3)" strokeWidth="1.4" strokeLinecap="round" />
        <circle cx="7.5" cy="4.6" r="0.85" fill="var(--color-ink-3)" />
      </svg>
      <p className="text-xs leading-relaxed text-[var(--color-ink-2)]">{DISTRIBUTION_MODE_COPY}</p>
    </div>
  );
}
