import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

// Self-serve trial signup — the one deliberate exception to this app's
// otherwise admin-provisioned onboarding model (see api/signup.php's own
// docblock). Always creates a distribution-mode tenant; there is no
// advisory-mode choice here at all, by design. A brand-new account is never
// MFA-enrolled yet, so landing on "/" immediately hits the same mandatory-
// enrollment redirect to /settings that any fresh admin-created account
// would — this page doesn't special-case that, it just reuses it.
export default function Signup() {
  const { signup } = useAuth();
  const navigate = useNavigate();

  const [companyName, setCompanyName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setSubmitting(true);
    try {
      await signup(companyName, email, password);
      navigate('/', { replace: true });
    } catch (err) {
      setError(err.message || 'Something went wrong. Try again.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center px-5 py-10" style={{ backgroundColor: 'var(--color-canvas)' }}>
      <div className="w-full max-w-sm animate-rise">
        <div className="mb-6">
          <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">Start your free trial</h1>
          <p className="mt-1.5 text-sm text-[var(--color-ink-2)]">
            Create your firm's workspace in a minute — no credit card, no waiting on an invite.
          </p>
        </div>

        <div
          className="rounded-[var(--radius-lg)] border p-6 sm:p-7"
          style={{ backgroundColor: 'var(--color-surface)', borderColor: 'var(--color-line)', boxShadow: 'var(--shadow-md)' }}
        >
          <form onSubmit={handleSubmit}>
            <label className="block text-sm font-medium mb-1.5 text-[var(--color-ink-2)]" htmlFor="company_name">
              Firm name
            </label>
            <input
              id="company_name"
              type="text"
              required
              autoFocus
              value={companyName}
              onChange={(e) => setCompanyName(e.target.value)}
              placeholder="Your Advisory Firm"
              className="field mb-4"
            />

            <label className="block text-sm font-medium mb-1.5 text-[var(--color-ink-2)]" htmlFor="email">
              Your email
            </label>
            <input
              id="email"
              type="email"
              required
              autoComplete="email"
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
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="At least 8 characters"
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
              {submitting ? 'Creating your workspace…' : 'Create my workspace'}
            </button>
          </form>

          <p className="mt-4 text-xs text-center text-[var(--color-ink-3)]">
            Your workspace starts in distribution mode. Advisory-mode compliance status is granted
            separately, only after a real review.
          </p>

          <Link
            to="/login"
            className="mt-4 block text-sm text-center text-[var(--color-ink-2)] hover:text-[var(--color-ink)]"
          >
            Already have an account? Sign in
          </Link>
        </div>
      </div>
    </div>
  );
}
