// Route /practice — the firm-level practice view for a principal (firm_admin /
// sr_advisor; super_admin passes too). Where the Dashboard answers "what's on
// my desk", this answers "how is the practice running": how the book is spread
// across advisors, how its health breaks down, where the plan-review queue is
// sitting, and which coverage gaps are open.
//
// Data: api.getFirmAnalytics() (firm_analytics.php), which is server-gated via
// requireFirmRole — this page also guards client-side so a jr_advisor gets a
// clean explanation instead of a failed request, the same pattern AdminConsole
// uses. Read-only: every number here is derived from data the app already
// stores, and nothing on this page writes.

import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import AppHeader from '../components/AppHeader';
import { Card, Spinner } from '../components/ui';
import { formatCurrency, formatCurrencyCompact } from '../lib/format';

const READINESS_SEGMENTS = [
  { key: 'strong', label: 'Strong', color: 'var(--color-teal)' },
  { key: 'fair', label: 'Fair', color: 'var(--color-amber)' },
  { key: 'at_risk', label: 'At risk', color: 'var(--color-alert)' },
  { key: 'unscored', label: 'Not scored', color: 'var(--color-line-2)' },
];

const REVIEW_LABELS = {
  pending_review: 'Awaiting sign-off',
  changes_requested: 'Changes requested',
  approved: 'Approved',
  not_required: 'Not in review',
};

export default function PracticeAnalytics() {
  const { user } = useAuth();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  // Same "NULL firm_role reads as sr_advisor" rule the server applies in
  // requireFirmRole(), so pre-migration-020 advisors aren't locked out of a
  // page the API would serve them.
  const allowed = user?.role === 'super_admin'
    || (user?.role === 'advisor' && (!user?.firmRole || user.firmRole === 'sr_advisor' || user.firmRole === 'firm_admin'));

  useEffect(() => {
    if (!allowed) { setLoading(false); return; }
    api
      .getFirmAnalytics()
      .then(setData)
      .catch((err) => setError(err.message || 'Could not load practice analytics.'))
      .finally(() => setLoading(false));
  }, [allowed]);

  if (!allowed) {
    return (
      <div className="min-h-screen">
        <AppHeader />
        <main className="mx-auto max-w-6xl px-5 py-10">
          <Card className="p-6">
            <h1 className="text-base font-semibold text-[var(--color-ink)]">Practice analytics</h1>
            <p className="mt-1.5 text-sm text-[var(--color-ink-2)]">
              This view is for senior advisors and firm admins. Your own clients are on{' '}
              <Link to="/" className="underline">your dashboard</Link>.
            </p>
          </Card>
        </main>
      </div>
    );
  }

  return (
    <div className="min-h-screen">
      <AppHeader />

      <div className="border-b border-[var(--color-line)] bg-[var(--color-surface)]">
        <div className="mx-auto max-w-6xl px-5 py-6">
          <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">Practice analytics</h1>
          <p className="mt-1 text-sm text-[var(--color-ink-2)]">
            How the book is spread across the firm, and where the gaps are.
          </p>
        </div>
      </div>

      <main className="mx-auto max-w-6xl px-5 py-6">
        {loading && <Spinner label="Loading practice analytics…" />}

        {error && !loading && (
          <Card className="p-4 border-[var(--color-alert)]">
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
          </Card>
        )}

        {data && !loading && (
          <>
            {/* Firm totals */}
            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
              <Stat label="Advisors" value={data.totals.advisors} />
              <Stat label="Clients" value={data.totals.clients} />
              <Stat label="Goals" value={data.totals.goals} />
              <Stat label="Households" value={data.totals.households} />
              <Stat label="Corpus under plan" value={formatCurrencyCompact(data.totals.total_corpus)} highlight />
            </div>

            {/* Coverage gaps — the actionable part. Raw counts out of the
                firm's client total, never a grade or a target: what "good"
                looks like is the firm's call, not the tool's. */}
            <Card className="p-4 mb-5">
              <h2 className="text-sm font-semibold text-[var(--color-ink)] mb-1">Coverage</h2>
              <p className="text-xs text-[var(--color-ink-2)] mb-3">
                Clients missing a piece of the plan. Counts out of {data.coverage.total_clients} in the firm.
              </p>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <CoverageItem
                  label="No goals yet"
                  count={data.coverage.clients_with_no_goals}
                  total={data.coverage.total_clients}
                />
                <CoverageItem
                  label="Never risk-profiled"
                  count={data.coverage.clients_never_profiled}
                  total={data.coverage.total_clients}
                />
                <CoverageItem
                  label="No advisor assigned"
                  count={data.coverage.clients_unassigned}
                  total={data.coverage.total_clients}
                />
              </div>
            </Card>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
              {/* Book health */}
              <Card className="p-4">
                <h2 className="text-sm font-semibold text-[var(--color-ink)] mb-3">Book health</h2>
                <ul className="flex flex-col gap-2">
                  {READINESS_SEGMENTS.map((s) => {
                    const n = data.readiness_distribution[s.key] ?? 0;
                    const pct = data.totals.clients > 0 ? (n / data.totals.clients) * 100 : 0;
                    return (
                      <li key={s.key} className="flex items-center gap-3">
                        <span className="w-20 shrink-0 text-xs text-[var(--color-ink-2)]">{s.label}</span>
                        <span className="h-2 flex-1 rounded-full bg-[var(--color-line)] overflow-hidden">
                          <span className="block h-full rounded-full" style={{ width: `${pct}%`, backgroundColor: s.color }} />
                        </span>
                        <span className="w-8 shrink-0 text-right text-xs font-semibold tabular-nums text-[var(--color-ink)]">{n}</span>
                      </li>
                    );
                  })}
                </ul>
              </Card>

              {/* Plan-review pipeline. Only meaningful for a firm that opted
                  into review — a firm that hasn't sees everything sitting in
                  "not in review", which is the honest picture, not an error. */}
              <Card className="p-4">
                <h2 className="text-sm font-semibold text-[var(--color-ink)] mb-1">Plan-review pipeline</h2>
                <p className="text-xs text-[var(--color-ink-2)] mb-3">Goals by review state.</p>
                <ul className="flex flex-col gap-2">
                  {Object.entries(REVIEW_LABELS).map(([key, label]) => (
                    <li key={key} className="flex items-center justify-between gap-3">
                      <span className="text-xs text-[var(--color-ink-2)]">{label}</span>
                      <span className="text-sm font-semibold tabular-nums text-[var(--color-ink)]">
                        {data.review_pipeline[key] ?? 0}
                      </span>
                    </li>
                  ))}
                </ul>
              </Card>
            </div>

            {/* Per-advisor load. Counts and sums only — no ranking, no target,
                no productivity score. A principal reads this against what they
                know about their own team. */}
            <Card className="overflow-hidden">
              <div className="p-4 border-b border-[var(--color-line)]">
                <h2 className="text-sm font-semibold text-[var(--color-ink)]">Load by advisor</h2>
                <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">
                  Assignment is for attribution — every advisor can still reach every client in the firm.
                </p>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-[var(--color-line)] text-[11px] uppercase tracking-wider text-[var(--color-ink-3)]">
                      <th className="px-4 py-2.5 text-left font-semibold">Advisor</th>
                      <th className="px-4 py-2.5 text-left font-semibold">Role</th>
                      <th className="px-4 py-2.5 text-right font-semibold">Clients</th>
                      <th className="px-4 py-2.5 text-right font-semibold">Goals</th>
                      <th className="px-4 py-2.5 text-right font-semibold">Needs attention</th>
                      <th className="px-4 py-2.5 text-right font-semibold">Corpus</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.per_advisor.map((a) => (
                      <tr key={a.user_id} className="border-b border-[var(--color-line)] last:border-b-0">
                        <td className="px-4 py-3 text-[var(--color-ink)]">{a.email}</td>
                        <td className="px-4 py-3 text-xs text-[var(--color-ink-2)]">{FIRM_ROLE_LABELS[a.firm_role] || a.firm_role}</td>
                        <td className="px-4 py-3 text-right tabular-nums">{a.client_count}</td>
                        <td className="px-4 py-3 text-right tabular-nums">{a.goal_count}</td>
                        <td className="px-4 py-3 text-right tabular-nums">
                          {a.attention_count > 0
                            ? <span className="font-semibold" style={{ color: 'var(--color-amber)' }}>{a.attention_count}</span>
                            : <span className="text-[var(--color-ink-3)]">0</span>}
                        </td>
                        <td className="px-4 py-3 text-right tabular-nums">{formatCurrency(a.total_corpus)}</td>
                      </tr>
                    ))}
                    {data.unassigned.client_count > 0 && (
                      <tr className="bg-[var(--color-surface-2)]">
                        <td className="px-4 py-3 text-[var(--color-ink-2)] italic">Unassigned</td>
                        <td className="px-4 py-3 text-xs text-[var(--color-ink-3)]">—</td>
                        <td className="px-4 py-3 text-right tabular-nums">{data.unassigned.client_count}</td>
                        <td className="px-4 py-3 text-right tabular-nums">{data.unassigned.goal_count}</td>
                        <td className="px-4 py-3 text-right tabular-nums">
                          {data.unassigned.attention_count > 0
                            ? <span className="font-semibold" style={{ color: 'var(--color-amber)' }}>{data.unassigned.attention_count}</span>
                            : <span className="text-[var(--color-ink-3)]">0</span>}
                        </td>
                        <td className="px-4 py-3 text-right tabular-nums">{formatCurrency(data.unassigned.total_corpus)}</td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </Card>
          </>
        )}
      </main>
    </div>
  );
}

const FIRM_ROLE_LABELS = {
  jr_advisor: 'Junior advisor',
  sr_advisor: 'Senior advisor',
  firm_admin: 'Firm admin',
};

function Stat({ label, value, highlight = false }) {
  return (
    <Card className="p-3.5" elevated={!highlight}>
      <div className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">{label}</div>
      <div className={`mt-1 text-xl font-semibold tabular-nums ${highlight ? 'text-[var(--color-teal-ink)]' : 'text-[var(--color-ink)]'}`}>
        {value}
      </div>
    </Card>
  );
}

function CoverageItem({ label, count, total }) {
  const pct = total > 0 ? Math.round((count / total) * 100) : 0;
  const clear = count === 0;
  return (
    <div className="rounded-lg border border-[var(--color-line)] p-3">
      <div className="flex items-baseline justify-between gap-2">
        <span className="text-xs text-[var(--color-ink-2)]">{label}</span>
        <span
          className="text-sm font-semibold tabular-nums"
          style={{ color: clear ? 'var(--color-teal-ink)' : 'var(--color-amber)' }}
        >
          {count}
        </span>
      </div>
      <div className="mt-2 h-1.5 w-full rounded-full bg-[var(--color-line)] overflow-hidden">
        <div
          className="h-full rounded-full"
          style={{ width: `${pct}%`, backgroundColor: clear ? 'var(--color-teal)' : 'var(--color-amber)' }}
        />
      </div>
    </div>
  );
}
