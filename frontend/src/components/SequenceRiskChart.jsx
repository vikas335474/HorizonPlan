import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { formatCurrencyCompact, formatCurrency } from '../lib/format';

// docs/02 Section 4.3: two series sharing a CAGR but diverging in outcome —
// steady flat return vs. the same average return reordered so below-average
// years land early. Both come from goals_projection.php; nothing is computed
// client-side (sequencing math is server-authoritative, matches PlanMath.php).
export default function SequenceRiskChart({ steadySeries, adverseSeries }) {
  const data = steadySeries.map((value, year) => ({
    year,
    steady: value,
    adverse: adverseSeries[year],
  }));

  return (
    <div style={{ width: '100%', height: 280 }}>
      <ResponsiveContainer>
        <LineChart data={data} margin={{ top: 8, right: 8, bottom: 4, left: 4 }}>
          <CartesianGrid strokeDasharray="2 4" stroke="var(--color-line)" vertical={false} />
          <XAxis
            dataKey="year"
            tick={{ fontSize: 11, fill: 'var(--color-ink-3)' }}
            tickLine={false}
            axisLine={{ stroke: 'var(--color-line-2)' }}
            label={{ value: 'Year', position: 'insideBottom', offset: -2, fontSize: 11, fill: 'var(--color-ink-3)' }}
          />
          <YAxis
            tickFormatter={(v) => formatCurrencyCompact(v)}
            tick={{ fontSize: 11, fill: 'var(--color-ink-3)', fontFamily: 'var(--font-mono)' }}
            tickLine={false}
            axisLine={false}
            width={70}
          />
          <Tooltip
            formatter={(value, name) => [formatCurrency(value), name]}
            labelFormatter={(year) => `Year ${year}`}
            contentStyle={{
              fontSize: 12,
              borderRadius: 8,
              border: '1px solid var(--color-line-2)',
              boxShadow: '0 4px 16px rgba(15,23,41,0.10)',
              fontFamily: 'var(--font-mono)',
            }}
          />
          <Legend wrapperStyle={{ fontSize: 12, paddingTop: 8 }} iconType="plainline" />
          <Line
            type="monotone"
            dataKey="steady"
            name="Steady return"
            stroke="var(--color-teal)"
            dot={false}
            strokeWidth={2.5}
            activeDot={{ r: 4 }}
          />
          <Line
            type="monotone"
            dataKey="adverse"
            name="Adverse sequence"
            stroke="var(--color-alert)"
            dot={false}
            strokeWidth={2}
            strokeDasharray="5 4"
            activeDot={{ r: 4 }}
          />
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}
