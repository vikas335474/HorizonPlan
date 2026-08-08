// docs/11 Prompt E-3: the sourced reference-cost library's opt-in UI touchpoint —
// "shall I look this up for you?" — never a silent default (see reference_costs_get.php
// / api/lib/ReferenceCosts.php for the data side). This is a minimal, standalone
// slice of what Prompt E-2's progressive-personalisation flow will eventually
// own end to end; until E-2 builds the full "make this more accurate" queue,
// this is the one place a self-serve user meets the library: the education
// goal's target-amount field, where "government or private seat?" is the
// actual cost driver (docs/11 §3), not the aspiration.
//
// Every suggestion renders as a RANGE with its source and as-of date, is
// clearly marked "unverified" until a human confirms it against that source,
// and only ever fills the field when the user clicks "Use this" — nothing
// here writes a number on its own.

import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { formatCurrency } from '../lib/format';

/**
 * @param {{ onUseAmount: (amount: number) => void }} props
 */
export function EducationCostSuggestion({ onUseAmount }) {
  const [rows, setRows] = useState(null); // null = loading, [] = none cached yet
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    api.getReferenceCosts('education')
      .then((res) => {
        if (!cancelled) setRows(res.rows || []);
      })
      .catch(() => {
        if (!cancelled) {
          setRows([]);
          setError('Could not load reference costs right now.');
        }
      });
    return () => { cancelled = true; };
  }, []);

  // Loading, errored, or the sync has simply never run — either way there's
  // nothing honest to show, so render nothing rather than an empty box.
  if (!rows || rows.length === 0) return null;

  return (
    <div
      className="mt-3 rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] p-3"
      style={{ background: 'var(--color-surface-2, transparent)' }}
    >
      <p className="text-xs font-medium text-[var(--color-ink)] mb-0.5">
        Shall I look this up for you?
      </p>
      <p className="text-[11px] text-[var(--color-ink-3)] mb-2">
        The cost driver that actually moves this number is government vs. private, not the
        course itself — pick whichever's closer, then adjust. Always a range, never a promise.
      </p>
      {error && <p className="text-[11px] mb-2" style={{ color: 'var(--color-alert)' }}>{error}</p>}
      <div className="flex flex-col gap-1.5">
        {rows.map((r) => {
          const mid = Math.round((r.low + r.high) / 2);
          return (
            <button
              key={r.subcategory}
              type="button"
              onClick={() => onUseAmount(mid)}
              className="text-left rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] px-2.5 py-1.5 transition-colors hover:border-[var(--color-teal)]"
            >
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-medium text-[var(--color-ink)]">{r.label}</span>
                <span className="text-xs font-semibold text-[var(--color-ink)] whitespace-nowrap">
                  {formatCurrency(r.low)}–{formatCurrency(r.high)}
                </span>
              </div>
              <div className="text-[10px] text-[var(--color-ink-3)] mt-0.5">
                {r.source_name} · as of {r.as_of_date}
                {!r.is_verified && ' · unverified — check before relying on it'}
              </div>
            </button>
          );
        })}
      </div>
    </div>
  );
}
