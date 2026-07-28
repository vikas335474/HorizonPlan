// Jr -> Sr plan-approval UI primitives: ReviewStatusBadge, GoalReviewCard (an
// advisor submits, an sr_advisor/firm_admin approves or requests changes — via
// api.submitGoalForReview / reviewGoal) and ReviewQueueRow. Role-gated: only
// sr_advisor / firm_admin see the approve / request-changes controls, and a
// client session never sees any review UI at all. useAuth.

import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import { Card, Badge, Button } from './ui';

// Jr -> Sr Advisor Plan-Approval Workflow. Internal (advisor-facing) only —
// never rendered for a client session, even though GoalDetail.jsx's route is
// shared by both roles. No hard gate anywhere (decision #2): this is a
// visible signal + audit trail, nothing here blocks PlanReport/Meeting Mode.

const STATUS_STYLES = {
  pending_review: { fg: '#b45309', bg: '#fef3c7', label: 'Pending review' },
  approved: { fg: '#0f766e', bg: '#ccfbf1', label: 'Approved' },
  changes_requested: { fg: '#b91c1c', bg: '#fee2e2', label: 'Changes requested' },
};

export function ReviewStatusBadge({ status }) {
  const style = STATUS_STYLES[status];
  if (!style) return null; // 'not_required' (or unknown) — nothing to show
  return <Badge fg={style.fg} bg={style.bg}>{style.label}</Badge>;
}

// Mirrors requireFirmRole()'s exact NULL-as-sr_advisor rule (docs/09 Piece 2)
// so the button can't drift from what the server actually allows.
function canReviewPlans(user) {
  if (!user) return false;
  if (user.role === 'super_admin') return true;
  if (user.role !== 'advisor') return false;
  return user.firmRole == null || user.firmRole === 'sr_advisor' || user.firmRole === 'firm_admin';
}

function formatTimestamp(ts) {
  if (!ts) return '';
  try {
    return new Date(ts.replace(' ', 'T') + 'Z').toLocaleString(undefined, {
      dateStyle: 'medium',
      timeStyle: 'short',
    });
  } catch {
    return ts;
  }
}

// Goal-level card for GoalDetail.jsx: current status, a submit/resubmit
// action for the goal's own advisor, and approve/request-changes actions for
// a sr_advisor/firm_admin session when the goal is pending_review.
export function GoalReviewCard({ goal, onChanged }) {
  const { user, tenant } = useAuth();
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [reviewing, setReviewing] = useState(false);
  const [notes, setNotes] = useState('');
  const [actionError, setActionError] = useState('');
  const [actionBusy, setActionBusy] = useState(false);

  if (user?.role === 'client') return null; // client-facing views never show internal review state
  if (!tenant?.requiresPlanReview && goal.review_status === 'not_required') return null;

  const status = goal.review_status;
  const showSubmit = tenant?.requiresPlanReview && (status === 'not_required' || status === 'changes_requested');
  const showReviewActions = canReviewPlans(user) && status === 'pending_review';

  async function handleSubmit() {
    setSubmitting(true);
    setSubmitError('');
    try {
      await api.submitGoalForReview(goal.id);
      await onChanged();
    } catch (err) {
      setSubmitError(err.message || 'Could not submit for review.');
    } finally {
      setSubmitting(false);
    }
  }

  async function handleApprove() {
    setActionBusy(true);
    setActionError('');
    try {
      await api.reviewGoal(goal.id, 'approve');
      await onChanged();
    } catch (err) {
      setActionError(err.message || 'Could not approve this goal.');
    } finally {
      setActionBusy(false);
    }
  }

  async function handleRequestChanges(e) {
    e.preventDefault();
    if (!notes.trim()) {
      setActionError('Notes are required when requesting changes.');
      return;
    }
    setActionBusy(true);
    setActionError('');
    try {
      await api.reviewGoal(goal.id, 'request_changes', notes.trim());
      setNotes('');
      setReviewing(false);
      await onChanged();
    } catch (err) {
      setActionError(err.message || 'Could not submit feedback.');
    } finally {
      setActionBusy(false);
    }
  }

  return (
    <Card className="p-4 mb-4">
      <div className="flex items-center justify-between gap-3 mb-1">
        <h2 className="text-base font-semibold text-[var(--color-ink)]">Plan review</h2>
        <ReviewStatusBadge status={status} />
      </div>

      {status === 'changes_requested' && goal.review_notes && (
        <p className="text-xs mt-2 p-2 rounded-[var(--radius-ctrl)]" style={{ background: '#fee2e2', color: '#b91c1c' }}>
          {goal.review_notes}
        </p>
      )}

      {status === 'approved' && (
        <p className="text-xs text-[var(--color-ink-2)] mt-2">
          Approved{goal.reviewed_at ? ` on ${formatTimestamp(goal.reviewed_at)}` : ''}.
        </p>
      )}

      {status === 'pending_review' && !showReviewActions && (
        <p className="text-xs text-[var(--color-ink-2)] mt-2">Waiting on a senior advisor or firm admin to review.</p>
      )}

      {showSubmit && (
        <div className="mt-2">
          <Button variant="outline" size="sm" disabled={submitting} onClick={handleSubmit}>
            {submitting ? 'Submitting…' : status === 'changes_requested' ? 'Resubmit for review' : 'Submit for review'}
          </Button>
        </div>
      )}
      {submitError && <p className="mt-2 text-sm" style={{ color: 'var(--color-alert)' }}>{submitError}</p>}

      {showReviewActions && (
        <div className="mt-3 pt-3 border-t border-[var(--color-line)]">
          {!reviewing ? (
            <div className="flex gap-2">
              <Button size="sm" disabled={actionBusy} onClick={handleApprove}>Approve</Button>
              <Button variant="outline" size="sm" onClick={() => setReviewing(true)}>Request changes</Button>
            </div>
          ) : (
            <form onSubmit={handleRequestChanges} noValidate>
              <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">What needs to change?</label>
              <textarea
                className="field"
                rows={3}
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
              />
              <div className="flex gap-2 mt-2">
                <Button type="submit" size="sm" disabled={actionBusy}>{actionBusy ? 'Sending…' : 'Send feedback'}</Button>
                <Button type="button" variant="ghost" size="sm" onClick={() => { setReviewing(false); setNotes(''); setActionError(''); }}>
                  Cancel
                </Button>
              </div>
            </form>
          )}
          {actionError && <p className="mt-2 text-sm" style={{ color: 'var(--color-alert)' }}>{actionError}</p>}
        </div>
      )}
    </Card>
  );
}

// Firm-wide queue row — used by PlanReviewQueue.jsx. Inline approve/request
// changes for a sr_advisor/firm_admin session; read-only for anyone else
// (e.g. a jr_advisor checking the status of their own submissions).
export function ReviewQueueRow({ item, onChanged }) {
  const { user } = useAuth();
  const [reviewing, setReviewing] = useState(false);
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const showActions = canReviewPlans(user) && item.review_status === 'pending_review';

  async function handleApprove() {
    setBusy(true);
    setError('');
    try {
      await api.reviewGoal(item.id, 'approve');
      await onChanged();
    } catch (err) {
      setError(err.message || 'Could not approve this goal.');
    } finally {
      setBusy(false);
    }
  }

  async function handleRequestChanges(e) {
    e.preventDefault();
    if (!notes.trim()) {
      setError('Notes are required when requesting changes.');
      return;
    }
    setBusy(true);
    setError('');
    try {
      await api.reviewGoal(item.id, 'request_changes', notes.trim());
      setNotes('');
      setReviewing(false);
      await onChanged();
    } catch (err) {
      setError(err.message || 'Could not submit feedback.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="py-3 border-b border-[var(--color-line)] last:border-b-0">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <Link to={`/goals/${item.id}`} className="text-sm font-medium text-[var(--color-ink)] hover:underline truncate">
            {item.goal_label}
          </Link>
          <div className="text-xs text-[var(--color-ink-3)] mt-0.5">
            {item.client_email || `client #${item.client_id}`}
            {item.reviewed_by_email && (item.review_status === 'approved' || item.review_status === 'changes_requested') && (
              <> · reviewed by {item.reviewed_by_email}{item.reviewed_at ? ` on ${formatTimestamp(item.reviewed_at)}` : ''}</>
            )}
          </div>
          {item.review_status === 'changes_requested' && item.review_notes && (
            <p className="text-xs mt-1 p-1.5 rounded-[var(--radius-ctrl)]" style={{ background: '#fee2e2', color: '#b91c1c' }}>
              {item.review_notes}
            </p>
          )}
        </div>
        <ReviewStatusBadge status={item.review_status} />
      </div>

      {showActions && (
        <div className="mt-2">
          {!reviewing ? (
            <div className="flex gap-2">
              <Button size="sm" disabled={busy} onClick={handleApprove}>Approve</Button>
              <Button variant="outline" size="sm" onClick={() => setReviewing(true)}>Request changes</Button>
            </div>
          ) : (
            <form onSubmit={handleRequestChanges} noValidate>
              <textarea
                className="field"
                rows={2}
                placeholder="What needs to change?"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
              />
              <div className="flex gap-2 mt-2">
                <Button type="submit" size="sm" disabled={busy}>{busy ? 'Sending…' : 'Send feedback'}</Button>
                <Button type="button" variant="ghost" size="sm" onClick={() => { setReviewing(false); setNotes(''); setError(''); }}>
                  Cancel
                </Button>
              </div>
            </form>
          )}
          {error && <p className="mt-2 text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>}
        </div>
      )}
    </div>
  );
}
