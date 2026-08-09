// Route /goals — the CLIENT's own self-service home. Lists the client's goals
// (api.listGoals, which the server forces to the caller's own id) plus read-only
// portfolio, cash-flow and risk cards and the compliance DisclosureBanner. An
// advisor reaches a specific client's goals through ClientGoals, not here.

import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import AppHeader from '../components/AppHeader';
import DisclosureBanner from '../components/DisclosureBanner';
import GoalCard from '../components/GoalCard';
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

        {/* docs/12 Prompt D-4 — the unifying alerts surface. Renders nothing
            when there is nothing to say, same convention as FoundationsCaveat. */}
        <ClientAlertsCard />

        {/* A FIRM-managed client reads these; a self-serve individual must be
            able to WRITE them, because there is nobody else to enter their
            data. Passing readOnly unconditionally was a real lockout: the
            wizard created a plan and the person could then never add a single
            asset or expense to it. The server already allowed both writes
            (verifySelfServiceWrite), so only this UI stood in the way.
            Gated on tenant kind, matching api/lib/SelfService.php — a firm's
            client gains nothing. */}
        <ClientPortfolioCard readOnly={!isSelfDirected} />

        {isSelfDirected ? (
          // The editable card. cash_flow_list.php withholds sip_comparison from
          // any client session, so the advisor-only "does the surplus cover the
          // SIPs?" framing simply never renders here.
          <CashFlowCard clientId={user?.userId} />
        ) : (
          <ClientCashFlowCard />
        )}

        {/* docs/10 P1-4 — reserve, protection and debt. Editable for a
            self-serve individual (there is nobody else to record it);
            read-only for a firm-managed client, whose adviser owns it. */}
        <ClientFoundationsCard />

        {/* docs/11 Prompt E-2 — opt-in refinement queue. Self-serve only: this
            is scoped to "the self-serve plan" (docs/11 §4), same gate as the
            cards above. Renders nothing outside a personal tenant. */}
        {isSelfDirected && (
          <>
            <PersonalisationCard />
            <DependantsCard />
          </>
        )}

        {/* sql/035 — a couple planning together. Renders nothing outside a
            personal tenant, and shows the invite affordance until a partner
            joins. The combined figures are the SUM of two plans, never a pool. */}
        <PartnerHouseholdCard />

        {/* docs/10 P0-4 — the client's own risk band, read-only, mirroring the
            portfolio card above. Advisor-only affordances (capture, suggested
            return) are not exposed here. */}
        <ClientRiskProfileCard />

        {loading && <Spinner label="Loading your goals…" />}

        {error && (
          <Card className="p-4 border-[var(--color-alert)]">
            <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
          </Card>
        )}

        {goals && goals.length === 0 && (
          <Card>
            <EmptyState title="No goals yet">
              {isSelfDirected
                ? "You haven't set up a plan yet. Answer a few questions and we'll build one you can change any time."
                : "Your advisor hasn't set up any goals for you yet. Once they do, you'll be able to explore different scenarios here."}
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
      </main>
    </div>
  );
}
