// Route guard wrapping every authenticated route. Redirects to /login when there
// is no session, and to /settings when a session hasn't satisfied mandatory MFA
// (a soft app-layer gate layered on top of the server-side 403 that backs it).
// Props: {children}. useAuth.

import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

// Gates a route on an authenticated session, and — MFA enrollment being
// mandatory (see "Security status" in CLAUDE.md) — on completed TOTP
// enrollment too. The server already blocks every endpoint except
// mfa_enroll.php/session.php/logout.php for an unenrolled session
// (verifyAccess()/verifyAccessAny()'s $requireMfaEnrolled default), so this
// gate exists to land the user somewhere coherent instead of a screen full
// of 403s. /settings itself is exempt — it's where enrollment happens.
export default function ProtectedRoute({ children }) {
  const { user, platform, loading } = useAuth();
  const location = useLocation();

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center" style={{ backgroundColor: 'var(--color-canvas)' }}>
        <p className="text-sm text-[var(--color-ink-3)]">Loading…</p>
      </div>
    );
  }

  if (!user) {
    return <Navigate to="/login" state={{ from: location }} replace />;
  }

  // docs/09 Piece 1: mirrors the server-side skip in requireMfaEnrollment() —
  // demo_mode='on' implies MFA is skipped regardless of mfa_enforcement, and
  // mfa_enforcement='disabled' on its own also skips it. The server already
  // wouldn't 403 in either case, so redirecting here would just be a UX dead
  // end with nothing to actually enroll against being enforced.
  const mfaIsEnforced = platform.demoMode !== 'on' && platform.mfaEnforcement !== 'disabled';

  if (mfaIsEnforced && !user.mfaEnrolled && location.pathname !== '/settings') {
    return <Navigate to="/settings" state={{ mfaRequired: true, from: location }} replace />;
  }

  return children;
}
