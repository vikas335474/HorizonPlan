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

// docs/12 Prompt D-1: the full instrument set, GROUPED so a user actually
// discovers "yes, my PF and my flat belong here" instead of hunting a flat
// dropdown (the discoverability half of the D-1 fix — every category below
// except bonds/reits/cash already existed server-side; `category` is a free
// VARCHAR, so adding these needed no migration, just surfacing them).
// bonds/reits/cash are new: bonds and REITs are market-linked and reachable
// within a normal liquidity horizon (not restricted-access like EPF/NPS/PPF),
// so both default liquid; `cash` is physical/at-hand cash or a wallet
// balance, distinct from `savings` (a bank account).
const ASSET_CATEGORY_GROUPS = [
  {
    group: 'Market-linked',
    items: [
      { value: 'mutual_fund', label: 'Mutual fund', bucket: 'liquid' },
      { value: 'stocks', label: 'Stocks', bucket: 'liquid' },
      { value: 'bonds', label: 'Bonds', bucket: 'liquid' },
      { value: 'reits', label: 'REITs / InvITs', bucket: 'liquid' },
      { value: 'gold', label: 'Gold', bucket: 'liquid' },
    ],
  },
  {
    group: 'Bank & cash',
    items: [
      { value: 'savings', label: 'Savings account', bucket: 'liquid' },
      { value: 'fd', label: 'Fixed deposit', bucket: 'liquid' },
      { value: 'cash', label: 'Cash', bucket: 'liquid' },
    ],
  },
  {
    group: 'Retirement & long-lock',
    items: [
      { value: 'ppf', label: 'PPF', bucket: 'locked' },
      { value: 'epf', label: 'EPF', bucket: 'locked' },
      { value: 'nps', label: 'NPS', bucket: 'locked' },
    ],
  },
  {
    group: 'Property',
    items: [
      { value: 'real_estate', label: 'Real estate (land, flat, other property)', bucket: 'locked' },
    ],
  },
  {
    group: 'Other',
    items: [
      { value: 'other_asset', label: 'Other', bucket: 'liquid' },
    ],
  },
];
// Flattened — every other place in this file (labels, bucket lookup, the
// PortfolioItemForm's flat state) works off the single list, same as before
// this grouping was added; only the <select> rendering itself uses the
// grouped shape above.
const ASSET_CATEGORIES = ASSET_CATEGORY_GROUPS.flatMap((g) => g.items);
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
  // docs/12 Prompt D-2 — which item's tax-context panel is expanded, at
  // most one at a time (per-row toggle, same collapsed-by-default posture
  // as the CAS export guide).
  const [expandedTaxId, setExpandedTaxId] = useState(null);

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
              <div key={item.id} className="rounded-[var(--radius-ctrl)] border border-[var(--color-line)] px-3 py-2">
                <div className="flex items-center justify-between gap-3">
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
                      {/* docs/12 D-1: which rows a CAS re-import is even allowed to
                          touch — surfaced so it's never a mystery why one holding
                          moved on its own after an import and another didn't. */}
                      {item.source === 'cas_import' && (
                        <span className="text-[10px] text-[var(--color-ink-3)]" title="Last set by a CAS/MFCentral import — a re-import can update this">
                          from CAS import
                        </span>
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

                {/* docs/12 Prompt D-2 — facts only, collapsed by default so it
                    doesn't crowd the roster. Only offered when this category
                    is actually covered by the tax-reference cache. */}
                {item.tax_context?.applicable && (
                  <div className="mt-1.5 pt-1.5 border-t border-[var(--color-line)]">
                    <button
                      type="button"
                      onClick={() => setExpandedTaxId((cur) => (cur === item.id ? null : item.id))}
                      className="text-[11px] text-[var(--color-teal-ink)] hover:underline"
                    >
                      {expandedTaxId === item.id ? 'Hide tax context' : 'Tax context'}
                    </button>
                    {expandedTaxId === item.id && <PortfolioTaxContextPanel context={item.tax_context} />}
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

// docs/12 Prompt D-1: concrete, per-source steps to actually get a CAS —
// this used to be a single unlabelled sentence ("export it as CSV first")
// with no help getting there. Collapsed by default so it doesn't crowd the
// upload step for someone who already knows the drill.
const CAS_EXPORT_GUIDES = [
  {
    key: 'cams',
    label: 'CAMS (camsonline.com)',
    steps: [
      'Go to mycams.camsonline.com → "Statements" → "Consolidated Account Statement (CAS)".',
      'Choose your date range (or "Detailed" for a full holdings statement) and enter your PAN / registered email.',
      'CAMS emails you a password-protected PDF by default — look instead for the "Export"/download option that offers CSV or Excel; if only a PDF arrives, most spreadsheet apps can open the PDF\'s table and you can Save As CSV from there.',
    ],
  },
  {
    key: 'kfintech',
    label: 'KFintech (mfs.kfintech.com)',
    steps: [
      'Go to mfs.kfintech.com → "Statements" → "Consolidated Account Statement".',
      'Select the date range and submit your PAN.',
      'Same as CAMS: download the statement, and use the CSV/Excel export if offered, or open the PDF in a spreadsheet app and save as CSV.',
    ],
  },
  {
    key: 'mfcentral',
    label: 'MFCentral (mfcentral.com)',
    steps: [
      'Sign in at mfcentral.com (covers holdings across both CAMS and KFintech-serviced funds in one place).',
      'Go to "Consolidated Account Statement" or "Portfolio" → "Download".',
      'Choose CSV/Excel if offered; otherwise export/print to a spreadsheet the same way as above.',
    ],
  },
];

function CasExportGuide() {
  const [open, setOpen] = useState(false);
  const [tab, setTab] = useState(CAS_EXPORT_GUIDES[0].key);
  const active = CAS_EXPORT_GUIDES.find((g) => g.key === tab);
  return (
    <div className="mt-2">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="text-[11px] font-medium text-[var(--color-teal-ink)] hover:underline"
      >
        {open ? 'Hide' : "Don't have your CAS yet? "}{open ? '' : 'See how to download one →'}
      </button>
      {open && (
        <div className="mt-2 rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] p-2.5">
          <div className="flex gap-1 mb-2 flex-wrap">
            {CAS_EXPORT_GUIDES.map((g) => (
              <button
                key={g.key}
                type="button"
                onClick={() => setTab(g.key)}
                className="text-[11px] px-2 py-1 rounded-[var(--radius-ctrl)] border"
                style={tab === g.key
                  ? { borderColor: 'var(--color-teal)', backgroundColor: 'var(--color-teal-soft)', color: 'var(--color-teal-ink)' }
                  : { borderColor: 'var(--color-line-2)', color: 'var(--color-ink-2)' }}
              >
                {g.label}
              </button>
            ))}
          </div>
          <ol className="list-decimal list-inside space-y-1 text-[11px] text-[var(--color-ink-2)]">
            {active.steps.map((s, i) => <li key={i}>{s}</li>)}
          </ol>
        </div>
      )}
    </div>
  );
}

// Reconcile-by-holding CAS/KFintech/MFCentral CSV import (docs/12 Prompt
// D-1). No direct API/Account Aggregator integration here (that's a
// separately-scoped, much bigger piece of work — a real external-credential/
// consent-flow security surface) — this is the CSV-mapping path: export your
// CAS as CSV, upload it here, tell the app which column is which. CAMS/
// KFintech/MFCentral don't share one guaranteed export layout (and often
// carry a few preamble lines — statement title, generation date, PAN — above
// the real header row), so nothing about the column order or header row
// position is assumed; you confirm both before anything is even previewed.
//
// A RE-IMPORT NO LONGER DUPLICATES. The old version of this component always
// inserted every row on every upload. Now, after you map the columns, the
// server computes an actual diff against holdings from your OWN prior CAS
// imports (never touching anything you entered by hand) — update / add /
// "in your portfolio but missing from this statement" — and you see and
// confirm exactly that before a single row changes.
function ImportCasCsv({ clientId, onImported, onCancel }) {
  const [step, setStep] = useState('upload'); // 'upload' | 'configure' | 'preview'
  const [rawRows, setRawRows] = useState([]);
  const [headerRowNum, setHeaderRowNum] = useState(1); // 1-based, user-facing
  const [schemeCol, setSchemeCol] = useState('');
  const [valueCol, setValueCol] = useState('');
  const [folioCol, setFolioCol] = useState('');
  const [error, setError] = useState('');
  const [importing, setImportingState] = useState(false);
  const [loadingPreview, setLoadingPreview] = useState(false);
  const [diff, setDiff] = useState(null); // {to_update, to_add, to_flag, unchanged_count}
  const [items, setItems] = useState([]); // the raw parsed rows the diff was computed from
  const [skipped, setSkipped] = useState(0);
  const [selectedRemovals, setSelectedRemovals] = useState(new Set());

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
  // rows after the real holdings. folio_number rides along as its own field
  // now (not baked into the description text) — it's the reconciliation
  // match key, see api/lib/PortfolioReconcile.php.
  function buildImportRows() {
    const schemeIdx = Number(schemeCol);
    const valueIdx = Number(valueCol);
    const folioIdx = folioCol !== '' ? Number(folioCol) : null;
    const built = [];
    let skippedCount = 0;
    for (const row of dataRows) {
      const scheme = (row[schemeIdx] || '').trim();
      const amount = parseAmount(row[valueIdx]);
      if (scheme === '' || amount === null) { skippedCount++; continue; }
      const folio = folioIdx !== null ? (row[folioIdx] || '').trim() : '';
      built.push({ description: scheme, value: amount, folio_number: folio || null });
    }
    return { items: built, skipped: skippedCount };
  }

  async function handleContinueToPreview() {
    if (schemeCol === '' || valueCol === '') {
      setError('Choose both the scheme/fund name column and the value column.');
      return;
    }
    setError('');
    const built = buildImportRows();
    if (built.items.length === 0) {
      setError('No usable rows found — check the column choices above.');
      return;
    }
    setItems(built.items);
    setSkipped(built.skipped);
    setSelectedRemovals(new Set());
    setLoadingPreview(true);
    try {
      const res = await api.previewPortfolioReconcile(clientId, built.items);
      setDiff(res.diff);
      setStep('preview');
    } catch (err) {
      setError(err.message || 'Could not compute the import preview.');
    } finally {
      setLoadingPreview(false);
    }
  }

  function toggleRemoval(id) {
    setSelectedRemovals((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }

  async function handleConfirmImport() {
    if (!diff) return;
    setImportingState(true);
    setError('');
    try {
      const res = await api.importPortfolioItems(clientId, items, Array.from(selectedRemovals));
      onImported(res);
    } catch (err) {
      setError(err.message || 'Import failed.');
    } finally {
      setImportingState(false);
    }
  }

  const totalChanges = diff ? diff.to_update.length + diff.to_add.length : 0;

  return (
    <div className="rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] p-3 mb-2">
      <p className="text-xs text-[var(--color-ink-2)] mb-1">
        Import mutual fund holdings from a CAMS, KFintech, or MFCentral Consolidated Account
        Statement (CAS) — export it as CSV first, then upload it here.
      </p>
      <p className="text-xs text-[var(--color-ink-2)] mb-2">
        <strong className="text-[var(--color-ink)]">What this does:</strong> we match each fund to
        anything from a <em>previous</em> CAS import (by folio number where your statement has one),
        update the value on a match, add anything new, and show you anything in your portfolio that
        this statement no longer lists — you decide whether to remove those, nothing is deleted
        automatically. A holding you typed in by hand is never touched by an import, ever.
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
          <CasExportGuide />
          <div className="mt-2">
            <Button variant="ghost" size="sm" onClick={onCancel}>Cancel</Button>
          </div>
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
              <label className="block text-[11px] font-medium text-[var(--color-ink-2)] mb-1">Folio number</label>
              <select value={folioCol} onChange={(e) => setFolioCol(e.target.value)} className="field text-sm">
                <option value="">Not included</option>
                {headerRow.map((_, idx) => <option key={idx} value={idx}>{columnLabel(idx)}</option>)}
              </select>
              <p className="text-[10px] text-[var(--color-ink-3)] mt-1">
                Strongly recommended — without it, re-importing later can only match funds by name.
              </p>
            </div>
          </div>

          {error && <p className="text-xs mb-2" style={{ color: 'var(--color-alert)' }}>{error}</p>}

          <div className="flex gap-2">
            <Button size="sm" disabled={loadingPreview} onClick={handleContinueToPreview}>
              {loadingPreview ? 'Comparing to your portfolio…' : 'Preview import'}
            </Button>
            <Button variant="ghost" size="sm" onClick={onCancel}>Cancel</Button>
          </div>
        </div>
      )}

      {step === 'preview' && diff && (
        <div>
          <p className="text-xs text-[var(--color-ink)] mb-3">
            <strong>{totalChanges}</strong> change{totalChanges === 1 ? '' : 's'} to apply
            {diff.unchanged_count > 0 && <> · {diff.unchanged_count} already up to date</>}
            {skipped > 0 && <> · {skipped} row{skipped === 1 ? '' : 's'} skipped (missing a name or a readable value)</>}.
          </p>

          {diff.to_update.length > 0 && (
            <ReconcileSection title={`Will update (${diff.to_update.length})`} tone="teal">
              {diff.to_update.map((u, i) => (
                <div key={i} className="flex items-center justify-between gap-2 px-2 py-1 text-[11px]">
                  <span className="text-[var(--color-ink)] truncate">
                    {u.new_description}
                    {u.match_type === 'name' && (
                      <span className="ml-1.5 text-[var(--color-amber)]" title="No folio number — matched by fund name, please double-check this is the right holding">
                        (matched by name — verify)
                      </span>
                    )}
                  </span>
                  <span className="tnum whitespace-nowrap text-[var(--color-ink-2)]">
                    {formatCurrency(u.old_value)} → <strong className="text-[var(--color-ink)]">{formatCurrency(u.new_value)}</strong>
                  </span>
                </div>
              ))}
            </ReconcileSection>
          )}

          {diff.to_add.length > 0 && (
            <ReconcileSection title={`Will add as new (${diff.to_add.length})`} tone="teal">
              {diff.to_add.map((a, i) => (
                <div key={i} className="flex items-center justify-between gap-2 px-2 py-1 text-[11px]">
                  <span className="text-[var(--color-ink)] truncate">{a.description}</span>
                  <span className="tnum whitespace-nowrap text-[var(--color-ink)]">{formatCurrency(a.value)}</span>
                </div>
              ))}
            </ReconcileSection>
          )}

          {diff.to_flag.length > 0 && (
            <ReconcileSection
              title={`In your portfolio, but not in this statement (${diff.to_flag.length})`}
              tone="amber"
              subtitle="Nothing is removed unless you tick it below — maybe you switched folios, or this fund has since been redeemed."
            >
              {diff.to_flag.map((f) => (
                <label key={f.id} className="flex items-center justify-between gap-2 px-2 py-1 text-[11px] cursor-pointer">
                  <span className="flex items-center gap-2 min-w-0">
                    <input
                      type="checkbox"
                      checked={selectedRemovals.has(f.id)}
                      onChange={() => toggleRemoval(f.id)}
                    />
                    <span className="text-[var(--color-ink)] truncate">{f.description}</span>
                  </span>
                  <span className="tnum whitespace-nowrap text-[var(--color-ink-2)]">{formatCurrency(f.value)}</span>
                </label>
              ))}
              {selectedRemovals.size > 0 && (
                <p className="px-2 pt-1 text-[11px] font-medium" style={{ color: 'var(--color-alert)' }}>
                  {selectedRemovals.size} selected for removal.
                </p>
              )}
            </ReconcileSection>
          )}

          {totalChanges === 0 && diff.to_flag.length === 0 && (
            <p className="text-xs text-[var(--color-ink-2)] mb-2">Nothing to change — your portfolio already matches this statement.</p>
          )}

          {error && <p className="text-xs mb-2" style={{ color: 'var(--color-alert)' }}>{error}</p>}

          <div className="flex gap-2 mt-2">
            <Button
              size="sm"
              disabled={importing || (totalChanges === 0 && selectedRemovals.size === 0)}
              onClick={handleConfirmImport}
            >
              {importing ? 'Applying…' : 'Confirm'}
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setStep('configure')}>Back</Button>
            <Button variant="ghost" size="sm" onClick={onCancel}>Cancel</Button>
          </div>
        </div>
      )}
    </div>
  );
}

function ReconcileSection({ title, tone, subtitle, children }) {
  const color = tone === 'amber' ? 'var(--color-amber)' : 'var(--color-teal-ink)';
  return (
    <div className="mb-3">
      <p className="text-[11px] font-semibold mb-0.5" style={{ color }}>{title}</p>
      {subtitle && <p className="text-[10px] text-[var(--color-ink-3)] mb-1">{subtitle}</p>}
      <div className="max-h-40 overflow-y-auto rounded-[var(--radius-ctrl)] border border-[var(--color-line)] divide-y divide-[var(--color-line)]">
        {children}
      </div>
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

// docs/12 Prompt D-2 — renders exactly what api/lib/PortfolioTaxContext.php
// computed: the sourced treatment note(s) for this holding, its
// short/long-term status today, and an illustrative unrealised gain when a
// purchase price was recorded. FACTS ONLY: never a "sell now"/"harvest now"
// line, never a filing figure, never a claim about how much LTCG exemption
// is left this year (this app has no transaction ledger to know that — see
// PortfolioTaxContext.php's header for why that's a deliberate boundary).
function PortfolioTaxContextPanel({ context }) {
  const { needs_fund_type: needsFundType, treatments, months_held: monthsHeld, holding_period_classification: classification, gain } = context;

  return (
    <div className="mt-2 rounded-[var(--radius-ctrl)] bg-[var(--color-surface-2)] p-2.5 text-[11px]">
      <p className="text-[10px] text-[var(--color-ink-3)] mb-2">
        Illustrative, not tax advice — always verify against current rules before filing or acting.
      </p>

      {needsFundType && (
        <p className="mb-2" style={{ color: 'var(--color-amber)' }}>
          Fund type not specified — showing all three possible regimes below. Edit this holding and
          choose equity/debt/hybrid for a precise answer.
        </p>
      )}

      {classification && (
        <p className="mb-2 text-[var(--color-ink)]">
          Held <strong>{monthsHeld}</strong> month{monthsHeld === 1 ? '' : 's'} so far — would count as{' '}
          <strong>{classification === 'long_term' ? 'long-term' : 'short-term'}</strong> if sold/withdrawn today.
        </p>
      )}

      <div className="space-y-2 mb-2">
        {treatments.map((t) => (
          <div key={t.subcategory} className="border-l-2 pl-2" style={{ borderColor: 'var(--color-line-2)' }}>
            {treatments.length > 1 && <p className="font-semibold text-[var(--color-ink)]">{t.label}</p>}
            {t.short_term_note && <p className="text-[var(--color-ink-2)]">{t.short_term_note}</p>}
            {t.long_term_note && <p className="text-[var(--color-ink-2)]">{t.long_term_note}</p>}
            {t.exemption_note && <p className="text-[var(--color-ink-2)]">{t.exemption_note}</p>}
            {t.other_note && <p className="text-[var(--color-ink-2)]">{t.other_note}</p>}
            <p className="text-[10px] text-[var(--color-ink-3)] mt-1">
              {t.source_name} · as of {t.as_of_date}{!t.is_verified && ' · unverified — check against current rules'}
            </p>
          </div>
        ))}
      </div>

      {gain !== null ? (
        <p className="text-[var(--color-ink)]">
          Illustrative unrealised {gain.amount >= 0 ? 'gain' : 'loss'}: <strong>{formatCurrency(Math.abs(gain.amount))}</strong>
          {gain.pct !== null && ` (${gain.amount >= 0 ? '+' : '−'}${Math.abs(gain.pct)}%)`} — based on your recorded purchase
          price, not a realised or filing figure.
        </p>
      ) : (
        <p className="text-[var(--color-ink-3)]">Add a purchase price to this holding to see an illustrative gain.</p>
      )}
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
  // docs/12 Prompt D-2 (tax context, facts only). fundType only matters for
  // category=mutual_fund (equity vs. debt is a completely different tax
  // regime); acquisitionValue/Date are independent of each other and of
  // everything else — a person may know one without the other.
  const [fundType, setFundType] = useState(existing?.fund_type || '');
  const [acquisitionValue, setAcquisitionValue] = useState(existing?.acquisition_value != null ? String(existing.acquisition_value) : '');
  const [acquisitionDate, setAcquisitionDate] = useState(existing?.acquisition_date || '');
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

    if (acquisitionValue.trim() !== '') {
      const acqNum = Number(acquisitionValue);
      if (!Number.isFinite(acqNum) || acqNum < 0) {
        setError('Purchase price must be a valid amount (0 or more), or left blank.');
        return;
      }
    }
    if (acquisitionDate && acquisitionDate > new Date().toISOString().slice(0, 10)) {
      setError('Purchase date cannot be in the future.');
      return;
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
        // docs/12 Prompt D-2 — each sent only when this row can carry it;
        // the backend independently re-validates and nulls out anything
        // that doesn't apply (a liability, or fund_type on a non-mutual-fund).
        fund_type: itemKind === 'asset' && category === 'mutual_fund' ? (fundType || null) : undefined,
        acquisition_value: itemKind === 'asset' ? (acquisitionValue.trim() !== '' ? Number(acquisitionValue) : null) : undefined,
        acquisition_date: itemKind === 'asset' ? (acquisitionDate || null) : undefined,
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
          {itemKind === 'liability'
            ? categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)
            : ASSET_CATEGORY_GROUPS.map((g) => (
                <optgroup key={g.group} label={g.group}>
                  {g.items.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                </optgroup>
              ))}
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

        {/* docs/12 Prompt D-2 — equity vs. debt is a completely different tax
            regime; never guessed at, so this is its own explicit choice,
            with a real "not specified" state. */}
        {itemKind === 'asset' && category === 'mutual_fund' && (
          <select value={fundType} onChange={(e) => setFundType(e.target.value)} className="field text-sm col-span-2">
            <option value="">Fund type not specified (shows all tax regimes)</option>
            <option value="equity">Equity fund</option>
            <option value="debt">Debt fund</option>
            <option value="hybrid">Hybrid fund</option>
          </select>
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

        {/* docs/12 Prompt D-2 — optional, independent of each other. Neither
            is required to save the item; both stay unset until supplied. */}
        {itemKind === 'asset' && (
          <>
            <input
              type="number" min="0" step="any" value={acquisitionValue}
              onChange={(e) => setAcquisitionValue(e.target.value)}
              placeholder="Purchase price (₹, optional)"
              className="field text-sm"
            />
            <input
              type="date" value={acquisitionDate}
              onChange={(e) => setAcquisitionDate(e.target.value)}
              aria-label="Purchase date (optional)"
              className="field text-sm"
              max={new Date().toISOString().slice(0, 10)}
            />
          </>
        )}
      </div>
      {itemKind === 'asset' && (
        <p className="text-[10px] text-[var(--color-ink-3)] mb-2 -mt-1">
          Purchase price and date are optional — add either or both to see an illustrative gain and
          holding-period fact next to this holding. Never required, never assumed.
        </p>
      )}

      {error && <p className="text-xs mb-2" style={{ color: 'var(--color-alert)' }}>{error}</p>}

      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={saving}>{saving ? 'Saving…' : 'Save'}</Button>
        <Button type="button" variant="ghost" size="sm" onClick={onCancel}>Cancel</Button>
      </div>
    </form>
  );
}

export { ASSET_CATEGORIES, LIABILITY_CATEGORIES };
