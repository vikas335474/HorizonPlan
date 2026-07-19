import { createContext, useContext, useEffect, useState, useCallback } from 'react';
import { api, ApiError } from '../lib/api';

const AuthContext = createContext(null);

// session.php returns { user_id, tenant_id, role }; login.php returns
// { id, tenant_id, role } for the same person — normalize to one shape here
// so the rest of the app doesn't have to know about the mismatch.
function normalizeUser(raw) {
  if (!raw) return null;
  return {
    userId: raw.user_id ?? raw.id,
    tenantId: raw.tenant_id,
    role: raw.role,
  };
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    api
      .session()
      .then((res) => {
        if (!cancelled) setUser(normalizeUser(res.user));
      })
      .catch((err) => {
        // 401 "no active session" is the expected logged-out state, not a
        // real error — anything else we still just treat as logged-out for
        // UI purposes, but don't swallow it silently in dev.
        if (!(err instanceof ApiError) || err.status !== 401) {
          console.error('Session check failed:', err);
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const login = useCallback(async (email, password) => {
    const res = await api.login(email, password);
    setUser(normalizeUser(res.user));
    return res;
  }, []);

  const logout = useCallback(async () => {
    await api.logout().catch(() => {
      // Logout is idempotent server-side; even if the request fails (e.g.
      // network blip) we still clear local state so the UI reflects intent.
    });
    setUser(null);
  }, []);

  return (
    <AuthContext.Provider value={{ user, loading, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
