// Every endpoint lives at /api/<name>.php (see CLAUDE.md deploy notes: the
// frontend builds into public_html alongside public_html/api, same origin —
// no CORS, no token in localStorage, the httpOnly session cookie rides along
// automatically as long as we send credentials).

const BASE = '/api';

class ApiError extends Error {
  constructor(message, status, body) {
    super(message);
    this.status = status;
    this.body = body;
  }
}

// CSRF: read the double-submit cookie the server sets after login / session check.
// The cookie is non-httpOnly by design so JS can read it here.
// Returns '' if the cookie isn't present yet (e.g. before first login).
function getCsrfToken() {
  const match = document.cookie
    .split('; ')
    .find((c) => c.startsWith('hp_csrf='));
  return match ? match.split('=')[1] : '';
}

// State-changing methods (POST/PUT/PATCH/DELETE) automatically attach the
// CSRF token as a header. GET requests don't need it (server is exempt for
// read-only methods, per verifyCsrfToken() in security_gatekeeper.php).
const MUTATION_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

async function request(path, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const csrfHeaders = MUTATION_METHODS.has(method)
    ? { 'X-CSRF-Token': getCsrfToken() }
    : {};

  const res = await fetch(`${BASE}/${path}`, {
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      ...csrfHeaders,
      ...(options.headers || {}),
    },
    ...options,
  });

  let body = null;
  try {
    body = await res.json();
  } catch {
    // Non-JSON response (e.g. a raw 500 from a fatal PHP error) — surface
    // status code, don't pretend we parsed something we didn't.
  }

  // 202 is a valid non-error response (mfa_required after password check) —
  // don't throw for it, let callers inspect body.status instead.
  if (!res.ok && res.status !== 202) {
    throw new ApiError(body?.message || `Request failed (${res.status})`, res.status, body);
  }

  return { ...body, _httpStatus: res.status };
}

export const api = {
  login: (email, password) =>
    request('login.php', { method: 'POST', body: JSON.stringify({ email, password }) }),

  // MFA: submit OTP after login.php returns mfa_required
  mfaVerify: (code) =>
    request('mfa_verify.php', { method: 'POST', body: JSON.stringify({ code }) }),

  // "Sign in with Google" — credential is the ID token Google Identity
  // Services hands back from the button's callback. A verified Google login
  // counts as MFA in its own right, so there's no second step after this one.
  authGoogle: (credential) =>
    request('auth_google.php', { method: 'POST', body: JSON.stringify({ credential }) }),

  // Unlink Google from the signed-in user's own account (Settings page).
  unlinkGoogle: () => request('google_unlink.php', { method: 'POST' }),

  // MFA enrollment (called from settings/profile, not login flow)
  mfaEnroll: () =>
    request('mfa_enroll.php', { method: 'POST' }),

  mfaEnrollConfirm: (code) =>
    request('mfa_enroll_confirm.php', { method: 'POST', body: JSON.stringify({ code }) }),

  // Self-service password change — requires re-entering the current password
  // (verified server-side), not just an active session.
  updatePassword: (currentPassword, newPassword) =>
    request('password_update.php', {
      method: 'POST',
      body: JSON.stringify({ current_password: currentPassword, new_password: newPassword }),
    }),

  // Forgot-password flow. Both are unauthenticated (no session/CSRF cookie
  // exists yet, same as login/mfaVerify) — see password_reset_request.php /
  // password_reset_confirm.php for why that's safe here.
  requestPasswordReset: (email) =>
    request('password_reset_request.php', { method: 'POST', body: JSON.stringify({ email }) }),

  confirmPasswordReset: (token, newPassword) =>
    request('password_reset_confirm.php', {
      method: 'POST',
      body: JSON.stringify({ token, new_password: newPassword }),
    }),

  logout: () => request('logout.php', { method: 'POST' }),

  session: () => request('session.php', { method: 'GET' }),

  // --- Super Admin console (super_admin sessions only; server-enforced) ---
  // List every advisory firm with mode, branding, and head-counts.
  listTenants: () => request('tenants_list.php'),

  // Full profile for one firm: the actual advisor list (not just a count) plus a goal count.
  getTenantDetail: (tenantId) => request(`tenant_detail.php?tenant_id=${encodeURIComponent(tenantId)}`),

  // Create a firm, optionally with its first advisor in the same step.
  // `firstAdvisor` (optional): { email, temporary_password }.
  createTenant: (companyName, advisoryMode = 'distribution', firstAdvisor) =>
    request('tenants_create.php', {
      method: 'POST',
      body: JSON.stringify({
        company_name: companyName,
        advisory_mode: advisoryMode,
        ...(firstAdvisor ? { first_advisor: firstAdvisor } : {}),
      }),
    }),

  // Update a firm's advisory_mode and/or white-label branding. `changes` is a
  // subset of { advisory_mode, white_label } — white_label is an object
  // { company_name?, logo_url?, primary_color? } or null to clear it.
  updateTenant: (tenantId, changes) =>
    request('tenant_update.php', {
      method: 'POST',
      body: JSON.stringify({ tenant_id: tenantId, ...changes }),
    }),

  // Add an advisor to an existing firm. firmRole (docs/09 Piece 2) is
  // optional — omit for the pre-migration default (treated as sr_advisor).
  createAdvisor: (tenantId, email, temporaryPassword, firmRole) =>
    request('admin_advisor_create.php', {
      method: 'POST',
      body: JSON.stringify({
        tenant_id: tenantId, email, temporary_password: temporaryPassword,
        ...(firmRole ? { firm_role: firmRole } : {}),
      }),
    }),

  // --- Platform settings (docs/09 Piece 1, super_admin only) ---
  getPlatformSettings: () => request('platform_settings_read.php'),

  // changes: a subset of { mfa_enforcement: 'enabled'|'disabled', demo_mode: 'off'|'on' }
  updatePlatformSettings: (changes) =>
    request('platform_settings_update.php', { method: 'POST', body: JSON.stringify(changes) }),

  // Only works when demo_mode is 'on' (403 otherwise). Returns demo login credentials.
  resetDemoData: () => request('demo_reset.php', { method: 'POST' }),

  // Advisor dashboard: all clients in the tenant + aggregate stats.
  listClients: () => request('clients_list.php'),

  // Advisor onboards a new client into their tenant.
  createClient: (email, temporaryPassword) =>
    request('clients_create.php', {
      method: 'POST',
      body: JSON.stringify({ email, temporary_password: temporaryPassword }),
    }),

  // Advisor creates a goal for a client. `fields` matches goals_create.php's
  // expected shape: { client_id, goal_type, goal_label, initial_net_worth,
  // inflation_rate, projection_horizon_years, and optionally target_amount,
  // target_date, withdrawal_rate }.
  createGoal: (fields) =>
    request('goals_create.php', { method: 'POST', body: JSON.stringify(fields) }),

  // clientId is only meaningful for advisor/super_admin sessions — a client
  // session ignores any client_id sent and always gets their own goals
  // (enforced server-side in goals_list.php, not just here).
  listGoals: (clientId) =>
    request(`goals_list.php${clientId ? `?client_id=${encodeURIComponent(clientId)}` : ''}`),

  getGoal: (id, subScenarioId) => {
    const params = new URLSearchParams({ id: String(id) });
    if (subScenarioId) params.set('sub_scenario_id', String(subScenarioId));
    return request(`goals_read.php?${params.toString()}`);
  },

  // Partial update — only keys present in fields are changed, mirroring
  // updateSubScenario. Used today for docs/07 Session C's accumulation-phase
  // fields (current_age, retirement_age, accumulation_return_rate,
  // monthly_sip_amount, sip_step_up_rate) — the only base-goal fields with an
  // edit-after-creation UI so far.
  updateGoal: (id, fields) =>
    request('goals_update.php', { method: 'POST', body: JSON.stringify({ id, ...fields }) }),

  listSubScenarios: (basePlanId) =>
    request(`subscenarios_list.php?base_plan_id=${encodeURIComponent(basePlanId)}`),

  createSubScenario: (basePlanId) =>
    request('subscenarios_create.php', {
      method: 'POST',
      body: JSON.stringify({ base_plan_id: basePlanId }),
    }),

  // fields is a subset of { custom_inflation, custom_withdrawal_rate,
  // custom_drawdown_return_rate } — subscenarios_update.php only touches
  // keys actually present in the body, and flips is_overridden=1 as soon as
  // any of them differ from the stored value (single shared flag, see
  // docs/02 4.2 — not per-field).
  updateSubScenario: (id, fields) =>
    request('subscenarios_update.php', {
      method: 'POST',
      body: JSON.stringify({ id, ...fields }),
    }),

  resetSubScenario: (id) =>
    request('subscenarios_reset.php', { method: 'POST', body: JSON.stringify({ id }) }),

  // --- Strategy Templates (Phase 1) ---
  // templates_list.php: returns { global_templates, my_templates, my_customizations }
  listTemplates: () =>
    request('templates_list.php', { method: 'POST' }),

  // templates_library.php: searchable discovery (GET, no CSRF needed).
  // q=search string, risk_profile=conservative|moderate|balanced|aggressive,
  // market_code=IN (default).
  searchTemplateLibrary: (q = '', riskProfile = '', marketCode = '') => {
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (riskProfile) params.set('risk_profile', riskProfile);
    if (marketCode) params.set('market_code', marketCode);
    const qs = params.toString();
    return request(`templates_library.php${qs ? `?${qs}` : ''}`);
  },

  // templates_create.php: advisor or super_admin creates a new template.
  // is_system_template is only honoured for super_admin sessions (server-enforced).
  createTemplate: (fields) =>
    request('templates_create.php', { method: 'POST', body: JSON.stringify(fields) }),

  // templates_fork.php: advisor forks a published template into a customization.
  // Returns { customization_id }.
  forkTemplate: (baseTemplateId, fields = {}) =>
    request('templates_fork.php', {
      method: 'POST',
      body: JSON.stringify({ base_template_id: baseTemplateId, ...fields }),
    }),

  // templates_customize.php: edit an existing customization (own-tenant only).
  // Partial update — only keys present in fields are changed.
  updateTemplateCustomization: (id, fields) =>
    request('templates_customize.php', { method: 'POST', body: JSON.stringify({ id, ...fields }) }),

  // templates_audit.php: full provenance trail for a template or customization.
  getTemplateAudit: (templateId, customizationId) =>
    request('templates_audit.php', {
      method: 'POST',
      body: JSON.stringify({
        ...(templateId ? { template_id: templateId } : {}),
        ...(customizationId ? { customization_id: customizationId } : {}),
      }),
    }),

  // templates_approve.php: marks a template/customization ready for use on a
  // real client plan (docs/07 Bet 1). Global templates require super_admin;
  // own-tenant templates/customizations require an advisor in that tenant.
  approveTemplate: (templateId, customizationId) =>
    request('templates_approve.php', {
      method: 'POST',
      body: JSON.stringify({
        ...(templateId ? { template_id: templateId } : {}),
        ...(customizationId ? { customization_id: customizationId } : {}),
      }),
    }),

  // goals_apply_template.php: the loop-closer — writes an approved
  // template's/customization's return_assumption_pct into the goal's
  // drawdown_return_rate, cascades to non-overridden sub-scenarios, and
  // records provenance. Retirement-type goals only; 403s if unapproved.
  applyTemplateToGoal: (goalId, { templateId, customizationId } = {}) =>
    request('goals_apply_template.php', {
      method: 'POST',
      body: JSON.stringify({
        goal_id: goalId,
        ...(templateId ? { template_id: templateId } : {}),
        ...(customizationId ? { customization_id: customizationId } : {}),
      }),
    }),

  // Retirement-type goals only — goals_projection.php 400s otherwise.
  // subScenarioId is optional: omit it to project the parent goal's own
  // values, pass it to project a specific (possibly overridden) scenario.
  // replayStartYear (docs/07 Bet 2) is optional — pass a year from
  // listMarketHistoryYears() to also get historical_sequence_series back.
  getProjection: (goalId, subScenarioId, replayStartYear) => {
    const params = new URLSearchParams({ id: String(goalId) });
    if (subScenarioId) params.set('sub_scenario_id', String(subScenarioId));
    if (replayStartYear) params.set('replay_start_year', String(replayStartYear));
    return request(`goals_projection.php?${params.toString()}`);
  },

  // market_history_years.php: which calendar years are available for the
  // "replay history from…" control, and whether each is verified yet
  // (docs/07 Bet 2 — sql/015 seeds these as unverified until reviewed).
  listMarketHistoryYears: () => request('market_history_years.php'),

  // --- Risk profiler (docs/06 Section B) ---
  // Returns { question_set: null } if the firm hasn't set one up yet.
  getRiskQuestionSet: () => request('risk_question_set_read.php'),

  // fields: { questions, scoring_rubric } — always a full replace (there's
  // only ever one active set per tenant); resets to draft on every save.
  updateRiskQuestionSet: (fields) =>
    request('risk_question_set_update.php', { method: 'POST', body: JSON.stringify(fields) }),

  approveRiskQuestionSet: () =>
    request('risk_question_set_approve.php', { method: 'POST', body: '{}' }),

  // answers: { [question_id]: selected_value }. Captured regardless of the
  // question set's approval state — suggested_return_assumption_pct in the
  // response is null until a firm principal approves the set.
  submitRiskProfile: (clientId, answers) =>
    request('risk_profile_submit.php', {
      method: 'POST',
      body: JSON.stringify({ client_id: clientId, answers }),
    }),

  // clientId is ignored for a client session (forced server-side to their own).
  getRiskProfile: (clientId) =>
    request(`risk_profile_read.php${clientId ? `?client_id=${encodeURIComponent(clientId)}` : ''}`),

  // --- Client portfolio ledger (docs/05 item 3 / docs/06 corpus composition) ---
  // clientId is ignored for a client session (forced server-side to their own).
  getClientPortfolio: (clientId) =>
    request(`client_portfolio_list.php${clientId ? `?client_id=${encodeURIComponent(clientId)}` : ''}`),

  // fields: { client_id, item_kind: 'asset'|'liability', bucket: 'liquid'|'locked' (assets only),
  // category, description, value }
  createPortfolioItem: (fields) =>
    request('client_portfolio_create.php', { method: 'POST', body: JSON.stringify(fields) }),

  updatePortfolioItem: (id, fields) =>
    request('client_portfolio_update.php', { method: 'POST', body: JSON.stringify({ id, ...fields }) }),

  deletePortfolioItem: (id) =>
    request('client_portfolio_delete.php', { method: 'POST', body: JSON.stringify({ id }) }),
};

export { ApiError };
