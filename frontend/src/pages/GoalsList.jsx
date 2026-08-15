// Route /goals — the CLIENT's own self-service home. Lists the client's goals
// (api.listGoals, which the server forces to the caller's own id) plus read-only
// portfolio, cash-flow and risk cards and the compliance DisclosureBanner. An
// advisor reaches a specific client's goals through ClientGoals, not here.

import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import AppHeader from '../components/AppHeader';
import DisclosureBanner from '../components/DisclosureBanner';
import GoalCard from '../components/GoalCard';
import PersonalPlanSummary from '../components/PersonalPlanSummary';
import NetWorthTrend from '../components/NetWorthTrend';
import { ClientPortfolioCard } from '../components/ClientPortfolioUI';
import { ClientCashFlowCard, CashFlowCard } from '../components/CashFlowUI';
import { ClientFoundationsCard } from '../components/FoundationsUI';
import { ClientAlertsCard } from '../components/AlertsUI';
import { PersonalisationCard, DependantsCard } from '../components/PersonalisationUI';
import PartnerHouseholdCard from '../components/PartnerHouseholdUI';
import { ClientRiskProfileCard } from '../components/RiskProfileUI';
import { Card, EmptyState, Spinner } from '../components/ui';

// A client's own goals view. Clients never pass a client_id — the server uses
// their session identity (goals_list.php enforces this).
export default function GoalsList() {
  // Self-serve individual tier: someone with no adviser must not be told an
  // adviser did something. Copy only — the data and layout are identical.
  const { tenant, user } = useAuth();
  const isSelfDirected = tenant?.kind === 'personal';
  const [goals, setGoals] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    api
      .listGoals()
      .then((res) => {
        if (!cancelled) setGoals(res.goals);
      })
      .catch((err) => {
        if (!cancelled) setError(err.message || 'Could not load your goals.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-6xl px-5 py-8">
        <div className="mb-5">
          <h1 className="text-xl font-semibold tracking-tight text-[var(--color-ink)]">Your goals</h1>
          <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
            {isSelfDirected
              ? 'The plans you\'ve built for yourself. Open one to change any number and see what happens.'
              : 'Retirement and savings plans your advisor has set up with you.'}
          </p>
        </div>

        <div className="mb-6">
          <DisclosureBanner />
        </div>

        {/* docs/13 I-1 — ORDER MATTERS ON THIS PAGE, and it differs by
            audience. For a firm-managed client every card below is a read-out
            of what their adviser entered, so the original order is kept
            untouched. For a self-serve individual every one of those cards is
            an EMPTY FORM asking them to do work, and putting eight of them
            above the plan meant someone finished onboarding and landed on a
            chore list with their goals below the fold.

            So the individual gets: where they stand -> the one next action ->
            their goals -> everything else, collapsed. Same components, same
            data, same server. Only the order and the disclosure change. */}

        {isSelfDirected && <PersonalPlanSummary goals={goals} />}

        {/* docs/12 Prompt D-4 — the unifying alerts surface. Renders nothing
            when there is nothing to say, same convention as FoundationsCaveat.
            For a self-serve individual the single most important alert is
            already promoted into PersonalPlanSummary above, so the full list
            moves down with the rest of the detail. */}
        {!isSelfDirected && <ClientAlertsCard />}

        {loading && <Spinner label="Loading your goals…" />}

        {error && (
          <Card className="p-4 border-[var(--color-alert)]">
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
          </Card>
        )}

        {goals && goals.length === 0 && (
          <Card>
            <EmptyState title="No goals yet">
              {isSelfDirected ? (
                // docs/13 I-3 — this used to describe an action with no way to
                // take it. /start was reachable only in the moments right after
                // signup, so anyone who abandoned onboarding or deleted their
                // goals was stranded here permanently, reading an invitation
                // that went nowhere.
                <>
                  <p>
                    You haven't set up a plan yet. Answer a few questions and we'll build one you
                    can change any time.
                  </p>
                  <Link
                    to="/start"
                    className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-[var(--color-teal-ink)] hover:underline"
                  >
                    Build my plan
                    <svg width="13" height="13" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                      <path d="M5 3l4 4-4 4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                  </Link>
                </>
              ) : (
                "Your advisor hasn't set up any goals for you yet. Once they do, you'll be able to explore different scenarios here."
              )}
            </EmptyState>
          </Card>
        )}

        {goals && goals.length > 0 && (
          <div className="stagger-children grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {goals.map((goal) => (
              <GoalCard key={goal.id} goal={goal} />
            ))}
          </div>
        )}

        {/* How the money has actually moved, from the recorded monthly
            snapshots. Sits AFTER the goals on purpose: the plan is the point of
            this page, and a portfolio chart above it would make this a tracking
            app. Renders nothing until three readings exist, so a new plan never
            shows an empty frame.

            Personal-tenant only, matching the ordering branch above. A
            firm-managed client's progress narrative belongs to their adviser,
            who already has this series on the client page — surfacing it here
            unasked would change a firm's client experience, not this one. */}
        {isSelfDirected && goals && goals.length > 0 && (
          <div className="mt-4">
            <NetWorthTrend />
          </div>
        )}

        {/* --- everything below here is detail and data entry --- */}
        {isSelfDirected ? (
          <RefineSection>
            <ClientAlertsCard />
            <ClientPortfolioCard readOnly={false} />
            <CashFlowCard clientId={user?.userId} />
            <ClientFoundationsCard />
            <PersonalisationCard />
            <DependantsCard />
            <PartnerHouseholdCard />
            <ClientRiskProfileCard />
          </RefineSection>
        ) : (
          <>
            {/* A FIRM-managed client reads these; a self-serve individual must be
                able to WRITE them, because there is nobody else to enter their
                data. Passing readOnly unconditionally was a real lockout: the
                wizard created a plan and the person could then never add a single
                asset or expense to it. The server already allowed both writes
                (verifySelfServiceWrite), so only this UI stood in the way.
                Gated on tenant kind, matching api/lib/SelfService.php — a firm's
                client gains nothing. */}
            <ClientPortfolioCard readOnly />
            <ClientCashFlowCard />
            {/* docs/10 P1-4 — reserve, protection and debt. Read-only for a
                firm-managed client, whose adviser owns it. */}
            <ClientFoundationsCard />
            {/* sql/035 — renders nothing outside a personal tenant. */}
            <PartnerHouseholdCard />
            {/* docs/10 P0-4 — the client's own risk band, read-only, mirroring
                the portfolio card above. Advisor-only affordances (capture,
                suggested return) are not exposed here. */}
            <ClientRiskProfileCard />
          </>
        )}
      </main>
    </div>
  );
}

// docs/13 I-1 — the detail cards, collapsed by default for a self-serve
// individual. Collapsed rather than removed: this is where the plan actually
// gets better, and hiding it entirely would trade one problem (a wall of
// forms) for a worse one (a plan nobody can refine).
//
// Open by default would defeat the point; a bare "Show more" gives no reason
// to open it. So the summary line says what is in there, and the person
// chooses.
function RefineSection({ children }) {
  const [open, setOpen] = useState(false);
  return (
    <section className="mt-8">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="flex w-full items-center justify-between gap-3 rounded-lg border border-[var(--color-line-2)] px-4 py-3 text-left transition-colors hover:bg-[var(--color-surface-2)]"
      >
        <span>
          <span className="block text-sm font-semibold text-[var(--color-ink)]">
            Make this plan more accurate
          </span>
          <span className="mt-0.5 block text-xs text-[var(--color-ink-2)]">
            What you own, what you spend, your safety net, and who else is planning with you.
          </span>
        </span>
        <svg
          width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"
          style={{ transform: open ? 'rotate(180deg)' : 'none', transition: 'transform 150ms' }}
        >
          <path d="M4 6l4 4 4-4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>

      {open && <div className="mt-4">{children}</div>}
    </section>
  );
}
