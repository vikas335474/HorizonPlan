import { ComposedChart, Area, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { formatCurrencyCompact, formatCurrency } from '../lib/format';

// docs/02 Section 4.3: two series sharing a CAGR but diverging in outcome —
// steady flat return vs. the same average return reordered so below-average
// years land early. Both come from goals_projection.php; nothing is computed
// client-side (sequencing math is server-authoritative, matches PlanMath.php).
//
// Visual language: the steady path is the "confident" line — teal, solid, with a
// soft gradient body under it. The adverse path is a dashed rust line riding
// below it; the shaded wedge between them is the sequence-of-returns risk made
// literal. The custom tooltip surfaces that gap as a number.
export default function SequenceRiskChart({ steadySeries, adverseSeries }) {
  const data = steadySeries.map((value, year) => ({
    year,
    steady: value,
    adverse: adverseSeries[year],
  }));

  return (
    <div style={{ width: '100%', height: 300 }}>
      <ResponsiveContainer>
        <ComposedChart data={data} margin={{ top: 10, right: 10, bottom: 6, left: 4 }}>
          <defs>
            <linearGradient id="steadyFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="var(--color-teal)" stopOpacity="0.24" />
              <stop offset="100%" stopColor="var(--color-teal)" stopOpacity="0" />
            </linearGradient>
          </defs>

          <CartesianGrid strokeDasharray="2 5" stroke="var(--color-line)" vertical={false} />
          <XAxis
            dataKey="year"
            tick={{ fontSize: 11, fill: 'var(--color-ink-3)' }}
            tickLine={false}
            axisLine={{ stroke: 'var(--color-line-2)' }}
            tickMargin={8}
            label={{ value: 'Year', position: 'insideBottom', offset: -4, fontSize: 11, fill: 'var(--color-ink-3)' }}
          />
          <YAxis
            tickFormatter={(v) => formatCurrencyCompact(v)}
            tick={{ fontSize: 11, fill: 'var(--color-ink-3)', fontFamily: 'var(--font-mono)' }}
            tickLine={false}
            axisLine={false}
            width={72}
          />
          <Tooltip content={<SequenceTooltip />} cursor={{ stroke: 'var(--color-line-2)', strokeWidth: 1 }} />

          {/* Steady path — area (gradient body) whose top edge is the line itself */}
          <Area
            type="monotone"
            dataKey="steady"
            name="Steady return"
            stroke="var(--color-teal)"
            strokeWidth={2.75}
            fill="url(#steadyFill)"
            dot={false}
            activeDot={{ r: 5, strokeWidth: 2, stroke: 'var(--color-surface)' }}
            isAnimationActive
            animationDuration={700}
          />
          {/* Adverse path — dashed rust line, no fill */}
          <Line
            type="monotone"
            dataKey="adverse"
            name="Adverse sequence"
            stroke="var(--color-alert)"
            strokeWidth={2}
            strokeDasharray="5 5"
            dot={false}
            activeDot={{ r: 5, strokeWidth: 2, stroke: 'var(--color-surface)' }}
            isAnimationActive
            animationDuration={700}
          />
        </ComposedChart>
      </ResponsiveContainer>

      {/* Custom legend — matches the app's type scale rather than recharts' default */}
      <div className="mt-1 flex items-center justify-center gap-5 text-xs text-[var(--color-ink-2)]">
        <LegendKey color="var(--color-teal)">Steady return</LegendKey>
        <LegendKey color="var(--color-alert)" dashed>Adverse sequence</LegendKey>
      </div>
    </div>
  );
}

function LegendKey({ color, dashed, children }) {
  return (
    <span className="inline-flex items-center gap-2">
      <svg width="20" height="8" viewBox="0 0 20 8" aria-hidden="true">
        <line x1="0" y1="4" x2="20" y2="4" stroke={color} strokeWidth="2.5" strokeLinecap="round" strokeDasharray={dashed ? '4 3' : undefined} />
      </svg>
      {children}
    </span>
  );
}

function SequenceTooltip({ active, payload, label }) {
  if (!active || !payload || !payload.length) return null;
  const steady = payload.find((p) => p.dataKey === 'steady')?.value;
  const adverse = payload.find((p) => p.dataKey === 'adverse')?.value;
  const gap = steady != null && adverse != null ? steady - adverse : null;

  return (
    <div
      className="rounded-[var(--radius-ctrl)] border px-3 py-2.5 text-xs"
      style={{ backgroundColor: 'var(--color-surface)', borderColor: 'var(--color-line-2)', boxShadow: 'var(--shadow-lg)' }}
    >
      <div className="font-semibold text-[var(--color-ink)] mb-1.5">Year {label}</div>
      <Row color="var(--color-teal)" label="Steady" value={formatCurrency(steady)} />
      <Row color="var(--color-alert)" label="Adverse" value={formatCurrency(adverse)} />
      {gap != null && gap > 0 && (
        <div className="mt-1.5 pt-1.5 border-t border-[var(--color-line)] tnum text-[var(--color-ink-2)]">
          Sequence gap · <span className="font-semibold text-[var(--color-alert)]">{formatCurrency(gap)}</span>
        </div>
      )}
    </div>
  );
}

function Row({ color, label, value }) {
  return (
    <div className="flex items-center justify-between gap-6 py-0.5">
      <span className="flex items-center gap-1.5 text-[var(--color-ink-2)]">
        <span className="h-2 w-2 rounded-full" style={{ backgroundColor: color }} />
        {label}
      </span>
      <span className="tnum font-semibold text-[var(--color-ink)]">{value}</span>
    </div>
  );
}
