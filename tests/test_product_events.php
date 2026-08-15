<?php
declare(strict_types=1);

// docs/13 I-5 · Pure tests for the product-analytics validation layer.
//
// THE POINT OF THIS FILE. sql/040 and ProductEvents.php both state a privacy
// rule — record what a person DID, never a figure — and a rule that lives only
// in a comment is a rule that erodes. These tests assert it mechanically, so
// the next person who adds an event with an amount attached gets a failing
// suite rather than a code review they might not get.
//
// Pure: no database, no PDO. recordProductEvent() is the only function here
// that touches storage and it is deliberately not exercised — the DB-level
// behaviour belongs in a *_db.php test.

require_once __DIR__ . '/../api/lib/ProductEvents.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

// --- the event-name allow-list ---------------------------------------------
assertTrue(isKnownProductEvent('plan_created'), 'a known event name is accepted');
assertTrue(!isKnownProductEvent('plan_creatd'), 'a typo is rejected, not silently recorded as a new event');
assertTrue(!isKnownProductEvent(''), 'an empty name is rejected');
assertTrue(!isKnownProductEvent('DROP TABLE users'), 'an arbitrary string is rejected');
assertTrue(count(EVENT_NAMES) === count(array_unique(EVENT_NAMES)), 'no duplicate event names');

// --- context: the whitelist ------------------------------------------------
assertTrue(
    sanitiseProductEventContext(['step' => 3]) === ['step' => 3],
    'a whitelisted integer key survives'
);
assertTrue(
    sanitiseProductEventContext(['screen' => 'personal_onboarding']) === ['screen' => 'personal_onboarding'],
    'a whitelisted slug survives'
);
assertTrue(
    sanitiseProductEventContext(['lever' => 'sip', 'question_id' => 'city_tier'])
        === ['lever' => 'sip', 'question_id' => 'city_tier'],
    'several whitelisted keys survive together'
);
assertTrue(
    sanitiseProductEventContext(['not_a_key' => 'x']) === [],
    'an unknown key is dropped'
);
assertTrue(
    sanitiseProductEventContext([]) === [],
    'empty context stays empty (callers store SQL NULL)'
);

// --- context: THE PRIVACY RULE ---------------------------------------------
// Each of these is a realistic way a financial value could end up in the event
// log if the only guard were a comment.
assertTrue(
    sanitiseProductEventContext(['amount' => 2500000]) === [],
    'a rupee amount under an unlisted key is dropped'
);
assertTrue(
    sanitiseProductEventContext(['step' => 2500000]) === [],
    'a rupee amount smuggled through a WHITELISTED integer key is dropped (out of range)'
);
assertTrue(
    sanitiseProductEventContext(['screen' => '2500000']) === [],
    'a rupee amount smuggled through a whitelisted key AS A STRING is dropped'
);
assertTrue(
    sanitiseProductEventContext(['screen' => '25000.50']) === [],
    'a decimal figure as a string is dropped'
);
assertTrue(
    sanitiseProductEventContext(['step' => 34]) === ['step' => 34],
    'an age-shaped number IS allowed through step — the range guard cannot tell 34 from an age'
);
assertTrue(
    sanitiseProductEventContext(['step' => 3.5]) === [],
    'a float is dropped whatever its size — a step index is never fractional'
);
assertTrue(
    sanitiseProductEventContext(['step' => -1]) === [],
    'a negative index is dropped'
);
assertTrue(
    sanitiseProductEventContext(['question_id' => str_repeat('a', 200)]) === [],
    'an over-long string is dropped rather than truncated into the database'
);
assertTrue(
    sanitiseProductEventContext(['outcome' => ['nested' => 'payload']]) === [],
    'an array value is dropped — context is labels, never a payload'
);
assertTrue(
    sanitiseProductEventContext(['outcome' => true]) === [],
    'a boolean is dropped'
);
assertTrue(
    sanitiseProductEventContext(['outcome' => null]) === [],
    'a null is dropped'
);
assertTrue(
    sanitiseProductEventContext(['screen' => '  goals  ']) === ['screen' => 'goals'],
    'a slug is trimmed'
);
assertTrue(
    sanitiseProductEventContext(['screen' => '   ']) === [],
    'a whitespace-only slug is dropped'
);

// A mixed payload: the legitimate parts survive, the figure does not.
assertTrue(
    sanitiseProductEventContext([
        'step'    => 4,
        'screen'  => 'personal_onboarding',
        'amount'  => 1200000,
        'balance' => '4500000',
    ]) === ['step' => 4, 'screen' => 'personal_onboarding'],
    'a mixed payload keeps only the non-financial labels'
);

echo "\nAll product-events validation tests passed.\n";
