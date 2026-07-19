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

async function request(path, options = {}) {
  const res = await fetch(`${BASE}/${path}`, {
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
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

  if (!res.ok) {
    throw new ApiError(body?.message || `Request failed (${res.status})`, res.status, body);
  }

  return body;
}

export const api = {
  login: (email, password) =>
    request('login.php', { method: 'POST', body: JSON.stringify({ email, password }) }),

  logout: () => request('logout.php', { method: 'POST' }),

  session: () => request('session.php', { method: 'GET' }),

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

  // Retirement-type goals only — goals_projection.php 400s otherwise.
  // subScenarioId is optional: omit it to project the parent goal's own
  // values, pass it to project a specific (possibly overridden) scenario.
  getProjection: (goalId, subScenarioId) => {
    const params = new URLSearchParams({ id: String(goalId) });
    if (subScenarioId) params.set('sub_scenario_id', String(subScenarioId));
    return request(`goals_projection.php?${params.toString()}`);
  },
};

export { ApiError };
