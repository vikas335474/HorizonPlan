// Shared spotlight rendering — a darkened backdrop with a cut-out around one
// real DOM element (via CSS box-shadow, no SVG mask) plus a positioned
// callout card. Pointer-events are disabled everywhere except the callout
// itself, so the real highlighted element underneath stays genuinely
// clickable while a spotlight is up.
//
// Extracted from DemoTourContext.jsx's TourOverlay (docs/09's guided demo
// tour) so the onboarding spotlight (components/OnboardingSpotlight.jsx)
// can reuse the same box-shadow-cutout technique without a second
// implementation — both now render through this component, each supplying
// their own callout content as children.
export default function Spotlight({ rect, pad = 8, cardWidth = 320, estCardHeight = 100, children }) {
  const spot = {
    top: rect.top - pad,
    left: rect.left - pad,
    width: rect.width + pad * 2,
    height: rect.height + pad * 2,
  };

  // Prefer placing the callout below the target; flip above if there isn't
  // room, and clamp horizontally so it never runs off-screen.
  const spaceBelow = window.innerHeight - spot.top - spot.height;
  const placeAbove = spaceBelow < estCardHeight + 24 && spot.top > estCardHeight + 24;
  const cardTop = placeAbove ? Math.max(16, spot.top - estCardHeight - 16) : spot.top + spot.height + 16;
  const cardLeft = Math.min(Math.max(16, spot.left), window.innerWidth - cardWidth - 16);

  return (
    <div className="fixed inset-0 z-[70]" style={{ pointerEvents: 'none' }} aria-live="polite">
      <div
        className="absolute rounded-xl transition-all duration-200 ease-out"
        style={{
          top: spot.top,
          left: spot.left,
          width: spot.width,
          height: spot.height,
          boxShadow: '0 0 0 9999px rgba(15, 23, 41, 0.6)',
          outline: '2px solid var(--color-teal)',
          outlineOffset: 2,
        }}
      />
      <div
        className="absolute rounded-[var(--radius-card)] border border-[var(--color-line)] bg-[var(--color-surface)] p-3 animate-[popIn_0.18s_cubic-bezier(0.34,1.56,0.64,1)]"
        style={{ top: cardTop, left: cardLeft, width: cardWidth, boxShadow: 'var(--shadow-lg)', pointerEvents: 'auto' }}
      >
        {children}
      </div>
    </div>
  );
}
