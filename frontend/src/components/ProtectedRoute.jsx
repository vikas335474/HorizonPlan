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
  const { user, loading } = useAuth();
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

  if (!user.mfaEnrolled && location.pathname !== '/settings') {
    return <Navigate to="/settings" state={{ mfaRequired: true, from: location }} replace />;
  }

  return children;
}
