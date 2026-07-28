import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { Card, Badge, Button, Spinner } from './ui';
import { formatCurrency } from '../lib/format';

// Cash-flow module (docs/10): a client's income (inflows) + expenses (outflows)
// + monthly surplus in one place — the gap HorizonPlan had between per-goal SIP
// modelling and implicit withdrawal-rate spending. Advisors add/edit line
// items; the card sums everything to a like-for-like monthly figure and, for
// the advisor, measures the surplus against the plan's total monthly SIPs
// ("is the plan actually fundable from cash flow?").
//
// The firm/advisor supplies every number — the app never fabricates income or
// expenses. Default state is empty ("nothing recorded yet").

const INCOME_CATEGORIES = [
  { value: 'salary', label: 'Salary' },
  { value: 'business', label: 'Business / professional' },
  { value: 'rental', label: 'Rental' },
  { value: 'investment_income', label: 'Investment income' },
  { value: 'pension', label: 'Pension' },
  { value: 'other_income', label: 'Other income' },
];
const EXPENSE_CATEGORIES = [
  { value: 'housing', label: 'Housing / rent' },
  { value: 'emi', label: 'Loan EMI' },
  { value: 'food', label: 'Food & groceries' },
  { value: 'utilities', label: 'Utilities' },
  { value: 'insurance', label: 'Insurance premium' },
  { value: 'education', label: 'Education / school fees' },
  { value: 'healthcare', label: 'Healthcare' },
  { value: 'lifestyle', label: 'Lifestyle' },
  { value: 'other_expense', label: 'Other expense' },
];

function categoryLabel(kind, category) {
  const list = kind === 'income' ? INCOME_CATEGORIES : EXPENSE_CATEGORIES;
  return list.find((c) => c.value === category)?.label || category;
}

// A monthly-normalised figure for one line, so the client-facing read-only card
// (and the row list) can show what each line actually contributes per month —
// the same annual ÷ 12 the server does in CashFlowSummary.php.
function monthlyOf(item) {
  return item.cadence === 'annual' ? item.amount / 12 : item.amount;
}

// Advisor-facing card on the client page. readOnly=false: full add/edit/delete
// plus the surplus-vs-SIP comparison. The client's own read-only view is
// ClientCashFlowCard below (deliberately without the SIP framing).
export function CashFlowCard({ clientId }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [adding, setAdding] = useState(false);
  const [editingId, setEditingId] = useState(null);

  function load() {
    setLoading(true);
    api
      .getClientCashFlow(clientId)
      .then(setData)
      .catch((err) => setError(err.message || 'Could not load the cash flow.'))
      .finally(() => setLoading(false));
  }

  useEffect(load, [clientId]);

  async function handleDelete(id) {
    if (!window.confirm('Remove this line from the cash flow?')) return;
    try {
      await api.deleteCashFlowItem(id);
      load();
    } catch (err) {
      setError(err.message || 'Could not remove this line.');
    }
  }

  const items = data?.items ?? [];
  const summary = data?.summary;
  const sip = data?.sip_comparison;
  const income = items.filter((it) => it.kind === 'income');
  const expense = items.filter((it) => it.kind === 'expense');

  return (
    <Card className="p-4 mb-4">
      <div className="flex items-center justify-between gap-3 mb-1">
        <h2 className="text-base font-semibold text-[var(--color-ink)]">Cash flow &amp; surplus</h2>
        {!adding && (
          <Button variant="outline" size="sm" onClick={() => setAdding(true)}>+ Add line</Button>
        )}
      </div>
      <p className="text-xs text-[var(--color-ink-2)] mb-3">
        Monthly income minus expenses — the surplus available to fund this client's plan. Enter each
        line at its natural cadence; annual items are spread evenly across the year.
      </p>

      {loading && <Spinner label="Loading…" />}
      {error && <p className="text-xs mb-2" style={{ color: 'var(--color-alert)' }}>{error}</p>}

      {summary && (
        <div className="grid grid-cols-3 gap-3 mb-4">
          <Total label="Monthly income" value={summary.monthly_income} />
          <Total label="Monthly expenses" value={summary.monthly_expense} negative />
          <Total label="Monthly surplus" value={summary.monthly_surplus} highlight={summary.monthly_surplus >= 0} deficit={summary.monthly_surplus < 0} />
        </div>
      )}

      {/* Advisory payoff: does the surplus cover the plan's SIPs? */}
      {sip && summary && (
        <div
          className="rounded-[var(--radius-ctrl)] px-3 py-2.5 mb-4"
          style={{ backgroundColor: sip.funded ? 'var(--color-teal-soft)' : 'var(--color-alert-soft)' }}
        >
          <div className="flex items-center justify-between gap-3 flex-wrap">
            <span className="text-xs" style={{ color: sip.funded ? 'var(--color-teal-ink)' : 'var(--color-alert)' }}>
              {sip.total_monthly_sip === 0 ? (
                <>No monthly SIP is set on this client's goals yet — surplus <strong>{formatCurrency(summary.monthly_surplus)}/mo</strong> is available to allocate.</>
              ) : sip.funded ? (
                <>Surplus <strong>{formatCurrency(summary.monthly_surplus)}/mo</strong> covers the plan's SIPs of <strong>{formatCurrency(sip.total_monthly_sip)}/mo</strong> — <strong>{formatCurrency(sip.gap)}/mo</strong> to spare.</>
              ) : (
                <>Surplus <strong>{formatCurrency(summary.monthly_surplus)}/mo</strong> is short of the plan's SIPs of <strong>{formatCurrency(sip.total_monthly_sip)}/mo</strong> by <strong>{formatCurrency(Math.abs(sip.gap))}/mo</strong>.</>
              )}
            </span>
          </div>
          <p className="text-[11px] mt-1" style={{ color: 'var(--color-ink-3)' }}>
            SIP total is the sum of every goal's current monthly SIP. It doesn't include step-ups or one-off contributions.
          </p>
        </div>
      )}

      {!loading && items.length === 0 && !adding && (
        <p className="text-xs text-[var(--color-ink-2)]">No cash-flow lines recorded yet.</p>
      )}

      {(income.length > 0 || expense.length > 0) && (
        <div className="space-y-3 mb-2">
          {income.length > 0 && (
            <ItemGroup
              title="Income" items={income} editingId={editingId}
              clientId={clientId} onEdit={setEditingId}
              onSaved={() => { setEditingId(null); load(); }} onCancel={() => setEditingId(null)}
              onDelete={handleDelete}
            />
          )}
          {expense.length > 0 && (
            <ItemGroup
              title="Expenses" items={expense} editingId={editingId}
              clientId={clientId} onEdit={setEditingId}
              onSaved={() => { setEditingId(null); load(); }} onCancel={() => setEditingId(null)}
              onDelete={handleDelete}
            />
          )}
        </div>
      )}

      {adding && (
        <CashFlowItemForm
          clientId={clientId}
          onSaved={() => { setAdding(false); load(); }}
          onCancel={() => setAdding(false)}
        />
      )}
    </Card>
  );
}

function ItemGroup({ title, items, editingId, clientId, onEdit, onSaved, onCancel, onDelete }) {
  return (
    <div>
      <div className="text-[11px] uppercase tracking-wider text-[var(--color-ink-3)] mb-1">{title}</div>
      <div className="space-y-1.5">
        {items.map((item) =>
          editingId === item.id ? (
            <CashFlowItemForm
              key={item.id} clientId={clientId} existing={item}
              onSaved={onSaved} onCancel={onCancel}
            />
          ) : (
            <div
              key={item.id}
              className="flex items-center justify-between gap-3 rounded-[var(--radius-ctrl)] border border-[var(--color-line)] px-3 py-2"
              style={item.is_active ? undefined : { opacity: 0.55 }}
            >
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="text-sm font-medium text-[var(--color-ink)]">{item.label}</span>
                  <Badge fg="var(--color-ink-2)" bg="var(--color-surface-2)">{categoryLabel(item.kind, item.category)}</Badge>
                  {item.cadence === 'annual' && (
                    <Badge fg="var(--color-amber)" bg="var(--color-amber-soft)">annual</Badge>
                  )}
                  {!item.is_active && (
                    <Badge fg="var(--color-ink-3)" bg="var(--color-surface-2)">paused</Badge>
                  )}
                </div>
              </div>
              <div className="text-right shrink-0">
                <div className="tnum text-sm text-[var(--color-ink)]">{formatCurrency(monthlyOf(item))}<span className="text-[11px] text-[var(--color-ink-3)]">/mo</span></div>
                {item.cadence === 'annual' && (
                  <div className="text-[11px] text-[var(--color-ink-3)]">{formatCurrency(item.amount)}/yr</div>
                )}
              </div>
              <div className="flex gap-1 shrink-0">
                <button type="button" onClick={() => onEdit(item.id)} className="text-xs text-[var(--color-ink-2)] hover:text-[var(--color-ink)] hover:underline">Edit</button>
                <button type="button" onClick={() => onDelete(item.id)} className="text-xs text-[var(--color-ink-3)] hover:text-[var(--color-alert)] hover:underline">Remove</button>
              </div>
            </div>
          )
        )}
      </div>
    </div>
  );
}

function Total({ label, value, highlight, negative, deficit }) {
  const color = deficit ? 'var(--color-alert)' : highlight ? 'var(--color-teal-ink)' : negative ? 'var(--color-ink)' : 'var(--color-ink)';
  return (
    <div>
      <div className="text-[11px] uppercase tracking-wider text-[var(--color-ink-3)]">{label}</div>
      <div className="tnum text-[15px] font-semibold mt-0.5" style={{ color }}>
        {negative && value > 0 ? '−' : ''}{formatCurrency(value)}<span className="text-[11px] text-[var(--color-ink-3)] font-normal">/mo</span>
      </div>
    </div>
  );
}

function CashFlowItemForm({ clientId, existing, onSaved, onCancel }) {
  const [kind, setKind] = useState(existing?.kind || 'income');
  const [category, setCategory] = useState(existing?.category || INCOME_CATEGORIES[0].value);
  const [label, setLabel] = useState(existing?.label || '');
  const [amount, setAmount] = useState(existing?.amount != null ? String(existing.amount) : '');
  const [cadence, setCadence] = useState(existing?.cadence || 'monthly');
  const [isActive, setIsActive] = useState(existing ? existing.is_active : true);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const categories = kind === 'income' ? INCOME_CATEGORIES : EXPENSE_CATEGORIES;

  function handleKindChange(next) {
    setKind(next);
    const list = next === 'income' ? INCOME_CATEGORIES : EXPENSE_CATEGORIES;
    setCategory(list[0].value);
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    if (label.trim() === '') return setError('Give this line a name.');
    const numeric = Number(amount);
    if (!Number.isFinite(numeric) || numeric < 0) return setError('Enter a valid amount (0 or more).');

    setSaving(true);
    try {
      const payload = {
        kind,
        label: label.trim(),
        amount: numeric,
        cadence,
        category,
        is_active: isActive,
      };
      if (existing) {
        await api.upsertCashFlowItem({ id: existing.id, ...payload });
      } else {
        await api.upsertCashFlowItem({ client_id: Number(clientId), ...payload });
      }
      onSaved();
    } catch (err) {
      setError(err.message || 'Could not save this line.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="rounded-[var(--radius-ctrl)] border border-[var(--color-line-2)] p-3 mb-2">
      {!existing && (
        <div className="flex gap-2 mb-2">
          <button
            type="button" onClick={() => handleKindChange('income')}
            className="flex-1 rounded-[var(--radius-ctrl)] border px-2 py-1.5 text-xs font-medium"
            style={kind === 'income' ? { borderColor: 'var(--color-teal)', backgroundColor: 'var(--color-teal-soft)', color: 'var(--color-teal-ink)' } : { borderColor: 'var(--color-line-2)', color: 'var(--color-ink-2)' }}
          >
            Income
          </button>
          <button
            type="button" onClick={() => handleKindChange('expense')}
            className="flex-1 rounded-[var(--radius-ctrl)] border px-2 py-1.5 text-xs font-medium"
            style={kind === 'expense' ? { borderColor: 'var(--color-alert)', backgroundColor: 'var(--color-alert-soft)', color: 'var(--color-alert)' } : { borderColor: 'var(--color-line-2)', color: 'var(--color-ink-2)' }}
          >
            Expense
          </button>
        </div>
      )}

      <div className="grid grid-cols-2 gap-2 mb-2">
        <input
          type="text" value={label} onChange={(e) => setLabel(e.target.value)}
          placeholder={kind === 'income' ? 'e.g. Salary' : 'e.g. Home loan EMI'}
          className="field text-sm col-span-2"
        />
        <select value={category} onChange={(e) => setCategory(e.target.value)} className="field text-sm">
          {categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
        </select>
        <select value={cadence} onChange={(e) => setCadence(e.target.value)} className="field text-sm">
          <option value="monthly">Monthly</option>
          <option value="annual">Annual</option>
        </select>
        <input
          type="number" min="0" step="any" value={amount}
          onChange={(e) => setAmount(e.target.value)}
          placeholder={cadence === 'annual' ? 'Amount per year (₹)' : 'Amount per month (₹)'}
          className="field text-sm col-span-2"
        />
      </div>

      <label className="flex items-center gap-2 text-xs text-[var(--color-ink-2)] mb-2">
        <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
        Include in the surplus total (uncheck to park this line without deleting it)
      </label>

      {error && <p className="text-xs mb-2" style={{ color: 'var(--color-alert)' }}>{error}</p>}

      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={saving}>{saving ? 'Saving…' : 'Save'}</Button>
        <Button type="button" variant="ghost" size="sm" onClick={onCancel}>Cancel</Button>
      </div>
    </form>
  );
}

// The client's own read-only view of their cash flow, mirroring
// ClientPortfolioCard/ClientRiskProfileCard. cash_flow_list.php already permits
// a client session to read their own rows (forced to their own id server-side),
// and deliberately withholds the advisor-only SIP comparison — so a client
// sees their income/expense/surplus statement, but not the planning framing.
// No clientId is passed; the server forces it to the session's own id.
export function ClientCashFlowCard() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    api
      .getClientCashFlow()
      .then((res) => { if (!cancelled) setData(res); })
      .catch(() => { /* soft — an unreadable statement just shows the empty note */ })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  const items = data?.items?.filter((it) => it.is_active) ?? [];
  const summary = data?.summary;
  const income = items.filter((it) => it.kind === 'income');
  const expense = items.filter((it) => it.kind === 'expense');

  return (
    <Card className="p-4 mb-4">
      <h2 className="text-base font-semibold text-[var(--color-ink)] mb-1">Your cash flow</h2>
      <p className="text-xs text-[var(--color-ink-2)] mb-3">
        Your monthly income and expenses, as recorded by your adviser — the surplus is what's available
        each month to put towards your goals.
      </p>

      {loading && <Spinner label="Loading…" />}

      {!loading && !summary && (
        <p className="text-xs text-[var(--color-ink-2)]">Your adviser hasn't recorded your cash flow yet.</p>
      )}

      {!loading && summary && (
        <>
          <div className="grid grid-cols-3 gap-3 mb-4">
            <Total label="Monthly income" value={summary.monthly_income} />
            <Total label="Monthly expenses" value={summary.monthly_expense} negative />
            <Total label="Monthly surplus" value={summary.monthly_surplus} highlight={summary.monthly_surplus >= 0} deficit={summary.monthly_surplus < 0} />
          </div>

          {(income.length > 0 || expense.length > 0) && (
            <div className="space-y-3">
              {income.length > 0 && <ReadOnlyGroup title="Income" items={income} />}
              {expense.length > 0 && <ReadOnlyGroup title="Expenses" items={expense} />}
            </div>
          )}
        </>
      )}
    </Card>
  );
}

function ReadOnlyGroup({ title, items }) {
  return (
    <div>
      <div className="text-[11px] uppercase tracking-wider text-[var(--color-ink-3)] mb-1">{title}</div>
      <div className="space-y-1.5">
        {items.map((item) => (
          <div key={item.id} className="flex items-center justify-between gap-3 rounded-[var(--radius-ctrl)] border border-[var(--color-line)] px-3 py-2">
            <div className="flex items-center gap-2 flex-wrap min-w-0">
              <span className="text-sm font-medium text-[var(--color-ink)]">{item.label}</span>
              <Badge fg="var(--color-ink-2)" bg="var(--color-surface-2)">{categoryLabel(item.kind, item.category)}</Badge>
              {item.cadence === 'annual' && <Badge fg="var(--color-amber)" bg="var(--color-amber-soft)">annual</Badge>}
            </div>
            <span className="tnum text-sm text-[var(--color-ink)] shrink-0">
              {formatCurrency(monthlyOf(item))}<span className="text-[11px] text-[var(--color-ink-3)]">/mo</span>
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}

export { INCOME_CATEGORIES, EXPENSE_CATEGORIES };
