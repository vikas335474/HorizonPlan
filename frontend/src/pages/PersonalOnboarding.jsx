// Route /start — the guided setup for a self-serve individual (sql/033).
//
// Turns a handful of plain questions into a real plan: a few life details, a
// risk choice, then a review screen that creates the goals via api.createGoal.
// Only reachable by a personal tenant; an advisor-managed client or an advisor
// is redirected home, since neither has anything to set up here.
//
// Language rule for this whole surface: no jargon on screen. "How long your
// money lasts", not "decumulation". "What you've saved", not "corpus". The
// underlying fields are unchanged — this is vocabulary, not a second model.
//
// The life answers (children, home) are used only to decide WHICH goals to
// suggest and are then discarded — nothing about them is sent to the server.
// See lib/personalPlanner.js.

import { useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import AppHeader from '../components/AppHeader';
import DisclosureBanner from '../components/DisclosureBanner';
import { Card, Button } from '../components/ui';
import { formatCurrency } from '../lib/format';
import {
  PLANNER_QUESTIONS,
  PERSONAL_RISK_BANDS,
  suggestGoals,
  retirementCountdown,
} from '../lib/personalPlanner';

export default function PersonalOnboarding() {
  const { user, tenant, refreshSession } = useAuth();
  const navigate = useNavigate();
  const [step, setStep] = useState(0);
  const [answers, setAnswers] = useState({});
  const [riskId, setRiskId] = useState('balanced');
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState('');

  // Personal tenants only. Everyone else has an advisor (or is one).
  if (tenant && tenant.kind !== 'personal') return <Navigate to="/" replace />;
  if (user && user.role !== 'client') return <Navigate to="/" replace />;

  const totalSteps = PLANNER_QUESTIONS.length + 2; // questions + risk + review
  const isRiskStep = step === PLANNER_QUESTIONS.length;
  const isReview = step === PLANNER_QUESTIONS.length + 1;
  const q = PLANNER_QUESTIONS[step];

  const suggested = isReview || isRiskStep ? suggestGoals(answers) : [];
  const band = PERSONAL_RISK_BANDS.find((b) => b.id === riskId);
  const countdown = retirementCountdown(Number(answers.age), Number(answers.retire_age));

  function setAnswer(id, value) {
    setAnswers((a) => ({ ...a, [id]: value }));
  }

  // A question is answerable-and-answered before we let them move on. Numbers
  // must be real numbers; the choice questions always have a value once picked.
  function canAdvance() {
    if (isRiskStep || isReview) return true;
    const v = answers[q.id];
    if (v === undefined || v === '') return false;
    if (q.type === 'number' || q.type === 'currency') return !Number.isNaN(Number(v));
    return true;
  }

  async function handleCreate() {
    setCreating(true);
    setError('');
    try {
      // Created in order, sequentially rather than in parallel: if one fails
      // we want the ones before it to have succeeded and the error to point at
      // the actual culprit, not a racing pile of rejections.
      for (const g of suggested) {
        const fields = { ...g.fields };
        // The risk choice supplies the return assumption for the retirement
        // goal — the one field the questions themselves can't answer.
        if (fields.goal_type === 'retirement') {
          fields.drawdown_return_rate = band.illustrativeReturn;
          fields.accumulation_return_rate = band.illustrativeReturn;
        }
        // client_id is ignored by the server for a personal session (it forces
        // the caller's own id), but sending it keeps the payload honest.
        await api.createGoal({ ...fields, client_id: user.userId });
      }
      // docs/10 P1-4 — the one answer from this Q&A that is kept. Everything
      // else here is used transiently to pick goals and then discarded; this
      // is stored because the foundations check cannot tell "no dependants"
      // (life cover not needed) from "not asked" (life cover unknown) without
      // it, and those two must not render the same way.
      if (answers.dependants !== undefined && answers.dependants !== '') {
        try {
          await api.saveFoundations({
            client_id: user.userId,
            dependants_count: Number(answers.dependants),
          });
        } catch {
          // Non-fatal: the plan itself is created, and the foundations card
          // will simply ask again. Failing the whole onboarding here would
          // lose goals that already saved successfully.
        }
      }
      await refreshSession();
      navigate('/goals');
    } catch (err) {
      setError(err.message || 'Could not create your plan. Please try again.');
    } finally {
      setCreating(false);
    }
  }

  return (
    <div className="min-h-screen">
      <AppHeader />

      <main className="mx-auto max-w-2xl px-5 py-8">
        {/* Progress — a plain "3 of 8", not a decorative bar with no number. */}
        <div className="mb-5 flex items-center gap-3">
          <div className="h-1.5 flex-1 rounded-full bg-[var(--color-line)] overflow-hidden">
            <div
              className="h-full rounded-full bg-[var(--color-teal)] transition-all duration-300"
              style={{ width: `${((step + 1) / totalSteps) * 100}%` }}
            />
          </div>
          <span className="text-xs tabular-nums text-[var(--color-ink-3)]">
            {step + 1} of {totalSteps}
          </span>
        </div>

        {/* --- the questions --- */}
        {!isRiskStep && !isReview && (
          <Card className="p-6">
            <h1 className="text-xl font-semibold text-[var(--color-ink)]">{q.question}</h1>
            <p className="mt-1.5 text-sm text-[var(--color-ink-2)]">{q.help}</p>

            <div className="mt-5">
              {q.type === 'choice' ? (
                <div className="flex flex-col gap-2">
                  {q.options.map((o) => (
                    <button
                      key={o.value}
                      onClick={() => { setAnswer(q.id, o.value); setStep((s) => s + 1); }}
                      className={`rounded-lg border px-4 py-3 text-left text-sm transition-colors ${
                        answers[q.id] === o.value
                          ? 'border-[var(--color-teal)] bg-[var(--color-teal-soft)] text-[var(--color-teal-ink)]'
                          : 'border-[var(--color-line-2)] hover:bg-[var(--color-surface-2)]'
                      }`}
                    >
                      {o.label}
                    </button>
                  ))}
                </div>
              ) : (
                <>
                  <div className="flex items-center gap-2">
                    {q.type === 'currency' && (
                      <span className="text-lg text-[var(--color-ink-3)]">₹</span>
                    )}
                    <input
                      type="number"
                      inputMode="numeric"
                      autoFocus
                      value={answers[q.id] ?? ''}
                      min={q.min}
                      max={q.max}
                      placeholder={q.placeholder}
                      onChange={(e) => setAnswer(q.id, e.target.value)}
                      onKeyDown={(e) => { if (e.key === 'Enter' && canAdvance()) setStep((s) => s + 1); }}
                      className="field w-full text-lg"
                    />
                  </div>
                  {q.type === 'currency' && answers[q.id] !== undefined && answers[q.id] !== '' && (
                    // Echo the number back in words-as-figures: Indian
                    // digit grouping makes a mistyped extra zero obvious.
                    <p className="mt-2 text-sm text-[var(--color-ink-2)] tabular-nums">
                      {formatCurrency(Number(answers[q.id]))}
                    </p>
                  )}
                </>
              )}
            </div>

            <div className="mt-6 flex items-center justify-between">
              <button
                onClick={() => setStep((s) => Math.max(0, s - 1))}
                disabled={step === 0}
                className="text-sm text-[var(--color-ink-2)] disabled:opacity-40"
              >
                ← Back
              </button>
              <Button onClick={() => setStep((s) => s + 1)} disabled={!canAdvance()}>
                Continue
              </Button>
            </div>
          </Card>
        )}

        {/* --- risk choice --- */}
        {isRiskStep && (
          <Card className="p-6">
            <h1 className="text-xl font-semibold text-[var(--color-ink)]">
              How much bumpiness are you comfortable with?
            </h1>
            <p className="mt-1.5 text-sm text-[var(--color-ink-2)]">
              Investments that grow more also fall further along the way. Pick what feels right —
              you can change it whenever you like.
            </p>

            <div className="mt-5 flex flex-col gap-2">
              {PERSONAL_RISK_BANDS.map((b) => (
                <button
                  key={b.id}
                  onClick={() => setRiskId(b.id)}
                  className={`rounded-lg border px-4 py-3 text-left transition-colors ${
                    riskId === b.id
                      ? 'border-[var(--color-teal)] bg-[var(--color-teal-soft)]'
                      : 'border-[var(--color-line-2)] hover:bg-[var(--color-surface-2)]'
                  }`}
                >
                  <div className="flex items-baseline justify-between gap-3">
                    <span className="text-sm font-semibold text-[var(--color-ink)]">{b.label}</span>
                    <span className="text-xs tabular-nums text-[var(--color-ink-2)]">
                      illustrates {b.illustrativeReturn}% a year
                    </span>
                  </div>
                  <p className="mt-1 text-xs text-[var(--color-ink-2)]">{b.blurb}</p>
                </button>
              ))}
            </div>

            {/* The honesty note. These rates are reference points to compare,
                not predictions, and this says so before the person leans on
                them — the same framing the advisor product uses for its
                withdrawal-rate presets. */}
            <p className="mt-4 text-xs leading-relaxed text-[var(--color-ink-3)]">
              These percentages are long-run reference points used to draw the chart — not a
              forecast, and not a recommendation. Real returns vary year to year, and some years
              are negative. You can type your own figure on the plan afterwards.
            </p>

            <div className="mt-6 flex items-center justify-between">
              <button onClick={() => setStep((s) => s - 1)} className="text-sm text-[var(--color-ink-2)]">← Back</button>
              <Button onClick={() => setStep((s) => s + 1)}>Continue</Button>
            </div>
          </Card>
        )}

        {/* --- review --- */}
        {isReview && (
          <>
            <Card className="p-6">
              <h1 className="text-xl font-semibold text-[var(--color-ink)]">Here's your starting plan</h1>
              <p className="mt-1.5 text-sm text-[var(--color-ink-2)]">
                Based on what you told us. Nothing is fixed — you can change every number, add
                goals, or remove them once you're in.
              </p>

              {countdown && !countdown.reached && (
                <div
                  className="mt-4 rounded-lg px-4 py-3"
                  style={{ backgroundColor: 'var(--color-teal-soft)' }}
                >
                  <div className="text-xs uppercase tracking-wide text-[var(--color-teal-ink)]">
                    Time until you stop working
                  </div>
                  <div className="mt-0.5 text-2xl font-semibold tabular-nums text-[var(--color-ink)]">
                    {countdown.years} years{countdown.months ? `, ${countdown.months} months` : ''}
                  </div>
                  <div className="mt-0.5 text-xs text-[var(--color-ink-2)] tabular-nums">
                    that's {countdown.totalMonths} months of saving ahead of you
                  </div>
                </div>
              )}

              <ul className="mt-4 flex flex-col gap-2">
                {suggested.map((g) => (
                  <li key={g.key} className="rounded-lg border border-[var(--color-line)] p-3.5">
                    <div className="text-sm font-semibold text-[var(--color-ink)]">{g.title}</div>
                    <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">{g.why}</p>
                    <div className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-[var(--color-ink-2)]">
                      {g.fields.initial_net_worth > 0 && (
                        <span>Starting with <strong className="text-[var(--color-ink)] tabular-nums">{formatCurrency(g.fields.initial_net_worth)}</strong></span>
                      )}
                      {g.fields.monthly_sip_amount > 0 && (
                        <span>Adding <strong className="text-[var(--color-ink)] tabular-nums">{formatCurrency(g.fields.monthly_sip_amount)}</strong>/month</span>
                      )}
                      {g.fields.target_amount && (
                        <span>Target <strong className="text-[var(--color-ink)] tabular-nums">{formatCurrency(g.fields.target_amount)}</strong></span>
                      )}
                    </div>
                  </li>
                ))}
              </ul>

              {error && <p className="mt-3 text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>}

              <div className="mt-6 flex items-center justify-between">
                <button onClick={() => setStep((s) => s - 1)} className="text-sm text-[var(--color-ink-2)]">← Back</button>
                <Button onClick={handleCreate} disabled={creating}>
                  {creating ? 'Building your plan…' : 'Create my plan'}
                </Button>
              </div>
            </Card>

            <div className="mt-4">
              <DisclosureBanner />
            </div>
          </>
        )}
      </main>
    </div>
  );
}
