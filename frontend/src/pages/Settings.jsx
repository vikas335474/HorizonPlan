import { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import AppHeader from '../components/AppHeader';
import { Card, Button, Badge } from '../components/ui';

// Settings: MFA enrollment + password change. Reachable from the header on any
// authenticated route; also the redirect target of the soft MFA gate (see
// ProtectedRoute's requireMfa). When arrived at via that gate,
// location.state.mfaRequired is true and we surface a banner explaining why.
export default function Settings() {
  const { user, refreshSession } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const mfaRequired = location.state?.mfaRequired === true;

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-2xl px-5 py-8">
        <h1 className="text-xl font-semibold tracking-tight text-[var(--color-ink)]">Settings</h1>
        <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
          Manage two-factor authentication and your password.
        </p>

        {mfaRequired && !user?.mfaEnrolled && (
          <div
            className="mt-5 rounded-[var(--radius-card)] border px-4 py-3"
            style={{ borderColor: 'var(--color-amber)', backgroundColor: 'var(--color-amber-soft)' }}
          >
            <p className="text-sm font-medium" style={{ color: 'var(--color-amber)' }}>
              Two-factor authentication is required
            </p>
            <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
              Set up an authenticator app below to continue to the rest of HorizonPlan.
            </p>
          </div>
        )}

        <div className="mt-6 space-y-5">
          <MfaSection
            enrolled={!!user?.mfaEnrolled}
            onEnrolled={refreshSession}
            mfaRequired={mfaRequired}
            onContinue={() => navigate('/', { replace: true })}
          />
          <PasswordSection />
        </div>
      </main>
    </div>
  );
}

function SectionCard({ title, description, children }) {
  return (
    <Card className="p-6">
      <h2 className="text-base font-semibold text-[var(--color-ink)]">{title}</h2>
      {description && <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">{description}</p>}
      <div className="mt-4">{children}</div>
    </Card>
  );
}

function MfaSection({ enrolled, onEnrolled, mfaRequired, onContinue }) {
  // 'idle' → user hasn't started; 'setup' → secret issued, awaiting confirm code;
  // 'done' → just confirmed in this session (before session refresh lands).
  const [phase, setPhase] = useState('idle');
  const [secret, setSecret] = useState('');
  const [uri, setUri] = useState('');
  const [code, setCode] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  if (enrolled) {
    return (
      <SectionCard
        title="Two-factor authentication"
        description="An authenticator code is required every time you sign in."
      >
        <div className="flex items-center gap-3">
          <Badge fg="var(--color-teal-ink)" bg="var(--color-teal-soft)">Enabled</Badge>
          <span className="text-sm text-[var(--color-ink-2)]">Your account is protected with TOTP.</span>
        </div>
      </SectionCard>
    );
  }

  async function startEnroll() {
    setError('');
    setBusy(true);
    try {
      const res = await api.mfaEnroll();
      setSecret(res.secret);
      setUri(res.otpauth_uri);
      setPhase('setup');
    } catch (err) {
      setError(err.message || 'Could not start enrollment.');
    } finally {
      setBusy(false);
    }
  }

  async function confirmEnroll(e) {
    e.preventDefault();
    setError('');
    if (!/^\d{6}$/.test(code)) {
      setError('Enter the 6-digit code from your authenticator app.');
      return;
    }
    setBusy(true);
    try {
      await api.mfaEnrollConfirm(code);
      // mfa_enroll_confirm.php returns only { status, message } — re-read the
      // session so the app picks up mfa_enrolled: true (updates the header dot,
      // clears the gate). If refresh fails, we still show the done state.
      setPhase('done');
      await onEnrolled();
    } catch (err) {
      setError(err.message || 'That code did not verify. Start again and check your phone clock.');
      // Server consumed the pending token on a failed confirm — user restarts.
      setPhase('idle');
      setSecret('');
      setUri('');
      setCode('');
    } finally {
      setBusy(false);
    }
  }

  return (
    <SectionCard
      title="Two-factor authentication"
      description="Add a second step at sign-in using an authenticator app (Google Authenticator, Authy, 1Password, etc.)."
    >
      {phase === 'done' ? (
        <div>
          <div className="flex items-center gap-3">
            <Badge fg="var(--color-teal-ink)" bg="var(--color-teal-soft)">Enabled</Badge>
            <span className="text-sm text-[var(--color-ink-2)]">Two-factor authentication is now active.</span>
          </div>
          {mfaRequired && (
            <div className="mt-4">
              <Button onClick={onContinue}>Continue</Button>
            </div>
          )}
        </div>
      ) : phase === 'setup' ? (
        <form onSubmit={confirmEnroll}>
          <p className="text-sm text-[var(--color-ink-2)] mb-3">
            Add this account to your authenticator app using the key or setup link below, then enter the 6-digit code it shows.
          </p>

          <CopyField label="Setup key (manual entry)" value={secret} mono />
          <div className="h-3" />
          <CopyField label="Setup link (otpauth URI)" value={uri} mono />

          <label className="block text-sm font-medium text-[var(--color-ink-2)] mt-4 mb-1.5">
            6-digit code
          </label>
          <input
            type="text"
            inputMode="numeric"
            pattern="[0-9]{6}"
            maxLength={6}
            autoComplete="one-time-code"
            value={code}
            onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
            placeholder="000000"
            className="field tnum tracking-widest max-w-[10rem]"
          />

          {error && (
            <p className="mt-3 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2"
               style={{ color: 'var(--color-alert)' }}>
              {error}
            </p>
          )}

          <div className="flex gap-2 mt-4">
            <Button type="submit" disabled={busy || code.length !== 6}>
              {busy ? 'Verifying…' : 'Confirm & enable'}
            </Button>
            <Button
              type="button"
              variant="ghost"
              onClick={() => { setPhase('idle'); setSecret(''); setUri(''); setCode(''); setError(''); }}
            >
              Cancel
            </Button>
          </div>
        </form>
      ) : (
        <div>
          <div className="flex items-center gap-3 mb-4">
            <Badge fg="var(--color-amber)" bg="var(--color-amber-soft)">Not set up</Badge>
            <span className="text-sm text-[var(--color-ink-2)]">Your account is protected by password only.</span>
          </div>
          {error && (
            <p className="mb-4 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2"
               style={{ color: 'var(--color-alert)' }}>
              {error}
            </p>
          )}
          <Button onClick={startEnroll} disabled={busy}>
            {busy ? 'Starting…' : 'Set up two-factor'}
          </Button>
        </div>
      )}
    </SectionCard>
  );
}

function PasswordSection() {
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [busy, setBusy] = useState(false);

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setSuccess('');

    if (!current || !next) return setError('Enter your current and new password.');
    if (next.length < 8) return setError('New password must be at least 8 characters.');
    if (next !== confirm) return setError('New password and confirmation do not match.');
    if (next === current) return setError('New password must be different from the current one.');

    setBusy(true);
    try {
      await api.updatePassword(current, next);
      setSuccess('Password updated.');
      setCurrent(''); setNext(''); setConfirm('');
    } catch (err) {
      setError(err.message || 'Could not update your password.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <SectionCard title="Password" description="Re-enter your current password to set a new one.">
      <form onSubmit={handleSubmit} className="max-w-sm">
        <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Current password</label>
        <input
          type="password" autoComplete="current-password" value={current}
          onChange={(e) => setCurrent(e.target.value)}
          className="field mb-4"
        />

        <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">New password</label>
        <input
          type="password" autoComplete="new-password" value={next}
          onChange={(e) => setNext(e.target.value)}
          placeholder="At least 8 characters"
          className="field mb-4"
        />

        <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Confirm new password</label>
        <input
          type="password" autoComplete="new-password" value={confirm}
          onChange={(e) => setConfirm(e.target.value)}
          className="field mb-4"
        />

        {error && (
          <p className="mb-4 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2"
             style={{ color: 'var(--color-alert)' }}>
            {error}
          </p>
        )}
        {success && (
          <p className="mb-4 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-teal-soft)] px-3 py-2"
             style={{ color: 'var(--color-teal-ink)' }}>
            {success}
          </p>
        )}

        <Button type="submit" disabled={busy}>
          {busy ? 'Updating…' : 'Update password'}
        </Button>
      </form>
    </SectionCard>
  );
}

function CopyField({ label, value, mono }) {
  const [copied, setCopied] = useState(false);
  function copy() {
    navigator.clipboard?.writeText(value).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    });
  }
  return (
    <div className="rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] bg-[var(--color-surface-2)] px-3 py-2">
      <div className="flex items-center justify-between gap-2">
        <div className="text-[10px] uppercase tracking-wide text-[var(--color-ink-3)]">{label}</div>
        <button type="button" onClick={copy}
                className="shrink-0 text-xs font-medium text-[var(--color-teal-ink)] hover:underline">
          {copied ? 'Copied' : 'Copy'}
        </button>
      </div>
      <div className={`mt-1 text-xs break-all text-[var(--color-ink)] ${mono ? 'tnum' : ''}`}>{value}</div>
    </div>
  );
}
