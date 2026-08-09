// Route /login (UNAUTHENTICATED) — password login with the MFA second-factor
// step, "Sign in with Google", and the public "try a live demo" firm picker
// (api.listDemoFirms). The auth actions themselves run through useAuth
// (api.login / mfaVerify / authGoogle / demoLogin). A session that resolves to a
// client role is redirected to /goals.

import { useState, useEffect, useRef } from 'react';
import { useNavigate, useLocation, useSearchParams, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { api, ApiError } from '../lib/api';
import AudienceTracks from '../components/AudienceTracks';

// Which audience this visit of /login is for — 'advisor' | 'individual'.
// /signup (firm trial) and /start-free (personal trial) were always separate
// pages; /login was the one page both audiences land on, and a prior session
// made it dual-audience (see AudienceTracks.jsx's own header) by putting the
// consumer track first with equal weight. That fixed individuals being
// invisible, but reads the opposite way to an advisor evaluating the
// product: the page now looks consumer-first. This resolves it by making
// /login show ONE audience's content at a time — hero, feature list, and
// sign-up CTA all swap together — with a small pill to switch, rather than
// two half-weighted tracks stacked on the same page.
//
// Default (no ?for= param) is 'advisor': CLAUDE.md's own framing is
// "B2B2C ... for Indian MFDs/IFAs and SEBI-RIA firms" first, self-serve
// individual second — so an unmarked visit (typed URL, bookmark, an
// advisor's own return trip) reads as a firm product by default. A consumer
// marketing link can point straight at the other state with ?for=individual.
function audienceFromParams(searchParams) {
  return searchParams.get('for') === 'individual' ? 'individual' : 'advisor';
}

// Login is a two-step flow when the user has MFA enrolled:
//   Step 1: email + password → server returns 202 mfa_required
//   Step 2: 6-digit TOTP code → server issues full session
// Users without MFA enrolled skip step 2 (server issues session on password alone).
//
// "Sign in with Google" is a third, independent path: one click, no OTP step
// — a verified Google login counts as MFA in its own right (see
// auth_google.php). An account that has linked Google can no longer complete
// password-only login (login.php blocks it with google_signin_required) —
// that response is handled below by nudging the user toward the Google button
// instead of showing a generic "wrong password" message.

const GOOGLE_CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID;

export default function Login() {
  const { login, mfaVerify, loginWithGoogle } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams, setSearchParams] = useSearchParams();
  const audience = audienceFromParams(searchParams); // 'advisor' | 'individual'
  function setAudience(next) {
    setSearchParams((prev) => {
      const p = new URLSearchParams(prev);
      p.set('for', next);
      return p;
    }, { replace: true });
  }
  // Where to land after login. If the user was redirected here from a specific
  // protected route, honor that. Otherwise pick a role-appropriate default:
  // advisors/admins land on "/" (Dashboard), clients on "/goals". We must NOT
  // hardcode "/goals" for everyone — that dropped advisors onto the client-only
  // goals list. The role isn't known until login() resolves, so compute the
  // destination there rather than up here.
  const fromPath = location.state?.from?.pathname || null;
  // Set by ResetPassword.jsx after a successful reset — shown once, not
  // persisted, so a refresh of /login doesn't keep re-displaying it.
  const passwordResetDone = location.state?.passwordResetDone;
  // Same mechanism, invite-acceptance variant (docs/09 Session 4).
  const inviteAccepted = location.state?.inviteAccepted;

  function destinationFor(user) {
    if (fromPath) return fromPath;
    return user?.role === 'client' ? '/goals' : '/';
  }

  const [step, setStep] = useState('password'); // 'password' | 'mfa'
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [error, setError] = useState('');
  const [googleSigninRequired, setGoogleSigninRequired] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [googleError, setGoogleError] = useState('');

  async function handlePasswordSubmit(e) {
    e.preventDefault();
    setError('');
    setGoogleSigninRequired(false);
    setSubmitting(true);
    try {
      const result = await login(email, password);
      if (result.mfaRequired) {
        setStep('mfa');
      } else {
        navigate(destinationFor(result.user), { replace: true });
      }
    } catch (err) {
      if (err instanceof ApiError && err.body?.google_signin_required) {
        setGoogleSigninRequired(true);
        setError(err.message || 'This account signs in with Google.');
      } else {
        setError(err.message || 'Something went wrong. Try again.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  async function handleGoogleCredential(credential) {
    setGoogleError('');
    try {
      const user = await loginWithGoogle(credential);
      navigate(destinationFor(user), { replace: true });
    } catch (err) {
      setGoogleError(err.message || 'Google sign-in failed. Try again.');
    }
  }

  async function handleMfaSubmit(e) {
    e.preventDefault();
    setError('');
    setSubmitting(true);
    try {
      const user = await mfaVerify(code);
      navigate(destinationFor(user), { replace: true });
    } catch (err) {
      // mfa_verify.php consumes the pending token on the first wrong attempt —
      // the user must start over from the password step.
      setError(err.message || 'Code incorrect or expired. Please sign in again.');
      setStep('password');
      setCode('');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="min-h-screen grid lg:grid-cols-[1.05fr_1fr]">
      <BrandPanel audience={audience} />

      {/* Form column */}
      <div className="flex items-center justify-center px-5 py-10" style={{ backgroundColor: 'var(--color-canvas)' }}>
        <div className="w-full max-w-sm animate-rise">
          {/* Compact wordmark — the only brand shown on mobile, where the hero is hidden */}
          <div className="lg:hidden mb-8 flex items-center gap-2.5">
            <HorizonMark size={26} />
            <span className="text-[17px] font-semibold tracking-tight text-[var(--color-ink)]">HorizonPlan</span>
          </div>

          {/* The audience switch. Visible on both mobile and desktop (unlike
              the hero, which is desktop-only) since it's what decides the
              hero's content on desktop and the CTA/demo track below on both. */}
          {step === 'password' && <AudiencePill audience={audience} onChange={setAudience} />}

          <div className="mb-6">
            <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">
              {step === 'password' ? 'Welcome back' : 'Two-factor verification'}
            </h1>
            <p className="mt-1.5 text-sm text-[var(--color-ink-2)]">
              {step === 'password'
                ? 'Sign in to your HorizonPlan workspace.'
                : 'Enter the 6-digit code from your authenticator app.'}
            </p>
          </div>

          <div
            className="rounded-[var(--radius-lg)] border p-6 sm:p-7"
            style={{ backgroundColor: 'var(--color-surface)', borderColor: 'var(--color-line)', boxShadow: 'var(--shadow-md)' }}
          >
            {step === 'password' && (
              <form onSubmit={handlePasswordSubmit}>
                {passwordResetDone && (
                  <p
                    className="mb-4 text-sm rounded-[var(--radius-ctrl)] px-3 py-2.5"
                    style={{ backgroundColor: 'var(--color-teal-soft)', color: 'var(--color-teal-ink)' }}
                  >
                    Password updated. Sign in with your new password.
                  </p>
                )}
                {inviteAccepted && (
                  <p
                    className="mb-4 text-sm rounded-[var(--radius-ctrl)] px-3 py-2.5"
                    style={{ backgroundColor: 'var(--color-teal-soft)', color: 'var(--color-teal-ink)' }}
                  >
                    Account activated. Sign in with your new password.
                  </p>
                )}

                <label className="block text-sm font-medium mb-1.5 text-[var(--color-ink-2)]" htmlFor="email">
                  Email
                </label>
                <input
                  id="email"
                  type="email"
                  required
                  autoComplete="email"
                  autoFocus
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="you@firm.com"
                  className="field mb-4"
                />

                <div className="flex items-center justify-between mb-1.5">
                  <label className="text-sm font-medium text-[var(--color-ink-2)]" htmlFor="password">
                    Password
                  </label>
                  <Link to="/forgot-password" className="text-xs text-[var(--color-ink-2)] hover:text-[var(--color-ink)]">
                    Forgot password?
                  </Link>
                </div>
                <input
                  id="password"
                  type="password"
                  required
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="••••••••"
                  className="field mb-5"
                />

                {error && <FormError>{error}</FormError>}

                <button
                  type="submit"
                  disabled={submitting}
                  className="w-full rounded-[var(--radius-ctrl)] py-2.5 text-sm font-semibold text-white transition-all duration-150 active:translate-y-px disabled:opacity-60"
                  style={{ background: 'var(--grad-ink)', boxShadow: 'var(--shadow-sm)' }}
                >
                  {submitting ? 'Signing in…' : 'Sign in'}
                </button>
              </form>
            )}

            {step === 'password' && (
              <GoogleSignInSection
                onCredential={handleGoogleCredential}
                error={googleError}
                highlight={googleSigninRequired}
              />
            )}

            {step === 'mfa' && (
              <form onSubmit={handleMfaSubmit}>
                <label className="block text-sm font-medium mb-1.5 text-[var(--color-ink-2)]" htmlFor="code">
                  Authenticator code
                </label>
                <input
                  id="code"
                  type="text"
                  inputMode="numeric"
                  pattern="[0-9]{6}"
                  maxLength={6}
                  required
                  autoComplete="one-time-code"
                  autoFocus
                  value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
                  className="field mb-5 tnum text-center text-2xl tracking-[0.5em]"
                  placeholder="000000"
                />

                {error && <FormError>{error}</FormError>}

                <button
                  type="submit"
                  disabled={submitting || code.length !== 6}
                  className="w-full rounded-[var(--radius-ctrl)] py-2.5 text-sm font-semibold text-white transition-all duration-150 active:translate-y-px disabled:opacity-60"
                  style={{ background: 'var(--grad-ink)', boxShadow: 'var(--shadow-sm)' }}
                >
                  {submitting ? 'Verifying…' : 'Verify & continue'}
                </button>

                <button
                  type="button"
                  onClick={() => { setStep('password'); setError(''); setCode(''); }}
                  className="mt-3 w-full text-sm text-center text-[var(--color-ink-2)] hover:text-[var(--color-ink)]"
                >
                  ← Back to sign in
                </button>
              </form>
            )}
          </div>

          {step === 'password' && (
            <p className="mt-4 text-sm text-center text-[var(--color-ink-2)]">
              New here?{' '}
              {audience === 'individual' ? (
                <Link to="/start-free" className="font-medium text-[var(--color-teal-ink)] hover:underline">
                  Start planning free
                </Link>
              ) : (
                <Link to="/signup" className="font-medium text-[var(--color-teal-ink)] hover:underline">
                  Start a firm trial
                </Link>
              )}
            </p>
          )}

          {step === 'password' && <TryDemoSection audience={audience} onSwitchAudience={setAudience} />}

          <p className="mt-6 text-center text-xs text-[var(--color-ink-3)]">
            Protected by two-factor authentication · Bank-grade session security
          </p>
        </div>
      </div>
    </div>
  );
}

// A small "try a live demo, no signup needed" picker. Fetches the list of
// seeded demo firms (empty if nothing has been seeded — renders nothing in
// that case, same graceful-degradation precedent as GoogleSignInSection with
// no configured Client ID) and logs straight into whichever one is picked via
// demo_login.php — no credentials involved anywhere in this flow.
function TryDemoSection({ audience, onSwitchAudience }) {
  const { demoLogin, demoLoginPersonal } = useAuth();
  const navigate = useNavigate();
  const [firms, setFirms] = useState([]);
  const [busySlug, setBusySlug] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    api.listDemoFirms()
      .then((res) => { if (!cancelled) setFirms(res.firms || []); })
      .catch(() => { /* silently hide the section on any failure */ });
    return () => { cancelled = true; };
  }, []);



  async function tryFirm(slug) {
    setError('');
    setBusySlug(slug);
    try {
      const user = await demoLogin(slug);
      navigate(user?.role === 'client' ? '/goals' : '/', { replace: true });
    } catch (err) {
      setError(err.message || 'Could not open that demo. Try again.');
    } finally {
      setBusySlug('');
    }
  }

  async function tryPersonal() {
    setError('');
    setBusySlug('__personal');
    try {
      await demoLoginPersonal();
      navigate('/goals');
    } catch (err) {
      setError(err.message || 'Could not open the personal demo. Try again.');
    } finally {
      setBusySlug('');
    }
  }

  return (
    <AudienceTracks
      audience={audience}
      onSwitchAudience={() => onSwitchAudience(audience === 'individual' ? 'advisor' : 'individual')}
      firms={firms}
      busySlug={busySlug}
      onFirm={tryFirm}
      onPersonal={tryPersonal}
      error={error}
    />
  );
}

// The audience switch — a small segmented pill, not a modal or a separate
// page, so changing your mind costs one click. Drives BrandPanel's hero
// content (desktop) and AudienceTracks' single CTA track (both). Persisted
// in the URL (?for=) so a direct link can point at either state and a
// refresh doesn't reset the choice.
function AudiencePill({ audience, onChange }) {
  const optionClass = (active) =>
    `rounded-full px-3 py-1.5 text-[12px] font-medium transition-colors ${active ? 'text-white' : 'text-[var(--color-ink-2)] hover:text-[var(--color-ink)]'}`;
  return (
    <div
      className="mb-5 inline-flex rounded-full border p-0.5"
      style={{ borderColor: 'var(--color-line-2)', backgroundColor: 'var(--color-surface-2)' }}
      role="tablist"
      aria-label="Who are you?"
    >
      <button
        type="button"
        role="tab"
        aria-selected={audience === 'advisor'}
        onClick={() => onChange('advisor')}
        className={optionClass(audience === 'advisor')}
        style={audience === 'advisor' ? { backgroundColor: 'var(--color-teal)' } : undefined}
      >
        Advisers &amp; firms
      </button>
      <button
        type="button"
        role="tab"
        aria-selected={audience === 'individual'}
        onClick={() => onChange('individual')}
        className={optionClass(audience === 'individual')}
        style={audience === 'individual' ? { backgroundColor: 'var(--color-teal)' } : undefined}
      >
        Planning for yourself
      </button>
    </div>
  );
}

// Renders Google's own "Sign in with Google" button via Identity Services
// (loaded globally in index.html). Renders nothing if no Client ID is
// configured for this deployment (see DEPLOY.md) rather than showing a
// broken/non-functional button.
function GoogleSignInSection({ onCredential, error, highlight }) {
  const buttonRef = useRef(null);
  const onCredentialRef = useRef(onCredential);
  onCredentialRef.current = onCredential;

  useEffect(() => {
    if (!GOOGLE_CLIENT_ID) return;

    let cancelled = false;
    let attempts = 0;

    // index.html's <script> tag loads asynchronously — poll briefly for
    // window.google rather than assuming it's ready by mount time.
    function tryInit() {
      if (cancelled) return;
      if (!window.google?.accounts?.id) {
        attempts += 1;
        if (attempts < 40) setTimeout(tryInit, 100);
        return;
      }
      window.google.accounts.id.initialize({
        client_id: GOOGLE_CLIENT_ID,
        callback: (response) => onCredentialRef.current(response.credential),
      });
      if (buttonRef.current) {
        window.google.accounts.id.renderButton(buttonRef.current, {
          type: 'standard',
          theme: 'outline',
          size: 'large',
          width: 320,
        });
      }
    }
    tryInit();
    return () => { cancelled = true; };
  }, []);

  if (!GOOGLE_CLIENT_ID) return null;

  return (
    <div className="mt-5">
      <div className="flex items-center gap-3 mb-4">
        <div className="h-px flex-1" style={{ backgroundColor: 'var(--color-line)' }} />
        <span className="text-xs text-[var(--color-ink-3)]">or</span>
        <div className="h-px flex-1" style={{ backgroundColor: 'var(--color-line)' }} />
      </div>
      <div
        ref={buttonRef}
        className={`flex justify-center rounded-[var(--radius-ctrl)] ${highlight ? 'ring-2 ring-offset-2' : ''}`}
        style={highlight ? { '--tw-ring-color': 'var(--color-amber)' } : undefined}
      />
      {error && (
        <p className="mt-3 text-sm rounded-[var(--radius-ctrl)] px-3 py-2.5 text-center"
           style={{ backgroundColor: 'var(--color-alert-soft)', color: 'var(--color-alert)' }}>
          {error}
        </p>
      )}
    </div>
  );
}

function FormError({ children }) {
  return (
    <p
      className="mb-4 text-sm rounded-[var(--radius-ctrl)] px-3 py-2.5"
      style={{ backgroundColor: 'var(--color-alert-soft)', color: 'var(--color-alert)' }}
    >
      {children}
    </p>
  );
}

function HorizonMark({ size = 22 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 22 22" fill="none" aria-hidden="true">
      <line x1="2" y1="15" x2="20" y2="15" stroke="var(--color-line-2)" strokeWidth="1.5" />
      <path d="M4 15 L11 6 L18 11" stroke="var(--color-teal)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
      <circle cx="18" cy="11" r="2" fill="var(--color-amber)" />
    </svg>
  );
}

// Per-audience hero copy. One headline, one blurb, one feature list — never
// both audiences' content on screen together, which was the ambiguity this
// split fixes. Kept as plain data so BrandPanel itself stays presentational.
const AUDIENCE_COPY = {
  advisor: {
    headline: 'Retirement plans your clients can actually feel',
    blurb:
      'Multi-goal retirement planning built for Indian markets — inflation, withdrawal rates, and the sequence-of-returns risk spreadsheets hide. Presentation-ready for the room, audit-ready for the file.',
    eyebrow: 'Built for advisers & firms',
    bullets: [
      'Meeting Mode — presentation-ready, for the room',
      'Real historical replay of a bad early decade',
      'Your own templates & risk questionnaire, approval-gated',
    ],
  },
  individual: {
    headline: 'Will the money last?',
    blurb:
      'Retirement planning built for Indian markets — inflation, withdrawal rates, and the sequence-of-returns risk spreadsheets hide. No adviser needed.',
    eyebrow: 'Planning for yourself',
    bullets: [
      'See the year you could stop working — and a countdown to it',
      'One readiness score, in plain language',
      'Plan together with your partner, each keeping your own plan',
    ],
  },
};

// The brand column: a deep gradient hero with the product promise and an
// animated "rising corpus over the horizon" motif. Hidden below lg — mobile
// gets the compact wordmark instead so the fold stays focused on the form.
// Content is audience-specific (see AUDIENCE_COPY) — deliberately ONE
// headline/blurb/feature-list at a time, not both audiences' copy stacked,
// so an advisor visiting never reads a consumer-first page and vice versa.
function BrandPanel({ audience }) {
  const copy = AUDIENCE_COPY[audience] ?? AUDIENCE_COPY.advisor;
  return (
    <div className="relative hidden lg:flex flex-col justify-between overflow-hidden p-12" style={{ background: 'var(--grad-hero)' }}>
      {/* Soft light bloom */}
      <div
        className="pointer-events-none absolute -top-24 -right-16 h-96 w-96 rounded-full opacity-40"
        style={{ background: 'radial-gradient(circle, rgba(14,124,107,0.55), transparent 65%)' }}
      />

      <div className="relative flex items-center gap-2.5">
        <span className="flex h-9 w-9 items-center justify-center rounded-xl" style={{ backgroundColor: 'rgba(255,255,255,0.08)' }}>
          <svg width="20" height="20" viewBox="0 0 22 22" fill="none" aria-hidden="true">
            <line x1="2" y1="15" x2="20" y2="15" stroke="rgba(255,255,255,0.35)" strokeWidth="1.5" />
            <path d="M4 15 L11 6 L18 11" stroke="#3FD6BD" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
            <circle cx="18" cy="11" r="2" fill="var(--color-amber)" />
          </svg>
        </span>
        <span className="text-[17px] font-semibold tracking-tight text-white">HorizonPlan</span>
      </div>

      <div className="relative max-w-md">
        {/* Animated rising-corpus line */}
        <svg viewBox="0 0 320 120" fill="none" className="mb-8 w-64" aria-hidden="true">
          <line x1="0" y1="100" x2="320" y2="100" stroke="rgba(255,255,255,0.14)" strokeWidth="1.5" />
          <path
            d="M8 96 C 70 92, 110 70, 160 54 S 250 18, 312 12"
            stroke="#3FD6BD"
            strokeWidth="3"
            strokeLinecap="round"
            fill="none"
            strokeDasharray="240"
            style={{ animation: 'drawLine 1.8s var(--ease-out) 0.2s both' }}
          />
          <circle cx="312" cy="12" r="5" fill="var(--color-amber)" style={{ animation: 'floatY 4s ease-in-out 2s infinite' }} />
        </svg>

        {/* Single-audience headline + blurb, from AUDIENCE_COPY. Larger type
            than a plain sign-in page would use (34px) because the old
            dual-audience version needed real typographic hierarchy to fit
            two headlines' worth of content on one screen; a single headline
            keeps that same weight now that there's only one. */}
        <p className="mb-2.5 text-[10px] font-semibold uppercase tracking-[0.1em]" style={{ color: '#3FD6BD' }}>
          {copy.eyebrow}
        </p>
        <h2 className="text-[34px] leading-[1.12] font-semibold tracking-[-0.02em] text-white">
          {copy.headline}
        </h2>
        <p className="mt-4 text-[15px] leading-relaxed" style={{ color: 'rgba(255,255,255,0.72)' }}>
          {copy.blurb}
        </p>

        <ul className="mt-7 space-y-2.5">
          {copy.bullets.map((b) => <FeatureBullet key={b}>{b}</FeatureBullet>)}
        </ul>
      </div>

      <div className="relative flex items-center gap-6 text-xs" style={{ color: 'rgba(255,255,255,0.55)' }}>
        <TrustPoint>Tenant-isolated</TrustPoint>
        <TrustPoint>2FA secured</TrustPoint>
        <TrustPoint>SEBI-aware disclosures</TrustPoint>
      </div>
    </div>
  );
}

function FeatureBullet({ children }) {
  return (
    <li className="flex items-start gap-2.5 text-[13px] leading-snug" style={{ color: 'rgba(255,255,255,0.8)' }}>
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none" className="mt-0.5 shrink-0" aria-hidden="true">
        <path d="M3 7.5l2.5 2.5L11 4" stroke="#3FD6BD" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
      {children}
    </li>
  );
}

function TrustPoint({ children }) {
  return (
    <span className="flex items-center gap-1.5">
      <svg width="13" height="13" viewBox="0 0 14 14" fill="none" aria-hidden="true">
        <path d="M3 7.5l2.5 2.5L11 4" stroke="#3FD6BD" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
      {children}
    </span>
  );
}
