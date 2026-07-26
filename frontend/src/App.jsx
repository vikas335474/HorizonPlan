import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import { DemoTourProvider } from './context/DemoTourContext';
import ProtectedRoute from './components/ProtectedRoute';
import Login from './pages/Login';
import Signup from './pages/Signup';
import ForgotPassword from './pages/ForgotPassword';
import ResetPassword from './pages/ResetPassword';
import Dashboard from './pages/Dashboard';
import ClientGoals from './pages/ClientGoals';
import GoalsList from './pages/GoalsList';
import GoalDetail from './pages/GoalDetail';
import PlanReport from './pages/PlanReport';
import MeetingMode from './pages/MeetingMode';
import AdminConsole from './pages/AdminConsole';
import AdvisorTemplates from './pages/AdvisorTemplates';
import RiskQuestionnaireBuilder from './pages/RiskQuestionnaireBuilder';
import ActivityLog from './pages/ActivityLog';
import PlanReviewQueue from './pages/PlanReviewQueue';
import Settings from './pages/Settings';

// The landing route depends on role: advisors/admins get the client dashboard,
// clients get their own goals list. A client hitting "/" is redirected to /goals.
function Home() {
  const { user } = useAuth();
  if (user?.role === 'client') return <Navigate to="/goals" replace />;
  return <Dashboard />;
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <DemoTourProvider>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/signup" element={<Signup />} />
          {/* Unauthenticated recovery flow — must stay outside ProtectedRoute,
              the whole point is reaching it while locked out of a session. */}
          <Route path="/forgot-password" element={<ForgotPassword />} />
          <Route path="/reset-password" element={<ResetPassword />} />
          {/* Advisor invite activation (docs/09 Session 4) — same redemption
              mechanism as password reset, different landing-page copy. */}
          <Route path="/accept-invite" element={<ResetPassword mode="invite" />} />

          <Route path="/" element={<ProtectedRoute><Home /></ProtectedRoute>} />

          {/* Advisor: drill into one client's goals */}
          <Route
            path="/clients/:clientId"
            element={<ProtectedRoute><ClientGoals /></ProtectedRoute>}
          />

          {/* Client: their own goals */}
          <Route path="/goals" element={<ProtectedRoute><GoalsList /></ProtectedRoute>} />

          {/* Goal detail — shared by both roles */}
          <Route path="/goals/:id" element={<ProtectedRoute><GoalDetail /></ProtectedRoute>} />

          {/* Printable/shareable client-meeting report for a single goal */}
          <Route path="/goals/:id/report" element={<ProtectedRoute><PlanReport /></ProtectedRoute>} />

          {/* docs/07 Bet 5 — full-screen guided presentation for a live client meeting */}
          <Route path="/goals/:id/meeting" element={<ProtectedRoute><MeetingMode /></ProtectedRoute>} />

          {/* MFA enrollment is mandatory — ProtectedRoute redirects any
              unenrolled session to /settings (state.mfaRequired), backed by a
              server-side 403 on every other endpoint. AppHeader's amber dot
              is a secondary nudge on top of the gate, not the only signal. */}
          {/* Super Admin console — role is enforced server-side on every
              endpoint; AdminConsole also client-guards and redirects non-admins. */}
          <Route path="/admin" element={<ProtectedRoute><AdminConsole /></ProtectedRoute>} />

          {/* Strategy Templates — advisor/super_admin view; client-side guard in page,
              server-side gate on every API call. */}
          <Route path="/templates" element={<ProtectedRoute><AdvisorTemplates /></ProtectedRoute>} />

          {/* docs/06 Section B — advisor/super_admin manage the firm's risk
              questionnaire + scoring rubric. Server-enforced (verifyAccess 'advisor'). */}
          <Route path="/risk-questionnaire" element={<ProtectedRoute><RiskQuestionnaireBuilder /></ProtectedRoute>} />

          {/* docs/09 Session 6 — firm-wide read-only audit trail; advisor/super_admin
              view, server-enforced (verifyAccessAny ['advisor', 'super_admin']). */}
          <Route path="/activity" element={<ProtectedRoute><ActivityLog /></ProtectedRoute>} />

          {/* Jr -> Sr Advisor Plan-Approval Workflow — advisor/super_admin
              view; server-enforced (verifyAccessAny ['advisor', 'super_admin']
              on goals_review_queue.php, requireFirmRole on the actual
              approve/request-changes action in goals_review.php). */}
          <Route path="/reviews" element={<ProtectedRoute><PlanReviewQueue /></ProtectedRoute>} />

          <Route path="/settings" element={<ProtectedRoute><Settings /></ProtectedRoute>} />

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
        </DemoTourProvider>
      </AuthProvider>
    </BrowserRouter>
  );
}
