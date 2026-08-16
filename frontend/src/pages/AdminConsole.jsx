// Route /admin — the Super Admin console. Client-guards to super_admin (redirects
// everyone else) on top of the server-side gate on every endpoint it touches.
// Tabs: Platform (api.getPlatformSettings / updatePlatformSettings /
// resetDemoData), Firms (api.getTenantDetail / createTenant / updateTenant /
// createAdvisor), and the global Strategy Templates library (api.listTemplates /
// getTemplateAudit).

import { useEffect, useState } from 'react';
import { Navigate } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import AppHeader from '../components/AppHeader';
import Modal from '../components/Modal';
import { Card, Badge, Button, EmptyState, Spinner, StatCard } from '../components/ui';
import { AllocationBar, RiskBadge, TemplateCreateModal, ApprovalBadge, ApproveButton } from '../components/TemplateUI';

// Super Admin console: tabs for Firms and Strategy Templates. super_admin only —
// enforced server-side on every endpoint; the client guard here is just UX.
export default function AdminConsole() {
  const { user } = useAuth();
  const [activeTab, setActiveTab] = useState('firms');

  if (user && user.role !== 'super_admin') return <Navigate to="/" replace />;

  return (
    <div className="min-h-screen">
      <AppHeader />

      <div className="border-b border-[var(--color-line)] bg-[var(--color-surface)]">
        <div className="mx-auto max-w-5xl px-5">
          <nav className="flex gap-1 pt-4">
            {[
              { key: 'platform', label: 'Platform' },
              { key: 'firms', label: 'Firms' },
              { key: 'templates', label: 'Strategy Templates' },
            ].map(({ key, label }) => (
              <button
                key={key}
                onClick={() => setActiveTab(key)}
                className="px-4 pb-3 pt-1 text-sm font-medium border-b-2 transition-colors"
                style={
                  activeTab === key
                    ? { borderColor: 'var(--color-ink)', color: 'var(--color-ink)' }
                    : { borderColor: 'transparent', color: 'var(--color-ink-3)' }
                }
              >
                {label}
              </button>
            ))}
          </nav>
        </div>
      </div>

      <main className="mx-auto max-w-5xl px-5 py-8">
        {activeTab === 'platform' && <PlatformTab />}
        {activeTab === 'firms' && <FirmsTab />}
        {activeTab === 'templates' && <AdminTemplatesTab />}
      </main>
    </div>
  );
}

// ─── Platform tab (docs/09 Piece 1) ────────────────────────────────────────
// Platform-wide knobs: MFA enforcement and demo mode. Both are independent —
// demo_mode='on' implies MFA is skipped regardless of mfa_enforcement, but
// mfa_enforcement can be disabled on its own too (see sql/019's comment).
function PlatformTab() {
  const { refreshSession } = useAuth();
  const [settings, setSettings] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [resetResult, setResetResult] = useState(null);
  const [resetError, setResetError] = useState('');
  const [resetBusy, setResetBusy] = useState(false);

  function load() {
    setLoading(true); setError('');
    api.getPlatformSettings()
      .then((res) => setSettings({ mfa_enforcement: res.mfa_enforcement, demo_mode: res.demo_mode, signup_enabled: res.signup_enabled }))
      .catch((e) => setError(e.message || 'Could not load platform settings.'))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, []);

  async function update(changes) {
    setBusy(true); setError('');
    try {
      const res = await api.updatePlatformSettings(changes);
      setSettings({ mfa_enforcement: res.mfa_enforcement, demo_mode: res.demo_mode, signup_enabled: res.signup_enabled });
      // The acting super_admin's own session is affected by these toggles too
      // (e.g. the MFA gate) — refresh so the header/route guard picks it up
      // immediately instead of waiting for the next session.php poll.
      refreshSession();
    } catch (e) {
      setError(e.message || 'Could not update platform settings.');
    } finally {
      setBusy(false);
    }
  }

  async function runReset() {
    setResetBusy(true); setResetError(''); setResetResult(null);
    try {
      const res = await api.resetDemoData();
      setResetResult(res);
    } catch (e) {
      setResetError(e.message || 'Could not reset demo data.');
    } finally {
      setResetBusy(false);
    }
  }

  return (
    <div className="max-w-2xl">
      <div className="mb-5">
        <h2 className="text-xl font-semibold tracking-tight text-[var(--color-ink)]">Platform</h2>
        <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
          Platform-wide settings — MFA enforcement, demo mode, and self-serve signup. Changes apply to every firm immediately.
        </p>
      </div>

      {loading && <Spinner label="Loading platform settings…" />}
      {error && (
        <Card className="p-4 mb-4 border-[var(--color-alert)]">
          <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
        </Card>
      )}

      {settings && (
        <div className="space-y-4">
          <Card className="p-5">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h3 className="text-sm font-semibold text-[var(--color-ink)]">MFA enforcement</h3>
                <p className="mt-1 text-xs text-[var(--color-ink-2)] max-w-md">
                  When enabled, every session is blocked from the rest of the app until two-factor
                  authentication is set up. Disabling this removes that requirement platform-wide —
                  use only for testing, not for a real advisory firm's production accounts.
                </p>
                {settings.mfa_enforcement === 'disabled' && (
                  <p className="mt-2 text-xs font-medium" style={{ color: 'var(--color-amber)' }}>
                    ⚠ MFA enforcement is currently disabled — accounts are not required to enroll.
                  </p>
                )}
              </div>
              <div className="inline-flex rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] overflow-hidden text-sm shrink-0">
                {['enabled', 'disabled'].map((v) => (
                  <button
                    key={v}
                    type="button"
                    disabled={busy}
                    onClick={() => update({ mfa_enforcement: v })}
                    className="px-3 py-1.5 font-medium transition-colors"
                    style={
                      settings.mfa_enforcement === v
                        ? { backgroundColor: 'var(--color-ink)', color: 'white' }
                        : { color: 'var(--color-ink-2)' }
                    }
                  >
                    {v}
                  </button>
                ))}
              </div>
            </div>
          </Card>

          <Card className="p-5">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h3 className="text-sm font-semibold text-[var(--color-ink)]">Demo mode</h3>
                <p className="mt-1 text-xs text-[var(--color-ink-2)] max-w-md">
                  Skips MFA enrollment for every account, suppresses all outbound emails, and enables
                  a one-click reset of demo data. Turn this on only for a demo/sandbox environment.
                </p>
                {settings.demo_mode === 'on' && (
                  <p className="mt-2 text-xs font-medium" style={{ color: 'var(--color-amber)' }}>
                    ⚠ Demo mode is on — the app shows an amber "DEMO ENVIRONMENT" banner to every user.
                  </p>
                )}
              </div>
              <div className="inline-flex rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] overflow-hidden text-sm shrink-0">
                {['off', 'on'].map((v) => (
                  <button
                    key={v}
                    type="button"
                    disabled={busy}
                    onClick={() => update({ demo_mode: v })}
                    className="px-3 py-1.5 font-medium transition-colors"
                    style={
                      settings.demo_mode === v
                        ? { backgroundColor: 'var(--color-ink)', color: 'white' }
                        : { color: 'var(--color-ink-2)' }
                    }
                  >
                    {v}
                  </button>
                ))}
              </div>
            </div>
          </Card>

          <Card className="p-5">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h3 className="text-sm font-semibold text-[var(--color-ink)]">Self-serve trial signup</h3>
                <p className="mt-1 text-xs text-[var(--color-ink-2)] max-w-md">
                  Whether the public "Start your free trial" form (/signup) accepts new firms. A
                  signup always creates a distribution-mode tenant — advisory_mode still requires a
                  Super Admin to grant it after a real review, unaffected by this toggle.
                </p>
                {settings.signup_enabled === 'off' && (
                  <p className="mt-2 text-xs font-medium" style={{ color: 'var(--color-amber)' }}>
                    ⚠ Self-serve signup is currently disabled — the signup form rejects every submission.
                  </p>
                )}
              </div>
              <div className="inline-flex rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] overflow-hidden text-sm shrink-0">
                {['off', 'on'].map((v) => (
                  <button
                    key={v}
                    type="button"
                    disabled={busy}
                    onClick={() => update({ signup_enabled: v })}
                    className="px-3 py-1.5 font-medium transition-colors"
                    style={
                      settings.signup_enabled === v
                        ? { backgroundColor: 'var(--color-ink)', color: 'white' }
                        : { color: 'var(--color-ink-2)' }
                    }
                  >
                    {v}
                  </button>
                ))}
              </div>
            </div>
          </Card>

          {settings.demo_mode === 'on' && (
            <Card className="p-5">
              <h3 className="text-sm font-semibold text-[var(--color-ink)]">Reset demo data</h3>
              <p className="mt-1 text-xs text-[var(--color-ink-2)] max-w-md">
                Deletes every demo-seeded firm/advisor/client and re-seeds fresh data. Does not touch
                your own Super Admin account or these platform settings.
              </p>
              <div className="mt-3">
                <Button variant="outline" onClick={runReset} disabled={resetBusy}>
                  {resetBusy ? 'Resetting…' : 'Reset demo data'}
                </Button>
              </div>
              {resetError && (
                <p className="mt-3 text-xs" style={{ color: 'var(--color-alert)' }}>{resetError}</p>
              )}
              {resetResult && (
                <p className="mt-3 text-xs text-[var(--color-ink-2)]">
                  Demo data reset. {resetResult.message || 'Fresh demo accounts are ready.'}
                </p>
              )}
            </Card>
          )}
        </div>
      )}
    </div>
  );
}

// ─── Firms tab (unchanged logic, extracted from former AdminConsole body) ─────

function FirmsTab() {
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

  const stats = (tenants || []).reduce(
    (acc, t) => ({
      advisors: acc.advisors + t.advisor_count,
      clients: acc.clients + t.client_count,
      advisory: acc.advisory + (t.advisory_mode === 'advisory' ? 1 : 0),
    }),
    { advisors: 0, clients: 0, advisory: 0 }
  );

  return (
    <>
      <div className="mb-5 flex items-end justify-between gap-4">
        <div>
          <h2 className="text-xl font-semibold tracking-tight text-[var(--color-ink)]">Firms</h2>
          <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
            Onboard advisory firms, manage advisors, compliance mode, and branding.
          </p>
        </div>
        <Button onClick={() => setCreateOpen(true)}>+ New firm</Button>
      </div>

      {tenants && tenants.length > 0 && (
        <div className="mb-6 grid grid-cols-2 sm:grid-cols-4 gap-3">
          <StatCard label="Firms" value={tenants.length} />
          <StatCard label="Advisors" value={stats.advisors} accent="teal" />
          <StatCard label="Clients" value={stats.clients} accent="teal" />
          <StatCard
            label="Advisory-mode firms"
            value={stats.advisory}
            sublabel={`${tenants.length - stats.advisory} distribution-mode`}
            accent="amber"
          />
        </div>
      )}

      {loading && <Spinner label="Loading firms…" />}
      {error && (
        <Card className="p-4 border-[var(--color-alert)]">
          <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
        </Card>
      )}
      {tenants && tenants.length === 0 && (
        <Card>
          <EmptyState title="No firms yet" action={<Button onClick={() => setCreateOpen(true)}>Onboard the first firm</Button>}>
            Create an advisory firm and its first advisor to get started.
          </EmptyState>
        </Card>
      )}
      {tenants && tenants.length > 0 && (
        <div className="space-y-3">
          {tenants.map((t) => <TenantRow key={t.id} tenant={t} onChanged={load} />)}
        </div>
      )}

      <CreateFirmModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={() => { setCreateOpen(false); load(); }}
      />
    </>
  );
}

// ─── Strategy Templates tab (Super Admin view) ────────────────────────────────

function AdminTemplatesTab() {
  const [templates, setTemplates] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [createOpen, setCreateOpen] = useState(false);
  const [query, setQuery] = useState('');

  function load() {
    setLoading(true);
    api.listTemplates()
      .then((res) => setTemplates(res.global_templates ?? []))
      .catch((err) => setError(err.message || 'Could not load templates.'))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, []);

  const filtered = templates
    ? templates.filter((t) =>
        query === '' ||
        t.template_name.toLowerCase().includes(query.toLowerCase()) ||
        (t.description ?? '').toLowerCase().includes(query.toLowerCase())
      )
    : [];

  return (
    <>
      <div className="mb-5 flex items-end justify-between gap-4">
        <div>
          <h2 className="text-xl font-semibold tracking-tight text-[var(--color-ink)]">Strategy Templates</h2>
          <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
            Global SaaS templates offered to all advisory firms.
          </p>
        </div>
        <Button onClick={() => setCreateOpen(true)}>+ New template</Button>
      </div>

      {templates && templates.length > 0 && (
        <div className="mb-4">
          <input
            type="search"
            className="field max-w-xs"
            placeholder="Search templates…"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </div>
      )}

      {loading && <Spinner label="Loading templates…" />}
      {error && (
        <Card className="p-4 border-[var(--color-alert)]">
          <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
        </Card>
      )}

      {templates && templates.length === 0 && (
        <Card>
          <EmptyState title="No global templates yet" action={<Button onClick={() => setCreateOpen(true)}>Create the first template</Button>}>
            Global templates are offered to all advisory firms. Create one to get started.
          </EmptyState>
        </Card>
      )}

      {filtered.length > 0 && (
        <div className="space-y-3">
          {filtered.map((t) => <AdminTemplateRow key={t.id} template={t} onChanged={load} />)}
        </div>
      )}
      {templates && templates.length > 0 && filtered.length === 0 && (
        <p className="text-sm text-[var(--color-ink-3)] py-8 text-center">No templates match "{query}".</p>
      )}

      <TemplateCreateModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={() => { setCreateOpen(false); load(); }}
        isGlobal
      />
    </>
  );
}

function AdminTemplateRow({ template, onChanged }) {
  const [auditOpen, setAuditOpen] = useState(false);
  const [audit, setAudit] = useState(null);
  const [auditLoading, setAuditLoading] = useState(false);

  async function loadAudit() {
    if (audit) { setAuditOpen(true); return; }
    setAuditLoading(true);
    try {
      const res = await api.getTemplateAudit(template.id, null);
      setAudit(res.audit_log ?? []);
      setAuditOpen(true);
    } catch {
      // swallow — not blocking
    } finally {
      setAuditLoading(false);
    }
  }

  return (
    <Card className="p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2 mb-1">
            <h3 className="text-sm font-semibold text-[var(--color-ink)]">{template.template_name}</h3>
            {template.risk_profile_enum && <RiskBadge profile={template.risk_profile_enum} />}
            <Badge
              fg="var(--color-teal-ink)"
              bg="var(--color-teal-soft)"
            >
              {template.is_published ? 'published' : 'draft'}
            </Badge>
            <ApprovalBadge status={template.approval_status} />
          </div>
          {template.description && (
            <p className="text-xs text-[var(--color-ink-2)] mb-2 max-w-lg">{template.description}</p>
          )}
          <AllocationBar allocation={template.allocation_json} />
          <div className="mt-1.5 flex gap-4 text-xs text-[var(--color-ink-3)]">
            {template.return_assumption_pct != null && (
              <span>Return assumption: <strong className="text-[var(--color-ink)]">{template.return_assumption_pct}%</strong></span>
            )}
            <span>Usage: <strong className="text-[var(--color-ink)]">{template.usage_count}</strong></span>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <ApproveButton
            templateId={template.id}
            approvalStatus={template.approval_status}
            onApproved={onChanged}
          />
          <Button
            variant="ghost"
            size="sm"
            onClick={loadAudit}
            disabled={auditLoading}
          >
            {auditLoading ? 'Loading…' : 'Audit trail'}
          </Button>
        </div>
      </div>

      {auditOpen && audit && (
        <div className="mt-4 pt-4 border-t border-[var(--color-line)]">
          <div className="flex items-center justify-between mb-3">
            <h4 className="text-xs font-semibold uppercase tracking-wide text-[var(--color-ink-3)]">Audit trail</h4>
            <button className="text-xs text-[var(--color-ink-3)] hover:text-[var(--color-ink)]" onClick={() => setAuditOpen(false)}>Close</button>
          </div>
          {audit.length === 0 && <p className="text-xs text-[var(--color-ink-3)]">No history yet.</p>}
          <ol className="space-y-2">
            {audit.map((entry) => (
              <li key={entry.id} className="text-xs text-[var(--color-ink-2)] flex gap-2">
                <span className="shrink-0 text-[var(--color-ink-3)]">{entry.created_at.slice(0, 16)}</span>
                <span>
                  <strong className="text-[var(--color-ink)]">{entry.action}</strong>
                  {entry.entity_details?.base_template_name && ` from "${entry.entity_details.base_template_name}"`}
                </span>
              </li>
            ))}
          </ol>
        </div>
      )}
    </Card>
  );
}

function TenantRow({ tenant, onChanged }) {
  const [detailOpen, setDetailOpen] = useState(false);

  return (
    <>
      <Card className="p-4 lift cursor-pointer" onClick={() => setDetailOpen(true)}>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="min-w-0 flex items-center gap-3">
            {tenant.white_label?.logo_url ? (
              <img
                src={tenant.white_label.logo_url}
                alt=""
                className="h-9 w-9 rounded-[var(--radius-ctrl)] object-cover border border-[var(--color-line-2)]"
              />
            ) : (
              <div
                className="h-9 w-9 rounded-[var(--radius-ctrl)] flex items-center justify-center text-sm font-semibold text-white shrink-0"
                style={{ background: tenant.white_label?.primary_color || 'var(--color-ink-3)' }}
              >
                {tenant.company_name.slice(0, 1).toUpperCase()}
              </div>
            )}
            <div className="min-w-0">
              <div className="flex items-center gap-2.5">
                <h2 className="text-base font-semibold text-[var(--color-ink)] truncate">{tenant.company_name}</h2>
                <Badge
                  fg={tenant.advisory_mode === 'advisory' ? 'var(--color-teal-ink)' : 'var(--color-ink-2)'}
                  bg={tenant.advisory_mode === 'advisory' ? 'var(--color-teal-soft)' : 'var(--color-surface-2)'}
                >
                  {tenant.advisory_mode}
                </Badge>
                {tenant.plan && (
                  <Badge fg="var(--color-ink-2)" bg="var(--color-surface-2)">
                    {tenant.plan.name}
                  </Badge>
                )}
              </div>
              <p className="mt-0.5 text-xs text-[var(--color-ink-3)]">
                {tenant.advisor_count}{tenant.plan?.max_advisors != null ? `/${tenant.plan.max_advisors}` : ''} advisor{tenant.advisor_count === 1 ? '' : 's'} · {tenant.client_count} client{tenant.client_count === 1 ? '' : 's'}
              </p>
            </div>
          </div>

          <Button variant="outline" size="sm" onClick={(e) => { e.stopPropagation(); setDetailOpen(true); }}>
            Manage firm
          </Button>
        </div>
      </Card>

      <FirmDetailModal
        tenantId={detailOpen ? tenant.id : null}
        onClose={() => setDetailOpen(false)}
        onChanged={onChanged}
      />
    </>
  );
}

// ─── Firm detail: the single place to manage a firm's compliance mode,
// branding, and advisors — replaces the old three-panel-per-row layout, which
// cluttered the list and scattered a firm's settings across expand/collapse
// panels instead of one coherent view. ──────────────────────────────────────
function FirmDetailModal({ tenantId, onClose, onChanged }) {
  const [detail, setDetail] = useState(null);
  const [plans, setPlans] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  function load() {
    if (!tenantId) return;
    setLoading(true); setError('');
    api.getTenantDetail(tenantId)
      .then((res) => setDetail(res))
      .catch((e) => setError(e.message || 'Could not load this firm.'))
      .finally(() => setLoading(false));
    if (!plans) {
      // Fixed catalog (docs/09 Session 8) — fetched once per modal session,
      // not on every refresh, since it never changes underneath us.
      api.listSubscriptionPlans().then((res) => setPlans(res.plans)).catch(() => {});
    }
  }

  useEffect(() => {
    if (tenantId) load();
    else setDetail(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tenantId]);

  function refresh() {
    load();
    onChanged();
  }

  async function setMode(mode) {
    if (!detail || mode === detail.tenant.advisory_mode || busy) return;
    setBusy(true);
    try {
      await api.updateTenant(detail.tenant.id, { advisory_mode: mode });
      refresh();
    } catch (e) {
      setError(e.message || 'Could not change mode.');
    } finally {
      setBusy(false);
    }
  }

  // Billing (docs/09 Session 8) — plan_code is super_admin-only server-side
  // (tenant_update.php), same restriction class as advisory_mode.
  async function setPlan(planCode) {
    if (!detail || planCode === detail.tenant.plan?.code || busy) return;
    setBusy(true);
    try {
      await api.updateTenant(detail.tenant.id, { plan_code: planCode });
      refresh();
    } catch (e) {
      setError(e.message || 'Could not change the plan.');
    } finally {
      setBusy(false);
    }
  }

  // Jr -> Sr Advisor Plan-Approval Workflow (decision #3) — opt-in per
  // tenant, toggled from the same place branding/compliance mode already are.
  async function togglePlanReview() {
    if (!detail || busy) return;
    setBusy(true);
    try {
      await api.updateTenant(detail.tenant.id, {
        requires_plan_review: detail.tenant.requires_plan_review ? 'off' : 'on',
      });
      refresh();
    } catch (e) {
      setError(e.message || 'Could not change this setting.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal open={!!tenantId} onClose={onClose} size="xl" title={detail ? detail.tenant.company_name : 'Firm'}>
      {loading && <Spinner label="Loading firm…" />}
      {error && (
        <p className="mb-4 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2" style={{ color: 'var(--color-alert)' }}>
          {error}
        </p>
      )}

      {detail && (
        <div className="space-y-6">
          <div className="grid grid-cols-3 gap-3">
            <StatCard
              label="Advisors"
              value={
                detail.tenant.plan?.max_advisors != null
                  ? `${detail.tenant.advisor_count}/${detail.tenant.plan.max_advisors}`
                  : detail.tenant.advisor_count
              }
            />
            <StatCard label="Clients" value={detail.tenant.client_count} />
            <StatCard label="Goals" value={detail.tenant.goal_count} accent="teal" />
          </div>

          {detail.tenant.plan && (
            <div>
              <h3 className="text-sm font-semibold text-[var(--color-ink)] mb-2">Plan</h3>
              <p className="mb-2 text-xs text-[var(--color-ink-3)]">
                Gates advisor seats only — clients and goals are unlimited on every tier (docs/09 Session 8).
                Price is informational; no payment is processed by this app.
              </p>
              <div className="flex flex-wrap gap-2">
                {(plans || []).map((p) => (
                  <button
                    key={p.code}
                    type="button"
                    disabled={busy}
                    onClick={() => setPlan(p.code)}
                    className="px-3 py-1.5 rounded-[var(--radius-ctrl)] border text-sm font-medium transition-colors text-left"
                    style={
                      detail.tenant.plan.code === p.code
                        ? { backgroundColor: 'var(--color-ink)', color: 'white', borderColor: 'var(--color-ink)' }
                        : { color: 'var(--color-ink-2)', borderColor: 'var(--color-line-2)' }
                    }
                  >
                    <div>{p.name}</div>
                    <div className="text-xs opacity-80">
                      {p.max_advisors != null ? `${p.max_advisors} advisor seats` : 'Unlimited seats'}
                      {p.price_inr_monthly != null ? ` · ₹${p.price_inr_monthly.toLocaleString('en-IN')}/mo` : ''}
                    </div>
                  </button>
                ))}
              </div>
            </div>
          )}

          <div className="pt-5 border-t border-[var(--color-line)]">
            <h3 className="text-sm font-semibold text-[var(--color-ink)] mb-2">Compliance mode</h3>
            <p className="mb-2 text-xs text-[var(--color-ink-3)]">
              Advisory mode carries advice language and is for SEBI-RIA firms only — set it only after off-platform review (docs/02 §3.6).
            </p>
            <div className="inline-flex rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] overflow-hidden text-sm">
              {['distribution', 'advisory'].map((m) => (
                <button
                  key={m}
                  type="button"
                  disabled={busy}
                  onClick={() => setMode(m)}
                  className="px-3 py-1.5 font-medium transition-colors"
                  style={
                    detail.tenant.advisory_mode === m
                      ? { backgroundColor: 'var(--color-ink)', color: 'white' }
                      : { color: 'var(--color-ink-2)' }
                  }
                >
                  {m}
                </button>
              ))}
            </div>
          </div>

          <div className="pt-5 border-t border-[var(--color-line)]">
            <div className="flex items-center justify-between gap-3">
              <div>
                <h3 className="text-sm font-semibold text-[var(--color-ink)]">Plan review</h3>
                <p className="mt-0.5 text-xs text-[var(--color-ink-3)] max-w-md">
                  When on, a goal's withdrawal rate / post-retirement return / applied template needs a
                  senior advisor or firm admin's sign-off. No hard gate yet — this only adds a status badge
                  and a review queue.
                </p>
              </div>
              <button
                type="button"
                role="switch"
                aria-checked={detail.tenant.requires_plan_review}
                disabled={busy}
                onClick={togglePlanReview}
                className="shrink-0 relative h-6 w-11 rounded-full transition-colors"
                style={{ backgroundColor: detail.tenant.requires_plan_review ? 'var(--color-teal)' : 'var(--color-line-2)' }}
              >
                <span
                  className="absolute top-0.5 h-5 w-5 rounded-full bg-white transition-transform"
                  style={{ transform: detail.tenant.requires_plan_review ? 'translateX(22px)' : 'translateX(2px)' }}
                />
              </button>
            </div>
          </div>

          <div className="pt-5 border-t border-[var(--color-line)]">
            <h3 className="text-sm font-semibold text-[var(--color-ink)] mb-3">Branding</h3>
            <BrandingPreview tenant={detail.tenant} />
            <BrandingForm tenant={detail.tenant} onSaved={refresh} />
          </div>

          <div className="pt-5 border-t border-[var(--color-line)]">
            <h3 className="text-sm font-semibold text-[var(--color-ink)] mb-3">Advisors</h3>
            {detail.advisors.length === 0 && (
              <p className="text-sm text-[var(--color-ink-3)] mb-3">No advisors yet — add the first one below.</p>
            )}
            {detail.advisors.length > 0 && (
              <ul className="mb-4 divide-y divide-[var(--color-line)] rounded-[var(--radius-ctrl)] border border-[var(--color-line)]">
                {detail.advisors.map((a) => (
                  <li key={a.id} className="flex items-center justify-between px-3 py-2 text-sm">
                    <span className="flex items-center gap-2">
                      <span className="text-[var(--color-ink)]">{a.email}</span>
                      <FirmRoleBadge firmRole={a.firm_role} />
                    </span>
                    <span className="text-xs text-[var(--color-ink-3)]">since {a.created_at.slice(0, 10)}</span>
                  </li>
                ))}
              </ul>
            )}
            <AddAdvisorForm tenant={detail.tenant} onAdded={refresh} embedded />
          </div>
        </div>
      )}
    </Modal>
  );
}

// A rough client-facing header preview — helps a super_admin see the branding
// they're setting without leaving the console to check a real client view.
function BrandingPreview({ tenant }) {
  const wl = tenant.white_label || {};
  const color = wl.primary_color || '#0f766e';
  return (
    <div
      className="mb-4 flex items-center gap-3 rounded-[var(--radius-card)] px-4 py-3 border border-[var(--color-line-2)]"
      style={{ background: `color-mix(in srgb, ${color} 8%, var(--color-surface))` }}
    >
      {wl.logo_url ? (
        <img src={wl.logo_url} alt="" className="h-8 w-8 rounded object-cover" />
      ) : (
        <div className="h-8 w-8 rounded flex items-center justify-center text-xs font-semibold text-white" style={{ background: color }}>
          {(wl.company_name || tenant.company_name).slice(0, 1).toUpperCase()}
        </div>
      )}
      <div>
        <p className="text-sm font-semibold" style={{ color }}>{wl.company_name || tenant.company_name}</p>
        <p className="text-[11px] text-[var(--color-ink-3)]">How this firm's client-facing header will look</p>
      </div>
    </div>
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

const FIRM_ROLE_LABELS = { jr_advisor: 'Jr. Advisor', sr_advisor: 'Sr. Advisor', firm_admin: 'Firm Admin' };

function FirmRoleBadge({ firmRole }) {
  const label = FIRM_ROLE_LABELS[firmRole] || 'Sr. Advisor'; // NULL treated as sr_advisor, same as the server
  return (
    <Badge fg="var(--color-ink-2)" bg="var(--color-surface-2)">{label}</Badge>
  );
}

function AddAdvisorForm({ tenant, onAdded, embedded = false }) {
  const [email, setEmail] = useState('');
  const [firmRole, setFirmRole] = useState('sr_advisor');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [done, setDone] = useState(null);
  const wrap = embedded ? '' : 'mt-4 pt-4 border-t border-[var(--color-line)]';

  async function submit(e) {
    e.preventDefault();
    setBusy(true); setErr('');
    try {
      const res = await api.createAdvisor(tenant.id, email.trim(), firmRole);
      setDone({ email: email.trim(), inviteLink: res.invite_link });
    } catch (e2) {
      setErr(e2.message || 'Could not add advisor.');
    } finally {
      setBusy(false);
    }
  }

  if (done) {
    return (
      <div className={wrap}>
        <p className="text-sm text-[var(--color-ink)]">
          Advisor <strong>{done.email}</strong> created and emailed an invite link to set their password. Here's a copyable fallback in case delivery doesn't land:
        </p>
        <div className="mt-2 flex items-center gap-2">
          <input readOnly className="field text-xs" value={done.inviteLink || ''} onFocus={(e) => e.target.select()} />
          <Button size="sm" variant="outline" onClick={() => navigator.clipboard?.writeText(done.inviteLink || '')}>Copy</Button>
        </div>
        <p className="mt-1 text-xs text-[var(--color-ink-3)]">This link expires in 7 days.</p>
        <div className="mt-2"><Button size="sm" variant="ghost" onClick={() => { setDone(null); setEmail(''); onAdded(); }}>Add another</Button></div>
      </div>
    );
  }

  return (
    <form onSubmit={submit} className={`${wrap} grid grid-cols-1 sm:grid-cols-2 gap-3`}>
      <div>
        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Advisor email</label>
        <input type="email" required className="field" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="advisor@firm.in" />
      </div>
      <div>
        <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Firm role</label>
        <select className="field" value={firmRole} onChange={(e) => setFirmRole(e.target.value)}>
          <option value="jr_advisor">Jr. Advisor</option>
          <option value="sr_advisor">Sr. Advisor</option>
          <option value="firm_admin">Firm Admin</option>
        </select>
      </div>
      <p className="sm:col-span-2 text-xs text-[var(--color-ink-3)]">An invite email will be sent — the advisor sets their own password.</p>
      {err && <p className="sm:col-span-2 text-xs" style={{ color: 'var(--color-alert)' }}>{err}</p>}
      <div className="sm:col-span-2">
        <Button size="sm" type="submit" disabled={busy}>{busy ? 'Adding…' : 'Add advisor'}</Button>
      </div>
    </form>
  );
}

const WIZARD_STEPS = [
  { key: 'firm', label: 'Firm' },
  { key: 'branding', label: 'Branding' },
  { key: 'advisor', label: 'First advisor' },
];

// Onboarding wizard for a new firm — three short steps instead of one cramped
// form, with branding/advisor steps skippable ("do it later"), ending in a
// setup-progress summary so it reads like onboarding a real customer, not
// filling out a database record.
function CreateFirmModal({ open, onClose, onCreated }) {
  const [step, setStep] = useState(0);
  const [companyName, setCompanyName] = useState('');
  const [advisoryMode, setAdvisoryMode] = useState('distribution');
  const [logoUrl, setLogoUrl] = useState('');
  const [primaryColor, setPrimaryColor] = useState('#0f766e');
  const [brandingSkipped, setBrandingSkipped] = useState(false);
  const [addAdvisor, setAddAdvisor] = useState(true);
  const [email, setEmail] = useState('');
  const [firmRole, setFirmRole] = useState('firm_admin');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [done, setDone] = useState(null);

  function reset() {
    setStep(0); setCompanyName(''); setAdvisoryMode('distribution');
    setLogoUrl(''); setPrimaryColor('#0f766e'); setBrandingSkipped(false);
    setAddAdvisor(true); setEmail(''); setFirmRole('firm_admin'); setErr(''); setDone(null);
  }
  function closeAndReset() { onClose(); setTimeout(reset, 200); }

  async function finish() {
    setBusy(true); setErr('');
    try {
      const firstAdvisor = addAdvisor && email.trim()
        ? { email: email.trim(), firm_role: firmRole }
        : undefined;
      const res = await api.createTenant(companyName.trim(), advisoryMode, firstAdvisor);

      const hasBranding = !brandingSkipped && (logoUrl.trim() || primaryColor !== '#0f766e');
      if (hasBranding) {
        await api.updateTenant(res.tenant_id, {
          white_label: {
            ...(logoUrl.trim() ? { logo_url: logoUrl.trim() } : {}),
            ...(primaryColor ? { primary_color: primaryColor } : {}),
          },
        });
      }

      setDone({
        companyName: companyName.trim(),
        advisorEmail: firstAdvisor ? firstAdvisor.email : null,
        inviteLink: firstAdvisor ? res.invite_link : null,
        brandingSet: hasBranding,
      });
    } catch (e2) {
      setErr(e2.message || 'Could not create the firm.');
    } finally {
      setBusy(false);
    }
  }

  function next() {
    setErr('');
    if (step === 0 && !companyName.trim()) { setErr('Firm name is required.'); return; }
    if (step < 2) { setStep(step + 1); return; }
    finish();
  }
  function back() { setErr(''); setStep(Math.max(0, step - 1)); }

  return (
    <Modal open={open} onClose={closeAndReset} size="lg" title={done ? 'Firm onboarded' : 'Onboard a new firm'}>
      {done ? (
        <div>
          <p className="text-sm text-[var(--color-ink)]">
            <strong>{done.companyName}</strong> is live on HorizonPlan. Here's what's set up:
          </p>
          <ul className="mt-4 space-y-2 text-sm">
            <li className="flex items-center gap-2 text-[var(--color-ink)]">
              <CheckDot /> Firm created, compliance mode set
            </li>
            <li className="flex items-center gap-2" style={{ color: done.brandingSet ? 'var(--color-ink)' : 'var(--color-ink-3)' }}>
              {done.brandingSet ? <CheckDot /> : <PendingDot />}
              {done.brandingSet ? 'Branding applied' : 'Branding not set yet — add it from Manage firm'}
            </li>
            <li className="flex items-center gap-2" style={{ color: done.advisorEmail ? 'var(--color-ink)' : 'var(--color-ink-3)' }}>
              {done.advisorEmail ? <CheckDot /> : <PendingDot />}
              {done.advisorEmail ? `Advisor ${done.advisorEmail} created and emailed an invite link` : 'No advisor added yet — add one from Manage firm'}
            </li>
          </ul>
          {done.inviteLink && (
            <div className="mt-3">
              <p className="text-xs text-[var(--color-ink-2)]">Invite link (fallback in case the email doesn't land, expires in 7 days):</p>
              <div className="mt-1 flex items-center gap-2">
                <input readOnly className="field text-xs" value={done.inviteLink} onFocus={(e) => e.target.select()} />
                <Button size="sm" variant="outline" onClick={() => navigator.clipboard?.writeText(done.inviteLink)}>Copy</Button>
              </div>
            </div>
          )}
          <div className="mt-5"><Button onClick={() => { onCreated(); reset(); }}>Done</Button></div>
        </div>
      ) : (
        <div>
          <ol className="mb-6 flex items-center gap-2">
            {WIZARD_STEPS.map((s, i) => (
              <li key={s.key} className="flex items-center gap-2 flex-1">
                <span
                  className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold"
                  style={
                    i <= step
                      ? { background: 'var(--color-ink)', color: 'white' }
                      : { background: 'var(--color-surface-2)', color: 'var(--color-ink-3)' }
                  }
                >
                  {i + 1}
                </span>
                <span className={`text-xs font-medium ${i === step ? 'text-[var(--color-ink)]' : 'text-[var(--color-ink-3)]'}`}>{s.label}</span>
                {i < WIZARD_STEPS.length - 1 && <span className="h-px flex-1 bg-[var(--color-line)]" />}
              </li>
            ))}
          </ol>

          {step === 0 && (
            <div>
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
              <p className="text-xs text-[var(--color-ink-3)]">
                Advisory mode carries advice language and is for SEBI-RIA firms only — set it only after off-platform review.
              </p>
            </div>
          )}

          {step === 1 && (
            <div>
              <p className="mb-3 text-sm text-[var(--color-ink-2)]">Optional — logo and colour shown on this firm's client-facing pages. Skip and set it later from Manage firm.</p>
              <div
                className="mb-4 flex items-center gap-3 rounded-[var(--radius-card)] px-4 py-3 border border-[var(--color-line-2)]"
                style={{ background: `color-mix(in srgb, ${primaryColor} 8%, var(--color-surface))` }}
              >
                {logoUrl.trim() ? (
                  <img src={logoUrl.trim()} alt="" className="h-8 w-8 rounded object-cover" />
                ) : (
                  <div className="h-8 w-8 rounded flex items-center justify-center text-xs font-semibold text-white" style={{ background: primaryColor }}>
                    {(companyName || '?').slice(0, 1).toUpperCase()}
                  </div>
                )}
                <p className="text-sm font-semibold" style={{ color: primaryColor }}>{companyName || 'Firm name'}</p>
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Logo URL</label>
                  <input className="field" value={logoUrl} onChange={(e) => { setLogoUrl(e.target.value); setBrandingSkipped(false); }} placeholder="https://…/logo.svg" />
                </div>
                <div>
                  <label className="block text-xs font-medium text-[var(--color-ink-2)] mb-1">Primary colour</label>
                  <div className="flex items-center gap-2">
                    <input type="color" value={primaryColor} onChange={(e) => { setPrimaryColor(e.target.value); setBrandingSkipped(false); }} className="h-9 w-10 rounded border border-[var(--color-line-2)]" />
                    <input className="field" value={primaryColor} onChange={(e) => { setPrimaryColor(e.target.value); setBrandingSkipped(false); }} />
                  </div>
                </div>
              </div>
            </div>
          )}

          {step === 2 && (
            <div>
              <label className="flex items-center gap-2 mb-3 text-sm text-[var(--color-ink-2)]">
                <input type="checkbox" checked={addAdvisor} onChange={(e) => setAddAdvisor(e.target.checked)} />
                Add the first advisor now
              </label>
              {addAdvisor && (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div>
                    <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Advisor email</label>
                    <input type="email" className="field" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="advisor@firm.in" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Firm role</label>
                    <select className="field" value={firmRole} onChange={(e) => setFirmRole(e.target.value)}>
                      <option value="jr_advisor">Jr. Advisor</option>
                      <option value="sr_advisor">Sr. Advisor</option>
                      <option value="firm_admin">Firm Admin</option>
                    </select>
                  </div>
                  <p className="sm:col-span-2 text-xs text-[var(--color-ink-3)]">An invite email will be sent — the advisor sets their own password.</p>
                </div>
              )}
              {!addAdvisor && (
                <p className="text-xs text-[var(--color-ink-3)]">You can add advisors any time from Manage firm.</p>
              )}
            </div>
          )}

          {err && (
            <p className="mt-4 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2" style={{ color: 'var(--color-alert)' }}>
              {err}
            </p>
          )}

          <div className="mt-6 flex gap-2">
            {step > 0 && <Button type="button" variant="ghost" onClick={back}>Back</Button>}
            {step === 1 && (
              <Button type="button" variant="ghost" onClick={() => { setBrandingSkipped(true); setLogoUrl(''); setPrimaryColor('#0f766e'); setStep(2); }}>Skip</Button>
            )}
            <div className="flex-1" />
            <Button type="button" onClick={next} disabled={busy}>
              {busy ? 'Creating…' : step === 2 ? 'Create firm' : 'Continue'}
            </Button>
            <Button type="button" variant="ghost" onClick={closeAndReset}>Cancel</Button>
          </div>
        </div>
      )}
    </Modal>
  );
}

function CheckDot() {
  return (
    <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full" style={{ background: 'var(--color-teal)' }}>
      <svg width="10" height="10" viewBox="0 0 24 24" fill="none"><path d="M4 12l5 5L20 6" stroke="white" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" /></svg>
    </span>
  );
}
function PendingDot() {
  return <span className="h-4 w-4 shrink-0 rounded-full border-2" style={{ borderColor: 'var(--color-line-2)' }} />;
}
