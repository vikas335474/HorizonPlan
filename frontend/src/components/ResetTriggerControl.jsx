export default function ResetTriggerControl({ onReset, resetting }) {
  return (
    <button
      type="button"
      onClick={onReset}
      disabled={resetting}
      className="inline-flex items-center gap-1.5 rounded-sm px-2.5 py-1 text-xs disabled:opacity-60"
      style={{ backgroundColor: 'var(--color-alert-soft)', color: 'var(--color-alert)' }}
      title="Clear manual overrides and resync with the parent goal"
    >
      <span aria-hidden="true">↺</span>
      {resetting ? 'Resetting…' : 'Reset to parent'}
    </button>
  );
}
