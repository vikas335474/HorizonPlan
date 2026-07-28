// Routes /reset-password and /accept-invite (UNAUTHENTICATED) — step 2 of the
// token flows: redeem an emailed token to set a new password
// (api.confirmPasswordReset). The `mode` prop switches the copy between a plain
// password reset ('reset') and advisor invite activation ('invite'); the
// redemption mechanism is identical (docs/09 Session 4).

import { useState } from 'react';
import { useSearchParams, useNavigate, Link } from 'react-router-dom';
import { api } from '../lib/api';

// Step 2 of the self-service recovery flow: the token from the emailed link
// (query param, never a route param — it must not get logged as part of the
// route path in any server access log) is redeemed for a new password.
//
// Also doubles as the invite-acceptance landing page (docs/09 Session 4,
// route /accept-invite, mode="invite") — same token/redemption mechanism
// (password_reset_confirm.php doesn't branch on purpose, see
// InviteTokens.php), only the copy differs: "welcome, set your password"
// instead of "reset your password."
export default function ResetPassword({ mode = 'reset' }) {
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token') || '';
  const navigate = useNavigate();
  const isInvite = mode === 'invite';

  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');

    if (newPassword.length < 8) {
      setError('New password must be at least 8 characters.');
      return;
    }
    if (newPassword !== confirmPassword) {
      setError('Passwords do not match.');
      return;
    }

    setSubmitting(true);
    try {
      await api.confirmPasswordReset(token, newPassword);
      navigate('/login', { replace: true, state: { passwordResetDone: !isInvite, inviteAccepted: isInvite } });
    } catch (err) {
      setError(err.message || (isInvite
        ? 'This invite link is invalid or has expired. Ask your admin to send a new one.'
        : 'This reset link is invalid or has expired. Request a new one.'));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center px-5 py-10" style={{ backgroundColor: 'var(--color-canvas)' }}>
      <div className="w-full max-w-sm animate-rise">
        <div className="mb-6">
          <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">
            {isInvite ? 'Welcome to HorizonPlan' : 'Set a new password'}
          </h1>
          <p className="mt-1.5 text-sm text-[var(--color-ink-2)]">
            {isInvite ? 'Set a password to activate your account.' : 'Choose a new password for your account.'}
          </p>
        </div>

        <div
          className="rounded-[var(--radius-lg)] border p-6 sm:p-7"
          style={{ backgroundColor: 'var(--color-surface)', borderColor: 'var(--color-line)', boxShadow: 'var(--shadow-md)' }}
        >
          {!token ? (
            <p
              className="text-sm rounded-[var(--radius-ctrl)] px-3 py-2.5"
              style={{ backgroundColor: 'var(--color-alert-soft)', color: 'var(--color-alert)' }}
            >
              {isInvite
                ? 'This link is missing its invite token. Ask your admin to send a new one.'
                : 'This link is missing its reset token. Request a new one from the sign-in page.'}
            </p>
          ) : (
            // noValidate: without it, the browser's own constraint validation
            // (minLength=8 below) silently blocks the submit before
            // handleSubmit's own matching length check ever runs, leaving no
            // visible error at all — the same class of bug found and fixed
            // on GoalDetail.jsx's edit forms (see CLAUDE.md).
            <form onSubmit={handleSubmit} noValidate>
              <label className="block text-sm font-medium mb-1.5 text-[var(--color-ink-2)]" htmlFor="new-password">
                New password
              </label>
              <input
                id="new-password"
                type="password"
                required
                autoComplete="new-password"
                autoFocus
                minLength={8}
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                placeholder="••••••••"
                className="field mb-4"
              />

              <label className="block text-sm font-medium mb-1.5 text-[var(--color-ink-2)]" htmlFor="confirm-password">
                Confirm new password
              </label>
              <input
                id="confirm-password"
                type="password"
                required
                autoComplete="new-password"
                minLength={8}
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                placeholder="••••••••"
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
                {submitting ? 'Saving…' : isInvite ? 'Activate account' : 'Update password'}
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
