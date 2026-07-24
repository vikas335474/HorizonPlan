import { useAuth } from '../context/AuthContext';

// docs/02 Section 3.6: these strings are compliance-relevant, not cosmetic —
// treat changes to them with the same care as changes to the auth logic. The
// mode is read from the tenant (never hardcoded): all MVP tenants are
// distribution-mode, and a tenant is only ever switched to advisory mode
// server-side by a Super Admin after an RIA partner has reviewed it.
const DISTRIBUTION_MODE_COPY =
  'This is an illustration to help you think through your goals, not personalized investment advice.';

// Advisory-mode tenants are a Phase 2 activation (see docs/04). Wiring the
// branch now closes the "hardcoded banner" gap so this renders correctly the
// moment a tenant is flipped. NOTE: confirm the exact advisory wording with the
// RIA partner before switching any tenant to advisory mode — like the auth
// logic, this string carries compliance weight.
const ADVISORY_MODE_COPY =
  'Prepared by your SEBI-registered investment adviser as part of your personalised financial plan.';

// `mode` can be passed explicitly (e.g. a print/share view that renders for a
// specific tenant outside the live auth context); otherwise it comes from the
// authenticated tenant, defaulting to the conservative distribution copy.
export default function DisclosureBanner({ compact = false, mode }) {
  const { tenant } = useAuth();
  const effectiveMode = mode ?? tenant?.advisoryMode ?? 'distribution';
  const copy = effectiveMode === 'advisory' ? ADVISORY_MODE_COPY : DISTRIBUTION_MODE_COPY;

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
      <p className="text-xs leading-relaxed text-[var(--color-ink-2)]">{copy}</p>
    </div>
  );
}
