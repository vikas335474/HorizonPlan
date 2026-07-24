import { useEffect, useState } from 'react';
import { Navigate } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import AppHeader from '../components/AppHeader';
import Modal from '../components/Modal';
import { Card, Badge, Button, EmptyState, Spinner } from '../components/ui';

// Super Admin console: onboard advisory firms (tenants), add their advisors,
// set the compliance mode, and manage white-label branding. super_admin only —
// enforced server-side on every endpoint; the client guard here is just UX.
export default function AdminConsole() {
  const { user } = useAuth();
  const [tenants, setTenants] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [createOpen, setCreateOpen] = useState(false);

  function load() {
    setLoading(true);
    api
      .listTenants()
      .then((res) => setTenants(res.tenants))
      .catch((err) => setError(err.message || 'Could not load firms.'))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    if (user?.role === 'super_admin') load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.role]);

  // Client-side guard; the API is the real gate.
  if (user && user.role !== 'super_admin') return <Navigate to="/" replace />;

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-5xl px-5 py-8">
        <div className="mb-5 flex items-end justify-between gap-4">
          <div>
            <h1 className="text-xl font-semibold tracking-tight text-[var(--color-ink)]">Firms</h1>
            <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
              Onboard advisory firms, manage advisors, compliance mode, and branding.
            </p>
          </div>
          <Button onClick={() => setCreateOpen(true)}>+ New firm</Button>
        </div>

        {loading && <Spinner label="Loading firms…" />}
        {error && (
          <Card className="p-4 border-[var(--color-alert)]">
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
          </Card>
        )}

        {tenants && tenants.length === 0 && (
          <Card>
            <EmptyState
              title="No firms yet"
              action={<Button onClick={() => setCreateOpen(true)}>Onboard the first firm</Button>}
            >
              Create an advisory firm and its first advisor to get started.
            </EmptyState>
          </Card>
        )}

        {tenants && tenants.length > 0 && (
          <div className="space-y-3">
            {tenants.map((t) => (
              <TenantRow key={t.id} tenant={t} onChanged={load} />
            ))}
          </div>
        )}
      </main>

      <CreateFirmModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={() => { setCreateOpen(false); load(); }}
      />
    </div>
  );
}

function TenantRow({ tenant, onChanged }) {
  const [panel, setPanel] = useState(null); // 'branding' | 'advisor' | null
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  async function setMode(mode) {
    if (mode === tenant.advisory_mode || busy) return;
    setBusy(true); setErr('');
    try {
      await api.updateTenant(tenant.id, { advisory_mode: mode });
      onChanged();
    } catch (e) {
      setErr(e.message || 'Could not change mode.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <div className="flex items-center gap-2.5">
            <h2 className="text-base font-semibold text-[var(--color-ink)] truncate">{tenant.company_name}</h2>
            <Badge
              fg={tenant.advisory_mode === 'advisory' ? 'var(--color-teal-ink)' : 'var(--color-ink-2)'}
              bg={tenant.advisory_mode === 'advisory' ? 'var(--color-teal-soft)' : 'var(--color-surface-2)'}
            >
              {tenant.advisory_mode}
            </Badge>
          </div>
          <p className="mt-0.5 text-xs text-[var(--color-ink-3)]">
            {tenant.advisor_count} advisor{tenant.advisor_count === 1 ? '' : 's'} · {tenant.client_count} client{tenant.client_count === 1 ? '' : 's'}
          </p>
        </div>

        <div className="flex items-center gap-2">
          {/* Compliance mode — super-admin-only control (docs/02 3.6) */}
          <div className="inline-flex rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] overflow-hidden text-xs">
            {['distribution', 'advisory'].map((m) => (
              <button
                key={m}
                type="button"
                disabled={busy}
                onClick={() => setMode(m)}
                className="px-2.5 py-1.5 font-medium transition-colors"
                style={
                  tenant.advisory_mode === m
                    ? { backgroundColor: 'var(--color-ink)', color: 'white' }
                    : { color: 'var(--color-ink-2)' }
                }
              >
                {m}
              </button>
            ))}
          </div>
          <Button variant="ghost" size="sm" onClick={() => setPanel(panel === 'branding' ? null : 'branding')}>
            Branding
          </Button>
          <Button variant="ghost" size="sm" onClick={() => setPanel(panel === 'advisor' ? null : 'advisor')}>
            Add advisor
          </Button>
        </div>
      </div>

      {err && <p className="mt-2 text-xs" style={{ color: 'var(--color-alert)' }}>{err}</p>}

      {panel === 'branding' && (
        <BrandingForm tenant={tenant} onSaved={() => { setPanel(null); onChanged(); }} />
      )}
      {panel === 'advisor' && (
        <AddAdvisorForm tenant={tenant} onAdded={() => { setPanel(null); onChanged(); }} />
      )}
    </Card>
  );
}

function BrandingForm({ tenant, onSaved }) {
  const wl = tenant.white_label || {};
  const [companyName, setCompanyName] = useState(wl.company_name || '');
  const [logoUrl, setLogoUrl] = useState(wl.logo_url || '');
  const [primaryColor, setPrimaryColor] = useState(wl.primary_color || '#0f766e');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  async function save() {
    setBusy(true); setErr('');
    try {
      const white_label = {
        ...(companyName.trim() ? { company_name: companyName.trim() } : {}),
        ...(logoUrl.trim() ? { logo_url: logoUrl.trim() } : {}),
        ...(primaryColor ? { primary_color: primaryColor } : {}),
      };
      await api.updateTenant(tenant.id, { white_label: Object.keys(white_label).length ? white_label : null });
      onSaved();
    } catch (e) {
      setErr(e.message || 'Could not save branding.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="mt-4 pt-4 border-t border-[var(--color-line)] grid grid-cols-1 sm:grid-cols-3 gap-3">
      <div className="sm:col-span-1">
        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Display name</label>
        <input className="field" value={companyName} onChange={(e) => setCompanyName(e.target.value)} placeholder={tenant.company_name} />
      </div>
      <div className="sm:col-span-1">
        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Logo URL</label>
        <input className="field" value={logoUrl} onChange={(e) => setLogoUrl(e.target.value)} placeholder="https://…/logo.svg" />
      </div>
      <div className="sm:col-span-1">
        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Primary colour</label>
        <div className="flex items-center gap-2">
          <input type="color" value={primaryColor} onChange={(e) => setPrimaryColor(e.target.value)} className="h-9 w-10 rounded border border-[var(--color-line-2)]" />
          <input className="field" value={primaryColor} onChange={(e) => setPrimaryColor(e.target.value)} placeholder="#0f766e" />
        </div>
      </div>
      {err && <p className="sm:col-span-3 text-xs" style={{ color: 'var(--color-alert)' }}>{err}</p>}
      <div className="sm:col-span-3 flex gap-2">
        <Button size="sm" onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Save branding'}</Button>
      </div>
    </div>
  );
}

function AddAdvisorForm({ tenant, onAdded }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [done, setDone] = useState(null);

  async function submit(e) {
    e.preventDefault();
    setBusy(true); setErr('');
    try {
      await api.createAdvisor(tenant.id, email.trim(), password);
      setDone({ email: email.trim(), password });
    } catch (e2) {
      setErr(e2.message || 'Could not add advisor.');
    } finally {
      setBusy(false);
    }
  }

  if (done) {
    return (
      <div className="mt-4 pt-4 border-t border-[var(--color-line)]">
        <p className="text-sm text-[var(--color-ink)]">
          Advisor <strong>{done.email}</strong> created. Share these credentials securely — they can change the password after signing in.
        </p>
        <p className="mt-1 tnum text-xs text-[var(--color-ink-2)]">Temporary password: <strong>{done.password}</strong></p>
        <div className="mt-2"><Button size="sm" variant="ghost" onClick={onAdded}>Done</Button></div>
      </div>
    );
  }

  return (
    <form onSubmit={submit} className="mt-4 pt-4 border-t border-[var(--color-line)] grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div>
        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Advisor email</label>
        <input type="email" required className="field" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="advisor@firm.in" />
      </div>
      <div>
        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Temporary password</label>
        <input type="text" required minLength={8} className="field" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="min 8 characters" />
      </div>
      {err && <p className="sm:col-span-2 text-xs" style={{ color: 'var(--color-alert)' }}>{err}</p>}
      <div className="sm:col-span-2">
        <Button size="sm" type="submit" disabled={busy}>{busy ? 'Adding…' : 'Add advisor'}</Button>
      </div>
    </form>
  );
}

const GENERATED = () =>
  Math.random().toString(36).slice(2, 6) + '-' + Math.random().toString(36).slice(2, 6);

function CreateFirmModal({ open, onClose, onCreated }) {
  const [companyName, setCompanyName] = useState('');
  const [advisoryMode, setAdvisoryMode] = useState('distribution');
  const [addAdvisor, setAddAdvisor] = useState(true);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState(GENERATED());
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [done, setDone] = useState(null);

  function reset() {
    setCompanyName(''); setAdvisoryMode('distribution'); setAddAdvisor(true);
    setEmail(''); setPassword(GENERATED()); setErr(''); setDone(null);
  }
  function closeAndReset() { onClose(); setTimeout(reset, 200); }

  async function submit(e) {
    e.preventDefault();
    setBusy(true); setErr('');
    try {
      const firstAdvisor = addAdvisor
        ? { email: email.trim(), temporary_password: password }
        : undefined;
      const res = await api.createTenant(companyName.trim(), advisoryMode, firstAdvisor);
      if (addAdvisor) {
        setDone({ email: email.trim(), password });
      } else {
        onCreated();
        reset();
      }
      return res;
    } catch (e2) {
      setErr(e2.message || 'Could not create the firm.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={closeAndReset}
      title="New firm"
      description="Onboard an advisory firm. You can add its first advisor now or later."
    >
      {done ? (
        <div>
          <p className="text-sm text-[var(--color-ink)]">
            Firm created and advisor <strong>{done.email}</strong> can sign in. Share these credentials securely:
          </p>
          <p className="mt-1 tnum text-xs text-[var(--color-ink-2)]">Temporary password: <strong>{done.password}</strong></p>
          <div className="mt-3"><Button onClick={() => { onCreated(); reset(); }}>Done</Button></div>
        </div>
      ) : (
        <form onSubmit={submit}>
          <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Firm name</label>
          <input className="field mb-4" required autoFocus value={companyName} onChange={(e) => setCompanyName(e.target.value)} placeholder="e.g. Nirvana Wealth" />

          <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Compliance mode</label>
          <div className="grid grid-cols-2 gap-2 mb-1">
            {['distribution', 'advisory'].map((m) => (
              <button
                key={m}
                type="button"
                onClick={() => setAdvisoryMode(m)}
                className="rounded-[var(--radius-ctrl)] border px-3 py-2 text-sm font-medium transition-colors"
                style={
                  advisoryMode === m
                    ? { borderColor: 'var(--color-teal)', backgroundColor: 'var(--color-teal-soft)', color: 'var(--color-teal-ink)' }
                    : { borderColor: 'var(--color-line-2)', color: 'var(--color-ink-2)' }
                }
              >
                {m}
              </button>
            ))}
          </div>
          <p className="mb-4 text-xs text-[var(--color-ink-3)]">
            Advisory mode carries advice language and is for SEBI-RIA firms only — set it only after off-platform review.
          </p>

          <label className="flex items-center gap-2 mb-3 text-sm text-[var(--color-ink-2)]">
            <input type="checkbox" checked={addAdvisor} onChange={(e) => setAddAdvisor(e.target.checked)} />
            Add the first advisor now
          </label>

          {addAdvisor && (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
              <div>
                <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Advisor email</label>
                <input type="email" required className="field" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="advisor@firm.in" />
              </div>
              <div>
                <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Temporary password</label>
                <input type="text" required minLength={8} className="field" value={password} onChange={(e) => setPassword(e.target.value)} />
              </div>
            </div>
          )}

          {err && (
            <p className="mb-4 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2" style={{ color: 'var(--color-alert)' }}>
              {err}
            </p>
          )}

          <div className="flex gap-2">
            <Button type="submit" disabled={busy} className="flex-1">{busy ? 'Creating…' : 'Create firm'}</Button>
            <Button type="button" variant="ghost" onClick={closeAndReset}>Cancel</Button>
          </div>
        </form>
      )}
    </Modal>
  );
}
