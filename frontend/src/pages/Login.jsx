import { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

// Login is a two-step flow when the user has MFA enrolled:
//   Step 1: email + password → server returns 202 mfa_required
//   Step 2: 6-digit TOTP code → server issues full session
// Users without MFA enrolled skip step 2 (server issues session on password alone).

export default function Login() {
  const { login, mfaVerify } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  // Where to land after login. If the user was redirected here from a specific
  // protected route, honor that. Otherwise pick a role-appropriate default:
  // advisors/admins land on "/" (Dashboard), clients on "/goals". We must NOT
  // hardcode "/goals" for everyone — that dropped advisors onto the client-only
  // goals list. The role isn't known until login() resolves, so compute the
  // destination there rather than up here.
  const fromPath = location.state?.from?.pathname || null;

  function destinationFor(user) {
    if (fromPath) return fromPath;
    return user?.role === 'client' ? '/goals' : '/';
  }

  const [step, setStep] = useState('password'); // 'password' | 'mfa'
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function handlePasswordSubmit(e) {
    e.preventDefault();
    setError('');
    setSubmitting(true);
    try {
      const result = await login(email, password);
      if (result.mfaRequired) {
        setStep('mfa');
      } else {
        navigate(destinationFor(result.user), { replace: true });
      }
    } catch (err) {
      setError(err.message || 'Something went wrong. Try again.');
    } finally {
      setSubmitting(false);
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
      <BrandPanel />

      {/* Form column */}
      <div className="flex items-center justify-center px-5 py-10" style={{ backgroundColor: 'var(--color-canvas)' }}>
        <div className="w-full max-w-sm animate-rise">
          {/* Compact wordmark — the only brand shown on mobile, where the hero is hidden */}
          <div className="lg:hidden mb-8 flex items-center gap-2.5">
            <HorizonMark size={26} />
            <span className="text-[17px] font-semibold tracking-tight text-[var(--color-ink)]">HorizonPlan</span>
          </div>

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

                <label className="block text-sm font-medium mb-1.5 text-[var(--color-ink-2)]" htmlFor="password">
                  Password
                </label>
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

          <p className="mt-6 text-center text-xs text-[var(--color-ink-3)]">
            Protected by two-factor authentication · Bank-grade session security
          </p>
        </div>
      </div>
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

// The brand column: a deep gradient hero with the product promise and an
// animated "rising corpus over the horizon" motif. Hidden below lg — mobile
// gets the compact wordmark instead so the fold stays focused on the form.
function BrandPanel() {
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

        <h2 className="text-[28px] leading-tight font-semibold tracking-tight text-white">
          Retirement plans your clients can actually feel.
        </h2>
        <p className="mt-4 text-[15px] leading-relaxed" style={{ color: 'rgba(255,255,255,0.68)' }}>
          Model corpus, inflation, and withdrawal rates built for Indian markets — and
          show clients the sequence-of-returns risk that spreadsheets hide.
        </p>
      </div>

      <div className="relative flex items-center gap-6 text-xs" style={{ color: 'rgba(255,255,255,0.55)' }}>
        <TrustPoint>Tenant-isolated</TrustPoint>
        <TrustPoint>2FA secured</TrustPoint>
        <TrustPoint>SEBI-aware disclosures</TrustPoint>
      </div>
    </div>
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
