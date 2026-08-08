// "How much will I need, when, and how am I doing?" — the three things a
// person planning for themselves opens the app to find out, and which nothing
// in it previously answered.
//
// WHY THIS DID NOT EXIST. The advisor product never needed it: an advisor
// arrives with a target already in mind and enters a corpus to model toward it.
// Someone planning alone has no target — they know what they spend today, and
// need the app to turn that into a number for a year decades away. Until now
// the app showed them a projection chart and a 0-100 score and left the actual
// question ("so what number am I aiming at?") unanswered.
//
// Arithmetic lives in PlanMath::retirementTarget() and arrives on the
// projection response as `retirement_target`. Null when no monthly expenses are
// recorded — there is no defensible way to invent someone's cost of living, and
// a guessed target is worse than none because it would anchor a real decision.
//
// THE ASSUMPTION IS RENDERED, NOT BURIED. The target assumes the person spends
// in retirement roughly what they spend now, adjusted for inflation. That is
// the standard planning starting point AND is frequently wrong for an
// individual (a paid-off home, grown children, higher medical costs). Stating
// the number without stating what produced it would be the dishonest version of
// this card, so the assumption sits directly under the figure — not in a
// tooltip, not in a footnote.

import { Card } from './ui';
import { formatCurrency } from '../lib/format';

// Years-and-months, because "24 years" alone reads as abstract and "292 months"
// alone reads as alarming. Both together is how people actually hold it.
function countdownLabel(years) {
  if (years <= 0) return 'This year';
  const months = Math.round(years * 12);
  const y = Math.floor(months / 12);
  const m = months % 12;
  if (y === 0) return `${m} month${m === 1 ? '' : 's'} away`;
  if (m === 0) return `${y} year${y === 1 ? '' : 's'} away`;
  return `${y} year${y === 1 ? '' : 's'}, ${m} month${m === 1 ? '' : 's'} away`;
}

function Figure({ label, value, tone = 'ink', sub }) {
  const color = tone === 'teal' ? 'var(--color-teal-ink)' : 'var(--color-ink)';
  return (
    <div>
      <p className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">{label}</p>
      <p className="mt-0.5 text-[22px] font-semibold leading-tight tabular-nums tracking-tight" style={{ color }}>
        {value}
      </p>
      {sub && <p className="mt-0.5 text-[11px] text-[var(--color-ink-3)]">{sub}</p>}
    </div>
  );
}

/**
 * @param target      projection.retirement_target (null when unavailable)
 * @param plain       consumer vocabulary (self-directed) vs advisor vocabulary
 * @param retirementAge  for the "by age N" phrasing
 */
export default function RetirementTargetCard({ target, plain = false, retirementAge }) {
  // No expenses recorded. Rather than hiding the card entirely — which leaves
  // the person with no idea the number is obtainable — say what is missing and
  // what it would unlock.
  if (!target) {
    if (!plain) return null;
    return (
      <Card className="p-4 mb-4">
        <h2 className="text-base font-semibold text-[var(--color-ink)]">How much will you need?</h2>
        <p className="mt-1 text-[13px] leading-relaxed text-[var(--color-ink-2)]">
          Add your monthly expenses in the cash-flow section and this will work out the amount you&rsquo;d
          need saved by then — and how close this plan gets you.
        </p>
      </Card>
    );
  }

  const covered = target.covered_pct;
  const onTrack = target.gap >= 0;
  // Clamped only for the BAR's width. The percentage itself is reported
  // unclamped below, so somebody comfortably ahead sees that they are.
  const barPct = Math.max(0, Math.min(100, covered));

  return (
    <Card className="p-4 mb-4" data-tour="retirement-target-card">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-base font-semibold text-[var(--color-ink)]">
          {plain ? 'How much will you need?' : 'Retirement target'}
        </h2>
        <span className="text-xs font-medium text-[var(--color-ink-2)]">
          {countdownLabel(target.years_to_retirement)}
          {retirementAge ? ` · at ${retirementAge}` : ''}
        </span>
      </div>

      <div className="mt-3 grid gap-4 sm:grid-cols-3">
        <Figure
          label={plain ? "You'd need about" : 'Corpus needed'}
          value={formatCurrency(target.corpus_needed)}
          sub={`to draw ${formatCurrency(target.annual_spend_at_retirement)}/yr`}
        />
        <Figure
          label={plain ? 'This plan reaches' : 'Projected corpus'}
          value={formatCurrency(target.projected_corpus)}
          tone="teal"
        />
        <Figure
          label={onTrack ? 'Ahead by' : 'Short by'}
          value={formatCurrency(Math.abs(target.gap))}
          sub={`${covered.toFixed(0)}% of the target`}
        />
      </div>

      <div className="mt-3 h-2 w-full overflow-hidden rounded-full" style={{ backgroundColor: 'var(--color-surface-2)' }}>
        <div
          className="h-full rounded-full transition-all"
          style={{
            width: `${barPct}%`,
            backgroundColor: onTrack ? 'var(--color-teal)' : 'var(--color-amber)',
          }}
        />
      </div>

      {/* The assumption, stated plainly and always — see the file header. */}
      <p className="mt-2.5 text-[11px] leading-relaxed text-[var(--color-ink-3)]">
        Assumes you&rsquo;d spend about what you spend now, grown by inflation, and that you draw down at
        this plan&rsquo;s withdrawal rate. If your spending in retirement would be different — a paid-off
        home, or higher medical costs — the target moves with it.
      </p>
    </Card>
  );
}
