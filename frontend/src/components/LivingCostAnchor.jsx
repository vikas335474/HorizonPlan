// A sourced living-cost anchor for someone who doesn't know their own monthly
// spend (MOSPI HCES, via api/lib/LivingCostReference.php).
//
// WHY IT LOOKS LIKE THIS. The obvious build is a button that fills the expense
// field with a national average. That would be the harmful version: MPCE is
// average per-capita consumption across ALL households, and the people using
// this product spend well above it. An uncritical anchor understates the
// retirement target, and understating is the one error this app must never
// make quietly.
//
// So this component:
//   * NEVER writes to the person's expense field. It sits beside it as
//     context. Copying a figure across is the person's decision, made with
//     the caveat in front of them (docs/11 design principle 2 — distinguish
//     OUR assumption from the person's own figure at a glance).
//   * renders the server's own `caveat` string verbatim rather than a friendlier
//     local paraphrase, so the warning cannot drift from the one the API
//     commits to.
//   * says out loud when the figure is a NATIONAL fallback rather than their
//     state's, and when the row is still unverified. A national average
//     presented as a local one is a figure that looks personalised and isn't.
//
// Collapsed by default: someone who already knows their spending should never
// have to read any of this.

import { useState } from 'react';
import { api } from '../lib/api';

// Only the states the reference cache actually holds (LivingCostReference.php
// is deliberately partial — inventing the rest so the dropdown looked complete
// would be exactly the fabrication CLAUDE.md forbids). Anything not here
// resolves to the all-India figure, and the response flags it.
const STATES = [
  { key: '', label: 'Not listed / not sure' },
  { key: 'andhra_pradesh', label: 'Andhra Pradesh' },
  { key: 'chhattisgarh', label: 'Chhattisgarh' },
  { key: 'karnataka', label: 'Karnataka' },
  { key: 'kerala', label: 'Kerala' },
  { key: 'maharashtra', label: 'Maharashtra' },
  { key: 'punjab', label: 'Punjab' },
  { key: 'sikkim', label: 'Sikkim' },
  { key: 'tamil_nadu', label: 'Tamil Nadu' },
  { key: 'telangana', label: 'Telangana' },
];

function formatRupees(n) {
  return `₹${Math.round(n).toLocaleString('en-IN')}`;
}

export default function LivingCostAnchor({ clientId }) {
  const [open, setOpen] = useState(false);
  const [state, setState] = useState('');
  const [sector, setSector] = useState('urban');
  const [size, setSize] = useState('');
  const [result, setResult] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  async function lookUp() {
    setLoading(true);
    setError('');
    try {
      const res = await api.getLivingCostEstimate({
        state: state || undefined,
        sector,
        householdSize: size ? Number(size) : undefined,
        clientId,
      });
      setResult(res);
    } catch (err) {
      setError(err.message || 'Could not look that up.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="mt-3 rounded-lg border border-[var(--color-line-2)]">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left"
      >
        <span className="text-sm font-medium text-[var(--color-ink)]">
          Not sure what you spend each month?
        </span>
        <svg
          width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"
          style={{ transform: open ? 'rotate(180deg)' : 'none', transition: 'transform 150ms' }}
        >
          <path d="M4 6l4 4 4-4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>

      {open && (
        <div className="border-t border-[var(--color-line)] px-4 py-4">
          <p className="text-xs leading-relaxed text-[var(--color-ink-2)]">
            We can show what an average household spends where you live, from the government's
            own consumption survey. It's a starting point to react to — not your number.
          </p>

          <div className="mt-3 flex flex-wrap items-end gap-3">
            <label className="flex flex-col gap-1">
              <span className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">State</span>
              <select value={state} onChange={(e) => setState(e.target.value)} className="field">
                {STATES.map((s) => (
                  <option key={s.key} value={s.key}>{s.label}</option>
                ))}
              </select>
            </label>

            <label className="flex flex-col gap-1">
              <span className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">Where you live</span>
              <select value={sector} onChange={(e) => setSector(e.target.value)} className="field">
                <option value="urban">Town or city</option>
                <option value="rural">Village or rural area</option>
              </select>
            </label>

            <label className="flex flex-col gap-1">
              <span className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">People in your home</span>
              <input
                type="text"
                inputMode="numeric"
                value={size}
                onChange={(e) => setSize(e.target.value.replace(/[^0-9]/g, ''))}
                placeholder="e.g. 4"
                className="field w-28"
              />
            </label>

            <button
              type="button"
              onClick={lookUp}
              disabled={loading}
              className="rounded-lg border border-[var(--color-line-2)] px-3 py-2 text-sm font-medium hover:bg-[var(--color-surface-2)] disabled:opacity-50"
            >
              {loading ? 'Looking…' : 'Show me'}
            </button>
          </div>

          {error && <p className="mt-3 text-xs" style={{ color: 'var(--color-alert)' }}>{error}</p>}

          {result && result.estimate === null && (
            <p className="mt-3 text-xs text-[var(--color-ink-2)]">{result.caveat}</p>
          )}

          {result?.estimate && (
            <div className="mt-4 rounded-lg px-4 py-3" style={{ backgroundColor: 'var(--color-surface-2)' }}>
              <div className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">
                Average household like yours
              </div>
              <div className="mt-0.5 text-2xl font-semibold tabular-nums text-[var(--color-ink)]">
                {formatRupees(result.estimate.monthly_household)}
                <span className="text-sm font-normal text-[var(--color-ink-3)]"> a month</span>
              </div>
              <p className="mt-1 text-[11px] text-[var(--color-ink-2)] tabular-nums">
                {formatRupees(result.estimate.per_capita)} per person × {result.estimate.household_size}{' '}
                {result.estimate.household_size === 1 ? 'person' : 'people'}
              </p>

              {/* The server's own caveat, verbatim — see the header. */}
              <p className="mt-2 text-[11px] leading-relaxed text-[var(--color-ink)]">
                {result.caveat}
              </p>

              {result.estimate.is_fallback && (
                <p className="mt-2 text-[11px] leading-relaxed text-[var(--color-ink-2)]">
                  We don't hold a figure for your state yet, so this is the all-India average.
                </p>
              )}

              <p className="mt-2 text-[11px] leading-relaxed text-[var(--color-ink-3)]">
                {result.estimate.is_verified ? 'Source: ' : 'Unverified figure. Source: '}
                {result.source?.url ? (
                  <a
                    href={result.source.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="underline"
                  >
                    {result.source.name}
                  </a>
                ) : (
                  result.source?.name
                )}
              </p>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
