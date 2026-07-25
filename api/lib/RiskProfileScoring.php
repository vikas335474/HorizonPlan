<?php
declare(strict_types=1);

/**
 * Pure computation only — no DB access, same contract as PlanMath.php, but a
 * separate class because this isn't retirement-projection arithmetic: it's
 * generic scoring of a firm-supplied questionnaire against a firm-supplied
 * rubric (docs/06 Section B / docs/07 Session C).
 *
 * Deliberately dumb: it has no opinion on what a "good" question or scoring
 * threshold looks like. Both $questions and $rubric are firm-authored data,
 * never hardcoded here — that's the guardrail (docs/06 guardrail 2): a
 * question set/rubric ships inert until a firm principal approves it, and
 * this class has no path to invent scoring content of its own.
 */
final class RiskProfileScoring
{
    /**
     * @param array<string,mixed> $answers question_id => selected option value
     * @param array<int,array{id:string,text:string,options:array<int,array{value:mixed,score:int}>}> $questions
     */
    public static function score(array $answers, array $questions): int
    {
        $total = 0;
        foreach ($questions as $question) {
            $questionId = (string) $question['id'];
            if (!array_key_exists($questionId, $answers)) {
                continue; // unanswered question contributes nothing
            }
            $selected = (string) $answers[$questionId];
            foreach ($question['options'] as $option) {
                if ((string) $option['value'] === $selected) {
                    $total += (int) $option['score'];
                    break;
                }
            }
        }
        return $total;
    }

    /**
     * @param array{bands:array<int,array{name:string,min:int,max:int,suggested_return_pct:float}>} $rubric
     * @return array{name:string,min:int,max:int,suggested_return_pct:float}|null null if no band's
     *   min/max range covers this score — a gap in the firm's own rubric, not this class's problem to guess at.
     */
    public static function bandFor(int $score, array $rubric): ?array
    {
        foreach ($rubric['bands'] as $band) {
            if ($score >= (int) $band['min'] && $score <= (int) $band['max']) {
                return $band;
            }
        }
        return null;
    }
}
