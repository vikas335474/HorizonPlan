// Route /product-analytics — platform-wide product usage, super_admin-only.
// The missing consumer for `product_events` (sql/040, docs/13 §5): nothing
// read this table until now. NOT the same page as /practice
// (PracticeAnalytics.jsx / firm_analytics.php), which is one firm's own book
// — this is cross-tenant: the self-serve onboarding funnel, activation,
// retention, feature adoption by tenant kind, and tenant growth.
//
// Data: api.getProductAnalytics() (product_analytics.php), server-gated to
// super_admin. Read-only, counts and ratios only — same "never a grade or a
// target" convention PracticeAnalytics.jsx already set.
//
// Several funnel/activation rows may read zero: docs/13 §5 named ten events
// on day one, but only five are wired into the frontend today (see
// frontend/src/lib/api.js's logEvent call sites). A zero here is an honest
// "not instrumented yet", not a bug in this page.

import { useEffect, useState } from 'react';
import { Navigate } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import AppHeader from '../components/AppHeader';
import { Card, Spinner } from '../components/ui';

const ACTIVATION_LABELS = {
  first_goal_opened: 'Opened a goal',
  lever_previewed: 'Previewed a gap-closing lever',
  plan_edited: 'Edited their plan',
};

const FUNNEL_LABELS = {
  personal_signup_started: 'Started signup',
  onboarding_question_answered: 'Answered an onboarding question',
  onboarding_risk_chosen: 'Chose a risk band',
  plan_created: 'Created a plan',
};

const FEATURE_LABELS = {
  goal_created: 'Created a goal',
  portfolio_entered: 'Entered a portfolio holding',
  cash_flow_entered: 'Entered cash flow',
  foundations_entered: 'Recorded a financial foundation',
  risk_profiled: 'Completed a risk profile',
};

export default function ProductAnalytics() {
  const { user } = useAuth();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (user && user.role !== 'super_admin') { setLoading(false); return; }
    api
      .getProductAnalytics()
      .then(setData)
      .catch((err) => setError(err.message || 'Could not load product analytics.'))
      .finally(() => setLoading(false));
  }, [user]);

  if (user && user.role !== 'super_admin') return <Navigate to="/" replace />;

  return (
    <div className="min-h-screen">
      <AppHeader />

      <div className="border-b border-[var(--color-line)] bg-[var(--color-surface)]">
        <div className="mx-auto max-w-6xl px-5 py-6">
          <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">Product analytics</h1>
          <p className="mt-1 text-sm text-[var(--color-ink-2)]">
            Platform-wide usage across every tenant — not one firm's book. Counts only, never a target.
          </p>
        </div>
      </div>

      <main className="mx-auto max-w-6xl px-5 py-6">
        {loading && <Spinner label="Loading product analytics…" />}

        {error && !loading && (
          <Card className="p-4 border-[var(--color-alert)]">
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
          </Card>
        )}

        {data && !loading && (
          <>
            {/* Platform totals */}
            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
              <Stat label="Tenants" value={data.totals.tenants} />
              <Stat label="Firm tenants" value={data.totals.firm_tenants} />
              <Stat label="Personal tenants" value={data.totals.personal_tenants} />
              <Stat label="Users" value={data.totals.users} />
              <Stat label="Users with events" value={data.totals.event_users} highlight />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
              {/* Onboarding funnel */}
              <Card className="p-4">
                <h2 className="text-sm font-semibold text-[var(--color-ink)] mb-1">Onboarding funnel</h2>
                <p className="text-xs text-[var(--color-ink-2)] mb-3">
                  Distinct users at each step, as a share of the first step.
                </p>
                <ul className="flex flex-col gap-2">
                  {data.onboarding_funnel.map((step) => (
                    <li key={step.event_name} className="flex items-center gap-3">
                      <span className="w-48 shrink-0 text-xs text-[var(--color-ink-2)]">
                        {FUNNEL_LABELS[step.event_name] || step.event_name}
                      </span>
                      <span className="h-2 flex-1 rounded-full bg-[var(--color-line)] overflow-hidden">
                        <span
                          className="block h-full rounded-full"
                          style={{ width: `${step.pct_of_first_step ?? 0}%`, backgroundColor: 'var(--color-teal)' }}
                        />
                      </span>
                      <span className="w-24 shrink-0 text-right text-xs font-semibold tabular-nums text-[var(--color-ink)]">
                        {step.users} {step.pct_of_first_step !== null && <>({step.pct_of_first_step}%)</>}
                      </span>
                    </li>
                  ))}
                </ul>
              </Card>

              {/* Activation */}
              <Card className="p-4">
                <h2 className="text-sm font-semibold text-[var(--color-ink)] mb-1">Activation</h2>
                <p className="text-xs text-[var(--color-ink-2)] mb-3">
                  Of {data.activation.planned_users} users who created a plan, share who also did each of these
                  (independent, not a sequence).
                </p>
                <ul className="flex flex-col gap-2">
                  {data.activation.rates.map((r) => (
                    <li key={r.event_name} className="flex items-center justify-between gap-3">
                      <span className="text-xs text-[var(--color-ink-2)]">
                        {ACTIVATION_LABELS[r.event_name] || r.event_name}
                      </span>
                      <span className="text-sm font-semibold tabular-nums text-[var(--color-ink)]">
                        {r.users}{r.pct_of_planners !== null && <> ({r.pct_of_planners}%)</>}
                      </span>
                    </li>
                  ))}
                </ul>
              </Card>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
              {/* Retention */}
              <Card className="p-4">
                <h2 className="text-sm font-semibold text-[var(--color-ink)] mb-1">Returning users</h2>
                <p className="text-xs text-[var(--color-ink-2)] mb-3">
                  Share of everyone with at least one event who was active on more than one distinct day.
                </p>
                <div className="flex items-baseline gap-2">
                  <span className="text-2xl font-semibold tabular-nums text-[var(--color-ink)]">
                    {data.retention.pct_returning !== null ? `${data.retention.pct_returning}%` : '—'}
                  </span>
                  <span className="text-xs text-[var(--color-ink-2)]">
                    {data.retention.returning_users} of {data.retention.total_users} users
                  </span>
                </div>
              </Card>

              {/* Weekly active users, last 90 days */}
              <Card className="p-4">
                <h2 className="text-sm font-semibold text-[var(--color-ink)] mb-1">Weekly active users</h2>
                <p className="text-xs text-[var(--color-ink-2)] mb-3">Last 90 days.</p>
                {data.weekly_active_users.length === 0 ? (
                  <p className="text-xs text-[var(--color-ink-3)]">No events in the last 90 days.</p>
                ) : (
                  <WeeklyBars weeks={data.weekly_active_users} />
                )}
              </Card>
            </div>

            {/* Feature adoption by tenant kind */}
            <Card className="overflow-hidden mb-5">
              <div className="p-4 border-b border-[var(--color-line)]">
                <h2 className="text-sm font-semibold text-[var(--color-ink)]">Feature adoption by tenant kind</h2>
                <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">
                  Share of tenants of each kind that have ever recorded at least one row for this feature.
                </p>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-[var(--color-line)] text-[11px] uppercase tracking-wider text-[var(--color-ink-3)]">
                      <th className="px-4 py-2.5 text-left font-semibold">Feature</th>
                      <th className="px-4 py-2.5 text-right font-semibold">Firm tenants</th>
                      <th className="px-4 py-2.5 text-right font-semibold">Personal tenants</th>
                    </tr>
                  </thead>
                  <tbody>
                    {Object.entries(data.feature_adoption).map(([feature, byKind]) => (
                      <tr key={feature} className="border-b border-[var(--color-line)] last:border-b-0">
                        <td className="px-4 py-3 text-[var(--color-ink)]">{FEATURE_LABELS[feature] || feature}</td>
                        <td className="px-4 py-3 text-right tabular-nums">
                          {byKind.firm.tenants}{byKind.firm.pct !== null && <> ({byKind.firm.pct}%)</>}
                        </td>
                        <td className="px-4 py-3 text-right tabular-nums">
                          {byKind.personal.tenants}{byKind.personal.pct !== null && <> ({byKind.personal.pct}%)</>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>

            {/* Tenant growth */}
            <Card className="overflow-hidden">
              <div className="p-4 border-b border-[var(--color-line)]">
                <h2 className="text-sm font-semibold text-[var(--color-ink)]">Tenant growth</h2>
                <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">New tenants per month, by kind.</p>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-[var(--color-line)] text-[11px] uppercase tracking-wider text-[var(--color-ink-3)]">
                      <th className="px-4 py-2.5 text-left font-semibold">Month</th>
                      <th className="px-4 py-2.5 text-right font-semibold">Firm</th>
                      <th className="px-4 py-2.5 text-right font-semibold">Personal</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.tenant_growth.length === 0 && (
                      <tr><td colSpan={3} className="px-4 py-3 text-xs text-[var(--color-ink-3)]">No tenants yet.</td></tr>
                    )}
                    {data.tenant_growth.map((row) => (
                      <tr key={row.month} className="border-b border-[var(--color-line)] last:border-b-0">
                        <td className="px-4 py-3 text-[var(--color-ink)]">{row.month}</td>
                        <td className="px-4 py-3 text-right tabular-nums">{row.firm}</td>
                        <td className="px-4 py-3 text-right tabular-nums">{row.personal}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          </>
        )}
      </main>
    </div>
  );
}

function Stat({ label, value, highlight = false }) {
  return (
    <Card className="p-3.5" elevated={!highlight}>
      <div className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">{label}</div>
      <div className={`mt-1 text-xl font-semibold tabular-nums ${highlight ? 'text-[var(--color-teal-ink)]' : 'text-[var(--color-ink)]'}`}>
        {value}
      </div>
    </Card>
  );
}

function WeeklyBars({ weeks }) {
  const max = Math.max(...weeks.map((w) => w.users), 1);
  return (
    <div className="flex items-end gap-1 h-20">
      {weeks.map((w) => (
        <div key={w.week} className="flex-1 flex flex-col items-center gap-1" title={`${w.week}: ${w.users} users`}>
          <div
            className="w-full rounded-t"
            style={{
              height: `${Math.max((w.users / max) * 100, 4)}%`,
              backgroundColor: 'var(--color-teal)',
              minHeight: '2px',
            }}
          />
        </div>
      ))}
    </div>
  );
}
