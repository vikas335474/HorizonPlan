// docs/13 §7.1 · Pure tests for lib/personalPlanner.js — the module that turns
// the self-serve Q&A into a starting set of goals.
//
// WHY THIS IS THE ONLY .mjs FILE IN tests/. The rest of the suite is PHP
// because the rest of the logic worth testing is PHP. suggestGoals() and
// retirementCountdown() are the exception: they are real decision logic that
// decides what plan a person ends up with, and they live in the frontend
// because the answers behind them are deliberately never sent to the server
// (see the module header for why). Testing them from PHP is not possible, and
// leaving the one module that shapes every self-serve plan untested was not
// acceptable either.
//
// Run with plain `node tests/test_personal_planner.mjs` — no test framework,
// no new dependency, same PASS/FAIL convention as the PHP tests so
// run_all.sh's output stays uniform. run_all.sh skips this file when node is
// unavailable, the same self-skipping posture the DB tests already use.

import {
  suggestGoals,
  retirementCountdown,
  RETIREMENT_AGE_DEFAULT,
  INFLATION_DEFAULT,
  WITHDRAWAL_RATE_DEFAULT,
} from '../frontend/src/lib/personalPlanner.js';

let failed = false;

function assertTrue(cond, label) {
  console.log(`${cond ? 'PASS' : 'FAIL'}: ${label}`);
  if (!cond) failed = true;
}

function goalOfType(goals, type) {
  return goals.find((g) => g.fields.goal_type === type) || null;
}

// A complete, ordinary set of answers — the baseline the cases below vary.
const base = {
  age: '34',
  retire_age: '60',
  savings: '1500000',
  monthly_saving: '25000',
  kids: 'none',
  dependants: '0',
  home: 'no',
};

// --- retirement is unconditional -------------------------------------------
assertTrue(
  goalOfType(suggestGoals(base), 'retirement') !== null,
  'a retirement goal is always suggested'
);
assertTrue(
  goalOfType(suggestGoals({}), 'retirement') !== null,
  'a retirement goal is suggested even with no answers at all'
);

// --- the life answers decide which goals appear ----------------------------
assertTrue(
  goalOfType(suggestGoals({ ...base, kids: 'none' }), 'education') === null,
  'no children -> no education goal'
);
assertTrue(
  goalOfType(suggestGoals({ ...base, kids: 'one' }), 'education') !== null,
  'one child -> an education goal'
);
assertTrue(
  goalOfType(suggestGoals({ ...base, kids: 'many' }), 'education').fields.target_amount >
    goalOfType(suggestGoals({ ...base, kids: 'one' }), 'education').fields.target_amount,
  'more than one child -> a larger education target than one child'
);
assertTrue(
  goalOfType(suggestGoals({ ...base, home: 'no' }), 'home_purchase') === null,
  'not planning to buy -> no home goal'
);
assertTrue(
  goalOfType(suggestGoals({ ...base, home: 'owned' }), 'home_purchase') === null,
  'already owns a home -> no home goal (the answer that is easiest to get wrong)'
);
assertTrue(
  goalOfType(suggestGoals({ ...base, home: 'yes' }), 'home_purchase') !== null,
  'planning to buy -> a home goal'
);

// --- the retirement goal carries the person's own figures ------------------
{
  const r = goalOfType(suggestGoals(base), 'retirement').fields;
  assertTrue(r.initial_net_worth === 1500000, "savings become the goal's starting corpus");
  assertTrue(r.monthly_sip_amount === 25000, 'the stated monthly saving becomes the SIP');
  assertTrue(r.current_age === 34 && r.retirement_age === 60, 'both ages are carried through');
  assertTrue(r.inflation_rate === INFLATION_DEFAULT, "inflation uses the app's own default");
  assertTrue(r.withdrawal_rate === WITHDRAWAL_RATE_DEFAULT, 'withdrawal uses the India baseline');
  assertTrue(
    r.drawdown_return_rate === null,
    'the return assumption is left null here — the risk step supplies it, and nothing else may invent one'
  );
}

// --- horizon: the floor that stops a late retirement modelling 2 years -----
assertTrue(
  goalOfType(suggestGoals({ ...base, retire_age: '60' }), 'retirement').fields
    .projection_horizon_years === 30,
  'retiring at 60 models to about 90'
);
assertTrue(
  goalOfType(suggestGoals({ ...base, retire_age: '75' }), 'retirement').fields
    .projection_horizon_years === 20,
  'retiring at 75 floors the horizon at 20 years rather than 15'
);
assertTrue(
  goalOfType(suggestGoals({ ...base, retire_age: '80' }), 'retirement').fields
    .projection_horizon_years === 20,
  'the floor holds at the top of the allowed retirement age'
);

// --- defaults when an answer is missing entirely ---------------------------
{
  const r = goalOfType(suggestGoals({}), 'retirement').fields;
  assertTrue(r.retirement_age === RETIREMENT_AGE_DEFAULT, 'missing retirement age falls back to the default');
  assertTrue(r.initial_net_worth === 0, 'missing savings is 0, not NaN');
  assertTrue(r.monthly_sip_amount === 0, 'missing monthly saving is 0, not NaN');
}
assertTrue(
  goalOfType(suggestGoals({ ...base, savings: '0' }), 'retirement').fields.initial_net_worth === 0,
  'zero savings is a real answer and survives as 0 (sql/033 — "not started saving yet")'
);

// --- docs/13 G5 / I-6: target-based goals carry no contribution -------------
// Asserted so the current, deliberate behaviour is visible rather than
// accidental. If a future session gives these goals a contribution (a real
// product decision — see the note in personalPlanner.js), this test should
// fail and be updated as part of that work, not quietly drift.
{
  const withBoth = suggestGoals({ ...base, kids: 'one', home: 'yes' });
  const edu = goalOfType(withBoth, 'education').fields;
  const home = goalOfType(withBoth, 'home_purchase').fields;
  assertTrue(
    edu.monthly_sip_amount === undefined && home.monthly_sip_amount === undefined,
    'target-based goals are created with no SIP (goals_create.php would drop it anyway)'
  );
  assertTrue(
    goalOfType(withBoth, 'retirement').fields.monthly_sip_amount === 25000,
    'so the whole stated monthly saving still goes to the retirement goal'
  );
}

// --- retirementCountdown ---------------------------------------------------
{
  const c = retirementCountdown(34, 60);
  assertTrue(c.years === 26 && c.months === 0, '34 to 60 is 26 years exactly');
  assertTrue(c.totalMonths === 312, 'and 312 months');
  assertTrue(c.reached === false, 'not reached');
}
{
  const c = retirementCountdown(34.5, 60);
  assertTrue(c.years === 25 && c.months === 6, 'a half year is reported as 6 months, not 25.5 years');
}
assertTrue(retirementCountdown(60, 60).reached === true, 'at the retirement age, reached');
assertTrue(retirementCountdown(65, 60).reached === true, 'past the retirement age, reached');
assertTrue(
  retirementCountdown(65, 60).totalMonths === 0,
  'and it never counts below zero'
);
assertTrue(retirementCountdown(null, 60) === null, 'no current age -> null');
assertTrue(retirementCountdown(34, null) === null, 'no retirement age -> null');

console.log(failed ? '\nSome personal-planner tests failed.' : '\nAll personal-planner tests passed.');
process.exit(failed ? 1 : 0);
