import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from './AuthContext';
import { Button } from '../components/ui';
import Spotlight from '../components/Spotlight';

// Guided, self-paced click-through tour over the PUBLIC DEMO — coach marks
// over the real seeded UI (dashboard health badges, a real client's
// GoalsHealthSummary card, a real goal's readiness score, Meeting Mode),
// never a canned screenshot/video. Scoped ONLY to demo-login sessions
// (user.isDemo, derived server-side from the *.demo.horizonplan.in email
// domain — see api/lib/DemoAccess.php / session.php) so it can never nag a
// real admin-created or trial-signup account. Deliberately NOT a generic
// reusable tour framework for other pages — see CLAUDE.md for why.
const DemoTourContext = createContext(null);

const STORAGE_KEY = 'hp_demo_tour_v1';

function loadState() {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

function saveState(state) {
  try {
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  } catch {
    // Private-browsing storage quirks aren't worth failing the tour over —
    // it just won't survive a reload in that case.
  }
}

const CLIENT_RE = /^\/clients\/[^/]+$/;
const GOAL_DETAIL_RE = /^\/goals\/[^/]+$/;
const MEETING_RE = /^\/goals\/[^/]+\/meeting$/;

// Each step targets one real, already-shipped element (via the data-tour
// attributes added to Dashboard.jsx/ClientGoals.jsx/GoalCard.jsx/
// GoalDetail.jsx/MeetingMode.jsx) — never synthetic tour-only markup.
// `onAdvance` only appears on steps that hand off to a different page; it
// reads a real client/goal id off the highlighted element itself, so the
// tour always follows an actual seeded record rather than a hardcoded one.
// Shared by both dashboard steps below so they always point at the SAME
// client — a well-populated one, not just whichever row the current sort
// happens to render first — so the rest of the tour (goals summary, a scored
// goal card, a readiness score) has real data to show once the visitor
// drills in.
function pickBestClientRow(root) {
  const rows = Array.from(root.querySelectorAll('[data-tour="dashboard-client-row"]'));
  if (rows.length === 0) return null;
  return rows.reduce((best, row) => (
    Number(row.dataset.goalCount || 0) > Number(best.dataset.goalCount || 0) ? row : best
  ), rows[0]);
}

const STEPS = [
  {
    key: 'dashboard-health',
    match: (p) => p === '/',
    resolveElement: (root) => pickBestClientRow(root)?.querySelector('[data-tour-badges="true"]') || null,
    title: 'At-a-glance client health',
    body: 'Every client row carries a readiness score and risk band — you can tell who needs a call without opening a single plan.',
  },
  {
    key: 'dashboard-filter',
    match: (p) => p === '/',
    selector: '[data-tour="dashboard-attention-filter"]',
    title: '"Needs attention" filter',
    body: 'Flip this on and the book narrows to clients with no goals, no risk profile, or a struggling plan — instantly.',
  },
  {
    key: 'dashboard-client',
    match: (p) => p === '/',
    resolveElement: pickBestClientRow,
    title: 'Drill into a client',
    body: "Open a client to see their full plan — every goal, portfolio, and risk profile in one place. Click the row, or hit Next.",
    onAdvance: (el, navigate) => {
      const id = el?.dataset.clientId;
      if (id) navigate(`/clients/${id}`);
    },
  },
  {
    key: 'client-summary',
    match: (p) => CLIENT_RE.test(p),
    selector: '[data-tour="goals-health-summary"]',
    title: 'Client overview, condensed',
    body: "Goal count, tracked corpus, and this client's lowest-scoring goal, in one glance.",
  },
  {
    key: 'client-goal-card',
    match: (p) => CLIENT_RE.test(p),
    selector: '[data-tour="goal-card"]',
    resolveElement: (root) => {
      const cards = Array.from(root.querySelectorAll('[data-tour="goal-card"]'));
      return cards.find((c) => c.dataset.hasReadiness === 'true') || cards[0] || null;
    },
    title: 'Open a goal',
    body: 'Every retirement goal carries its own readiness score and scenario tools. Open one to see the full picture.',
    onAdvance: (el, navigate) => {
      const id = el?.dataset.goalId;
      if (id) navigate(`/goals/${id}`);
    },
  },
  {
    key: 'goal-readiness',
    match: (p) => GOAL_DETAIL_RE.test(p),
    selector: '[data-tour="readiness-score-card"]',
    title: 'The number a client can hold onto',
    body: 'One 0–100 score blending steady- and adverse-sequence survival with a corpus-multiple check. "How is this calculated?" shows the full formula.',
  },
  {
    key: 'goal-meeting',
    match: (p) => GOAL_DETAIL_RE.test(p),
    selector: '[data-tour="meeting-mode-button"]',
    title: 'What the client actually sees',
    body: 'Meeting Mode turns this same plan into a full-screen, presentation-ready walkthrough for a live client conversation.',
    onAdvance: (el, navigate, location) => {
      navigate(`${location.pathname}/meeting`);
    },
  },
  {
    key: 'meeting-closing',
    match: (p) => MEETING_RE.test(p),
    selector: '[data-tour="meeting-mode-slide"]',
    title: "That's the tour",
    body: "Arrow keys (or the edge tap-zones) move slides. Explore the rest of this firm's book freely — or head back and sign up to build your own.",
    isLast: true,
  },
];

export function DemoTourProvider({ children }) {
  const { user, loading } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();

  const [stepIndex, setStepIndex] = useState(0);
  const [tourActive, setTourActive] = useState(false);
  const [dismissed, setDismissed] = useState(false);
  const [rect, setRect] = useState(null);
  const targetElRef = useRef(null);
  const initializedRef = useRef(false);

  // One-time init per browser tab — but the "once" only counts once we
  // actually have a demo user. On first page load (before demo-login
  // completes) user is null, and consuming the guard there would mean the
  // effect never re-evaluates once login succeeds and user.isDemo flips
  // true a few renders later. So the guard is only set once user.isDemo is
  // actually true — not on every loading===false render.
  useEffect(() => {
    if (loading || !user?.isDemo || initializedRef.current) return;
    initializedRef.current = true;
    const saved = loadState();
    if (saved?.dismissed) {
      setDismissed(true);
    } else if (saved?.active) {
      setStepIndex(saved.stepIndex ?? 0);
      setTourActive(true);
    } else if (!saved) {
      // First time this demo session has loaded the app in this tab.
      setTourActive(true);
      setStepIndex(0);
    }
  }, [loading, user?.isDemo]);

  useEffect(() => {
    if (!user?.isDemo) return;
    saveState({ active: tourActive, dismissed, stepIndex });
  }, [tourActive, dismissed, stepIndex, user?.isDemo]);

  // Keep the step index in sync with real navigation — including a visitor
  // clicking the actual highlighted element instead of "Next" (the overlay
  // deliberately lets clicks pass through to the real page, see below).
  useEffect(() => {
    if (!tourActive) return;
    const current = STEPS[stepIndex];
    if (current && current.match(location.pathname)) return;
    const matchIndex = STEPS.findIndex((s) => s.match(location.pathname));
    if (matchIndex !== -1) {
      setStepIndex(matchIndex);
    } else {
      targetElRef.current = null;
      setRect(null);
    }
  }, [location.pathname, tourActive, stepIndex]);

  // Locate + track the current step's target element. Polls briefly after a
  // navigation since the destination page's own data fetch (goals,
  // projection) hasn't necessarily resolved yet — the element this step
  // highlights doesn't exist in the DOM until that finishes.
  useEffect(() => {
    if (!tourActive) return;
    const step = STEPS[stepIndex];
    if (!step || !step.match(location.pathname)) return;

    let cancelled = false;
    let attempts = 0;
    let timer = null;

    function updateRect() {
      const el = targetElRef.current;
      if (!el || !document.contains(el)) return;
      setRect(el.getBoundingClientRect());
    }

    function tryFind() {
      if (cancelled) return;
      const el = step.resolveElement
        ? step.resolveElement(document)
        : document.querySelector(step.selector);
      if (el) {
        targetElRef.current = el;
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        updateRect();
        return;
      }
      attempts += 1;
      if (attempts < 40) {
        timer = setTimeout(tryFind, 150);
      }
    }

    targetElRef.current = null;
    setRect(null);
    tryFind();

    function onScrollOrResize() {
      requestAnimationFrame(updateRect);
    }
    window.addEventListener('scroll', onScrollOrResize, true);
    window.addEventListener('resize', onScrollOrResize);
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
      window.removeEventListener('scroll', onScrollOrResize, true);
      window.removeEventListener('resize', onScrollOrResize);
    };
  }, [tourActive, stepIndex, location.pathname]);

  const finish = useCallback(() => {
    setTourActive(false);
    setDismissed(true);
  }, []);

  const goNext = useCallback(() => {
    const step = STEPS[stepIndex];
    if (!step) return;
    if (step.isLast) {
      finish();
      return;
    }
    step.onAdvance?.(targetElRef.current, navigate, location);
    setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
  }, [stepIndex, navigate, location, finish]);

  const goBack = useCallback(() => {
    setStepIndex((i) => Math.max(i - 1, 0));
  }, []);

  const restart = useCallback(() => {
    setDismissed(false);
    setTourActive(true);
    setStepIndex(0);
    if (location.pathname !== '/') navigate('/');
  }, [navigate, location.pathname]);

  const value = useMemo(() => ({
    available: !!user?.isDemo,
    dismissed,
    active: tourActive,
    restart,
  }), [user?.isDemo, dismissed, tourActive, restart]);

  const step = tourActive ? STEPS[stepIndex] : null;

  return (
    <DemoTourContext.Provider value={value}>
      {children}
      {step && rect && (
        <TourOverlay
          step={step}
          stepNumber={stepIndex + 1}
          totalSteps={STEPS.length}
          rect={rect}
          onNext={goNext}
          onBack={stepIndex > 0 ? goBack : null}
          onSkip={finish}
        />
      )}
    </DemoTourContext.Provider>
  );
}

export function useDemoTour() {
  const ctx = useContext(DemoTourContext);
  if (!ctx) throw new Error('useDemoTour must be used within DemoTourProvider');
  return ctx;
}

function TourOverlay({ step, stepNumber, totalSteps, rect, onNext, onBack, onSkip }) {
  return (
    <Spotlight rect={rect} cardWidth={320} estCardHeight={190}>
      <div className="flex items-center justify-between mb-2">
        <span className="text-[11px] font-semibold uppercase tracking-wide text-[var(--color-teal-ink)]">
          Guided tour · {stepNumber} of {totalSteps}
        </span>
        <button
          type="button"
          onClick={onSkip}
          className="text-xs font-medium text-[var(--color-ink-3)] hover:text-[var(--color-ink)] transition-colors"
        >
          Skip
        </button>
      </div>
      <h3 className="text-sm font-semibold text-[var(--color-ink)] mb-1">{step.title}</h3>
      <p className="text-xs leading-relaxed text-[var(--color-ink-2)] mb-4">{step.body}</p>
      <div className="flex items-center justify-between gap-2">
        {onBack ? (
          <Button type="button" variant="ghost" size="sm" onClick={onBack}>Back</Button>
        ) : <span />}
        <Button type="button" variant="teal" size="sm" onClick={onNext}>
          {step.isLast ? 'Done' : 'Next'}
        </Button>
      </div>
    </Spotlight>
  );
}
