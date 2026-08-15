<?php
declare(strict_types=1);

// docs/13 I-9 · Pure tests for the monthly plan digest's body composition.
//
// TWO RULES ARE ENFORCED HERE, both of which are easy to erode later:
//
//   1. AN EMAIL NEVER INSTRUCTS. Every line states what IS true about the
//      person's plan; none tells them what to do. AlertsEngine holds itself to
//      exactly this and asserts it in its own tests — the digest reuses those
//      messages, so the same guard has to follow them into the inbox. An email
//      is read away from the chart, with no way to ask a follow-up, which
//      makes it the worst possible place for advice to leak in.
//
//   2. SILENCE BEATS FILLER. A digest with nothing to report returns null and
//      sends nothing, rather than mailing "no change this month". A monthly
//      email that is usually empty is how a useful digest becomes a filtered
//      one.
//
// Pure: composeDigestBody() takes an alerts array and returns a string, with
// no DB, no clock and no mail. dueDigestRecipients() and markDigestSent() are
// the DB half and belong in a *_db.php companion.

require_once __DIR__ . '/../api/lib/PersonalDigestMailer.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

const BASE_URL = 'https://example.test';

function alert(string $severity, string $message, string $type = 'goal_drift'): array
{
    return ['type' => $type, 'severity' => $severity, 'factual_message' => $message];
}

// --- silence beats filler ---------------------------------------------------
assertTrue(
    composeDigestBody([], null, BASE_URL) === null,
    'no alerts -> null, so the cron sends nothing at all'
);
assertTrue(
    composeDigestBody([alert('attention', '')], null, BASE_URL) === null,
    'alerts with only empty messages -> null, not an email with a blank bullet'
);

// --- the ordinary case ------------------------------------------------------
$body = composeDigestBody(
    [alert('attention', 'Your "Retiring at 60" plan is behind where it expected to be.')],
    'Priya',
    BASE_URL
);
assertTrue($body !== null, 'a real alert produces a body');
assertTrue(str_contains($body, 'Priya'), 'the greeting uses the name when there is one');
assertTrue(
    str_contains($body, 'behind where it expected to be'),
    "the alert's own wording is carried through verbatim, not paraphrased"
);
assertTrue(str_contains($body, BASE_URL . '/goals'), 'there is exactly one link, back into the plan');
assertTrue(
    substr_count($body, BASE_URL) === 1,
    'and only one — a digest is not a place to farm clicks'
);
assertTrue(str_contains($body, 'Settings'), 'the opt-out is stated in the body');
assertTrue(
    str_contains($body, 'not') && str_contains($body, 'advice'),
    'the body says plainly that this is not advice'
);

// --- no name recorded -------------------------------------------------------
$anon = composeDigestBody([alert('attention', 'Something is true.')], null, BASE_URL);
assertTrue(str_contains($anon, 'Hello,'), 'no display name -> a clean greeting, never "Hello null,"');
$blank = composeDigestBody([alert('attention', 'Something is true.')], '   ', BASE_URL);
assertTrue(str_contains($blank, 'Hello,'), 'a whitespace-only name is treated as no name');

// --- good news first --------------------------------------------------------
$mixed = composeDigestBody(
    [
        alert('attention', 'ATTENTION LINE.'),
        alert('positive', 'POSITIVE LINE.', 'goal_met'),
    ],
    null,
    BASE_URL
);
assertTrue(
    strpos($mixed, 'POSITIVE LINE.') < strpos($mixed, 'ATTENTION LINE.'),
    'a positive alert is listed before an attention one, whatever order they arrived in'
);

// --- RULE 1: an email never instructs ---------------------------------------
// The same vocabulary AlertsEngine's own tests forbid. Checked against the
// FULL composed body, so the framing this file adds around the alerts is held
// to the same standard as the alerts themselves.
$instructionWords = [
    'you should', 'we recommend', 'recommended', 'you must', 'you need to',
    'buy ', 'sell ', 'switch to', 'invest in', 'act now', 'don\'t delay',
    'take action', 'log in now', 'urgent',
];

$realistic = composeDigestBody(
    [
        alert('positive', '"Child\'s education" has reached its target.', 'goal_met'),
        alert('attention', 'Your "Retiring at 60" plan is behind where it expected to be.'),
        alert('attention', 'A price used in your portfolio has not refreshed in 5 days.', 'price_stale'),
        alert('attention', 'Your emergency reserve has not been recorded yet.', 'foundations_gap'),
    ],
    'Sam',
    BASE_URL
);

foreach ($instructionWords as $word) {
    assertTrue(
        !str_contains(strtolower($realistic), $word),
        "a composed body never contains the instruction phrase '{$word}'"
    );
}

// --- the subject stays dull and stable --------------------------------------
assertTrue(digestSubject() === 'Your plan this month', 'the subject is fixed');
foreach (['!', 'URGENT', 'Act now', '🚀'] as $noise) {
    assertTrue(
        !str_contains(digestSubject(), $noise),
        "the subject has no '{$noise}' — a digest is not news"
    );
}

echo "\nAll personal-digest composition tests passed.\n";
