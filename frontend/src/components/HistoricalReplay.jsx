// docs/07 Bet 2: historical sequence replay — "what if you retired in
// [year]?" Shared between ScenarioPanel, PlanReport, and MeetingMode so the
// selector and the unverified-data notice look and behave identically
// everywhere they appear.
import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';

// Dropdown of available replay start years, sourced from
// market_history_years.php (not hardcoded client-side) so it always matches
// whatever's actually seeded. Renders nothing while loading or if no years
// are seeded yet, so callers don't need to guard on that themselves.
export function ReplayYearSelect({ value, onChange, className = '' }) {
  const [years, setYears] = useState(null);

  useEffect(() => {
    let cancelled = false;
    api.listMarketHistoryYears()
      .then((res) => { if (!cancelled) setYears(res.years ?? []); })
      .catch(() => { if (!cancelled) setYears([]); });
    return () => { cancelled = true; };
  }, []);

  if (!years || years.length === 0) return null;

  return (
    <select
      className={`field w-auto ${className}`}
      value={value ?? ''}
      onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}
    >
      <option value="">Replay history from…</option>
      {years.map((y) => (
        <option key={y.year} value={y.year}>
          {y.year}{!y.is_verified ? ' (unverified)' : ''}
        </option>
      ))}
    </select>
  );
}

// Shown whenever a rendered historical series used at least one unverified
// year (sql/015 seeds every row as unverified until someone confirms it
// against a real source — see that migration's header comment). Deliberately
// loud, not a quiet badge: this is a data-accuracy caveat, not a cosmetic one.
//
// The caveat is the same for everyone; only who it warns differs. An advisor is
// warned off quoting the numbers to a client; a self-serve individual has no
// client, so they are warned off leaning on the numbers themselves. Gated on
// tenant kind, matching api/lib/SelfService.php.
export function UnverifiedHistoryNotice() {
  const { tenant } = useAuth();
  const isSelfDirected = tenant?.kind === 'personal';
  return (
    <p
      className="mt-2 flex items-start gap-1.5 text-xs rounded-[var(--radius-ctrl)] px-3 py-2"
      style={{ color: 'var(--color-amber)', backgroundColor: 'var(--color-amber-soft)' }}
    >
      <span aria-hidden="true">⚠</span>
      <span>
        This historical replay uses <strong>unverified</strong> illustrative market data — it has not yet
        been confirmed against an authoritative source.{' '}
        {isSelfDirected
          ? "Treat the shape as illustrative and don't lean on the specific numbers."
          : "Don't rely on the specific numbers with a client until this is reviewed."}
      </span>
    </p>
  );
}
