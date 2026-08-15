// docs/13 I-1 / I-2 — the self-serve individual's standing block: where they
// are, and the ONE thing worth doing next.
//
// WHY THIS EXISTS. /goals used to open with eight cards of empty forms and put
// the person's actual plan underneath all of them (docs/13 G1), and the answer
// to "I'm short — what do I do?" was computed on every projection but rendered
// only inside a goal's own detail page (G2). Someone finishing onboarding
// landed on a chore list, with the one genuinely useful sentence one click
// away and no sign it existed.
//
// THE ONE-THING RULE. This card names a single next action, never a list. A
// list of five things to do is a to-do app; the whole point here is to answer
// "what now?" with one sentence a person can act on. Priority order, highest
// first:
//   1. a real shortfall with a solved lever  — the most actionable fact we have
//   2. an 'attention' alert                  — something changed and it matters
//   3. an unanswered foundations question    — a gap in what we can even assess
//   4. nothing                               — say so plainly and stop talking
//
// It states facts and never instructs — same rule AlertsEngine.php asserts in
// its own tests. "Closing this takes ₹8,400 more a month" is a fact about
// arithmetic. "You should invest ₹8,400 more" is advice, and this product does
// not give advice.

import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { Card } from './ui';
import { leverLine } from './RetirementTargetCard';
import { retirementCountdown } from '../lib/personalPlanner';
import { useAuth, displayNameFor } from '../context/AuthContext';

// "Aug 2026" — a monthly series never needs the day, and the window this
// labels is measured in months at minimum.
function shortMonth(iso) {
  const d = new Date(`${iso}T00:00:00`);
  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleDateString('en-IN', { month: 'short', year: 'numeric' });
}

/**
 * @param goals  the already-loaded goals array from GoalsList (not re-fetched)
 */
export default function PersonalPlanSummary({ goals }) {
  const { user } = useAuth();
  const [projection, setProjection] = useState(null);
  const [alerts, setAlerts] = useState(null);
  const [progress, setProgress] = useState(null);

  // The retirement goal is the one this block reports on: it is the only goal
  // type that carries a readiness score and a solvable gap.
  const retirementGoal = (goals || []).find((g) => g.goal_type === 'retirement') || null;
  // Depend on the id, not the object: GoalsList re-renders with a fresh array
  // identity on every state change, and refetching the projection each time
  // would hammer the endpoint for an unchanged goal.
  const retirementGoalId = retirementGoal?.id ?? null;

  useEffect(() => {
    if (retirementGoalId === null) return undefined;
    let cancelled = false;
    // Deliberately the SAME endpoint the goal's own page uses, rather than a
    // second server-side computation of the target and levers. A duplicate
    // would be a figure that can disagree with the goal page in front of the
    // same person — the exact failure AlertsInputs.php avoids by mirroring
    // goals_projection.php's own lifecycle computation.
    api
      .getProjection(retirementGoalId)
      .then((res) => { if (!cancelled) setProjection(res); })
      .catch(() => { if (!cancelled) setProjection(null); });
    return () => { cancelled = true; };
  }, [retirementGoalId]);

  useEffect(() => {
    let cancelled = false;
    api
      .getAlerts()
      .then((res) => { if (!cancelled) setAlerts(res.alerts || []); })
      .catch(() => { if (!cancelled) setAlerts([]); });
    return () => { cancelled = true; };
  }, []);

  // The recorded readiness history for this goal (sql/042). Separate from the
  // projection above: that one is today's answer, this one is how it has moved.
  useEffect(() => {
    if (retirementGoalId === null) return undefined;
    let cancelled = false;
    api
      .getGoalProgress(retirementGoalId)
      .then((res) => { if (!cancelled) setProgress(res); })
      .catch(() => { if (!cancelled) setProgress(null); });
    return () => { cancelled = true; };
  }, [retirementGoalId]);

  // Nothing to stand on yet — the empty state in GoalsList handles this case.
  if (!goals || goals.length === 0) return null;

  const countdown = retirementGoal
    ? retirementCountdown(retirementGoal.current_age, retirementGoal.retirement_age)
    : null;

  const target = projection?.retirement_target ?? null;
  const levers = projection?.gap_closing_levers ?? null;
  const score = retirementGoal?.readiness_score ?? null;

  // --- the one next action, in priority order (see header) ------------------
  let action = null;

  const lever = leverLine(levers, true);
  if (lever && retirementGoal) {
    action = {
      tone: 'attention',
      text: lever,
      href: `/goals/${retirementGoal.id}`,
      cta: 'Try it on the plan',
      lever: levers?.smaller_lever ?? null,
    };
  }

  if (!action && alerts && alerts.length > 0) {
    const attention = alerts.find((a) => a.severity === 'attention');
    if (attention) {
      action = {
        tone: 'attention',
        text: attention.factual_message,
        href: attention.deep_link,
        cta: 'Take a look',
      };
    }
  }

  if (!action && target && target.is_met) {
    action = {
      tone: 'positive',
      text: "On what you've told us, this plan reaches its number.",
      href: retirementGoal ? `/goals/${retirementGoal.id}` : null,
      cta: 'See the detail',
    };
  }

  // --- who this plan is about, and what it assumes ------------------------
  //
  // Not decoration. current_age and retirement_age are the two most
  // load-bearing inputs in the whole plan — they set the horizon every other
  // number is computed over — and until now they were visible only by opening
  // a goal. A wrong age silently invalidates the entire projection, so the
  // assumptions are stated on the surface that leads with the answer.
  const name = displayNameFor(user);
  const currentAge = retirementGoal?.current_age ?? null;
  const retirementAge = retirementGoal?.retirement_age ?? null;

  // How the ANSWER has moved (sql/042). Null until two scored snapshots exist;
  // the server refuses to report a delta from one reading, so there is nothing
  // to guard against here beyond the null itself.
  const readinessChange = progress?.readiness_change ?? null;

  return (
    <Card className="p-5 mb-4">
      {/* --- who you are, and what this plan assumes about you --- */}
      {(name || currentAge !== null) && (
        <div className="mb-4 flex flex-wrap items-baseline gap-x-2 gap-y-1 border-b border-[var(--color-line)] pb-3">
          {name && (
            <span className="text-sm font-semibold text-[var(--color-ink)]">{name}</span>
          )}
          {currentAge !== null && (
            <span className="text-xs text-[var(--color-ink-2)] tabular-nums">
              {name ? '· ' : ''}age {currentAge}
              {retirementAge !== null && <> · retiring at {retirementAge}</>}
            </span>
          )}
          {retirementGoal && (
            <Link
              to={`/goals/${retirementGoal.id}`}
              className="ml-auto text-[11px] text-[var(--color-ink-3)] underline hover:text-[var(--color-ink)]"
            >
              Not right? Change it
            </Link>
          )}
        </div>
      )}

      {/* --- where you stand --- */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">
            {countdown?.reached ? 'You’ve reached your date' : 'Time until you stop working'}
          </p>
          <p className="mt-0.5 text-[26px] font-semibold leading-tight tabular-nums tracking-tight text-[var(--color-ink)]">
            {countdown && !countdown.reached
              ? `${countdown.years} years${countdown.months ? `, ${countdown.months} months` : ''}`
              : countdown?.reached
                ? 'Now'
                : '—'}
          </p>
        </div>

        {score !== null && (
          <div className="text-right">
            <p className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">
              How the money holds up
            </p>
            <p className="mt-0.5 text-[26px] font-semibold leading-tight tabular-nums tracking-tight text-[var(--color-ink)]">
              {score}
              <span className="text-sm font-normal text-[var(--color-ink-3)]"> / 100</span>
            </p>

            {/* How that answer has MOVED across the tracked window.
                Deliberately "since <month>", never "this month vs last": a
                monthly framing on a multi-decade plan trains exactly the
                short-horizon evaluation habit that makes long-term investors
                worse off. A zero delta is worth saying out loud — "unchanged"
                is a real and reassuring answer, not an empty state. */}
            {readinessChange && (
              <p
                className="mt-1 text-[11px] tabular-nums"
                style={{
                  color:
                    readinessChange.delta > 0
                      ? 'var(--color-teal-ink)'
                      : readinessChange.delta < 0
                        ? 'var(--color-amber-ink)'
                        : 'var(--color-ink-3)',
                }}
              >
                {readinessChange.delta > 0 && `▲ ${readinessChange.delta} `}
                {readinessChange.delta < 0 && `▼ ${Math.abs(readinessChange.delta)} `}
                {readinessChange.delta === 0 && 'Unchanged '}
                since {shortMonth(readinessChange.from_date)}
              </p>
            )}
          </div>
        )}
      </div>

      {/* --- the one thing to do next --- */}
      {action && (
        <div
          className="mt-4 rounded-lg px-4 py-3"
          style={{
            backgroundColor:
              action.tone === 'positive' ? 'var(--color-teal-soft)' : 'var(--color-amber-soft)',
          }}
        >
          <p className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">
            {action.tone === 'positive' ? 'Where you are' : 'Worth a look'}
          </p>
          <p className="mt-1 text-sm leading-relaxed text-[var(--color-ink)]">{action.text}</p>
          {action.href && (
            <Link
              to={action.href}
              onClick={() => {
                // docs/13 §5 question 3 — does anyone actually engage with the
                // lever? Records WHICH lever was shown, never the amount.
                if (action.lever) api.logEvent('lever_previewed', { lever: action.lever });
              }}
              className="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-[var(--color-teal-ink)] hover:underline"
            >
              {action.cta}
              <svg width="13" height="13" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                <path d="M5 3l4 4-4 4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </Link>
          )}
        </div>
      )}

      {/* The score says whether the money lasts — not whether the person is
          otherwise covered. Same caveat ReadinessScore.jsx already carries;
          repeated here because this is now the first number on the page. */}
      {score !== null && (
        <p className="mt-3 text-[11px] leading-relaxed text-[var(--color-ink-3)]">
          This score only asks whether the money lasts. It says nothing about insurance, an
          emergency fund, or debt — those are further down this page.
        </p>
      )}
    </Card>
  );
}
