import { createContext, useContext, useEffect, useState, useCallback } from 'react';
import { api, ApiError } from '../lib/api';

const AuthContext = createContext(null);

// session.php returns { user_id, tenant_id, role }; login.php and mfa_verify.php
// return { id, tenant_id, role } — normalize once here so the rest of the app
// never has to know about this mismatch.
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
        // 401 "no active session" is expected on page load — not an error.
        if (!(err instanceof ApiError) || err.status !== 401) {
          console.error('Session check failed:', err);
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, []);

  // login() returns { mfaRequired: true } if the server returned 202,
  // or sets user state directly if MFA is not enrolled.
  const login = useCallback(async (email, password) => {
    const res = await api.login(email, password);

    if (res._httpStatus === 202 || res.status === 'mfa_required') {
      // Password verified, but MFA step is needed — don't set user yet.
      // The pending cookie is now set server-side; Login.jsx transitions
      // to the OTP step and will call mfaVerify() next.
      return { mfaRequired: true };
    }

    setUser(normalizeUser(res.user));
    return { mfaRequired: false };
  }, []);

  // mfaVerify() is called from Login.jsx after the user enters their OTP.
  // On success the server issues a full session and the CSRF cookie.
  const mfaVerify = useCallback(async (code) => {
    const res = await api.mfaVerify(code);
    setUser(normalizeUser(res.user));
  }, []);

  const logout = useCallback(async () => {
    await api.logout().catch(() => {
      // Idempotent — clear local state even if the network request fails.
    });
    setUser(null);
  }, []);

  return (
    <AuthContext.Provider value={{ user, loading, login, mfaVerify, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
