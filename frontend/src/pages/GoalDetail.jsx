import { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { api } from '../lib/api';
import DisclosureBanner from '../components/DisclosureBanner';
import AppHeader from '../components/AppHeader';

const GOAL_TYPE_LABELS = {
  retirement: 'Retirement',
  education: 'Education',
  home_purchase: 'Home purchase',
  other: 'Other',
};

const currency = new Intl.NumberFormat('en-IN', {
  style: 'currency',
  currency: 'INR',
  maximumFractionDigits: 0,
});

function StatRow({ label, value }) {
  if (value === null || value === undefined) return null;
  return (
    <div className="flex justify-between py-1.5 text-sm border-b last:border-b-0" style={{ borderColor: 'var(--color-line)' }}>
      <span className="text-[var(--color-ink-soft)]">{label}</span>
      <span style={{ color: 'var(--color-ink)', fontFamily: 'var(--font-mono)' }}>{value}</span>
    </div>
  );
}

export default function GoalDetail() {
  const { id } = useParams();
  const [goal, setGoal] = useState(null);
  const [subScenarios, setSubScenarios] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError('');
    Promise.all([api.getGoal(id), api.listSubScenarios(id)])
      .then(([goalRes, subRes]) => {
        if (cancelled) return;
        setGoal(goalRes.goal);
        setSubScenarios(subRes.sub_scenarios);
      })
      .catch((err) => {
        if (!cancelled) setError(err.message || 'Could not load this goal.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [id]);

  return (
    <div className="min-h-screen" style={{ backgroundColor: 'var(--color-paper)' }}>
      <AppHeader />
      <main className="max-w-3xl mx-auto px-4 py-8">
        <Link to="/goals" className="text-sm text-[var(--color-ink-soft)] hover:text-[var(--color-ink)]">
          ← Goals
        </Link>

        {loading && <p className="mt-4 text-sm text-[var(--color-ink-soft)]">Loading…</p>}
        {error && (
          <p className="mt-4 text-sm" style={{ color: 'var(--color-alert)' }}>
            {error}
          </p>
        )}

        {goal && (
          <>
            <div className="mt-3 mb-1 flex items-baseline justify-between gap-4">
              <h1 className="text-xl" style={{ fontFamily: 'var(--font-display)', color: 'var(--color-ink)' }}>
                {goal.goal_label}
              </h1>
              <span className="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                {GOAL_TYPE_LABELS[goal.goal_type] || goal.goal_type}
              </span>
            </div>

            <div className="mb-6 mt-4">
              <DisclosureBanner />
            </div>

            <section
              className="mb-6 rounded-sm border p-4"
              style={{ backgroundColor: 'var(--color-paper-raised)', borderColor: 'var(--color-line)' }}
            >
              <h2 className="mb-2 text-sm font-medium" style={{ color: 'var(--color-ink)' }}>
                Plan parameters
              </h2>
              <StatRow label="Initial net worth" value={currency.format(goal.initial_net_worth)} />
              <StatRow
                label="Target amount"
                value={goal.target_amount !== null ? currency.format(goal.target_amount) : null}
              />
              <StatRow label="Target date" value={goal.target_date} />
              <StatRow label="Inflation assumption" value={`${goal.inflation_rate}%`} />
              <StatRow
                label="Withdrawal rate"
                value={goal.withdrawal_rate !== null ? `${goal.withdrawal_rate}%` : null}
              />
              <StatRow
                label="Post-retirement return"
                value={goal.drawdown_return_rate !== null ? `${goal.drawdown_return_rate}%` : null}
              />
              <StatRow label="Projection horizon" value={`${goal.projection_horizon_years} years`} />
              <StatRow
                label="Corpus multiple"
                value={goal.corpus_multiple !== null ? `${goal.corpus_multiple}×` : null}
              />
            </section>

            <h2 className="mb-2 text-sm font-medium" style={{ color: 'var(--color-ink)' }}>
              Sub-scenarios
            </h2>
            {subScenarios && subScenarios.length === 0 && (
              <p className="text-sm text-[var(--color-ink-soft)]">
                No what-if scenarios yet for this goal.
              </p>
            )}
            {subScenarios && subScenarios.length > 0 && (
              <ul className="space-y-2">
                {subScenarios.map((s) => (
                  <li
                    key={s.id}
                    className="rounded-sm border px-4 py-3"
                    style={{
                      backgroundColor: 'var(--color-paper-raised)',
                      borderColor: s.is_overridden ? 'var(--color-alert)' : 'var(--color-line)',
                    }}
                  >
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-medium" style={{ color: 'var(--color-ink)' }}>
                        Scenario #{s.id}
                      </span>
                      {s.is_overridden && (
                        <span
                          className="rounded-sm px-2 py-0.5 text-xs"
                          style={{ backgroundColor: 'var(--color-alert-soft)', color: 'var(--color-alert)' }}
                        >
                          Overridden — not following parent
                        </span>
                      )}
                    </div>
                    <div className="mt-2 grid grid-cols-3 gap-2 text-xs text-[var(--color-ink-soft)]">
                      <span>Inflation: {s.custom_inflation ?? '—'}%</span>
                      <span>Withdrawal: {s.custom_withdrawal_rate ?? '—'}%</span>
                      <span>Return: {s.custom_drawdown_return_rate ?? '—'}%</span>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </main>
    </div>
  );
}
