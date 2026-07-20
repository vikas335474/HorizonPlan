// Indian rupee formatting — the product is built for the Indian MFD/IFA market
// (docs reference r/CFP + Indian inflation assumptions), so INR + lakh/crore
// grouping is correct here, not USD.

const inr = new Intl.NumberFormat('en-IN', {
  style: 'currency',
  currency: 'INR',
  maximumFractionDigits: 0,
});

const inrCompact = new Intl.NumberFormat('en-IN', {
  style: 'currency',
  currency: 'INR',
  notation: 'compact',
  maximumFractionDigits: 1,
});

export function formatCurrency(value) {
  if (value === null || value === undefined) return '—';
  return inr.format(value);
}

export function formatCurrencyCompact(value) {
  if (value === null || value === undefined) return '—';
  return inrCompact.format(value);
}

export function formatPercent(value) {
  if (value === null || value === undefined) return '—';
  return `${value}%`;
}

export function formatDate(value) {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
}

export const GOAL_TYPE_LABELS = {
  retirement: 'Retirement',
  education: 'Education',
  home_purchase: 'Home purchase',
  other: 'Other',
};

// Each goal type gets a distinct quiet accent so the list is scannable by type
// without relying on text alone.
export const GOAL_TYPE_ACCENT = {
  retirement: { fg: 'var(--color-teal-ink)', bg: 'var(--color-teal-soft)' },
  education: { fg: '#3A4CAD', bg: '#E7EAF8' },
  home_purchase: { fg: 'var(--color-amber)', bg: 'var(--color-amber-soft)' },
  other: { fg: 'var(--color-ink-2)', bg: 'var(--color-surface-2)' },
};

// Corpus multiple = 100 / withdrawal_rate. Matches PlanMath::corpusMultiple().
export function corpusMultiple(ratePercent) {
  if (!ratePercent || ratePercent <= 0) return null;
  return Math.round((100 / ratePercent) * 100) / 100;
}
