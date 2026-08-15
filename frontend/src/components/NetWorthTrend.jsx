// A client's NET WORTH over time — the recorded series from the portfolio
// ledger (client_net_worth_snapshots, migration 032), captured monthly by
// tools/progress_snapshot.php.
//
// WHY THIS EXISTS AT ALL. The table, the capture cron, the endpoint
// (client_progress_read.php) and the API method (api.getClientProgress) were
// all built and shipped — and nothing in the app ever rendered them. The
// series has been accumulating with no way to see it. This is the missing
// consumer, not a new feature.
//
// *** WHY IT IS FRAMED "SINCE YOU STARTED TRACKING", NOT MONTH-OVER-MONTH ***
// The obvious build is a "you're up 2.1% this month" tile, and it is the wrong
// one. Benartzi & Thaler's myopic loss aversion is the documented result here:
// the more often an investor evaluates, the more small losses they observe,
// the less equity they hold, and the worse their lifetime outcome. A person
// with a twenty-year horizon should not be handed a monthly scoreboard. So the
// headline delta spans the WHOLE tracked window and names its start date, and
// no month-over-month figure is computed anywhere in this file.
//
// The server has already done the arithmetic (`change`, null below two points)
// so this component never recomputes a delta the API would state differently.
//
// THREE-POINT FLOOR. Below three readings this renders nothing at all. Two
// points drawn as a line is a shape the eye reads as a trend when it is just
// the only two facts we hold; the honest version of "not enough history yet"
// is silence, the same convention AlertsCard and FoundationsCaveat already use.
//
// AUDIENCE. Mounted on the self-serve individual's own plan page. It is
// deliberately plain-language throughout ("what you own minus what you owe"),
// and it never implies this pool funds any particular goal — docs/02 §4.1 is
// explicit that the portfolio is not a pool a goal draws from, which is why
// migration 032 keeps this series separate from goal_snapshots in the first
// place.

import { useEffect, useState } from 'react';
import {
  AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
} from 'recharts';
import { api } from '../lib/api';
import { Card } from './ui';
import { formatCurrency } from '../lib/format';

// Fewer than this many readings renders nothing — see the header.
const MIN_POINTS_FOR_A_TREND = 3;

function shortDate(iso) {
  const d = new Date(`${iso}T00:00:00`);
  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleDateString('en-IN', { month: 'short', year: '2-digit' });
}

function longMonth(iso) {
  const d = new Date(`${iso}T00:00:00`);
  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
}

// Compact rupee ticks. Net worth spans a wide range across a life, so the
// shared 1-decimal compact form is right here — unlike ProgressChart, whose
// whole subject is a narrow actual-vs-expected gap needing extra digits.
const tickFormatter = (v) =>
  new Intl.NumberFormat('en-IN', {
    style: 'currency', currency: 'INR', notation: 'compact', maximumFractionDigits: 1,
  }).format(v);

export default function NetWorthTrend() {
  const [data, setData] = useState(null);

  useEffect(() => {
    let cancelled = false;
    // No client_id: a client session is forced to their own server-side, which
    // is the only mode this component is mounted in.
    api
      .getClientProgress()
      .then((res) => { if (!cancelled) setData(res); })
      .catch(() => { if (!cancelled) setData(null); });
    return () => { cancelled = true; };
  }, []);

  const points = data?.points ?? [];
  if (points.length < MIN_POINTS_FOR_A_TREND) return null;

  const change = data?.change ?? null;
  const latest = data?.latest ?? null;

  const series = points.map((p) => ({
    date: shortDate(p.as_of_date),
    netWorth: p.net_worth,
    assets: p.assets_total,
    liabilities: p.liabilities_total,
    automatic: p.automatic,
  }));

  // A net worth that has been negative at any point must not be drawn against
  // a zero-anchored axis that crops it. Let recharts choose when that happens.
  const anyNegative = points.some((p) => p.net_worth < 0);

  return (
    <Card className="p-5 mb-4">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="text-sm font-semibold text-[var(--color-ink)]">
            What you own, over time
          </h2>
          <p className="mt-0.5 text-[11px] text-[var(--color-ink-2)]">
            Everything you've recorded, minus what you owe.
          </p>
        </div>

        {latest && (
          <div className="text-right">
            <p className="text-[26px] font-semibold leading-tight tabular-nums tracking-tight text-[var(--color-ink)]">
              {formatCurrency(latest.net_worth)}
            </p>
            {/* The window's whole-period change, as the server computed it.
                Never a monthly figure — see the file header. */}
            {change && (
              <p
                className="mt-0.5 text-[11px] tabular-nums"
                style={{
                  color:
                    change.amount > 0
                      ? 'var(--color-teal-ink)'
                      : change.amount < 0
                        ? 'var(--color-amber-ink)'
                        : 'var(--color-ink-3)',
                }}
              >
                {change.amount > 0 && '▲ '}
                {change.amount < 0 && '▼ '}
                {change.amount !== 0 && formatCurrency(Math.abs(change.amount))}
                {change.amount === 0 && 'No change'}
                {' since '}
                {longMonth(change.from_date)}
              </p>
            )}
          </div>
        )}
      </div>

      <div className="mt-4" style={{ width: '100%', height: 200 }}>
        <ResponsiveContainer>
          <AreaChart data={series} margin={{ top: 8, right: 8, bottom: 4, left: 4 }}>
            <defs>
              <linearGradient id="nwFill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="var(--color-teal)" stopOpacity={0.28} />
                <stop offset="100%" stopColor="var(--color-teal)" stopOpacity={0.02} />
              </linearGradient>
            </defs>
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
              domain={anyNegative ? ['auto', 'auto'] : [0, 'auto']}
              tickFormatter={tickFormatter}
            />
            <Tooltip content={<NetWorthTooltip />} />
            <Area
              type="monotone"
              dataKey="netWorth"
              stroke="var(--color-teal)"
              strokeWidth={2.2}
              fill="url(#nwFill)"
              dot={{ r: 3, fill: 'var(--color-teal)' }}
              // A month nobody recorded is a gap in the record, not a value to
              // interpolate through — the same rule ProgressChart follows.
              connectNulls={false}
            />
          </AreaChart>
        </ResponsiveContainer>
      </div>

      <p className="mt-2 text-[11px] leading-relaxed text-[var(--color-ink-3)]">
        Recorded from what you've entered in your portfolio, once a month. It starts when
        tracking starts — there's no history from before that.
      </p>
    </Card>
  );
}

function NetWorthTooltip({ active, payload, label }) {
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
      <div className="mb-1 font-semibold text-[var(--color-ink)]">{label}</div>
      <Row label="Worth" value={formatCurrency(row.netWorth)} />
      <Row label="You own" value={formatCurrency(row.assets)} />
      <Row label="You owe" value={formatCurrency(row.liabilities)} />
      <div className="mt-1 text-[10px] text-[var(--color-ink-3)]">
        {row.automatic ? 'Recorded automatically' : 'Recorded by you'}
      </div>
    </div>
  );
}

function Row({ label, value }) {
  return (
    <div className="flex items-center justify-between gap-4">
      <span className="text-[var(--color-ink-2)]">{label}</span>
      <span className="font-medium tabular-nums text-[var(--color-ink)]">{value}</span>
    </div>
  );
}
