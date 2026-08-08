// docs/07 Bet 3: Retirement Readiness Score — one deterministic 0-100 number,
// computed server-side by PlanMath::readinessScore() and returned on
// goals_projection.php's response. This component only renders it; the
// formula lives in one place (PlanMath), not duplicated here.
import { useState } from 'react';

// Exported so other views needing the same "what counts as low" cutoff (the
// Dashboard client list's needs-attention filter) read off one definition
// instead of a second hardcoded threshold that could drift from this one.
export function bandFor(score) {
  if (score >= 70) return { color: 'var(--color-teal-ink)', bg: 'var(--color-teal-soft)', label: 'Strong' };
  if (score >= 40) return { color: 'var(--color-amber)', bg: 'var(--color-amber-soft)', label: 'On track' };
  return { color: 'var(--color-alert)', bg: 'var(--color-alert-soft)', label: 'Needs attention' };
}

// docs/11 Prompt E-1: the Readiness Score asks one question — does the corpus
// survive its own withdrawal rate — and the Retirement Target asks a
// different one — does it cover what this person actually spends. They can
// disagree sharply: the personal demo showed 89/100 next to "44% of target"
// on one screen, which reads as the app contradicting itself.
//
// Per the docs/11 decision (suppress rather than blend or leave as-is): the
// score is hidden ONLY when a target exists AND it would otherwise say
// something the target directly contradicts — the score reads "on track" or
// better while the target shows a real, meaningful shortfall. When the score
// already agrees (it's in "Needs attention"), or there's no target to compare
// against, the score renders exactly as it always has — this never touches
// the advisor-facing meaning of the score on its own.
export function scoreContradictsTarget(score, target) {
  if (score === null || score === undefined || !target) return false;
  return score >= 40 && target.covered_pct < 100;
}

// Small inline pill — for contexts already dense with other numbers
// (e.g. inside a per-scenario panel next to the sequence-risk chart).
export function ReadinessScoreBadge({ score }) {
  if (score === null || score === undefined) return null;
  const { color, bg } = bandFor(score);
  return (
    <span
      className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
      style={{ color, backgroundColor: bg }}
    >
      <span className="tnum">{score}</span>
      <span className="opacity-80">Readiness</span>
    </span>
  );
}

// The prominent widget — "the number a client can hold onto" per docs/07 §2.
// Used at the top of GoalDetail (advisor working view) and PlanReport
// (what actually gets shown/handed to a client in a meeting).
export function ReadinessScoreCard({ score, compact = false }) {
  const [showFormula, setShowFormula] = useState(false);

  if (score === null || score === undefined) return null;

  const { color, bg, label } = bandFor(score);
  const size = compact ? 72 : 104;
  const stroke = compact ? 6 : 9;
  const radius = (size - stroke) / 2;
  const circumference = 2 * Math.PI * radius;
  const clamped = Math.max(0, Math.min(100, score));
  const offset = circumference * (1 - clamped / 100);

  return (
    <div className="rounded-[var(--radius-card)] border border-[var(--color-line)] bg-[var(--color-surface)] p-4 flex items-start gap-4">
      <div className="relative shrink-0" style={{ width: size, height: size }}>
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden="true">
          <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="var(--color-line)" strokeWidth={stroke} />
          <circle
            cx={size / 2}
            cy={size / 2}
            r={radius}
            fill="none"
            stroke={color}
            strokeWidth={stroke}
            strokeLinecap="round"
            strokeDasharray={circumference}
            strokeDashoffset={offset}
            transform={`rotate(-90 ${size / 2} ${size / 2})`}
            style={{ transition: 'stroke-dashoffset 0.4s ease' }}
          />
        </svg>
        <div className="absolute inset-0 flex flex-col items-center justify-center">
          <span className={`tnum font-semibold text-[var(--color-ink)] ${compact ? 'text-lg' : 'text-2xl'}`}>{score}</span>
          {!compact && <span className="text-[10px] text-[var(--color-ink-3)]">/ 100</span>}
        </div>
      </div>

      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2 mb-1">
          <h3 className="text-sm font-semibold text-[var(--color-ink)]">Retirement Readiness</h3>
          <span
            className="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
            style={{ color, backgroundColor: bg }}
          >
            {label}
          </span>
        </div>
        <p className="text-xs text-[var(--color-ink-2)] leading-relaxed">
          A single illustration score from this goal's projection — not a recommendation.
        </p>
        <button
          type="button"
          onClick={() => setShowFormula((v) => !v)}
          className="mt-1.5 text-xs font-medium text-[var(--color-teal-ink)] hover:underline"
        >
          {showFormula ? 'Hide' : 'How is this calculated?'}
        </button>
        {showFormula && (
          <ul className="mt-2 text-[11px] text-[var(--color-ink-3)] space-y-1 leading-relaxed">
            <li>• 40% — years the steady-return projection survives before the corpus would run out</li>
            <li>• 40% — years the adverse-sequence (bad-early-years) projection survives</li>
            <li>• 20% — how the withdrawal rate compares to the suggested 2.5%–4% Indian range</li>
          </ul>
        )}
      </div>
    </div>
  );
}
