import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import AppHeader from '../components/AppHeader';
import DisclosureBanner from '../components/DisclosureBanner';
import GoalCard from '../components/GoalCard';
import { Card, EmptyState, Spinner } from '../components/ui';

// A client's own goals view. Clients never pass a client_id — the server uses
// their session identity (goals_list.php enforces this).
export default function GoalsList() {
  const [goals, setGoals] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    api
      .listGoals()
      .then((res) => {
        if (!cancelled) setGoals(res.goals);
      })
      .catch((err) => {
        if (!cancelled) setError(err.message || 'Could not load your goals.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-6xl px-5 py-8">
        <div className="mb-5">
          <h1 className="text-xl font-semibold tracking-tight text-[var(--color-ink)]">Your goals</h1>
          <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
            Retirement and savings plans your advisor has set up with you.
          </p>
        </div>

        <div className="mb-6">
          <DisclosureBanner />
        </div>

        {loading && <Spinner label="Loading your goals…" />}

        {error && (
          <Card className="p-4 border-[var(--color-alert)]">
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
          </Card>
        )}

        {goals && goals.length === 0 && (
          <Card>
            <EmptyState title="No goals yet">
              Your advisor hasn't set up any goals for you yet. Once they do, you'll be able to explore
              different scenarios here.
            </EmptyState>
          </Card>
        )}

        {goals && goals.length > 0 && (
          <div className="stagger-children grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {goals.map((goal) => (
              <GoalCard key={goal.id} goal={goal} />
            ))}
          </div>
        )}
      </main>
    </div>
  );
}
