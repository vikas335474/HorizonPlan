// P1-1 · Goal-progress tracking over time (docs/10). Renders a goal's RECORDED
// snapshot history — what the corpus actually was on each date we looked —
// against what the plan expected on those same dates.
//
// Two modes, chosen by the endpoint's tracking_mode, never both:
//   'drift'    — actual corpus vs the expected line (projectable goals).
//   'coverage' — the funding % trend (target-based goals, which carry no
//                return assumption and so have no expected curve at all).
//
// Purely presentational: it plots the points it is handed and never models,
// interpolates, or extends them. A gap between snapshots is a gap in the
// record, and the chart shows it as one — connecting through an unobserved
// month would be inventing a reading.
//
// Props: { points, mode }  (from api.getGoalProgress)

import {
  ComposedChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
} from 'recharts';
import { formatCurrency } from '../lib/format';

// Axis ticks for a NARROW value range.
//
// A progress chart's whole subject is a small gap — actual vs expected differ
// by a few percent — so the shared 1-decimal compact format ("₹1.4Cr") renders
// several distinct ticks with the identical label, which reads as a rendering
// bug rather than a tight range. This picks just enough decimals for the
// span actually being plotted: wide ranges keep the familiar compact form,
// narrow ones gain precision until the ticks are distinguishable.
function makeAmountTickFormatter(values) {
  const finite = values.filter((v) => typeof v === 'number' && Number.isFinite(v));
  const max = finite.length ? Math.max(...finite.map(Math.abs)) : 0;
  const span = finite.length ? Math.max(...finite) - Math.min(...finite) : 0;
  // Ratio of the span to the magnitude decides how many digits are needed.
  const ratio = max > 0 ? span / max : 1;
  const digits = ratio < 0.02 ? 3 : ratio < 0.1 ? 2 : 1;

  const fmt = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    notation: 'compact',
    maximumFractionDigits: digits,
    minimumFractionDigits: digits,
  });
  return (v) => fmt.format(v);
}

function shortDate(iso) {
  // "2026-08-01" → "Aug 26" — a monthly series doesn't need the day, and the
  // axis has to stay readable at a dozen points on a phone.
  const d = new Date(`${iso}T00:00:00`);
  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleDateString('en-IN', { month: 'short', year: '2-digit' });
}

export default function ProgressChart({ points, mode }) {
  if (!points || points.length === 0) return null;

  const isCoverage = mode === 'coverage';

  // Built from every plotted amount so both series share one tick scale.
  const amountTick = makeAmountTickFormatter(
    points.flatMap((p) => [p.corpus_value, p.expected_value])
  );

  const data = points.map((p) => ({
    date: shortDate(p.as_of_date),
    rawDate: p.as_of_date,
    actual: p.corpus_value,
    expected: p.expected_value,
    coverage: p.funding_covered_pct,
    automatic: p.automatic,
  }));

  return (
    <div style={{ width: '100%', height: 240 }}>
      <ResponsiveContainer>
        <ComposedChart data={data} margin={{ top: 8, right: 8, bottom: 4, left: 4 }}>
          <CartesianGrid stroke="var(--color-line)" strokeDasharray="3 3" vertical={false} />
          <XAxis
            dataKey="date"
            tick={{ fontSize: 11, fill: 'var(--color-ink-3)' }}
            axisLine={{ stroke: 'var(--color-line-2)' }}
            tickLine={false}
          />
          <YAxis
            tick={{ fontSize: 11, fill: 'var(--color-ink-3)' }}
            axisLine={false}
            tickLine={false}
            width={58}
            domain={isCoverage ? [0, 100] : ['auto', 'auto']}
            tickFormatter={(v) => (isCoverage ? `${v}%` : amountTick(v))}
          />
          <Tooltip content={<ProgressTooltip isCoverage={isCoverage} />} />
          <Legend
            wrapperStyle={{ fontSize: 11, color: 'var(--color-ink-2)' }}
            iconType="plainline"
          />

          {isCoverage ? (
            <Line
              type="monotone"
              dataKey="coverage"
              name="Funding covered"
              stroke="var(--color-teal)"
              strokeWidth={2.2}
              dot={{ r: 3, fill: 'var(--color-teal)' }}
              // A missing point breaks the line rather than bridging it — see
              // the file header: an unobserved month is not a reading.
              connectNulls={false}
            />
          ) : (
            <>
              <Line
                type="monotone"
                dataKey="expected"
                name="Plan expects"
                stroke="var(--color-ink-3)"
                strokeWidth={1.8}
                strokeDasharray="5 4"
                dot={false}
                connectNulls={false}
              />
              <Line
                type="monotone"
                dataKey="actual"
                name="Actual corpus"
                stroke="var(--color-teal)"
                strokeWidth={2.2}
                dot={{ r: 3, fill: 'var(--color-teal)' }}
                connectNulls={false}
              />
            </>
          )}
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  );
}

function ProgressTooltip({ active, payload, label, isCoverage }) {
  if (!active || !payload || payload.length === 0) return null;
  const row = payload[0].payload;

  return (
    <div
      className="rounded-lg border px-3 py-2 text-xs"
      style={{
        background: 'var(--color-surface)',
        borderColor: 'var(--color-line-2)',
        boxShadow: 'var(--shadow-sm)',
      }}
    >
      <div className="font-semibold text-[var(--color-ink)] mb-1">{label}</div>

      {isCoverage ? (
        <Row color="var(--color-teal)" label="Covered" value={`${row.coverage}%`} />
      ) : (
        <>
          <Row color="var(--color-teal)" label="Actual" value={formatCurrency(row.actual)} />
          {row.expected !== null && row.expected !== undefined && (
            <>
              <Row color="var(--color-ink-3)" label="Plan expects" value={formatCurrency(row.expected)} />
              <div className="mt-1 pt-1 border-t border-[var(--color-line)] text-[var(--color-ink-2)]">
                {row.actual >= row.expected ? '+' : '−'}
                {formatCurrency(Math.abs(row.actual - row.expected))} vs plan
              </div>
            </>
          )}
        </>
      )}

      {/* Whether a person took this reading or the scheduled job did. Small,
          but it's the difference between "we checked before your review" and
          "the system logged it". */}
      <div className="mt-1 text-[10px] text-[var(--color-ink-3)]">
        {row.automatic ? 'Recorded automatically' : 'Recorded by your adviser'}
      </div>
    </div>
  );
}

function Row({ color, label, value }) {
  return (
    <div className="flex items-center justify-between gap-4">
      <span className="inline-flex items-center gap-1.5 text-[var(--color-ink-2)]">
        <span className="h-2 w-2 rounded-full" style={{ backgroundColor: color }} aria-hidden="true" />
        {label}
      </span>
      <span className="tabular-nums font-medium text-[var(--color-ink)]">{value}</span>
    </div>
  );
}
