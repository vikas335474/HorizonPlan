// docs/02 Section 3.6: this string is compliance-relevant, not cosmetic —
// treat changes to it with the same care as changes to the auth logic.
// All MVP tenants are distribution-mode (advisory_mode is Super Admin-only
// and gated on off-platform RIA review, per CLAUDE.md rule #2), so this
// always renders the distribution-mode copy for now. When advisory_mode
// becomes readable from the API, branch on it here instead of hardcoding.
const DISTRIBUTION_MODE_COPY =
  'This is an illustration to help you think through your goals, not personalized investment advice.';

export default function DisclosureBanner() {
  return (
    <div
      role="note"
      className="border-l-2 pl-3 py-2 text-sm text-[var(--color-ink-soft)]"
      style={{ borderColor: 'var(--color-brass)' }}
    >
      {DISTRIBUTION_MODE_COPY}
    </div>
  );
}
