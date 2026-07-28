// Recharts area chart of the full accumulation -> decumulation lifecycle. Props:
// {series, yearsToRetirement}; a ReferenceLine marks the retirement year. Purely
// presentational — it renders series it is handed, it never fetches.

import { ComposedChart, Area, ReferenceLine, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { formatCurrencyCompact, formatCurrency } from '../lib/format';

// docs/07 Session C / docs/06 Section A: one continuous curve spanning the
// saving years (accumulation) and the withdrawal years (decumulation), from
// PlanMath::lifecycleSeries(). A single teal line — this isn't a comparison
// of two paths like SequenceRiskChart, it's the client's one journey — with a
// dashed vertical marker at the retirement-day boundary (yearsToRetirement)
// so the "before" and "after" halves of the curve read clearly.
export default function LifecycleChart({ series, yearsToRetirement }) {
  const data = series.map((value, year) => ({ year, corpus: value }));

  return (
    <div style={{ width: '100%', height: 280 }}>
      <ResponsiveContainer>
        <ComposedChart data={data} margin={{ top: 10, right: 10, bottom: 6, left: 4 }}>
          <defs>
            <linearGradient id="lifecycleFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="var(--color-teal)" stopOpacity="0.22" />
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
          <Tooltip content={<LifecycleTooltip yearsToRetirement={yearsToRetirement} />} cursor={{ stroke: 'var(--color-line-2)', strokeWidth: 1 }} />

          <ReferenceLine
            x={yearsToRetirement}
            stroke="var(--color-ink-3)"
            strokeDasharray="3 3"
            label={{ value: 'Retirement', position: 'top', fontSize: 11, fill: 'var(--color-ink-3)' }}
          />

          <Area
            type="monotone"
            dataKey="corpus"
            name="Corpus"
            stroke="var(--color-teal)"
            strokeWidth={2.75}
            fill="url(#lifecycleFill)"
            dot={false}
            activeDot={{ r: 5, strokeWidth: 2, stroke: 'var(--color-surface)' }}
            isAnimationActive
            animationDuration={700}
          />
        </ComposedChart>
      </ResponsiveContainer>

      <div className="mt-1 flex items-center justify-center gap-2 text-xs text-[var(--color-ink-2)]">
        <span className="inline-flex items-center gap-2">
          <span className="h-2 w-2 rounded-full" style={{ backgroundColor: 'var(--color-teal)' }} />
          Saving years → retirement → withdrawal years
        </span>
      </div>
    </div>
  );
}

function LifecycleTooltip({ active, payload, label, yearsToRetirement }) {
  if (!active || !payload || !payload.length) return null;
  const corpus = payload.find((p) => p.dataKey === 'corpus')?.value;
  const phase = label < yearsToRetirement ? 'Saving' : label === yearsToRetirement ? 'Retirement day' : 'Withdrawing';

  return (
    <div
      className="rounded-[var(--radius-ctrl)] border px-3 py-2.5 text-xs"
      style={{ backgroundColor: 'var(--color-surface)', borderColor: 'var(--color-line-2)', boxShadow: 'var(--shadow-lg)' }}
    >
      <div className="font-semibold text-[var(--color-ink)] mb-1">Year {label} · {phase}</div>
      <div className="tnum font-semibold text-[var(--color-ink)]">{formatCurrency(corpus)}</div>
    </div>
  );
}
