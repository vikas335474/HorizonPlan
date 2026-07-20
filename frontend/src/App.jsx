import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import ProtectedRoute from './components/ProtectedRoute';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import ClientGoals from './pages/ClientGoals';
import GoalsList from './pages/GoalsList';
import GoalDetail from './pages/GoalDetail';

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
        <Routes>
          <Route path="/login" element={<Login />} />

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

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
