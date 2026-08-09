// docs/10 P1-4 · The "financial foundations" card — emergency reserve, income
// replacement, medical cover, and debt costing more than the plan assumes to
// earn. Two exports, the same shape as CashFlowUI:
//
//   FoundationsCard        — advisor view on a client page (planning vocabulary)
//   ClientFoundationsCard  — the client's / individual's own view
//
// Data comes from api.getFoundations (foundations_read.php); the protection
// figures are written back through api.saveFoundations. Editing is offered only
// where the server would actually accept the write: an advisor in a firm
// tenant, or an individual in a personal tenant (sql/033). A firm-managed
// client sees the same checks read-only, because their adviser owns the record.
//
// TONE RULE, and it is the point of this module: every line states a fact and a
// commonly-cited reference point. Nothing here tells anyone to buy anything —
// see api/lib/FinancialFoundations.php for why that boundary is drawn where it
// is. 'not_recorded' renders as an open question, never as a passed check.

import { useEffect, useState, useCallback } from 'react';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import { Card, Button, Spinner } from './ui';
import { formatCurrency } from '../lib/format';

// Per-status presentation. 'not_recorded' deliberately gets the same neutral
// grey as 'not_applicable' rather than a warning colour — an unanswered
// question is not a finding.
const STATUS_STYLE = {
  ok:             { dot: 'var(--color-teal)',  label: 'Covered' },
  partial:        { dot: 'var(--color-amber)', label: 'Partly there' },
  short:          { dot: 'var(--color-alert)', label: 'Gap' },
  not_recorded:   { dot: 'var(--color-ink-3)', label: 'Not answered yet' },
  not_applicable: { dot: 'var(--color-ink-3)', label: 'Not needed' },
};

const TITLES = {
  advisor: {
    emergency_reserve: 'Emergency reserve',
    life_cover: 'Life cover (income replacement)',
    health_cover: 'Health cover',
    costly_debt: 'High-cost debt',
  },
  plain: {
    emergency_reserve: 'Money set aside for emergencies',
    life_cover: 'If something happened to you',
    health_cover: 'Medical cover',
    costly_debt: 'Expensive borrowing',
  },
};

const months = (n) => {
  const shown = n < 10 ? n.toFixed(1) : String(Math.round(n));
  return `${shown} month${Number(shown) === 1 ? '' : 's'}`;
};

// The sentence for one check. Plain mode drops the jargon but never softens the
// fact — the numbers are identical in both.
function statement(check, plain) {
  const s = check.status;
  switch (check.id) {
    case 'emergency_reserve': {
      if (s === 'not_recorded') {
        return plain
          ? 'Add your monthly expenses and your savings, and this will show how long you could cover yourself if your income stopped.'
          : 'No monthly expenses recorded, so months of cover cannot be computed.';
      }
      const held = `${months(check.months)} of expenses`;
      const ref = `Commonly cited: ${check.floor}–${check.comfortable} months held in savings you can reach quickly.`;
      return plain
        ? `You have ${held} set aside in savings you could reach quickly. ${ref}`
        : `Liquid assets cover ${held} (${formatCurrency(check.liquid)} against ${formatCurrency(check.monthly_expenses)}/month). ${ref}`;
    }
    case 'life_cover': {
      if (s === 'not_applicable') {
        return plain
          ? 'You told us nobody depends on your income, so life cover is not something this plan needs to size.'
          : 'No dependants recorded — income-replacement cover is not applicable.';
      }
      if (s === 'not_recorded') {
        // Three different things can be missing here. Asking for all of them
        // when only one is absent makes an answered question look ignored.
        if (check.dependants === null || check.dependants === undefined) {
          return plain
            ? 'Tell us whether anyone depends on your income — that decides whether life cover is worth sizing at all.'
            : 'Dependants not recorded — cover cannot be sized or ruled out.';
        }
        const who = check.dependants === 1 ? 'One person depends' : `${check.dependants} people depend`;
        if (check.cover === null || check.cover === undefined) {
          return plain
            ? `${who} on your income. Add the life cover you already have and this will show how far it goes.`
            : `${check.dependants} dependant(s) recorded; existing cover not recorded.`;
        }
        return plain
          ? `${who} on your income, and you have cover recorded — add your income in the cash-flow section to see how the two compare.`
          : 'No income on file to size the recorded cover against.';
      }
      const multiple = `${check.multiple.toFixed(1)}× your annual income`;
      const ref = `A widely used reference is ${check.floor}–${check.comfortable}× annual income where people depend on it.`;
      return plain
        ? `Your cover is worth about ${multiple}. ${ref}`
        : `Cover of ${formatCurrency(check.cover)} is ${multiple}. ${ref}`;
    }
    case 'health_cover': {
      if (s === 'not_recorded') {
        return plain
          ? 'Add the medical cover you already have — from your employer or your own policy.'
          : 'Existing health cover not recorded.';
      }
      const ref = `${formatCurrency(check.floor)} is the most commonly cited individual baseline, though the right figure varies a lot with city, age and family size.`;
      return check.cover > 0
        ? `You have ${formatCurrency(check.cover)} of medical cover. ${ref}`
        : `No medical cover recorded. ${ref}`;
    }
    case 'costly_debt': {
      if (s === 'not_recorded') {
        if (check.unrated > 0) {
          return plain
            ? `Add the interest rate on ${check.unrated === 1 ? 'your loan' : `your ${check.unrated} loans`} and this will compare what they cost against what your plan expects to earn.`
            : `${check.unrated} liabilit${check.unrated === 1 ? 'y has' : 'ies have'} no interest rate recorded, so the comparison is unavailable.`;
        }
        if (check.assumed_return === null || check.assumed_return === undefined) {
          return plain
            ? 'Once a goal has a growth assumption, this will compare what you owe against it.'
            : 'No return assumption on any goal to compare borrowing costs against.';
        }
        // Nothing recorded in the ledger at all — see costlyDebt()'s
        // $ledgerHasRows. An untouched ledger is not a clean bill of health.
        return plain
          ? 'Add what you own and owe, and this will flag anything costing you more than your savings are expected to earn.'
          : 'No portfolio or liabilities recorded, so borrowing cost is unknown.';
      }
      if (check.costly.length === 0) {
        return plain
          ? 'Nothing you owe costs more than your plan expects your savings to earn.'
          : 'No recorded liability costs more than the plan’s highest return assumption.';
      }
      const worst = check.costly.reduce((a, b) => (b.gap > a.gap ? b : a));
      const total = formatCurrency(check.total_costly);
      return plain
        ? `${worst.label} charges ${worst.interest_rate}% a year. Your plan assumes your savings grow at ${check.assumed_return}%. That means ${total} of what you owe is costing more than your money is expected to make.`
        : `${total} of borrowing exceeds the plan’s ${check.assumed_return}% assumption — worst is ${worst.label} at ${worst.interest_rate}% (${worst.gap.toFixed(1)}pp gap).`;
    }
    default:
      return '';
  }
}

function CheckRow({ check, plain }) {
  const style = STATUS_STYLE[check.status] ?? STATUS_STYLE.not_recorded;
  const title = (plain ? TITLES.plain : TITLES.advisor)[check.id];
  return (
    <li className="flex gap-2.5 py-2.5 border-b border-[var(--color-line)] last:border-0">
      <span
        className="mt-1.5 h-2 w-2 shrink-0 rounded-full"
        style={{ backgroundColor: style.dot }}
        aria-hidden="true"
      />
      <div className="min-w-0">
        <div className="flex flex-wrap items-baseline gap-x-2">
          <span className="text-sm font-medium text-[var(--color-ink)]">{title}</span>
          <span className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">{style.label}</span>
        </div>
        <p className="mt-0.5 text-xs leading-relaxed text-[var(--color-ink-2)]">{statement(check, plain)}</p>
      </div>
    </li>
  );
}

// The three stored facts. Empty string means "not recorded" and is sent as null
// so the server clears the field rather than storing 0 — 0 is a real answer
// ("no cover") and must stay distinguishable from a blank.
function ProtectionForm({ clientId, protection, onSaved, plain }) {
  const [dependants, setDependants] = useState(protection.dependants_count ?? '');
  const [term, setTerm] = useState(protection.term_life_cover ?? '');
  const [health, setHealth] = useState(protection.health_cover ?? '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  async function save() {
    setSaving(true);
    setError('');
    const blankToNull = (v) => (v === '' || v === null ? null : Number(v));
    try {
      await api.saveFoundations({
        ...(clientId ? { client_id: clientId } : {}),
        dependants_count: blankToNull(dependants),
        term_life_cover: blankToNull(term),
        health_cover: blankToNull(health),
      });
      onSaved();
    } catch (e) {
      setError(e?.message || 'Could not save.');
    } finally {
      setSaving(false);
    }
  }

  const field = 'w-full rounded-lg border border-[var(--color-line-2)] bg-[var(--color-surface)] px-2.5 py-1.5 text-sm';
  return (
    <div className="mt-3 rounded-lg bg-[var(--color-surface-2)] p-3">
      <div className="grid gap-3 sm:grid-cols-3">
        <label className="block">
          <span className="mb-1 block text-xs font-medium text-[var(--color-ink-2)]">
            {plain ? 'People who depend on your income' : 'Dependants'}
          </span>
          <input className={field} type="number" min="0" max="20" value={dependants}
            onChange={(e) => setDependants(e.target.value)} placeholder="—" />
        </label>
        <label className="block">
          <span className="mb-1 block text-xs font-medium text-[var(--color-ink-2)]">
            {plain ? 'Life cover you already have (₹ sum assured)' : 'Life cover (₹ sum assured)'}
          </span>
          <input className={field} type="number" min="0" value={term}
            onChange={(e) => setTerm(e.target.value)} placeholder="e.g. 5000000 for ₹50L" />
        </label>
        <label className="block">
          <span className="mb-1 block text-xs font-medium text-[var(--color-ink-2)]">
            {plain ? 'Medical cover you already have (₹ sum insured)' : 'Health cover (₹ sum insured)'}
          </span>
          <input className={field} type="number" min="0" value={health}
            onChange={(e) => setHealth(e.target.value)} placeholder="e.g. 500000 for ₹5L" />
        </label>
      </div>
      {error && <p className="mt-2 text-xs text-[var(--color-alert)]">{error}</p>}
      <div className="mt-3 flex items-center gap-2">
        <Button size="sm" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save'}</Button>
        <span className="text-[11px] text-[var(--color-ink-3)]">Leave a box empty if you'd rather not answer it.</span>
      </div>
    </div>
  );
}

// Shared body. `plain` switches vocabulary only; `canEdit` reflects what the
// server would actually accept.
function FoundationsBody({ clientId, plain, canEdit, blurb }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState(false);

  const load = useCallback(() => {
    let cancelled = false;
    setLoading(true);
    api
      .getFoundations(clientId)
      .then((res) => { if (!cancelled) setData(res); })
      .catch(() => { /* soft — an unreadable check just shows nothing */ })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [clientId]);

  useEffect(() => load(), [load]);

  const checks = data?.foundations?.checks ?? [];

  return (
    <Card className="p-4 mb-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-[var(--color-ink)]">
            {plain ? 'Before the plan: the basics' : 'Financial foundations'}
          </h2>
          <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">{blurb}</p>
        </div>
        {canEdit && !loading && (
          <Button variant="ghost" size="sm" onClick={() => setEditing((v) => !v)}>
            {editing ? 'Close' : 'Update'}
          </Button>
        )}
      </div>

      {loading && <div className="mt-3"><Spinner label="Loading…" /></div>}

      {!loading && checks.length > 0 && (
        <ul className="mt-2">
          {checks.map((c) => <CheckRow key={c.id} check={c} plain={plain} />)}
        </ul>
      )}

      {!loading && canEdit && editing && (
        <ProtectionForm
          clientId={clientId}
          protection={data?.protection ?? {}}
          plain={plain}
          onSaved={() => { setEditing(false); load(); }}
        />
      )}
    </Card>
  );
}

// A one-line caption for a readiness score.
//
// The score answers exactly one question — does the money last — and says
// nothing about whether the person could absorb a hospital bill or a job loss
// next month. Left uncaptioned next to a number like 89, that silence reads as
// endorsement. This states what the score does not cover, in plain terms, and
// renders NOTHING when there is nothing to say: a sound statement gets no
// caption, because a caveat that always appears stops being read.
export function FoundationsCaveat({ clientId = null }) {
  const [data, setData] = useState(null);

  useEffect(() => {
    let cancelled = false;
    api
      .getFoundations(clientId)
      .then((res) => { if (!cancelled) setData(res); })
      .catch(() => { /* soft — no caption rather than an error */ });
    return () => { cancelled = true; };
  }, [clientId]);

  const f = data?.foundations;
  if (!f || (f.unmet === 0 && f.open === 0)) return null;

  const parts = [];
  if (f.unmet > 0) parts.push(`${f.unmet} ${f.unmet === 1 ? 'gap' : 'gaps'} in your foundations`);
  if (f.open > 0) parts.push(`${f.open} unanswered`);

  return (
    <p className="mt-2 text-xs leading-relaxed text-[var(--color-ink-2)]">
      This score only asks whether the money lasts. It doesn’t account for{' '}
      {parts.join(' and ')} — emergency savings, cover and debt are checked separately.
    </p>
  );
}

// Advisor view on a client page.
export function FoundationsCard({ clientId }) {
  return (
    <FoundationsBody
      clientId={clientId}
      plain={false}
      canEdit
      blurb="Reserve, protection and debt — the ground the goal plan stands on. Reserve and debt are read from the cash-flow statement and portfolio; cover is recorded here."
    />
  );
}

// The client's / individual's own view. A personal-tenant user can edit their
// own record (sql/033); a firm-managed client cannot, because their adviser
// owns it — and the server enforces exactly that, this only matches it.
export function ClientFoundationsCard() {
  const { tenant } = useAuth();
  const isSelfDirected = tenant?.kind === 'personal';
  return (
    <FoundationsBody
      clientId={null}
      plain
      canEdit={isSelfDirected}
      blurb={
        isSelfDirected
          ? 'A plan works better when the ground under it is solid. These are the four things worth having in place before a long-term goal.'
          : 'The four things worth having in place before a long-term goal, based on what your adviser has recorded.'
      }
    />
  );
}
