import { useEffect, useState, useCallback } from 'react';
import { api } from '../lib/api';
import LiveTimelineSlider from './LiveTimelineSlider';
import ResetTriggerControl from './ResetTriggerControl';
import SequenceRiskChart from './SequenceRiskChart';
import DisclosureBanner from './DisclosureBanner';
import { corpusMultiple } from '../lib/format';

// docs/02 4.2: default 3.5%, range ~2.5%-4% — deliberately not the US 4%/25x
// convention (structurally higher Indian inflation pushes the safe rate down).
// A starting point for the advisor/client to adjust, not a fixed rule.
const WITHDRAWAL_RATE_MIN = 2.5;
const WITHDRAWAL_RATE_MAX = 4.0;
const WITHDRAWAL_RATE_DEFAULT = 3.5;

const INFLATION_MIN = 0;
const INFLATION_MAX = 12;

export default function ScenarioPanel({ goal, subScenario, onChanged }) {
  const isRetirement = goal.goal_type === 'retirement';

  const [inflation, setInflation] = useState(subScenario.custom_inflation ?? goal.inflation_rate);
  const [withdrawalRate, setWithdrawalRate] = useState(
    subScenario.custom_withdrawal_rate ?? goal.withdrawal_rate ?? WITHDRAWAL_RATE_DEFAULT
  );
  const [projection, setProjection] = useState(null);
  const [projectionError, setProjectionError] = useState('');
  const [saving, setSaving] = useState(false);
  const [resetting, setResetting] = useState(false);

  const loadProjection = useCallback(() => {
    if (!isRetirement) return;
    setProjectionError('');
    api
      .getProjection(goal.id, subScenario.id)
      .then(setProjection)
      .catch((err) => setProjectionError(err.message || 'Could not load the projection.'));
  }, [goal.id, subScenario.id, isRetirement]);

  useEffect(() => {
    loadProjection();
  }, [loadProjection]);

  async function commitField(field, value, setLocal) {
    setLocal(value);
    setSaving(true);
    try {
      const res = await api.updateSubScenario(subScenario.id, { [field]: value });
      onChanged({ ...subScenario, [field]: value, is_overridden: true });
      if (res?.changed_fields?.length) loadProjection();
    } catch (err) {
      setProjectionError(err.message || 'Save failed — showing the last saved values.');
    } finally {
      setSaving(false);
    }
  }

  async function handleReset() {
    setResetting(true);
    try {
      const res = await api.resetSubScenario(subScenario.id);
      const resetTo = res.reset_to || {};
      setInflation(resetTo.custom_inflation ?? goal.inflation_rate);
      setWithdrawalRate(resetTo.custom_withdrawal_rate ?? goal.withdrawal_rate ?? WITHDRAWAL_RATE_DEFAULT);
      onChanged({ ...subScenario, ...resetTo, is_overridden: false });
      loadProjection();
    } catch (err) {
      setProjectionError(err.message || 'Reset failed.');
    } finally {
      setResetting(false);
    }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between min-h-[28px]">
        <span className="text-xs text-[var(--color-ink-3)]">
          {saving ? 'Saving…' : subScenario.is_overridden ? 'Customized' : 'Following parent goal'}
        </span>
        {subScenario.is_overridden && <ResetTriggerControl onReset={handleReset} resetting={resetting} />}
      </div>

      <div className="rounded-[var(--radius-ctrl)] bg-[var(--color-surface-2)] p-4 space-y-5">
        <LiveTimelineSlider
          label="Inflation assumption"
          value={inflation}
          min={INFLATION_MIN}
          max={INFLATION_MAX}
          step={0.1}
          onCommit={(v) => commitField('custom_inflation', v, setInflation)}
        />

        {isRetirement && (
          <LiveTimelineSlider
            label="Withdrawal rate"
            value={withdrawalRate}
            min={WITHDRAWAL_RATE_MIN}
            max={WITHDRAWAL_RATE_MAX}
            step={0.1}
            onCommit={(v) => commitField('custom_withdrawal_rate', v, setWithdrawalRate)}
            helpText={`≈ ${corpusMultiple(withdrawalRate)}× annual expenses — a starting point to adjust per client, not a fixed rule.`}
          />
        )}
      </div>

      {isRetirement && (
        <>
          {projectionError && (
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>
              {projectionError}
            </p>
          )}

          {projection && (
            <div>
              <div className="flex items-baseline justify-between mb-1">
                <h4 className="text-sm font-semibold text-[var(--color-ink)]">Sequence-of-returns stress test</h4>
              </div>
              <p className="text-xs text-[var(--color-ink-2)] mb-3 leading-relaxed">
                Both lines share the same average return. The dashed line front-loads the weak years —
                showing how much the <em>order</em> of returns matters in drawdown.
              </p>
              <SequenceRiskChart
                steadySeries={projection.steady_return_series}
                adverseSeries={projection.adverse_sequence_series}
              />
            </div>
          )}

          <DisclosureBanner compact />
        </>
      )}
    </div>
  );
}
