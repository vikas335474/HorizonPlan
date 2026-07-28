// Route /signup (UNAUTHENTICATED) — self-serve trial signup. Creates a
// distribution-mode firm plus its first firm_admin and logs straight in via
// useAuth.signup (api.signup). Redirects to the app if already authenticated.

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
// Basic client-side email shape check — deliberately loose (matches
// api/signup.php's own FILTER_VALIDATE_EMAIL, not a strict RFC 5322 parser).
// This is just fast feedback; the server remains the source of truth.
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export default function Signup() {
  const { signup } = useAuth();
  const navigate = useNavigate();

  const [companyName, setCompanyName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  // Per-field errors — shown inline, right under the field that's wrong —
  // distinct from `error`, which carries a server/whole-form-level message
  // (duplicate email, signup disabled, rate limited) that isn't about any
  // one field's shape.
  const [fieldErrors, setFieldErrors] = useState({});
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [touched, setTouched] = useState({});

  function validate(values) {
    const errors = {};
    if (!values.companyName.trim()) {
      errors.companyName = 'Firm name is required.';
    }
    if (!values.email.trim()) {
      errors.email = 'Email is required.';
    } else if (!EMAIL_PATTERN.test(values.email.trim())) {
      errors.email = 'Enter a valid email address.';
    }
    if (values.password.length === 0) {
      errors.password = 'Password is required.';
    } else if (values.password.length < 8) {
      errors.password = `At least 8 characters — ${8 - values.password.length} more to go.`;
    }
    return errors;
  }

  function handleBlur(field) {
    setTouched((t) => ({ ...t, [field]: true }));
    setFieldErrors(validate({ companyName, email, password }));
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setTouched({ companyName: true, email: true, password: true });

    const errors = validate({ companyName, email, password });
    setFieldErrors(errors);
    if (Object.keys(errors).length > 0) {
      return; // inline messages already show the specific problem — no generic banner needed
    }

    setSubmitting(true);
    try {
      await signup(companyName, email, password);
      navigate('/', { replace: true });
    } catch (err) {
      // Server-level errors (duplicate email, signup disabled, rate limit)
      // aren't about one field's shape, so they go in the whole-form banner —
      // except a duplicate-email 409, which reads better right under the
      // email field itself, same spot the shape check would have flagged it.
      if (err.status === 409) {
        setFieldErrors((fe) => ({ ...fe, email: err.message }));
      } else {
        setError(err.message || 'Something went wrong. Try again.');
      }
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
          <form onSubmit={handleSubmit} noValidate>
            <label className="block text-sm font-medium mb-1.5 text-[var(--color-ink-2)]" htmlFor="company_name">
              Firm name
            </label>
            <input
              id="company_name"
              type="text"
              autoFocus
              value={companyName}
              onChange={(e) => setCompanyName(e.target.value)}
              onBlur={() => handleBlur('companyName')}
              placeholder="Your Advisory Firm"
              className="field mb-1"
              aria-invalid={touched.companyName && !!fieldErrors.companyName}
            />
            <FieldError show={touched.companyName} message={fieldErrors.companyName} />

            <label className="block text-sm font-medium mb-1.5 mt-4 text-[var(--color-ink-2)]" htmlFor="email">
              Your email
            </label>
            <input
              id="email"
              type="email"
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              onBlur={() => handleBlur('email')}
              placeholder="you@firm.com"
              className="field mb-1"
              aria-invalid={touched.email && !!fieldErrors.email}
            />
            <FieldError show={touched.email} message={fieldErrors.email} />

            <label className="block text-sm font-medium mb-1.5 mt-4 text-[var(--color-ink-2)]" htmlFor="password">
              Password
            </label>
            <input
              id="password"
              type="password"
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              onBlur={() => handleBlur('password')}
              placeholder="At least 8 characters"
              className="field mb-1"
              aria-invalid={touched.password && !!fieldErrors.password}
            />
            <FieldError show={touched.password} message={fieldErrors.password} />

            {error && (
              <p
                className="mt-4 mb-1 text-sm rounded-[var(--radius-ctrl)] px-3 py-2.5"
                style={{ backgroundColor: 'var(--color-alert-soft)', color: 'var(--color-alert)' }}
              >
                {error}
              </p>
            )}

            <button
              type="submit"
              disabled={submitting}
              className="mt-3 w-full rounded-[var(--radius-ctrl)] py-2.5 text-sm font-semibold text-white transition-all duration-150 active:translate-y-px disabled:opacity-60"
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

// Only shown once the field has actually been touched (blurred or submit was
// attempted) — otherwise every field would flash red before the user has had
// a chance to type anything.
function FieldError({ show, message }) {
  if (!show || !message) return null;
  return (
    <p className="text-xs mb-2" style={{ color: 'var(--color-alert)' }}>
      {message}
    </p>
  );
}
