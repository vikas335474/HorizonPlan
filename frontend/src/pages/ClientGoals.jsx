import { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { api } from '../lib/api';
import AppHeader from '../components/AppHeader';
import DisclosureBanner from '../components/DisclosureBanner';
import GoalCard from '../components/GoalCard';
import Modal from '../components/Modal';
import { RiskProfileSummary } from '../components/RiskProfileUI';
import { ClientPortfolioCard } from '../components/ClientPortfolioUI';
import { ReadinessScoreBadge } from '../components/ReadinessScore';
import { Card, EmptyState, Spinner, Button } from '../components/ui';
import { formatCurrency } from '../lib/format';

// Advisor drills into one client from the dashboard. clientId comes from the
// route; goals_list.php accepts client_id for advisor/super_admin sessions and
// scopes it to the advisor's tenant server-side.
export default function ClientGoals() {
  const { clientId } = useParams();
  const [goals, setGoals] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [addOpen, setAddOpen] = useState(false);

  function load() {
    setLoading(true);
    api
      .listGoals(clientId)
      .then((res) => setGoals(res.goals))
      .catch((err) => setError(err.message || 'Could not load this client’s goals.'))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [clientId]);

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-6xl px-5 py-8">
        <Link to="/" className="text-sm text-[var(--color-ink-2)] hover:text-[var(--color-ink)]">
          ← All clients
        </Link>

        <div className="mt-3 mb-5 flex items-end justify-between gap-4">
          <div>
            <h1 className="text-xl font-semibold tracking-tight text-[var(--color-ink)]">Client goals</h1>
            <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
              Every goal on this client's plan. Open one to adjust scenarios.
            </p>
          </div>
          <Button onClick={() => setAddOpen(true)}>
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
              <path d="M8 3.5v9M3.5 8h9" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
            </svg>
            New goal
          </Button>
        </div>

        <div className="mb-6">
          <DisclosureBanner />
        </div>

        {/* docs/08 gap #5 "client overview depth" — a consolidated glance at
            this client's goals before drilling into the portfolio/risk/goal
            cards below. */}
        {goals && goals.length > 0 && <GoalsHealthSummary goals={goals} />}

        {/* docs/05 item 3 / docs/06 corpus composition — what the client already
            owns, independent of any one goal. */}
        <ClientPortfolioCard clientId={clientId} />

        {/* docs/06 Section B — risk tolerance belongs to the client, not one goal. */}
        <RiskProfileSummary clientId={clientId} />

        {/* docs/10 P0-3 — opt a client into periodic "your plan, refreshed"
            review emails. Advisor-set, default off. */}
        <ReviewScheduleCard clientId={clientId} />

        {/* docs/10 P0-1 — which household this client belongs to (for the
            combined family view). */}
        <HouseholdAssignCard clientId={clientId} />

        {loading && <Spinner label="Loading goals…" />}

        {error && (
          <Card className="p-4 border-[var(--color-alert)]">
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
          </Card>
        )}

        {goals && goals.length === 0 && (
          <Card>
            <EmptyState
              title="No goals for this client yet"
              action={<Button onClick={() => setAddOpen(true)}>Create the first goal</Button>}
            >
              Set up a retirement, education, or savings goal to start modelling scenarios with this client.
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

      <NewGoalModal
        open={addOpen}
        clientId={clientId}
        onClose={() => setAddOpen(false)}
        onCreated={() => { setAddOpen(false); load(); }}
      />
    </div>
  );
}

// docs/10 P0-3 — a client's scheduled-review cadence. A recurring "your plan,
// refreshed" email (quarterly/annually) that links the client to their own
// self-service login. Opt-in, default off; the daily cron sends the ones due.
function ReviewScheduleCard({ clientId }) {
  const [cadence, setCadence] = useState(null); // null = loading
  const [lastSentAt, setLastSentAt] = useState(null);
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    api
      .getPlanReviewSchedule(clientId)
      .then((res) => {
        if (cancelled) return;
        setCadence(res.cadence || 'off');
        setLastSentAt(res.last_sent_at || null);
      })
      .catch(() => { if (!cancelled) setCadence('off'); });
    return () => { cancelled = true; };
  }, [clientId]);

  async function change(next) {
    const prev = cadence;
    setCadence(next);
    setBusy(true);
    setError('');
    setSaved(false);
    try {
      await api.updatePlanReviewSchedule(clientId, next);
      setSaved(true);
      setTimeout(() => setSaved(false), 1800);
    } catch (err) {
      setCadence(prev); // revert on failure
      setError(err.message || 'Could not update the review schedule.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="p-5 mb-4">
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h2 className="text-base font-semibold text-[var(--color-ink)]">Scheduled reviews</h2>
          <p className="mt-0.5 text-sm text-[var(--color-ink-2)] max-w-md">
            Email this client a periodic reminder to revisit their plan, with a link to their own login.
            Off by default — nothing is sent unless you turn it on.
          </p>
          {lastSentAt && (
            <p className="mt-1 text-xs text-[var(--color-ink-3)]">Last sent {lastSentAt.slice(0, 10)}</p>
          )}
          {error && <p className="mt-1 text-xs" style={{ color: 'var(--color-alert)' }}>{error}</p>}
        </div>
        <div className="flex items-center gap-2">
          {cadence === null ? (
            <span className="text-sm text-[var(--color-ink-3)]">Loading…</span>
          ) : (
            <>
              <select
                value={cadence}
                disabled={busy}
                onChange={(e) => change(e.target.value)}
                className="field !w-auto"
                aria-label="Review cadence"
              >
                <option value="off">Off</option>
                <option value="quarterly">Quarterly</option>
                <option value="annually">Annually</option>
              </select>
              {saved && <span className="text-xs" style={{ color: 'var(--color-teal-ink)' }}>Saved</span>}
            </>
          )}
        </div>
      </div>
    </Card>
  );
}

// docs/10 P0-1 — assign this client to a household (or clear it). Reads the
// firm's households + the client's current one; changes go through
// household_assign.php (tenant-scoped). Links out to the combined view.
function HouseholdAssignCard({ clientId }) {
  const [households, setHouseholds] = useState(null);
  const [current, setCurrent] = useState(''); // '' = none
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    Promise.all([api.listHouseholds(), api.getClientHousehold(clientId)])
      .then(([list, cur]) => {
        if (cancelled) return;
        setHouseholds(list.households);
        setCurrent(cur.household_id ? String(cur.household_id) : '');
      })
      .catch((err) => { if (!cancelled) setError(err.message || 'Could not load households.'); });
    return () => { cancelled = true; };
  }, [clientId]);

  async function change(next) {
    const prev = current;
    setCurrent(next);
    setBusy(true);
    setError('');
    setSaved(false);
    try {
      await api.assignHousehold(clientId, next === '' ? null : Number(next));
      setSaved(true);
      setTimeout(() => setSaved(false), 1800);
    } catch (err) {
      setCurrent(prev);
      setError(err.message || 'Could not update the household.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="p-4 mb-4">
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h2 className="text-base font-semibold text-[var(--color-ink)]">Household</h2>
          <p className="mt-0.5 text-sm text-[var(--color-ink-2)] max-w-md">
            Group this client with family members to see a combined retirement plan.{' '}
            <Link to="/households" className="text-[var(--color-teal-ink)] underline">Manage households</Link>.
          </p>
          {error && <p className="mt-1 text-xs" style={{ color: 'var(--color-alert)' }}>{error}</p>}
          {current && (
            <Link to={`/households/${current}`} className="mt-1 inline-block text-xs text-[var(--color-teal-ink)] underline">
              View combined plan →
            </Link>
          )}
        </div>
        <div className="flex items-center gap-2">
          {households === null ? (
            <span className="text-sm text-[var(--color-ink-3)]">Loading…</span>
          ) : households.length === 0 ? (
            <span className="text-xs text-[var(--color-ink-3)]">No households yet — <Link to="/households" className="underline">create one</Link>.</span>
          ) : (
            <>
              <select
                value={current}
                disabled={busy}
                onChange={(e) => change(e.target.value)}
                className="field !w-auto"
                aria-label="Household"
              >
                <option value="">Not in a household</option>
                {households.map((h) => (
                  <option key={h.id} value={String(h.id)}>{h.name}</option>
                ))}
              </select>
              {saved && <span className="text-xs" style={{ color: 'var(--color-teal-ink)' }}>Saved</span>}
            </>
          )}
        </div>
      </div>
    </Card>
  );
}

// Total tracked corpus and the worst-scoring retirement goal's readiness, in
// one glance — complements RiskProfileSummary/ClientPortfolioCard below,
// which are about the client as a person, not their goals specifically.
function GoalsHealthSummary({ goals }) {
  const totalCorpus = goals.reduce((sum, g) => sum + g.initial_net_worth, 0);
  const scored = goals.filter((g) => g.readiness_score !== null && g.readiness_score !== undefined);
  const worst = scored.length > 0
    ? scored.reduce((min, g) => (g.readiness_score < min.readiness_score ? g : min))
    : null;

  return (
    <Card className="p-4 mb-4" data-tour="goals-health-summary">
      <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
        <div>
          <div className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">Goals</div>
          <div className="tnum text-base font-semibold text-[var(--color-ink)]">{goals.length}</div>
        </div>
        <div>
          <div className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">Tracked corpus</div>
          <div className="tnum text-base font-semibold text-[var(--color-ink)]">{formatCurrency(totalCorpus)}</div>
        </div>
        {worst ? (
          <div>
            <div className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">Lowest readiness</div>
            <div className="mt-0.5 flex items-center gap-2">
              <ReadinessScoreBadge score={worst.readiness_score} />
              <span className="text-xs text-[var(--color-ink-2)] truncate max-w-[12rem]">{worst.goal_label}</span>
            </div>
          </div>
        ) : (
          <span className="text-xs text-[var(--color-ink-3)]">No projectable retirement goal yet</span>
        )}
      </div>
    </Card>
  );
}

const GOAL_TYPES = [
  { value: 'retirement', label: 'Retirement' },
  { value: 'education', label: 'Education' },
  { value: 'home_purchase', label: 'Home purchase' },
  { value: 'other', label: 'Other' },
];

function NewGoalModal({ open, clientId, onClose, onCreated }) {
  const [goalType, setGoalType] = useState('retirement');
  const [goalLabel, setGoalLabel] = useState('');
  const [initialNetWorth, setInitialNetWorth] = useState('');
  const [inflationRate, setInflationRate] = useState('6');
  const [targetAmount, setTargetAmount] = useState('');
  const [targetDate, setTargetDate] = useState('');
  const [withdrawalRate, setWithdrawalRate] = useState('3.5');
  const [horizonYears, setHorizonYears] = useState('30');
  // docs/07 Session C / docs/06 Section A — accumulation-phase fields, all
  // optional. A goal can still be decumulation-only, same as before this session.
  const [showAccumulation, setShowAccumulation] = useState(false);
  const [currentAge, setCurrentAge] = useState('');
  const [retirementAge, setRetirementAge] = useState('');
  const [accumulationReturnRate, setAccumulationReturnRate] = useState('');
  const [monthlySipAmount, setMonthlySipAmount] = useState('');
  const [sipStepUpRate, setSipStepUpRate] = useState('');
  // docs/05 item 3 / docs/06 corpus composition — optional liquid/locked
  // split of initial_net_worth, retirement goals only.
  const [showCorpusComposition, setShowCorpusComposition] = useState(false);
  const [liquidCorpusAmount, setLiquidCorpusAmount] = useState('');
  const [lockedCorpusAmount, setLockedCorpusAmount] = useState('');
  const [lockedReturnRate, setLockedReturnRate] = useState('');
  const [prefillError, setPrefillError] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  function reset() {
    setGoalType('retirement');
    setGoalLabel('');
    setInitialNetWorth('');
    setInflationRate('6');
    setTargetAmount('');
    setTargetDate('');
    setWithdrawalRate('3.5');
    setHorizonYears('30');
    setShowAccumulation(false);
    setCurrentAge('');
    setRetirementAge('');
    setAccumulationReturnRate('');
    setMonthlySipAmount('');
    setSipStepUpRate('');
    setShowCorpusComposition(false);
    setLiquidCorpusAmount('');
    setLockedCorpusAmount('');
    setLockedReturnRate('');
    setPrefillError('');
    setError('');
  }

  // docs/05 item 3 — pull the client's already-recorded portfolio totals as a
  // starting point. Sets initial_net_worth to match too, since liquid+locked
  // must sum to it; the advisor can still edit any of the three afterward if
  // this goal's corpus is meant to be a subset of the client's total.
  async function handlePrefillFromPortfolio() {
    setPrefillError('');
    try {
      const res = await api.getClientPortfolio(clientId);
      const { liquid_total, locked_total } = res.totals;
      if (liquid_total === 0 && locked_total === 0) {
        setPrefillError('No portfolio items recorded for this client yet.');
        return;
      }
      setLiquidCorpusAmount(String(liquid_total));
      setLockedCorpusAmount(String(locked_total));
      setInitialNetWorth(String(liquid_total + locked_total));
    } catch (err) {
      setPrefillError(err.message || 'Could not load this client\'s portfolio.');
    }
  }

  function closeAndReset() {
    onClose();
    setTimeout(reset, 200);
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');

    // Client-side validation mirrors goals_create.php's server checks so the
    // user gets immediate feedback; the server remains the source of truth.
    const label = goalLabel.trim();
    const netWorth = Number(initialNetWorth);
    const inflation = Number(inflationRate);
    const horizon = Number(horizonYears);

    if (!label) return setError('Give the goal a name.');
    if (!Number.isFinite(netWorth) || netWorth < 0) return setError('Enter a valid starting corpus (0 or more).');
    if (!Number.isFinite(inflation) || inflation < 0 || inflation > 100) return setError('Inflation rate must be between 0 and 100%.');
    if (!Number.isInteger(horizon) || horizon < 1 || horizon > 100) return setError('Projection horizon must be a whole number between 1 and 100 years.');

    const isRetirement = goalType === 'retirement';
    let withdrawal;
    if (isRetirement) {
      withdrawal = Number(withdrawalRate);
      if (!Number.isFinite(withdrawal) || withdrawal <= 0 || withdrawal > 20) {
        return setError('Withdrawal rate must be between 0 and 20%.');
      }
    }

    const fields = {
      client_id: Number(clientId),
      goal_type: goalType,
      goal_label: label,
      initial_net_worth: netWorth,
      inflation_rate: inflation,
      projection_horizon_years: horizon,
    };
    if (targetAmount !== '' && Number.isFinite(Number(targetAmount))) {
      fields.target_amount = Number(targetAmount);
    }
    if (targetDate !== '') fields.target_date = targetDate;
    if (isRetirement) fields.withdrawal_rate = withdrawal;

    // docs/07 Session C — all optional, retirement goals only. Mirrors
    // goals_create.php's own validation (retirement_age > current_age).
    if (isRetirement && showAccumulation) {
      const age = currentAge !== '' ? Number(currentAge) : null;
      const retAge = retirementAge !== '' ? Number(retirementAge) : null;
      if (age !== null && retAge !== null && retAge <= age) {
        return setError('Retirement age must be greater than current age.');
      }
      if (age !== null) fields.current_age = age;
      if (retAge !== null) fields.retirement_age = retAge;
      if (accumulationReturnRate !== '') fields.accumulation_return_rate = Number(accumulationReturnRate);
      if (monthlySipAmount !== '') fields.monthly_sip_amount = Number(monthlySipAmount);
      if (sipStepUpRate !== '') fields.sip_step_up_rate = Number(sipStepUpRate);
    }

    // docs/05 item 3 — mirrors goals_create.php's own validation: both-or-
    // neither, and the two must sum to initial_net_worth.
    if (isRetirement && showCorpusComposition && (liquidCorpusAmount !== '' || lockedCorpusAmount !== '')) {
      const liquid = Number(liquidCorpusAmount);
      const locked = Number(lockedCorpusAmount);
      if (liquidCorpusAmount === '' || lockedCorpusAmount === '') {
        return setError('Provide both liquid and locked corpus amounts, or neither.');
      }
      if (!Number.isFinite(liquid) || !Number.isFinite(locked) || liquid < 0 || locked < 0) {
        return setError('Liquid and locked corpus amounts must be valid non-negative numbers.');
      }
      if (Math.abs(liquid + locked - netWorth) > 0.01) {
        return setError(`Liquid + locked (${liquid + locked}) must sum to the starting corpus (${netWorth}).`);
      }
      fields.liquid_corpus_amount = liquid;
      fields.locked_corpus_amount = locked;
      if (lockedReturnRate !== '') fields.locked_return_rate = Number(lockedReturnRate);
    }

    setSubmitting(true);
    try {
      await api.createGoal(fields);
      onCreated();
      reset();
    } catch (err) {
      setError(err.message || 'Could not create this goal.');
    } finally {
      setSubmitting(false);
    }
  }

  const isRetirement = goalType === 'retirement';

  return (
    <Modal
      open={open}
      onClose={closeAndReset}
      title="New goal"
      description="Set up a goal for this client. You can refine scenarios after it's created."
    >
      <form onSubmit={handleSubmit}>
        {/* Practitioner guidance (docs/05 item 4): the multi-goal model already
            supports this — surface it so advisors actually use it. */}
        <div
          className="mb-4 flex items-start gap-2 rounded-[var(--radius-ctrl)] px-3 py-2.5"
          style={{ backgroundColor: 'var(--color-teal-soft)' }}
        >
          <svg width="15" height="15" viewBox="0 0 15 15" fill="none" className="mt-0.5 shrink-0" aria-hidden="true">
            <path d="M7.5 1.5a4 4 0 0 0-2.4 7.2c.4.3.6.7.6 1.1v.4h3.6v-.4c0-.4.2-.8.6-1.1A4 4 0 0 0 7.5 1.5Z"
              stroke="var(--color-teal-ink)" strokeWidth="1.1" fill="none" />
            <path d="M6 12.5h3M6.3 10.7h2.4" stroke="var(--color-teal-ink)" strokeWidth="1.1" strokeLinecap="round" />
          </svg>
          <p className="text-xs leading-relaxed text-[var(--color-teal-ink)]">
            <strong>Tip:</strong> model fast-inflating costs like healthcare as their own goal with a
            higher inflation rate (often 8–14% vs ~6% general), rather than blending them into one
            number — it's the most commonly underestimated line item for retirees.
          </p>
        </div>

        <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Goal type</label>
        <div className="grid grid-cols-2 gap-2 mb-4">
          {GOAL_TYPES.map((t) => {
            const active = goalType === t.value;
            return (
              <button
                key={t.value}
                type="button"
                onClick={() => setGoalType(t.value)}
                className="rounded-[var(--radius-ctrl)] border px-3 py-2 text-sm font-medium transition-colors"
                style={
                  active
                    ? { borderColor: 'var(--color-teal)', backgroundColor: 'var(--color-teal-soft)', color: 'var(--color-teal-ink)' }
                    : { borderColor: 'var(--color-line-2)', color: 'var(--color-ink-2)' }
                }
              >
                {t.label}
              </button>
            );
          })}
        </div>

        <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Goal name</label>
        <input
          type="text" required autoFocus value={goalLabel}
          onChange={(e) => setGoalLabel(e.target.value)}
          placeholder="e.g. Retirement at 60"
          className="field mb-4"
        />

        <div className="grid grid-cols-2 gap-3 mb-4">
          <div>
            <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Starting corpus (₹)</label>
            <input
              type="number" required min="0" step="any" value={initialNetWorth}
              onChange={(e) => setInitialNetWorth(e.target.value)}
              placeholder="0"
              className="field"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Inflation rate (%)</label>
            <input
              type="number" required min="0" max="100" step="any" value={inflationRate}
              onChange={(e) => setInflationRate(e.target.value)}
              placeholder="6"
              className="field"
            />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3 mb-4">
          <div>
            <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">
              Target amount (₹) <span className="text-[var(--color-ink-3)] font-normal">optional</span>
            </label>
            <input
              type="number" min="0" step="any" value={targetAmount}
              onChange={(e) => setTargetAmount(e.target.value)}
              placeholder="—"
              className="field"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">
              Target date <span className="text-[var(--color-ink-3)] font-normal">optional</span>
            </label>
            <input
              type="date" value={targetDate}
              onChange={(e) => setTargetDate(e.target.value)}
              className="field"
            />
          </div>
        </div>

        <div className={`grid gap-3 mb-4 ${isRetirement ? 'grid-cols-2' : 'grid-cols-1'}`}>
          <div>
            <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Horizon (years)</label>
            <input
              type="number" required min="1" max="100" step="1" value={horizonYears}
              onChange={(e) => setHorizonYears(e.target.value)}
              placeholder="30"
              className="field"
            />
          </div>
          {isRetirement && (
            <div>
              <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Withdrawal rate (%)</label>
              <input
                type="number" required min="0" max="20" step="any" value={withdrawalRate}
                onChange={(e) => setWithdrawalRate(e.target.value)}
                placeholder="3.5"
                className="field"
              />
            </div>
          )}
        </div>

        {/* docs/07 Session C / docs/06 Section A — the saving years before
            retirement. Optional: a goal can still be decumulation-only. */}
        {isRetirement && (
          <div className="mb-4">
            <button
              type="button"
              onClick={() => setShowAccumulation((v) => !v)}
              className="text-sm font-medium text-[var(--color-teal-ink)] hover:underline"
            >
              {showAccumulation ? '− Hide' : '+ Add'} accumulation phase (optional)
            </button>
            {showAccumulation && (
              <div className="mt-3 grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Current age</label>
                  <input
                    type="number" min="0" max="120" step="1" value={currentAge}
                    onChange={(e) => setCurrentAge(e.target.value)}
                    placeholder="35"
                    className="field"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Retirement age</label>
                  <input
                    type="number" min="0" max="120" step="1" value={retirementAge}
                    onChange={(e) => setRetirementAge(e.target.value)}
                    placeholder="60"
                    className="field"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Pre-retirement return (%)</label>
                  <input
                    type="number" min="0" max="100" step="any" value={accumulationReturnRate}
                    onChange={(e) => setAccumulationReturnRate(e.target.value)}
                    placeholder="10"
                    className="field"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Monthly SIP (₹)</label>
                  <input
                    type="number" min="0" step="any" value={monthlySipAmount}
                    onChange={(e) => setMonthlySipAmount(e.target.value)}
                    placeholder="25000"
                    className="field"
                  />
                </div>
                <div className="col-span-2">
                  <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">
                    Annual SIP step-up (%) <span className="text-[var(--color-ink-3)] font-normal">optional</span>
                  </label>
                  <input
                    type="number" min="0" max="100" step="any" value={sipStepUpRate}
                    onChange={(e) => setSipStepUpRate(e.target.value)}
                    placeholder="10"
                    className="field"
                  />
                </div>
              </div>
            )}
          </div>
        )}

        {/* docs/05 item 3 / docs/06 corpus composition — optional liquid vs
            locked split. Optional: a goal can still be a single undecomposed corpus. */}
        {isRetirement && (
          <div className="mb-4">
            <button
              type="button"
              onClick={() => setShowCorpusComposition((v) => !v)}
              className="text-sm font-medium text-[var(--color-teal-ink)] hover:underline"
            >
              {showCorpusComposition ? '− Hide' : '+ Add'} corpus composition (optional)
            </button>
            {showCorpusComposition && (
              <div className="mt-3">
                <div className="flex items-center justify-between mb-2">
                  <p className="text-xs text-[var(--color-ink-2)]">
                    Split the starting corpus into liquid (market-linked) vs locked (EPF/NPS/PPF-style) — withdrawals
                    draw from liquid first, then locked.
                  </p>
                  <Button type="button" variant="outline" size="sm" onClick={handlePrefillFromPortfolio} className="shrink-0 ml-2">
                    Use client's portfolio
                  </Button>
                </div>
                {prefillError && <p className="text-xs mb-2" style={{ color: 'var(--color-alert)' }}>{prefillError}</p>}
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Liquid corpus (₹)</label>
                    <input
                      type="number" min="0" step="any" value={liquidCorpusAmount}
                      onChange={(e) => setLiquidCorpusAmount(e.target.value)}
                      className="field"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Locked corpus (₹)</label>
                    <input
                      type="number" min="0" step="any" value={lockedCorpusAmount}
                      onChange={(e) => setLockedCorpusAmount(e.target.value)}
                      className="field"
                    />
                  </div>
                  <div className="col-span-2">
                    <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">
                      Locked bucket return (%) <span className="text-[var(--color-ink-3)] font-normal">optional</span>
                    </label>
                    <input
                      type="number" min="0" max="100" step="any" value={lockedReturnRate}
                      onChange={(e) => setLockedReturnRate(e.target.value)}
                      placeholder="e.g. 8"
                      className="field"
                    />
                  </div>
                </div>
                {liquidCorpusAmount !== '' && lockedCorpusAmount !== '' && (
                  <p className="mt-1.5 text-[11px] text-[var(--color-ink-3)]">
                    Sum: {(Number(liquidCorpusAmount) || 0) + (Number(lockedCorpusAmount) || 0)} — must match the starting corpus above.
                  </p>
                )}
              </div>
            )}
          </div>
        )}

        {error && (
          <p className="mb-4 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2"
             style={{ color: 'var(--color-alert)' }}>
            {error}
          </p>
        )}

        <div className="flex gap-2">
          <Button type="submit" disabled={submitting} className="flex-1">
            {submitting ? 'Creating…' : 'Create goal'}
          </Button>
          <Button type="button" variant="ghost" onClick={closeAndReset}>
            Cancel
          </Button>
        </div>
      </form>
    </Modal>
  );
}
