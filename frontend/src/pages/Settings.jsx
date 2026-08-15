// Route /settings — per-user and per-firm settings: password change, MFA
// enrollment / Google unlink, and (advisor with firm-admin branding rights) the
// white-label branding editor. Also the mandatory-MFA landing target —
// ProtectedRoute redirects an unenrolled session here. Data: api.mfaEnroll /
// mfaEnrollConfirm / unlinkGoogle / updatePassword / updateTenant. useAuth.

import { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import AppHeader from '../components/AppHeader';
import { Card, Button, Badge } from '../components/ui';
import DigestPreference from '../components/DigestPreference';

// Settings: MFA enrollment + password change. Reachable from the header on any
// authenticated route; also the redirect target of ProtectedRoute's mandatory-
// MFA gate. When arrived at via that gate, location.state.mfaRequired is true
// and we surface a banner explaining why, and "Continue" after enrolling goes
// back to wherever the user was actually headed (location.state.from) rather
// than always landing on the dashboard.
export default function Settings() {
  const { user, tenant, refreshSession } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const mfaRequired = location.state?.mfaRequired === true;
  const continuePath = location.state?.from?.pathname || '/';

  // Branding self-service is a firm_admin-only affordance (docs/02 3.6 /
  // CLAUDE.md rule #2): the server (tenant_update.php) already lets a
  // firm_admin write their own tenant's white_label, but the only editor used
  // to live in the super_admin AdminConsole. This surfaces the same edit for
  // the firm's own admin — advisory_mode stays super_admin-only and is never
  // exposed here. jr_advisor/sr_advisor and clients never see this section.
  const canBrand = user?.role === 'advisor' && user?.firmRole === 'firm_admin';

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-2xl px-5 py-8">
        <h1 className="text-xl font-semibold tracking-tight text-[var(--color-ink)]">Settings</h1>
        <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
          {canBrand
            ? 'Manage two-factor authentication, your password, and your firm’s branding.'
            : 'Manage two-factor authentication and your password.'}
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
            totpEnrolled={!!user?.totpEnrolled}
            googleLinked={!!user?.googleLinked}
            onEnrolled={refreshSession}
            onGoogleUnlinked={refreshSession}
            mfaRequired={mfaRequired}
            onContinue={() => navigate(continuePath, { replace: true })}
          />
          <PasswordSection />
          {/* docs/13 I-9 — renders nothing outside a personal tenant. */}
          <DigestPreference />
          {canBrand && (
            <BrandingSection
              tenantId={user.tenantId}
              companyName={tenant?.companyName}
              whiteLabel={tenant?.whiteLabel}
              onSaved={refreshSession}
            />
          )}
        </div>
      </main>
    </div>
  );
}

// Firm-admin white-label editor. Writes the firm's own tenant via the same
// tenant_update.php white_label path the super_admin console uses, then
// refreshes the session so AppHeader's logo/name and the app-wide brand colour
// (AuthContext applies primary_color to --color-teal) update immediately,
// without a reload. advisory_mode is deliberately absent — that stays a
// super_admin-only compliance control.
function BrandingSection({ tenantId, companyName, whiteLabel, onSaved }) {
  const wl = whiteLabel || {};
  const [name, setName] = useState(wl.company_name || '');
  const [logoUrl, setLogoUrl] = useState(wl.logo_url || '');
  const [color, setColor] = useState(wl.primary_color || '#0f766e');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  // Validate the colour client-side to the same #rrggbb shape the server
  // enforces, so a hand-typed value can't silently drop on save.
  const colorValid = /^#[0-9a-fA-F]{6}$/.test(color);

  async function save() {
    setError(''); setSuccess('');
    if (color && !colorValid) {
      setError('Primary colour must be a 6-digit hex value like #0f766e.');
      return;
    }
    setBusy(true);
    try {
      const payload = {
        ...(name.trim() ? { company_name: name.trim() } : {}),
        ...(logoUrl.trim() ? { logo_url: logoUrl.trim() } : {}),
        ...(colorValid ? { primary_color: color.toLowerCase() } : {}),
      };
      await api.updateTenant(tenantId, { white_label: Object.keys(payload).length ? payload : null });
      await onSaved();
      setSuccess('Branding saved — it’s live across your client views now.');
    } catch (err) {
      setError(err.message || 'Could not save branding.');
    } finally {
      setBusy(false);
    }
  }

  async function reset() {
    setError(''); setSuccess('');
    setBusy(true);
    try {
      await api.updateTenant(tenantId, { white_label: null });
      setName(''); setLogoUrl(''); setColor('#0f766e');
      await onSaved();
      setSuccess('Branding removed — back to the HorizonPlan default.');
    } catch (err) {
      setError(err.message || 'Could not reset branding.');
    } finally {
      setBusy(false);
    }
  }

  const previewColor = colorValid ? color : '#0f766e';
  const previewName = name.trim() || companyName || 'Your firm';

  return (
    <SectionCard
      title="Firm branding"
      description="Your firm’s name, logo and accent colour — shown across every client-facing view, the client report and Meeting Mode."
    >
      {/* Live preview of the client-facing header, same idea as the console's. */}
      <div
        className="mb-5 flex items-center gap-3 rounded-[var(--radius-card)] border border-[var(--color-line-2)] px-4 py-3"
        style={{ background: `color-mix(in srgb, ${previewColor} 8%, var(--color-surface))` }}
      >
        {logoUrl.trim() ? (
          <img src={logoUrl.trim()} alt="" className="h-8 w-8 rounded object-cover" />
        ) : (
          <div className="flex h-8 w-8 items-center justify-center rounded text-xs font-semibold text-white" style={{ background: previewColor }}>
            {previewName.slice(0, 1).toUpperCase()}
          </div>
        )}
        <div>
          <p className="text-sm font-semibold" style={{ color: previewColor }}>{previewName}</p>
          <p className="text-[11px] text-[var(--color-ink-3)]">How your client-facing header will look</p>
        </div>
      </div>

      <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Display name</label>
      <input
        className="field mb-4" value={name} onChange={(e) => setName(e.target.value)}
        placeholder={companyName || 'Your firm’s name'}
      />

      <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Logo URL</label>
      <input
        className="field mb-1.5" value={logoUrl} onChange={(e) => setLogoUrl(e.target.value)}
        placeholder="https://…/logo.svg"
      />
      <p className="mb-4 text-xs text-[var(--color-ink-3)]">
        Paste a link to a hosted image (SVG or PNG works best). File upload isn’t supported yet.
      </p>

      <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Primary colour</label>
      <div className="flex items-center gap-2">
        <input
          type="color" value={colorValid ? color : '#0f766e'}
          onChange={(e) => setColor(e.target.value)}
          className="h-9 w-10 rounded border border-[var(--color-line-2)]"
          aria-label="Primary colour picker"
        />
        <input
          className="field tnum max-w-[10rem]" value={color}
          onChange={(e) => setColor(e.target.value)} placeholder="#0f766e"
        />
      </div>

      {error && (
        <p className="mt-4 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2"
           style={{ color: 'var(--color-alert)' }}>
          {error}
        </p>
      )}
      {success && (
        <p className="mt-4 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-teal-soft)] px-3 py-2"
           style={{ color: 'var(--color-teal-ink)' }}>
          {success}
        </p>
      )}

      <div className="mt-5 flex gap-2">
        <Button onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Save branding'}</Button>
        <Button variant="ghost" onClick={reset} disabled={busy}>Reset to default</Button>
      </div>
    </SectionCard>
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

function MfaSection({ enrolled, totpEnrolled, googleLinked, onEnrolled, onGoogleUnlinked, mfaRequired, onContinue }) {
  // 'idle' → user hasn't started; 'setup' → secret issued, awaiting confirm code.
  const [phase, setPhase] = useState('idle');
  const [secret, setSecret] = useState('');
  const [uri, setUri] = useState('');
  const [code, setCode] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  // This branch also covers "just finished enrolling in this session": once
  // confirmEnroll()'s onEnrolled() call resolves, the `enrolled` prop (driven
  // by AuthContext's user.mfaEnrolled) flips true and this early return takes
  // over — there's no separate transient "done" phase, so the Continue button
  // has to live here too, not in a phase branch below that `enrolled` would
  // otherwise make unreachable a moment after it renders.
  //
  // TOTP and Google are two independent ways to satisfy the same mandatory
  // requirement (see security_gatekeeper.php::userHasMfaEnrolled()) — either
  // one showing here is enough to be "Enabled" overall, so both statuses are
  // always shown together rather than one hiding the other.
  if (enrolled) {
    return (
      <SectionCard
        title="Two-factor authentication"
        description="Google sign-in or an authenticator code — either one satisfies sign-in security."
      >
        <div className="space-y-3">
          <div className="flex items-center gap-3">
            {totpEnrolled ? (
              <Badge fg="var(--color-teal-ink)" bg="var(--color-teal-soft)">Enabled</Badge>
            ) : (
              <Badge fg="var(--color-ink-3)" bg="var(--color-surface-2)">Not set up</Badge>
            )}
            <span className="text-sm text-[var(--color-ink-2)]">Authenticator app (TOTP)</span>
          </div>
          <GoogleLinkStatus linked={googleLinked} onUnlinked={onGoogleUnlinked} />
        </div>
        {mfaRequired && (
          <div className="mt-4">
            <Button onClick={onContinue}>Continue</Button>
          </div>
        )}
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
      // session so the app picks up mfa_enrolled: true, which flips this
      // component to the `enrolled` early-return branch above.
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
      {phase === 'setup' ? (
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
          <p className="mt-4 text-xs text-[var(--color-ink-3)]">
            Prefer not to use an authenticator app? Sign out and use "Sign in with Google"
            on the login page instead — it satisfies this requirement on its own, and links
            automatically the first time you use it.
          </p>
        </div>
      )}
    </SectionCard>
  );
}

// Shows whether a Google account is linked, with an Unlink action. Linking
// itself only ever happens via the login page's Google button (auto-linked
// on first successful Google login by verified email) — there is no "link"
// button here, only "unlink", since this page can't originate a Google OAuth
// flow without duplicating that logic for no real benefit.
function GoogleLinkStatus({ linked, onUnlinked }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  async function unlink() {
    setError('');
    setBusy(true);
    try {
      await api.unlinkGoogle();
      await onUnlinked();
    } catch (err) {
      setError(err.message || 'Could not unlink Google.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <div className="flex items-center gap-3">
        {linked ? (
          <Badge fg="var(--color-teal-ink)" bg="var(--color-teal-soft)">Linked</Badge>
        ) : (
          <Badge fg="var(--color-ink-3)" bg="var(--color-surface-2)">Not linked</Badge>
        )}
        <span className="text-sm text-[var(--color-ink-2)]">Google sign-in</span>
        {linked && (
          <button
            type="button"
            onClick={unlink}
            disabled={busy}
            className="text-xs font-medium text-[var(--color-ink-2)] hover:text-[var(--color-alert)] disabled:opacity-60"
          >
            {busy ? 'Unlinking…' : 'Unlink'}
          </button>
        )}
      </div>
      {error && (
        <p className="mt-2 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2"
           style={{ color: 'var(--color-alert)' }}>
          {error}
        </p>
      )}
    </div>
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
