import { useEffect, useState, useRef } from 'react';

/**
 * Original blueprint's Live Timeline Slider (Section 6): local state updates
 * while dragging, a single dispatch on release. React's onChange for a
 * range input fires continuously (behaves like native "input", not native
 * "change"), so continuous updates and the release-commit are deliberately
 * split across two handlers here rather than relying on onChange alone.
 *
 * `onCommit` fires once per drag (on mouseup/touchend), and once per
 * discrete key press for keyboard users (arrow keys don't have a
 * "release" event, so each keystroke is its own commit — still far from
 * "per drag frame").
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

  // Parent value changes underneath us (e.g. after a reset) — resync.
  useEffect(() => {
    setLocalValue(value);
    lastCommitted.current = value;
  }, [value]);

  function commit() {
    if (localValue === lastCommitted.current) return;
    lastCommitted.current = localValue;
    onCommit(localValue);
  }

  return (
    <div>
      <div className="flex items-baseline justify-between mb-1">
        <label className="text-sm text-[var(--color-ink-soft)]">{label}</label>
        <span className="text-sm" style={{ fontFamily: 'var(--font-mono)', color: 'var(--color-ink)' }}>
          {localValue.toFixed(2)}
          {unit}
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
        className="w-full accent-[var(--color-brass)] disabled:opacity-50"
      />
      {helpText && <p className="mt-1 text-xs text-[var(--color-ink-soft)]">{helpText}</p>}
    </div>
  );
}
