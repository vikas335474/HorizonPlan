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

import { useState, useEffect } from 'react';
import { Navigate, useNavigate, Link } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import AppHeader from '../components/AppHeader';
import DisclosureBanner from '../components/DisclosureBanner';
import { leverLine } from '../components/RetirementTargetCard';
import DigestPreference from '../components/DigestPreference';
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
  // docs/13 I-7 — starts unset, NOT pre-filled with 'balanced'. A pre-selected
  // band let someone press Continue without ever choosing and walk away with a
  // 9% return assumption they never consented to, while every other question
  // in this wizard blocks until answered. The return assumption is the single
  // most consequential number in the plan; it should be the least defaulted.
  const [riskId, setRiskId] = useState(null);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState('');
  // docs/13 I-4 — set once the plan exists; switches this page to the proof
  // screen rather than navigating away to the card list.
  const [createdGoalId, setCreatedGoalId] = useState(null);

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
    // docs/13 I-7 — the risk step is gated on an explicit choice, exactly like
    // every other question. Nothing is pre-selected, so Continue stays
    // disabled until the person picks a band.
    if (isRiskStep) return riskId !== null;
    if (isReview) return true;
    const v = answers[q.id];
    if (v === undefined || v === '') return false;
    if (q.type === 'number' || q.type === 'currency') {
      const n = Number(v);
      if (Number.isNaN(n)) return false;
      // docs/13 I-8 — the range is now enforced here rather than relying on a
      // number input's min/max, which never blocked typing anyway: an age of
      // 200 used to sail through and produce a nonsense plan.
      if (q.min !== undefined && n < q.min) return false;
      if (q.max !== undefined && n > q.max) return false;
    }
    return true;
  }

  // Why Continue is disabled, in the person's own terms. Silence on a disabled
  // button is its own accessibility failure — it looks broken rather than
  // waiting for something.
  function advanceHint() {
    if (isRiskStep) return riskId === null ? 'Pick one to continue.' : '';
    if (isReview || canAdvance()) return '';
    const v = answers[q.id];
    if (v === undefined || v === '') return '';
    if (q.type === 'number' || q.type === 'currency') {
      const n = Number(v);
      if (Number.isNaN(n)) return 'Enter a number.';
      if (q.min !== undefined && n < q.min) return `Enter ${q.min} or more.`;
      if (q.max !== undefined && n > q.max) return `Enter ${q.max} or less.`;
    }
    return '';
  }

  async function handleCreate() {
    setCreating(true);
    setError('');
    let newRetirementGoalId = null;
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
        const created = await api.createGoal({ ...fields, client_id: user.userId });
        // Keep the retirement goal's id — it is what the proof screen shows.
        // goals_create.php responds {status, goal_id}.
        if (fields.goal_type === 'retirement' && created?.goal_id) {
          newRetirementGoalId = Number(created.goal_id);
        }
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
      api.logEvent('plan_created');
      // docs/13 I-4 — end on the proof, not on the card list. This used to
      // navigate straight to /goals, which meant the moment the person had
      // just worked for (their own number, their own countdown, and what
      // closes the gap) sat behind another click on a screen full of empty
      // forms. The proof screen renders here instead, and /goals is one
      // deliberate button away.
      if (newRetirementGoalId !== null) {
        setCreatedGoalId(newRetirementGoalId);
      } else {
        navigate('/goals');
      }
    } catch (err) {
      setError(err.message || 'Could not create your plan. Please try again.');
    } finally {
      setCreating(false);
    }
  }

  // docs/13 I-4 — the proof screen. The person has just answered nine
  // questions; this is where they see it was worth it, before anything asks
  // them for more.
  if (createdGoalId !== null) {
    return (
      <PlanCreated
        goalId={createdGoalId}
        countdown={countdown}
        // docs/13 I-11 — only offered when the person has just told us someone
        // depends on their income. Asking everyone would be noise; asking the
        // people who said "yes, my partner" is answering a question they have
        // already half-raised.
        offerPartner={Number(answers.dependants || 0) > 0}
        onContinue={() => navigate('/goals')}
      />
    );
  }

  return (
    <div className="min-h-screen">
      <AppHeader />

      <main className="mx-auto max-w-2xl px-5 py-8">
        {/* Progress — a plain "3 of 9", not a decorative bar with no number.
            docs/13 I-8: the bar itself is decorative (aria-hidden); the step
            count carries the meaning and is announced politely on change, so a
            screen-reader user hears "3 of 9" when the step advances instead of
            silently landing on a new question. */}
        <div className="mb-5 flex items-center gap-3">
          <div
            className="h-1.5 flex-1 rounded-full bg-[var(--color-line)] overflow-hidden"
            aria-hidden="true"
          >
            <div
              className="h-full rounded-full bg-[var(--color-teal)] transition-all duration-300"
              style={{ width: `${((step + 1) / totalSteps) * 100}%` }}
            />
          </div>
          <span
            className="text-xs tabular-nums text-[var(--color-ink-3)]"
            role="status"
            aria-live="polite"
          >
            {step + 1} of {totalSteps}
          </span>
        </div>

        {/* --- the questions --- */}
        {!isRiskStep && !isReview && (
          <Card className="p-6">
            <h1 className="text-xl font-semibold text-[var(--color-ink)]" id="wizard-question">
              {q.question}
            </h1>
            <p className="mt-1.5 text-sm text-[var(--color-ink-2)]" id="wizard-help">{q.help}</p>

            <div className="mt-5">
              {q.type === 'choice' ? (
                // docs/13 I-8 — a real radio group. This used to be a row of
                // plain buttons that advanced the step inside onClick: a
                // mis-click was committed instantly with no way to see what
                // had been chosen, and the screen changed under a
                // screen-reader user with nothing announced. Now selecting is
                // separate from advancing, and Continue is the only thing that
                // moves the wizard — same as every other step.
                <div
                  role="radiogroup"
                  aria-labelledby="wizard-question"
                  aria-describedby="wizard-help"
                  className="flex flex-col gap-2"
                >
                  {q.options.map((o) => (
                    <button
                      key={o.value}
                      type="button"
                      role="radio"
                      aria-checked={answers[q.id] === o.value}
                      onClick={() => setAnswer(q.id, o.value)}
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
                      <span className="text-lg text-[var(--color-ink-3)]" aria-hidden="true">₹</span>
                    )}
                    {/* docs/13 I-8 — type="text" with inputMode="numeric"
                        rather than type="number". A number input silently
                        changes its value on a stray scroll wheel over a
                        focused field, which on a currency question means a
                        wrong plan with no visible cause. The pattern still
                        brings up the numeric keypad on mobile. */}
                    <input
                      type="text"
                      inputMode="numeric"
                      pattern="[0-9]*"
                      autoFocus
                      aria-labelledby="wizard-question"
                      aria-describedby="wizard-help"
                      value={answers[q.id] ?? ''}
                      placeholder={q.placeholder}
                      onChange={(e) => setAnswer(q.id, e.target.value.replace(/[^0-9]/g, ''))}
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

            {advanceHint() && (
              <p className="mt-3 text-xs text-[var(--color-ink-2)]" role="status" aria-live="polite">
                {advanceHint()}
              </p>
            )}

            <div className="mt-6 flex items-center justify-between">
              <button
                type="button"
                onClick={() => setStep((s) => Math.max(0, s - 1))}
                disabled={step === 0}
                className="text-sm text-[var(--color-ink-2)] disabled:opacity-40"
              >
                ← Back
              </button>
              <Button
                onClick={() => {
                  // docs/13 §5 question 1 — which question loses people.
                  // Records the STEP INDEX only, never the answer: knowing
                  // someone stalled on question 3 is a product fact; knowing
                  // what they earn is not, and does not belong in an event log.
                  api.logEvent('onboarding_question_answered', { step, screen: 'personal_onboarding' });
                  setStep((s) => s + 1);
                }}
                disabled={!canAdvance()}
              >
                Continue
              </Button>
            </div>
          </Card>
        )}

        {/* --- risk choice --- */}
        {isRiskStep && (
          <Card className="p-6">
            <h1 className="text-xl font-semibold text-[var(--color-ink)]" id="risk-question">
              How much bumpiness are you comfortable with?
            </h1>
            <p className="mt-1.5 text-sm text-[var(--color-ink-2)]" id="risk-help">
              Investments that grow more also fall further along the way. Pick what feels right —
              you can change it whenever you like.
            </p>

            <div
              role="radiogroup"
              aria-labelledby="risk-question"
              aria-describedby="risk-help"
              className="mt-5 flex flex-col gap-2"
            >
              {PERSONAL_RISK_BANDS.map((b) => (
                <button
                  key={b.id}
                  type="button"
                  role="radio"
                  aria-checked={riskId === b.id}
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

            {advanceHint() && (
              <p className="mt-3 text-xs text-[var(--color-ink-2)]" role="status" aria-live="polite">
                {advanceHint()}
              </p>
            )}

            <div className="mt-6 flex items-center justify-between">
              <button type="button" onClick={() => setStep((s) => s - 1)} className="text-sm text-[var(--color-ink-2)]">← Back</button>
              <Button
                onClick={() => {
                  api.logEvent('onboarding_risk_chosen');
                  setStep((s) => s + 1);
                }}
                disabled={!canAdvance()}
              >
                Continue
              </Button>
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

// docs/13 I-4 · The proof screen — the last step of onboarding, and the first
// time the person sees their own plan rather than their own answers.
//
// WHY THIS IS A SCREEN AND NOT A REDIRECT. Onboarding used to navigate
// straight to /goals the moment the goals were created. That put the payoff —
// their number, their countdown, and what closes the gap — behind another
// click, on a page that (before I-1) opened with eight empty forms. The person
// did the work and was handed a chore list.
//
// It fetches the real projection rather than restating the wizard's own
// inputs: the point is to show what the app COMPUTED, not to echo back what
// was just typed. Same endpoint the goal page uses, so the two can never
// disagree.
function PlanCreated({ goalId, countdown, offerPartner = false, onContinue }) {
  const [projection, setProjection] = useState(null);
  const [failed, setFailed] = useState(false);
  // docs/13 I-11 — the partner invite, offered here rather than left as the
  // seventh card on the home screen. Three states: not asked, sending, sent.
  const [partnerEmail, setPartnerEmail] = useState('');
  const [partnerState, setPartnerState] = useState('idle');
  const [partnerError, setPartnerError] = useState('');

  async function handleInvite(e) {
    e.preventDefault();
    if (!partnerEmail.trim()) return;
    setPartnerState('sending');
    setPartnerError('');
    try {
      await api.invitePartner(partnerEmail.trim());
      api.logEvent('partner_invited', { screen: 'plan_created' });
      setPartnerState('sent');
    } catch (err) {
      setPartnerError(err.message || 'Could not send that invitation.');
      setPartnerState('idle');
    }
  }

  useEffect(() => {
    let cancelled = false;
    api
      .getProjection(goalId)
      .then((res) => { if (!cancelled) setProjection(res); })
      .catch(() => { if (!cancelled) setFailed(true); });
    return () => { cancelled = true; };
  }, [goalId]);

  const target = projection?.retirement_target ?? null;
  const lever = leverLine(projection?.gap_closing_levers ?? null, true);

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-2xl px-5 py-8">
        <Card className="p-6">
          <h1 className="text-xl font-semibold text-[var(--color-ink)]">Your plan is ready</h1>
          <p className="mt-1.5 text-sm text-[var(--color-ink-2)]">
            Here's what it says today. Every number in it is yours to change.
          </p>

          {countdown && !countdown.reached && (
            <div className="mt-4 rounded-lg px-4 py-3" style={{ backgroundColor: 'var(--color-teal-soft)' }}>
              <div className="text-xs uppercase tracking-wide text-[var(--color-teal-ink)]">
                Time until you stop working
              </div>
              <div className="mt-0.5 text-2xl font-semibold tabular-nums text-[var(--color-ink)]">
                {countdown.years} years{countdown.months ? `, ${countdown.months} months` : ''}
              </div>
            </div>
          )}

          {/* The honest part. If there is a gap, say so and immediately say
              what closes it — docs/11 E-1's "never pair a gap with silence".
              If there isn't, say that plainly too rather than inventing a
              worry to justify the screen. */}
          {target && (
            <div className="mt-3 rounded-lg px-4 py-3" style={{
              backgroundColor: target.is_met ? 'var(--color-teal-soft)' : 'var(--color-amber-soft)',
            }}>
              <div className="text-xs uppercase tracking-wide text-[var(--color-ink-3)]">
                {target.is_met ? 'Where you are' : 'Worth knowing'}
              </div>
              <p className="mt-1 text-sm leading-relaxed text-[var(--color-ink)]">
                {target.is_met
                  ? "On what you've told us, this plan reaches its number."
                  : lever || "There's a shortfall on these numbers. Open the plan to see what moves it."}
              </p>
            </div>
          )}

          {failed && (
            <p className="mt-3 text-sm text-[var(--color-ink-2)]">
              Your plan was created. We couldn't draw the summary just now — open it to see the detail.
            </p>
          )}

          {/* docs/13 I-9 — offered here because this is the moment it makes
              sense: the person has just built the thing the summary would be
              about. Unticked, like everywhere else. */}
          <div className="mt-5 border-t border-[var(--color-line)] pt-4">
            <DigestPreference compact />
          </div>

          <div className="mt-6 flex flex-wrap items-center gap-3">
            <Link to={`/goals/${goalId}`}>
              <Button>See my plan</Button>
            </Link>
            <button
              type="button"
              onClick={onContinue}
              className="text-sm text-[var(--color-ink-2)] hover:underline"
            >
              All my goals
            </button>
          </div>
        </Card>

        {/* docs/13 I-11 — a couple planning together (sql/035). Offered only
            to someone who has just said another person depends on their
            income, and skippable simply by walking past it: there is no
            "later" button to dismiss, because it is not a modal and does not
            block anything. Each partner keeps their own plan — this is a
            grouping, never a shared pot (docs/02 §4.1). */}
        {offerPartner && (
          <Card className="mt-4 p-5">
            {partnerState === 'sent' ? (
              <p className="text-sm text-[var(--color-ink)]">
                Invitation sent. Once they accept, you'll each keep your own plan and be able to see
                the combined picture.
              </p>
            ) : (
              <form onSubmit={handleInvite}>
                <h2 className="text-sm font-semibold text-[var(--color-ink)]">
                  Planning this with someone?
                </h2>
                <p className="mt-1 text-xs leading-relaxed text-[var(--color-ink-2)]">
                  Invite them and you'll each keep your own plan, with a combined view across both.
                  You can always do this later.
                </p>
                <div className="mt-3 flex flex-wrap items-center gap-2">
                  <input
                    type="email"
                    value={partnerEmail}
                    onChange={(e) => setPartnerEmail(e.target.value)}
                    placeholder="their@email.com"
                    aria-label="Partner's email address"
                    className="field flex-1 min-w-[200px]"
                  />
                  <Button type="submit" disabled={partnerState === 'sending' || !partnerEmail.trim()}>
                    {partnerState === 'sending' ? 'Sending…' : 'Send invite'}
                  </Button>
                </div>
                {partnerError && (
                  <p className="mt-2 text-xs" style={{ color: 'var(--color-alert)' }}>{partnerError}</p>
                )}
              </form>
            )}
          </Card>
        )}

        <div className="mt-4">
          <DisclosureBanner />
        </div>
      </main>
    </div>
  );
}
