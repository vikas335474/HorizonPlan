// Route /households — advisor-only household list + create form (docs/10 P0-1).
// Data: api.listHouseholds (loaded here) and api.createHousehold. Each row links
// into HouseholdDetail. Clients never see households.

import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import AppHeader from '../components/AppHeader';
import { Card, Button, EmptyState, Spinner } from '../components/ui';

// docs/10 P0-1 — households group a firm's client records so an advisor can see
// a family's combined picture (the aggregate is the *sum* of members' goals,
// not a shared pool). Advisor-facing; clients never see households.
export default function Households() {
  const [households, setHouseholds] = useState(null);
  const [error, setError] = useState('');
  const [name, setName] = useState('');
  const [creating, setCreating] = useState(false);

  function load() {
    api
      .listHouseholds()
      .then((res) => setHouseholds(res.households))
      .catch((err) => setError(err.message || 'Could not load households.'));
  }
  useEffect(load, []);

  async function create(e) {
    e.preventDefault();
    if (!name.trim()) return;
    setCreating(true);
    setError('');
    try {
      await api.createHousehold(name.trim());
      setName('');
      load();
    } catch (err) {
      setError(err.message || 'Could not create the household.');
    } finally {
      setCreating(false);
    }
  }

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-4xl px-5 py-8">
        <div className="mb-1">
          <h1 className="text-xl font-semibold tracking-tight text-[var(--color-ink)]">Households</h1>
          <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
            Group family members to see their combined retirement picture — the sum of each member's own plan.
          </p>
        </div>

        <Card className="p-4 mt-5 mb-6">
          <form onSubmit={create} className="flex items-end gap-3 flex-wrap">
            <div className="flex-1 min-w-[220px]">
              <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">New household</label>
              <input
                className="field" value={name} onChange={(e) => setName(e.target.value)}
                placeholder="e.g. Sharma family"
              />
            </div>
            <Button type="submit" disabled={creating || !name.trim()}>
              {creating ? 'Creating…' : 'Create household'}
            </Button>
          </form>
          {error && <p className="mt-3 text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>}
        </Card>

        {households === null && <Spinner label="Loading households…" />}

        {households && households.length === 0 && (
          <Card>
            <EmptyState title="No households yet">
              Create a household above, then add clients to it from each client's page.
            </EmptyState>
          </Card>
        )}

        {households && households.length > 0 && (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {households.map((h) => (
              <Link key={h.id} to={`/households/${h.id}`}>
                <Card className="p-4 hover:border-[var(--color-teal)] transition-colors">
                  <div className="flex items-center justify-between gap-3">
                    <h2 className="text-base font-semibold text-[var(--color-ink)]">{h.name}</h2>
                    <span className="text-xs text-[var(--color-ink-3)]">
                      {h.member_count} {h.member_count === 1 ? 'member' : 'members'}
                    </span>
                  </div>
                  <p className="mt-1 text-sm text-[var(--color-teal-ink)]">View combined plan →</p>
                </Card>
              </Link>
            ))}
          </div>
        )}
      </main>
    </div>
  );
}
