import { useEffect, useState, useCallback } from 'react';
import { api } from '../lib/api';
import LiveTimelineSlider from './LiveTimelineSlider';
import ResetTriggerControl from './ResetTriggerControl';
import SequenceRiskChart from './SequenceRiskChart';
import DisclosureBanner from './DisclosureBanner';

// docs/02 4.2: default 3.5%, range ~2.5%-4% — deliberately not the US 4%/25x
// convention (structurally higher Indian inflation pushes the safe rate
// down). This is a starting point for the advisor/client to adjust per
// client, not a fixed rule.
const WITHDRAWAL_RATE_MIN = 2.5;
const WITHDRAWAL_RATE_MAX = 4.0;
const WITHDRAWAL_RATE_DEFAULT = 3.5;

const INFLATION_MIN = 0;
const INFLATION_MAX = 12;

function corpusMultiple(ratePercent) {
  if (!ratePercent || ratePercent <= 0) return null;
  return Math.round((100 / ratePercent) * 100) / 100;
}

export default function ScenarioPanel({ goal, subScenario, onChanged }) {
  const isRetirement = goal.goal_type === 'retirement';

  const [inflation, setInflation] = useState(
    subScenario.custom_inflation ?? goal.inflation_rate
  );
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
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <span className="text-xs text-[var(--color-ink-soft)]">{saving ? 'Saving…' : '\u00A0'}</span>
        {subScenario.is_overridden && (
          <ResetTriggerControl onReset={handleReset} resetting={resetting} />
        )}
      </div>

      <LiveTimelineSlider
        label="Inflation assumption"
        value={inflation}
        min={INFLATION_MIN}
        max={INFLATION_MAX}
        step={0.1}
        onCommit={(v) => commitField('custom_inflation', v, setInflation)}
      />

      {isRetirement && (
        <>
          <LiveTimelineSlider
            label="Withdrawal rate"
            value={withdrawalRate}
            min={WITHDRAWAL_RATE_MIN}
            max={WITHDRAWAL_RATE_MAX}
            step={0.1}
            onCommit={(v) => commitField('custom_withdrawal_rate', v, setWithdrawalRate)}
            helpText={`≈ ${corpusMultiple(withdrawalRate)}× annual expenses — a starting point to adjust per client, not a fixed rule.`}
          />

          <DisclosureBanner />

          {projectionError && (
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>
              {projectionError}
            </p>
          )}

          {projection && (
            <SequenceRiskChart
              steadySeries={projection.steady_return_series}
              adverseSeries={projection.adverse_sequence_series}
            />
          )}
        </>
      )}
    </div>
  );
}
