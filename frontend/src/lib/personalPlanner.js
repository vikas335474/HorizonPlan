// Self-serve individual tier — the guided Q&A that turns a few plain questions
// into a starting set of goals.
//
// WHY THIS IS A PURE FRONTEND MODULE, AND WHY NOTHING HERE IS STORED.
// The questions ask about life circumstances — age, whether you have a
// partner, whether you have children. Those answers are only ever used to
// DECIDE WHICH GOALS TO SUGGEST, and are then discarded: the app persists the
// goals the person accepts, never the answers behind them. That keeps a pile
// of sensitive personal detail (marital status, dependants) out of the
// database entirely, needs no schema, and means the suggestion logic can be
// changed freely without a migration or a backfill.
//
// PROVENANCE OF THE NUMBERS. Every default below is either
//   (a) an anchor already established elsewhere in this repo, or
//   (b) an obvious arithmetic consequence of the person's own answers.
// Nothing here is a new financial claim, and nothing is presented as a
// recommendation — the UI labels every figure as an editable starting point,
// the same framing lib/strategyPresets.js already uses for withdrawal rates.
//
//   * 6% inflation — HorizonPlan's own default across the app (docs/02 §4.2,
//     NewGoalModal's default).
//   * 3.5% withdrawal — the India baseline in docs/05 (freefincal / Arthgyaan
//     sourced ~3–3.5%), and already this app's default.
//   * 60 retirement age — the common Indian superannuation age; the person
//     can change it, and the countdown recomputes from whatever they set.
//   * Education/home target amounts are round, obviously-illustrative
//     placeholders the person is expected to overwrite with their own number.

export const RETIREMENT_AGE_DEFAULT = 60;
export const INFLATION_DEFAULT = 6;
export const WITHDRAWAL_RATE_DEFAULT = 3.5;

// The questions. Deliberately few, plain-language, and each one has to EARN
// its place by changing which goals get suggested — a question whose answer
// doesn't change the outcome is just friction.
export const PLANNER_QUESTIONS = [
  {
    id: 'age',
    question: 'How old are you?',
    help: 'This sets how long your money has to grow, and the countdown to your retirement.',
    type: 'number',
    min: 18,
    max: 75,
    placeholder: 'e.g. 34',
  },
  {
    id: 'retire_age',
    question: 'When would you like to stop working?',
    help: `Most people in India plan around ${RETIREMENT_AGE_DEFAULT}. You can change this any time and watch the plan react.`,
    type: 'number',
    min: 40,
    max: 80,
    placeholder: `e.g. ${RETIREMENT_AGE_DEFAULT}`,
  },
  {
    id: 'savings',
    question: 'Roughly how much have you saved so far?',
    help: 'Everything you already have set aside — mutual funds, EPF, PPF, deposits. A rough number is fine; you can refine it later.',
    type: 'currency',
    placeholder: 'e.g. 15,00,000',
  },
  {
    id: 'monthly_saving',
    question: 'About how much do you put away each month?',
    help: 'Your regular monthly saving or SIP. Enter 0 if you are just starting out.',
    type: 'currency',
    placeholder: 'e.g. 25,000',
  },
  {
    id: 'kids',
    question: 'Do you have children you would want to fund an education for?',
    help: "This only decides whether we suggest an education goal — it isn't stored.",
    type: 'choice',
    options: [
      { value: 'none', label: 'No' },
      { value: 'one', label: 'Yes, one' },
      { value: 'many', label: 'Yes, more than one' },
    ],
  },
  {
    id: 'home',
    question: 'Are you planning to buy a home?',
    help: "Same here — it only decides whether we suggest a home goal.",
    type: 'choice',
    options: [
      { value: 'no', label: 'Not planning to' },
      { value: 'yes', label: 'Yes, at some point' },
      { value: 'owned', label: 'I already own one' },
    ],
  },
];

const YEAR_MS = 365.25 * 24 * 60 * 60 * 1000;

/** ISO date this many years from today — for a goal's target_date. */
function yearsFromNow(years) {
  return new Date(Date.now() + years * YEAR_MS).toISOString().slice(0, 10);
}

/**
 * Turn answers into suggested goals.
 *
 * Every returned goal is in the exact shape goals_create.php expects, so the
 * wizard can create them directly with no translation layer in between.
 * The person reviews and edits these before anything is saved — this function
 * proposes, it never decides.
 *
 * @param {object} answers keyed by PLANNER_QUESTIONS[].id
 * @returns {Array<{key:string, title:string, why:string, fields:object}>}
 */
export function suggestGoals(answers) {
  const age = Number(answers.age) || 30;
  const retireAge = Number(answers.retire_age) || RETIREMENT_AGE_DEFAULT;
  const savings = Number(answers.savings) || 0;
  const monthly = Number(answers.monthly_saving) || 0;
  const goals = [];

  // Retirement — always suggested. It's the product's whole subject, and it's
  // the one goal everyone has whether or not they've named it.
  //
  // projection_horizon_years is how long the money must last AFTER stopping
  // work. 90 is used as a planning-age anchor (a deliberately long life rather
  // than an average one — planning to the average leaves half of people short),
  // floored at 20 years so an unusually late retirement age still models a
  // sensible drawdown period rather than a 2-year one.
  const yearsAfterRetiring = Math.max(20, 90 - retireAge);
  goals.push({
    key: 'retirement',
    title: 'Retiring comfortably',
    why: `You stop working at ${retireAge}, and this plans for your money lasting until about 90.`,
    fields: {
      goal_type: 'retirement',
      goal_label: `Retiring at ${retireAge}`,
      initial_net_worth: savings,
      inflation_rate: INFLATION_DEFAULT,
      withdrawal_rate: WITHDRAWAL_RATE_DEFAULT,
      // The return assumption is what turns this into a projectable goal.
      // Left to the risk step, which sets it from the person's own answers —
      // see PERSONAL_RISK_BANDS. Null here means "not chosen yet".
      drawdown_return_rate: null,
      projection_horizon_years: yearsAfterRetiring,
      current_age: age,
      retirement_age: retireAge,
      monthly_sip_amount: monthly,
      sip_step_up_rate: 0,
    },
  });

  if (answers.kids === 'one' || answers.kids === 'many') {
    // Assumes college at ~18 for a young child. The person overwrites both the
    // amount and the date — this is a placeholder to react to, not an estimate
    // of their actual costs.
    const perChild = 2000000;
    const count = answers.kids === 'many' ? 2 : 1;
    goals.push({
      key: 'education',
      title: count > 1 ? "Children's education" : "Your child's education",
      why: 'A target amount you want ready by the time they start college. Change the amount and the year to match your plan.',
      fields: {
        goal_type: 'education',
        goal_label: count > 1 ? "Children's education" : "Child's education",
        initial_net_worth: 0,
        inflation_rate: INFLATION_DEFAULT,
        target_amount: perChild * count,
        target_date: yearsFromNow(15),
        projection_horizon_years: 15,
      },
    });
  }

  if (answers.home === 'yes') {
    goals.push({
      key: 'home',
      title: 'Buying a home',
      why: 'The deposit you want ready, and when. Both are yours to change.',
      fields: {
        goal_type: 'home_purchase',
        goal_label: 'Home purchase',
        initial_net_worth: 0,
        inflation_rate: INFLATION_DEFAULT,
        target_amount: 3000000,
        target_date: yearsFromNow(7),
        projection_horizon_years: 7,
      },
    });
  }

  return goals;
}

/**
 * Risk bands for a self-serve individual, and the return assumption each one
 * illustrates.
 *
 * IMPORTANT FRAMING. In the advisor product the return assumption behind a
 * risk band is supplied and approved by the FIRM — HorizonPlan never authors
 * it (docs/06 guardrail 2), because a firm has a principal accountable for it.
 * An individual has no such principal. So these are presented exactly the way
 * lib/strategyPresets.js presents withdrawal rates: sourced, clearly labelled
 * illustrations to compare and edit, never "the right answer for you".
 *
 * The rates are long-run asset-class reference points, not forecasts, and the
 * UI says so next to every one of them. The person can type their own number
 * instead, and the plan recomputes from whatever they choose.
 */
export const PERSONAL_RISK_BANDS = [
  {
    id: 'cautious',
    label: 'Cautious',
    blurb: 'Mostly steady, lower-growth investments. Smaller ups and downs, and usually a smaller final pot.',
    illustrativeReturn: 7,
  },
  {
    id: 'balanced',
    label: 'Balanced',
    blurb: 'A mix of growth and stability. The middle path most long-term plans assume.',
    illustrativeReturn: 9,
  },
  {
    id: 'adventurous',
    label: 'Adventurous',
    blurb: 'Mostly equity. Bigger swings along the way, including years where it falls, in exchange for more growth over decades.',
    illustrativeReturn: 11,
  },
];

/**
 * The countdown: how long until you stop working.
 *
 * Returns whole years and the leftover months — "23 years, 4 months" reads
 * like a human said it, where "23.4 years" does not. Null when the plan has no
 * ages set, and a done flag once the date has passed, so the UI can say
 * "you're there" rather than counting down past zero.
 *
 * @returns {{years:number, months:number, totalMonths:number, reached:boolean}|null}
 */
export function retirementCountdown(currentAge, retirementAge) {
  if (currentAge == null || retirementAge == null) return null;
  const totalMonths = Math.round((Number(retirementAge) - Number(currentAge)) * 12);
  if (Number.isNaN(totalMonths)) return null;
  if (totalMonths <= 0) {
    return { years: 0, months: 0, totalMonths: 0, reached: true };
  }
  return {
    years: Math.floor(totalMonths / 12),
    months: totalMonths % 12,
    totalMonths,
    reached: false,
  };
}
