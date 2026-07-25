-- docs/07 Bet 2: historical sequence replay. Global reference data (not
-- tenant-scoped — market history isn't owned by any firm), so this table is
-- deliberately outside TenantScopedDb's ALLOWED_TABLES, same as
-- login_attempts/active_sessions/mfa_pending/password_resets.
--
-- *** is_verified = 0 on every seeded row below, ON PURPOSE. ***
-- This session's environment had outbound web access blocked (proxy policy),
-- so these Sensex/Nifty annual-return and CPI-inflation figures were seeded
-- from general training-knowledge approximation, NOT fetched from a live,
-- citable source (BSE/NSE/RBI/AMFI/MOSPI). Treat every row as illustrative
-- and directionally-plausible, not authoritative, until someone verifies it
-- against a real source and flips is_verified to 1. This mirrors exactly the
-- draft/approved posture already built for strategy templates (migration
-- 013) — unverified numbers can be browsed and used to demonstrate the
-- feature, but the UI must keep showing an "unverified" notice until this
-- flag is flipped, and this should happen before any real client meeting
-- uses the historical-replay chart to make a point that depends on the
-- specific numbers being right.
CREATE TABLE market_history (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    year                SMALLINT UNSIGNED NOT NULL,
    equity_return_pct   DECIMAL(6,2) NOT NULL COMMENT 'approx. annual total return, broad Indian equity index (Sensex/Nifty)',
    cpi_inflation_pct   DECIMAL(5,2) NOT NULL COMMENT 'approx. annual CPI inflation',
    source_note         VARCHAR(255) NULL,
    is_verified         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'seeded from unverified approximation — 0 until confirmed against an authoritative source',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_market_history_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO market_history (year, equity_return_pct, cpi_inflation_pct, source_note, is_verified) VALUES
(1994, 19.00, 10.20, 'Unverified approximation — needs review against BSE/RBI data', 0),
(1995, -19.00, 10.00, 'Unverified approximation — needs review against BSE/RBI data', 0),
(1996, -1.00, 9.00, 'Unverified approximation — needs review against BSE/RBI data', 0),
(1997, 11.00, 7.20, 'Unverified approximation — needs review against BSE/RBI data', 0),
(1998, -18.00, 13.20, 'Unverified approximation — needs review against BSE/RBI data', 0),
(1999, 67.00, 4.70, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2000, -21.00, 4.00, 'Unverified approximation — dot-com bust era, needs review against BSE/RBI data', 0),
(2001, -18.00, 3.80, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2002, 4.00, 4.30, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2003, 73.00, 3.80, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2004, 13.00, 3.80, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2005, 42.00, 4.20, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2006, 47.00, 5.80, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2007, 47.00, 6.40, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2008, -52.00, 8.30, 'Unverified approximation — global financial crisis, needs review against BSE/RBI data', 0),
(2009, 81.00, 10.90, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2010, 17.00, 12.00, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2011, -25.00, 8.90, 'Unverified approximation — European debt crisis, needs review against BSE/RBI data', 0),
(2012, 26.00, 9.30, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2013, 9.00, 10.90, 'Unverified approximation — taper tantrum, needs review against BSE/RBI data', 0),
(2014, 30.00, 6.70, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2015, -5.00, 4.90, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2016, 2.00, 4.50, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2017, 28.00, 3.60, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2018, 6.00, 3.40, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2019, 14.00, 4.80, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2020, 16.00, 6.20, 'Unverified approximation — COVID crash then recovery, needs review against BSE/RBI data', 0),
(2021, 22.00, 5.10, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2022, 4.00, 6.70, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2023, 18.00, 5.70, 'Unverified approximation — needs review against BSE/RBI data', 0),
(2024, 8.00, 4.90, 'Unverified approximation — needs review against BSE/RBI data', 0);
