import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import AppHeader from '../components/AppHeader';
import DisclosureBanner from '../components/DisclosureBanner';
import ScenarioPanel from '../components/ScenarioPanel';
import { Card, Badge, Button, Spinner } from '../components/ui';
import {
  formatCurrency,
  formatPercent,
  formatDate,
  GOAL_TYPE_LABELS,
  GOAL_TYPE_ACCENT,
  corpusMultiple,
} from '../lib/format';

export default function GoalDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [goal, setGoal] = useState(null);
  const [subScenarios, setSubScenarios] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [expandedId, setExpandedId] = useState(null);
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState('');

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

  function handleScenarioChanged(updated) {
    setSubScenarios((prev) => prev.map((s) => (s.id === updated.id ? { ...s, ...updated } : s)));
  }

  async function handleCreateScenario() {
    setCreating(true);
    setCreateError('');
    try {
      const res = await api.createSubScenario(goal.id);
      const fresh = await api.listSubScenarios(goal.id);
      setSubScenarios(fresh.sub_scenarios);
      setExpandedId(res.sub_scenario_id);
    } catch (err) {
      setCreateError(err.message || 'Could not create a new scenario.');
    } finally {
      setCreating(false);
    }
  }

  const isRetirement = goal?.goal_type === 'retirement';
  const accent = goal ? GOAL_TYPE_ACCENT[goal.goal_type] || GOAL_TYPE_ACCENT.other : null;

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-4xl px-5 py-8">
        <button
          onClick={() => navigate(-1)}
          className="text-sm text-[var(--color-ink-2)] hover:text-[var(--color-ink)]"
        >
          ← Back
        </button>

        {loading && <Spinner label="Loading goal…" />}

        {error && (
          <Card className="mt-4 p-4 border-[var(--color-alert)]">
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
          </Card>
        )}

        {goal && (
          <>
            {/* Goal header */}
            <div className="mt-4 mb-5 flex items-start justify-between gap-4">
              <div className="min-w-0">
                <div className="flex items-center gap-2.5 mb-1.5">
                  <Badge fg={accent.fg} bg={accent.bg}>
                    {GOAL_TYPE_LABELS[goal.goal_type] || goal.goal_type}
                  </Badge>
                  <span className="text-xs text-[var(--color-ink-3)]">
                    {goal.projection_horizon_years}-year horizon
                  </span>
                </div>
                <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">
                  {goal.goal_label}
                </h1>
              </div>
            </div>

            {/* Plan parameters — key figures grid */}
            <Card className="p-5 mb-4">
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-4">
                <Param label="Starting corpus" value={formatCurrency(goal.initial_net_worth)} />
                {goal.target_amount !== null && (
                  <Param label="Target" value={formatCurrency(goal.target_amount)} />
                )}
                {goal.target_date && <Param label="Target date" value={formatDate(goal.target_date)} />}
                <Param label="Inflation" value={formatPercent(goal.inflation_rate)} />
                {isRetirement && goal.withdrawal_rate !== null && (
                  <Param
                    label="Withdrawal rate"
                    value={formatPercent(goal.withdrawal_rate)}
                    sub={corpusMultiple(goal.withdrawal_rate) ? `${corpusMultiple(goal.withdrawal_rate)}× expenses` : null}
                  />
                )}
                {isRetirement && goal.drawdown_return_rate !== null && (
                  <Param label="Post-retirement return" value={formatPercent(goal.drawdown_return_rate)} />
                )}
              </div>
            </Card>

            <div className="mb-6">
              <DisclosureBanner />
            </div>

            {/* Sub-scenarios */}
            <div className="flex items-center justify-between mb-3">
              <div>
                <h2 className="text-base font-semibold text-[var(--color-ink)]">Scenarios</h2>
                <p className="text-xs text-[var(--color-ink-2)] mt-0.5">
                  Try different assumptions without touching the base goal.
                </p>
              </div>
              <Button onClick={handleCreateScenario} disabled={creating}>
                {creating ? 'Creating…' : '+ New scenario'}
              </Button>
            </div>

            {createError && (
              <p className="mb-3 text-sm" style={{ color: 'var(--color-alert)' }}>{createError}</p>
            )}

            {subScenarios && subScenarios.length === 0 && (
              <Card className="p-6 text-center">
                <p className="text-sm text-[var(--color-ink-2)]">
                  No scenarios yet. Create one to explore a different inflation
                  {isRetirement ? ' or withdrawal-rate' : ''} assumption.
                </p>
              </Card>
            )}

            {subScenarios && subScenarios.length > 0 && (
              <div className="space-y-2.5">
                {subScenarios.map((s, idx) => {
                  const isExpanded = expandedId === s.id;
                  return (
                    <Card
                      key={s.id}
                      className={isExpanded ? 'ring-1' : ''}
                      style={{
                        borderColor: s.is_overridden ? 'var(--color-alert)' : 'var(--color-line)',
                      }}
                    >
                      <button
                        onClick={() => setExpandedId(isExpanded ? null : s.id)}
                        className="w-full flex items-center justify-between px-4 py-3.5 text-left"
                      >
                        <div className="flex items-center gap-3">
                          <span className="tnum text-sm font-semibold text-[var(--color-ink)]">
                            Scenario {idx + 1}
                          </span>
                          {s.is_overridden && (
                            <Badge fg="var(--color-alert)" bg="var(--color-alert-soft)">
                              Customized
                            </Badge>
                          )}
                        </div>
                        <div className="flex items-center gap-3">
                          {!isExpanded && (
                            <span className="tnum hidden sm:inline text-xs text-[var(--color-ink-3)]">
                              infl {s.custom_inflation ?? goal.inflation_rate}%
                            </span>
                          )}
                          <svg
                            width="16" height="16" viewBox="0 0 16 16" fill="none"
                            style={{ transform: isExpanded ? 'rotate(180deg)' : 'none', transition: 'transform 0.15s' }}
                          >
                            <path d="M4 6l4 4 4-4" stroke="var(--color-ink-3)" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                          </svg>
                        </div>
                      </button>

                      {isExpanded && (
                        <div className="px-4 pb-4 pt-1 border-t border-[var(--color-line)]">
                          <div className="pt-4">
                            <ScenarioPanel goal={goal} subScenario={s} onChanged={handleScenarioChanged} />
                          </div>
                        </div>
                      )}
                    </Card>
                  );
                })}
              </div>
            )}
          </>
        )}
      </main>
    </div>
  );
}

function Param({ label, value, sub }) {
  return (
    <div>
      <div className="text-[11px] uppercase tracking-wider text-[var(--color-ink-3)]">{label}</div>
      <div className="tnum text-[15px] font-semibold text-[var(--color-ink)] mt-1">{value}</div>
      {sub && <div className="text-[11px] text-[var(--color-ink-3)] mt-0.5">{sub}</div>}
    </div>
  );
}
