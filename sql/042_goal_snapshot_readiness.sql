-- Records the READINESS SCORE alongside each goal snapshot, so the number the
-- individual dashboard leads with becomes a tracked series rather than a
-- point-in-time reading.
--
-- WHY THIS AND NOT A CORPUS DELTA. Migration 032 already records what the
-- corpus was and what the plan expected. That answers "is the money where the
-- plan said it would be". It does not answer the question a person actually
-- opens the app to ask — "has the ANSWER changed?" — because readiness is a
-- blend of survival across the steady and adverse series, and a corpus that
-- moved 2% can leave the score untouched or shift it several points depending
-- on where it sits relative to depletion. Storing the score is the only way to
-- say "62 in March, 68 now" honestly.
--
-- WHY STORED, NOT RECOMPUTED ON READ — the same reasoning migration 032 gives
-- for expected_value, and it matters more here. Readiness is computed from the
-- goal's CURRENT assumptions. Recomputing a historical point would mean that
-- lowering a return assumption today retroactively rewrites what the score was
-- last March, turning a decline into an improvement without anyone editing
-- history. The score on a row is what the plan said on that date, permanently.
--
-- NULL is a real and common state, not missing data:
--   * target-based goals (education/home/other) carry no return assumption and
--     so have no readiness score at all — they track funding coverage instead;
--   * a zero-corpus goal scores NULL by design, because withdrawing a
--     percentage of nothing survives forever and would otherwise report ~89 to
--     someone who has not started saving (PlanMath::readinessScoreForGoal);
--   * every row written before this migration.
-- So the UI must treat NULL as "not scored", never as zero.
--
-- NO BACKFILL. Consistent with 032's own rule: history starts when tracking
-- starts. Backfilling would mean computing what today's assumptions imply for
-- a past date and writing it down as though it had been observed then — which
-- is exactly the retroactive rewrite the paragraph above refuses. A plan gains
-- a readiness history from its next snapshot onward, and the UI renders no
-- delta until two scored points exist.

ALTER TABLE goal_snapshots
    ADD COLUMN readiness_score TINYINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'blended 0-100 readiness as the plan stood on this date; NULL = not scorable (target-based goal, zero corpus, or pre-migration row)'
        AFTER funding_covered_pct;

SELECT '042_goal_snapshot_readiness  (goal_snapshots.readiness_score)' AS migration, 'applied' AS state;
