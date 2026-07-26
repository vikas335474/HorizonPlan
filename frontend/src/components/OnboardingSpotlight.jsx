import { useEffect, useState } from 'react';
import Spotlight from './Spotlight';

// A single-target coach mark that sits alongside OnboardingChecklist.jsx,
// not a replacement for it: the checklist tracks what's left, this points
// at the one real button to click next. Scoped to Dashboard.jsx only (the
// only place the checklist renders), so unlike DemoTourContext.jsx this
// needs no cross-page navigation tracking — just one step, one element,
// re-resolved whenever the active step changes.
//
// 'client' targets the header's real "Add client" button (the one an
// advisor will keep using after onboarding, not a duplicate onboarding-only
// button) — 'goal' targets the checklist's own "Go to client" button, since
// that action only exists inside the checklist itself. Advancing past both
// (or dismissing) hides it; OnboardingChecklist.jsx's own visibility
// condition already guarantees this never needs a 'report' step, since the
// checklist itself stops rendering once the advisor has both a client and
// a goal.
const STEP_CONFIG = {
  client: {
    selector: '[data-tour="onboarding-add-client"]',
    text: 'Start here — add your first client.',
  },
  goal: {
    selector: '[data-tour="onboarding-goal-cta"]',
    text: 'Now create a goal for them.',
  },
};

export default function OnboardingSpotlight({ step, onDismiss }) {
  const [rect, setRect] = useState(null);

  useEffect(() => {
    if (!step) {
      setRect(null);
      return;
    }
    const config = STEP_CONFIG[step];
    let cancelled = false;
    let attempts = 0;
    let timer = null;

    function update() {
      const el = document.querySelector(config.selector);
      if (!el) return false;
      setRect(el.getBoundingClientRect());
      return true;
    }

    function tryFind() {
      if (cancelled) return;
      if (update()) return;
      attempts += 1;
      if (attempts < 20) {
        timer = setTimeout(tryFind, 150);
      }
    }
    tryFind();

    function onScrollOrResize() {
      requestAnimationFrame(update);
    }
    window.addEventListener('resize', onScrollOrResize);
    window.addEventListener('scroll', onScrollOrResize, true);
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
      window.removeEventListener('resize', onScrollOrResize);
      window.removeEventListener('scroll', onScrollOrResize, true);
    };
  }, [step]);

  if (!step || !rect) return null;

  return (
    <Spotlight rect={rect} cardWidth={230} estCardHeight={56}>
      <div className="flex items-start justify-between gap-2">
        <p className="text-xs font-medium text-[var(--color-ink)] leading-snug">
          {STEP_CONFIG[step].text}
        </p>
        <button
          type="button"
          onClick={onDismiss}
          aria-label="Dismiss guidance"
          className="shrink-0 text-sm leading-none text-[var(--color-ink-3)] hover:text-[var(--color-ink)]"
        >
          ×
        </button>
      </div>
    </Spotlight>
  );
}
