import { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function Login() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const from = location.state?.from?.pathname || '/goals';

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setSubmitting(true);
    try {
      await login(email, password);
      navigate(from, { replace: true });
    } catch (err) {
      // login.php gives an identical message for "no such user" and "wrong
      // password" by design (docs/02 3.2) — surface it as-is, don't invent
      // a more specific one.
      setError(err.message || 'Something went wrong. Try again.');
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
          <p className="mt-1 text-sm text-[var(--color-ink-soft)]">Sign in to your plan</p>
        </div>

        <form
          onSubmit={handleSubmit}
          className="rounded-sm border p-6"
          style={{ backgroundColor: 'var(--color-paper-raised)', borderColor: 'var(--color-line)' }}
        >
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
            className="w-full mb-4 rounded-sm border px-3 py-2 text-sm outline-none focus:ring-2"
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
            className="w-full mb-4 rounded-sm border px-3 py-2 text-sm outline-none focus:ring-2"
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
      </div>
    </div>
  );
}
