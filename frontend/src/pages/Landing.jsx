// Route / (UNAUTHENTICATED) — the public marketing landing page. Authenticated
// visitors never see this: App.jsx's <HomeOrLanding /> shunts them into the
// existing <ProtectedRoute><Home /></ProtectedRoute> flow (which handles the
// MFA-enrollment gate and the client-vs-advisor role split).
//
// Design brief (this session, user-chosen): "ProjectionLab / Notion" vibe
// — light, illustration-driven, warm, editorial — with an advisor-first
// hero and a distinct band for the self-serve individual tier below it. The
// palette, typography, and animation classes all come from index.css's
// design tokens (--color-*, --grad-*, .animate-rise, .stagger-children,
// .lift, .text-gradient-teal, .surface-glass). Nothing new is imported at
// runtime beyond React and react-router — no external images, no CDN
// scripts, no chart library on this page. Every illustration is an inline
// SVG so the marketing page ships as one static bundle Hostinger can serve
// with no live dependencies.
//
// Content thesis (from docs/07 and CLAUDE.md's own capability index):
// HorizonPlan is "the meeting, the follow-through, the record." The three
// product pillars section names those three verbatim. Anywhere this page
// makes a claim about the product, that claim is checkable in the app
// itself — Meeting Mode really exists, so does the Jr→Sr approval workflow,
// so does the audit trail. No fabricated testimonials, no invented
// customer logos, no fake press mentions; the trust strip surfaces the
// architectural facts (tenant isolation, 2FA, SEBI-aware disclosures) that
// are real and shipping, not marketing that isn't.

import { Link } from 'react-router-dom';
import { useEffect, useRef, useState } from 'react';

// ---------------------------------------------------------------------------
// Small helper: reveal on scroll. IntersectionObserver-backed, no library.
// Sections stay opacity:0 → fade-and-rise into view once, then leave-alone —
// respects prefers-reduced-motion via the global rule in index.css (which
// already zeroes out `animation` and `transition` for that media query).
// ---------------------------------------------------------------------------
function useReveal() {
  const ref = useRef(null);
  const [shown, setShown] = useState(false);
  useEffect(() => {
    const el = ref.current;
    if (!el || shown) return;
    if (typeof IntersectionObserver === 'undefined') { setShown(true); return; }
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((e) => {
          if (e.isIntersecting) {
            setShown(true);
            io.disconnect();
          }
        });
      },
      { threshold: 0.12, rootMargin: '0px 0px -60px 0px' }
    );
    io.observe(el);
    return () => io.disconnect();
  }, [shown]);
  return { ref, shown };
}

function Reveal({ children, className = '', delay = 0 }) {
  const { ref, shown } = useReveal();
  return (
    <div
      ref={ref}
      className={className}
      style={{
        opacity: shown ? 1 : 0,
        transform: shown ? 'none' : 'translateY(14px)',
        transition: `opacity 0.6s var(--ease-out) ${delay}ms, transform 0.6s var(--ease-out) ${delay}ms`,
      }}
    >
      {children}
    </div>
  );
}

export default function Landing() {
  return (
    <div className="min-h-screen" style={{ backgroundColor: 'var(--color-canvas)' }}>
      <TopNav />
      <Hero />
      <TrustStrip />
      <ProductPillars />
      <HowItWorks />
      <FeatureGrid />
      <IndividualBand />
      <PositioningSection />
      <PricingSection />
      <FinalCTA />
      <SiteFooter />
    </div>
  );
}

// ---------------------------------------------------------------------------
// Top navigation — glass surface (see .surface-glass in index.css), sticky.
// Anchor links target section ids further down the page. The two CTAs on the
// right are the two real trial paths the app already exposes: /signup (firm
// trial, always distribution mode, founder is firm_admin — see TrialSignup.php)
// and /start-free (personal trial — see docs/13 I-1).
// ---------------------------------------------------------------------------
function TopNav() {
  const [open, setOpen] = useState(false);
  const linkCls = 'text-[13px] font-medium text-[var(--color-ink-2)] hover:text-[var(--color-ink)] transition-colors';
  return (
    <header className="sticky top-0 z-50 surface-glass border-b" style={{ borderColor: 'var(--color-line)' }}>
      <div className="mx-auto max-w-[1200px] px-5 sm:px-8 h-16 flex items-center justify-between">
        <Link to="/" className="flex items-center gap-2.5" aria-label="HorizonPlan home">
          <HorizonMark size={26} />
          <span className="text-[17px] font-semibold tracking-tight text-[var(--color-ink)]">HorizonPlan</span>
        </Link>

        <nav className="hidden md:flex items-center gap-7">
          <a href="#pillars" className={linkCls}>Product</a>
          <a href="#how" className={linkCls}>How it works</a>
          <a href="#features" className={linkCls}>Features</a>
          <a href="#individuals" className={linkCls}>For individuals</a>
          <a href="#pricing" className={linkCls}>Pricing</a>
        </nav>

        <div className="hidden md:flex items-center gap-3">
          <Link to="/login" className={linkCls}>Sign in</Link>
          <Link
            to="/signup"
            className="rounded-full px-4 py-2 text-[13px] font-semibold text-white transition-all duration-150 active:translate-y-px"
            style={{ background: 'var(--grad-ink)', boxShadow: 'var(--shadow-sm)' }}
          >
            Start free trial
          </Link>
        </div>

        {/* Mobile: hamburger. Toggles a compact sheet under the nav. */}
        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          className="md:hidden inline-flex h-9 w-9 items-center justify-center rounded-lg"
          style={{ backgroundColor: 'var(--color-surface-2)', color: 'var(--color-ink)' }}
          aria-label="Menu"
          aria-expanded={open}
        >
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            {open
              ? <path d="M3 3l10 10M13 3L3 13" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" />
              : <>
                  <line x1="2" y1="4.5" x2="14" y2="4.5" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" />
                  <line x1="2" y1="8" x2="14" y2="8" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" />
                  <line x1="2" y1="11.5" x2="14" y2="11.5" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" />
                </>
            }
          </svg>
        </button>
      </div>

      {open && (
        <div className="md:hidden border-t px-5 py-4 space-y-3" style={{ borderColor: 'var(--color-line)', backgroundColor: 'var(--color-surface)' }}>
          <a href="#pillars" onClick={() => setOpen(false)} className="block text-sm text-[var(--color-ink-2)]">Product</a>
          <a href="#how" onClick={() => setOpen(false)} className="block text-sm text-[var(--color-ink-2)]">How it works</a>
          <a href="#features" onClick={() => setOpen(false)} className="block text-sm text-[var(--color-ink-2)]">Features</a>
          <a href="#individuals" onClick={() => setOpen(false)} className="block text-sm text-[var(--color-ink-2)]">For individuals</a>
          <a href="#pricing" onClick={() => setOpen(false)} className="block text-sm text-[var(--color-ink-2)]">Pricing</a>
          <div className="pt-3 border-t flex items-center gap-3" style={{ borderColor: 'var(--color-line)' }}>
            <Link to="/login" className="text-sm text-[var(--color-ink-2)]">Sign in</Link>
            <Link
              to="/signup"
              className="rounded-full px-4 py-2 text-[13px] font-semibold text-white"
              style={{ background: 'var(--grad-ink)' }}
            >
              Start free trial
            </Link>
          </div>
        </div>
      )}
    </header>
  );
}

// ---------------------------------------------------------------------------
// Hero — the top 85vh. Left column: eyebrow + big headline + subhead + dual
// CTA + tiny trust line. Right column: the product mock (see HeroProductMock).
// The soft radial washes on <body> in index.css do most of the visual work in
// the background; this section adds only its own subtle top-fade so the
// headline lifts off the canvas without shouting.
// ---------------------------------------------------------------------------
function Hero() {
  return (
    <section className="relative overflow-hidden pt-14 pb-24 sm:pt-20 sm:pb-32">
      {/* Whisper of teal glow behind the mock — never crosses the headline column */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute top-16 right-[-8%] h-[420px] w-[520px] hidden lg:block"
        style={{ background: 'radial-gradient(circle at 40% 40%, rgba(14,124,107,0.16), transparent 62%)' }}
      />

      <div className="mx-auto max-w-[1200px] px-5 sm:px-8 grid lg:grid-cols-[6fr_5fr] gap-14 lg:gap-8 items-center">
        <div className="animate-rise">
          <p
            className="mb-5 inline-flex items-center gap-2 rounded-full border px-3 py-1 text-[11px] font-medium uppercase tracking-[0.08em]"
            style={{ borderColor: 'var(--color-line-2)', color: 'var(--color-ink-2)', backgroundColor: 'var(--color-surface)' }}
          >
            <span
              className="inline-block h-1.5 w-1.5 rounded-full"
              style={{ backgroundColor: 'var(--color-teal)' }}
            />
            Built for MFDs, IFAs & SEBI-RIA firms in India
          </p>

          <h1 className="text-[42px] sm:text-[56px] lg:text-[64px] leading-[1.05] font-semibold tracking-[-0.025em] text-[var(--color-ink)]">
            The retirement conversation, <span className="text-gradient-teal">in one place</span>.
          </h1>

          <p className="mt-6 max-w-[560px] text-[17px] sm:text-[19px] leading-[1.55] text-[var(--color-ink-2)]">
            Multi-goal planning built for Indian markets — the numbers you show a client, the follow-through your firm can audit, and the record of every meeting. Designed for the room, not the spreadsheet.
          </p>

          <div className="mt-9 flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
            <Link
              to="/signup"
              className="inline-flex items-center justify-center gap-2 rounded-full px-6 py-3 text-[14px] font-semibold text-white transition-all duration-150 active:translate-y-px"
              style={{ background: 'var(--grad-ink)', boxShadow: 'var(--shadow-md)' }}
            >
              Start a free 14-day trial
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                <path d="M3 7h8m0 0l-3-3m3 3l-3 3" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </Link>
            <Link
              to="/login"
              className="inline-flex items-center justify-center gap-2 rounded-full border px-6 py-3 text-[14px] font-semibold transition-all duration-150"
              style={{ borderColor: 'var(--color-line-2)', color: 'var(--color-ink)', backgroundColor: 'var(--color-surface)' }}
            >
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                <polygon points="4,3 12,7 4,11" fill="currentColor" />
              </svg>
              Try a live demo
            </Link>
          </div>

          <p className="mt-5 text-[13px] text-[var(--color-ink-3)]">
            No credit card · 14-day trial for firms · Free forever for individuals
          </p>
        </div>

        <div className="relative">
          <HeroProductMock />
        </div>
      </div>
    </section>
  );
}

// ---------------------------------------------------------------------------
// The product mock — a stylised "client roster + open goal" screen. Deliberately
// stylised, not a real screenshot: the mock is fully self-contained SVG-and-CSS
// so it renders instantly with zero image weight, and it stays honest — every
// element here is a fixture, none of it presents real client data. Notion-style
// floating annotations mark two of the most distinctive product features
// (Meeting Mode entry, sequence-of-returns chart) so the visitor can see the
// concept at a glance, not just an abstract "app screenshot."
// ---------------------------------------------------------------------------
function HeroProductMock() {
  return (
    <div className="relative" style={{ perspective: '1200px' }}>
      {/* The main "app window" card */}
      <div
        className="relative rounded-[var(--radius-lg)] overflow-hidden animate-rise"
        style={{
          backgroundColor: 'var(--color-surface)',
          border: '1px solid var(--color-line)',
          boxShadow: 'var(--shadow-lg)',
          animationDelay: '0.15s',
        }}
      >
        {/* Chrome bar */}
        <div className="flex items-center gap-1.5 px-4 py-3 border-b" style={{ borderColor: 'var(--color-line)', backgroundColor: 'var(--color-surface-2)' }}>
          <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: '#E4E8EE' }} />
          <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: '#E4E8EE' }} />
          <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: '#E4E8EE' }} />
          <span className="ml-4 text-[11px] text-[var(--color-ink-3)] font-mono">horizonplan.app / clients / priya-r</span>
        </div>

        {/* Body: readiness score + a small chart + a couple of goal rows */}
        <div className="p-5">
          <div className="flex items-start justify-between gap-4">
            <div>
              <p className="text-[11px] uppercase tracking-[0.08em] text-[var(--color-ink-3)] font-semibold">Retirement readiness</p>
              <p className="mt-1 flex items-baseline gap-2">
                <span className="tnum text-[42px] font-semibold text-[var(--color-ink)]">72</span>
                <span className="text-[13px] text-[var(--color-ink-2)]">/ 100</span>
              </p>
              <p className="mt-1 text-[12px] text-[var(--color-teal-ink)] font-medium">▲ 6 since March</p>
            </div>
            <ReadinessRing value={72} />
          </div>

          {/* Sparkline / lifecycle chart */}
          <div className="mt-5 rounded-[var(--radius-card)] p-4" style={{ backgroundColor: 'var(--color-surface-2)' }}>
            <div className="flex items-center justify-between text-[11px] text-[var(--color-ink-3)]">
              <span className="font-semibold uppercase tracking-[0.08em]">Corpus lifecycle · steady vs. adverse</span>
              <span>2026 – 2062</span>
            </div>
            <svg viewBox="0 0 320 96" className="mt-2 w-full" aria-hidden="true">
              {/* Grid */}
              <line x1="0" y1="80" x2="320" y2="80" stroke="var(--color-line)" strokeWidth="1" />
              <line x1="0" y1="50" x2="320" y2="50" stroke="var(--color-line)" strokeWidth="1" strokeDasharray="2,3" />
              <line x1="0" y1="20" x2="320" y2="20" stroke="var(--color-line)" strokeWidth="1" strokeDasharray="2,3" />
              {/* Adverse-sequence area (muted amber wash) */}
              <path
                d="M4 80 C 60 72, 100 48, 150 30 S 220 62, 260 70 L 316 84 L 316 90 L 4 90 Z"
                fill="rgba(169,120,47,0.10)"
              />
              <path
                d="M4 80 C 60 72, 100 48, 150 30 S 220 62, 260 70 L 316 84"
                stroke="var(--color-amber)"
                strokeWidth="1.75"
                strokeDasharray="3,3"
                fill="none"
              />
              {/* Steady-return line */}
              <path
                d="M4 82 C 60 66, 100 40, 150 22 S 220 12, 316 6"
                stroke="var(--color-teal)"
                strokeWidth="2.25"
                fill="none"
                strokeLinecap="round"
                strokeDasharray="360"
                style={{ animation: 'drawLine 1.6s var(--ease-out) 0.6s both' }}
              />
              {/* End dot */}
              <circle cx="316" cy="6" r="3.5" fill="var(--color-teal)" />
            </svg>
            <div className="mt-2 flex items-center gap-4 text-[11px] text-[var(--color-ink-2)]">
              <span className="flex items-center gap-1.5">
                <span className="h-[3px] w-4 rounded" style={{ backgroundColor: 'var(--color-teal)' }} />
                Steady 11%
              </span>
              <span className="flex items-center gap-1.5">
                <span className="h-[3px] w-4 rounded" style={{ backgroundColor: 'var(--color-amber)', backgroundImage: 'repeating-linear-gradient(90deg, var(--color-amber) 0 3px, transparent 3px 6px)' }} />
                Adverse-sequence
              </span>
            </div>
          </div>

          {/* Goal rows */}
          <div className="mt-4 space-y-2">
            <MockGoalRow name="Retirement" amount="₹4.2 Cr" state="On track" tone="teal" />
            <MockGoalRow name="Kavya's undergrad" amount="₹68 L" state="Behind plan" tone="amber" />
            <MockGoalRow name="Home upgrade 2031" amount="₹1.1 Cr" state="Met target" tone="teal-soft" />
          </div>
        </div>
      </div>

      {/* Floating annotation 1 — Meeting Mode */}
      <div
        className="absolute -left-4 top-[38%] hidden lg:flex items-center gap-2 rounded-full px-3 py-1.5 animate-rise"
        style={{
          backgroundColor: 'var(--color-ink)',
          color: '#fff',
          boxShadow: 'var(--shadow-ink)',
          animationDelay: '0.9s',
          fontSize: '11px',
          fontWeight: 500,
        }}
      >
        <svg width="12" height="12" viewBox="0 0 14 14" fill="none" aria-hidden="true">
          <polygon points="4,3 12,7 4,11" fill="currentColor" />
        </svg>
        Open in Meeting Mode
      </div>

      {/* Floating annotation 2 — Sequence risk */}
      <div
        className="absolute -right-3 top-[62%] hidden lg:flex items-center gap-2 rounded-full px-3 py-1.5 animate-rise"
        style={{
          backgroundColor: 'var(--color-surface)',
          color: 'var(--color-ink)',
          border: '1px solid var(--color-line-2)',
          boxShadow: 'var(--shadow-md)',
          animationDelay: '1.1s',
          fontSize: '11px',
          fontWeight: 500,
        }}
      >
        <span
          className="inline-block h-1.5 w-1.5 rounded-full"
          style={{ backgroundColor: 'var(--color-amber)' }}
        />
        Sequence-of-returns risk visible
      </div>
    </div>
  );
}

function ReadinessRing({ value }) {
  const circumference = 2 * Math.PI * 22;
  const offset = circumference * (1 - value / 100);
  return (
    <svg width="60" height="60" viewBox="0 0 60 60" aria-hidden="true">
      <circle cx="30" cy="30" r="22" stroke="var(--color-line)" strokeWidth="4" fill="none" />
      <circle
        cx="30" cy="30" r="22"
        stroke="var(--color-teal)"
        strokeWidth="4"
        fill="none"
        strokeLinecap="round"
        transform="rotate(-90 30 30)"
        strokeDasharray={circumference}
        strokeDashoffset={offset}
        style={{ transition: 'stroke-dashoffset 1.2s var(--ease-out) 0.7s' }}
      />
    </svg>
  );
}

function MockGoalRow({ name, amount, state, tone }) {
  const stateStyles = {
    teal: { color: 'var(--color-teal-ink)', backgroundColor: 'var(--color-teal-soft)' },
    amber: { color: '#7A5720', backgroundColor: 'var(--color-amber-soft)' },
    'teal-soft': { color: 'var(--color-teal-ink)', backgroundColor: 'var(--color-teal-soft)' },
  };
  return (
    <div
      className="flex items-center justify-between rounded-[var(--radius-ctrl)] border px-3 py-2.5"
      style={{ borderColor: 'var(--color-line)' }}
    >
      <div>
        <p className="text-[13px] font-medium text-[var(--color-ink)]">{name}</p>
        <p className="tnum text-[11px] text-[var(--color-ink-3)] mt-0.5">Target {amount}</p>
      </div>
      <span
        className="rounded-full px-2.5 py-1 text-[10px] font-semibold"
        style={stateStyles[tone] ?? stateStyles.teal}
      >
        {state}
      </span>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Trust strip — a slim band of architectural facts, not fabricated logos.
// Everything here is a real, shipping property of the system: tenant isolation
// is enforced through TenantScopedDb (CLAUDE.md rule #1); MFA is RFC-6238 TOTP
// (Totp.php); SEBI-aware disclosure copy renders adjacent to every projection
// chart (CLAUDE.md rule #3); the app is Indian-market-first (inflation model,
// withdrawal presets, historical replay of Indian market years).
// ---------------------------------------------------------------------------
function TrustStrip() {
  const items = [
    { icon: <ShieldIcon />, label: 'Tenant-isolated', sub: 'Every query scoped, always' },
    { icon: <LockIcon />, label: '2FA-secured', sub: 'RFC 6238 TOTP + Google' },
    { icon: <SebiIcon />, label: 'SEBI-aware disclosures', sub: 'Rendered on every plan view' },
    { icon: <IndiaIcon />, label: 'Indian-market-first', sub: 'Real Indian historical years' },
  ];
  return (
    <section className="border-y" style={{ borderColor: 'var(--color-line)', backgroundColor: 'var(--color-surface)' }}>
      <div className="mx-auto max-w-[1200px] px-5 sm:px-8 py-6">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-6">
          {items.map((it) => (
            <div key={it.label} className="flex items-start gap-3">
              <span className="mt-0.5 flex h-8 w-8 items-center justify-center rounded-lg shrink-0" style={{ backgroundColor: 'var(--color-teal-soft)', color: 'var(--color-teal-ink)' }}>
                {it.icon}
              </span>
              <div className="min-w-0">
                <p className="text-[13px] font-semibold text-[var(--color-ink)] leading-tight">{it.label}</p>
                <p className="text-[11px] text-[var(--color-ink-3)] mt-0.5 leading-snug">{it.sub}</p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

// ---------------------------------------------------------------------------
// Product pillars — the three-legged product thesis from docs/07: "the
// meeting, the follow-through, the record." This section is the closest thing
// to a mission statement on the whole page, and it's on the record everywhere
// else in the codebase too — CLAUDE.md's own capability index lists these
// three as the through-line.
// ---------------------------------------------------------------------------
function ProductPillars() {
  const pillars = [
    {
      eyebrow: 'The meeting',
      title: 'Numbers your client can feel',
      body: 'Multi-goal planning, sequence-of-returns risk, and a plain-language readiness score. Present in Meeting Mode — a full-screen, distraction-free canvas for the room, not a spreadsheet you talk over.',
      illo: <PillarIllustrationMeeting />,
      href: '#features',
    },
    {
      eyebrow: 'The follow-through',
      title: "The work between meetings",
      body: 'A Jr→Sr advisor approval workflow, scheduled plan reviews, an alerts engine that surfaces drift and gaps as facts (never instructions), and progress snapshots you can compare against what the plan actually expected.',
      illo: <PillarIllustrationFollowThrough />,
      href: '#features',
    },
    {
      eyebrow: 'The record',
      title: 'Every change, every meeting',
      body: 'A firm-wide read-only audit log, per-goal change history, and captured client responses. Nothing overwrites the past silently — every decision your advisers make has a receipt.',
      illo: <PillarIllustrationRecord />,
      href: '#features',
    },
  ];
  return (
    <section id="pillars" className="py-24 sm:py-32">
      <div className="mx-auto max-w-[1200px] px-5 sm:px-8">
        <Reveal className="max-w-2xl">
          <p className="mb-3 text-[12px] font-semibold uppercase tracking-[0.09em] text-[var(--color-teal-ink)]">
            The three-legged thesis
          </p>
          <h2 className="text-[32px] sm:text-[44px] leading-[1.1] font-semibold tracking-[-0.02em] text-[var(--color-ink)]">
            The meeting, the follow-through, the record.
          </h2>
          <p className="mt-5 text-[16px] sm:text-[18px] leading-[1.55] text-[var(--color-ink-2)]">
            Retirement planning isn't one screen. It's a conversation that has to hold up in the room, translate into ongoing work, and leave behind an auditable trail. HorizonPlan is built as those three parts, not one.
          </p>
        </Reveal>

        <div className="mt-14 grid md:grid-cols-3 gap-6 stagger-children">
          {pillars.map((p) => (
            <article
              key={p.eyebrow}
              className="lift rounded-[var(--radius-lg)] border p-6 flex flex-col"
              style={{
                borderColor: 'var(--color-line)',
                backgroundColor: 'var(--color-surface)',
                boxShadow: 'var(--shadow-sm)',
              }}
            >
              <div className="mb-6 rounded-[var(--radius-card)] p-5 flex items-center justify-center" style={{ backgroundColor: 'var(--color-surface-2)', minHeight: 148 }}>
                {p.illo}
              </div>
              <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--color-teal-ink)]">{p.eyebrow}</p>
              <h3 className="mt-2 text-[20px] font-semibold tracking-tight text-[var(--color-ink)]">{p.title}</h3>
              <p className="mt-2.5 text-[14px] leading-relaxed text-[var(--color-ink-2)] flex-1">{p.body}</p>
              <a href={p.href} className="mt-5 inline-flex items-center gap-1.5 text-[13px] font-semibold text-[var(--color-teal-ink)] hover:gap-2 transition-all">
                See what's inside
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                  <path d="M3 6h6m0 0l-2.5-2.5M9 6l-2.5 2.5" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
              </a>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}

// ---------------------------------------------------------------------------
// How it works — three narrow steps that each carry one substantive
// illustration. Deliberately not "sign up, invite users, done" filler; each
// step names a real advisor task the product actually does.
// ---------------------------------------------------------------------------
function HowItWorks() {
  const steps = [
    {
      no: '01',
      title: 'Bring your clients into the system',
      body: 'Add clients manually, invite them to log in themselves, or ingest a CAS statement (CAMS / KFintech / MFCentral). Every holding lands reconciled against the client\'s existing book — never a silent duplicate.',
      illo: <HowIllustrationOne />,
    },
    {
      no: '02',
      title: 'Model the plan with a couple of sliders',
      body: 'Multi-goal, inheritance-cascading scenarios; accumulation → decumulation as one lifecycle; sequence-of-returns and historical replay side by side. Apply a firm-approved template in one click.',
      illo: <HowIllustrationTwo />,
    },
    {
      no: '03',
      title: 'Present in the room, record the meeting',
      body: 'Open Meeting Mode for a full-screen conversation. Every change to the plan is logged. The client leaves with a printable report; the firm leaves with an audit trail.',
      illo: <HowIllustrationThree />,
    },
  ];
  return (
    <section id="how" className="py-24 sm:py-32" style={{ backgroundColor: 'var(--color-surface)' }}>
      <div className="mx-auto max-w-[1200px] px-5 sm:px-8">
        <Reveal className="max-w-2xl">
          <p className="mb-3 text-[12px] font-semibold uppercase tracking-[0.09em] text-[var(--color-teal-ink)]">
            How it works
          </p>
          <h2 className="text-[32px] sm:text-[44px] leading-[1.1] font-semibold tracking-[-0.02em] text-[var(--color-ink)]">
            Three steps, then it becomes a rhythm.
          </h2>
        </Reveal>

        <div className="mt-14 grid md:grid-cols-3 gap-6 lg:gap-8 stagger-children">
          {steps.map((s) => (
            <Reveal key={s.no}>
              <div className="h-full flex flex-col">
                <div
                  className="rounded-[var(--radius-lg)] p-6 mb-5 flex items-center justify-center"
                  style={{
                    backgroundColor: 'var(--color-canvas)',
                    border: '1px solid var(--color-line)',
                    minHeight: 180,
                  }}
                >
                  {s.illo}
                </div>
                <span className="tnum text-[13px] font-semibold text-[var(--color-teal-ink)]">{s.no}</span>
                <h3 className="mt-1 text-[19px] font-semibold tracking-tight text-[var(--color-ink)]">{s.title}</h3>
                <p className="mt-2 text-[14px] leading-relaxed text-[var(--color-ink-2)]">{s.body}</p>
              </div>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  );
}

// ---------------------------------------------------------------------------
// Feature grid — 8 tiles. Every entry here is a real, shipping capability
// (see CLAUDE.md's capability index for the full descriptions and provenance).
// Icon + short title + one-sentence body, nothing more; the point is coverage
// and legibility, not deep marketing prose.
// ---------------------------------------------------------------------------
function FeatureGrid() {
  const features = [
    {
      icon: <FeatureIconGoals />,
      title: 'Multi-goal planning',
      body: 'Retirement, education, home, or bespoke — modelled together, with a Global Inheritance Engine for what-if scenarios.',
    },
    {
      icon: <FeatureIconSequence />,
      title: 'Sequence-of-returns risk',
      body: 'Steady vs. adverse-sequence charts side by side on every projectable goal, not just retirement.',
    },
    {
      icon: <FeatureIconHistory />,
      title: 'Historical replay',
      body: 'Rerun a plan against real Indian market years — including the decade that would have broken it.',
    },
    {
      icon: <FeatureIconMeeting />,
      title: 'Meeting Mode',
      body: 'A full-screen, distraction-free canvas designed for the client conversation, not another tab.',
    },
    {
      icon: <FeatureIconReview />,
      title: 'Jr → Sr approval workflow',
      body: 'Opt-in per firm. Junior advisers draft, seniors approve — every decision on the audit trail.',
    },
    {
      icon: <FeatureIconTemplates />,
      title: 'Strategy templates',
      body: 'Global and firm-authored templates, approval-gated, applied to a goal in one click.',
    },
    {
      icon: <FeatureIconRisk />,
      title: 'Your own risk profiler',
      body: 'You author the questions and the scoring rubric — the app never invents them. Approval-gated.',
    },
    {
      icon: <FeatureIconAlerts />,
      title: 'Alerts engine',
      body: 'Drift, price-staleness, review-due, foundations gaps, met targets — all as facts, never instructions.',
    },
  ];
  return (
    <section id="features" className="py-24 sm:py-32">
      <div className="mx-auto max-w-[1200px] px-5 sm:px-8">
        <Reveal className="max-w-2xl">
          <p className="mb-3 text-[12px] font-semibold uppercase tracking-[0.09em] text-[var(--color-teal-ink)]">
            What's under the hood
          </p>
          <h2 className="text-[32px] sm:text-[44px] leading-[1.1] font-semibold tracking-[-0.02em] text-[var(--color-ink)]">
            The features you'd build yourself, given enough time.
          </h2>
          <p className="mt-5 text-[16px] leading-[1.55] text-[var(--color-ink-2)]">
            Depth chosen the way a practitioner would — every one of these earned its place because a real client conversation needs it.
          </p>
        </Reveal>

        <div className="mt-14 grid sm:grid-cols-2 lg:grid-cols-4 gap-4 stagger-children">
          {features.map((f) => (
            <article
              key={f.title}
              className="lift rounded-[var(--radius-card)] border p-5"
              style={{ borderColor: 'var(--color-line)', backgroundColor: 'var(--color-surface)' }}
            >
              <span className="flex h-10 w-10 items-center justify-center rounded-lg mb-4" style={{ backgroundColor: 'var(--color-teal-soft)', color: 'var(--color-teal-ink)' }}>
                {f.icon}
              </span>
              <h3 className="text-[15px] font-semibold text-[var(--color-ink)]">{f.title}</h3>
              <p className="mt-1.5 text-[13px] leading-relaxed text-[var(--color-ink-2)]">{f.body}</p>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}

// ---------------------------------------------------------------------------
// Individual band — the self-serve tier (docs/13). Distinct visual band
// (deep-slate gradient) so it reads as a different room from the advisor
// story above, without breaking the visual grammar of the page. The mock is
// a compact, warm "personal plan summary" that matches PersonalPlanSummary.jsx.
// ---------------------------------------------------------------------------
function IndividualBand() {
  return (
    <section id="individuals" className="relative overflow-hidden">
      <div
        className="absolute inset-0"
        style={{ background: 'var(--grad-hero)' }}
        aria-hidden="true"
      />
      <div
        className="pointer-events-none absolute -top-24 -left-24 h-96 w-96 rounded-full opacity-30"
        style={{ background: 'radial-gradient(circle, rgba(63,214,189,0.4), transparent 65%)' }}
        aria-hidden="true"
      />
      <div className="relative mx-auto max-w-[1200px] px-5 sm:px-8 py-24 sm:py-32 grid lg:grid-cols-[5fr_6fr] gap-14 items-center">
        <Reveal>
          <p className="mb-4 inline-flex items-center gap-2 rounded-full border px-3 py-1 text-[11px] font-medium uppercase tracking-[0.08em]" style={{ borderColor: 'rgba(255,255,255,0.14)', color: '#3FD6BD', backgroundColor: 'rgba(255,255,255,0.04)' }}>
            <span className="inline-block h-1.5 w-1.5 rounded-full" style={{ backgroundColor: '#3FD6BD' }} />
            Planning for yourself?
          </p>
          <h2 className="text-[32px] sm:text-[44px] leading-[1.1] font-semibold tracking-[-0.02em] text-white">
            Will the money last?
          </h2>
          <p className="mt-5 text-[16px] sm:text-[18px] leading-[1.55]" style={{ color: 'rgba(255,255,255,0.72)' }}>
            You don't need an adviser to answer that. HorizonPlan's free-forever individual tier gives you the same engine — multi-goal, sequence-of-returns-aware, calibrated for Indian markets — in plain language. No jargon on screen, no upsell to a fund, no product recommendations.
          </p>
          <ul className="mt-7 space-y-3">
            {[
              'See the year you could stop working — and a countdown to it',
              'One readiness score, in plain language, that moves as you tweak',
              'Plan together with your partner, each keeping your own plan',
              'Free forever. No card. No upsell.',
            ].map((b) => (
              <li key={b} className="flex items-start gap-2.5 text-[14px]" style={{ color: 'rgba(255,255,255,0.85)' }}>
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" className="mt-1 shrink-0" aria-hidden="true">
                  <path d="M3 7.5l2.5 2.5L11 4" stroke="#3FD6BD" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
                {b}
              </li>
            ))}
          </ul>
          <div className="mt-8 flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
            <Link
              to="/start-free"
              className="inline-flex items-center justify-center gap-2 rounded-full px-6 py-3 text-[14px] font-semibold transition-all duration-150 active:translate-y-px"
              style={{ backgroundColor: '#3FD6BD', color: 'var(--color-ink)', boxShadow: 'var(--shadow-md)' }}
            >
              Start planning free
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                <path d="M3 7h8m0 0l-3-3m3 3l-3 3" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </Link>
            <Link
              to="/login?for=individual"
              className="inline-flex items-center justify-center gap-2 rounded-full px-6 py-3 text-[14px] font-semibold transition-all duration-150"
              style={{ border: '1px solid rgba(255,255,255,0.22)', color: '#fff', backgroundColor: 'rgba(255,255,255,0.04)' }}
            >
              Try the personal demo
            </Link>
          </div>
        </Reveal>

        <Reveal delay={120}>
          <PersonalSummaryMock />
        </Reveal>
      </div>
    </section>
  );
}

// A compact stylised "personal plan summary" — mirrors the shape of
// PersonalPlanSummary.jsx (identity strip → countdown → readiness → one next
// action) without ever presenting real data.
function PersonalSummaryMock() {
  return (
    <div
      className="rounded-[var(--radius-lg)] p-6 sm:p-7"
      style={{
        backgroundColor: 'var(--color-surface)',
        boxShadow: 'var(--shadow-lg)',
      }}
    >
      {/* Identity strip */}
      <div className="flex items-center justify-between pb-4 border-b" style={{ borderColor: 'var(--color-line)' }}>
        <div className="flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-full text-[13px] font-semibold" style={{ backgroundColor: 'var(--color-teal-soft)', color: 'var(--color-teal-ink)' }}>PR</span>
          <div>
            <p className="text-[14px] font-semibold text-[var(--color-ink)]">Priya Raman</p>
            <p className="text-[11px] text-[var(--color-ink-3)]">Age 38 · retiring at 60</p>
          </div>
        </div>
        <span className="text-[11px] text-[var(--color-ink-3)]">Your plan</span>
      </div>

      {/* Countdown */}
      <div className="mt-5 rounded-[var(--radius-card)] p-4" style={{ backgroundColor: 'var(--color-surface-2)' }}>
        <p className="text-[11px] uppercase tracking-[0.08em] text-[var(--color-ink-3)] font-semibold">
          You could stop working in
        </p>
        <p className="mt-1 flex items-baseline gap-2">
          <span className="tnum text-[36px] font-semibold text-[var(--color-ink)]">22</span>
          <span className="text-[14px] text-[var(--color-ink-2)]">years</span>
        </p>
        <p className="mt-1 text-[12px] text-[var(--color-ink-2)]">
          At your current savings rate, on the assumptions below.
        </p>
      </div>

      {/* Readiness + delta */}
      <div className="mt-4 flex items-center justify-between rounded-[var(--radius-card)] p-4" style={{ backgroundColor: 'var(--color-teal-soft)' }}>
        <div>
          <p className="text-[11px] uppercase tracking-[0.08em] font-semibold" style={{ color: 'var(--color-teal-ink)' }}>Readiness</p>
          <p className="tnum text-[28px] font-semibold text-[var(--color-teal-ink)] mt-0.5">68 / 100</p>
        </div>
        <div className="text-right">
          <p className="text-[12px] font-medium" style={{ color: 'var(--color-teal-ink)' }}>▲ 4 since June</p>
          <p className="text-[10px] mt-1" style={{ color: 'var(--color-teal-ink)' }}>Progress logged monthly</p>
        </div>
      </div>

      {/* Next action */}
      <div className="mt-4 rounded-[var(--radius-card)] border border-dashed p-4" style={{ borderColor: 'var(--color-line-2)' }}>
        <p className="text-[11px] uppercase tracking-[0.08em] text-[var(--color-ink-3)] font-semibold">One thing to try</p>
        <p className="mt-1.5 text-[13px] text-[var(--color-ink)]">
          Increasing your SIP by <span className="tnum font-semibold">₹2,000/mo</span> lifts readiness to <span className="tnum font-semibold">76</span>.
        </p>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Positioning — the honest "what it is / isn't" three-column, per docs/10's
// competitive framing. Naming what the product is not (a brokerage, a
// robo-adviser, a spreadsheet replacement) is a positive trust signal — a
// visitor who was looking for one of those saves themselves a signup, and a
// visitor who wants exactly the middle thing knows they've found it.
// ---------------------------------------------------------------------------
function PositioningSection() {
  return (
    <section className="py-24 sm:py-32" style={{ backgroundColor: 'var(--color-surface)' }}>
      <div className="mx-auto max-w-[1200px] px-5 sm:px-8">
        <Reveal className="max-w-2xl mb-14">
          <p className="mb-3 text-[12px] font-semibold uppercase tracking-[0.09em] text-[var(--color-teal-ink)]">
            Straight up
          </p>
          <h2 className="text-[32px] sm:text-[44px] leading-[1.1] font-semibold tracking-[-0.02em] text-[var(--color-ink)]">
            Here's what HorizonPlan isn't.
          </h2>
        </Reveal>
        <div className="grid md:grid-cols-3 gap-6 stagger-children">
          <NotCard
            title="Not a brokerage"
            body="No order routing, no custody, no KYC-on-us. Your client's execution happens where it always has — we're the conversation, not the trade."
          />
          <NotCard
            title="Not a robo-adviser"
            body="We don't recommend funds or take fiduciary positions. The firm brings the strategy; HorizonPlan makes it presentable, reviewable, and auditable."
          />
          <NotCard
            title="Not a spreadsheet replacement"
            body="It's a replacement for the meeting version of that spreadsheet — the one you re-key each quarter, that nobody else on the team can safely edit."
          />
        </div>
        <Reveal className="mt-14 max-w-3xl mx-auto text-center">
          <p className="text-[19px] leading-[1.5] text-[var(--color-ink)]">
            It <em className="not-italic font-semibold text-[var(--color-teal-ink)]">is</em> the planning conversation, made repeatable — every part of it your firm actually charges for.
          </p>
        </Reveal>
      </div>
    </section>
  );
}

function NotCard({ title, body }) {
  return (
    <article
      className="rounded-[var(--radius-lg)] border p-6"
      style={{ borderColor: 'var(--color-line)', backgroundColor: 'var(--color-canvas)' }}
    >
      <div className="mb-4 flex items-center gap-2">
        <span className="flex h-6 w-6 items-center justify-center rounded-full" style={{ backgroundColor: 'var(--color-alert-soft)', color: 'var(--color-alert)' }}>
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
            <path d="M3 3l6 6M9 3l-6 6" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" />
          </svg>
        </span>
        <h3 className="text-[15px] font-semibold text-[var(--color-ink)]">{title}</h3>
      </div>
      <p className="text-[13px] leading-relaxed text-[var(--color-ink-2)]">{body}</p>
    </article>
  );
}

// ---------------------------------------------------------------------------
// Pricing — placeholder for the launch. Two cards: firms and individuals.
// The firm card intentionally says "during early access, free" — because that
// is the truth right now (billing hasn't shipped yet; see docs/10 backlog).
// When the billing session lands, this is the surface to swap in real tiers.
// ---------------------------------------------------------------------------
function PricingSection() {
  return (
    <section id="pricing" className="py-24 sm:py-32">
      <div className="mx-auto max-w-[1200px] px-5 sm:px-8">
        <Reveal className="max-w-2xl mb-14">
          <p className="mb-3 text-[12px] font-semibold uppercase tracking-[0.09em] text-[var(--color-teal-ink)]">
            Pricing
          </p>
          <h2 className="text-[32px] sm:text-[44px] leading-[1.1] font-semibold tracking-[-0.02em] text-[var(--color-ink)]">
            Fair pricing, told plainly.
          </h2>
        </Reveal>

        <div className="grid md:grid-cols-2 gap-6">
          <Reveal>
            <article
              className="rounded-[var(--radius-lg)] p-8 h-full flex flex-col"
              style={{
                background: 'var(--grad-hero)',
                boxShadow: 'var(--shadow-lg)',
                color: '#fff',
              }}
            >
              <p className="text-[11px] font-semibold uppercase tracking-[0.09em]" style={{ color: '#3FD6BD' }}>For firms</p>
              <h3 className="mt-1 text-[24px] font-semibold tracking-tight">HorizonPlan for advisers</h3>
              <p className="mt-2 text-[13px]" style={{ color: 'rgba(255,255,255,0.72)' }}>Per-seat, per-firm. Full-tenant isolation.</p>
              <div className="my-8">
                <p className="tnum text-[44px] font-semibold leading-none">Free</p>
                <p className="mt-1 text-[12px]" style={{ color: 'rgba(255,255,255,0.7)' }}>during early access</p>
              </div>
              <ul className="space-y-2.5 text-[13px] flex-1" style={{ color: 'rgba(255,255,255,0.85)' }}>
                {[
                  'Unlimited advisers and clients',
                  'Full planning engine + Meeting Mode',
                  'Jr → Sr approval workflow',
                  'White-label branding',
                  'Firm-wide audit log',
                ].map((f) => (
                  <li key={f} className="flex items-start gap-2">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" className="mt-0.5 shrink-0" aria-hidden="true">
                      <path d="M3 7.5l2.5 2.5L11 4" stroke="#3FD6BD" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                    {f}
                  </li>
                ))}
              </ul>
              <Link
                to="/signup"
                className="mt-8 inline-flex items-center justify-center gap-2 rounded-full px-6 py-3 text-[14px] font-semibold transition-all"
                style={{ backgroundColor: '#3FD6BD', color: 'var(--color-ink)', boxShadow: 'var(--shadow-md)' }}
              >
                Start a firm trial
              </Link>
            </article>
          </Reveal>

          <Reveal delay={80}>
            <article
              className="rounded-[var(--radius-lg)] p-8 h-full flex flex-col border"
              style={{ borderColor: 'var(--color-line)', backgroundColor: 'var(--color-surface)', boxShadow: 'var(--shadow-sm)' }}
            >
              <p className="text-[11px] font-semibold uppercase tracking-[0.09em] text-[var(--color-teal-ink)]">For individuals</p>
              <h3 className="mt-1 text-[24px] font-semibold tracking-tight text-[var(--color-ink)]">HorizonPlan Personal</h3>
              <p className="mt-2 text-[13px] text-[var(--color-ink-2)]">Plan for yourself. No adviser needed.</p>
              <div className="my-8">
                <p className="tnum text-[44px] font-semibold leading-none text-[var(--color-ink)]">Free</p>
                <p className="mt-1 text-[12px] text-[var(--color-ink-3)]">forever. No card required.</p>
              </div>
              <ul className="space-y-2.5 text-[13px] flex-1 text-[var(--color-ink-2)]">
                {[
                  'Guided setup in plain language',
                  'Retirement countdown + readiness score',
                  'Household planning with a partner',
                  'CAS import for your mutual funds',
                  'Never a product recommendation',
                ].map((f) => (
                  <li key={f} className="flex items-start gap-2">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" className="mt-0.5 shrink-0" aria-hidden="true">
                      <path d="M3 7.5l2.5 2.5L11 4" stroke="var(--color-teal)" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                    {f}
                  </li>
                ))}
              </ul>
              <Link
                to="/start-free"
                className="mt-8 inline-flex items-center justify-center gap-2 rounded-full border px-6 py-3 text-[14px] font-semibold transition-all"
                style={{ borderColor: 'var(--color-ink)', color: 'var(--color-ink)' }}
              >
                Start planning free
              </Link>
            </article>
          </Reveal>
        </div>
      </div>
    </section>
  );
}

// ---------------------------------------------------------------------------
// Final CTA — one confident line + the same two trial paths, styled as a
// deep-ink card that lifts off the page.
// ---------------------------------------------------------------------------
function FinalCTA() {
  return (
    <section className="pb-24 sm:pb-32">
      <div className="mx-auto max-w-[1200px] px-5 sm:px-8">
        <div
          className="rounded-[var(--radius-lg)] p-10 sm:p-16 text-center"
          style={{ background: 'var(--grad-hero)', boxShadow: 'var(--shadow-lg)' }}
        >
          <h2 className="text-[30px] sm:text-[42px] leading-[1.12] font-semibold tracking-[-0.02em] text-white max-w-2xl mx-auto">
            Plan the meeting once. Present it forever.
          </h2>
          <p className="mt-5 text-[16px] sm:text-[17px] max-w-xl mx-auto" style={{ color: 'rgba(255,255,255,0.75)' }}>
            Start a firm trial in under two minutes, or open the individual tier and plan for yourself.
          </p>
          <div className="mt-9 flex flex-col sm:flex-row items-stretch sm:items-center justify-center gap-3">
            <Link
              to="/signup"
              className="inline-flex items-center justify-center gap-2 rounded-full px-6 py-3 text-[14px] font-semibold transition-all duration-150 active:translate-y-px"
              style={{ backgroundColor: '#3FD6BD', color: 'var(--color-ink)', boxShadow: 'var(--shadow-md)' }}
            >
              Start a firm trial
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                <path d="M3 7h8m0 0l-3-3m3 3l-3 3" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </Link>
            <Link
              to="/start-free"
              className="inline-flex items-center justify-center gap-2 rounded-full px-6 py-3 text-[14px] font-semibold transition-all duration-150"
              style={{ border: '1px solid rgba(255,255,255,0.28)', color: '#fff', backgroundColor: 'rgba(255,255,255,0.04)' }}
            >
              Plan for yourself, free
            </Link>
          </div>
        </div>
      </div>
    </section>
  );
}

// ---------------------------------------------------------------------------
// Footer — small print, product/company/legal columns, and the wordmark.
// Links marked "coming soon" (docs, blog, careers, T&C, privacy) are anchors
// to nowhere on purpose — the alternative is dead 404s. The one live link
// pattern outside the app itself is /guide for authenticated advisors, which
// stays gated.
// ---------------------------------------------------------------------------
function SiteFooter() {
  const year = new Date().getFullYear();
  return (
    <footer className="border-t" style={{ borderColor: 'var(--color-line)', backgroundColor: 'var(--color-surface)' }}>
      <div className="mx-auto max-w-[1200px] px-5 sm:px-8 py-14">
        <div className="grid md:grid-cols-4 gap-10">
          <div className="md:col-span-1">
            <div className="flex items-center gap-2.5 mb-4">
              <HorizonMark size={24} />
              <span className="text-[16px] font-semibold tracking-tight text-[var(--color-ink)]">HorizonPlan</span>
            </div>
            <p className="text-[12px] leading-relaxed text-[var(--color-ink-3)] max-w-[220px]">
              Retirement planning built for Indian advisers, firms, and the people they serve.
            </p>
          </div>
          <FooterColumn heading="Product" links={[
            { label: 'How it works', href: '#how' },
            { label: 'Features', href: '#features' },
            { label: 'For individuals', href: '#individuals' },
            { label: 'Pricing', href: '#pricing' },
          ]}/>
          <FooterColumn heading="Get started" links={[
            { label: 'Sign in', to: '/login' },
            { label: 'Start a firm trial', to: '/signup' },
            { label: 'Start planning free', to: '/start-free' },
            { label: 'Try a live demo', to: '/login' },
          ]}/>
          <FooterColumn heading="Company" links={[
            { label: 'About', href: '#' },
            { label: 'Docs', href: '#' },
            { label: 'Privacy', href: '#' },
            { label: 'Terms', href: '#' },
          ]}/>
        </div>
        <div className="mt-14 pt-6 border-t flex flex-col sm:flex-row items-center justify-between gap-3 text-[12px] text-[var(--color-ink-3)]" style={{ borderColor: 'var(--color-line)' }}>
          <p>© {year} HorizonPlan. Made in India.</p>
          <p>Planning-side software. Not a distributor, not a fiduciary.</p>
        </div>
      </div>
    </footer>
  );
}

function FooterColumn({ heading, links }) {
  return (
    <div>
      <p className="text-[11px] uppercase tracking-[0.09em] font-semibold text-[var(--color-ink-3)] mb-4">{heading}</p>
      <ul className="space-y-2.5">
        {links.map((l) => (
          <li key={l.label}>
            {l.to ? (
              <Link to={l.to} className="text-[13px] text-[var(--color-ink-2)] hover:text-[var(--color-ink)] transition-colors">{l.label}</Link>
            ) : (
              <a href={l.href} className="text-[13px] text-[var(--color-ink-2)] hover:text-[var(--color-ink)] transition-colors">{l.label}</a>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}

// ===========================================================================
// Inline SVG illustrations. All hand-drawn to match the palette and stroke
// weight of the rest of the app (index.css). Kept in this file rather than a
// separate module because they are single-use on the landing page and
// separating them adds one more file to maintain for no reuse benefit.
// ===========================================================================

function HorizonMark({ size = 22 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 22 22" fill="none" aria-hidden="true">
      <line x1="2" y1="15" x2="20" y2="15" stroke="var(--color-line-2)" strokeWidth="1.5" />
      <path d="M4 15 L11 6 L18 11" stroke="var(--color-teal)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
      <circle cx="18" cy="11" r="2" fill="var(--color-amber)" />
    </svg>
  );
}

// -- Trust strip icons ------------------------------------------------------
function ShieldIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
      <path d="M8 1.5l5.5 2v4.5c0 3.5-2.5 6-5.5 6.5-3-.5-5.5-3-5.5-6.5V3.5L8 1.5z" stroke="currentColor" strokeWidth="1.4" strokeLinejoin="round" />
      <path d="M6 8l1.5 1.5L10.5 6.5" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}
function LockIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
      <rect x="3" y="7" width="10" height="7" rx="1.3" stroke="currentColor" strokeWidth="1.4" />
      <path d="M5 7V5a3 3 0 013-3 3 3 0 013 3v2" stroke="currentColor" strokeWidth="1.4" />
    </svg>
  );
}
function SebiIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
      <rect x="2.5" y="3" width="11" height="10" rx="1" stroke="currentColor" strokeWidth="1.4" />
      <line x1="5" y1="6.5" x2="11" y2="6.5" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" />
      <line x1="5" y1="9" x2="9" y2="9" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" />
    </svg>
  );
}
function IndiaIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
      <circle cx="8" cy="8" r="6.2" stroke="currentColor" strokeWidth="1.4" />
      <path d="M2.2 8h11.6M8 2c1.6 2 1.6 10 0 12M8 2c-1.6 2-1.6 10 0 12" stroke="currentColor" strokeWidth="1.2" />
    </svg>
  );
}

// -- Pillar illustrations ---------------------------------------------------

// "The meeting" — a stylised full-screen meeting canvas with a big number
// and a smaller data band under it. Alludes to Meeting Mode without
// literally being a screenshot.
function PillarIllustrationMeeting() {
  return (
    <svg viewBox="0 0 200 120" className="w-full max-w-[220px]" aria-hidden="true">
      <rect x="6" y="6" width="188" height="108" rx="10" fill="var(--color-ink)" />
      <rect x="16" y="20" width="60" height="6" rx="2" fill="rgba(255,255,255,0.15)" />
      <text x="100" y="66" textAnchor="middle" fontFamily="IBM Plex Sans" fontWeight="600" fontSize="30" fill="#3FD6BD">₹4.2 Cr</text>
      <text x="100" y="82" textAnchor="middle" fontFamily="IBM Plex Sans" fontSize="9" fill="rgba(255,255,255,0.55)">Projected corpus at 60</text>
      <path d="M20 100 C 60 92, 100 76, 180 60" stroke="#3FD6BD" strokeWidth="2" fill="none" strokeLinecap="round" />
      <circle cx="180" cy="60" r="3" fill="var(--color-amber)" />
    </svg>
  );
}

// "The follow-through" — a simple stacked list of "tasks" with a check on
// one and a clock on another.
function PillarIllustrationFollowThrough() {
  return (
    <svg viewBox="0 0 200 120" className="w-full max-w-[220px]" aria-hidden="true">
      <rect x="10" y="10" width="180" height="100" rx="10" fill="var(--color-surface)" stroke="var(--color-line)" />
      <rect x="22" y="24" width="156" height="22" rx="6" fill="var(--color-teal-soft)" />
      <circle cx="34" cy="35" r="5" fill="var(--color-teal)" />
      <path d="M32 35 l2 2 l4 -4" stroke="#fff" strokeWidth="1.4" fill="none" strokeLinecap="round" strokeLinejoin="round" />
      <rect x="46" y="31" width="90" height="4" rx="1.5" fill="var(--color-teal-ink)" opacity="0.5" />
      <text x="164" y="38" fontFamily="IBM Plex Sans" fontSize="8" fill="var(--color-teal-ink)" fontWeight="600">APPROVED</text>

      <rect x="22" y="52" width="156" height="22" rx="6" fill="var(--color-amber-soft)" />
      <circle cx="34" cy="63" r="5" stroke="var(--color-amber)" strokeWidth="1.4" fill="none" />
      <path d="M34 60 v3 l2 2" stroke="var(--color-amber)" strokeWidth="1.4" strokeLinecap="round" fill="none" />
      <rect x="46" y="59" width="70" height="4" rx="1.5" fill="var(--color-amber)" opacity="0.5" />
      <text x="164" y="66" fontFamily="IBM Plex Sans" fontSize="8" fill="var(--color-amber)" fontWeight="600">DUE JUL</text>

      <rect x="22" y="80" width="156" height="22" rx="6" fill="var(--color-surface-2)" />
      <circle cx="34" cy="91" r="5" stroke="var(--color-line-2)" strokeWidth="1.4" fill="none" />
      <rect x="46" y="87" width="60" height="4" rx="1.5" fill="var(--color-line-2)" />
      <text x="164" y="94" fontFamily="IBM Plex Sans" fontSize="8" fill="var(--color-ink-3)" fontWeight="600">QUEUED</text>
    </svg>
  );
}

// "The record" — a stack of "change log" rows with timestamps.
function PillarIllustrationRecord() {
  return (
    <svg viewBox="0 0 200 120" className="w-full max-w-[220px]" aria-hidden="true">
      <rect x="10" y="10" width="180" height="100" rx="10" fill="var(--color-surface)" stroke="var(--color-line)" />
      <line x1="30" y1="24" x2="30" y2="102" stroke="var(--color-line-2)" strokeDasharray="2,3" />
      {[24, 44, 64, 84].map((y, i) => (
        <g key={y}>
          <circle cx="30" cy={y + 3} r="3" fill={i === 0 ? 'var(--color-teal)' : 'var(--color-line-2)'} />
          <rect x="42" y={y} width={110 - i * 12} height="4" rx="1.5" fill="var(--color-ink-2)" opacity="0.85" />
          <rect x="42" y={y + 7} width={60 - i * 8} height="3" rx="1.5" fill="var(--color-ink-3)" opacity="0.6" />
          <text x="180" y={y + 5} textAnchor="end" fontFamily="IBM Plex Mono" fontSize="7" fill="var(--color-ink-3)">
            {['12:04', '10:31', '09:58', 'Yesterday'][i]}
          </text>
        </g>
      ))}
    </svg>
  );
}

// -- How-it-works illustrations --------------------------------------------

// Step 1 — clients coming into a roster (a stack of cards with names)
function HowIllustrationOne() {
  return (
    <svg viewBox="0 0 220 140" className="w-full max-w-[240px]" aria-hidden="true">
      <rect x="10" y="18" width="200" height="112" rx="10" fill="var(--color-surface)" stroke="var(--color-line)" />
      {[0, 1, 2].map((i) => (
        <g key={i}>
          <rect x="22" y={30 + i * 32} width="176" height="24" rx="6" fill="var(--color-surface-2)" />
          <circle cx="34" cy={42 + i * 32} r="7" fill={['var(--color-teal-soft)', 'var(--color-amber-soft)', 'var(--color-teal-soft)'][i]} />
          <text x="34" y={45 + i * 32} textAnchor="middle" fontFamily="IBM Plex Sans" fontSize="7" fontWeight="600" fill={['var(--color-teal-ink)', '#7A5720', 'var(--color-teal-ink)'][i]}>
            {['PR', 'AS', 'MK'][i]}
          </text>
          <rect x="48" y={38 + i * 32} width={70 - i * 10} height="4" rx="1.5" fill="var(--color-ink-2)" />
          <rect x="48" y={45 + i * 32} width={40 + i * 6} height="3" rx="1.5" fill="var(--color-ink-3)" opacity="0.7" />
          <rect x="170" y={40 + i * 32} width="22" height="8" rx="4" fill={i === 0 ? 'var(--color-teal-soft)' : 'var(--color-surface-2)'} stroke={i === 0 ? 'var(--color-teal)' : 'var(--color-line-2)'} strokeWidth="0.8" />
        </g>
      ))}
      <path d="M110 4 L110 14 M104 10 L110 14 L116 10" stroke="var(--color-teal)" strokeWidth="1.6" fill="none" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

// Step 2 — modelling the plan: a chart + a slider
function HowIllustrationTwo() {
  return (
    <svg viewBox="0 0 220 140" className="w-full max-w-[240px]" aria-hidden="true">
      <rect x="10" y="10" width="200" height="120" rx="10" fill="var(--color-surface)" stroke="var(--color-line)" />
      {/* chart */}
      <line x1="22" y1="80" x2="200" y2="80" stroke="var(--color-line)" strokeWidth="1" />
      <path d="M24 74 C 60 60, 100 40, 140 28 S 190 22, 198 20" stroke="var(--color-teal)" strokeWidth="2" fill="none" strokeLinecap="round" />
      <path d="M24 74 C 60 68, 100 60, 140 62 S 190 68, 198 74" stroke="var(--color-amber)" strokeWidth="1.6" fill="none" strokeLinecap="round" strokeDasharray="3,3" />
      {/* slider */}
      <line x1="22" y1="106" x2="198" y2="106" stroke="var(--color-line-2)" strokeWidth="3" strokeLinecap="round" />
      <line x1="22" y1="106" x2="120" y2="106" stroke="var(--color-teal)" strokeWidth="3" strokeLinecap="round" />
      <circle cx="120" cy="106" r="7" fill="var(--color-teal)" stroke="var(--color-surface)" strokeWidth="3" />
      <text x="22" y="122" fontFamily="IBM Plex Sans" fontSize="8" fill="var(--color-ink-3)">Withdrawal rate · 4.2%</text>
    </svg>
  );
}

// Step 3 — meeting mode + a "printable report" leave-behind
function HowIllustrationThree() {
  return (
    <svg viewBox="0 0 220 140" className="w-full max-w-[240px]" aria-hidden="true">
      {/* meeting canvas */}
      <rect x="6" y="14" width="150" height="108" rx="10" fill="var(--color-ink)" />
      <text x="81" y="66" textAnchor="middle" fontFamily="IBM Plex Sans" fontWeight="600" fontSize="24" fill="#3FD6BD">72</text>
      <text x="81" y="80" textAnchor="middle" fontFamily="IBM Plex Sans" fontSize="8" fill="rgba(255,255,255,0.55)">READINESS</text>
      <path d="M20 96 C 60 88, 100 74, 148 62" stroke="#3FD6BD" strokeWidth="1.6" fill="none" strokeLinecap="round" />
      {/* printable report */}
      <rect x="140" y="34" width="72" height="90" rx="6" fill="var(--color-surface)" stroke="var(--color-line)" />
      <rect x="148" y="42" width="42" height="4" rx="1.5" fill="var(--color-ink)" />
      <rect x="148" y="50" width="30" height="3" rx="1.5" fill="var(--color-ink-3)" />
      <rect x="148" y="60" width="56" height="20" rx="3" fill="var(--color-teal-soft)" />
      <rect x="148" y="86" width="56" height="3" rx="1.5" fill="var(--color-line-2)" />
      <rect x="148" y="92" width="42" height="3" rx="1.5" fill="var(--color-line-2)" />
      <rect x="148" y="98" width="50" height="3" rx="1.5" fill="var(--color-line-2)" />
    </svg>
  );
}

// -- Feature icons ---------------------------------------------------------
function FeatureIconGoals() {
  return (
    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
      <circle cx="10" cy="10" r="7.5" stroke="currentColor" strokeWidth="1.5" />
      <circle cx="10" cy="10" r="3.5" stroke="currentColor" strokeWidth="1.5" />
      <circle cx="10" cy="10" r="1" fill="currentColor" />
    </svg>
  );
}
function FeatureIconSequence() {
  return (
    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
      <path d="M2 15 C 5 12, 8 6, 11 4 S 17 9, 18 3" stroke="currentColor" strokeWidth="1.6" fill="none" strokeLinecap="round" />
      <path d="M2 15 C 5 16, 8 15, 11 16 S 17 17, 18 14" stroke="currentColor" strokeWidth="1.4" fill="none" strokeLinecap="round" strokeDasharray="2,2" opacity="0.5" />
    </svg>
  );
}
function FeatureIconHistory() {
  return (
    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
      <circle cx="10" cy="10" r="7.5" stroke="currentColor" strokeWidth="1.5" />
      <path d="M10 5.5V10l3 2" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
    </svg>
  );
}
function FeatureIconMeeting() {
  return (
    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
      <rect x="2.5" y="4" width="15" height="10" rx="1.5" stroke="currentColor" strokeWidth="1.5" />
      <polygon points="8,7 13,10 8,13" fill="currentColor" />
      <line x1="6" y1="17" x2="14" y2="17" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
    </svg>
  );
}
function FeatureIconReview() {
  return (
    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
      <path d="M4 10 L8 14 L16 5" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" fill="none" />
      <path d="M2 6l2 -2" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
    </svg>
  );
}
function FeatureIconTemplates() {
  return (
    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
      <rect x="3" y="3" width="7" height="7" rx="1.2" stroke="currentColor" strokeWidth="1.5" />
      <rect x="10.5" y="3" width="6.5" height="4" rx="1.2" stroke="currentColor" strokeWidth="1.5" />
      <rect x="10.5" y="8.5" width="6.5" height="8.5" rx="1.2" stroke="currentColor" strokeWidth="1.5" />
      <rect x="3" y="11.5" width="7" height="5.5" rx="1.2" stroke="currentColor" strokeWidth="1.5" />
    </svg>
  );
}
function FeatureIconRisk() {
  return (
    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
      <path d="M10 3v14M4 10h12" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" />
      <circle cx="10" cy="10" r="7.5" stroke="currentColor" strokeWidth="1.5" />
      <path d="M10 10L14 6" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
    </svg>
  );
}
function FeatureIconAlerts() {
  return (
    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
      <path d="M4 14V9a6 6 0 0112 0v5l1.5 1.5H2.5L4 14z" stroke="currentColor" strokeWidth="1.5" strokeLinejoin="round" fill="none" />
      <path d="M8 17.5a2 2 0 004 0" stroke="currentColor" strokeWidth="1.5" fill="none" strokeLinecap="round" />
    </svg>
  );
}
