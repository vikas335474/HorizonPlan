// sql/035 · The self-serve household — a couple planning together with no
// advisor. Rendered on /goals for personal tenants only.
//
// Two states in one card:
//   * alone      → invite a partner (email + optional name → magic link)
//   * together   → the combined view: joint corpus, combined monthly surplus,
//                  and each person's goals side by side
//
// WHAT THIS IS NOT. Not a merged pot. The combined figures are the SUM of two
// independently-modelled plans (sql/029's principle, and docs/02 §4.1): two
// earners have different ages, horizons and risk tolerances, so summing two
// honest plans beats averaging them into one that models neither person.
//
// Read/write asymmetry, and it is deliberate: both partners can SEE everything
// (a combined shortfall is unexplainable if you cannot see what drives it) but
// each EDITS only their own plan. That is enforced server-side by
// SelfService.php, not here — this UI only avoids offering what would be
// refused.

import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import { Card, Button, Spinner } from './ui';
import { formatCurrency } from '../lib/format';
import { ReadinessScoreBadge } from './ReadinessScore';

// users.display_name is nullable (sql/035) and NULL for everyone predating it,
// so email is the fallback — exactly what the app showed before names existed.
function personName(member) {
  return member?.display_name || member?.email || 'Member';
}

// The invite link is owned by the PARENT, not this form. Creating the partner
// immediately makes the household two-strong, which flips the card to its
// "together" state and unmounts this form — so a link held in local state was
// destroyed on the same tick it was created, and the inviter never saw the URL
// they need to send. Caught in a browser run; the partner row existed but the
// link was gone.
function InviteForm({ onInvited }) {
  const [email, setEmail] = useState('');
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      const res = await api.invitePartner(email.trim(), name.trim());
      onInvited(res.invite_link || '');
    } catch (err) {
      setError(err?.message || 'Could not send that invite.');
    } finally {
      setBusy(false);
    }
  }

  const field = 'w-full rounded-lg border border-[var(--color-line-2)] bg-[var(--color-surface)] px-2.5 py-1.5 text-sm';

  return (
    <form onSubmit={submit} className="mt-3 rounded-lg bg-[var(--color-surface-2)] p-3">
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block">
          <span className="mb-1 block text-xs font-medium text-[var(--color-ink-2)]">Their email</span>
          <input className={field} type="email" required value={email}
            onChange={(e) => setEmail(e.target.value)} placeholder="partner@example.com" />
        </label>
        <label className="block">
          <span className="mb-1 block text-xs font-medium text-[var(--color-ink-2)]">Their name (optional)</span>
          <input className={field} type="text" value={name}
            onChange={(e) => setName(e.target.value)} placeholder="e.g. Priya" />
        </label>
      </div>
      {error && <p className="mt-2 text-xs text-[var(--color-alert)]">{error}</p>}
      <div className="mt-3 flex items-center gap-2">
        <Button size="sm" type="submit" disabled={busy}>{busy ? 'Creating…' : 'Create invite'}</Button>
        <span className="text-[11px] text-[var(--color-ink-3)]">
          They get their own sign-in and keep their own plan.
        </span>
      </div>
    </form>
  );
}

function Total({ label, value, sublabel }) {
  return (
    <div>
      <p className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">{label}</p>
      <p className="mt-0.5 text-lg font-semibold tabular-nums text-[var(--color-ink)]">{value}</p>
      {sublabel && <p className="text-[11px] text-[var(--color-ink-3)]">{sublabel}</p>}
    </div>
  );
}

export default function PartnerHouseholdCard() {
  const { user, tenant } = useAuth();
  const isSelfDirected = tenant?.kind === 'personal';

  const [projection, setProjection] = useState(null);
  const [cashFlow, setCashFlow] = useState(null);
  const [loading, setLoading] = useState(true);
  const [inviting, setInviting] = useState(false);
  // Held here, not in InviteForm — see that component's note. Survives the
  // switch to the "together" layout so the inviter can still copy the link.
  const [inviteLink, setInviteLink] = useState('');

  const load = useCallback(() => {
    let cancelled = false;
    setLoading(true);
    // Both endpoints 404 until a partner joins — that is the "alone" state, not
    // an error, so it resolves to null rather than surfacing a message.
    Promise.allSettled([api.getMyHouseholdProjection(), api.getMyHouseholdCashFlow()])
      .then(([p, c]) => {
        if (cancelled) return;
        setProjection(p.status === 'fulfilled' ? p.value : null);
        setCashFlow(c.status === 'fulfilled' ? c.value : null);
      })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    if (!isSelfDirected) return undefined;
    return load();
  }, [isSelfDirected, load]);

  if (!isSelfDirected) return null;

  const members = projection?.members ?? [];
  const together = members.length > 1;

  return (
    <Card className="p-4 mb-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-[var(--color-ink)]">
            {together ? 'Together' : 'Planning with someone else?'}
          </h2>
          <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">
            {together
              ? 'Your two plans, added together. Each of you keeps your own goals and your own retirement date — this is the sum of both, not one shared pot.'
              : 'If you and your partner both earn, you can plan side by side. You each keep your own plan; you both see the combined picture.'}
          </p>
        </div>
        {!together && !loading && (
          <Button variant="ghost" size="sm" onClick={() => setInviting((v) => !v)}>
            {inviting ? 'Close' : 'Invite'}
          </Button>
        )}
      </div>

      {loading && <div className="mt-3"><Spinner label="Loading…" /></div>}

      {!loading && !together && inviting && !inviteLink && (
        <InviteForm
          onInvited={(link) => { setInviteLink(link); setInviting(false); load(); }}
        />
      )}

      {/* Rendered in BOTH states. The invite is what the person has to act on
          — this environment has no guaranteed outbound mail, so they pass the
          link to their partner themselves. It stays until dismissed. */}
      {inviteLink && (
        <div className="mt-3 rounded-lg p-3" style={{ backgroundColor: 'var(--color-teal-soft)' }}>
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="text-sm font-medium text-[var(--color-teal-ink)]">Invite ready</p>
              <p className="mt-1 text-xs text-[var(--color-ink-2)]">
                Send them this link so they can set a password and sign in. It works for 7 days.
              </p>
            </div>
            <Button variant="ghost" size="sm" onClick={() => setInviteLink('')}>Done</Button>
          </div>
          <code className="mt-2 block break-all rounded p-2 text-[11px] text-[var(--color-ink-2)]"
            style={{ backgroundColor: 'var(--color-surface)' }}>
            {inviteLink}
          </code>
        </div>
      )}

      {!loading && together && (
        <>
          <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
            <Total
              label="Combined starting corpus"
              value={formatCurrency(projection?.total_starting_corpus ?? 0)}
              sublabel={`${members.length} people`}
            />
            {cashFlow?.totals && (
              <>
                <Total label="Combined monthly surplus" value={`${formatCurrency(cashFlow.totals.monthly_surplus)}/mo`} />
                <Total label="Going to goals" value={`${formatCurrency(cashFlow.totals.total_monthly_sip)}/mo`} />
              </>
            )}
          </div>

          <ul className="mt-4 space-y-3">
            {members.map((m) => {
              const isMe = Number(m.client_id) === Number(user?.userId);
              return (
                <li key={m.client_id} className="rounded-lg border border-[var(--color-line)] p-3">
                  <div className="flex items-baseline gap-2">
                    <span className="text-sm font-medium text-[var(--color-ink)]">{personName(m)}</span>
                    {isMe && (
                      <span className="text-[11px] uppercase tracking-wide text-[var(--color-teal-ink)]">You</span>
                    )}
                  </div>
                  {m.goals.length === 0 ? (
                    <p className="mt-1 text-xs text-[var(--color-ink-2)]">
                      {isMe ? "You haven't added a retirement goal yet." : 'No retirement goal added yet.'}
                    </p>
                  ) : (
                    <ul className="mt-2 space-y-1.5">
                      {m.goals.map((g) => (
                        <li key={g.goal_id} className="flex flex-wrap items-center gap-2 text-xs">
                          {/* Only your own goal is a link: goals_read.php refuses
                              a client reading someone else's goal detail, so
                              linking a partner's would just 403. The combined
                              view above is where shared visibility lives. */}
                          {isMe ? (
                            <Link to={`/goals/${g.goal_id}`} className="font-medium text-[var(--color-teal-ink)] hover:underline">
                              {g.goal_label}
                            </Link>
                          ) : (
                            <span className="font-medium text-[var(--color-ink)]">{g.goal_label}</span>
                          )}
                          <span className="tabular-nums text-[var(--color-ink-2)]">
                            {formatCurrency(g.initial_net_worth)}
                          </span>
                          <ReadinessScoreBadge score={g.readiness_score} />
                        </li>
                      ))}
                    </ul>
                  )}
                </li>
              );
            })}
          </ul>

          <p className="mt-3 text-[11px] leading-relaxed text-[var(--color-ink-3)]">
            You can each see both plans. You can only change your own.
          </p>
        </>
      )}
    </Card>
  );
}
