import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import AppHeader from '../components/AppHeader';
import { Card, StatCard, EmptyState, Spinner } from '../components/ui';
import { formatCurrencyCompact, formatCurrency, formatDate } from '../lib/format';

export default function Dashboard() {
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [query, setQuery] = useState('');

  useEffect(() => {
    let cancelled = false;
    api
      .listClients()
      .then((res) => {
        if (!cancelled) setData(res);
      })
      .catch((err) => {
        if (!cancelled) setError(err.message || 'Could not load your clients.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const clients = data?.clients ?? [];
  const filtered = query
    ? clients.filter((c) => c.email.toLowerCase().includes(query.toLowerCase()))
    : clients;

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-6xl px-5 py-8">
        <div className="mb-6">
          <h1 className="text-xl font-semibold tracking-tight text-[var(--color-ink)]">Clients</h1>
          <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
            Everyone in your firm's book, with their goals and tracked corpus.
          </p>
        </div>

        {loading && <Spinner label="Loading your book…" />}

        {error && (
          <Card className="p-4 border-[var(--color-alert)]">
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>
              {error}
            </p>
          </Card>
        )}

        {data && (
          <>
            {/* Aggregate stat row */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
              <StatCard label="Clients" value={<span className="tnum">{data.stats.total_clients}</span>} />
              <StatCard label="Goals tracked" value={<span className="tnum">{data.stats.total_goals}</span>} />
              <StatCard
                label="Corpus under plan"
                value={formatCurrencyCompact(data.stats.total_aum)}
                sublabel="Sum of starting corpus across all goals"
                accent="teal"
              />
            </div>

            {clients.length === 0 ? (
              <Card>
                <EmptyState title="No clients yet">
                  Once clients are added to your firm, they'll appear here with their retirement and
                  savings goals. Client onboarding isn't available in this view yet.
                </EmptyState>
              </Card>
            ) : (
              <Card className="overflow-hidden">
                {/* Search */}
                <div className="p-3 border-b border-[var(--color-line)]">
                  <input
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Search clients by email…"
                    className="w-full rounded-[var(--radius-ctrl)] bg-[var(--color-surface-2)] px-3 py-2 text-sm outline-none placeholder:text-[var(--color-ink-3)]"
                  />
                </div>

                {/* Column headers */}
                <div className="hidden md:grid grid-cols-12 gap-4 px-4 py-2.5 border-b border-[var(--color-line)] text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-3)]">
                  <div className="col-span-6">Client</div>
                  <div className="col-span-2 text-right">Goals</div>
                  <div className="col-span-4 text-right">Tracked corpus</div>
                </div>

                {/* Rows */}
                <ul>
                  {filtered.map((c) => (
                    <li key={c.client_id}>
                      <button
                        onClick={() => navigate(`/clients/${c.client_id}`)}
                        className="w-full grid grid-cols-12 gap-4 px-4 py-3.5 items-center text-left border-b border-[var(--color-line)] last:border-b-0 hover:bg-[var(--color-surface-2)] transition-colors"
                      >
                        <div className="col-span-12 md:col-span-6 flex items-center gap-3 min-w-0">
                          <span
                            className="shrink-0 h-8 w-8 rounded-full flex items-center justify-center text-xs font-semibold"
                            style={{ backgroundColor: 'var(--color-teal-soft)', color: 'var(--color-teal-ink)' }}
                          >
                            {c.email.slice(0, 2).toUpperCase()}
                          </span>
                          <div className="min-w-0">
                            <div className="truncate text-sm font-medium text-[var(--color-ink)]">{c.email}</div>
                            <div className="text-xs text-[var(--color-ink-3)]">Since {formatDate(c.client_since)}</div>
                          </div>
                        </div>
                        <div className="col-span-6 md:col-span-2 md:text-right">
                          <span className="tnum text-sm text-[var(--color-ink)]">{c.goal_count}</span>
                          <span className="md:hidden text-xs text-[var(--color-ink-3)]"> goals</span>
                        </div>
                        <div className="col-span-6 md:col-span-4 text-right tnum text-sm text-[var(--color-ink)]">
                          {formatCurrency(c.total_net_worth)}
                        </div>
                      </button>
                    </li>
                  ))}
                  {filtered.length === 0 && (
                    <li className="px-4 py-8 text-center text-sm text-[var(--color-ink-3)]">
                      No clients match "{query}".
                    </li>
                  )}
                </ul>
              </Card>
            )}
          </>
        )}
      </main>
    </div>
  );
}
