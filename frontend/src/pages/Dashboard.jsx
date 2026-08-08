// Route / for advisor / super_admin sessions (Home() redirects a client to
// /goals). The advisor's working view of their book:
//
//   * a persona-aware heading + default scope (see PERSONA below) — a junior
//     advisor lands on "my clients", a firm admin on the whole practice;
//   * the attention queue promoted to a banner with the firm-wide count,
//     rather than hidden behind a filter checkbox;
//   * a book-health bar (readiness spread across every client, not the page);
//   * the roster itself — scope tabs, search, sort, per-row advisor
//     assignment, and pagination, ALL server-side via api.listClients();
//   * the first-run onboarding checklist / spotlight, and add-client.
//
// Data: api.listClients() (paged rows + firm-wide stats + the firm's advisor
// list) and api.assignClientAdvisor(); useAuth for identity and firmRole.
// Assignment here is attribution only — it never changes who can read a
// client (see migration 031 / client_assign_advisor.php).

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import AppHeader from '../components/AppHeader';
import Modal from '../components/Modal';
import { Card, Button, Badge } from '../components/ui';
import { ReadinessScoreBadge } from '../components/ReadinessScore';
import OnboardingChecklist, { isOnboardingDismissed, dismissOnboarding } from '../components/OnboardingChecklist';
import OnboardingSpotlight from '../components/OnboardingSpotlight';
import { formatCurrencyCompact, formatCurrency, formatDate } from '../lib/format';

// docs/08 gap #5 — "which clients actually need the advisor's attention":
// no goals yet, no risk profile on file, or a worst-goal readiness score in
// ReadinessScore.jsx's "Needs attention" band (< 40).
//
// That rule used to live here and run in the browser over the whole client
// list. It now lives in clients_list.php, which owns both the firm-wide
// attention_count and the server-side attention filter/sort — the list is
// paginated, so a browser-side rule could only ever see the current page and
// would have quietly disagreed with the total shown above it. One definition,
// on the server, is the only version that stays true.

const SORT_OPTIONS = [
  { value: 'recent', label: 'Recently added' },
  { value: 'attention', label: 'Needs attention first' },
  { value: 'readiness', label: 'Lowest readiness first' },
  { value: 'corpus', label: 'Highest corpus first' },
];

const PER_PAGE = 25;

// What each advisor tier lands on. The data is identical — assignment is
// attribution, not access control, and every advisor can still reach every
// client in the firm — but the DEFAULT view differs, because the question
// each role opens the app to answer differs. A jr_advisor wants "what's on my
// desk"; a principal wants "how is the practice doing". Any advisor can switch
// scope; this only picks where they start.
const PERSONA = {
  jr_advisor: {
    title: 'My clients',
    subtitle: 'The clients assigned to you, and what needs your attention first.',
    defaultScope: 'mine',
  },
  sr_advisor: {
    title: 'Your book',
    subtitle: 'Clients you look after, plus the firm\'s wider book.',
    defaultScope: 'mine',
  },
  firm_admin: {
    title: 'The firm\'s book',
    subtitle: 'Every client across the practice, and how the work is distributed.',
    defaultScope: 'all',
  },
};
const DEFAULT_PERSONA = {
  title: 'Your book',
  subtitle: 'Clients, goals, and corpus under plan across your firm.',
  defaultScope: 'all',
};

export default function Dashboard() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [query, setQuery] = useState('');
  const [attentionOnly, setAttentionOnly] = useState(false);
  const [sortBy, setSortBy] = useState('recent');
  const [addOpen, setAddOpen] = useState(false);
  // docs/09 Group 3 Session 5 — re-read on every render rather than cached in
  // state: the only way it changes is dismissOnboarding() below, and this is
  // cheap enough (one localStorage read) not to need memoizing.
  const [dismissed, setDismissed] = useState(() => isOnboardingDismissed(user?.userId));

  const persona = PERSONA[user?.firmRole] ?? DEFAULT_PERSONA;
  const canSeeFirmAnalytics = user?.role === 'super_admin'
    || user?.firmRole === 'sr_advisor' || user?.firmRole === 'firm_admin'
    // firm_role is NULL for every advisor created before migration 020, and
    // requireFirmRole() reads that as sr_advisor — mirror it here so those
    // accounts don't lose a link the server would happily serve them.
    || (user?.role === 'advisor' && !user?.firmRole);

  const [scope, setScope] = useState(persona.defaultScope);
  const [page, setPage] = useState(1);
  // Debounced copy of `query` — the search box stays instant to type in, but
  // only settles into a request 300ms after the last keystroke. Without this
  // every character would be its own round trip now that filtering is
  // server-side.
  const [debouncedQuery, setDebouncedQuery] = useState('');
  useEffect(() => {
    const t = setTimeout(() => setDebouncedQuery(query), 300);
    return () => clearTimeout(t);
  }, [query]);

  // Any change to a filter resets to page 1 — staying on page 4 of a result
  // set that just shrank to one page would show an empty table.
  useEffect(() => { setPage(1); }, [debouncedQuery, scope, attentionOnly, sortBy]);

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    api
      .listClients({ page, perPage: PER_PAGE, q: debouncedQuery, scope, attentionOnly, sort: sortBy })
      .then(setData)
      .catch((err) => setError(err.message || 'Could not load your clients.'))
      .finally(() => setLoading(false));
  }, [page, debouncedQuery, scope, attentionOnly, sortBy]);

  useEffect(() => { load(); }, [load]);

  // Memoized so its reference is stable across renders where `data` hasn't
  // changed — otherwise `data?.clients ?? []` mints a fresh array on every
  // render while `data` is still null (the loading phase).
  const clients = useMemo(() => data?.clients ?? [], [data]);
  const advisors = useMemo(() => data?.advisors ?? [], [data]);
  const total = data?.total ?? 0;
  const pageCount = Math.max(1, Math.ceil(total / PER_PAGE));

  // Assignment is a fire-and-reload action: the row's own advisor cell is the
  // only thing that changes, but the firm-wide per-advisor counts behind the
  // scope tabs change too, so a reload is both simpler and more honest than
  // patching one row in place.
  const [assigningId, setAssigningId] = useState(null);
  async function handleAssign(clientId, advisorId) {
    setAssigningId(clientId);
    try {
      await api.assignClientAdvisor(clientId, advisorId === '' ? null : advisorId);
      load();
    } catch (err) {
      setError(err.message || 'Could not update the assignment.');
    } finally {
      setAssigningId(null);
    }
  }

  return (
    <div className="min-h-screen">
      <AppHeader />

      {/* Page header band */}
      <div className="border-b border-[var(--color-line)] bg-[var(--color-surface)]">
        <div className="mx-auto max-w-6xl px-5 py-6 flex items-end justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">
              {persona.title}
            </h1>
            <p className="mt-1 text-sm text-[var(--color-ink-2)]">
              {persona.subtitle}
            </p>
          </div>
          <div className="flex items-center gap-2">
          {/* Practice analytics is the firm-level counterpart to this page:
              this one is the book, that one is how the practice is running.
              Gated to the same roles the endpoint enforces (requireFirmRole
              sr_advisor/firm_admin), so a jr_advisor never sees a link that
              would 403 — the server remains the real gate either way. */}
          {canSeeFirmAnalytics && (
            <Link
              to="/practice"
              className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--color-line-2)] px-3 py-2 text-sm font-medium text-[var(--color-ink-2)] hover:bg-[var(--color-surface-2)] transition-colors"
            >
              Practice analytics
            </Link>
          )}
          <Button data-tour="onboarding-add-client" onClick={() => setAddOpen(true)}>
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
              <path d="M8 3.5v9M3.5 8h9" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
            </svg>
            Add client
          </Button>
          </div>
        </div>
      </div>

      <main className="mx-auto max-w-6xl px-5 py-6">
        {loading && <DashboardSkeleton />}

        {error && !loading && (
          <Card className="p-4 border-[var(--color-alert)]">
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
          </Card>
        )}

        {data && !loading && (
          <>
            {/* docs/09 Group 3 Session 5 — first-run guidance. Shown until the
                advisor has at least one client AND at least one goal, or
                until they dismiss it early, whichever comes first; a client
                list of zero clients (or clients with zero goals between
                them) IS the first-run signal, no has_completed_onboarding
                column needed. */}
            {!dismissed && !(data.stats.total_clients > 0 && data.stats.total_goals > 0) && (
              <>
                <OnboardingChecklist
                  clients={clients}
                  onAddClient={() => setAddOpen(true)}
                  onGoToClient={(clientId) => navigate(`/clients/${clientId}`)}
                  onDismiss={() => { dismissOnboarding(user?.userId); setDismissed(true); }}
                />
                {/* Sits alongside the checklist, not in place of it — the
                    checklist tracks what's left, this points at the one
                    real button to click next. */}
                <OnboardingSpotlight
                  step={data.stats.total_clients === 0 ? 'client' : 'goal'}
                  onDismiss={() => { dismissOnboarding(user?.userId); setDismissed(true); }}
                />
              </>
            )}

            {/* Stat band. These are FIRM-WIDE totals and stay firm-wide
                whatever scope/page the roster below is showing — a headline
                number that shifted when you switched tab or turned a page
                would be worse than useless. That does mean a junior advisor
                sees "40 clients" above a list of their own 8, so the band
                says plainly which set it is counting rather than leaving the
                mismatch to be puzzled out. */}
            <p className="mb-2 text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">
              Across the whole firm
            </p>
            <div className="stagger-children grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
              <MetricCard
                label="Clients"
                value={data.stats.total_clients}
                icon="users"
              />
              <MetricCard
                label="Goals tracked"
                value={data.stats.total_goals}
                icon="target"
              />
              <MetricCard
                label="Corpus under plan"
                value={formatCurrencyCompact(data.stats.total_aum)}
                sublabel="Sum of starting corpus across goals"
                icon="corpus"
                highlight
              />
            </div>

            {/* The attention queue, promoted out of a checkbox.
                "Which clients need me today" is the single most valuable
                thing this page knows, and it used to be reachable only by
                ticking a filter nobody sees. Now it leads — with the firm-wide
                count from the server (never just this page's slice) and a
                one-click jump into the filtered, worst-first list. */}
            {data.stats.attention_count > 0 && (
              <Card className="p-4 mb-4 flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-start gap-3 min-w-0">
                  <span
                    className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                    style={{ backgroundColor: 'var(--color-amber-soft)' }}
                    aria-hidden="true"
                  >
                    <svg width="17" height="17" viewBox="0 0 16 16" fill="none">
                      <path d="M8 5v3.5M8 11h.01" stroke="var(--color-amber)" strokeWidth="1.7" strokeLinecap="round" />
                      <circle cx="8" cy="8" r="6.2" stroke="var(--color-amber)" strokeWidth="1.4" />
                    </svg>
                  </span>
                  <div className="min-w-0">
                    <h2 className="text-sm font-semibold text-[var(--color-ink)]">
                      {data.stats.attention_count} {data.stats.attention_count === 1 ? 'client needs' : 'clients need'} attention
                    </h2>
                    <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">
                      No goals yet, no risk profile on file, or a readiness score below 40.
                    </p>
                  </div>
                </div>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => { setAttentionOnly(true); setSortBy('readiness'); }}
                >
                  Review them
                </Button>
              </Card>
            )}

            {/* Book health at a glance — the readiness spread across the whole
                firm, as one bar rather than a number the advisor has to build
                in their head by scrolling the roster. Counts come from the
                server and cover every client, not the current page. */}
            {data.stats.distribution && data.stats.total_clients > 0 && (
              <ReadinessDistributionBar
                distribution={data.stats.distribution}
                total={data.stats.total_clients}
              />
            )}

            {clients.length === 0 ? (
              <Card className="py-16">
                <div className="flex flex-col items-center text-center px-6">
                  <div className="mb-4 h-14 w-14 rounded-2xl flex items-center justify-center"
                       style={{ background: 'linear-gradient(135deg, var(--color-teal-soft), #d5eae5)' }}>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                      <circle cx="9" cy="8" r="3.2" stroke="var(--color-teal-ink)" strokeWidth="1.6" />
                      <path d="M3.5 19c0-3 2.5-5 5.5-5s5.5 2 5.5 5" stroke="var(--color-teal-ink)" strokeWidth="1.6" strokeLinecap="round" />
                      <path d="M17 8v5M19.5 10.5h-5" stroke="var(--color-teal-ink)" strokeWidth="1.6" strokeLinecap="round" />
                    </svg>
                  </div>
                  <h3 className="text-base font-semibold text-[var(--color-ink)]">Add your first client</h3>
                  <p className="mt-1.5 max-w-sm text-sm text-[var(--color-ink-2)]">
                    Onboard a client to start building retirement and savings goals with them.
                  </p>
                  <div className="mt-5">
                    <Button onClick={() => setAddOpen(true)}>Add client</Button>
                  </div>
                </div>
              </Card>
            ) : (
              <Card className="overflow-hidden">
                {/* Scope tabs. Purely a filter over data every advisor can
                    already read — never a permission boundary (see
                    migration 031). "Unassigned" is here because an unowned
                    client is the one thing a firm most wants to notice. */}
                <div className="flex items-center gap-1 p-2 border-b border-[var(--color-line)] overflow-x-auto">
                  {[
                    { value: 'mine', label: 'My clients' },
                    { value: 'all', label: 'Whole firm' },
                    { value: 'unassigned', label: 'Unassigned' },
                  ].map((t) => (
                    <button
                      key={t.value}
                      onClick={() => setScope(t.value)}
                      aria-pressed={scope === t.value}
                      className={`shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
                        scope === t.value
                          ? 'bg-[var(--color-teal-soft)] text-[var(--color-teal-ink)]'
                          : 'text-[var(--color-ink-2)] hover:bg-[var(--color-surface-2)]'
                      }`}
                    >
                      {t.label}
                    </button>
                  ))}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 p-3.5 border-b border-[var(--color-line)]">
                  <div className="flex flex-wrap items-center gap-2.5">
                    <div className="relative">
                      <svg width="16" height="16" viewBox="0 0 16 16" fill="none"
                           className="absolute left-3 top-1/2 -translate-y-1/2" aria-hidden="true">
                        <circle cx="7" cy="7" r="4.5" stroke="var(--color-ink-3)" strokeWidth="1.4" />
                        <path d="M10.5 10.5L14 14" stroke="var(--color-ink-3)" strokeWidth="1.4" strokeLinecap="round" />
                      </svg>
                      <input
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search clients"
                        className="field pl-9 w-full sm:w-56"
                      />
                    </div>
                    <select
                      value={sortBy}
                      onChange={(e) => setSortBy(e.target.value)}
                      className="field w-auto py-1.5 text-sm"
                      aria-label="Sort clients"
                    >
                      {SORT_OPTIONS.map((o) => (
                        <option key={o.value} value={o.value}>{o.label}</option>
                      ))}
                    </select>
                    <label
                      className="flex items-center gap-1.5 text-sm text-[var(--color-ink-2)] cursor-pointer select-none"
                      data-tour="dashboard-attention-filter"
                    >
                      <input
                        type="checkbox"
                        checked={attentionOnly}
                        onChange={(e) => setAttentionOnly(e.target.checked)}
                      />
                      Needs attention only
                    </label>
                  </div>
                  <span className="text-xs text-[var(--color-ink-3)] tabular-nums">
                    {total === 0 ? '0' : `${(page - 1) * PER_PAGE + 1}–${Math.min(page * PER_PAGE, total)} of ${total}`}
                  </span>
                </div>

                <div className="hidden md:grid grid-cols-12 gap-4 px-4 py-2.5 border-b border-[var(--color-line)] text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-3)]">
                  <div className="col-span-5">Client</div>
                  <div className="col-span-3">Advisor</div>
                  <div className="col-span-1 text-right">Goals</div>
                  <div className="col-span-3 text-right">Tracked corpus</div>
                </div>

                <ul>
                  {/* The row carries an interactive <select> (assign advisor)
                      as well as being clickable itself, so it can't stay a
                      single <button> — a form control nested in a button is
                      invalid and swallows its own clicks. Instead the row is a
                      grid whose first child is a stretched overlay Link; the
                      read-only cells sit above it with pointer-events-none so
                      clicks fall through to the link, while the select opts
                      back in to receiving its own. */}
                  {clients.map((c) => (
                    <li
                      key={c.client_id}
                      data-tour="dashboard-client-row"
                      data-client-id={c.client_id}
                      data-goal-count={c.goal_count}
                      className="group relative grid grid-cols-12 gap-4 px-4 py-3.5 items-center border-b border-[var(--color-line)] last:border-b-0 hover:bg-[var(--color-surface-2)] transition-colors"
                    >
                      <Link
                        to={`/clients/${c.client_id}`}
                        className="absolute inset-0"
                        aria-label={`Open ${c.email}`}
                      />
                      <div className="pointer-events-none relative col-span-12 md:col-span-5 flex items-center gap-3 min-w-0">
                        <Avatar email={c.email} />
                        <div className="min-w-0">
                          <div className="truncate text-sm font-medium text-[var(--color-ink)]">{c.email}</div>
                          <div className="mt-1 flex flex-wrap items-center gap-1.5" data-tour-badges="true">
                            <span className="text-xs text-[var(--color-ink-3)]">Client since {formatDate(c.client_since)}</span>
                            <ClientHealthBadges client={c} />
                          </div>
                        </div>
                      </div>

                      <div className="relative col-span-12 md:col-span-3">
                        <select
                          value={c.assigned_advisor_id ?? ''}
                          disabled={assigningId === c.client_id}
                          onChange={(e) => handleAssign(c.client_id, e.target.value === '' ? null : Number(e.target.value))}
                          onClick={(e) => e.stopPropagation()}
                          aria-label={`Advisor assigned to ${c.email}`}
                          className={`field w-full py-1 text-xs ${c.assigned_advisor_id === null ? 'text-[var(--color-ink-3)]' : ''}`}
                        >
                          <option value="">Unassigned</option>
                          {advisors.map((a) => (
                            <option key={a.user_id} value={a.user_id}>{a.email}</option>
                          ))}
                        </select>
                      </div>

                      <div className="pointer-events-none relative col-span-6 md:col-span-1 md:text-right">
                        <span className="tnum text-sm text-[var(--color-ink)]">{c.goal_count}</span>
                        <span className="md:hidden text-xs text-[var(--color-ink-3)]"> goals</span>
                      </div>
                      <div className="pointer-events-none relative col-span-6 md:col-span-3 flex items-center justify-end gap-2">
                        <span className="tnum text-sm text-[var(--color-ink)]">{formatCurrency(c.total_net_worth)}</span>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"
                             className="text-[var(--color-ink-3)] opacity-0 group-hover:opacity-100 transition-opacity" aria-hidden="true">
                          <path d="M6 4l4 4-4 4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                        </svg>
                      </div>
                    </li>
                  ))}
                  {clients.length === 0 && (
                    <li className="px-4 py-10 text-center text-sm text-[var(--color-ink-3)]">
                      {query
                        ? `No clients match "${query}".`
                        : scope === 'mine'
                          ? 'No clients are assigned to you yet — switch to "Whole firm" to pick some up.'
                          : scope === 'unassigned'
                            ? 'Every client has an advisor assigned.'
                            : 'No clients match these filters.'}
                    </li>
                  )}
                </ul>

                {/* Pager. The roster used to render every client in the tenant
                    in one unbounded list — fine at 40, a heavy payload and a
                    multi-thousand-row DOM for a real book (docs/10 §4: tools
                    that stop fitting "past a few hundred clients"). */}
                {pageCount > 1 && (
                  <div className="flex items-center justify-between gap-3 p-3 border-t border-[var(--color-line)]">
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={page <= 1}
                      onClick={() => setPage((p) => Math.max(1, p - 1))}
                    >
                      Previous
                    </Button>
                    <span className="text-xs text-[var(--color-ink-3)] tabular-nums">
                      Page {page} of {pageCount}
                    </span>
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={page >= pageCount}
                      onClick={() => setPage((p) => Math.min(pageCount, p + 1))}
                    >
                      Next
                    </Button>
                  </div>
                )}
              </Card>
            )}
          </>
        )}
      </main>

      <AddClientModal
        open={addOpen}
        onClose={() => setAddOpen(false)}
        onCreated={() => { setAddOpen(false); load(); }}
      />
    </div>
  );
}

// The firm's readiness spread as a single stacked bar. Buckets mirror
// ReadinessScore.jsx's own bands (and clients_list.php's server-side copy) so
// a segment labelled "at risk" is exactly the set badged "Needs attention" on
// the rows below — one definition, three places, no third cutoff invented here.
//
// "Not scored" is shown rather than hidden: a client with no projectable
// retirement goal is a real state a principal should see, not an absence to
// round away. Segments below ~4% still render at 4% width so a small-but-
// nonzero bucket can't visually vanish; the legend carries the true counts.
function ReadinessDistributionBar({ distribution, total }) {
  const segments = [
    { key: 'strong', label: 'Strong', color: 'var(--color-teal)' },
    { key: 'fair', label: 'Fair', color: 'var(--color-amber)' },
    { key: 'at_risk', label: 'At risk', color: 'var(--color-alert)' },
    { key: 'unscored', label: 'Not scored', color: 'var(--color-line-2)' },
  ].filter((s) => (distribution[s.key] ?? 0) > 0);

  if (total === 0 || segments.length === 0) return null;

  return (
    <Card className="p-4 mb-4">
      <div className="flex items-baseline justify-between gap-3 mb-2.5">
        <h2 className="text-sm font-semibold text-[var(--color-ink)]">Book health</h2>
        <span className="text-xs text-[var(--color-ink-3)]">
          Lowest readiness per client, across {total} {total === 1 ? 'client' : 'clients'}
        </span>
      </div>
      <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-[var(--color-line)]" role="img"
           aria-label={segments.map((s) => `${distribution[s.key]} ${s.label}`).join(', ')}>
        {segments.map((s) => (
          <div
            key={s.key}
            style={{ width: `${Math.max(4, (distribution[s.key] / total) * 100)}%`, backgroundColor: s.color }}
            title={`${s.label}: ${distribution[s.key]}`}
          />
        ))}
      </div>
      <div className="mt-2.5 flex flex-wrap gap-x-4 gap-y-1">
        {segments.map((s) => (
          <span key={s.key} className="inline-flex items-center gap-1.5 text-xs text-[var(--color-ink-2)]">
            <span className="h-2 w-2 rounded-full" style={{ backgroundColor: s.color }} aria-hidden="true" />
            {s.label}
            <span className="tabular-nums font-semibold text-[var(--color-ink)]">{distribution[s.key]}</span>
          </span>
        ))}
      </div>
    </Card>
  );
}

// At-a-glance health signal on each client row: risk-profile band, the
// client's worst-goal readiness score, and a "No goals yet" flag — the
// concrete gap docs/08 named ("at-a-glance client status"). Deliberately
// terse (small badges, not the full ReadinessScoreCard) since this renders
// once per row in a list, not as the page's own subject.
function ClientHealthBadges({ client }) {
  if (client.goal_count === 0) {
    return <Badge fg="var(--color-ink-3)" bg="var(--color-surface-2)">No goals yet</Badge>;
  }
  return (
    <>
      {client.min_readiness_score !== null && <ReadinessScoreBadge score={client.min_readiness_score} />}
      {client.risk_band !== null ? (
        <Badge fg="var(--color-teal-ink)" bg="var(--color-teal-soft)">{client.risk_band}</Badge>
      ) : (
        <Badge fg="var(--color-ink-3)" bg="var(--color-surface-2)">Not risk-assessed</Badge>
      )}
    </>
  );
}

function MetricCard({ label, value, sublabel, icon, highlight }) {
  return (
    <div
      className="rounded-[var(--radius-card)] border p-5 relative overflow-hidden"
      style={{
        borderColor: highlight ? 'transparent' : 'var(--color-line)',
        background: highlight
          ? 'linear-gradient(135deg, var(--color-ink) 0%, #1c2a47 100%)'
          : 'var(--color-surface)',
      }}
    >
      <div className="flex items-start justify-between">
        <div className="text-[11px] font-semibold uppercase tracking-wider"
             style={{ color: highlight ? 'rgba(255,255,255,0.6)' : 'var(--color-ink-3)' }}>
          {label}
        </div>
        <MetricIcon icon={icon} highlight={highlight} />
      </div>
      <div className="tnum mt-3 text-3xl font-semibold"
           style={{ color: highlight ? '#fff' : 'var(--color-ink)' }}>
        {value}
      </div>
      {sublabel && (
        <div className="mt-1 text-xs"
             style={{ color: highlight ? 'rgba(255,255,255,0.55)' : 'var(--color-ink-3)' }}>
          {sublabel}
        </div>
      )}
    </div>
  );
}

function MetricIcon({ icon, highlight }) {
  const stroke = highlight ? 'rgba(255,255,255,0.5)' : 'var(--color-teal)';
  const paths = {
    users: <><circle cx="7" cy="6" r="2.5" stroke={stroke} strokeWidth="1.5" /><path d="M2.5 15c0-2.5 2-4 4.5-4s4.5 1.5 4.5 4" stroke={stroke} strokeWidth="1.5" strokeLinecap="round" /></>,
    target: <><circle cx="9" cy="9" r="6.5" stroke={stroke} strokeWidth="1.5" /><circle cx="9" cy="9" r="2.5" stroke={stroke} strokeWidth="1.5" /></>,
    corpus: <><path d="M3 14V8M7 14V5M11 14v-4M15 14V7" stroke={stroke} strokeWidth="1.6" strokeLinecap="round" /></>,
  };
  return <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">{paths[icon]}</svg>;
}

function Avatar({ email }) {
  // Deterministic hue from email so each client keeps a stable color
  let hash = 0;
  for (let i = 0; i < email.length; i++) hash = email.charCodeAt(i) + ((hash << 5) - hash);
  const hue = Math.abs(hash) % 360;
  return (
    <span
      className="shrink-0 h-9 w-9 rounded-full flex items-center justify-center text-xs font-semibold"
      style={{ backgroundColor: `hsl(${hue} 45% 92%)`, color: `hsl(${hue} 55% 32%)` }}
    >
      {email.slice(0, 2).toUpperCase()}
    </span>
  );
}

function DashboardSkeleton() {
  return (
    <>
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
        {[0, 1, 2].map((i) => <div key={i} className="skeleton h-[104px]" />)}
      </div>
      <div className="skeleton h-[300px]" />
    </>
  );
}

function AddClientModal({ open, onClose, onCreated }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [created, setCreated] = useState(null);

  function reset() {
    setEmail(''); setPassword(''); setError(''); setCreated(null);
  }

  function generatePassword() {
    // Readable temporary password the advisor can share verbally
    const words = ['plan', 'goal', 'save', 'grow', 'fund', 'nest'];
    const w = words[Math.floor(Math.random() * words.length)];
    const n = Math.floor(1000 + Math.random() * 9000);
    setPassword(`${w.charAt(0).toUpperCase() + w.slice(1)}-${n}-horizon`);
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setSubmitting(true);
    try {
      const res = await api.createClient(email.trim(), password);
      setCreated({ email: res.email, password });
    } catch (err) {
      setError(err.message || 'Could not add this client.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={() => { onClose(); setTimeout(reset, 200); }}
      title={created ? 'Client added' : 'Add a client'}
      description={created ? null : 'Create a login for your client. Share the temporary password with them — they can change it later.'}
    >
      {created ? (
        <div>
          <div className="rounded-[var(--radius-ctrl)] bg-[var(--color-teal-soft)] p-4 mb-4">
            <p className="text-sm text-[var(--color-teal-ink)]">
              <strong>{created.email}</strong> can now sign in. Share these credentials securely:
            </p>
            <div className="mt-3 space-y-2">
              <CredRow label="Email" value={created.email} />
              <CredRow label="Temporary password" value={created.password} mono />
            </div>
          </div>
          <div className="flex gap-2">
            <Button onClick={() => { onCreated(); reset(); }} className="flex-1">Done</Button>
            <Button variant="outline" onClick={reset}>Add another</Button>
          </div>
        </div>
      ) : (
        <form onSubmit={handleSubmit}>
          <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Client email</label>
          <input
            type="email" required autoFocus value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder="client@example.com"
            className="field mb-4"
          />

          <label className="block text-sm font-medium text-[var(--color-ink-2)] mb-1.5">Temporary password</label>
          <div className="flex gap-2 mb-1">
            <input
              type="text" required minLength={8} value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="At least 8 characters"
              className="field"
            />
            <Button type="button" variant="outline" onClick={generatePassword} className="shrink-0">
              Generate
            </Button>
          </div>
          <p className="text-xs text-[var(--color-ink-3)] mb-4">
            The client uses this to sign in the first time.
          </p>

          {error && (
            <p className="mb-4 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2"
               style={{ color: 'var(--color-alert)' }}>
              {error}
            </p>
          )}

          <div className="flex gap-2">
            <Button type="submit" disabled={submitting} className="flex-1">
              {submitting ? 'Adding…' : 'Add client'}
            </Button>
            <Button type="button" variant="ghost" onClick={() => { onClose(); setTimeout(reset, 200); }}>
              Cancel
            </Button>
          </div>
        </form>
      )}
    </Modal>
  );
}

function CredRow({ label, value, mono }) {
  const [copied, setCopied] = useState(false);
  function copy() {
    navigator.clipboard?.writeText(value).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    });
  }
  return (
    <div className="flex items-center justify-between gap-2 bg-[var(--color-surface)] rounded-md px-3 py-2">
      <div className="min-w-0">
        <div className="text-[10px] uppercase tracking-wide text-[var(--color-ink-3)]">{label}</div>
        <div className={`text-sm text-[var(--color-ink)] truncate ${mono ? 'tnum' : ''}`}>{value}</div>
      </div>
      <button type="button" onClick={copy}
              className="shrink-0 text-xs font-medium text-[var(--color-teal-ink)] hover:underline">
        {copied ? 'Copied' : 'Copy'}
      </button>
    </div>
  );
}
