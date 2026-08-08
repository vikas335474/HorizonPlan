// Client portfolio ledger UI. ClientPortfolioCard({clientId, readOnly}) renders
// the balance sheet — liquid/locked buckets, net worth, NAV freshness — with CAS
// CSV import and cached-price refresh. Data: api.getClientPortfolio plus
// create / update / delete / import / refresh. readOnly=true hides every edit
// affordance (the client self-service view).

import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import { Card, Badge, Button, Spinner } from './ui';
import { formatCurrency } from '../lib/format';
import { parseCsv, parseAmount } from '../lib/csv';

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

// Same shape as PlanReviewUI.jsx's/ChangeLogUI.jsx's own local formatTimestamp
// — small enough that this codebase's own precedent is a per-file copy, not a
// shared helper.
function formatTimestamp(ts) {
  if (!ts) return '';
  try {
    return new Date(ts.replace(' ', 'T') + 'Z').toLocaleString(undefined, {
      dateStyle: 'medium',
      timeStyle: 'short',
    });
  } catch {
    return ts;
  }
}

// readOnly: the client's own view of their own portfolio (GoalsList.jsx) —
// client_portfolio_list.php already permits a 'client' session to read their
// own rows (forced to their own id server-side, clientId is only meaningful
// for an advisor caller), but every mutation endpoint this card otherwise
// wires up (create/update/delete/import) is verifyAccess($db, 'advisor')-only
// — so a client session must never see those actions, not just have them
// fail if clicked.
export function ClientPortfolioCard({ clientId, readOnly = false }) {
  const { tenant } = useAuth();
  // Self-serve individual tier: a user with no adviser must not be told an
  // adviser recorded or will capture anything. Copy only.
  const isSelfDirected = tenant?.kind === 'personal';
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [adding, setAdding] = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [importing, setImporting] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [refreshMsg, setRefreshMsg] = useState('');

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

  // Recomputes NAV-tracked holdings from whatever the daily cron already
  // cached — no live AMFI call happens from this click, per the user's own
  // "using the cached data locally" instruction.
  async function handleRefresh() {
    setRefreshing(true);
    setRefreshMsg('');
    setError('');
    try {
      const res = await api.refreshClientPortfolio(clientId);
      setRefreshMsg(
        res.updated_count > 0
          ? `Updated ${res.updated_count} holding${res.updated_count === 1 ? '' : 's'} from cached prices.`
          : 'No holdings needed updating.'
      );
      load();
    } catch (err) {
      setError(err.message || 'Could not refresh prices.');
    } finally {
      setRefreshing(false);
    }
  }

  const items = data?.items ?? [];
  const totals = data?.totals;
  const navTrackedCount = items.filter((it) => it.amfi_scheme_code).length;

  return (
    <Card className="p-4 mb-4">
      <div className="flex items-center justify-between gap-3 mb-1">
        <h2 className="text-base font-semibold text-[var(--color-ink)]">Portfolio &amp; net worth</h2>
        {!readOnly && !adding && !importing && (
          <div className="flex gap-2">
            {navTrackedCount > 0 && (
              <Button variant="ghost" size="sm" disabled={refreshing} onClick={handleRefresh}>
                {refreshing ? 'Refreshing…' : 'Refresh prices'}
              </Button>
            )}
            <Button variant="ghost" size="sm" onClick={() => setImporting(true)}>Import CAS/MFCentral CSV</Button>
            <Button variant="outline" size="sm" onClick={() => setAdding(true)}>+ Add item</Button>
          </div>
        )}
      </div>
      <p className="text-xs text-[var(--color-ink-2)] mb-1">
        {/* isSelfDirected is tested FIRST, and the order is load-bearing. This
            used to branch on readOnly first, which was correct only while a
            self-serve individual's portfolio was read-only. It no longer is —
            they have no adviser to enter it for them — so a readOnly-first
            ternary dropped them into the advisor branch and told a person
            looking at their own assets "what THE CLIENT already owns". */}
        {isSelfDirected
          ? 'What you already own — a starting point, not a shared pool any one goal automatically draws from.'
          : readOnly
            ? 'What you already own, as recorded by your advisor — a starting point, not a shared pool any one goal automatically draws from.'
            : 'What the client already owns — a starting point, not a shared pool any one goal automatically draws from.'}
      </p>

      {/* Freshness is always shown once there's at least one NAV-tracked
          holding — never hidden just because it happens to be stale. */}
      {navTrackedCount > 0 && (
        <p className="text-[11px] text-[var(--color-ink-3)] mb-2">
          MF prices {data?.portfolio_nav_freshness
            ? <>as of <span className="font-medium">{formatTimestamp(data.portfolio_nav_freshness)}</span></>
            : 'not yet synced — price pending on the next daily update'}
          .
        </p>
      )}
      {refreshMsg && <p className="text-[11px] mb-2" style={{ color: 'var(--color-teal-ink)' }}>{refreshMsg}</p>}

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
            !readOnly && editingId === item.id ? (
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
                  {item.amfi_scheme_code && (
                    <div className="text-[11px] text-[var(--color-ink-3)]">
                      {item.units_held} units · scheme {item.amfi_scheme_code}
                      {item.nav_value != null
                        ? ` · NAV ₹${item.nav_value} (${formatTimestamp(item.nav_fetched_at)})`
                        : ' · price pending'}
                    </div>
                  )}
                </div>
                <span className="tnum text-sm text-[var(--color-ink)]">{formatCurrency(item.value)}</span>
                {!readOnly && (
                  <div className="flex gap-1 shrink-0">
                    <button type="button" onClick={() => setEditingId(item.id)} className="text-xs text-[var(--color-ink-2)] hover:text-[var(--color-ink)] hover:underline">Edit</button>
                    <button type="button" onClick={() => handleDelete(item.id)} className="text-xs text-[var(--color-ink-3)] hover:text-[var(--color-alert)] hover:underline">Remove</button>
                  </div>
                )}
              </div>
            )
          )}
        </div>
      )}

      {!readOnly && adding && (
        <PortfolioItemForm
          clientId={clientId}
          onSaved={() => { setAdding(false); load(); }}
          onCancel={() => setAdding(false)}
        />
      )}

      {!readOnly && importing && (
        <ImportCasCsv
          clientId={clientId}
          onImported={() => { setImporting(false); load(); }}
          onCancel={() => setImporting(false)}
        />
      )}
    </Card>
  );
}

// Bulk-import a client's mutual fund holdings from a CAMS/KFintech/MFCentral
// Consolidated Account Statement (CAS) CSV export. No direct API/Account
// Aggregator integration here (that's a separately-scoped, much bigger piece
// of work — a real external-credential/consent-flow security surface) —
// this is the CSV-mapping path: the advisor exports/downloads their CAS as
// CSV, uploads it here, and tells the app which column is which. CAMS/
// KFintech/MFCentral don't share one guaranteed export layout (and often
// carry a few preamble lines — statement title, generation date, PAN — above
// the real header row), so nothing about the column order or header row
// position is assumed; the advisor confirms both before anything imports.
function ImportCasCsv({ clientId, onImported, onCancel }) {
  const [step, setStep] = useState('upload'); // 'upload' | 'configure' | 'preview'
  const [rawRows, setRawRows] = useState([]);
  const [headerRowNum, setHeaderRowNum] = useState(1); // 1-based, advisor-facing
  const [schemeCol, setSchemeCol] = useState('');
  const [valueCol, setValueCol] = useState('');
  const [folioCol, setFolioCol] = useState('');
  const [error, setError] = useState('');
  const [importing, setImportingState] = useState(false);

  function loadText(text) {
    setError('');
    const rows = parseCsv(text).filter((r) => r.some((cell) => cell.trim() !== ''));
    if (rows.length < 2) {
      setError('Could not find at least a header row and one data row in this file.');
      return;
    }
    setRawRows(rows);
    setHeaderRowNum(1);
    setSchemeCol('');
    setValueCol('');
    setFolioCol('');
    setStep('configure');
  }

  function handleFile(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => loadText(String(reader.result || ''));
    reader.onerror = () => setError('Could not read that file.');
    reader.readAsText(file);
  }

  const headerRowIndex = headerRowNum - 1;
  const headerRow = rawRows[headerRowIndex] || [];
  const dataRows = rawRows.slice(headerRowIndex + 1);

  function columnLabel(idx) {
    const cell = (headerRow[idx] || '').trim();
    return cell !== '' ? cell : `Column ${idx + 1}`;
  }

  // Normalizes + validates every data row against the chosen column mapping.
  // Rows missing a scheme name or a parseable value are skipped, not
  // rejected outright — a CAS export commonly has trailing summary/blank
  // rows after the real holdings.
  function buildImportRows() {
    const schemeIdx = Number(schemeCol);
    const valueIdx = Number(valueCol);
    const folioIdx = folioCol !== '' ? Number(folioCol) : null;
    const items = [];
    let skipped = 0;
    for (const row of dataRows) {
      const scheme = (row[schemeIdx] || '').trim();
      const amount = parseAmount(row[valueIdx]);
      if (scheme === '' || amount === null) { skipped++; continue; }
      const folio = folioIdx !== null ? (row[folioIdx] || '').trim() : '';
      items.push({
        description: folio ? `${scheme} (Folio ${folio})` : scheme,
        value: amount,
      });
    }
    return { items, skipped };
  }

  const [preview, setPreview] = useState(null);

  function handleContinueToPreview() {
    if (schemeCol === '' || valueCol === '') {
      setError('Choose both the scheme/fund name column and the value column.');
      return;
    }
    setError('');
    setPreview(buildImportRows());
    setStep('preview');
  }

  async function handleConfirmImport() {
    if (!preview || preview.items.length === 0) return;
    setImportingState(true);
    setError('');
    try {
      const res = await api.importPortfolioItems(clientId, preview.items);
      onImported(res);
    } catch (err) {
      setError(err.message || 'Import failed.');
    } finally {
      setImportingState(false);
    }
  }

  return (
    <div className="rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] p-3 mb-2">
      <p className="text-xs text-[var(--color-ink-2)] mb-2">
        Import mutual fund holdings from a CAMS, KFintech, or MFCentral Consolidated Account
        Statement (CAS) — export it as CSV first, then upload it here. Every imported holding
        lands as a liquid mutual fund asset, same as adding one by hand.
      </p>

      {step === 'upload' && (
        <div>
          <input
            type="file"
            accept=".csv,text/csv"
            onChange={handleFile}
            className="text-xs"
          />
          <p className="text-[11px] text-[var(--color-ink-3)] mt-2">
            Don't have a file handy? Paste the CSV text instead:
          </p>
          <PasteCsvBox onLoad={loadText} />
        </div>
      )}

      {step === 'configure' && (
        <div>
          <p className="text-xs font-medium text-[var(--color-ink)] mb-1.5">
            Which row has the column headers?
          </p>
          <input
            type="number" min="1" max={rawRows.length} value={headerRowNum}
            aria-label="Header row number"
            onChange={(e) => setHeaderRowNum(Math.max(1, Math.min(rawRows.length, Number(e.target.value) || 1)))}
            className="field text-sm mb-2"
            style={{ maxWidth: '6rem' }}
          />
          <div className="overflow-x-auto mb-3 rounded-[var(--radius-ctrl)] border border-[var(--color-line)]">
            <table className="text-[11px] w-full">
              <tbody>
                {rawRows.slice(0, 8).map((row, i) => (
                  <tr
                    key={i}
                    style={i === headerRowIndex ? { backgroundColor: 'var(--color-teal-soft)' } : undefined}
                  >
                    <td className="px-2 py-1 text-[var(--color-ink-3)] whitespace-nowrap">{i + 1}</td>
                    {row.slice(0, 6).map((cell, j) => (
                      <td key={j} className="px-2 py-1 whitespace-nowrap text-[var(--color-ink)]">{cell || '—'}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-2 mb-2">
            <div>
              <label className="block text-[11px] font-medium text-[var(--color-ink-2)] mb-1">Scheme / fund name column</label>
              <select value={schemeCol} onChange={(e) => setSchemeCol(e.target.value)} className="field text-sm">
                <option value="">Choose…</option>
                {headerRow.map((_, idx) => <option key={idx} value={idx}>{columnLabel(idx)}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-[11px] font-medium text-[var(--color-ink-2)] mb-1">Current value column</label>
              <select value={valueCol} onChange={(e) => setValueCol(e.target.value)} className="field text-sm">
                <option value="">Choose…</option>
                {headerRow.map((_, idx) => <option key={idx} value={idx}>{columnLabel(idx)}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-[11px] font-medium text-[var(--color-ink-2)] mb-1">Folio number (optional)</label>
              <select value={folioCol} onChange={(e) => setFolioCol(e.target.value)} className="field text-sm">
                <option value="">Not included</option>
                {headerRow.map((_, idx) => <option key={idx} value={idx}>{columnLabel(idx)}</option>)}
              </select>
            </div>
          </div>

          {error && <p className="text-xs mb-2" style={{ color: 'var(--color-alert)' }}>{error}</p>}

          <div className="flex gap-2">
            <Button size="sm" onClick={handleContinueToPreview}>Preview import</Button>
            <Button variant="ghost" size="sm" onClick={onCancel}>Cancel</Button>
          </div>
        </div>
      )}

      {step === 'preview' && preview && (
        <div>
          <p className="text-xs text-[var(--color-ink)] mb-2">
            Ready to import <strong>{preview.items.length}</strong> holding{preview.items.length === 1 ? '' : 's'} totaling{' '}
            <strong>{formatCurrency(preview.items.reduce((sum, it) => sum + it.value, 0))}</strong> as liquid mutual fund assets.
            {preview.skipped > 0 && ` (${preview.skipped} row${preview.skipped === 1 ? '' : 's'} skipped — missing a name or a readable value.)`}
          </p>
          <div className="max-h-48 overflow-y-auto mb-3 rounded-[var(--radius-ctrl)] border border-[var(--color-line)]">
            <table className="text-[11px] w-full">
              <tbody>
                {preview.items.slice(0, 15).map((it, i) => (
                  <tr key={i} className="border-b border-[var(--color-line)] last:border-b-0">
                    <td className="px-2 py-1 text-[var(--color-ink)]">{it.description}</td>
                    <td className="px-2 py-1 text-right tnum text-[var(--color-ink)]">{formatCurrency(it.value)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {preview.items.length > 15 && (
              <p className="text-[11px] text-[var(--color-ink-3)] px-2 py-1">…and {preview.items.length - 15} more</p>
            )}
          </div>

          {error && <p className="text-xs mb-2" style={{ color: 'var(--color-alert)' }}>{error}</p>}

          <div className="flex gap-2">
            <Button size="sm" disabled={importing || preview.items.length === 0} onClick={handleConfirmImport}>
              {importing ? 'Importing…' : `Import ${preview.items.length} holding${preview.items.length === 1 ? '' : 's'}`}
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setStep('configure')}>Back</Button>
            <Button variant="ghost" size="sm" onClick={onCancel}>Cancel</Button>
          </div>
        </div>
      )}
    </div>
  );
}

function PasteCsvBox({ onLoad }) {
  const [text, setText] = useState('');
  return (
    <div className="mt-1.5">
      <textarea
        value={text}
        onChange={(e) => setText(e.target.value)}
        placeholder="Paste CSV content here…"
        rows={3}
        className="field text-xs w-full"
      />
      <Button
        type="button" variant="outline" size="sm" className="mt-1.5"
        disabled={text.trim() === ''}
        onClick={() => onLoad(text)}
      >
        Use pasted text
      </Button>
    </div>
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
  const [schemeCode, setSchemeCode] = useState(existing?.amfi_scheme_code || '');
  const [unitsHeld, setUnitsHeld] = useState(existing?.units_held != null ? String(existing.units_held) : '');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const categories = itemKind === 'liability' ? LIABILITY_CATEGORIES : ASSET_CATEGORIES;
  // NAV tracking (docs "session 2" MF NAV price-sync) only makes sense for a
  // mutual fund asset — its value then comes from units × the synced NAV,
  // not a number the advisor keeps typing in by hand.
  const navEligible = itemKind === 'asset' && category === 'mutual_fund';
  const navTracked = navEligible && schemeCode.trim() !== '' && unitsHeld.trim() !== '';

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

    if (navEligible && (schemeCode.trim() !== '') !== (unitsHeld.trim() !== '')) {
      setError('Enter both an AMFI scheme code and units held, or leave both blank.');
      return;
    }
    const unitsNumeric = unitsHeld.trim() !== '' ? Number(unitsHeld) : null;
    if (navTracked && (!Number.isFinite(unitsNumeric) || unitsNumeric <= 0)) {
      setError('Units held must be a positive number.');
      return;
    }

    let numericValue = null;
    if (!navTracked) {
      numericValue = Number(value);
      if (!Number.isFinite(numericValue) || numericValue < 0) {
        setError('Enter a valid amount (0 or more).');
        return;
      }
    }

    setSaving(true);
    try {
      const payload = {
        bucket: itemKind === 'asset' ? bucket : undefined,
        category,
        description: description || null,
        // When NAV-tracked, omit `value` entirely (undefined keys are
        // dropped by JSON.stringify) so the backend computes it from
        // units × the cached NAV instead of us sending a stale/empty number.
        value: navTracked ? undefined : numericValue,
        amfi_scheme_code: navTracked ? schemeCode.trim() : null,
        units_held: navTracked ? unitsNumeric : null,
      };
      if (existing) {
        await api.updatePortfolioItem(existing.id, payload);
      } else {
        await api.createPortfolioItem({ client_id: clientId, item_kind: itemKind, ...payload });
      }
      onSaved();
    } catch (err) {
      setError(err.message || 'Could not save this item.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] p-3 mb-2">
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

        {navEligible && (
          <>
            <input
              type="text" value={schemeCode}
              onChange={(e) => setSchemeCode(e.target.value)}
              placeholder="AMFI scheme code (optional)"
              className="field text-sm"
            />
            <input
              type="number" min="0" step="any" value={unitsHeld}
              onChange={(e) => setUnitsHeld(e.target.value)}
              placeholder="Units held"
              className="field text-sm"
            />
          </>
        )}

        {navTracked ? (
          <div className="col-span-2 text-[11px] text-[var(--color-ink-3)] px-1 py-1.5">
            Value is computed from units × the synced NAV — no manual entry needed. It'll show
            "price pending" until the daily sync (or a Refresh) first prices this scheme.
          </div>
        ) : (
          <input
            type="number" min="0" step="any" value={value}
            onChange={(e) => setValue(e.target.value)}
            placeholder="Value (₹)"
            className={`field text-sm ${itemKind === 'liability' ? 'col-span-2' : ''}`}
          />
        )}
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
