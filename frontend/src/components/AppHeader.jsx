import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

const ROLE_LABELS = {
  advisor: 'Advisor',
  super_admin: 'Admin',
  client: 'Client',
};

export default function AppHeader() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  async function handleLogout() {
    await logout();
    navigate('/login', { replace: true });
  }

  const homePath = user?.role === 'client' ? '/goals' : '/';

  return (
    <header className="surface-glass sticky top-0 z-20 border-b border-[var(--color-line)]">
      <div className="mx-auto max-w-6xl px-5 h-14 flex items-center justify-between">
        <Link to={homePath} className="flex items-center gap-2.5 group">
          {/* Horizon mark — a thin rule meeting a rising point, echoing the product name */}
          <span className="flex h-8 w-8 items-center justify-center rounded-lg transition-colors group-hover:bg-[var(--color-surface-2)]">
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
              <line x1="2" y1="15" x2="20" y2="15" stroke="var(--color-line-2)" strokeWidth="1.5" />
              <path d="M4 15 L11 6 L18 11" stroke="var(--color-teal)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
              <circle cx="18" cy="11" r="2" fill="var(--color-amber)" />
            </svg>
          </span>
          <span className="text-[15px] font-semibold tracking-tight text-[var(--color-ink)]">HorizonPlan</span>
        </Link>

        <div className="flex items-center gap-4">
          {user && (
            <span className="hidden sm:inline text-xs font-medium text-[var(--color-ink-3)]">
              {ROLE_LABELS[user.role] || user.role}
            </span>
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
      </div>
    </header>
  );
}
