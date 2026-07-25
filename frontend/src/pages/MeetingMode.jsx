import { useEffect, useMemo, useState, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import DisclosureBanner from '../components/DisclosureBanner';
import SequenceRiskChart from '../components/SequenceRiskChart';
import { ReadinessScoreCard } from '../components/ReadinessScore';
import { ReplayYearSelect, UnverifiedHistoryNotice } from '../components/HistoricalReplay';
import { Spinner } from '../components/ui';
import { formatCurrency, formatPercent, GOAL_TYPE_LABELS } from '../lib/format';

// docs/07 Bet 5: Meeting Mode — a full-screen, advance-by-tap presentation
// assembled from components that already exist elsewhere in the app. This
// page has no endpoints or schema of its own; it's pure composition over
// goals_read.php / goals_projection.php, the same data GoalDetail and
// PlanReport already fetch. §3.6 disclosure renders on every slide, not just
// once, since each slide is its own client-facing view of the plan.
export default function MeetingMode() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { tenant } = useAuth();
  const [goal, setGoal] = useState(null);
  const [projection, setProjection] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [replayYear, setReplayYear] = useState(null);
  const [replayProjection, setReplayProjection] = useState(null);
  const [step, setStep] = useState(0);
  const [notesOpen, setNotesOpen] = useState(false);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError('');
    api.getGoal(id)
      .then((goalRes) => {
        if (cancelled) return null;
        setGoal(goalRes.goal);
        if (goalRes.goal.goal_type === 'retirement') {
          return api.getProjection(id).catch(() => null);
        }
        return null;
      })
      .then((projRes) => { if (!cancelled && projRes) setProjection(projRes); })
      .catch((err) => { if (!cancelled) setError(err.message || 'Could not load this goal.'); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [id]);

  // The historical-replay slide fetches its own projection (with
  // replay_start_year) only once a year is picked, separate from the
  // baseline projection above — same pattern PlanReport/ScenarioPanel use.
  useEffect(() => {
    if (!replayYear || !goal || goal.goal_type !== 'retirement') { setReplayProjection(null); return; }
    let cancelled = false;
    api.getProjection(id, undefined, replayYear)
      .then((res) => { if (!cancelled) setReplayProjection(res); })
      .catch(() => { if (!cancelled) setReplayProjection(null); });
    return () => { cancelled = true; };
  }, [id, goal, replayYear]);

  const isRetirement = goal?.goal_type === 'retirement';
  const hasProjection = isRetirement && projection != null;

  // Build the slide list once the data is in — non-retirement goals just get
  // the goal overview + inflation slides, since survival/replay/readiness
  // all depend on withdrawal_rate + drawdown_return_rate.
  const slides = useMemo(() => {
    if (!goal) return [];
    const list = [
      { key: 'goal', title: 'Your goal', notes: 'Confirm the numbers on screen match what the client expects before moving on.' },
      { key: 'inflation', title: 'What inflation does', notes: 'Let the cost-of-living jump land — pause here before continuing.' },
    ];
    if (hasProjection) {
      list.push({ key: 'survival', title: 'Will the corpus survive?', notes: 'Point out where the adverse line dips below zero, if it does.' });
      list.push({ key: 'replay', title: 'What a bad early decade does', notes: 'Pick a start year live — 2008 or 2020 usually land well.' });
      list.push({ key: 'readiness', title: 'Your readiness score', notes: 'Close on this number. It\'s what they should remember.' });
    }
    return list;
  }, [goal, hasProjection]);

  const goNext = useCallback(() => setStep((s) => Math.min(s + 1, slides.length - 1)), [slides.length]);
  const goPrev = useCallback(() => setStep((s) => Math.max(s - 1, 0)), []);

  useEffect(() => {
    function onKeyDown(e) {
      if (e.key === 'ArrowRight' || e.key === ' ') { e.preventDefault(); goNext(); }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); goPrev(); }
      else if (e.key === 'Escape') { navigate(`/goals/${id}`); }
    }
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [goNext, goPrev, navigate, id]);

  const firmName = tenant?.whiteLabel?.company_name || tenant?.companyName || 'HorizonPlan';
  const brandLogo = tenant?.whiteLabel?.logo_url || null;
  const currentSlide = slides[step];

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center" style={{ backgroundColor: 'var(--color-canvas)' }}>
        <Spinner label="Preparing meeting…" />
      </div>
    );
  }

  if (error || !goal) {
    return (
      <div className="min-h-screen flex flex-col items-center justify-center gap-4" style={{ backgroundColor: 'var(--color-canvas)' }}>
        <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error || 'Goal not found.'}</p>
        <button onClick={() => navigate(-1)} className="text-sm text-[var(--color-ink-2)] hover:text-[var(--color-ink)]">← Back</button>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex flex-col" style={{ backgroundColor: 'var(--color-canvas)' }}>
      {/* Header — branding + exit, always visible */}
      <header className="flex items-center justify-between px-6 py-4 border-b border-[var(--color-line)]" style={{ backgroundColor: 'var(--color-surface)' }}>
        <div className="flex items-center gap-2.5">
          {brandLogo ? (
            <img src={brandLogo} alt="" className="h-7 w-7 object-contain rounded" />
          ) : (
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
              <line x1="2" y1="15" x2="20" y2="15" stroke="var(--color-line-2)" strokeWidth="1.5" />
              <path d="M4 15 L11 6 L18 11" stroke="var(--color-teal)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
              <circle cx="18" cy="11" r="2" fill="var(--color-amber)" />
            </svg>
          )}
          <span className="text-sm font-semibold tracking-tight text-[var(--color-ink)]">{firmName}</span>
          <span className="text-sm text-[var(--color-ink-3)]">·</span>
          <span className="text-sm text-[var(--color-ink-2)]">{goal.goal_label}</span>
        </div>
        <div className="flex items-center gap-3">
          <button
            onClick={() => setNotesOpen((v) => !v)}
            className="text-xs font-medium text-[var(--color-ink-3)] hover:text-[var(--color-ink)] transition-colors"
            title="Speaking notes — only visible to you"
          >
            {notesOpen ? 'Hide notes' : 'Notes for you'}
          </button>
          <button
            onClick={() => navigate(`/goals/${id}`)}
            className="text-sm font-medium text-[var(--color-ink-2)] hover:text-[var(--color-ink)] transition-colors"
          >
            Exit (Esc)
          </button>
        </div>
      </header>

      {notesOpen && currentSlide && (
        <div className="px-6 py-2 text-xs" style={{ backgroundColor: 'var(--color-amber-soft)', color: 'var(--color-amber)' }}>
          <strong>Note:</strong> {currentSlide.notes}
        </div>
      )}

      {/* Slide area with tap zones */}
      <main className="relative flex-1 flex items-center justify-center px-6 py-10 overflow-y-auto">
        <button
          aria-label="Previous slide"
          onClick={goPrev}
          disabled={step === 0}
          className="absolute left-0 top-0 bottom-0 w-1/6 min-w-[3rem] cursor-w-resize disabled:cursor-default"
        />
        <button
          aria-label="Next slide"
          onClick={goNext}
          disabled={step === slides.length - 1}
          className="absolute right-0 top-0 bottom-0 w-1/6 min-w-[3rem] cursor-e-resize disabled:cursor-default"
        />

        <div className="w-full max-w-2xl">
          {currentSlide?.key === 'goal' && <GoalSlide goal={goal} />}
          {currentSlide?.key === 'inflation' && <InflationSlide goal={goal} />}
          {currentSlide?.key === 'survival' && <SurvivalSlide projection={projection} />}
          {currentSlide?.key === 'replay' && (
            <ReplaySlide
              projection={projection}
              replayYear={replayYear}
              onReplayYearChange={setReplayYear}
              replayProjection={replayProjection}
            />
          )}
          {currentSlide?.key === 'readiness' && <ReadinessSlide projection={projection} />}

          <div className="mt-6">
            <DisclosureBanner compact />
          </div>
        </div>
      </main>

      {/* Footer — step dots + prev/next */}
      <footer className="flex items-center justify-center gap-4 px-6 py-4 border-t border-[var(--color-line)]" style={{ backgroundColor: 'var(--color-surface)' }}>
        <button onClick={goPrev} disabled={step === 0} className="text-sm font-medium text-[var(--color-ink-2)] hover:text-[var(--color-ink)] disabled:opacity-30">
          ← Back
        </button>
        <div className="flex items-center gap-2">
          {slides.map((s, i) => (
            <button
              key={s.key}
              aria-label={s.title}
              onClick={() => setStep(i)}
              className="h-2 rounded-full transition-all"
              style={{
                width: i === step ? 20 : 8,
                backgroundColor: i === step ? 'var(--color-teal)' : 'var(--color-line-2)',
              }}
            />
          ))}
        </div>
        <button onClick={goNext} disabled={step === slides.length - 1} className="text-sm font-medium text-[var(--color-ink-2)] hover:text-[var(--color-ink)] disabled:opacity-30">
          Next →
        </button>
      </footer>
    </div>
  );
}

function SlideHeading({ children }) {
  return <h1 className="text-2xl sm:text-3xl font-semibold tracking-tight text-[var(--color-ink)] mb-6 text-center">{children}</h1>;
}

function GoalSlide({ goal }) {
  return (
    <div>
      <SlideHeading>{goal.goal_label}</SlideHeading>
      <div className="grid grid-cols-2 gap-6 text-center">
        <BigStat label={GOAL_TYPE_LABELS[goal.goal_type] || goal.goal_type} value={formatCurrency(goal.initial_net_worth)} sub="Starting corpus" />
        {goal.target_amount != null ? (
          <BigStat label="Goal" value={formatCurrency(goal.target_amount)} sub="Target amount" />
        ) : (
          <BigStat label="Horizon" value={`${goal.projection_horizon_years} yrs`} sub="Projection horizon" />
        )}
      </div>
    </div>
  );
}

function InflationSlide({ goal }) {
  // A concrete, plan-specific illustration: today's annual withdrawal (or a
  // round reference figure for non-retirement goals) compounded forward by
  // this goal's own inflation assumption — not an abstract textbook example.
  const base = goal.goal_type === 'retirement' && goal.withdrawal_rate
    ? goal.initial_net_worth * (goal.withdrawal_rate / 100)
    : 100000;
  const years = goal.projection_horizon_years || 20;
  const future = base * Math.pow(1 + goal.inflation_rate / 100, years);
  return (
    <div>
      <SlideHeading>What {formatPercent(goal.inflation_rate)} inflation does</SlideHeading>
      <div className="grid grid-cols-2 gap-6 text-center items-center">
        <BigStat label="Today" value={formatCurrency(base)} sub="Annual cost, in today's rupees" />
        <div className="text-2xl text-[var(--color-ink-3)]">→</div>
        <BigStat label={`In ${years} years`} value={formatCurrency(future)} sub="Same lifestyle, future rupees" accent />
      </div>
      <p className="mt-6 text-sm text-center text-[var(--color-ink-2)]">
        This is why the projection on the next slide inflates withdrawals every year — not just the plan's average.
      </p>
    </div>
  );
}

function SurvivalSlide({ projection }) {
  return (
    <div>
      <SlideHeading>Will the corpus survive?</SlideHeading>
      <p className="text-sm text-center text-[var(--color-ink-2)] mb-4">
        Both lines share the same average return. The dashed line front-loads the weak years.
      </p>
      <SequenceRiskChart steadySeries={projection.steady_return_series} adverseSeries={projection.adverse_sequence_series} />
    </div>
  );
}

function ReplaySlide({ projection, replayYear, onReplayYearChange, replayProjection }) {
  return (
    <div>
      <SlideHeading>What a bad early decade does</SlideHeading>
      <p className="text-sm text-center text-[var(--color-ink-2)] mb-4">
        This isn't a synthetic reorder — pick a real year and see what actually happened to markets from then on.
      </p>
      <div className="flex justify-center mb-4">
        <ReplayYearSelect value={replayYear} onChange={onReplayYearChange} />
      </div>
      {replayYear && replayProjection ? (
        <>
          <SequenceRiskChart
            steadySeries={projection.steady_return_series}
            adverseSeries={projection.adverse_sequence_series}
            historicalSeries={replayProjection.historical_sequence_series}
            historicalLabel={`Replay from ${replayYear}`}
          />
          {replayProjection.historical_replay_meta && !replayProjection.historical_replay_meta.is_verified && (
            <UnverifiedHistoryNotice />
          )}
        </>
      ) : (
        <p className="text-center text-sm text-[var(--color-ink-3)] py-8">Choose a year above to replay it live.</p>
      )}
    </div>
  );
}

function ReadinessSlide({ projection }) {
  return (
    <div>
      <SlideHeading>Your readiness score</SlideHeading>
      <div className="flex justify-center">
        <ReadinessScoreCard score={projection.readiness_score} />
      </div>
    </div>
  );
}

function BigStat({ label, value, sub, accent }) {
  return (
    <div>
      <div className="text-[11px] uppercase tracking-wider text-[var(--color-ink-3)] mb-1">{label}</div>
      <div className="tnum text-3xl font-semibold" style={{ color: accent ? 'var(--color-amber)' : 'var(--color-ink)' }}>{value}</div>
      {sub && <div className="text-xs text-[var(--color-ink-3)] mt-1">{sub}</div>}
    </div>
  );
}
