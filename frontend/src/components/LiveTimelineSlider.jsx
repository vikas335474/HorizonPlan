import { useEffect, useState, useRef } from 'react';

/**
 * Live Timeline Slider (blueprint Section 6): local state while dragging, a
 * single commit on release. onChange for a range input fires continuously
 * (like native "input"), so continuous visual updates and the release-commit
 * are split across handlers — onCommit fires once on mouseup/touchend, and
 * once per discrete keypress for keyboard users.
 */
export default function LiveTimelineSlider({
  label,
  value,
  min,
  max,
  step,
  unit = '%',
  onCommit,
  disabled = false,
  helpText,
}) {
  const [localValue, setLocalValue] = useState(value);
  const lastCommitted = useRef(value);

  useEffect(() => {
    setLocalValue(value);
    lastCommitted.current = value;
  }, [value]);

  function commit() {
    if (localValue === lastCommitted.current) return;
    lastCommitted.current = localValue;
    onCommit(localValue);
  }

  const pct = ((localValue - min) / (max - min)) * 100;

  return (
    <div>
      <div className="flex items-baseline justify-between mb-2">
        <label className="text-sm font-medium text-[var(--color-ink-2)]">{label}</label>
        <span className="tnum text-sm font-semibold text-[var(--color-ink)]">
          {localValue.toFixed(2)}
          <span className="text-[var(--color-ink-3)]">{unit}</span>
        </span>
      </div>
      <input
        type="range"
        min={min}
        max={max}
        step={step}
        value={localValue}
        disabled={disabled}
        onChange={(e) => setLocalValue(parseFloat(e.target.value))}
        onMouseUp={commit}
        onTouchEnd={commit}
        onKeyUp={commit}
        aria-label={label}
        style={{
          background: `linear-gradient(to right, var(--color-teal) ${pct}%, var(--color-line-2) ${pct}%)`,
        }}
      />
      {helpText && <p className="mt-2 text-xs leading-relaxed text-[var(--color-ink-3)]">{helpText}</p>}
    </div>
  );
}
