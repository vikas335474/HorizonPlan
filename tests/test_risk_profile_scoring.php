<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/RiskProfileScoring.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? "PASS" : "FAIL") . ": $label\n";
    if (!$cond) {
        exit(1);
    }
}

// A mock firm-authored question set + rubric — never HorizonPlan's own
// content (docs/06 guardrail 2), just illustrative fixtures for the test.
$questions = [
    ['id' => 'q1', 'text' => 'Horizon', 'options' => [['value' => 'short', 'score' => 1], ['value' => 'long', 'score' => 3]]],
    ['id' => 'q2', 'text' => 'Reaction to a 20% drop', 'options' => [
        ['value' => 'sell', 'score' => 0], ['value' => 'hold', 'score' => 2], ['value' => 'buy_more', 'score' => 4],
    ]],
];
$rubric = ['bands' => [
    ['name' => 'conservative', 'min' => 0, 'max' => 2, 'suggested_return_pct' => 6.0],
    ['name' => 'moderate', 'min' => 3, 'max' => 5, 'suggested_return_pct' => 9.0],
    ['name' => 'aggressive', 'min' => 6, 'max' => 7, 'suggested_return_pct' => 12.0],
]];

// Hand-verified: long (3) + buy_more (4) = 7 -> aggressive band.
$score1 = RiskProfileScoring::score(['q1' => 'long', 'q2' => 'buy_more'], $questions);
assertTrue($score1 === 7, 'score sums the matched option for each answered question');
$band1 = RiskProfileScoring::bandFor($score1, $rubric);
assertTrue($band1['name'] === 'aggressive', 'bandFor finds the rubric band containing the score (aggressive)');
assertTrue($band1['suggested_return_pct'] === 12.0, 'bandFor returns the firm-supplied suggested_return_pct, not an invented one');

// Hand-verified: short (1) + sell (0) = 1 -> conservative band.
$score2 = RiskProfileScoring::score(['q1' => 'short', 'q2' => 'sell'], $questions);
assertTrue($score2 === 1, 'score handles the lowest-scoring answer combination');
assertTrue(RiskProfileScoring::bandFor($score2, $rubric)['name'] === 'conservative', 'bandFor finds the conservative band');

// An unanswered question contributes nothing, not an error and not a default score.
$score3 = RiskProfileScoring::score(['q1' => 'long'], $questions);
assertTrue($score3 === 3, 'score treats an unanswered question as contributing zero, not throwing');

// A score outside every band's range (rubric gap) returns null, not a guess.
assertTrue(RiskProfileScoring::bandFor(100, $rubric) === null, 'bandFor returns null when no band covers the score — a rubric gap, not this class\'s problem to guess at');

// An answer that doesn't match any of the question's options contributes nothing.
$score4 = RiskProfileScoring::score(['q1' => 'nonexistent_option'], $questions);
assertTrue($score4 === 0, 'score contributes nothing for an answer value not present in the question\'s options');

echo "\nAll risk profile scoring tests passed.\n";
