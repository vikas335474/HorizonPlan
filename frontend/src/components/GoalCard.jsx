import { Link } from 'react-router-dom';
import { Badge } from './ui';
import {
  formatCurrency,
  formatDate,
  formatPercent,
  GOAL_TYPE_LABELS,
  GOAL_TYPE_ACCENT,
} from '../lib/format';

export default function GoalCard({ goal }) {
  const accent = GOAL_TYPE_ACCENT[goal.goal_type] || GOAL_TYPE_ACCENT.other;
  const isRetirement = goal.goal_type === 'retirement';

  return (
    <Link
      to={`/goals/${goal.id}`}
      className="group block bg-[var(--color-surface)] border border-[var(--color-line)] rounded-[var(--radius-card)] p-4 hover:border-[var(--color-line-2)] hover:shadow-[0_2px_12px_rgba(15,23,41,0.06)] transition-all"
    >
      <div className="flex items-start justify-between gap-3 mb-3">
        <div className="min-w-0">
          <h3 className="text-[15px] font-semibold text-[var(--color-ink)] truncate">{goal.goal_label}</h3>
        </div>
        <Badge fg={accent.fg} bg={accent.bg}>
          {GOAL_TYPE_LABELS[goal.goal_type] || goal.goal_type}
        </Badge>
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

      <div className="mt-3 pt-3 border-t border-[var(--color-line)] flex items-center justify-between">
        <span className="text-xs text-[var(--color-ink-3)]">
          {goal.projection_horizon_years}-year horizon
        </span>
        <span className="text-xs font-medium text-[var(--color-teal-ink)] group-hover:underline">
          Open →
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
