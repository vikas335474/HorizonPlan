import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

// requireMfa: when true, a logged-in but MFA-unenrolled user is bounced to
// /settings (with location.state.mfaRequired) instead of the route rendering.
// This is a SOFT, app-layer gate — login.php still issues sessions to
// unenrolled users (a hard block there would lock out already-provisioned
// accounts that have no enrollment path). Do NOT apply requireMfa to /settings
// itself, or an unenrolled user redirected there loops back onto itself.
export default function ProtectedRoute({ children, requireMfa = false }) {
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

  if (requireMfa && !user.mfaEnrolled) {
    return <Navigate to="/settings" state={{ mfaRequired: true }} replace />;
  }

  return children;
}
