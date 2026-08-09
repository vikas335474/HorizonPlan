// Summary card for one goal in a grid — prop {goal}. Shows the label, type and
// readiness signal and links to /goals/:id. Uses useAuth so an advisor
// (role !== 'client') sees advisor-oriented framing while a client sees the
// client-facing version.

import { Link } from 'react-router-dom';
import { Badge } from './ui';
import { ReadinessScoreBadge } from './ReadinessScore';
import { ReviewStatusBadge } from './PlanReviewUI';
import { useAuth } from '../context/AuthContext';
import {
  formatCurrency,
  formatDate,
  formatPercent,
  GOAL_TYPE_LABELS,
  GOAL_TYPE_ACCENT,
} from '../lib/format';

export default function GoalCard({ goal }) {
  const { user } = useAuth();
  const accent = GOAL_TYPE_ACCENT[goal.goal_type] || GOAL_TYPE_ACCENT.other;
  const isRetirement = goal.goal_type === 'retirement';

  return (
    <Link
      to={`/goals/${goal.id}`}
      data-tour="goal-card"
      data-goal-id={goal.id}
      data-has-readiness={goal.readiness_score !== null && goal.readiness_score !== undefined}
      className="lift group relative block overflow-hidden bg-[var(--color-surface)] border border-[var(--color-line)] rounded-[var(--radius-card)] p-4 pl-5"
      style={{ boxShadow: 'var(--shadow-sm)' }}
    >
      {/* Type-keyed accent spine — makes a grid of goals scannable by color */}
      <span className="absolute left-0 top-0 h-full w-1" style={{ backgroundColor: accent.fg, opacity: 0.85 }} aria-hidden="true" />

      <div className="flex items-start justify-between gap-3 mb-3">
        <div className="min-w-0">
          <h3 className="text-[15px] font-semibold text-[var(--color-ink)] truncate">{goal.goal_label}</h3>
        </div>
        <div className="flex items-center gap-1.5 shrink-0">
          {/* goals_list.php only sends readiness_score for retirement goals
              with enough set to project — null (omitted visually) otherwise. */}
          {goal.readiness_score !== null && goal.readiness_score !== undefined && (
            <ReadinessScoreBadge score={goal.readiness_score} />
          )}
          {user?.role !== 'client' && <ReviewStatusBadge status={goal.review_status} />}
          <Badge fg={accent.fg} bg={accent.bg}>
            {GOAL_TYPE_LABELS[goal.goal_type] || goal.goal_type}
          </Badge>
        </div>
      </div>

      {/* Key figures — tabular, aligned */}
      <div className="grid grid-cols-2 gap-x-4 gap-y-2.5">
        <Figure label="Starting corpus" value={formatCurrency(goal.initial_net_worth)} />
        {goal.target_amount !== null ? (
          <Figure label="Target" value={formatCurrency(goal.target_amount)} />
        ) : (
          <Figure label="Inflation" value={formatPercent(goal.inflation_rate)} />
        )}
        {isRetirement && goal.withdrawal_rate !== null && (
          <Figure label="Withdrawal rate" value={formatPercent(goal.withdrawal_rate)} />
        )}
        {goal.target_date && <Figure label="Target date" value={formatDate(goal.target_date)} />}
      </div>

      {/* Funding status for a target-based goal (education / home / other).
          These goal types never get a Readiness Score — they carry no
          withdrawal or drawdown rate to project from — so before this they
          showed only static parameters and no progress signal at all.
          `target_funding` comes from goals_list.php (PlanMath::targetGoalFunding)
          and deliberately assumes NO growth: it's what the goal will cost in
          target-date rupees against what's saved today, which is a floor, not
          a forecast. The copy says so rather than letting the bar imply one. */}
      {goal.target_funding && (
        <div className="mt-3 pt-3 border-t border-[var(--color-line)]">
          <div className="flex items-baseline justify-between mb-1.5">
            <span className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">
              Covered today
            </span>
            <span
              className="text-xs font-semibold tabular-nums"
              style={{ color: goal.target_funding.is_met ? 'var(--color-teal-ink)' : 'var(--color-ink)' }}
            >
              {/* docs/12 Prompt D-3 — is_met reads the unclamped ratio, so a
                  goal that only just crosses the line still shows the
                  celebratory state even though covered_pct itself clamps
                  its DISPLAY figure at 100%. */}
              {goal.target_funding.is_met ? 'Goal met' : `${goal.target_funding.covered_pct}%`}
            </span>
          </div>
          <div
            className="h-1.5 w-full rounded-full bg-[var(--color-line)] overflow-hidden"
            role="img"
            aria-label={`${goal.target_funding.covered_pct}% of the inflation-adjusted target is covered by the current corpus`}
          >
            <div
              className="h-full rounded-full"
              style={{
                width: `${Math.max(2, goal.target_funding.covered_pct)}%`,
                backgroundColor: goal.target_funding.is_met ? 'var(--color-teal-ink)' : 'var(--color-teal)',
              }}
            />
          </div>
          <p className="mt-1.5 text-[11px] leading-snug text-[var(--color-ink-3)]">
            {goal.target_funding.is_met
              ? `Today's corpus already covers the ~${formatCurrency(goal.target_funding.inflated_target)} this needs by then at ${formatPercent(goal.inflation_rate)} inflation.`
              : <>Needs ~{formatCurrency(goal.target_funding.inflated_target)} by then at{' '}
                  {formatPercent(goal.inflation_rate)} inflation. Assumes no growth on what's saved.</>}
          </p>
        </div>
      )}

      <div className="mt-3 pt-3 border-t border-[var(--color-line)] flex items-center justify-between">
        <span className="text-xs text-[var(--color-ink-3)]">
          {goal.projection_horizon_years}-year horizon
        </span>
        <span className="inline-flex items-center gap-1 text-xs font-semibold text-[var(--color-teal-ink)]">
          Open
          <svg width="13" height="13" viewBox="0 0 14 14" fill="none" className="transition-transform duration-200 group-hover:translate-x-0.5" aria-hidden="true">
            <path d="M5 3l4 4-4 4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        </span>
      </div>
    </Link>
  );
}

function Figure({ label, value }) {
  return (
    <div>
      <div className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">{label}</div>
      <div className="tnum text-sm text-[var(--color-ink)] mt-0.5">{value}</div>
    </div>
  );
}
