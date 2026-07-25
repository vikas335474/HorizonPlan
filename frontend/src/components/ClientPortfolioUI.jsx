import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { Card, Badge, Button, Spinner } from './ui';
import { formatCurrency } from '../lib/format';

// docs/05 item 3 / docs/06 corpus composition: a factual snapshot of what the
// client already owns, independent of any one goal — shown on the client's
// page (ClientGoals.jsx), not per-goal. Advisors pull from this when setting
// a goal's own liquid/locked corpus split (GoalDetail.jsx), but a goal's
// split is independently editable, same as the rest of the "goal is not a
// shared household pool" principle (docs/02 4.1).

const ASSET_CATEGORIES = [
  { value: 'mutual_fund', label: 'Mutual fund', bucket: 'liquid' },
  { value: 'stocks', label: 'Stocks', bucket: 'liquid' },
  { value: 'fd', label: 'Fixed deposit', bucket: 'liquid' },
  { value: 'savings', label: 'Savings account', bucket: 'liquid' },
  { value: 'gold', label: 'Gold', bucket: 'liquid' },
  { value: 'ppf', label: 'PPF', bucket: 'locked' },
  { value: 'epf', label: 'EPF', bucket: 'locked' },
  { value: 'nps', label: 'NPS', bucket: 'locked' },
  { value: 'real_estate', label: 'Real estate', bucket: 'locked' },
  { value: 'other_asset', label: 'Other', bucket: 'liquid' },
];
const LIABILITY_CATEGORIES = [
  { value: 'home_loan', label: 'Home loan' },
  { value: 'personal_loan', label: 'Personal loan' },
  { value: 'credit_card', label: 'Credit card' },
  { value: 'other_liability', label: 'Other' },
];

function categoryLabel(kind, category) {
  const list = kind === 'liability' ? LIABILITY_CATEGORIES : ASSET_CATEGORIES;
  return list.find((c) => c.value === category)?.label || category;
}

export function ClientPortfolioCard({ clientId }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [adding, setAdding] = useState(false);
  const [editingId, setEditingId] = useState(null);

  function load() {
    setLoading(true);
    api
      .getClientPortfolio(clientId)
      .then(setData)
      .catch((err) => setError(err.message || 'Could not load the portfolio.'))
      .finally(() => setLoading(false));
  }

  useEffect(load, [clientId]);

  async function handleDelete(id) {
    if (!window.confirm('Remove this item from the portfolio?')) return;
    try {
      await api.deletePortfolioItem(id);
      load();
    } catch (err) {
      setError(err.message || 'Could not remove this item.');
    }
  }

  const items = data?.items ?? [];
  const totals = data?.totals;

  return (
    <Card className="p-4 mb-4">
      <div className="flex items-center justify-between gap-3 mb-1">
        <h2 className="text-base font-semibold text-[var(--color-ink)]">Portfolio &amp; net worth</h2>
        {!adding && (
          <Button variant="outline" size="sm" onClick={() => setAdding(true)}>+ Add item</Button>
        )}
      </div>
      <p className="text-xs text-[var(--color-ink-2)] mb-3">
        What the client already owns — a starting point, not a shared pool any one goal automatically draws from.
      </p>

      {loading && <Spinner label="Loading…" />}
      {error && <p className="text-xs mb-2" style={{ color: 'var(--color-alert)' }}>{error}</p>}

      {totals && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
          <Total label="Liquid" value={totals.liquid_total} />
          <Total label="Locked" value={totals.locked_total} />
          <Total label="Liabilities" value={totals.liabilities_total} negative />
          <Total label="Net worth" value={totals.net_worth} highlight />
        </div>
      )}

      {!loading && items.length === 0 && !adding && (
        <p className="text-xs text-[var(--color-ink-2)]">No portfolio items recorded yet.</p>
      )}

      {items.length > 0 && (
        <div className="space-y-1.5 mb-2">
          {items.map((item) =>
            editingId === item.id ? (
              <PortfolioItemForm
                key={item.id}
                clientId={clientId}
                existing={item}
                onSaved={() => { setEditingId(null); load(); }}
                onCancel={() => setEditingId(null)}
              />
            ) : (
              <div key={item.id} className="flex items-center justify-between gap-3 rounded-[var(--radius-ctrl)] border border-[var(--color-line)] px-3 py-2">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-sm font-medium text-[var(--color-ink)]">{categoryLabel(item.item_kind, item.category)}</span>
                    {item.item_kind === 'asset' ? (
                      <Badge fg={item.bucket === 'liquid' ? 'var(--color-teal-ink)' : 'var(--color-amber)'} bg={item.bucket === 'liquid' ? 'var(--color-teal-soft)' : 'var(--color-amber-soft)'}>
                        {item.bucket}
                      </Badge>
                    ) : (
                      <Badge fg="var(--color-alert)" bg="var(--color-alert-soft)">liability</Badge>
                    )}
                  </div>
                  {item.description && <div className="text-[11px] text-[var(--color-ink-3)]">{item.description}</div>}
                </div>
                <span className="tnum text-sm text-[var(--color-ink)]">{formatCurrency(item.value)}</span>
                <div className="flex gap-1 shrink-0">
                  <button type="button" onClick={() => setEditingId(item.id)} className="text-xs text-[var(--color-ink-2)] hover:text-[var(--color-ink)] hover:underline">Edit</button>
                  <button type="button" onClick={() => handleDelete(item.id)} className="text-xs text-[var(--color-ink-3)] hover:text-[var(--color-alert)] hover:underline">Remove</button>
                </div>
              </div>
            )
          )}
        </div>
      )}

      {adding && (
        <PortfolioItemForm
          clientId={clientId}
          onSaved={() => { setAdding(false); load(); }}
          onCancel={() => setAdding(false)}
        />
      )}
    </Card>
  );
}

function Total({ label, value, highlight, negative }) {
  return (
    <div>
      <div className="text-[11px] uppercase tracking-wider text-[var(--color-ink-3)]">{label}</div>
      <div
        className="tnum text-[15px] font-semibold mt-0.5"
        style={{ color: highlight ? 'var(--color-teal-ink)' : negative ? 'var(--color-alert)' : 'var(--color-ink)' }}
      >
        {negative && value > 0 ? '−' : ''}{formatCurrency(value)}
      </div>
    </div>
  );
}

function PortfolioItemForm({ clientId, existing, onSaved, onCancel }) {
  const [itemKind, setItemKind] = useState(existing?.item_kind || 'asset');
  const [category, setCategory] = useState(existing?.category || ASSET_CATEGORIES[0].value);
  const [bucket, setBucket] = useState(existing?.bucket || ASSET_CATEGORIES[0].bucket);
  const [description, setDescription] = useState(existing?.description || '');
  const [value, setValue] = useState(existing?.value != null ? String(existing.value) : '');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const categories = itemKind === 'liability' ? LIABILITY_CATEGORIES : ASSET_CATEGORIES;

  function handleKindChange(kind) {
    setItemKind(kind);
    const list = kind === 'liability' ? LIABILITY_CATEGORIES : ASSET_CATEGORIES;
    setCategory(list[0].value);
    if (kind === 'asset') setBucket(list[0].bucket);
  }

  function handleCategoryChange(cat) {
    setCategory(cat);
    const match = ASSET_CATEGORIES.find((c) => c.value === cat);
    if (match) setBucket(match.bucket); // suggest a bucket; advisor can still override below
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    const numericValue = Number(value);
    if (!Number.isFinite(numericValue) || numericValue < 0) {
      setError('Enter a valid amount (0 or more).');
      return;
    }
    setSaving(true);
    try {
      if (existing) {
        await api.updatePortfolioItem(existing.id, {
          bucket: itemKind === 'asset' ? bucket : undefined,
          category,
          description: description || null,
          value: numericValue,
        });
      } else {
        await api.createPortfolioItem({
          client_id: clientId,
          item_kind: itemKind,
          bucket: itemKind === 'asset' ? bucket : undefined,
          category,
          description: description || null,
          value: numericValue,
        });
      }
      onSaved();
    } catch (err) {
      setError(err.message || 'Could not save this item.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] p-3 mb-2">
      <div className="grid grid-cols-2 gap-2 mb-2">
        {!existing && (
          <div className="col-span-2 flex gap-2">
            <button
              type="button"
              onClick={() => handleKindChange('asset')}
              className="flex-1 rounded-[var(--radius-ctrl)] border px-2 py-1.5 text-xs font-medium"
              style={itemKind === 'asset' ? { borderColor: 'var(--color-teal)', backgroundColor: 'var(--color-teal-soft)', color: 'var(--color-teal-ink)' } : { borderColor: 'var(--color-line-2)', color: 'var(--color-ink-2)' }}
            >
              Asset
            </button>
            <button
              type="button"
              onClick={() => handleKindChange('liability')}
              className="flex-1 rounded-[var(--radius-ctrl)] border px-2 py-1.5 text-xs font-medium"
              style={itemKind === 'liability' ? { borderColor: 'var(--color-alert)', backgroundColor: 'var(--color-alert-soft)', color: 'var(--color-alert)' } : { borderColor: 'var(--color-line-2)', color: 'var(--color-ink-2)' }}
            >
              Liability
            </button>
          </div>
        )}

        <select value={category} onChange={(e) => handleCategoryChange(e.target.value)} className="field text-sm">
          {categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
        </select>

        {itemKind === 'asset' && (
          <select value={bucket} onChange={(e) => setBucket(e.target.value)} className="field text-sm">
            <option value="liquid">Liquid</option>
            <option value="locked">Locked</option>
          </select>
        )}

        <input
          type="number" min="0" step="any" required value={value}
          onChange={(e) => setValue(e.target.value)}
          placeholder="Value (₹)"
          className={`field text-sm ${itemKind === 'liability' ? 'col-span-2' : ''}`}
        />
        <input
          type="text" value={description}
          onChange={(e) => setDescription(e.target.value)}
          placeholder="Description (optional)"
          className="field text-sm col-span-2"
        />
      </div>

      {error && <p className="text-xs mb-2" style={{ color: 'var(--color-alert)' }}>{error}</p>}

      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={saving}>{saving ? 'Saving…' : 'Save'}</Button>
        <Button type="button" variant="ghost" size="sm" onClick={onCancel}>Cancel</Button>
      </div>
    </form>
  );
}

export { ASSET_CATEGORIES, LIABILITY_CATEGORIES };
