// Route /forgot-password (UNAUTHENTICATED) — step 1 of self-service password
// reset. Calls api.requestPasswordReset and always shows the same neutral
// "if that email exists, a link is on its way" confirmation, so it can't be used
// to enumerate accounts.

import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';

// Step 1 of the self-service recovery flow: request a reset link by email.
// The response is intentionally identical whether or not the email is
// registered (see password_reset_request.php) — this page must never branch
// its own copy on that either, or it would leak the same thing server-side
// anti-enumeration is designed to hide.
export default function ForgotPassword() {
  const [email, setEmail] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState('');

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setSubmitting(true);
    try {
      await api.requestPasswordReset(email);
      setSent(true);
    } catch (err) {
      // Only a genuine transport/server failure lands here (e.g. a 500) —
      // "email not found" never throws, by design of the endpoint.
      setError(err.message || 'Something went wrong. Try again.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center px-5 py-10" style={{ backgroundColor: 'var(--color-canvas)' }}>
      <div className="w-full max-w-sm animate-rise">
        <div className="mb-6">
          <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">Reset your password</h1>
          <p className="mt-1.5 text-sm text-[var(--color-ink-2)]">
            Enter your account email and we'll send you a link to set a new password.
          </p>
        </div>

        <div
          className="rounded-[var(--radius-lg)] border p-6 sm:p-7"
          style={{ backgroundColor: 'var(--color-surface)', borderColor: 'var(--color-line)', boxShadow: 'var(--shadow-md)' }}
        >
          {sent ? (
            <p
              className="text-sm rounded-[var(--radius-ctrl)] px-3 py-2.5"
              style={{ backgroundColor: 'var(--color-teal-soft)', color: 'var(--color-teal-ink)' }}
            >
              If that email is registered, a password reset link has been sent. Check your inbox — the link expires in 30 minutes.
            </p>
          ) : (
            <form onSubmit={handleSubmit}>
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
                className="field mb-5"
              />

              {error && (
                <p
                  className="mb-4 text-sm rounded-[var(--radius-ctrl)] px-3 py-2.5"
                  style={{ backgroundColor: 'var(--color-alert-soft)', color: 'var(--color-alert)' }}
                >
                  {error}
                </p>
              )}

              <button
                type="submit"
                disabled={submitting}
                className="w-full rounded-[var(--radius-ctrl)] py-2.5 text-sm font-semibold text-white transition-all duration-150 active:translate-y-px disabled:opacity-60"
                style={{ background: 'var(--grad-ink)', boxShadow: 'var(--shadow-sm)' }}
              >
                {submitting ? 'Sending…' : 'Send reset link'}
              </button>
            </form>
          )}

          <Link
            to="/login"
            className="mt-4 block text-sm text-center text-[var(--color-ink-2)] hover:text-[var(--color-ink)]"
          >
            ← Back to sign in
          </Link>
        </div>
      </div>
    </div>
  );
}
