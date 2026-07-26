import { Card, Button } from './ui';

// docs/09 Group 3 Session 5 — first-time advisor onboarding walkthrough.
// Frontend-only, zero new schema/endpoints per the session's own decision:
// "first-run" is detected purely from the same clients_list.php response
// Dashboard.jsx already fetches (zero clients, or clients with zero goals
// between them) rather than a has_completed_onboarding column. Dismissal is
// localStorage-only (per-browser-tab-ish, not auditable state), keyed by
// user id so switching accounts on the same device doesn't leak dismissal
// across advisors.
const DISMISS_KEY_PREFIX = 'hp_onboarding_dismissed_';

export function isOnboardingDismissed(userId) {
  if (!userId) return false;
  try {
    return localStorage.getItem(DISMISS_KEY_PREFIX + userId) === '1';
  } catch {
    return false; // localStorage unavailable (private mode, etc.) — fail open, just show it
  }
}

export function dismissOnboarding(userId) {
  if (!userId) return;
  try {
    localStorage.setItem(DISMISS_KEY_PREFIX + userId, '1');
  } catch {
    // ignore — nothing to do if storage is unavailable
  }
}

// Shown until the advisor has at least one client AND at least one goal
// (docs/09's own suggested threshold), or until they dismiss it early —
// whichever comes first. Not a modal takeover: every step links into an
// existing flow rather than duplicating it, and the advisor can ignore this
// entirely and use the app normally.
export default function OnboardingChecklist({ clients, onAddClient, onGoToClient, onDismiss }) {
  const hasClient = clients.length > 0;
  const totalGoals = clients.reduce((sum, c) => sum + (c.goal_count || 0), 0);
  const hasGoal = totalGoals > 0;

  // Two different routing targets: "create a goal" points at a client who
  // still needs one, "share a report" points at a client who actually has
  // one to share — never the same fallback, or the report step could route
  // to an empty client with nothing to show.
  const clientNeedingGoal = clients.find((c) => c.goal_count === 0) || null;
  const clientWithGoal = clients.find((c) => c.goal_count > 0) || null;

  return (
    <Card className="p-4 sm:p-5 mb-6 border-[var(--color-teal)]" style={{ backgroundColor: 'var(--color-teal-soft)' }}>
      <div className="flex items-start justify-between gap-3">
        <div>
          <h3 className="text-sm font-semibold text-[var(--color-ink)]">Get started with HorizonPlan</h3>
          <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">Three steps to your first client-ready plan.</p>
        </div>
        <button
          onClick={onDismiss}
          className="text-xs text-[var(--color-ink-3)] hover:text-[var(--color-ink)] shrink-0"
          aria-label="Dismiss getting-started checklist"
        >
          Skip
        </button>
      </div>

      <ol className="mt-4 space-y-2.5">
        <ChecklistItem
          done={hasClient}
          label="Add your first client"
          action={!hasClient && <Button size="sm" onClick={onAddClient}>Add client</Button>}
        />
        <ChecklistItem
          done={hasGoal}
          label="Create a goal for them"
          action={
            !hasGoal && clientNeedingGoal
              ? <Button size="sm" variant="outline" onClick={() => onGoToClient(clientNeedingGoal.client_id)}>Go to client</Button>
              : null
          }
        />
        <ChecklistItem
          done={false}
          optional
          label="Share a plan report with your client"
          action={
            clientWithGoal
              ? <Button size="sm" variant="outline" onClick={() => onGoToClient(clientWithGoal.client_id)}>Go to client</Button>
              : null
          }
        />
      </ol>
    </Card>
  );
}

function ChecklistItem({ done, label, action, optional = false }) {
  return (
    <li className="flex items-center justify-between gap-3">
      <div className="flex items-center gap-2.5 min-w-0">
        <span
          className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold"
          style={
            done
              ? { background: 'var(--color-teal-ink)', color: 'white' }
              : { background: 'var(--color-surface)', color: 'var(--color-ink-3)', border: '1px solid var(--color-line-2)' }
          }
        >
          {done ? '✓' : ''}
        </span>
        <span className={`text-sm ${done ? 'text-[var(--color-ink-2)] line-through' : 'text-[var(--color-ink)]'}`}>
          {label}
          {optional && !done && <span className="ml-1.5 text-xs text-[var(--color-ink-3)]">(optional)</span>}
        </span>
      </div>
      {action}
    </li>
  );
}
