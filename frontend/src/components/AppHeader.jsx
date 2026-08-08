// The app's top navigation bar. Role-aware: the nav links are built from
// user.role — advisor / super_admin get the dashboard, templates, reviews,
// households, activity and admin links; a client gets a minimal set. Collapses to
// a hamburger below the `sm` breakpoint, carries the "MFA not enrolled" amber
// nudge, and owns the logout action. useAuth.

import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useDemoTour } from '../context/DemoTourContext';

const ROLE_LABELS = {
  advisor: 'Advisor',
  super_admin: 'Admin',
  client: 'Client',
};

// A self-serve individual (sql/033) carries role='client' so that every
// existing read path works unchanged — but "Client" is the wrong word to show
// them, because they are nobody's client. The role is an internal mechanism;
// the label is what the person actually reads.
function roleLabel(user, tenant) {
  if (user?.role === 'client' && tenant?.kind === 'personal') return 'Personal plan';
  return ROLE_LABELS[user?.role] || user?.role;
}

// docs/09 Piece 2 — only meaningful for role === 'advisor'; shown next to the
// role label so a logged-in advisor knows their own permission level (e.g.
// whether they can approve templates/risk questionnaires).
const FIRM_ROLE_LABELS = {
  jr_advisor: 'Jr. Advisor',
  sr_advisor: 'Sr. Advisor',
  firm_admin: 'Firm Admin',
};

export default function AppHeader() {
  const { user, tenant, platform, logout } = useAuth();
  const { available: tourAvailable, restart: restartTour } = useDemoTour();
  const navigate = useNavigate();
  // Session 7 mobile audit: the nav (Templates/Risk questionnaire/Activity/
  // role label/Settings/Sign out) is 6+ inline items with no wrap — at a
  // real phone width that forced the entire page 187px wider than the
  // viewport (562px content in a 375px viewport, confirmed via Playwright),
  // pushing every authenticated page into permanent horizontal scroll. Below
  // sm, the inline nav collapses behind this menu instead.
  const [menuOpen, setMenuOpen] = useState(false);

  async function handleLogout() {
    setMenuOpen(false);
    await logout();
    navigate('/login', { replace: true });
  }

  function go(path) {
    setMenuOpen(false);
    navigate(path);
  }

  const homePath = user?.role === 'client' ? '/goals' : '/';
  // White-label branding: a firm's own logo/name replaces the HorizonPlan mark
  // for its advisors and clients (docs/04 Phase 1). Falls back to HorizonPlan.
  const brandLogo = tenant?.whiteLabel?.logo_url || null;
  const brandName = tenant?.whiteLabel?.company_name || 'HorizonPlan';

  // Staging vs. production is a build-time distinction (a separate deploy
  // pipeline/branch/subdomain/database, not a runtime toggle like demoMode
  // below) — set via VITE_APP_ENV at `npm run build` time by
  // deploy-staging.yml. Renders unconditionally on staging (no login/role
  // check) since the whole point is nobody mistakes this environment for
  // production, logged in or not.
  const isStaging = import.meta.env.VITE_APP_ENV === 'staging';

  return (
    <>
      {isStaging && (
        <div
          className="sticky top-0 z-30 px-4 py-1.5 text-center text-xs font-semibold"
          style={{ backgroundColor: 'var(--color-ink)', color: '#fff' }}
        >
          STAGING ENVIRONMENT — not production, safe to test and reset.
        </div>
      )}
      {/* docs/09 Piece 1: a demo environment must never be mistaken for
          production data — this banner renders on every authenticated page
          (AppHeader mounts on all of them) whenever platform.demoMode is on. */}
      {platform?.demoMode === 'on' && (
        <div
          className="sticky top-0 z-30 px-4 py-1.5 text-center text-xs font-semibold"
          style={{ backgroundColor: 'var(--color-amber)', color: '#1a1200' }}
        >
          DEMO ENVIRONMENT — data is illustrative, not real client information.
        </div>
      )}
      <header className="surface-glass sticky top-0 z-20 border-b border-[var(--color-line)]">
      {/* gap-4 on the row, plus whitespace-nowrap on the nav below: at a plain
          1440px laptop width a long firm name used to butt straight into the
          first nav link and push multi-word labels ("Risk questionnaire",
          "Take the tour") into wrapping mid-phrase across two lines. The brand
          link is allowed to shrink and truncate; the nav is not. */}
      <div className="mx-auto max-w-6xl px-5 h-14 flex items-center justify-between gap-4">
        <Link to={homePath} className="flex min-w-0 shrink items-center gap-2.5 group">
          <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg overflow-hidden transition-colors group-hover:bg-[var(--color-surface-2)]">
            {brandLogo ? (
              <img src={brandLogo} alt="" className="h-full w-full object-contain" />
            ) : (
              /* Horizon mark — a thin rule meeting a rising point, echoing the product name */
              <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
                <line x1="2" y1="15" x2="20" y2="15" stroke="var(--color-line-2)" strokeWidth="1.5" />
                <path d="M4 15 L11 6 L18 11" stroke="var(--color-teal)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
                <circle cx="18" cy="11" r="2" fill="var(--color-amber)" />
              </svg>
            )}
          </span>
          <span className="truncate text-[15px] font-semibold tracking-tight text-[var(--color-ink)]">{brandName}</span>
        </Link>

        {/* Desktop nav — unchanged, just now hidden below sm in favor of the
            hamburger menu (mobile audit: this row's 6+ inline items had no
            wrap and forced the whole page into horizontal scroll on a real
            phone width). */}
        <div className="hidden sm:flex shrink-0 items-center gap-4 whitespace-nowrap">
          {user?.role === 'super_admin' && (
            <Link
              to="/admin"
              className="text-sm font-medium text-[var(--color-ink-2)] hover:text-[var(--color-ink)] transition-colors"
            >
              Firms
            </Link>
          )}
          {(user?.role === 'advisor' || user?.role === 'super_admin') && (
            <Link
              to="/households"
              className="text-sm font-medium text-[var(--color-ink-2)] hover:text-[var(--color-ink)] transition-colors"
            >
              Households
            </Link>
          )}
          {(user?.role === 'advisor' || user?.role === 'super_admin') && (
            <Link
              to="/templates"
              className="text-sm font-medium text-[var(--color-ink-2)] hover:text-[var(--color-ink)] transition-colors"
            >
              Templates
            </Link>
          )}
          {(user?.role === 'advisor' || user?.role === 'super_admin') && (
            <Link
              to="/risk-questionnaire"
              className="text-sm font-medium text-[var(--color-ink-2)] hover:text-[var(--color-ink)] transition-colors"
            >
              Risk questionnaire
            </Link>
          )}
          {(user?.role === 'advisor' || user?.role === 'super_admin') && (
            <Link
              to="/activity"
              className="text-sm font-medium text-[var(--color-ink-2)] hover:text-[var(--color-ink)] transition-colors"
            >
              Activity
            </Link>
          )}
          {/* Jr -> Sr Advisor Plan-Approval Workflow — shown to every advisor/
              super_admin (not just sr_advisor/firm_admin), same as Activity:
              a jr_advisor can still see the status of their own submissions,
              just not approve them. */}
          {(user?.role === 'advisor' || user?.role === 'super_admin') && (
            <Link
              to="/reviews"
              className="text-sm font-medium text-[var(--color-ink-2)] hover:text-[var(--color-ink)] transition-colors"
            >
              Reviews
            </Link>
          )}
          {(user?.role === 'advisor' || user?.role === 'super_admin') && (
            <Link
              to="/guide"
              className="text-sm font-medium text-[var(--color-ink-2)] hover:text-[var(--color-ink)] transition-colors"
            >
              Guide
            </Link>
          )}
          {user && (
            <span className="text-xs font-medium text-[var(--color-ink-3)]">
              {roleLabel(user, tenant)}
              {user.role === 'advisor' && user.firmRole && FIRM_ROLE_LABELS[user.firmRole] && (
                <> · {FIRM_ROLE_LABELS[user.firmRole]}</>
              )}
            </span>
          )}
          {/* Only ever rendered for a demo-login session (user.isDemo, see
              DemoTourContext.jsx) — a real admin-created or trial-signup
              advisor never sees this. */}
          {tourAvailable && (
            <button
              onClick={restartTour}
              className="text-sm font-medium text-[var(--color-teal-ink)] hover:underline"
            >
              Take the tour
            </button>
          )}
          {user && (
            <Link
              to="/settings"
              className="relative flex items-center gap-1.5 text-sm font-medium text-[var(--color-ink-2)] hover:text-[var(--color-ink)] transition-colors"
            >
              Settings
              {/* Amber nudge dot when MFA isn't enrolled — makes the gap
                  discoverable, not just enforced by the route gate. */}
              {!user.mfaEnrolled && (
                <span
                  className="h-1.5 w-1.5 rounded-full"
                  style={{ backgroundColor: 'var(--color-amber)' }}
                  title="Two-factor authentication is not set up"
                  aria-label="Two-factor authentication is not set up"
                />
              )}
            </Link>
          )}
          <button
            onClick={handleLogout}
            className="text-sm font-medium text-[var(--color-ink-2)] hover:text-[var(--color-ink)] transition-colors"
          >
            Sign out
          </button>
        </div>

        {/* Mobile menu toggle — replaces the whole nav row below sm. */}
        <button
          type="button"
          className="sm:hidden relative flex h-9 w-9 shrink-0 items-center justify-center rounded-[var(--radius-ctrl)] text-[var(--color-ink-2)] hover:bg-[var(--color-surface-2)]"
          onClick={() => setMenuOpen((o) => !o)}
          aria-label={menuOpen ? 'Close menu' : 'Open menu'}
          aria-expanded={menuOpen}
        >
          <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
            {menuOpen ? (
              <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
            ) : (
              <path d="M3 6h14M3 10h14M3 14h14" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
            )}
          </svg>
          {user && !user.mfaEnrolled && (
            <span
              className="absolute top-1 right-1 h-1.5 w-1.5 rounded-full"
              style={{ backgroundColor: 'var(--color-amber)' }}
              aria-hidden="true"
            />
          )}
        </button>
      </div>

      {/* Mobile nav panel — same destinations as the desktop row above, one
          per line instead of crammed into one non-wrapping row. */}
      {menuOpen && (
        <div className="sm:hidden border-t border-[var(--color-line)] bg-[var(--color-surface)] px-5 py-3 flex flex-col gap-1">
          {user?.role === 'super_admin' && (
            <button onClick={() => go('/admin')} className="py-2 text-left text-sm font-medium text-[var(--color-ink-2)]">Firms</button>
          )}
          {(user?.role === 'advisor' || user?.role === 'super_admin') && (
            <button onClick={() => go('/households')} className="py-2 text-left text-sm font-medium text-[var(--color-ink-2)]">Households</button>
          )}
          {(user?.role === 'advisor' || user?.role === 'super_admin') && (
            <button onClick={() => go('/templates')} className="py-2 text-left text-sm font-medium text-[var(--color-ink-2)]">Templates</button>
          )}
          {(user?.role === 'advisor' || user?.role === 'super_admin') && (
            <button onClick={() => go('/risk-questionnaire')} className="py-2 text-left text-sm font-medium text-[var(--color-ink-2)]">Risk questionnaire</button>
          )}
          {(user?.role === 'advisor' || user?.role === 'super_admin') && (
            <button onClick={() => go('/activity')} className="py-2 text-left text-sm font-medium text-[var(--color-ink-2)]">Activity</button>
          )}
          {(user?.role === 'advisor' || user?.role === 'super_admin') && (
            <button onClick={() => go('/reviews')} className="py-2 text-left text-sm font-medium text-[var(--color-ink-2)]">Reviews</button>
          )}
          {(user?.role === 'advisor' || user?.role === 'super_admin') && (
            <button onClick={() => go('/guide')} className="py-2 text-left text-sm font-medium text-[var(--color-ink-2)]">Guide</button>
          )}
          {tourAvailable && (
            <button
              onClick={() => { setMenuOpen(false); restartTour(); }}
              className="py-2 text-left text-sm font-medium text-[var(--color-teal-ink)]"
            >
              Take the tour
            </button>
          )}
          {user && (
            <button onClick={() => go('/settings')} className="flex items-center gap-1.5 py-2 text-left text-sm font-medium text-[var(--color-ink-2)]">
              Settings
              {!user.mfaEnrolled && (
                <span
                  className="h-1.5 w-1.5 rounded-full"
                  style={{ backgroundColor: 'var(--color-amber)' }}
                  title="Two-factor authentication is not set up"
                  aria-label="Two-factor authentication is not set up"
                />
              )}
            </button>
          )}
          {user && (
            <div className="pt-1 mt-1 border-t border-[var(--color-line)] text-xs font-medium text-[var(--color-ink-3)]">
              {roleLabel(user, tenant)}
              {user.role === 'advisor' && user.firmRole && FIRM_ROLE_LABELS[user.firmRole] && (
                <> · {FIRM_ROLE_LABELS[user.firmRole]}</>
              )}
            </div>
          )}
          <button onClick={handleLogout} className="py-2 text-left text-sm font-medium text-[var(--color-ink-2)]">Sign out</button>
        </div>
      )}
      </header>
    </>
  );
}
