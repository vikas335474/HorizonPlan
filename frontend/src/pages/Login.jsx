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
  const from = location.state?.from?.pathname || '/goals';

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
        navigate(from, { replace: true });
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
      await mfaVerify(code);
      navigate(from, { replace: true });
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
    <div className="min-h-screen flex items-center justify-center px-4" style={{ backgroundColor: 'var(--color-paper)' }}>
      <div className="w-full max-w-sm">
        <div className="mb-8 text-center">
          <div
            className="mx-auto mb-3 h-px w-10"
            style={{ backgroundColor: 'var(--color-brass)' }}
            aria-hidden="true"
          />
          <h1 className="text-2xl" style={{ fontFamily: 'var(--font-display)', color: 'var(--color-ink)' }}>
            HorizonPlan
          </h1>
          <p className="mt-1 text-sm text-[var(--color-ink-soft)]">
            {step === 'password' ? 'Sign in to your plan' : 'Two-factor verification'}
          </p>
        </div>

        <div
          className="rounded-sm border p-6"
          style={{ backgroundColor: 'var(--color-paper-raised)', borderColor: 'var(--color-line)' }}
        >
          {step === 'password' && (
            <form onSubmit={handlePasswordSubmit}>
              <label className="block text-sm mb-1 text-[var(--color-ink-soft)]" htmlFor="email">
                Email
              </label>
              <input
                id="email"
                type="email"
                required
                autoComplete="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full mb-4 rounded-sm border px-3 py-2 text-sm outline-none"
                style={{ borderColor: 'var(--color-line)' }}
              />

              <label className="block text-sm mb-1 text-[var(--color-ink-soft)]" htmlFor="password">
                Password
              </label>
              <input
                id="password"
                type="password"
                required
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full mb-4 rounded-sm border px-3 py-2 text-sm outline-none"
                style={{ borderColor: 'var(--color-line)' }}
              />

              {error && (
                <p className="mb-4 text-sm" style={{ color: 'var(--color-alert)' }}>
                  {error}
                </p>
              )}

              <button
                type="submit"
                disabled={submitting}
                className="w-full rounded-sm py-2 text-sm font-medium text-white transition-opacity disabled:opacity-60"
                style={{ backgroundColor: 'var(--color-ink)' }}
              >
                {submitting ? 'Signing in…' : 'Sign in'}
              </button>
            </form>
          )}

          {step === 'mfa' && (
            <form onSubmit={handleMfaSubmit}>
              <p className="mb-4 text-sm text-[var(--color-ink-soft)]">
                Open your authenticator app and enter the 6-digit code for HorizonPlan.
              </p>

              <label className="block text-sm mb-1 text-[var(--color-ink-soft)]" htmlFor="code">
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
                className="w-full mb-4 rounded-sm border px-3 py-2 text-sm outline-none tracking-widest"
                style={{ borderColor: 'var(--color-line)', fontFamily: 'var(--font-mono)' }}
                placeholder="000000"
              />

              {error && (
                <p className="mb-4 text-sm" style={{ color: 'var(--color-alert)' }}>
                  {error}
                </p>
              )}

              <button
                type="submit"
                disabled={submitting || code.length !== 6}
                className="w-full rounded-sm py-2 text-sm font-medium text-white transition-opacity disabled:opacity-60"
                style={{ backgroundColor: 'var(--color-ink)' }}
              >
                {submitting ? 'Verifying…' : 'Verify'}
              </button>

              <button
                type="button"
                onClick={() => { setStep('password'); setError(''); setCode(''); }}
                className="mt-3 w-full text-sm text-center text-[var(--color-ink-soft)] hover:text-[var(--color-ink)]"
              >
                ← Back to sign in
              </button>
            </form>
          )}
        </div>
      </div>
    </div>
  );
}
