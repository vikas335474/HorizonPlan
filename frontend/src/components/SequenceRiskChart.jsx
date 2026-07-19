import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const compactCurrency = new Intl.NumberFormat('en-IN', {
  notation: 'compact',
  style: 'currency',
  currency: 'INR',
  maximumFractionDigits: 1,
});

const fullCurrency = new Intl.NumberFormat('en-IN', {
  style: 'currency',
  currency: 'INR',
  maximumFractionDigits: 0,
});

// docs/02 Section 4.3: two series sharing a CAGR but diverging in outcome —
// steady flat return vs. the same average return reordered so below-average
// years land early. Both come straight from goals_projection.php; nothing
// here is computed client-side, since the sequencing math is deliberately
// server-authoritative (matches PlanMath.php exactly, no drift risk).
export default function SequenceRiskChart({ steadySeries, adverseSeries }) {
  const data = steadySeries.map((value, year) => ({
    year,
    steady: value,
    adverse: adverseSeries[year],
  }));

  return (
    <div style={{ width: '100%', height: 260 }}>
      <ResponsiveContainer>
        <LineChart data={data} margin={{ top: 8, right: 12, bottom: 0, left: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="var(--color-line)" />
          <XAxis
            dataKey="year"
            tick={{ fontSize: 11, fill: 'var(--color-ink-soft)' }}
            label={{ value: 'Year', position: 'insideBottom', offset: -4, fontSize: 11, fill: 'var(--color-ink-soft)' }}
          />
          <YAxis
            tickFormatter={(v) => compactCurrency.format(v)}
            tick={{ fontSize: 11, fill: 'var(--color-ink-soft)' }}
            width={64}
          />
          <Tooltip
            formatter={(value, name) => [fullCurrency.format(value), name]}
            labelFormatter={(year) => `Year ${year}`}
            contentStyle={{ fontSize: 12, borderColor: 'var(--color-line)' }}
          />
          <Legend wrapperStyle={{ fontSize: 12 }} />
          <Line
            type="monotone"
            dataKey="steady"
            name="Steady return"
            stroke="var(--color-growth)"
            dot={false}
            strokeWidth={2}
          />
          <Line
            type="monotone"
            dataKey="adverse"
            name="Adverse sequence"
            stroke="var(--color-alert)"
            dot={false}
            strokeWidth={2}
            strokeDasharray="4 3"
          />
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}
