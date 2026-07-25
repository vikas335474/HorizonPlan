// Shared visual primitives for the strategy-template system.
// Used by AdminConsole (Strategy Templates tab) and AdvisorTemplates page.

import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import Modal from './Modal';
import { Badge, Button, Spinner, EmptyState } from './ui';

// ─── Allocation bar ───────────────────────────────────────────────────────────
// Renders a proportional colour strip for {equity, debt, gold, cash, ...}.
// Unknown asset classes get a deterministic grey-family colour.

const ASSET_COLORS = {
  equity: { bar: '#0d9488', label: '#0f766e' },
  debt:   { bar: '#3b82f6', label: '#1d4ed8' },
  gold:   { bar: '#f59e0b', label: '#b45309' },
  cash:   { bar: '#9ca3af', label: '#6b7280' },
};

function assetColor(name) {
  return ASSET_COLORS[name.toLowerCase()] ?? { bar: '#a0a0b0', label: '#6b7280' };
}

export function AllocationBar({ allocation }) {
  if (!allocation || typeof allocation !== 'object') return null;
  const entries = Object.entries(allocation).filter(([, v]) => v > 0);
  if (entries.length === 0) return null;

  return (
    <div>
      <div className="flex rounded-full overflow-hidden h-2 w-full max-w-xs">
        {entries.map(([asset, pct]) => (
          <div
            key={asset}
            style={{ width: `${pct}%`, backgroundColor: assetColor(asset).bar }}
            title={`${asset}: ${pct}%`}
          />
        ))}
      </div>
      <div className="flex flex-wrap gap-x-3 gap-y-0.5 mt-1">
        {entries.map(([asset, pct]) => (
          <span key={asset} className="text-[11px]" style={{ color: assetColor(asset).label }}>
            {asset.charAt(0).toUpperCase() + asset.slice(1)} {pct}%
          </span>
        ))}
      </div>
    </div>
  );
}

// ─── Risk profile badge ───────────────────────────────────────────────────────

const RISK_STYLES = {
  conservative: { fg: '#1d4ed8', bg: '#dbeafe' },
  moderate:     { fg: '#0f766e', bg: '#ccfbf1' },
  balanced:     { fg: '#065f46', bg: '#d1fae5' },
  aggressive:   { fg: '#b45309', bg: '#fef3c7' },
};

export function RiskBadge({ profile }) {
  if (!profile) return null;
  const style = RISK_STYLES[profile] ?? { fg: 'var(--color-ink-2)', bg: 'var(--color-surface-2)' };
  return <Badge fg={style.fg} bg={style.bg}>{profile}</Badge>;
}

// ─── Template create modal ────────────────────────────────────────────────────
// Used by AdminConsole (isGlobal=true) and AdvisorTemplates (isGlobal=false).

const RISK_PROFILES = ['conservative', 'moderate', 'balanced', 'aggressive'];
const DEFAULT_ALLOCATION = { equity: 60, debt: 30, gold: 10, cash: 0 };

function parseAllocationField(raw) {
  // Parses "equity 60, debt 30, gold 10" or "equity:60 debt:30" -> object
  const result = {};
  const pairs = raw.split(/[,\n]+/);
  for (const pair of pairs) {
    const m = pair.trim().match(/^([a-z]+)\s*[:\s]\s*(\d+(?:\.\d+)?)$/i);
    if (m) result[m[1].toLowerCase()] = parseFloat(m[2]);
  }
  return result;
}

export function TemplateCreateModal({ open, onClose, onCreated, isGlobal = false }) {
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [allocationRaw, setAllocationRaw] = useState('equity 60, debt 30, gold 10, cash 0');
  const [returnPct, setReturnPct] = useState('');
  const [riskProfile, setRiskProfile] = useState('');
  const [isPublished, setIsPublished] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  function reset() {
    setName(''); setDescription(''); setAllocationRaw('equity 60, debt 30, gold 10, cash 0');
    setReturnPct(''); setRiskProfile(''); setIsPublished(false); setError('');
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    const allocation = parseAllocationField(allocationRaw);
    if (Object.keys(allocation).length === 0) {
      setError('Could not parse allocation. Use format: equity 60, debt 30, gold 10.');
      return;
    }
    const sum = Object.values(allocation).reduce((a, b) => a + b, 0);
    if (Math.abs(sum - 100) > 0.5) {
      setError(`Allocations must sum to 100 (currently ${sum.toFixed(1)}).`);
      return;
    }

    setBusy(true);
    try {
      await api.createTemplate({
        template_name: name.trim(),
        description: description.trim() || null,
        allocation_json: allocation,
        return_assumption_pct: returnPct ? parseFloat(returnPct) : null,
        risk_profile_enum: riskProfile || null,
        is_system_template: isGlobal,
        is_published: isPublished,
      });
      onCreated();
      reset();
    } catch (err) {
      setError(err.message || 'Could not create template.');
    } finally {
      setBusy(false);
    }
  }

  const preview = parseAllocationField(allocationRaw);

  return (
    <Modal
      open={open}
      onClose={() => { onClose(); setTimeout(reset, 200); }}
      title={isGlobal ? 'New global template' : 'New template'}
      description={isGlobal
        ? 'Create a strategy template that all advisory firms can see and fork.'
        : 'Create a strategy template for your own use.'}
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1">Template name *</label>
          <input
            required autoFocus className="field"
            value={name} onChange={(e) => setName(e.target.value)}
            placeholder="e.g. Balanced Growth – India"
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1">Description</label>
          <textarea
            className="field resize-none" rows={2}
            value={description} onChange={(e) => setDescription(e.target.value)}
            placeholder="Suitable for 10–15 year horizon with moderate risk tolerance…"
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1">Asset allocation *</label>
          <input
            className="field font-mono text-sm"
            value={allocationRaw} onChange={(e) => setAllocationRaw(e.target.value)}
            placeholder="equity 60, debt 30, gold 10, cash 0"
          />
          <p className="text-[11px] text-[var(--color-ink-3)] mt-0.5">Format: asset value, asset value — must sum to 100.</p>
          {Object.keys(preview).length > 0 && (
            <div className="mt-2">
              <AllocationBar allocation={preview} />
            </div>
          )}
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1">Return assumption (%)</label>
            <input
              type="number" min="0" max="30" step="0.1" className="field"
              value={returnPct} onChange={(e) => setReturnPct(e.target.value)}
              placeholder="e.g. 10.5"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1">Risk profile</label>
            <select className="field" value={riskProfile} onChange={(e) => setRiskProfile(e.target.value)}>
              <option value="">— select —</option>
              {RISK_PROFILES.map((p) => (
                <option key={p} value={p}>{p.charAt(0).toUpperCase() + p.slice(1)}</option>
              ))}
            </select>
          </div>
        </div>

        {isGlobal && (
          <label className="flex items-center gap-2 text-sm text-[var(--color-ink-2)]">
            <input type="checkbox" checked={isPublished} onChange={(e) => setIsPublished(e.target.checked)} />
            Publish immediately (visible to all advisory firms)
          </label>
        )}

        {error && (
          <p className="text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2" style={{ color: 'var(--color-alert)' }}>
            {error}
          </p>
        )}

        <div className="flex gap-2">
          <Button type="submit" disabled={busy} className="flex-1">
            {busy ? 'Creating…' : 'Create template'}
          </Button>
          <Button type="button" variant="ghost" onClick={() => { onClose(); setTimeout(reset, 200); }}>Cancel</Button>
        </div>
      </form>
    </Modal>
  );
}

// ─── Fork/customize modal ─────────────────────────────────────────────────────
// Used on the Global Library and My Created tabs — advisor clicks "Fork".

export function ForkTemplateModal({ open, onClose, onForked, template }) {
  const [name, setName] = useState('');
  const [allocationRaw, setAllocationRaw] = useState('');
  const [returnPct, setReturnPct] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  function reset() {
    setName(''); setAllocationRaw(''); setReturnPct(''); setError('');
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');

    let allocationOverride = null;
    if (allocationRaw.trim()) {
      const parsed = parseAllocationField(allocationRaw);
      if (Object.keys(parsed).length === 0) {
        setError('Could not parse allocation. Use format: equity 60, debt 30, gold 10.');
        return;
      }
      const sum = Object.values(parsed).reduce((a, b) => a + b, 0);
      if (Math.abs(sum - 100) > 0.5) {
        setError(`Allocations must sum to 100 (currently ${sum.toFixed(1)}).`);
        return;
      }
      allocationOverride = parsed;
    }

    setBusy(true);
    try {
      await api.forkTemplate(template.id, {
        template_name: name.trim() || `${template.template_name} (customized)`,
        allocation_json: allocationOverride,
        return_assumption_pct: returnPct ? parseFloat(returnPct) : null,
      });
      onForked();
      reset();
    } catch (err) {
      setError(err.message || 'Could not fork template.');
    } finally {
      setBusy(false);
    }
  }

  if (!template) return null;

  return (
    <Modal
      open={open}
      onClose={() => { onClose(); setTimeout(reset, 200); }}
      title="Fork template"
      description={`Forking "${template.template_name}" — your copy won't affect the original.`}
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1">Name for your copy</label>
          <input
            autoFocus className="field"
            value={name} onChange={(e) => setName(e.target.value)}
            placeholder={`${template.template_name} (customized)`}
          />
        </div>

        <div className="rounded-[var(--radius-ctrl)] bg-[var(--color-surface-2)] p-3 text-xs text-[var(--color-ink-2)]">
          <p className="font-medium text-[var(--color-ink)] mb-1">Base allocation</p>
          <AllocationBar allocation={template.allocation_json} />
        </div>

        <div>
          <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1">Override allocation (optional)</label>
          <input
            className="field font-mono text-sm"
            value={allocationRaw} onChange={(e) => setAllocationRaw(e.target.value)}
            placeholder="Leave blank to inherit base allocation"
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1">Override return assumption % (optional)</label>
          <input
            type="number" min="0" max="30" step="0.1" className="field"
            value={returnPct} onChange={(e) => setReturnPct(e.target.value)}
            placeholder={template.return_assumption_pct != null ? String(template.return_assumption_pct) : 'Inherit from base'}
          />
        </div>

        {error && (
          <p className="text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2" style={{ color: 'var(--color-alert)' }}>
            {error}
          </p>
        )}

        <div className="flex gap-2">
          <Button type="submit" disabled={busy} className="flex-1">{busy ? 'Forking…' : 'Fork template'}</Button>
          <Button type="button" variant="ghost" onClick={() => { onClose(); setTimeout(reset, 200); }}>Cancel</Button>
        </div>
      </form>
    </Modal>
  );
}

// ─── Approval badge + button (docs/07 Bet 1) ──────────────────────────────────
// An unapproved template/customization is illustration-only — it can be
// browsed and forked, but goals_apply_template.php refuses to apply it to a
// real client's goal. This badge/button pair makes that state visible and
// actionable everywhere a template row renders.

export function ApprovalBadge({ status }) {
  if (status === 'approved') {
    return <Badge fg="var(--color-teal-ink)" bg="var(--color-teal-soft)">Approved</Badge>;
  }
  return <Badge fg="var(--color-amber)" bg="var(--color-amber-soft)">Draft — not approved</Badge>;
}

export function ApproveButton({ templateId, customizationId, approvalStatus, onApproved, size = 'sm' }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  if (approvalStatus === 'approved') return null;

  async function handleApprove() {
    setBusy(true);
    setError('');
    try {
      await api.approveTemplate(templateId, customizationId);
      onApproved();
    } catch (err) {
      setError(err.message || 'Could not approve.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="inline-flex items-center gap-2">
      <Button variant="outline" size={size} onClick={handleApprove} disabled={busy}>
        {busy ? 'Approving…' : 'Approve for use'}
      </Button>
      {error && <span className="text-xs" style={{ color: 'var(--color-alert)' }}>{error}</span>}
    </div>
  );
}

// ─── Apply-to-goal modal (docs/07 Bet 1 — the loop-closer) ────────────────────
// Lets an advisor pick an APPROVED template/customization and apply its
// return assumption to a retirement goal via goals_apply_template.php.
// Unapproved rows are listed but disabled, with a note why, so the gap
// between "exists in the library" and "usable on a real plan" is visible
// rather than just producing a 403 after the fact.

export function ApplyTemplateModal({ open, onClose, onApplied, goalId }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [applyingId, setApplyingId] = useState(null);

  useEffect(() => {
    if (!open) return;
    setLoading(true);
    setError('');
    api.listTemplates()
      .then(setData)
      .catch((err) => setError(err.message || 'Could not load templates.'))
      .finally(() => setLoading(false));
  }, [open]);

  async function handleApply(entry) {
    setApplyingId(entry.key);
    setError('');
    try {
      await api.applyTemplateToGoal(goalId, {
        templateId: entry.kind === 'template' ? entry.id : undefined,
        customizationId: entry.kind === 'customization' ? entry.id : undefined,
      });
      onApplied();
    } catch (err) {
      setError(err.message || 'Could not apply this template.');
    } finally {
      setApplyingId(null);
    }
  }

  const rows = data
    ? [
        ...(data.global_templates ?? []).map((t) => ({ ...t, kind: 'template', key: `t${t.id}`, sourceLabel: 'Global' })),
        ...(data.my_templates ?? []).map((t) => ({ ...t, kind: 'template', key: `t${t.id}`, sourceLabel: 'My created' })),
        ...(data.my_customizations ?? []).map((c) => ({ ...c, kind: 'customization', key: `c${c.id}`, sourceLabel: 'My customized' })),
      ]
    : [];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Apply a strategy template"
      description="Sets this goal's post-retirement return assumption from an approved template. Only approved templates can be applied to a real plan."
    >
      {loading && <Spinner label="Loading templates…" />}
      {error && (
        <p className="mb-3 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2" style={{ color: 'var(--color-alert)' }}>
          {error}
        </p>
      )}

      {data && rows.length === 0 && (
        <EmptyState title="No templates yet">
          Create or fork a strategy template first, from the Templates page.
        </EmptyState>
      )}

      {data && rows.length > 0 && (
        <div className="space-y-2 max-h-96 overflow-y-auto">
          {rows.map((entry) => {
            const isApproved = entry.approval_status === 'approved';
            return (
              <div
                key={entry.key}
                className="flex items-center justify-between gap-3 rounded-[var(--radius-ctrl)] border border-[var(--color-line)] p-3"
                style={{ opacity: isApproved ? 1 : 0.65 }}
              >
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2 flex-wrap mb-0.5">
                    <span className="text-sm font-medium text-[var(--color-ink)] truncate">{entry.template_name}</span>
                    <Badge fg="var(--color-ink-3)" bg="var(--color-surface-2)">{entry.sourceLabel}</Badge>
                    <ApprovalBadge status={entry.approval_status} />
                  </div>
                  <div className="text-xs text-[var(--color-ink-3)]">
                    {entry.return_assumption_pct != null ? `${entry.return_assumption_pct}% return assumption` : 'No return assumption set'}
                  </div>
                </div>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={!isApproved || entry.return_assumption_pct == null || applyingId === entry.key}
                  onClick={() => handleApply(entry)}
                  title={!isApproved ? 'Not yet approved — cannot be applied to a client plan' : undefined}
                >
                  {applyingId === entry.key ? 'Applying…' : 'Apply'}
                </Button>
              </div>
            );
          })}
        </div>
      )}
    </Modal>
  );
}
