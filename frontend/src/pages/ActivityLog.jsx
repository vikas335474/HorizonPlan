// Route /activity — the firm-wide read-only audit trail (docs/09 Session 6).
// Advisor / super_admin (server-enforced via verifyAccessAny on
// change_log_list.php). Renders <ChangeLogPanel> with no entity filter, i.e. the
// whole-tenant feed; the data fetch (api.getChangeLog) lives inside that panel,
// so this page itself is just layout.

import AppHeader from '../components/AppHeader';
import { Card } from '../components/ui';
import ChangeLogPanel from '../components/ChangeLogUI';

// docs/09 Session 6 — firm-wide "who changed what, and when" view. Advisor
// and super_admin only (matches change_log_list.php's own access check);
// no per-tenant picker here — a super_admin sees their own tenant's history
// same as everywhere else, per goals_list.php's own precedent.
export default function ActivityLog() {
  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-3xl px-5 py-8">
        <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">Activity</h1>
        <p className="mt-1 mb-5 text-sm text-[var(--color-ink-2)]">
          Every change made to a goal or scenario across your firm — who, what, and when.
        </p>

        <Card className="p-4">
          <ChangeLogPanel pageSize={25} emptyMessage="No changes recorded yet." />
        </Card>
      </main>
    </div>
  );
}
