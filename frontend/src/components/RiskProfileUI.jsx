// Risk-profiler UI. RiskProfileSummary({clientId}) is the advisor view — latest
// band plus a take-the-questionnaire modal (api.getRiskProfile /
// getRiskQuestionSet / submitRiskProfile). ClientRiskProfileCard is the client's
// own read-only band. The suggested return assumption only surfaces when the
// firm's question set is currently approved (read-time gate).

import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import Modal from './Modal';
import { Card, Badge, Button, Spinner } from './ui';
import { formatPercent, formatDate } from '../lib/format';

// docs/06 Section B — per-client risk profile summary + entry point to
// (re)take the questionnaire. Lives on the client's page (ClientGoals.jsx),
// not per-goal: risk tolerance belongs to the person, not one goal.
export function RiskProfileSummary({ clientId }) {
  const [loading, setLoading] = useState(true);
  const [profile, setProfile] = useState(null);
  const [questionSetExists, setQuestionSetExists] = useState(true);
  const [error, setError] = useState('');
  const [modalOpen, setModalOpen] = useState(false);

  function load() {
    setLoading(true);
    Promise.all([api.getRiskProfile(clientId), api.getRiskQuestionSet()])
      .then(([profileRes, setRes]) => {
        setProfile(profileRes.latest);
        setQuestionSetExists(!!setRes.question_set);
      })
      .catch((err) => setError(err.message || 'Could not load the risk profile.'))
      .finally(() => setLoading(false));
  }

  useEffect(load, [clientId]);

  function handleSubmitted() {
    setModalOpen(false);
    load();
  }

  return (
    <Card className="p-4 mb-4">
      <div className="flex items-center justify-between gap-3 mb-1">
        <h2 className="text-base font-semibold text-[var(--color-ink)]">Risk profile</h2>
        {questionSetExists && (
          <Button variant="outline" size="sm" onClick={() => setModalOpen(true)}>
            {profile ? 'Retake questionnaire' : 'Take questionnaire'}
          </Button>
        )}
      </div>

      {loading && <Spinner label="Loading…" />}
      {error && <p className="text-xs" style={{ color: 'var(--color-alert)' }}>{error}</p>}

      {!loading && !error && !questionSetExists && (
        <p className="text-xs text-[var(--color-ink-2)]">
          This firm hasn't set up a risk questionnaire yet. <Link to="/risk-questionnaire" className="underline">Set one up</Link>.
        </p>
      )}

      {!loading && !error && questionSetExists && !profile && (
        <p className="text-xs text-[var(--color-ink-2)]">No risk profile captured for this client yet.</p>
      )}

      {!loading && !error && profile && (
        <div className="flex items-center gap-3">
          <Badge fg="var(--color-teal-ink)" bg="var(--color-teal-soft)">{profile.band}</Badge>
          <span className="tnum text-sm text-[var(--color-ink-2)]">Score {profile.score}</span>
          {profile.suggested_return_assumption_pct != null ? (
            <span className="text-xs text-[var(--color-ink-2)]">
              Suggested return: <strong className="text-[var(--color-ink)]">{formatPercent(profile.suggested_return_assumption_pct)}</strong>
            </span>
          ) : (
            <span className="text-xs text-[var(--color-ink-3)]">
              (no suggestion — questionnaire not yet approved)
            </span>
          )}
          <span className="text-[11px] text-[var(--color-ink-3)] ml-auto">{formatDate(profile.created_at)}</span>
        </div>
      )}

      <TakeQuestionnaireModal open={modalOpen} onClose={() => setModalOpen(false)} clientId={clientId} onSubmitted={handleSubmitted} />
    </Card>
  );
}

// docs/10 P0-4 — the client's own read-only view of their risk profile, for
// symmetry with the read-only portfolio they can already see (GoalsList.jsx).
// Deliberately narrower than the advisor's RiskProfileSummary: it shows the
// band (their risk category — about them, empowering) but NOT the suggested
// return assumption or raw score. Those are advisor planning inputs; a client
// could misread "suggested return X%" as a promised return, so they stay on
// the advisor side. No capture/retake affordance — a client never self-scores;
// the advisor runs the questionnaire with them. No clientId is passed, so
// risk_profile_read.php forces it to the session's own id server-side.
export function ClientRiskProfileCard() {
  const { tenant } = useAuth();
  // Self-serve individual tier: a user with no adviser must not be told an
  // adviser recorded or will capture anything. Copy only.
  const isSelfDirected = tenant?.kind === 'personal';
  const [loading, setLoading] = useState(true);
  const [profile, setProfile] = useState(null);

  useEffect(() => {
    let cancelled = false;
    api
      .getRiskProfile()
      .then((res) => { if (!cancelled) setProfile(res.latest); })
      .catch(() => { /* soft — an unreadable profile just shows the empty note */ })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  return (
    <Card className="p-4 mb-4">
      <h2 className="text-base font-semibold text-[var(--color-ink)] mb-1">Your risk profile</h2>
      {loading && <Spinner label="Loading…" />}

      {!loading && !profile && (
        <p className="text-xs text-[var(--color-ink-2)]">
          {isSelfDirected
            ? 'You chose a comfort level during setup, and it\'s reflected in your plan\'s return assumption. You can change that on any goal.'
            : 'Your adviser hasn\'t captured your risk profile yet — you\'ll do this together.'}
        </p>
      )}

      {!loading && profile && (
        <div className="flex items-center gap-3 flex-wrap">
          <Badge fg="var(--color-teal-ink)" bg="var(--color-teal-soft)">{profile.band}</Badge>
          <span className="text-xs text-[var(--color-ink-2)] max-w-md">
            How much investment ups-and-downs you're comfortable with, from the questionnaire you completed with your adviser.
          </span>
          {profile.created_at && (
            <span className="text-[11px] text-[var(--color-ink-3)] ml-auto">{formatDate(profile.created_at)}</span>
          )}
        </div>
      )}
    </Card>
  );
}

function TakeQuestionnaireModal({ open, onClose, clientId, onSubmitted }) {
  const [questionSet, setQuestionSet] = useState(null);
  const [answers, setAnswers] = useState({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState(null);

  useEffect(() => {
    if (!open) return;
    setLoading(true);
    setError('');
    setResult(null);
    setAnswers({});
    api
      .getRiskQuestionSet()
      .then((res) => setQuestionSet(res.question_set))
      .catch((err) => setError(err.message || 'Could not load the questionnaire.'))
      .finally(() => setLoading(false));
  }, [open]);

  async function handleSubmit(e) {
    e.preventDefault();
    if (!questionSet) return;
    if (Object.keys(answers).length < questionSet.questions.length) {
      setError('Answer every question before submitting.');
      return;
    }
    setError('');
    setSubmitting(true);
    try {
      const res = await api.submitRiskProfile(clientId, answers);
      setResult(res);
    } catch (err) {
      setError(err.message || 'Could not submit the questionnaire.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} onClose={onClose} title="Risk questionnaire" description={result ? undefined : "Answer each question as the client would."}>
      {loading && <Spinner label="Loading…" />}
      {error && (
        <p className="mb-3 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2" style={{ color: 'var(--color-alert)' }}>
          {error}
        </p>
      )}

      {!loading && !result && questionSet && (
        <form onSubmit={handleSubmit}>
          <div className="space-y-4 max-h-[50vh] overflow-y-auto pr-1">
            {questionSet.questions.map((q) => (
              <div key={q.id}>
                <p className="text-sm font-medium text-[var(--color-ink)] mb-1.5">{q.text}</p>
                <div className="space-y-1.5">
                  {q.options.map((o) => (
                    <label key={String(o.value)} className="flex items-center gap-2 text-sm text-[var(--color-ink-2)] cursor-pointer">
                      <input
                        type="radio"
                        name={q.id}
                        value={o.value}
                        checked={answers[q.id] === String(o.value)}
                        onChange={() => setAnswers((a) => ({ ...a, [q.id]: String(o.value) }))}
                      />
                      {o.value}
                    </label>
                  ))}
                </div>
              </div>
            ))}
          </div>
          <div className="flex gap-2 mt-5">
            <Button type="submit" disabled={submitting} className="flex-1">
              {submitting ? 'Submitting…' : 'Submit'}
            </Button>
            <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          </div>
        </form>
      )}

      {result && (
        <div>
          <div className="flex items-center gap-3 mb-2">
            <Badge fg="var(--color-teal-ink)" bg="var(--color-teal-soft)">{result.band}</Badge>
            <span className="tnum text-sm text-[var(--color-ink-2)]">Score {result.score}</span>
          </div>
          <p className="text-sm text-[var(--color-ink-2)] mb-4">
            {result.suggested_return_assumption_pct != null
              ? <>Suggested return assumption: <strong className="text-[var(--color-ink)]">{formatPercent(result.suggested_return_assumption_pct)}</strong> — informs, doesn't dictate; set the plan's actual number yourself.</>
              : "This questionnaire isn't approved yet, so no suggested return surfaces — the response is still recorded."}
          </p>
          <Button onClick={onSubmitted} className="w-full">Done</Button>
        </div>
      )}
    </Modal>
  );
}
