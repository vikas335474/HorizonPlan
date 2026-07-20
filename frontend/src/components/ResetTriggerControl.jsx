// Reset Trigger Control (blueprint Section 6): clears manual overrides and
// resyncs the sub-scenario with its parent goal. Rendered only when the
// scenario is currently overridden.
export default function ResetTriggerControl({ onReset, resetting }) {
  return (
    <button
      type="button"
      onClick={onReset}
      disabled={resetting}
      className="inline-flex items-center gap-1.5 rounded-[var(--radius-ctrl)] px-2.5 py-1.5 text-xs font-medium transition-colors disabled:opacity-60"
      style={{ backgroundColor: 'var(--color-alert-soft)', color: 'var(--color-alert)' }}
      title="Clear manual overrides and resync with the parent goal"
    >
      <svg width="13" height="13" viewBox="0 0 13 13" fill="none" aria-hidden="true">
        <path d="M11 6.5a4.5 4.5 0 1 1-1.3-3.2" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" />
        <path d="M10.5 1.5v2.2H8.3" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
      {resetting ? 'Resetting…' : 'Reset to parent'}
    </button>
  );
}
