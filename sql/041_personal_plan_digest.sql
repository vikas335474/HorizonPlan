-- docs/13 I-9 · The monthly "here's where you are" digest for a self-serve
-- individual.
--
-- THE PROBLEM. A retirement plan is looked at a few times a year at best. The
-- monthly snapshot cron (tools/progress_snapshot.php) has been faithfully
-- recording each person's progress and then telling them nothing about it, so
-- the self-serve tier has had no retention mechanism at all (docs/13 G8).
--
-- *** OPT-IN, DEFAULT OFF — and that is deliberate. ***
-- The obvious growth move is to default this ON and let people unsubscribe.
-- This codebase does not do that, for two reasons that are already settled
-- here rather than being re-argued now:
--   1. CLAUDE.md's standing guardrail: default new toggles OFF, never silently
--      change what an existing person experiences on a migration.
--   2. The direct precedent is PlanReviewMailer (docs/10 P0-3), whose own
--      header states that cadence defaults to 'off' and a client is only ever
--      emailed when someone has explicitly opted them in.
-- Defaulting to 0 also means this migration cannot start emailing anybody the
-- moment it is applied, which is the failure mode worth engineering against.
--
-- The opt-in is offered prominently in-product (the post-onboarding proof
-- screen and Settings) rather than assumed. That is the honest way to earn the
-- send, and it keeps the product's "we plan, we don't sell" posture intact —
-- an inbox is the easiest thing in this business to abuse.

ALTER TABLE users
    ADD COLUMN plan_digest_opt_in TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'docs/13 I-9. 1 = this person asked for the monthly plan digest. Default 0: nobody is emailed until they say so.'
        AFTER display_name,

    -- Send-once-per-period bookkeeping, same shape as
    -- plan_review_schedules.last_sent_at. NULL = never sent, which is how a
    -- freshly opted-in person qualifies on the next run.
    ADD COLUMN plan_digest_last_sent_at DATETIME NULL
        COMMENT 'docs/13 I-9. When the monthly digest last went out to this person. NULL = never.'
        AFTER plan_digest_opt_in;

-- The cron's own lookup: opted-in people, oldest send first. Narrow on
-- purpose — this index exists for one query.
CREATE INDEX idx_users_digest_due
    ON users (plan_digest_opt_in, plan_digest_last_sent_at);
