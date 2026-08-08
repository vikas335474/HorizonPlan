// Route /start-free — self-serve signup for an INDIVIDUAL planning for
// themselves (sql/033). The consumer counterpart to /signup, which creates a
// firm and an advisor.
//
// Deliberately three fields and no jargon: someone arriving here is not
// evaluating advisory software, they are trying to answer "will I be OK?".
// On success the server has already issued the session, so this goes straight
// into the guided setup at /start rather than dropping them on an empty app.
//
// Data: useAuth().signupPersonal → api.signupPersonal → signup_personal.php.

import { useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { Card, Button } from '../components/ui';

export default function PersonalSignup() {
  const { user, signupPersonal } = useAuth();
  const navigate = useNavigate();
  const [fullName, setFullName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  // Already signed in — nothing to create.
  if (user) return <Navigate to="/" replace />;

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    if (password.length < 8) {
      setError('Please choose a password of at least 8 characters.');
      return;
    }
    setBusy(true);
    try {
      await signupPersonal(fullName.trim(), email.trim(), password);
      navigate('/start');
    } catch (err) {
      setError(err.message || 'Could not create your account.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center px-5 py-10">
      <div className="w-full max-w-md">
        <div className="mb-6 text-center">
          <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">
            Plan your own retirement
          </h1>
          <p className="mt-2 text-sm text-[var(--color-ink-2)]">
            Answer a few questions and see where you stand — how much you'll have, how long it
            lasts, and how far away it is. Free, and yours alone.
          </p>
        </div>

        <Card className="p-6">
          <form onSubmit={handleSubmit} noValidate>
            <label className="block text-sm font-medium text-[var(--color-ink)]">
              Your name
              <input
                type="text"
                required
                autoFocus
                value={fullName}
                onChange={(e) => setFullName(e.target.value)}
                placeholder="Priya Sharma"
                className="field mt-1.5 w-full"
              />
            </label>

            <label className="mt-4 block text-sm font-medium text-[var(--color-ink)]">
              Email
              <input
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="you@example.com"
                className="field mt-1.5 w-full"
              />
            </label>

            <label className="mt-4 block text-sm font-medium text-[var(--color-ink)]">
              Password
              <input
                type="password"
                required
                minLength={8}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="At least 8 characters"
                className="field mt-1.5 w-full"
              />
            </label>

            {error && (
              <p className="mt-3 text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
            )}

            <Button type="submit" disabled={busy} className="mt-5 w-full justify-center">
              {busy ? 'Creating your account…' : 'Start planning'}
            </Button>
          </form>

          {/* Said plainly and up front, not buried in a footer: this is a
              tool you drive yourself. Someone expecting an adviser should
              know before they sign up, not after. */}
          <p className="mt-4 text-xs leading-relaxed text-[var(--color-ink-3)]">
            This is a self-directed planning tool. It shows illustrations based on the figures you
            enter — it isn't investment advice, and no adviser reviews your plan.
          </p>
        </Card>

        <p className="mt-5 text-center text-sm text-[var(--color-ink-2)]">
          Already have an account? <Link to="/login" className="underline">Sign in</Link>
        </p>
        <p className="mt-2 text-center text-xs text-[var(--color-ink-3)]">
          Are you an advisory firm? <Link to="/signup" className="underline">Set up your practice instead</Link>
        </p>
      </div>
    </div>
  );
}
