import { useEffect, useState, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
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

export default function GoalsList() {
  const { user } = useAuth();
  const isAdvisor = user?.role === 'advisor' || user?.role === 'super_admin';

  // Advisors must supply which client's goals to view — goals_list.php
  // rejects the request otherwise (docs/02, enforced server-side). This is
  // a bare id field for now; a real client picker is a later phase.
  const [clientId, setClientId] = useState('');
  const [goals, setGoals] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(!isAdvisor);

  const fetchGoals = useCallback(
    (id) => {
      setLoading(true);
      setError('');
      api
        .listGoals(id)
        .then((res) => setGoals(res.goals))
        .catch((err) => setError(err.message || 'Could not load goals.'))
        .finally(() => setLoading(false));
    },
    []
  );

  useEffect(() => {
    if (!isAdvisor) fetchGoals();
  }, [isAdvisor, fetchGoals]);

  return (
    <div className="min-h-screen" style={{ backgroundColor: 'var(--color-paper)' }}>
      <AppHeader />
      <main className="max-w-3xl mx-auto px-4 py-8">
        <h1 className="text-xl mb-4" style={{ fontFamily: 'var(--font-display)', color: 'var(--color-ink)' }}>
          Goals
        </h1>

        <div className="mb-6">
          <DisclosureBanner />
        </div>

        {isAdvisor && (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              if (clientId) fetchGoals(clientId);
            }}
            className="mb-6 flex items-end gap-2"
          >
            <div>
              <label htmlFor="clientId" className="block text-sm mb-1 text-[var(--color-ink-soft)]">
                Client ID
              </label>
              <input
                id="clientId"
                type="number"
                min="1"
                value={clientId}
                onChange={(e) => setClientId(e.target.value)}
                className="rounded-sm border px-3 py-2 text-sm outline-none"
                style={{ borderColor: 'var(--color-line)' }}
              />
            </div>
            <button
              type="submit"
              className="rounded-sm px-4 py-2 text-sm font-medium text-white"
              style={{ backgroundColor: 'var(--color-ink)' }}
            >
              Load
            </button>
          </form>
        )}

        {loading && <p className="text-sm text-[var(--color-ink-soft)]">Loading…</p>}
        {error && (
          <p className="text-sm" style={{ color: 'var(--color-alert)' }}>
            {error}
          </p>
        )}

        {goals && goals.length === 0 && (
          <p className="text-sm text-[var(--color-ink-soft)]">No goals yet.</p>
        )}

        {goals && goals.length > 0 && (
          <ul className="space-y-2">
            {goals.map((goal) => (
              <li key={goal.id}>
                <Link
                  to={`/goals/${goal.id}`}
                  className="block rounded-sm border px-4 py-3 hover:border-[var(--color-brass)] transition-colors"
                  style={{ backgroundColor: 'var(--color-paper-raised)', borderColor: 'var(--color-line)' }}
                >
                  <div className="flex items-baseline justify-between gap-4">
                    <span className="font-medium" style={{ color: 'var(--color-ink)' }}>
                      {goal.goal_label}
                    </span>
                    <span className="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                      {GOAL_TYPE_LABELS[goal.goal_type] || goal.goal_type}
                    </span>
                  </div>
                  {goal.target_amount !== null && (
                    <div className="mt-1 text-sm text-[var(--color-ink-soft)]">
                      Target: {currency.format(goal.target_amount)}
                      {goal.target_date ? ` by ${goal.target_date}` : ''}
                    </div>
                  )}
                </Link>
              </li>
            ))}
          </ul>
        )}
      </main>
    </div>
  );
}
