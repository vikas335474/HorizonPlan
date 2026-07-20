import { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { api } from '../lib/api';
import AppHeader from '../components/AppHeader';
import DisclosureBanner from '../components/DisclosureBanner';
import GoalCard from '../components/GoalCard';
import { Card, EmptyState, Spinner } from '../components/ui';

// Advisor drills into one client from the dashboard. clientId comes from the
// route; goals_list.php accepts client_id for advisor/super_admin sessions and
// scopes it to the advisor's tenant server-side.
export default function ClientGoals() {
  const { clientId } = useParams();
  const [goals, setGoals] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    api
      .listGoals(clientId)
      .then((res) => {
        if (!cancelled) setGoals(res.goals);
      })
      .catch((err) => {
        if (!cancelled) setError(err.message || 'Could not load this client’s goals.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [clientId]);

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-6xl px-5 py-8">
        <Link to="/" className="text-sm text-[var(--color-ink-2)] hover:text-[var(--color-ink)]">
          ← All clients
        </Link>

        <div className="mt-3 mb-5">
          <h1 className="text-xl font-semibold tracking-tight text-[var(--color-ink)]">Client goals</h1>
          <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
            Every goal on this client's plan. Open one to adjust scenarios.
          </p>
        </div>

        <div className="mb-6">
          <DisclosureBanner />
        </div>

        {loading && <Spinner label="Loading goals…" />}

        {error && (
          <Card className="p-4 border-[var(--color-alert)]">
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
          </Card>
        )}

        {goals && goals.length === 0 && (
          <Card>
            <EmptyState title="No goals for this client yet">
              This client doesn't have any goals set up. Goal creation isn't available in this view yet.
            </EmptyState>
          </Card>
        )}

        {goals && goals.length > 0 && (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {goals.map((goal) => (
              <GoalCard key={goal.id} goal={goal} />
            ))}
          </div>
        )}
      </main>
    </div>
  );
}
