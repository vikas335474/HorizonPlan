// docs/13 I-10 · Side-by-side scenario comparison.
//
// WHY THIS EXISTS. Sub-scenarios have always been an accordion on GoalDetail:
// one open at a time, so answering "is retiring two years later better than
// saving more?" meant opening one, remembering four numbers, opening the
// other, and comparing from memory. Comparing options is the entire point of
// having scenarios; doing it in your head is where the value leaked out. This
// is the one thing worth borrowing from Boldin and ProjectionLab (docs/13 §3),
// and it needs no new backend at all.
//
// READ-ONLY ON PURPOSE. Editing stays in ScenarioPanel. A grid where every
// cell is editable is a spreadsheet, and a spreadsheet is what this product
// exists to replace — the comparison answers "which of these do I want?", then
// you go and change that one.
//
// It calls the SAME goals_projection.php the individual panels call, once per
// column. No second computation, so a column can never disagree with the panel
// it came from. Retirement goals only: a target-based goal carries no
// withdrawal or drawdown rate, so there is no projection to compare.

import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { Card, Spinner } from './ui';
import { ReadinessScoreBadge } from './ReadinessScore';
import { formatCurrency } from '../lib/format';

/** Last value of a projection series — what's left at the end of the horizon. */
function endingCorpus(series) {
  if (!Array.isArray(series) || series.length === 0) return null;
  return series[series.length - 1];
}

/**
 * The year the money runs out, or null if it lasts the whole horizon.
 * Stated in years-from-now because "runs out in year 24" is a fact a person
 * can act on, where a bare ending balance of 0 is not.
 */
function depletionYear(series) {
  if (!Array.isArray(series)) return null;
  const idx = series.findIndex((v) => v <= 0);
  return idx === -1 ? null : idx;
}

/**
 * @param goal          the base goal
 * @param subScenarios  every sub-scenario on it
 * @param plain         consumer vocabulary (self-directed) vs advisor vocabulary
 */
export default function ScenarioCompare({ goal, subScenarios, plain = false }) {
  const [columns, setColumns] = useState(null);
  const [error, setError] = useState('');

  // A stable key over the scenario ids: GoalDetail hands a fresh array on every
  // change, and depending on the array itself would refetch every column on
  // each render.
  const scenarioKey = (subScenarios || []).map((s) => s.id).join(',');

  useEffect(() => {
    let cancelled = false;
    setError('');
    setColumns(null);

    // Baseline first, then each scenario in the order they appear on the page,
    // so a column's position here matches its position there.
    const jobs = [
      api.getProjection(goal.id).then((res) => ({
        id: 'baseline',
        label: plain ? 'Your plan' : 'Baseline',
        projection: res,
      })),
      ...(subScenarios || []).map((s, idx) =>
        api.getProjection(goal.id, s.id).then((res) => ({
          id: s.id,
          label: `Scenario ${idx + 1}`,
          isOverridden: s.is_overridden,
          projection: res,
        }))
      ),
    ];

    Promise.all(jobs)
      .then((cols) => { if (!cancelled) setColumns(cols); })
      .catch((err) => {
        if (!cancelled) setError(err.message || 'Could not load the comparison.');
      });

    return () => { cancelled = true; };
    // scenarioKey stands in for subScenarios deliberately — see its definition
    // above. Same pattern, and same reason, as GoalDetail's own baseline effect.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [goal.id, scenarioKey, plain]);

  if (error) {
    return (
      <Card className="p-4">
        <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
      </Card>
    );
  }

  if (!columns) return <Spinner label="Working out the comparison…" />;

  // Rows are defined once, each knowing how to read itself out of a column, so
  // adding a row later is one entry rather than an edit in three places.
  const rows = [
    {
      label: plain ? 'How the money holds up' : 'Readiness score',
      render: (c) =>
        c.projection.readiness_score != null
          ? <ReadinessScoreBadge score={c.projection.readiness_score} />
          : <span className="text-[var(--color-ink-3)]">—</span>,
    },
    {
      label: plain ? 'Yearly price rises assumed' : 'Inflation',
      render: (c) => `${c.projection.assumptions?.inflation_rate ?? '—'}%`,
    },
    {
      label: plain ? 'Share taken each year' : 'Withdrawal rate',
      render: (c) => `${c.projection.assumptions?.withdrawal_rate ?? '—'}%`,
    },
    {
      label: plain ? 'Left at the end' : 'Ending corpus (steady)',
      render: (c) => {
        const v = endingCorpus(c.projection.steady_return_series);
        return v == null ? '—' : formatCurrency(v);
      },
    },
    {
      // The honest one. A steady-return ending balance flatters every plan;
      // this is the same series run through a poor early sequence, which is
      // the risk that actually ends retirements (docs/05).
      label: plain ? 'If the bad years come first' : 'Ending corpus (adverse sequence)',
      render: (c) => {
        const series = c.projection.adverse_sequence_series;
        const depleted = depletionYear(series);
        if (depleted !== null) {
          return (
            <span style={{ color: 'var(--color-alert)' }}>
              {plain ? `Runs out in year ${depleted}` : `Depleted, year ${depleted}`}
            </span>
          );
        }
        const v = endingCorpus(series);
        return v == null ? '—' : formatCurrency(v);
      },
    },
  ];

  return (
    <Card className="p-0 overflow-hidden">
      {/* Wide tables scroll inside their own container rather than pushing the
          page sideways — the roster tables already do this. */}
      <div className="overflow-x-auto">
        <table className="w-full text-sm border-collapse">
          <thead>
            <tr className="border-b border-[var(--color-line)]">
              <th className="sticky left-0 z-10 bg-[var(--color-surface)] px-4 py-3 text-left text-[11px] uppercase tracking-wide text-[var(--color-ink-3)] font-medium">
                {plain ? 'What changes' : 'Assumption'}
              </th>
              {columns.map((c) => (
                <th key={c.id} className="px-4 py-3 text-left min-w-[140px]">
                  <span className="text-sm font-semibold text-[var(--color-ink)]">{c.label}</span>
                  {c.isOverridden && (
                    <span className="mt-0.5 block text-[10px] uppercase tracking-wide text-[var(--color-alert)]">
                      Customized
                    </span>
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.label} className="border-b border-[var(--color-line)] last:border-0">
                <th
                  scope="row"
                  className="sticky left-0 z-10 bg-[var(--color-surface)] px-4 py-3 text-left text-xs font-medium text-[var(--color-ink-2)]"
                >
                  {row.label}
                </th>
                {columns.map((c) => (
                  <td key={c.id} className="px-4 py-3 tabular-nums text-[var(--color-ink)]">
                    {row.render(c)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="border-t border-[var(--color-line)] px-4 py-3 text-[11px] leading-relaxed text-[var(--color-ink-3)]">
        Every column is an illustration built from the assumptions above it, not a forecast. The
        "{plain ? 'if the bad years come first' : 'adverse sequence'}" row runs the same average
        return with the poor years early — the order returns arrive in changes the outcome even
        when the average does not.
      </p>
    </Card>
  );
}
