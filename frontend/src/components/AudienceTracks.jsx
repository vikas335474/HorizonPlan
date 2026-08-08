// The unauthenticated entry point's audience split.
//
// THE PROBLEM THIS SOLVES. /login was a single-audience page: the hero read
// "Retirement plans YOUR CLIENTS can actually feel", all five feature bullets
// were advisor features, and the only way in for someone planning for
// themselves was a 12px button with a 10px caption, ranked BELOW a grid of firm
// demos. HorizonPlan serves two audiences (sql/033 added the self-serve tier),
// and one of them arrived to find a B2B sign-in box and no evidence the product
// was for them. That is an information-architecture problem, not a styling one,
// so the fix is to make the audience the FIRST thing the page resolves.
//
// Design decisions worth keeping:
//
//   * Two tracks of EQUAL visual weight. Ranking one above the other is what
//     made the consumer path invisible, and a visitor knows which one they are
//     faster than any copy can explain.
//   * The returning-user login form stays primary on the right. This block is
//     for people who have no account yet; burying sign-in behind an audience
//     question would tax every returning user to help a first-time one.
//   * Consumer copy avoids the vocabulary the advisor product uses. "Corpus",
//     "decumulation" and "sequence-of-returns risk" are precise and correct and
//     mean nothing to someone asking "when can I stop working?".
//   * Illustrations are INLINE SVG, not images. The deploy is static files on
//     Hostinger with no asset pipeline for art, and an external URL would be a
//     network dependency on the one page that must always render. Inline SVG is
//     crisp at any size, themes with the palette, and costs no request.
//   * The figures are deliberately abstract — geometric head-and-shoulder
//     forms, no faces, no skin tone, no gender signal beyond silhouette count
//     (one person / two people / a person with a chart). Stock-photo personas
//     would tell an Indian MFD's client base that the product was built for
//     someone else.

import { Link } from 'react-router-dom';

// --- persona illustrations --------------------------------------------------
// One shared 64x64 frame so the three sit on the same optical baseline.
// `tone` picks the accent so each track reads as its own thing.

function Figure({ cx = 32, cy = 26, r = 9, tone }) {
  return (
    <>
      <circle cx={cx} cy={cy} r={r} fill={tone} opacity="0.9" />
      <path
        d={`M${cx - r - 5} 56 C ${cx - r - 5} ${cy + r + 2}, ${cx + r + 5} ${cy + r + 2}, ${cx + r + 5} 56 Z`}
        fill={tone}
        opacity="0.55"
      />
    </>
  );
}

export function PersonaAvatar({ kind, size = 64 }) {
  const teal = 'var(--color-teal)';
  const amber = 'var(--color-amber)';

  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 64 64"
      fill="none"
      aria-hidden="true"
      className="shrink-0"
    >
      <circle cx="32" cy="32" r="31" fill="var(--color-surface-2)" />

      {kind === 'individual' && (
        <>
          <Figure tone={teal} />
          {/* the rising line their plan is about */}
          <path d="M14 48 L26 40 L38 44 L50 30" stroke={amber} strokeWidth="2.2"
            strokeLinecap="round" strokeLinejoin="round" fill="none" opacity="0.9" />
        </>
      )}

      {kind === 'couple' && (
        <>
          <Figure cx={23} cy={25} r={7.5} tone={teal} />
          <Figure cx={43} cy={25} r={7.5} tone={amber} />
        </>
      )}

      {kind === 'adviser' && (
        <>
          <Figure cx={26} cy={24} r={8} tone={teal} />
          {/* a chart being shown to someone — the meeting, not the spreadsheet */}
          <rect x="38" y="26" width="18" height="14" rx="2" fill="var(--color-surface)"
            stroke={teal} strokeWidth="1.6" />
          <path d="M41 36 L45 31 L48 33 L53 29" stroke={amber} strokeWidth="1.8"
            strokeLinecap="round" strokeLinejoin="round" fill="none" />
        </>
      )}
    </svg>
  );
}

// --- one track -------------------------------------------------------------

// Full-width and stacked, never side by side. The sign-in column is ~400px, so
// two cards across left every line wrapping after two or three words ("Plan
// your / own / retirement") — which reads as broken rather than compact. One
// card per row gives each track a real measure and lets the persona art be big
// enough to actually read.
function Track({ kind, eyebrow, title, blurb, children }) {
  return (
    <div
      className="rounded-[var(--radius-card)] border p-4"
      style={{ borderColor: 'var(--color-line-2)', backgroundColor: 'var(--color-surface)' }}
    >
      <div className="flex items-start gap-3.5">
        <PersonaAvatar kind={kind} size={56} />
        <div className="min-w-0 flex-1">
          <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-[var(--color-ink-3)]">
            {eyebrow}
          </p>
          <h3 className="mt-0.5 text-[16px] font-semibold leading-snug tracking-[-0.01em] text-[var(--color-ink)]">
            {title}
          </h3>
          <p className="mt-1.5 text-[13px] leading-relaxed text-[var(--color-ink-2)]">{blurb}</p>
          <div className="mt-3 flex flex-col gap-2">{children}</div>
        </div>
      </div>
    </div>
  );
}

function TrackButton({ onClick, to, variant = 'quiet', children, disabled, className = '' }) {
  const base =
    `${className} min-w-[8rem] rounded-[var(--radius-ctrl)] px-3 py-2 text-center text-[13px] font-medium transition-colors disabled:opacity-60`;
  const style =
    variant === 'solid'
      ? { backgroundColor: 'var(--color-teal)', color: '#fff' }
      : { backgroundColor: 'var(--color-surface-2)', color: 'var(--color-ink)', border: '1px solid var(--color-line-2)' };

  if (to) {
    return (
      <Link to={to} className={base} style={style}>
        {children}
      </Link>
    );
  }
  return (
    <button type="button" onClick={onClick} disabled={disabled} className={base} style={style}>
      {children}
    </button>
  );
}

/**
 * The two-track "new here?" block.
 *
 * @param firms       demo firms from api.listDemoFirms (may be empty)
 * @param busySlug    '' | firm slug | '__personal' — which demo is opening
 * @param onFirm      (slug) => void
 * @param onPersonal  () => void
 * @param error       message from a failed demo attempt
 */
export default function AudienceTracks({ firms = [], busySlug = '', onFirm, onPersonal, error }) {
  const opening = busySlug !== '';
  const firmOpening = opening && busySlug !== '__personal';

  return (
    <div className="mt-6">
      <div className="mb-3 flex items-center gap-3">
        <div className="h-px flex-1" style={{ backgroundColor: 'var(--color-line)' }} />
        <span className="text-[11px] font-medium uppercase tracking-[0.08em] text-[var(--color-ink-3)]">
          New to HorizonPlan?
        </span>
        <div className="h-px flex-1" style={{ backgroundColor: 'var(--color-line)' }} />
      </div>

      <div className="grid gap-3">
        {/* Consumer track FIRST — the audience that previously could not find
            itself on this page at all. */}
        <Track
          kind="individual"
          eyebrow="For yourself"
          title="Plan your own retirement"
          blurb="No adviser needed. Answer a few plain questions and see when you could stop working — and whether the money lasts."
        >
          <div className="flex flex-wrap gap-2">
            <TrackButton to="/start-free" variant="solid" className="flex-1">
              Start free
            </TrackButton>
            <TrackButton onClick={onPersonal} disabled={opening} className="flex-1">
              {busySlug === '__personal' ? 'Opening…' : 'See a sample plan'}
            </TrackButton>
          </div>
          <p className="text-[11px] leading-relaxed text-[var(--color-ink-3)]">
            Planning as a couple? You can invite your partner once you&rsquo;re in.
          </p>
        </Track>

        <Track
          kind="adviser"
          eyebrow="For advisers &amp; firms"
          title="Plan for your clients"
          blurb="Multi-goal plans, a presentation-ready meeting view, your own templates and risk questionnaire, and a client login."
        >
          {firms.length > 0 ? (
            <>
              <div className="grid grid-cols-2 gap-2">
                {firms.slice(0, 4).map((firm) => (
                  <button
                    key={firm.slug}
                    type="button"
                    onClick={() => onFirm(firm.slug)}
                    disabled={opening}
                    className="rounded-[var(--radius-ctrl)] border px-2.5 py-1.5 text-left transition-colors disabled:opacity-60"
                    style={{ borderColor: 'var(--color-line-2)', backgroundColor: 'var(--color-surface-2)' }}
                  >
                    <span className="block truncate text-[12px] font-medium text-[var(--color-ink)]">
                      {busySlug === firm.slug ? 'Opening…' : firm.name}
                    </span>
                    <span className="block text-[10px] uppercase tracking-wide text-[var(--color-ink-3)]">
                      {firm.advisory_mode === 'advisory' ? 'Advisory' : 'Distribution'}
                    </span>
                  </button>
                ))}
              </div>
              <p className="text-[11px] leading-relaxed text-[var(--color-ink-3)]">
                {firmOpening ? 'Opening the demo…' : 'Open a live demo firm — no signup.'}
              </p>
            </>
          ) : (
            <>
              <TrackButton to="/signup" variant="solid">
                Start a firm trial
              </TrackButton>
              <p className="text-[11px] leading-relaxed text-[var(--color-ink-3)]">
                Already invited? Sign in above.
              </p>
            </>
          )}
        </Track>
      </div>

      {error && (
        <p
          className="mt-3 rounded-[var(--radius-ctrl)] px-3 py-2.5 text-center text-sm"
          style={{ backgroundColor: 'var(--color-alert-soft)', color: 'var(--color-alert)' }}
        >
          {error}
        </p>
      )}
    </div>
  );
}
