-- ============================================================================
-- reference_costs seed — the paste-into-phpMyAdmin equivalent of
--   php tools/reference_costs_sync.php
--
-- WHY THIS FILE EXISTS. Hostinger Premium has no SSH, so there is no way to
-- run a PHP CLI script on demand — only scheduled cron jobs. That leaves no
-- path to populate this cache the first time without either scheduling a
-- throwaway cron or pasting SQL. This is the SQL.
--
-- GENERATED, NOT HAND-WRITTEN. Produced directly from
-- referenceCostDataset() in api/lib/ReferenceCosts.php, so it cannot drift
-- from what the sync would write by a transcription error. Regenerate after
-- changing that dataset (see the command in DEPLOY.md).
--
-- SAFE TO RE-RUN. Every row is an upsert on (category, subcategory), which is
-- the same ON DUPLICATE KEY UPDATE the sync itself uses. Running the CLI sync
-- later will simply rewrite the same rows.
--
-- WHAT THIS DOES *NOT* DO: the sync's PRUNE step, which deletes cached rows
-- the dataset no longer defines. That only matters when a row is REMOVED from
-- the dataset — on a first population, or when rows are only added, there is
-- nothing to prune. If you ever delete a row from the dataset, remove it here
-- by hand too.
--
-- *** Every living_cost row lands with is_verified = 0 on purpose. *** They
-- are transcriptions of MOSPI HCES 2023-24 published figures, not fetched from
-- a live source. The UI keeps labelling them unverified until a human checks
-- them against the fact sheet and flips the flag.
--
-- *** RUN sql/043 FIRST, or the living_cost rows below will all fail. ***
-- Migration 037 created `unit` as VARCHAR(20); the living-cost unit
-- ('inr_monthly_per_capita') is 22 characters, so MySQL rejects those 15 rows
-- outright with SQLSTATE[22001] "Data too long for column 'unit'". The widening
-- ALTER is repeated at the top of this file so pasting THIS ONE FILE is
-- sufficient — it is idempotent and harmless if 043 has already been applied.
-- ============================================================================

-- Prerequisite from sql/043 — see the note above. Safe to re-run.
ALTER TABLE reference_costs
    MODIFY COLUMN unit VARCHAR(32) NOT NULL
        COMMENT 'absolute-rupee unit only, never a multiplier: inr_total | inr_annual | inr_monthly_per_capita';

INSERT INTO reference_costs
  (category, subcategory, label, unit, low, high, source_name, source_url, as_of_date, is_verified, fetched_at)
VALUES
  ('education', 'government', 'Government seat (total course cost)', 'inr_total', 300000.00, 1000000.00, 'AICTE / NMC / state-counselling government-seat fee disclosures', NULL, '2025-01-01', 1, NOW()),
  ('education', 'private', 'Private institute (total course cost)', 'inr_total', 1500000.00, 4000000.00, 'AICTE / state-counselling private-seat fee disclosures', NULL, '2025-01-01', 1, NOW()),
  ('education', 'overseas', 'Overseas (total course + living cost)', 'inr_total', 5000000.00, 12000000.00, 'Illustrative overseas course + living-cost range — no single Indian regulator source', NULL, '2025-01-01', 0, NOW()),
  ('healthcare', 'ongoing_medical_annual', 'Ongoing medical cost for a household member with a chronic condition (annual, out-of-pocket)', 'inr_annual', 30000.00, 150000.00, 'Illustrative range — no single published index; verify against IRDAI health-claims data before relying on it', NULL, '2025-01-01', 0, NOW()),
  ('living_cost', 'all_india_rural', 'All India (average) — rural (average spend per person, per month)', 'inr_monthly_per_capita', 4122.00, 4122.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW()),
  ('living_cost', 'all_india_urban', 'All India (average) — urban (average spend per person, per month)', 'inr_monthly_per_capita', 6996.00, 6996.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW()),
  ('living_cost', 'sikkim_rural', 'Sikkim — rural (average spend per person, per month)', 'inr_monthly_per_capita', 9377.00, 9377.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW()),
  ('living_cost', 'sikkim_urban', 'Sikkim — urban (average spend per person, per month)', 'inr_monthly_per_capita', 13927.00, 13927.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW()),
  ('living_cost', 'kerala_rural', 'Kerala — rural (average spend per person, per month)', 'inr_monthly_per_capita', 6611.00, 6611.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW()),
  ('living_cost', 'punjab_rural', 'Punjab — rural (average spend per person, per month)', 'inr_monthly_per_capita', 5817.00, 5817.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW()),
  ('living_cost', 'tamil_nadu_rural', 'Tamil Nadu — rural (average spend per person, per month)', 'inr_monthly_per_capita', 5701.00, 5701.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW()),
  ('living_cost', 'tamil_nadu_urban', 'Tamil Nadu — urban (average spend per person, per month)', 'inr_monthly_per_capita', 8165.00, 8165.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW()),
  ('living_cost', 'telangana_rural', 'Telangana — rural (average spend per person, per month)', 'inr_monthly_per_capita', 5435.00, 5435.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW()),
  ('living_cost', 'telangana_urban', 'Telangana — urban (average spend per person, per month)', 'inr_monthly_per_capita', 8978.00, 8978.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW()),
  ('living_cost', 'karnataka_urban', 'Karnataka — urban (average spend per person, per month)', 'inr_monthly_per_capita', 8076.00, 8076.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW()),
  ('living_cost', 'maharashtra_urban', 'Maharashtra — urban (average spend per person, per month)', 'inr_monthly_per_capita', 7363.00, 7363.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW()),
  ('living_cost', 'andhra_pradesh_urban', 'Andhra Pradesh — urban (average spend per person, per month)', 'inr_monthly_per_capita', 7182.00, 7182.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW()),
  ('living_cost', 'chhattisgarh_rural', 'Chhattisgarh — rural (average spend per person, per month)', 'inr_monthly_per_capita', 2739.00, 2739.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW()),
  ('living_cost', 'chhattisgarh_urban', 'Chhattisgarh — urban (average spend per person, per month)', 'inr_monthly_per_capita', 4927.00, 4927.00, 'MOSPI, Household Consumption Expenditure Survey 2023-24 (fact sheet), average MPCE', 'https://www.mospi.gov.in/sites/default/files/publication_reports/HCES%20FactSheet%202023-24.pdf', '2024-12-27', 0, NOW())
ON DUPLICATE KEY UPDATE
  label=VALUES(label), unit=VALUES(unit), low=VALUES(low), high=VALUES(high),
  source_name=VALUES(source_name), source_url=VALUES(source_url),
  as_of_date=VALUES(as_of_date), is_verified=VALUES(is_verified), fetched_at=NOW();
