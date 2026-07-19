import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import ProtectedRoute from './components/ProtectedRoute';
import Login from './pages/Login';
import GoalsList from './pages/GoalsList';
import GoalDetail from './pages/GoalDetail';

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route
            path="/goals"
            element={
              <ProtectedRoute>
                <GoalsList />
              </ProtectedRoute>
            }
          />
          <Route
            path="/goals/:id"
            element={
              <ProtectedRoute>
                <GoalDetail />
              </ProtectedRoute>
            }
          />
          <Route path="/" element={<Navigate to="/goals" replace />} />
          <Route path="*" element={<Navigate to="/goals" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
