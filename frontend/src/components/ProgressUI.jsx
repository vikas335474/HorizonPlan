// P1-1 · Shared progress-tracking primitives (docs/10), so the goal page, the
// client roster and the printable report all describe drift identically
// rather than each inventing its own wording and thresholds.
//
// Exports:
//   PROGRESS_LABELS      — the vocabulary, one definition
//   ProgressStatusBadge  — the ahead / on-track / behind pill
//   GoalProgressCard     — the goal page's full card (chart + capture action)
//
// The ±5% dead zone that decides the label lives server-side in
// PlanMath::progressStatus(); nothing here re-derives it. These components
// render the status they are given.

import { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import { Card, Button, Badge } from './ui';
import ProgressChart from './ProgressChart';
import { formatCurrency, formatDate } from '../lib/format';

export const PROGRESS_LABELS = {
  ahead:    { label: 'Ahead of plan', fg: 'var(--color-teal-ink)', bg: 'var(--color-teal-soft)' },
  on_track: { label: 'On track',      fg: 'var(--color-teal-ink)', bg: 'var(--color-teal-soft)' },
  behind:   { label: 'Behind plan',   fg: 'var(--color-amber)',    bg: 'var(--color-amber-soft)' },
};

export function ProgressStatusBadge({ status }) {
  const meta = PROGRESS_LABELS[status];
  if (!meta) return null;
  return <Badge fg={meta.fg} bg={meta.bg}>{meta.label}</Badge>;
}

/**
 * The goal page's progress card.
 *
 * Props: { goalId, canCapture, clientId }
 *   canCapture — advisor only; a client sees the history but cannot record a
 *                new reading (capture is an advisor action, server-enforced).
 *   clientId   — required to capture, since a capture snapshots the whole
 *                client (every goal + net worth), not one goal in isolation.
 */
export function GoalProgressCard({ goalId, canCapture = false, clientId = null }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [capturing, setCapturing] = useState(false);
  const { user, tenant } = useAuth();
  const isClient = user?.role === 'client';
  // Who takes a manual reading depends on tenant kind, not role: in a personal
  // tenant there is no adviser, so the person took it themselves.
  const isSelfDirected = tenant?.kind === 'personal';

  const load = useCallback(() => {
    setLoading(true);
    api
      .getGoalProgress(goalId)
      .then(setData)
      .catch((err) => setError(err.message || 'Could not load progress history.'))
      .finally(() => setLoading(false));
  }, [goalId]);

  useEffect(() => { load(); }, [load]);

  async function handleCapture() {
    setCapturing(true);
    setError('');
    try {
      await api.captureProgress(clientId);
      load();
    } catch (err) {
      setError(err.message || 'Could not record a snapshot.');
    } finally {
      setCapturing(false);
    }
  }

  if (loading) return null;

  // A goal that can't report progress at all (no rates AND no target) simply
  // omits the card rather than showing an empty frame explaining itself.
  if (data && data.tracking_mode === 'none' && (data.points?.length ?? 0) === 0) return null;

  const points = data?.points ?? [];
  const latest = data?.latest;
  const progress = latest?.progress;
  const isCoverage = data?.tracking_mode === 'coverage';

  return (
    <Card className="p-4 mb-4">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-1">
        <div className="min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <h2 className="text-base font-semibold text-[var(--color-ink)]">Progress over time</h2>
            {progress && <ProgressStatusBadge status={progress.status} />}
          </div>
          <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">
            {isCoverage
              ? 'How the share of this goal\'s cost that\'s already funded has moved.'
              : 'What this goal\'s corpus actually was, against what the plan expected.'}
          </p>
        </div>
        {canCapture && clientId && (
          <Button variant="outline" size="sm" onClick={handleCapture} disabled={capturing}>
            {capturing ? 'Recording…' : 'Record now'}
          </Button>
        )}
      </div>

      {error && (
        <p className="mt-2 text-xs" style={{ color: 'var(--color-alert)' }}>{error}</p>
      )}

      {points.length === 0 ? (
        // Empty state carries the reason, because "no data" here is a fact
        // about time (nothing has been recorded yet), not a failure.
        <p className="mt-3 text-sm text-[var(--color-ink-2)]">
          No readings recorded yet. Progress is captured monthly
          {canCapture ? ', or you can record one now before a review.' : ' from now on.'}
        </p>
      ) : points.length === 1 ? (
        <>
          <p className="mt-3 text-sm text-[var(--color-ink-2)]">
            One reading so far, from {formatDate(points[0].as_of_date)}. A trend needs at least two —
            the next monthly snapshot will start the line.
          </p>
          {!isCoverage && latest?.expected_value != null && (
            <DriftSummary latest={latest} progress={progress} isClient={isClient} />
          )}
        </>
      ) : (
        <>
          <div className="mt-3">
            <ProgressChart
              points={points}
              mode={data.tracking_mode}
              manualLabel={isSelfDirected ? 'Recorded by you' : 'Recorded by your adviser'}
            />
          </div>
          {isCoverage ? (
            <p className="mt-2 text-xs text-[var(--color-ink-3)]">
              Coverage rises as the target date nears even when nothing is added, because the
              inflated cost of the goal falls back toward its stated amount. It assumes no growth
              on what's saved.
            </p>
          ) : (
            <DriftSummary latest={latest} progress={progress} isClient={isClient} />
          )}
        </>
      )}
    </Card>
  );
}

function DriftSummary({ latest, progress, isClient }) {
  if (!latest || !progress) return null;
  const behind = progress.status === 'behind';

  return (
    <div className="mt-3 pt-3 border-t border-[var(--color-line)] flex flex-wrap items-baseline justify-between gap-2">
      <span className="text-xs text-[var(--color-ink-2)]">
        As of {formatDate(latest.as_of_date)}, {behind ? 'short of' : 'against'} the plan by{' '}
        <strong className="text-[var(--color-ink)] tabular-nums">
          {formatCurrency(Math.abs(progress.drift))}
        </strong>{' '}
        <span className="tabular-nums">({progress.drift_pct > 0 ? '+' : ''}{progress.drift_pct}%)</span>
      </span>
      {/* An advisor gets the "what now"; a client is not told to go do
          something their adviser owns. */}
      {!isClient && behind && (
        <span className="text-xs text-[var(--color-ink-3)]">
          Worth revisiting the contribution or the assumptions.
        </span>
      )}
    </div>
  );
}
