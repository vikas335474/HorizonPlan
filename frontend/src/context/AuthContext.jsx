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
    // login.php / mfa_verify.php / session.php all now carry mfa_enrolled.
    // Coerce to a real boolean so consumers can rely on it (the soft MFA gate,
    // the header nudge dot) without re-checking for undefined.
    mfaEnrolled: !!raw.mfa_enrolled,
  };
}

// Tenant context (company name, compliance mode, white-label branding). Only
// session.php returns this today, so it populates on bootstrap and after a
// session refresh. advisoryMode defaults to the conservative 'distribution'
// copy whenever it's unknown — the compliance-safe fallback (docs/02 3.6).
function normalizeTenant(raw) {
  if (!raw) return null;
  return {
    companyName: raw.company_name ?? null,
    advisoryMode: raw.advisory_mode === 'advisory' ? 'advisory' : 'distribution',
    whiteLabel: raw.white_label ?? null,
  };
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [tenant, setTenant] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    api
      .session()
      .then((res) => {
        if (!cancelled) {
          setUser(normalizeUser(res.user));
          setTenant(normalizeTenant(res.tenant));
        }
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

  // Re-fetch session.php and update user + tenant state. Needed after MFA
  // enrollment (mfa_enroll_confirm.php returns only { status, message }) and
  // after login (login.php doesn't carry the tenant block). Defined before the
  // callbacks that depend on it to avoid a temporal-dead-zone reference.
  const refreshSession = useCallback(async () => {
    try {
      const res = await api.session();
      const normalized = normalizeUser(res.user);
      setUser(normalized);
      setTenant(normalizeTenant(res.tenant));
      return normalized;
    } catch {
      // A failed refresh shouldn't tear down the current session state — the
      // existing user stays until an explicit logout or a hard 401 elsewhere.
      return null;
    }
  }, []);

  // login() returns { mfaRequired: true } if the server returned 202, or
  // { mfaRequired: false, user } with the normalized user object if MFA is not
  // enrolled. Returning the user lets callers route by role synchronously,
  // without waiting for the setUser state flush to land.
  const login = useCallback(async (email, password) => {
    const res = await api.login(email, password);

    if (res._httpStatus === 202 || res.status === 'mfa_required') {
      // Password verified, but MFA step is needed — don't set user yet.
      // The pending cookie is now set server-side; Login.jsx transitions
      // to the OTP step and will call mfaVerify() next.
      return { mfaRequired: true, user: null };
    }

    const normalized = normalizeUser(res.user);
    setUser(normalized);
    // login.php doesn't carry the tenant block; pull it in the background so the
    // compliance disclosure and branding are correct without blocking routing.
    refreshSession();
    return { mfaRequired: false, user: normalized };
  }, [refreshSession]);

  // mfaVerify() is called from Login.jsx after the user enters their OTP.
  // On success the server issues a full session and the CSRF cookie. Returns
  // the normalized user so callers can route by role without a state flush.
  const mfaVerify = useCallback(async (code) => {
    const res = await api.mfaVerify(code);
    const normalized = normalizeUser(res.user);
    setUser(normalized);
    refreshSession(); // pull the tenant block in the background, as login() does
    return normalized;
  }, [refreshSession]);

  const logout = useCallback(async () => {
    await api.logout().catch(() => {
      // Idempotent — clear local state even if the network request fails.
    });
    setUser(null);
    setTenant(null);
  }, []);

  return (
    <AuthContext.Provider value={{ user, tenant, loading, login, mfaVerify, logout, refreshSession }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
