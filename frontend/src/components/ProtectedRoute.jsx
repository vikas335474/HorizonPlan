import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

// Gates a route on an authenticated session only. MFA enrolment is optional by
// design (login.php issues sessions to unenrolled users), so there is no MFA
// gate here — the reminder to enrol lives in AppHeader as an amber dot on the
// Settings link, and users enrol voluntarily from /settings.
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

  return children;
}
