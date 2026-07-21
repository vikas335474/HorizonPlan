import { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { api } from '../lib/api';
import AppHeader from '../components/AppHeader';
import DisclosureBanner from '../components/DisclosureBanner';
import GoalCard from '../components/GoalCard';
import Modal from '../components/Modal';
import { Card, EmptyState, Spinner, Button } from '../components/ui';

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
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
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
    setError('');
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
