// docs/11 Prompt E-2 · Progressive personalisation — "want to make this more
// accurate?" A short, individually-skippable queue that refines the
// retirement target E-1 already computes, plus a per-child list feeding
// education-goal target ranges. Self-serve only (tenants.kind='personal') —
// this is scoped to "the self-serve plan" per docs/11 §4, mirroring
// ClientFoundationsCard's editability gate.
//
// DESIGN PRINCIPLES THIS FOLLOWS (docs/11 §2), same as FoundationsUI:
//   - every field defaults to unset, and 'not answered' is never hidden away
//   - a sourced range/default is always visibly distinguished from the
//     person's own figure (their own is never shown WITH a citation)
//   - nothing here is applied silently — the goal page states what moved
//     the number, right next to the number itself (see RetirementTargetCard)
//
// Two exports:
//   PersonalisationCard — city tier + medical cost, roster-level (GoalsList)
//   DependantsCard      — per-child age + education cost driver + goal link

import { useEffect, useState, useCallback } from 'react';
import { api } from '../lib/api';
import { Card, Button, Spinner } from './ui';
import { formatCurrency } from '../lib/format';

const field = 'w-full rounded-md border border-[var(--color-line)] bg-[var(--color-surface)] px-3 py-2 text-sm text-[var(--color-ink)]';

const TIER_ORDER = ['X', 'Y', 'Z'];

function TierPicker({ value, onChange, labels }) {
  return (
    <div className="grid gap-2 sm:grid-cols-3">
      {TIER_ORDER.map((tier) => (
        <button
          key={tier}
          type="button"
          onClick={() => onChange(tier)}
          className="rounded-md border px-3 py-2 text-left text-xs transition-colors"
          style={{
            borderColor: value === tier ? 'var(--color-teal)' : 'var(--color-line)',
            backgroundColor: value === tier ? 'var(--color-teal-soft, var(--color-surface-2))' : 'var(--color-surface)',
          }}
        >
          <div className="font-semibold text-[var(--color-ink)]">Tier {tier}</div>
          <div className="mt-0.5 text-[11px] text-[var(--color-ink-2)]">{labels?.[tier] ?? ''}</div>
        </button>
      ))}
    </div>
  );
}

// One opt-in question. Renders the CURRENT effective value (own answer or a
// sourced default) and, when editing, a form to change or skip it.
function CityTierQuestion({ context, labels, onSaved }) {
  const [editing, setEditing] = useState(false);
  const [tier, setTier] = useState(context?.own_city_tier ?? '');
  const [multiplier, setMultiplier] = useState(context?.own_expense_multiplier ?? '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const save = async () => {
    setSaving(true);
    setError('');
    try {
      await api.savePersonalisationContext({
        city_tier: tier || null,
        expense_multiplier: multiplier === '' ? null : Number(multiplier),
      });
      setEditing(false);
      onSaved();
    } catch (err) {
      setError(err.message || 'Could not save.');
    } finally {
      setSaving(false);
    }
  };

  const effectiveTier = context?.effective_city_tier;
  const effectiveMultiplier = context?.effective_multiplier;
  const isOverride = context?.multiplier_is_override;
  const fromPartner = context?.tier_answered_by_partner;

  return (
    <div className="border-t border-[var(--color-line)] pt-3 first:border-t-0 first:pt-0">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="text-sm font-medium text-[var(--color-ink)]">Where will you be living in retirement?</p>
          <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">
            {context?.multiplier_applied ? (
              <>
                Currently assuming <strong className="text-[var(--color-ink)]">×{effectiveMultiplier}</strong> your
                recorded spend{isOverride ? ' — your own figure' : ` — ${labels?.[effectiveTier] ?? `tier ${effectiveTier}`} default`}
                {fromPartner ? ', from your household partner’s answer' : ''}.
                {!isOverride && ' Sourced: 7th CPC HRA classification.'}
              </>
            ) : (
              'Not answered — this plan assumes you’ll spend where you retire the same as today.'
            )}
          </p>
        </div>
        <Button variant="ghost" size="sm" onClick={() => setEditing((v) => !v)}>
          {editing ? 'Close' : context?.multiplier_applied ? 'Change' : 'Answer'}
        </Button>
      </div>

      {editing && (
        <div className="mt-3 rounded-lg bg-[var(--color-surface-2)] p-3">
          <TierPicker value={tier} onChange={setTier} labels={labels} />
          <label className="mt-3 block">
            <span className="mb-1 block text-xs font-medium text-[var(--color-ink-2)]">
              Or set your own multiplier directly (1.00 = same as today)
            </span>
            <input
              className={field} type="number" min="0.3" max="2" step="0.01"
              value={multiplier}
              onChange={(e) => setMultiplier(e.target.value)}
              placeholder="e.g. 0.85"
            />
          </label>
          {error && <p className="mt-2 text-xs text-[var(--color-alert)]">{error}</p>}
          <div className="mt-3 flex items-center gap-2">
            <Button size="sm" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save'}</Button>
            <span className="text-[11px] text-[var(--color-ink-3)]">Leave both blank to skip this one.</span>
          </div>
        </div>
      )}
    </div>
  );
}

function MedicalCostQuestion({ context, onSaved }) {
  const [editing, setEditing] = useState(false);
  const [cost, setCost] = useState(context?.monthly_medical_cost ?? '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const save = async () => {
    setSaving(true);
    setError('');
    try {
      await api.savePersonalisationContext({ monthly_medical_cost: cost === '' ? null : Number(cost) });
      setEditing(false);
      onSaved();
    } catch (err) {
      setError(err.message || 'Could not save.');
    } finally {
      setSaving(false);
    }
  };

  const recorded = context?.monthly_medical_cost;

  return (
    <div className="border-t border-[var(--color-line)] pt-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="text-sm font-medium text-[var(--color-ink)]">
            Any ongoing medical cost not already in your cash flow?
          </p>
          <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">
            {recorded
              ? <>Adding <strong className="text-[var(--color-ink)]">{formatCurrency(recorded)}/month</strong> to your retirement-year spend.</>
              : 'A recurring cost — a regular prescription, ongoing care — not already in your cash-flow expenses. Not asking what it’s for.'}
          </p>
        </div>
        <Button variant="ghost" size="sm" onClick={() => setEditing((v) => !v)}>
          {editing ? 'Close' : recorded ? 'Change' : 'Answer'}
        </Button>
      </div>

      {editing && (
        <div className="mt-3 rounded-lg bg-[var(--color-surface-2)] p-3">
          <label className="block">
            <span className="mb-1 block text-xs font-medium text-[var(--color-ink-2)]">Monthly cost (₹)</span>
            <input className={field} type="number" min="0" value={cost} onChange={(e) => setCost(e.target.value)} placeholder="—" />
          </label>
          {error && <p className="mt-2 text-xs text-[var(--color-alert)]">{error}</p>}
          <div className="mt-3 flex items-center gap-2">
            <Button size="sm" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save'}</Button>
            <span className="text-[11px] text-[var(--color-ink-3)]">Leave blank to skip.</span>
          </div>
        </div>
      )}
    </div>
  );
}

// Self-serve only — see file header. Rendered on the client's own goals roster.
export function PersonalisationCard() {
  const [context, setContext] = useState(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(() => {
    let cancelled = false;
    api.getPersonalisationContext()
      .then((res) => { if (!cancelled) setContext(res); })
      .catch(() => { /* soft — an unreadable context just shows the unanswered state */ })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  useEffect(() => load(), [load]);

  return (
    <Card className="p-4 mb-4">
      <h2 className="text-base font-semibold text-[var(--color-ink)]">Make this more accurate</h2>
      <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">
        A couple of optional questions. Each one moves the retirement target on your goal page — answer as many
        or as few as you like.
      </p>

      {loading && <div className="mt-3"><Spinner label="Loading…" /></div>}

      {!loading && (
        <div className="mt-3 space-y-3">
          <CityTierQuestion context={context?.context} labels={context?.city_tier_labels} onSaved={load} />
          <MedicalCostQuestion context={context?.context} onSaved={load} />
        </div>
      )}
    </Card>
  );
}

// --- Dependants (per-child education cost driver) --------------------------

function DependantRow({ dependant, driverLabels, educationGoals, onChanged }) {
  const [editing, setEditing] = useState(false);
  const [label, setLabel] = useState(dependant.label ?? '');
  const [age, setAge] = useState(dependant.current_age ?? '');
  const [driver, setDriver] = useState(dependant.cost_driver ?? '');
  const [goalId, setGoalId] = useState(dependant.goal ? String(dependant.goal.id) : '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const save = async () => {
    setSaving(true);
    setError('');
    try {
      await api.saveDependant({
        id: dependant.id,
        label: label || null,
        current_age: age === '' ? null : Number(age),
        cost_driver: driver || null,
        goal_id: goalId === '' ? null : Number(goalId),
      });
      setEditing(false);
      onChanged();
    } catch (err) {
      setError(err.message || 'Could not save.');
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    if (!window.confirm('Remove this child?')) return;
    await api.deleteDependant(dependant.id);
    onChanged();
  };

  const range = dependant.suggested_range;

  return (
    <div className="rounded-lg border border-[var(--color-line)] p-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="text-sm font-medium text-[var(--color-ink)]">
            {dependant.label || `Child${dependant.current_age != null ? `, age ${dependant.current_age}` : ''}`}
          </p>
          {dependant.cost_driver ? (
            <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">
              {driverLabels?.[dependant.cost_driver] ?? dependant.cost_driver}
              {range && ` — typically ${formatCurrency(range.low)}–${formatCurrency(range.high)} total (${range.source_name}, ${range.source_as_of})`}
            </p>
          ) : (
            <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">Cost driver not answered yet.</p>
          )}
          {dependant.goal ? (
            <p className="mt-0.5 text-[11px] text-[var(--color-ink-3)]">
              Linked to &ldquo;{dependant.goal.goal_label}&rdquo; (currently {formatCurrency(dependant.goal.target_amount ?? 0)})
            </p>
          ) : (
            <p className="mt-0.5 text-[11px] text-[var(--color-ink-3)]">Not linked to a goal yet.</p>
          )}
        </div>
        <div className="flex gap-1">
          <Button variant="ghost" size="sm" onClick={() => setEditing((v) => !v)}>{editing ? 'Close' : 'Edit'}</Button>
          <Button variant="ghost" size="sm" onClick={remove}>Remove</Button>
        </div>
      </div>

      {editing && (
        <div className="mt-3 rounded-lg bg-[var(--color-surface-2)] p-3">
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block">
              <span className="mb-1 block text-xs font-medium text-[var(--color-ink-2)]">Label (optional)</span>
              <input className={field} value={label} onChange={(e) => setLabel(e.target.value)} placeholder="e.g. Older child" />
            </label>
            <label className="block">
              <span className="mb-1 block text-xs font-medium text-[var(--color-ink-2)]">Current age</span>
              <input className={field} type="number" min="0" max="30" value={age} onChange={(e) => setAge(e.target.value)} placeholder="—" />
            </label>
            <label className="block">
              <span className="mb-1 block text-xs font-medium text-[var(--color-ink-2)]">Cost driver</span>
              <select className={field} value={driver} onChange={(e) => setDriver(e.target.value)}>
                <option value="">Not answered</option>
                <option value="government">Government seat</option>
                <option value="private">Private institute</option>
                <option value="overseas">Overseas</option>
              </select>
            </label>
            <label className="block">
              <span className="mb-1 block text-xs font-medium text-[var(--color-ink-2)]">Linked education goal</span>
              <select className={field} value={goalId} onChange={(e) => setGoalId(e.target.value)}>
                <option value="">Not linked</option>
                {educationGoals.map((g) => (
                  <option key={g.id} value={g.id}>{g.goal_label}</option>
                ))}
              </select>
            </label>
          </div>
          {error && <p className="mt-2 text-xs text-[var(--color-alert)]">{error}</p>}
          <div className="mt-3">
            <Button size="sm" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save'}</Button>
          </div>
        </div>
      )}
    </div>
  );
}

// Self-serve only. Renders nothing when there are no education-type goals to
// link against AND no dependants recorded yet — no point offering this before
// there's anything for it to feed.
export function DependantsCard() {
  const [data, setData] = useState(null);
  const [goals, setGoals] = useState([]);
  const [loading, setLoading] = useState(true);
  const [adding, setAdding] = useState(false);

  const load = useCallback(() => {
    let cancelled = false;
    Promise.all([api.getDependants(), api.listGoals()])
      .then(([dep, goalRes]) => {
        if (cancelled) return;
        setData(dep);
        setGoals((goalRes.goals || []).filter((g) => g.goal_type === 'education'));
      })
      .catch(() => { /* soft */ })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  useEffect(() => load(), [load]);

  const addChild = async () => {
    setAdding(true);
    try {
      await api.saveDependant({});
      load();
    } finally {
      setAdding(false);
    }
  };

  const dependants = data?.dependants ?? [];
  if (!loading && dependants.length === 0 && goals.length === 0) return null;

  return (
    <Card className="p-4 mb-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-[var(--color-ink)]">Children&rsquo;s education</h2>
          <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">
            Add each child&rsquo;s age and whether you&rsquo;re planning for a government seat, a private institute,
            or overseas study — this is what actually moves the cost, far more than the course itself.
          </p>
        </div>
        <Button variant="ghost" size="sm" onClick={addChild} disabled={adding}>{adding ? 'Adding…' : '+ Add child'}</Button>
      </div>

      {loading && <div className="mt-3"><Spinner label="Loading…" /></div>}

      {!loading && dependants.length > 0 && (
        <div className="mt-3 space-y-2">
          {dependants.map((d) => (
            <DependantRow key={d.id} dependant={d} driverLabels={data?.driver_labels} educationGoals={goals} onChanged={load} />
          ))}
        </div>
      )}
    </Card>
  );
}
