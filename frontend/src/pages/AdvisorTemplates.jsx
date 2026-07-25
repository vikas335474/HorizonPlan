import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import AppHeader from '../components/AppHeader';
import { Card, Badge, Button, EmptyState, Spinner } from '../components/ui';
import { AllocationBar, RiskBadge, TemplateCreateModal, ForkTemplateModal } from '../components/TemplateUI';

// Advisor console — Strategy Templates page.
// Tab 1: Global Library (SaaS published templates, advisor can fork any)
// Tab 2: My Created  (templates this advisor's firm created from scratch)
// Tab 3: My Customized (forks this firm made from any base template)
export default function AdvisorTemplates() {
  const [activeTab, setActiveTab] = useState('global');
  const [data, setData] = useState(null); // { global_templates, my_templates, my_customizations }
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  function load() {
    setLoading(true);
    api.listTemplates()
      .then(setData)
      .catch((err) => setError(err.message || 'Could not load templates.'))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, []);

  return (
    <div className="min-h-screen">
      <AppHeader />

      <div className="border-b border-[var(--color-line)] bg-[var(--color-surface)]">
        <div className="mx-auto max-w-5xl px-5">
          <div className="pt-6 pb-2 flex items-end justify-between gap-4">
            <div>
              <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">My Templates</h1>
              <p className="mt-1 text-sm text-[var(--color-ink-2)]">
                Global strategy library, your own templates, and your customized forks.
              </p>
            </div>
          </div>
          <nav className="flex gap-1">
            {[
              { key: 'global', label: 'Global Library' },
              { key: 'created', label: 'My Created' },
              { key: 'customized', label: 'My Customized' },
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
        {loading && <Spinner label="Loading templates…" />}
        {error && (
          <Card className="p-4 border-[var(--color-alert)]">
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
          </Card>
        )}

        {!loading && data && (
          <>
            {activeTab === 'global' && (
              <GlobalLibraryTab templates={data.global_templates ?? []} onChanged={load} />
            )}
            {activeTab === 'created' && (
              <MyCreatedTab templates={data.my_templates ?? []} onChanged={load} />
            )}
            {activeTab === 'customized' && (
              <MyCustomizedTab customizations={data.my_customizations ?? []} globalTemplates={data.global_templates ?? []} onChanged={load} />
            )}
          </>
        )}
      </main>
    </div>
  );
}

// ─── Global Library tab ───────────────────────────────────────────────────────

function GlobalLibraryTab({ templates, onChanged }) {
  const [query, setQuery] = useState('');
  const [riskFilter, setRiskFilter] = useState('');
  const [forkTarget, setForkTarget] = useState(null);

  const filtered = templates.filter((t) => {
    const matchQ = query === '' || t.template_name.toLowerCase().includes(query.toLowerCase());
    const matchR = riskFilter === '' || t.risk_profile_enum === riskFilter;
    return matchQ && matchR;
  });

  return (
    <>
      <div className="flex flex-wrap gap-3 mb-4">
        <input
          type="search" className="field max-w-xs"
          placeholder="Search templates…"
          value={query} onChange={(e) => setQuery(e.target.value)}
        />
        <select className="field w-auto" value={riskFilter} onChange={(e) => setRiskFilter(e.target.value)}>
          <option value="">All risk profiles</option>
          {['conservative', 'moderate', 'balanced', 'aggressive'].map((p) => (
            <option key={p} value={p}>{p.charAt(0).toUpperCase() + p.slice(1)}</option>
          ))}
        </select>
      </div>

      {templates.length === 0 && (
        <Card>
          <EmptyState title="No global templates available">
            The HorizonPlan team will publish strategy templates here. Check back soon.
          </EmptyState>
        </Card>
      )}

      {filtered.length > 0 && (
        <div className="space-y-3">
          {filtered.map((t) => (
            <GlobalTemplateRow
              key={t.id}
              template={t}
              onFork={() => setForkTarget(t)}
            />
          ))}
        </div>
      )}
      {templates.length > 0 && filtered.length === 0 && (
        <p className="text-sm text-[var(--color-ink-3)] py-8 text-center">No templates match your filters.</p>
      )}

      <ForkTemplateModal
        open={forkTarget !== null}
        onClose={() => setForkTarget(null)}
        onForked={() => { setForkTarget(null); onChanged(); }}
        template={forkTarget}
      />
    </>
  );
}

function GlobalTemplateRow({ template, onFork }) {
  return (
    <Card className="p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2 mb-1">
            <h3 className="text-sm font-semibold text-[var(--color-ink)]">{template.template_name}</h3>
            {template.risk_profile_enum && <RiskBadge profile={template.risk_profile_enum} />}
            {template.market_code && (
              <Badge fg="var(--color-ink-3)" bg="var(--color-surface-2)">{template.market_code}</Badge>
            )}
          </div>
          {template.description && (
            <p className="text-xs text-[var(--color-ink-2)] mb-2 max-w-lg">{template.description}</p>
          )}
          <AllocationBar allocation={template.allocation_json} />
          <div className="mt-1.5 flex gap-4 text-xs text-[var(--color-ink-3)]">
            {template.return_assumption_pct != null && (
              <span>Return assumption: <strong className="text-[var(--color-ink)]">{template.return_assumption_pct}%</strong></span>
            )}
            <span>Used by <strong className="text-[var(--color-ink)]">{template.usage_count}</strong> {template.usage_count === 1 ? 'advisor' : 'advisors'}</span>
          </div>
        </div>
        <Button variant="outline" size="sm" onClick={onFork}>Fork</Button>
      </div>
    </Card>
  );
}

// ─── My Created tab ───────────────────────────────────────────────────────────

function MyCreatedTab({ templates, onChanged }) {
  const [createOpen, setCreateOpen] = useState(false);
  const [forkTarget, setForkTarget] = useState(null);

  return (
    <>
      <div className="mb-5 flex items-center justify-between gap-4">
        <p className="text-sm text-[var(--color-ink-2)]">
          Templates you created from scratch — only visible to your firm.
        </p>
        <Button onClick={() => setCreateOpen(true)}>+ New template</Button>
      </div>

      {templates.length === 0 && (
        <Card>
          <EmptyState title="No templates created yet" action={<Button onClick={() => setCreateOpen(true)}>Create your first template</Button>}>
            Build a strategy template from scratch to reuse across client goals.
          </EmptyState>
        </Card>
      )}

      {templates.length > 0 && (
        <div className="space-y-3">
          {templates.map((t) => (
            <OwnTemplateRow
              key={t.id}
              template={t}
              onFork={() => setForkTarget(t)}
            />
          ))}
        </div>
      )}

      <TemplateCreateModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={() => { setCreateOpen(false); onChanged(); }}
        isGlobal={false}
      />
      <ForkTemplateModal
        open={forkTarget !== null}
        onClose={() => setForkTarget(null)}
        onForked={() => { setForkTarget(null); onChanged(); }}
        template={forkTarget}
      />
    </>
  );
}

function OwnTemplateRow({ template, onFork }) {
  return (
    <Card className="p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2 mb-1">
            <h3 className="text-sm font-semibold text-[var(--color-ink)]">{template.template_name}</h3>
            {template.risk_profile_enum && <RiskBadge profile={template.risk_profile_enum} />}
            <Badge
              fg={template.is_published ? 'var(--color-teal-ink)' : 'var(--color-ink-3)'}
              bg={template.is_published ? 'var(--color-teal-soft)' : 'var(--color-surface-2)'}
            >
              {template.is_published ? 'published' : 'draft'}
            </Badge>
          </div>
          {template.description && (
            <p className="text-xs text-[var(--color-ink-2)] mb-2 max-w-lg">{template.description}</p>
          )}
          <AllocationBar allocation={template.allocation_json} />
          <div className="mt-1.5 flex gap-4 text-xs text-[var(--color-ink-3)]">
            {template.return_assumption_pct != null && (
              <span>Return assumption: <strong className="text-[var(--color-ink)]">{template.return_assumption_pct}%</strong></span>
            )}
            <span>Used <strong className="text-[var(--color-ink)]">{template.usage_count}</strong> {template.usage_count === 1 ? 'time' : 'times'}</span>
          </div>
        </div>
        <Button variant="ghost" size="sm" onClick={onFork}>Fork</Button>
      </div>
    </Card>
  );
}

// ─── My Customized tab ────────────────────────────────────────────────────────

function MyCustomizedTab({ customizations, globalTemplates, onChanged }) {
  const [editTarget, setEditTarget] = useState(null);
  const [auditTarget, setAuditTarget] = useState(null);

  const globalById = Object.fromEntries(globalTemplates.map((t) => [String(t.id), t]));

  return (
    <>
      <div className="mb-5">
        <p className="text-sm text-[var(--color-ink-2)]">
          Your forks and customized versions of global or own templates.
        </p>
      </div>

      {customizations.length === 0 && (
        <Card>
          <EmptyState title="No customized templates yet">
            Go to Global Library or My Created and fork a template to customize it for your firm.
          </EmptyState>
        </Card>
      )}

      {customizations.length > 0 && (
        <div className="space-y-3">
          {customizations.map((c) => (
            <CustomizationRow
              key={c.id}
              customization={c}
              baseTemplate={globalById[String(c.base_template_id)] ?? null}
              onEdit={() => setEditTarget(c)}
              onAudit={() => setAuditTarget(c)}
            />
          ))}
        </div>
      )}

      {editTarget && (
        <EditCustomizationModal
          customization={editTarget}
          onClose={() => setEditTarget(null)}
          onSaved={() => { setEditTarget(null); onChanged(); }}
        />
      )}

      {auditTarget && (
        <AuditModal
          customization={auditTarget}
          onClose={() => setAuditTarget(null)}
        />
      )}
    </>
  );
}

function CustomizationRow({ customization: c, baseTemplate, onEdit, onAudit }) {
  const effectiveAllocation = c.allocation_json ?? baseTemplate?.allocation_json ?? null;
  const effectiveReturn = c.return_assumption_pct ?? baseTemplate?.return_assumption_pct ?? null;

  return (
    <Card className="p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2 mb-0.5">
            <h3 className="text-sm font-semibold text-[var(--color-ink)]">{c.template_name}</h3>
            {c.is_shareable && (
              <Badge fg="var(--color-teal-ink)" bg="var(--color-teal-soft)">shareable</Badge>
            )}
          </div>
          {baseTemplate && (
            <p className="text-[11px] text-[var(--color-ink-3)] mb-2">
              Forked from <strong className="text-[var(--color-ink-2)]">{baseTemplate.template_name}</strong>
              {c.allocation_json ? ' · allocation overridden' : ''}
              {c.return_assumption_pct != null ? ' · return assumption overridden' : ''}
            </p>
          )}
          {effectiveAllocation && <AllocationBar allocation={effectiveAllocation} />}
          <div className="mt-1.5 flex gap-4 text-xs text-[var(--color-ink-3)]">
            {effectiveReturn != null && (
              <span>Return assumption: <strong className="text-[var(--color-ink)]">{effectiveReturn}%</strong></span>
            )}
            <span>Used <strong className="text-[var(--color-ink)]">{c.usage_count}</strong> {c.usage_count === 1 ? 'time' : 'times'}</span>
            <span>Last updated: <strong className="text-[var(--color-ink)]">{c.last_modified_at?.slice(0, 10)}</strong></span>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="ghost" size="sm" onClick={onAudit}>Provenance</Button>
          <Button variant="outline" size="sm" onClick={onEdit}>Edit</Button>
        </div>
      </div>
    </Card>
  );
}

function EditCustomizationModal({ customization: c, onClose, onSaved }) {
  const [name, setName] = useState(c.template_name);
  const [returnPct, setReturnPct] = useState(c.return_assumption_pct != null ? String(c.return_assumption_pct) : '');
  const [isShareable, setIsShareable] = useState(Boolean(c.is_shareable));
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setBusy(true);
    try {
      await api.updateTemplateCustomization(c.id, {
        template_name: name.trim(),
        return_assumption_pct: returnPct ? parseFloat(returnPct) : null,
        is_shareable: isShareable,
      });
      onSaved();
    } catch (err) {
      setError(err.message || 'Could not save changes.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal open onClose={onClose} title="Edit customization" description={`Editing "${c.template_name}"`}>
      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1">Name</label>
          <input required className="field" value={name} onChange={(e) => setName(e.target.value)} />
        </div>
        <div>
          <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1">Override return assumption % (optional)</label>
          <input
            type="number" min="0" max="30" step="0.1" className="field"
            value={returnPct} onChange={(e) => setReturnPct(e.target.value)}
            placeholder="Leave blank to inherit from base"
          />
        </div>
        <label className="flex items-center gap-2 text-sm text-[var(--color-ink-2)]">
          <input type="checkbox" checked={isShareable} onChange={(e) => setIsShareable(e.target.checked)} />
          Mark as shareable with other firms
        </label>
        {error && (
          <p className="text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2" style={{ color: 'var(--color-alert)' }}>
            {error}
          </p>
        )}
        <div className="flex gap-2">
          <Button type="submit" disabled={busy} className="flex-1">{busy ? 'Saving…' : 'Save changes'}</Button>
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
        </div>
      </form>
    </Modal>
  );
}

function AuditModal({ customization, onClose }) {
  const [log, setLog] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    api.getTemplateAudit(null, customization.id)
      .then((res) => setLog(res.audit_log ?? []))
      .catch((err) => setError(err.message || 'Could not load audit log.'))
      .finally(() => setLoading(false));
  }, [customization.id]);

  return (
    <Modal open onClose={onClose} title="Provenance trail" description={`Full history for "${customization.template_name}"`}>
      {loading && <Spinner label="Loading history…" />}
      {error && <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>}
      {log && log.length === 0 && <p className="text-sm text-[var(--color-ink-3)]">No history yet.</p>}
      {log && log.length > 0 && (
        <ol className="space-y-3">
          {log.map((entry) => (
            <li key={entry.id} className="flex gap-3 text-sm">
              <span className="shrink-0 text-xs text-[var(--color-ink-3)] pt-0.5 tnum">{entry.created_at.slice(0, 16)}</span>
              <div>
                <strong className="text-[var(--color-ink)]">{entry.action}</strong>
                {entry.entity_details?.base_template_name && (
                  <span className="text-[var(--color-ink-2)]"> · from "{entry.entity_details.base_template_name}"</span>
                )}
                {entry.action === 'customized' && entry.entity_details && (
                  <ul className="mt-1 text-xs text-[var(--color-ink-3)] space-y-0.5 ml-3">
                    {Object.entries(entry.entity_details).map(([field, { old: o, new: n }]) => (
                      <li key={field}>
                        <span className="text-[var(--color-ink-2)]">{field}</span>: {String(o)} → {String(n)}
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </li>
          ))}
        </ol>
      )}
    </Modal>
  );
}
