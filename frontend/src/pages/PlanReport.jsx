// Route /goals/:id/report — the printable / shareable single-goal client report.
// Read-only; renders api.getProjection output plus the compliance
// DisclosureBanner, laid out for print. Shared by advisor and client.

import { useEffect, useRef, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import DisclosureBanner from '../components/DisclosureBanner';
import SequenceRiskChart from '../components/SequenceRiskChart';
import ProgressChart from '../components/ProgressChart';
import { ReadinessScoreCard } from '../components/ReadinessScore';
import { ReplayYearSelect, UnverifiedHistoryNotice } from '../components/HistoricalReplay';
import { Spinner } from '../components/ui';
import {
  formatCurrency,
  formatPercent,
  formatDate,
  GOAL_TYPE_LABELS,
} from '../lib/format';

// A clean, print-optimised single-goal view — the artefact an advisor actually
// shows or hands to a client in a meeting (docs/04 Phase 1: "a basic
// shareable/printable view of a single goal's scenario, now including the
// projection chart"). Reuses the same goals_read + goals_projection endpoints
// and the SequenceRiskChart the interactive view uses, so the numbers match.
export default function PlanReport() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { tenant } = useAuth();
  const [goal, setGoal] = useState(null);
  const [projection, setProjection] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [replayYear, setReplayYear] = useState(null);
  // P1-1 · Recorded progress history for this goal. Loaded independently of
  // the projection and soft-failing: a report must still print if progress
  // has never been captured (the common case for a new plan).
  const [progress, setProgress] = useState(null);
  const [downloading, setDownloading] = useState(false);
  const [downloadError, setDownloadError] = useState('');
  const reportRef = useRef(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError('');
    api
      .getGoal(id)
      .then((goalRes) => {
        if (cancelled) return null;
        setGoal(goalRes.goal);
        // Projection only exists for retirement goals with the required rates;
        // swallow its error so a non-retirement goal still renders its report.
        if (goalRes.goal.goal_type === 'retirement') {
          return api.getProjection(id, undefined, replayYear).catch(() => null);
        }
        return null;
      })
      .then((projRes) => {
        if (!cancelled && projRes) setProjection(projRes);
      })
      .catch((err) => {
        if (!cancelled) setError(err.message || 'Could not load this plan.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, [id, replayYear]);

  useEffect(() => {
    let cancelled = false;
    api
      .getGoalProgress(id)
      .then((res) => { if (!cancelled) setProgress(res); })
      .catch(() => { if (!cancelled) setProgress(null); });
    return () => { cancelled = true; };
  }, [id]);

  // "Download PDF" produces an actual saved file (jsPDF/html2canvas
  // rasterise the same reportRef DOM the screen shows) rather than relying on
  // the browser's own print-to-PDF driver — the latter is the "Print" button
  // below, kept because it's still the faster path at a desk with a real
  // printer. Both render identical content; neither has a second data source.
  async function handleDownload() {
    if (!reportRef.current || downloading) return;
    setDownloading(true);
    setDownloadError('');
    try {
      // Dynamically imported: jsPDF + html2canvas are a non-trivial chunk
      // (~180kB gzipped) that most page loads never need — only someone who
      // actually clicks "Download PDF" should pay for fetching it.
      const { downloadElementAsPdf } = await import('../lib/pdfExport');
      const filename = goal ? `${goal.goal_label.replace(/[^\w\- ]+/g, '').trim() || 'plan-report'}` : 'plan-report';
      await downloadElementAsPdf(reportRef.current, filename);
    } catch (err) {
      setDownloadError(err.message || 'Could not generate the PDF. Try Print / Save as PDF instead.');
    } finally {
      setDownloading(false);
    }
  }

  const firmName = tenant?.whiteLabel?.company_name || tenant?.companyName || 'HorizonPlan';
  // Use the firm's own uploaded logo on the leave-behind when they've set one
  // (Settings → Firm branding); fall back to the HorizonPlan mark otherwise.
  const brandLogo = tenant?.whiteLabel?.logo_url || null;
  const isRetirement = goal?.goal_type === 'retirement';

  return (
    <div className="min-h-screen" style={{ backgroundColor: 'var(--color-canvas)' }}>
      {/* Toolbar — hidden when printing */}
      <div className="no-print sticky top-0 z-10 surface-glass border-b border-[var(--color-line)]">
        <div className="mx-auto max-w-3xl px-5 h-14 flex items-center justify-between">
          <button
            onClick={() => navigate(-1)}
            className="text-sm text-[var(--color-ink-2)] hover:text-[var(--color-ink)]"
          >
            ← Back to plan
          </button>
          <div className="flex items-center gap-2">
            {goal?.goal_type === 'retirement' && (
              <ReplayYearSelect value={replayYear} onChange={setReplayYear} className="text-xs py-1.5" />
            )}
            <button
              onClick={handleDownload}
              disabled={downloading || !goal}
              className="rounded-[var(--radius-ctrl)] px-3.5 py-1.5 text-sm font-medium disabled:opacity-50"
              style={{ border: '1px solid var(--color-line-2)', color: 'var(--color-ink)' }}
            >
              {downloading ? 'Preparing PDF…' : 'Download PDF'}
            </button>
            <button
              onClick={() => window.print()}
              className="rounded-[var(--radius-ctrl)] px-3.5 py-1.5 text-sm font-medium text-white"
              style={{ background: 'var(--grad-ink, var(--color-ink))' }}
            >
              Print / Save as PDF
            </button>
          </div>
        </div>
        {downloadError && (
          <div className="mx-auto max-w-3xl px-5 pb-2">
            <p className="text-xs" style={{ color: 'var(--color-alert)' }}>{downloadError}</p>
          </div>
        )}
      </div>

      <main ref={reportRef} className="report-sheet mx-auto max-w-3xl px-6 py-8">
        {loading && <Spinner label="Preparing report…" />}
        {error && (
          <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
        )}

        {goal && (
          <>
            {/* Letterhead */}
            <header className="flex items-baseline justify-between border-b border-[var(--color-line-2)] pb-4">
              <div className="flex items-center gap-2">
                {brandLogo ? (
                  <img src={brandLogo} alt="" className="h-6 w-auto max-w-[150px] object-contain" />
                ) : (
                  <svg width="20" height="20" viewBox="0 0 22 22" fill="none" aria-hidden="true">
                    <line x1="2" y1="15" x2="20" y2="15" stroke="var(--color-line-2)" strokeWidth="1.5" />
                    <path d="M4 15 L11 6 L18 11" stroke="var(--color-teal)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
                    <circle cx="18" cy="11" r="2" fill="var(--color-amber)" />
                  </svg>
                )}
                <span className="text-sm font-semibold tracking-tight text-[var(--color-ink)]">{firmName}</span>
              </div>
              <span className="text-xs text-[var(--color-ink-3)]">Prepared {formatDate(new Date().toISOString())}</span>
            </header>

            {/* Title */}
            <div className="mt-6">
              <div className="text-xs uppercase tracking-wider text-[var(--color-ink-3)]">
                {GOAL_TYPE_LABELS[goal.goal_type] || goal.goal_type} · {goal.projection_horizon_years}-year horizon
              </div>
              <h1 className="mt-1 text-2xl font-semibold tracking-tight text-[var(--color-ink)]">
                {goal.goal_label}
              </h1>
            </div>

            {/* Key figures */}
            <section className="mt-6 grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-5">
              <Figure label="Starting corpus" value={formatCurrency(goal.initial_net_worth)} />
              {goal.target_amount !== null && <Figure label="Target amount" value={formatCurrency(goal.target_amount)} />}
              {goal.target_date && <Figure label="Target date" value={formatDate(goal.target_date)} />}
              <Figure label="Inflation assumption" value={formatPercent(goal.inflation_rate)} />
              {isRetirement && goal.withdrawal_rate !== null && (
                <Figure
                  label="Withdrawal rate"
                  value={formatPercent(goal.withdrawal_rate)}
                  sub={goal.corpus_multiple ? `≈ ${goal.corpus_multiple}× annual expenses` : null}
                />
              )}
              {isRetirement && goal.drawdown_return_rate !== null && (
                <Figure label="Post-retirement return" value={formatPercent(goal.drawdown_return_rate)} />
              )}
            </section>

            {/* Retirement Readiness Score (docs/07 Bet 3) — "the number a
                client can hold onto" after the meeting ends, prominent in
                the artefact that actually gets handed to them. */}
            {isRetirement && projection?.readiness_score != null && (
              <section className="mt-6">
                <ReadinessScoreCard score={projection.readiness_score} />
              </section>
            )}

            {/* Projection */}
            {isRetirement && projection && (
              <section className="mt-8">
                <h2 className="text-base font-semibold text-[var(--color-ink)]">
                  Will the corpus last? Steady vs. adverse-sequence
                </h2>
                <p className="mt-1 mb-3 text-sm text-[var(--color-ink-2)] leading-relaxed">
                  Both lines assume the <strong>same average return</strong>. The dashed line front-loads the
                  weak years — showing how the <em>order</em> of returns, not just the average, decides whether
                  the money outlasts the plan.
                </p>
                <SequenceRiskChart
                  steadySeries={projection.steady_return_series}
                  adverseSeries={projection.adverse_sequence_series}
                  historicalSeries={projection.historical_sequence_series}
                  historicalLabel={replayYear ? `Replay from ${replayYear}` : undefined}
                />
                {projection.historical_replay_meta && !projection.historical_replay_meta.is_verified && (
                  <UnverifiedHistoryNotice />
                )}
              </section>
            )}

            {/* P1-1 · Progress over time. Included in the leave-behind because
                a review conversation is exactly where "how have we actually
                tracked against this plan" belongs. Omitted entirely when
                fewer than two readings exist — a single point is a position,
                not a trend, and a one-dot chart in a printed report reads as
                a mistake. */}
            {progress && progress.points.length > 1 && (
              <section className="mt-8">
                <h2 className="text-base font-semibold text-[var(--color-ink)]">
                  {progress.tracking_mode === 'coverage'
                    ? 'How much of this goal is funded, over time'
                    : 'Progress against the plan'}
                </h2>
                <p className="mt-1 mb-3 text-sm text-[var(--color-ink-2)] leading-relaxed">
                  {progress.tracking_mode === 'coverage'
                    ? 'The share of this goal\'s inflation-adjusted cost already covered, on each date it was recorded. Assumes no growth on what is saved.'
                    : 'What the corpus actually was on each date it was recorded, against what this plan expected on those dates.'}
                </p>
                <ProgressChart
                  points={progress.points}
                  mode={progress.tracking_mode}
                  manualLabel={tenant?.kind === 'personal' ? 'Recorded by you' : 'Recorded by your adviser'}
                />
              </section>
            )}

            {/* Disclosure — required on every client-facing view (docs/02 3.6) */}
            <div className="mt-8">
              <DisclosureBanner />
            </div>
          </>
        )}
      </main>
    </div>
  );
}

function Figure({ label, value, sub }) {
  return (
    <div>
      <div className="text-[11px] uppercase tracking-wider text-[var(--color-ink-3)]">{label}</div>
      <div className="tnum text-lg font-semibold text-[var(--color-ink)] mt-1">{value}</div>
      {sub && <div className="text-[11px] text-[var(--color-ink-3)] mt-0.5">{sub}</div>}
    </div>
  );
}
