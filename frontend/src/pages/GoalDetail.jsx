import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import AppHeader from '../components/AppHeader';
import DisclosureBanner from '../components/DisclosureBanner';
import ScenarioPanel from '../components/ScenarioPanel';
import { Card, Badge, Button, Spinner } from '../components/ui';
import { ApplyTemplateModal } from '../components/TemplateUI';
import { ReadinessScoreCard } from '../components/ReadinessScore';
import LifecycleChart from '../components/LifecycleChart';
import {
  formatCurrency,
  formatPercent,
  formatDate,
  GOAL_TYPE_LABELS,
  GOAL_TYPE_ACCENT,
  corpusMultiple,
} from '../lib/format';
import { presetsForGoalType } from '../lib/strategyPresets';

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
  const [baselineProjection, setBaselineProjection] = useState(null);
  const [applyModalOpen, setApplyModalOpen] = useState(false);
  const [appliedSourceName, setAppliedSourceName] = useState(null);
  // docs/07 Session C / docs/06 Section A — accumulation-phase edit form.
  const [editingAccumulation, setEditingAccumulation] = useState(false);
  const [accForm, setAccForm] = useState(null);
  const [accError, setAccError] = useState('');
  const [accSaving, setAccSaving] = useState(false);
  // docs/05 item 3 / docs/06 corpus composition — liquid/locked edit form.
  const [editingCorpus, setEditingCorpus] = useState(false);
  const [corpusForm, setCorpusForm] = useState(null);
  const [corpusError, setCorpusError] = useState('');
  const [corpusSaving, setCorpusSaving] = useState(false);
  // docs/09 Pre-Launch Hardening Session 1 — the goal's own core fields
  // (starting corpus, inflation, target, withdrawal/drawdown rates, horizon)
  // had no edit UI at all before this session; only accumulation and corpus
  // composition (added in later sessions) were ever editable post-creation.
  const [editingBasics, setEditingBasics] = useState(false);
  const [basicsForm, setBasicsForm] = useState(null);
  const [basicsError, setBasicsError] = useState('');
  const [basicsSaving, setBasicsSaving] = useState(false);

  function loadGoal() {
    return api.getGoal(id).then((res) => {
      setGoal(res.goal);
      return res.goal;
    });
  }

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError('');
    Promise.all([loadGoal(), api.listSubScenarios(id)])
      .then(([, subRes]) => {
        if (cancelled) return;
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  // Baseline readiness score (docs/07 Bet 3) — the goal's own values, not a
  // sub-scenario's. goals_projection.php 400s if the required rates aren't
  // set yet, which is a normal state for a freshly created retirement goal —
  // swallow that case rather than surfacing it as an error banner.
  useEffect(() => {
    if (!goal || goal.goal_type !== 'retirement') return;
    let cancelled = false;
    api.getProjection(goal.id)
      .then((res) => { if (!cancelled) setBaselineProjection(res); })
      .catch(() => { if (!cancelled) setBaselineProjection(null); });
    return () => { cancelled = true; };
  }, [
    goal?.id, goal?.withdrawal_rate, goal?.drawdown_return_rate, goal?.inflation_rate,
    goal?.current_age, goal?.retirement_age, goal?.accumulation_return_rate,
    goal?.monthly_sip_amount, goal?.sip_step_up_rate,
    goal?.liquid_corpus_amount, goal?.locked_corpus_amount, goal?.locked_return_rate,
  ]);

  // Resolve the currently-applied template/customization's display name
  // (docs/07 Bet 1) — goals_read.php only returns the ID, since a global
  // template may live outside this tenant; resolve from whatever the
  // advisor already has library access to.
  useEffect(() => {
    if (!goal || (!goal.applied_template_id && !goal.applied_customization_id)) {
      setAppliedSourceName(null);
      return;
    }
    let cancelled = false;
    api.listTemplates().then((res) => {
      if (cancelled) return;
      const all = [...(res.global_templates ?? []), ...(res.my_templates ?? []), ...(res.my_customizations ?? [])];
      const match = all.find((t) =>
        (goal.applied_template_id && t.id === goal.applied_template_id) ||
        (goal.applied_customization_id && t.id === goal.applied_customization_id)
      );
      setAppliedSourceName(match?.template_name ?? null);
    }).catch(() => { if (!cancelled) setAppliedSourceName(null); });
    return () => { cancelled = true; };
  }, [goal?.applied_template_id, goal?.applied_customization_id]);

  async function handleTemplateApplied() {
    setApplyModalOpen(false);
    await loadGoal();
  }

  function startEditingAccumulation() {
    setAccForm({
      current_age: goal.current_age ?? '',
      retirement_age: goal.retirement_age ?? '',
      accumulation_return_rate: goal.accumulation_return_rate ?? '',
      monthly_sip_amount: goal.monthly_sip_amount ?? '',
      sip_step_up_rate: goal.sip_step_up_rate ?? '',
    });
    setAccError('');
    setEditingAccumulation(true);
  }

  async function handleSaveAccumulation(e) {
    e.preventDefault();
    setAccError('');

    const age = accForm.current_age !== '' ? Number(accForm.current_age) : null;
    const retAge = accForm.retirement_age !== '' ? Number(accForm.retirement_age) : null;
    if (age !== null && retAge !== null && retAge <= age) {
      setAccError('Retirement age must be greater than current age.');
      return;
    }

    const fields = {
      current_age: age,
      retirement_age: retAge,
      accumulation_return_rate: accForm.accumulation_return_rate !== '' ? Number(accForm.accumulation_return_rate) : null,
      monthly_sip_amount: accForm.monthly_sip_amount !== '' ? Number(accForm.monthly_sip_amount) : null,
      sip_step_up_rate: accForm.sip_step_up_rate !== '' ? Number(accForm.sip_step_up_rate) : null,
    };

    setAccSaving(true);
    try {
      await api.updateGoal(goal.id, fields);
      await loadGoal();
      setEditingAccumulation(false);
    } catch (err) {
      setAccError(err.message || 'Could not save these changes.');
    } finally {
      setAccSaving(false);
    }
  }

  function startEditingCorpus() {
    setCorpusForm({
      liquid_corpus_amount: goal.liquid_corpus_amount ?? '',
      locked_corpus_amount: goal.locked_corpus_amount ?? '',
      locked_return_rate: goal.locked_return_rate ?? '',
    });
    setCorpusError('');
    setEditingCorpus(true);
  }

  async function handleSaveCorpus(e) {
    e.preventDefault();
    setCorpusError('');

    const liquid = corpusForm.liquid_corpus_amount !== '' ? Number(corpusForm.liquid_corpus_amount) : null;
    const locked = corpusForm.locked_corpus_amount !== '' ? Number(corpusForm.locked_corpus_amount) : null;
    if ((liquid === null) !== (locked === null)) {
      setCorpusError('Provide both liquid and locked corpus amounts, or clear both.');
      return;
    }
    if (liquid !== null && locked !== null && Math.abs(liquid + locked - goal.initial_net_worth) > 0.01) {
      setCorpusError(`Liquid + locked (${liquid + locked}) must sum to the starting corpus (${goal.initial_net_worth}).`);
      return;
    }

    const fields = {
      liquid_corpus_amount: liquid,
      locked_corpus_amount: locked,
      locked_return_rate: corpusForm.locked_return_rate !== '' ? Number(corpusForm.locked_return_rate) : null,
    };

    setCorpusSaving(true);
    try {
      await api.updateGoal(goal.id, fields);
      await loadGoal();
      setEditingCorpus(false);
    } catch (err) {
      setCorpusError(err.message || 'Could not save these changes.');
    } finally {
      setCorpusSaving(false);
    }
  }

  function startEditingBasics() {
    setBasicsForm({
      initial_net_worth: goal.initial_net_worth ?? '',
      target_amount: goal.target_amount ?? '',
      target_date: goal.target_date ?? '',
      inflation_rate: goal.inflation_rate ?? '',
      projection_horizon_years: goal.projection_horizon_years ?? '',
      withdrawal_rate: goal.withdrawal_rate ?? '',
      drawdown_return_rate: goal.drawdown_return_rate ?? '',
    });
    setBasicsError('');
    setEditingBasics(true);
  }

  // Client-side validation mirrors GoalFieldValidation.php's rules so the
  // advisor gets immediate feedback; the server remains the source of truth.
  async function handleSaveBasics(e) {
    e.preventDefault();
    setBasicsError('');

    const netWorth = Number(basicsForm.initial_net_worth);
    if (!Number.isFinite(netWorth) || netWorth <= 0) {
      return setBasicsError('Starting corpus must be greater than 0.');
    }
    const inflation = Number(basicsForm.inflation_rate);
    if (!Number.isFinite(inflation) || inflation < 0 || inflation > 100) {
      return setBasicsError('Inflation rate must be between 0 and 100%.');
    }
    const horizon = Number(basicsForm.projection_horizon_years);
    if (!Number.isInteger(horizon) || horizon < 1 || horizon > 100) {
      return setBasicsError('Projection horizon must be a whole number between 1 and 100 years.');
    }
    if (basicsForm.target_amount !== '' && (!Number.isFinite(Number(basicsForm.target_amount)) || Number(basicsForm.target_amount) <= 0)) {
      return setBasicsError('Target amount must be greater than 0, or left blank.');
    }

    const fields = {
      initial_net_worth: netWorth,
      inflation_rate: inflation,
      projection_horizon_years: horizon,
      target_amount: basicsForm.target_amount !== '' ? Number(basicsForm.target_amount) : null,
      target_date: basicsForm.target_date !== '' ? basicsForm.target_date : null,
    };

    if (isRetirement) {
      if (basicsForm.withdrawal_rate !== '') {
        const wd = Number(basicsForm.withdrawal_rate);
        if (!Number.isFinite(wd) || wd < 0 || wd > 100) {
          return setBasicsError('Withdrawal rate must be between 0 and 100%.');
        }
        fields.withdrawal_rate = wd;
      } else {
        fields.withdrawal_rate = null;
      }
      if (basicsForm.drawdown_return_rate !== '') {
        const dd = Number(basicsForm.drawdown_return_rate);
        if (!Number.isFinite(dd) || dd < 0 || dd > 100) {
          return setBasicsError('Post-retirement return must be between 0 and 100%.');
        }
        fields.drawdown_return_rate = dd;
      } else {
        fields.drawdown_return_rate = null;
      }
    }

    setBasicsSaving(true);
    try {
      await api.updateGoal(goal.id, fields);
      await loadGoal();
      setEditingBasics(false);
    } catch (err) {
      setBasicsError(err.message || 'Could not save these changes.');
    } finally {
      setBasicsSaving(false);
    }
  }

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

  // Apply a strategy preset: create a fresh sub-scenario, then override it with
  // the preset's parameters through the existing endpoints (no new backend).
  // The server stays the source of truth — the projection redraws from it.
  async function handleApplyPreset(preset) {
    setCreating(true);
    setCreateError('');
    try {
      const res = await api.createSubScenario(goal.id);
      await api.updateSubScenario(res.sub_scenario_id, preset.params);
      const fresh = await api.listSubScenarios(goal.id);
      setSubScenarios(fresh.sub_scenarios);
      setExpandedId(res.sub_scenario_id);
    } catch (err) {
      setCreateError(err.message || 'Could not apply that preset.');
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
              <div className="flex items-center gap-2">
                <Button variant="ghost" onClick={() => navigate(`/goals/${goal.id}/report`)}>
                  <svg width="15" height="15" viewBox="0 0 15 15" fill="none" aria-hidden="true" className="mr-1.5">
                    <path d="M4 2.5h5l2.5 2.5v7.5h-7.5z" stroke="currentColor" strokeWidth="1.2" strokeLinejoin="round" />
                    <path d="M5.5 7.5h4M5.5 9.5h4" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" />
                  </svg>
                  Client report
                </Button>
                <Button variant="teal" data-tour="meeting-mode-button" onClick={() => navigate(`/goals/${goal.id}/meeting`)}>
                  <svg width="15" height="15" viewBox="0 0 15 15" fill="none" aria-hidden="true" className="mr-1.5">
                    <rect x="2" y="3" width="11" height="7.5" rx="1" stroke="currentColor" strokeWidth="1.2" />
                    <path d="M5.5 13h4" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" />
                  </svg>
                  Meeting Mode
                </Button>
              </div>
            </div>

            {/* Plan parameters — key figures grid, editable (docs/09
                Pre-Launch Hardening Session 1: these core fields had no edit
                UI at all before this session). */}
            <Card className="p-5 mb-4">
              <div className="flex items-center justify-between gap-3 mb-1">
                <h2 className="text-base font-semibold text-[var(--color-ink)]">Plan parameters</h2>
                {!editingBasics && (
                  <Button variant="outline" size="sm" onClick={startEditingBasics}>
                    Edit
                  </Button>
                )}
              </div>

              {!editingBasics && (
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-4 mt-3">
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
                  {isRetirement && goal.current_age !== null && goal.retirement_age !== null && (
                    <Param label="Age now → retirement" value={`${goal.current_age} → ${goal.retirement_age}`} />
                  )}
                  {isRetirement && goal.accumulation_return_rate !== null && (
                    <Param label="Pre-retirement return" value={formatPercent(goal.accumulation_return_rate)} />
                  )}
                  {isRetirement && goal.monthly_sip_amount !== null && (
                    <Param
                      label="Monthly SIP"
                      value={formatCurrency(goal.monthly_sip_amount)}
                      sub={goal.sip_step_up_rate !== null ? `+${formatPercent(goal.sip_step_up_rate)}/yr step-up` : null}
                    />
                  )}
                  {isRetirement && goal.liquid_corpus_amount !== null && (
                    <Param label="Liquid corpus" value={formatCurrency(goal.liquid_corpus_amount)} />
                  )}
                  {isRetirement && goal.locked_corpus_amount !== null && (
                    <Param
                      label="Locked corpus"
                      value={formatCurrency(goal.locked_corpus_amount)}
                      sub={goal.locked_return_rate !== null ? `${formatPercent(goal.locked_return_rate)} return` : null}
                    />
                  )}
                </div>
              )}

              {editingBasics && (
                <form onSubmit={handleSaveBasics} className="mt-2" noValidate>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Starting corpus (₹)</label>
                      <input
                        type="number" min="0.01" step="any" value={basicsForm.initial_net_worth}
                        onChange={(e) => setBasicsForm((f) => ({ ...f, initial_net_worth: e.target.value }))}
                        className="field"
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Inflation rate (%)</label>
                      <input
                        type="number" min="0" max="100" step="any" value={basicsForm.inflation_rate}
                        onChange={(e) => setBasicsForm((f) => ({ ...f, inflation_rate: e.target.value }))}
                        className="field"
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Target amount (₹, optional)</label>
                      <input
                        type="number" min="0.01" step="any" value={basicsForm.target_amount}
                        onChange={(e) => setBasicsForm((f) => ({ ...f, target_amount: e.target.value }))}
                        className="field"
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Target date (optional)</label>
                      <input
                        type="date" value={basicsForm.target_date}
                        onChange={(e) => setBasicsForm((f) => ({ ...f, target_date: e.target.value }))}
                        className="field"
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Projection horizon (years)</label>
                      <input
                        type="number" min="1" max="100" step="1" value={basicsForm.projection_horizon_years}
                        onChange={(e) => setBasicsForm((f) => ({ ...f, projection_horizon_years: e.target.value }))}
                        className="field"
                      />
                    </div>
                    {isRetirement && (
                      <div>
                        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Withdrawal rate (%, optional)</label>
                        <input
                          type="number" min="0" max="100" step="any" value={basicsForm.withdrawal_rate}
                          onChange={(e) => setBasicsForm((f) => ({ ...f, withdrawal_rate: e.target.value }))}
                          className="field"
                        />
                      </div>
                    )}
                    {isRetirement && (
                      <div>
                        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Post-retirement return (%, optional)</label>
                        <input
                          type="number" min="0" max="100" step="any" value={basicsForm.drawdown_return_rate}
                          onChange={(e) => setBasicsForm((f) => ({ ...f, drawdown_return_rate: e.target.value }))}
                          className="field"
                        />
                      </div>
                    )}
                  </div>

                  {basicsError && (
                    <p className="mt-3 text-sm" style={{ color: 'var(--color-alert)' }}>{basicsError}</p>
                  )}

                  <div className="flex gap-2 mt-3">
                    <Button type="submit" size="sm" disabled={basicsSaving}>
                      {basicsSaving ? 'Saving…' : 'Save'}
                    </Button>
                    <Button type="button" variant="ghost" size="sm" onClick={() => setEditingBasics(false)}>
                      Cancel
                    </Button>
                  </div>
                </form>
              )}
            </Card>

            {/* Retirement Readiness Score (docs/07 Bet 3) — the goal's own
                baseline, computed from goals_projection.php. Null while the
                goal is missing withdrawal_rate/drawdown_return_rate. */}
            {isRetirement && baselineProjection?.readiness_score != null && (
              <div className="mb-4" data-tour="readiness-score-card">
                <ReadinessScoreCard score={baselineProjection.readiness_score} />
              </div>
            )}

            <div className="mb-6">
              <DisclosureBanner />
            </div>

            {/* Apply a strategy template (docs/07 Bet 1) — the loop-closer:
                sets drawdown_return_rate from an approved template/customization
                rather than the advisor typing a number in by hand. */}
            {isRetirement && (
              <Card className="p-4 mb-4">
                <div className="flex items-center justify-between gap-3 mb-1">
                  <h2 className="text-base font-semibold text-[var(--color-ink)]">Strategy template</h2>
                  <Button variant="outline" size="sm" onClick={() => setApplyModalOpen(true)}>
                    Apply a template
                  </Button>
                </div>
                <p className="text-xs text-[var(--color-ink-2)]">
                  {appliedSourceName
                    ? <>Currently applied: <strong className="text-[var(--color-ink)]">{appliedSourceName}</strong> — sets the post-retirement return assumption above.</>
                    : 'No template applied yet — this goal\'s post-retirement return is set manually.'}
                </p>
              </Card>
            )}

            {/* Accumulation phase (docs/07 Session C / docs/06 Section A) — the
                only base-goal fields with an edit-after-creation UI so far,
                since age/SIP naturally drift year to year unlike a one-time
                goal setup. */}
            {isRetirement && (
              <Card className="p-4 mb-4">
                <div className="flex items-center justify-between gap-3 mb-1">
                  <h2 className="text-base font-semibold text-[var(--color-ink)]">Accumulation phase</h2>
                  {!editingAccumulation && (
                    <Button variant="outline" size="sm" onClick={startEditingAccumulation}>
                      {goal.current_age !== null ? 'Edit' : 'Add saving years'}
                    </Button>
                  )}
                </div>

                {!editingAccumulation && goal.current_age === null && (
                  <p className="text-xs text-[var(--color-ink-2)]">
                    Not set — this goal projects decumulation only, starting from today's corpus.
                  </p>
                )}

                {!editingAccumulation && goal.current_age !== null && baselineProjection?.lifecycle_series && (
                  <div className="mt-2">
                    <LifecycleChart
                      series={baselineProjection.lifecycle_series}
                      yearsToRetirement={baselineProjection.accumulation_assumptions.years_to_retirement}
                    />
                  </div>
                )}

                {editingAccumulation && (
                  <form onSubmit={handleSaveAccumulation} className="mt-2" noValidate>
                    <div className="grid grid-cols-2 gap-3">
                      <div>
                        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Current age</label>
                        <input
                          type="number" min="0" max="120" step="1" value={accForm.current_age}
                          onChange={(e) => setAccForm((f) => ({ ...f, current_age: e.target.value }))}
                          className="field"
                        />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Retirement age</label>
                        <input
                          type="number" min="0" max="120" step="1" value={accForm.retirement_age}
                          onChange={(e) => setAccForm((f) => ({ ...f, retirement_age: e.target.value }))}
                          className="field"
                        />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Pre-retirement return (%)</label>
                        <input
                          type="number" min="0" max="100" step="any" value={accForm.accumulation_return_rate}
                          onChange={(e) => setAccForm((f) => ({ ...f, accumulation_return_rate: e.target.value }))}
                          className="field"
                        />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Monthly SIP (₹)</label>
                        <input
                          type="number" min="0" step="any" value={accForm.monthly_sip_amount}
                          onChange={(e) => setAccForm((f) => ({ ...f, monthly_sip_amount: e.target.value }))}
                          className="field"
                        />
                      </div>
                      <div className="col-span-2">
                        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Annual SIP step-up (%)</label>
                        <input
                          type="number" min="0" max="100" step="any" value={accForm.sip_step_up_rate}
                          onChange={(e) => setAccForm((f) => ({ ...f, sip_step_up_rate: e.target.value }))}
                          className="field"
                        />
                      </div>
                    </div>

                    {accError && (
                      <p className="mt-3 text-sm" style={{ color: 'var(--color-alert)' }}>{accError}</p>
                    )}

                    <div className="flex gap-2 mt-3">
                      <Button type="submit" size="sm" disabled={accSaving}>
                        {accSaving ? 'Saving…' : 'Save'}
                      </Button>
                      <Button type="button" variant="ghost" size="sm" onClick={() => setEditingAccumulation(false)}>
                        Cancel
                      </Button>
                    </div>
                  </form>
                )}
              </Card>
            )}

            {/* Corpus composition (docs/05 item 3 / docs/06 corpus composition) —
                liquid vs locked split of the starting corpus. Withdrawals draw
                from liquid first, then locked, once both amounts are set. */}
            {isRetirement && (
              <Card className="p-4 mb-4">
                <div className="flex items-center justify-between gap-3 mb-1">
                  <h2 className="text-base font-semibold text-[var(--color-ink)]">Corpus composition</h2>
                  {!editingCorpus && (
                    <Button variant="outline" size="sm" onClick={startEditingCorpus}>
                      {goal.liquid_corpus_amount !== null ? 'Edit' : 'Split corpus'}
                    </Button>
                  )}
                </div>

                {!editingCorpus && goal.liquid_corpus_amount === null && (
                  <p className="text-xs text-[var(--color-ink-2)]">
                    Not set — the full starting corpus is treated as a single liquid pool.
                  </p>
                )}
                {!editingCorpus && goal.liquid_corpus_amount !== null && (
                  <p className="text-xs text-[var(--color-ink-2)]">
                    {formatCurrency(goal.liquid_corpus_amount)} liquid, {formatCurrency(goal.locked_corpus_amount)} locked
                    {goal.locked_return_rate !== null ? ` (locked bucket assumed at ${formatPercent(goal.locked_return_rate)})` : ''} —
                    withdrawals draw from liquid first.
                  </p>
                )}

                {editingCorpus && (
                  <form onSubmit={handleSaveCorpus} className="mt-2" noValidate>
                    <div className="grid grid-cols-2 gap-3">
                      <div>
                        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Liquid corpus (₹)</label>
                        <input
                          type="number" min="0" step="any" value={corpusForm.liquid_corpus_amount}
                          onChange={(e) => setCorpusForm((f) => ({ ...f, liquid_corpus_amount: e.target.value }))}
                          className="field"
                        />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Locked corpus (₹)</label>
                        <input
                          type="number" min="0" step="any" value={corpusForm.locked_corpus_amount}
                          onChange={(e) => setCorpusForm((f) => ({ ...f, locked_corpus_amount: e.target.value }))}
                          className="field"
                        />
                      </div>
                      <div className="col-span-2">
                        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Locked bucket return (%)</label>
                        <input
                          type="number" min="0" max="100" step="any" value={corpusForm.locked_return_rate}
                          onChange={(e) => setCorpusForm((f) => ({ ...f, locked_return_rate: e.target.value }))}
                          className="field"
                        />
                      </div>
                    </div>
                    <p className="mt-1.5 text-[11px] text-[var(--color-ink-3)]">
                      Must sum to the starting corpus ({formatCurrency(goal.initial_net_worth)}). Clear both fields to remove the split.
                    </p>

                    {corpusError && (
                      <p className="mt-3 text-sm" style={{ color: 'var(--color-alert)' }}>{corpusError}</p>
                    )}

                    <div className="flex gap-2 mt-3">
                      <Button type="submit" size="sm" disabled={corpusSaving}>
                        {corpusSaving ? 'Saving…' : 'Save'}
                      </Button>
                      <Button type="button" variant="ghost" size="sm" onClick={() => setEditingCorpus(false)}>
                        Cancel
                      </Button>
                    </div>
                  </form>
                )}
              </Card>
            )}

            {/* Strategy presets — one-click, comparable withdrawal-rate scenarios.
                Neutral illustrations to compare, not advice (see strategyPresets.js). */}
            {presetsForGoalType(goal.goal_type).length > 0 && (
              <Card className="p-4 mb-4">
                <div className="flex items-center justify-between gap-3 mb-1">
                  <h2 className="text-base font-semibold text-[var(--color-ink)]">Compare a strategy preset</h2>
                </div>
                <p className="text-xs text-[var(--color-ink-2)] mb-3">
                  Spin up a ready-made withdrawal-rate scenario to compare on the chart. Each is a starting
                  point to adjust per client — an illustration, not a recommendation.
                </p>
                <div className="flex flex-wrap gap-2">
                  {presetsForGoalType(goal.goal_type).map((p) => (
                    <button
                      key={p.id}
                      type="button"
                      disabled={creating}
                      onClick={() => handleApplyPreset(p)}
                      className="text-left rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] px-3 py-2 transition-colors hover:border-[var(--color-teal)] disabled:opacity-50"
                      style={{ minWidth: '10rem' }}
                    >
                      <div className="text-sm font-semibold text-[var(--color-ink)]">{p.name}</div>
                      <div className="text-[11px] text-[var(--color-ink-3)] mt-0.5">{p.tagline}</div>
                    </button>
                  ))}
                </div>
              </Card>
            )}

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

      {goal && (
        <ApplyTemplateModal
          open={applyModalOpen}
          onClose={() => setApplyModalOpen(false)}
          onApplied={handleTemplateApplied}
          goalId={goal.id}
        />
      )}
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
