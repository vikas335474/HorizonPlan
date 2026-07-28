// Route / for advisor / super_admin sessions (Home() redirects a client to
// /goals). The client roster with at-a-glance health badges (readiness + risk
// band), sort/filter, an add-client form (api.createClient) and the first-run
// onboarding checklist / spotlight. Roster data comes from clients_list via the
// dashboard load; useAuth for identity.

import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import AppHeader from '../components/AppHeader';
import Modal from '../components/Modal';
import { Card, Button, Badge } from '../components/ui';
import { ReadinessScoreBadge, bandFor } from '../components/ReadinessScore';
import OnboardingChecklist, { isOnboardingDismissed, dismissOnboarding } from '../components/OnboardingChecklist';
import OnboardingSpotlight from '../components/OnboardingSpotlight';
import { formatCurrencyCompact, formatCurrency, formatDate } from '../lib/format';

// docs/08 gap #5 — the client list previously only showed goal count and
// corpus, with no signal for which clients actually need the advisor's
// attention. A client needs it if they have no goals yet, haven't taken a
// risk profile, or their worst goal's readiness score reads "Needs
// attention" per ReadinessScore.jsx's own band (score < 40) — reusing that
// band definition rather than a second hardcoded cutoff.
function needsAttention(client) {
  if (client.goal_count === 0) return true;
  if (client.risk_band === null) return true;
  if (client.min_readiness_score !== null && bandFor(client.min_readiness_score).label === 'Needs attention') {
    return true;
  }
  return false;
}

const SORT_OPTIONS = [
  { value: 'recent', label: 'Recently added' },
  { value: 'attention', label: 'Needs attention first' },
  { value: 'readiness', label: 'Lowest readiness first' },
  { value: 'corpus', label: 'Highest corpus first' },
];

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

  function load() {
    setLoading(true);
    api
      .listClients()
      .then(setData)
      .catch((err) => setError(err.message || 'Could not load your clients.'))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, []);

  // Memoized so its reference is stable across renders where `data` hasn't
  // changed — otherwise `data?.clients ?? []` mints a fresh array on every
  // render while `data` is still null (the loading phase), which would
  // needlessly re-run the filtered/sorted useMemo below on every render.
  const clients = useMemo(() => data?.clients ?? [], [data]);

  const filtered = useMemo(() => {
    let list = query
      ? clients.filter((c) => c.email.toLowerCase().includes(query.toLowerCase()))
      : clients;

    if (attentionOnly) {
      list = list.filter(needsAttention);
    }

    if (sortBy === 'recent') return list;

    const sorted = [...list];
    if (sortBy === 'attention') {
      // Stable partition — needs-attention clients first, original order
      // preserved within each group (Array.sort is stable per spec).
      sorted.sort((a, b) => Number(needsAttention(b)) - Number(needsAttention(a)));
    } else if (sortBy === 'readiness') {
      // Nulls (no computable retirement goal) sort last, not first — an
      // unscored client isn't necessarily "fine," but it's not the same
      // signal as a scored-and-struggling one, so it shouldn't crowd it out.
      sorted.sort((a, b) => {
        if (a.min_readiness_score === null) return 1;
        if (b.min_readiness_score === null) return -1;
        return a.min_readiness_score - b.min_readiness_score;
      });
    } else if (sortBy === 'corpus') {
      sorted.sort((a, b) => b.total_net_worth - a.total_net_worth);
    }
    return sorted;
  }, [clients, query, attentionOnly, sortBy]);

  return (
    <div className="min-h-screen">
      <AppHeader />

      {/* Page header band */}
      <div className="border-b border-[var(--color-line)] bg-[var(--color-surface)]">
        <div className="mx-auto max-w-6xl px-5 py-6 flex items-end justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">
              Your book
            </h1>
            <p className="mt-1 text-sm text-[var(--color-ink-2)]">
              Clients, goals, and corpus under plan across your firm.
            </p>
          </div>
          <Button data-tour="onboarding-add-client" onClick={() => setAddOpen(true)}>
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
              <path d="M8 3.5v9M3.5 8h9" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
            </svg>
            Add client
          </Button>
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

            {/* Stat band */}
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
                    {filtered.length} of {clients.length}
                  </span>
                </div>

                <div className="hidden md:grid grid-cols-12 gap-4 px-4 py-2.5 border-b border-[var(--color-line)] text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-3)]">
                  <div className="col-span-6">Client</div>
                  <div className="col-span-2 text-right">Goals</div>
                  <div className="col-span-4 text-right">Tracked corpus</div>
                </div>

                <ul>
                  {filtered.map((c) => (
                    <li key={c.client_id}>
                      <button
                        onClick={() => navigate(`/clients/${c.client_id}`)}
                        data-tour="dashboard-client-row"
                        data-client-id={c.client_id}
                        data-goal-count={c.goal_count}
                        className="group w-full grid grid-cols-12 gap-4 px-4 py-3.5 items-center text-left border-b border-[var(--color-line)] last:border-b-0 hover:bg-[var(--color-surface-2)] transition-colors"
                      >
                        <div className="col-span-12 md:col-span-6 flex items-center gap-3 min-w-0">
                          <Avatar email={c.email} />
                          <div className="min-w-0">
                            <div className="truncate text-sm font-medium text-[var(--color-ink)]">{c.email}</div>
                            <div className="mt-1 flex flex-wrap items-center gap-1.5" data-tour-badges="true">
                              <span className="text-xs text-[var(--color-ink-3)]">Client since {formatDate(c.client_since)}</span>
                              <ClientHealthBadges client={c} />
                            </div>
                          </div>
                        </div>
                        <div className="col-span-6 md:col-span-2 md:text-right">
                          <span className="tnum text-sm text-[var(--color-ink)]">{c.goal_count}</span>
                          <span className="md:hidden text-xs text-[var(--color-ink-3)]"> goals</span>
                        </div>
                        <div className="col-span-6 md:col-span-4 flex items-center justify-end gap-2">
                          <span className="tnum text-sm text-[var(--color-ink)]">{formatCurrency(c.total_net_worth)}</span>
                          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"
                               className="text-[var(--color-ink-3)] opacity-0 group-hover:opacity-100 transition-opacity" aria-hidden="true">
                            <path d="M6 4l4 4-4 4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                          </svg>
                        </div>
                      </button>
                    </li>
                  ))}
                  {filtered.length === 0 && (
                    <li className="px-4 py-10 text-center text-sm text-[var(--color-ink-3)]">
                      No clients match "{query}".
                    </li>
                  )}
                </ul>
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
