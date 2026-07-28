import { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { api } from '../lib/api';
import AppHeader from '../components/AppHeader';
import DisclosureBanner from '../components/DisclosureBanner';
import SequenceRiskChart from '../components/SequenceRiskChart';
import { ReadinessScoreCard } from '../components/ReadinessScore';
import { Card, Badge, EmptyState, Spinner } from '../components/ui';
import { formatCurrency } from '../lib/format';

// docs/10 P0-1 — a household's combined retirement picture: the element-wise
// sum of every member's own goal projections (no shared-corpus modelling). The
// numbers come straight from household_projection.php, which reuses the same
// PlanMath the per-goal projection does, so the aggregate == sum of members.
export default function HouseholdDetail() {
  const { householdId } = useParams();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    api
      .getHouseholdProjection(householdId)
      .then((res) => { if (!cancelled) setData(res); })
      .catch((err) => { if (!cancelled) setError(err.message || 'Could not load this household.'); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [householdId]);

  const hasSeries = data?.household_steady_series?.length > 0;

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-4xl px-5 py-8">
        <Link to="/households" className="text-sm text-[var(--color-ink-2)] hover:text-[var(--color-ink)]">
          ← All households
        </Link>

        {loading && <div className="mt-4"><Spinner label="Building the combined plan…" /></div>}
        {error && (
          <Card className="mt-4 p-4 border-[var(--color-alert)]">
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
          </Card>
        )}

        {data && (
          <>
            <div className="mt-3 mb-5">
              <h1 className="text-xl font-semibold tracking-tight text-[var(--color-ink)]">{data.household.name}</h1>
              <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
                Combined retirement plan across {data.members.length} {data.members.length === 1 ? 'member' : 'members'} —
                the sum of each member's own projection, not a shared pool.
              </p>
            </div>

            <div className="mb-6">
              <DisclosureBanner />
            </div>

            {/* Combined headline: total starting corpus + household readiness. */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
              <Card className="p-5">
                <div className="text-[11px] uppercase tracking-wider text-[var(--color-ink-3)]">Combined starting corpus</div>
                <div className="tnum text-2xl font-semibold text-[var(--color-ink)] mt-1">
                  {formatCurrency(data.total_starting_corpus)}
                </div>
                <p className="mt-1 text-xs text-[var(--color-ink-3)]">Across all members' retirement goals</p>
              </Card>
              {data.household_readiness_score != null ? (
                <ReadinessScoreCard score={data.household_readiness_score} />
              ) : (
                <Card className="p-5">
                  <p className="text-sm text-[var(--color-ink-2)]">
                    No household readiness yet — add members with a fully-specified retirement goal
                    (withdrawal and post-retirement return set).
                  </p>
                </Card>
              )}
            </div>

            {/* Combined steady-vs-adverse projection. */}
            {hasSeries && (
              <Card className="p-5 mb-6">
                <h2 className="text-base font-semibold text-[var(--color-ink)]">Combined corpus — steady vs. adverse-sequence</h2>
                <p className="mt-1 mb-3 text-sm text-[var(--color-ink-2)] leading-relaxed">
                  Every member's projection added together, year by year. Both lines assume each member's own
                  average return; the dashed line front-loads the weak years.
                </p>
                <SequenceRiskChart
                  steadySeries={data.household_steady_series}
                  adverseSeries={data.household_adverse_series}
                />
              </Card>
            )}

            {/* Per-member breakdown. */}
            <h2 className="text-base font-semibold text-[var(--color-ink)] mb-2">Members</h2>
            {data.members.length === 0 ? (
              <Card>
                <EmptyState title="No members yet">
                  Add clients to this household from each client's page.
                </EmptyState>
              </Card>
            ) : (
              <div className="space-y-3">
                {data.members.map((m) => (
                  <Card key={m.client_id} className="p-4">
                    <div className="flex items-center justify-between gap-3 mb-2">
                      <Link to={`/clients/${m.client_id}`} className="text-sm font-medium text-[var(--color-ink)] hover:underline break-all">
                        {m.email}
                      </Link>
                      <span className="text-xs text-[var(--color-ink-3)]">
                        {m.goals.length} {m.goals.length === 1 ? 'goal' : 'goals'}
                      </span>
                    </div>
                    {m.goals.length === 0 ? (
                      <p className="text-xs text-[var(--color-ink-3)]">No retirement goals yet.</p>
                    ) : (
                      <ul className="divide-y divide-[var(--color-line)]">
                        {m.goals.map((g) => (
                          <li key={g.goal_id} className="flex items-center justify-between gap-3 py-1.5">
                            <span className="text-sm text-[var(--color-ink-2)]">{g.goal_label}</span>
                            <span className="flex items-center gap-3">
                              <span className="tnum text-xs text-[var(--color-ink-3)]">{formatCurrency(g.initial_net_worth)}</span>
                              {g.readiness_score != null && (
                                <Badge fg="var(--color-teal-ink)" bg="var(--color-teal-soft)">{g.readiness_score}/100</Badge>
                              )}
                            </span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </Card>
                ))}
              </div>
            )}
          </>
        )}
      </main>
    </div>
  );
}
