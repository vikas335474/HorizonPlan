import { useCallback, useEffect, useState } from 'react';
import AppHeader from '../components/AppHeader';
import { Card, Spinner } from '../components/ui';
import { ReviewQueueRow } from '../components/PlanReviewUI';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';

const TABS = [
  { key: 'active', label: 'Needs attention' },
  { key: 'all', label: 'All reviewed' },
];

// Jr -> Sr Advisor Plan-Approval Workflow — firm-wide queue. Readable by any
// advisor/super_admin (a jr_advisor can see the status of their own
// submissions); approve/request-changes actions are gated to sr_advisor/
// firm_admin server-side (goals_review.php) and hidden client-side to match
// (ReviewQueueRow's own canReviewPlans() check).
export default function PlanReviewQueue() {
  const { tenant } = useAuth();
  const [tab, setTab] = useState('active');
  const [items, setItems] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    return api.listReviewQueue(tab)
      .then((res) => setItems(res.items))
      .catch((err) => setError(err.message || 'Could not load the review queue.'))
      .finally(() => setLoading(false));
  }, [tab]);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-3xl px-5 py-8">
        <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">Plan review</h1>
        <p className="mt-1 mb-5 text-sm text-[var(--color-ink-2)]">
          Goals your firm has flagged for a senior advisor's sign-off before use with a client.
        </p>

        {tenant && !tenant.requiresPlanReview && (
          <Card className="p-4 mb-4">
            <p className="text-sm text-[var(--color-ink-2)]">
              Plan review isn't turned on for your firm yet. A firm admin can enable it from the firm's
              settings — goals will then need a senior advisor or firm admin's sign-off before their advice
              fields are considered final.
            </p>
          </Card>
        )}

        <div className="flex gap-2 mb-4">
          {TABS.map((t) => (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className="text-sm font-medium px-3 py-1.5 rounded-[var(--radius-ctrl)] transition-colors"
              style={{
                color: tab === t.key ? 'var(--color-teal-ink)' : 'var(--color-ink-2)',
                backgroundColor: tab === t.key ? 'var(--color-teal-soft)' : 'transparent',
              }}
            >
              {t.label}
            </button>
          ))}
        </div>

        <Card className="p-4">
          {loading && <Spinner label="Loading queue…" />}
          {error && <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>}
          {!loading && !error && items && items.length === 0 && (
            <p className="text-sm text-[var(--color-ink-2)]">Nothing here right now.</p>
          )}
          {!loading && !error && items && items.length > 0 && (
            <div>
              {items.map((item) => (
                <ReviewQueueRow key={item.id} item={item} onChanged={load} />
              ))}
            </div>
          )}
        </Card>
      </main>
    </div>
  );
}
