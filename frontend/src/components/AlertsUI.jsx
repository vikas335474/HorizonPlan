// docs/12 Prompt D-4 · The alerts panel — the unifying surface both the
// individual's own dashboard and an advisor's per-client page read through.
// Two exports, same shape as FoundationsUI/CashFlowUI:
//
//   AlertsCard        — advisor view on a client page
//   ClientAlertsCard   — the client's / individual's own view
//
// Data comes from api.getAlerts (alerts_read.php) — recomputed fresh on
// every load, no acknowledge/dismiss yet (see AlertsEngine.php's header for
// why this ships stateless-only this session).
//
// TONE RULE: every alert states a fact, never an instruction — see
// AlertsEngine.php's own header. This component only ever renders what the
// server sends; it never adds a verb of its own ("sell", "should", etc).

import { useEffect, useState, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { Card, Spinner } from './ui';

const SEVERITY_STYLE = {
  positive:  { dot: 'var(--color-teal)',  label: 'Good news' },
  attention: { dot: 'var(--color-amber)', label: 'Needs attention' },
};

function AlertRow({ alert }) {
  const style = SEVERITY_STYLE[alert.severity] ?? SEVERITY_STYLE.attention;
  return (
    <li className="flex gap-2.5 py-2.5 border-b border-[var(--color-line)] last:border-0">
      <span
        className="mt-1.5 h-2 w-2 shrink-0 rounded-full"
        style={{ backgroundColor: style.dot }}
        aria-hidden="true"
      />
      <div className="min-w-0 flex-1">
        <span className="text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">{style.label}</span>
        <p className="mt-0.5 text-sm leading-relaxed text-[var(--color-ink)]">{alert.factual_message}</p>
      </div>
      {alert.deep_link && (
        <Link
          to={alert.deep_link}
          className="mt-1 shrink-0 self-start text-xs font-medium text-[var(--color-teal-ink)] hover:underline"
        >
          View
        </Link>
      )}
    </li>
  );
}

// Shared body. `clientId` is passed through to api.getAlerts unchanged — null
// for a client session, which the server forces to the caller's own id.
function AlertsBody({ clientId, title, blurb, emptyText }) {
  const [alerts, setAlerts] = useState(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(() => {
    let cancelled = false;
    setLoading(true);
    api
      .getAlerts(clientId)
      .then((res) => { if (!cancelled) setAlerts(res.alerts); })
      .catch(() => { /* soft — an unreadable alerts panel just shows nothing */ })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [clientId]);

  useEffect(() => load(), [load]);

  // Nothing to say and nothing loading: this card simply doesn't render,
  // same convention as FoundationsCaveat — a card that's always visible even
  // when empty stops being read.
  if (!loading && (alerts === null || alerts.length === 0)) {
    return null;
  }

  return (
    <Card className="p-4 mb-4">
      <div>
        <h2 className="text-base font-semibold text-[var(--color-ink)]">{title}</h2>
        <p className="mt-0.5 text-xs text-[var(--color-ink-2)]">{blurb}</p>
      </div>

      {loading && <div className="mt-3"><Spinner label="Loading…" /></div>}

      {!loading && alerts && alerts.length > 0 && (
        <ul className="mt-2">
          {alerts.map((a, i) => (
            <AlertRow key={`${a.type}-${JSON.stringify(a.subject)}-${i}`} alert={a} />
          ))}
        </ul>
      )}

      {!loading && alerts && alerts.length === 0 && emptyText && (
        <p className="mt-2 text-xs text-[var(--color-ink-2)]">{emptyText}</p>
      )}
    </Card>
  );
}

// Advisor view on a client page.
export function AlertsCard({ clientId }) {
  return (
    <AlertsBody
      clientId={clientId}
      title="Alerts"
      blurb="What's currently true about this client's plan — a goal reaching target, a plan drifting behind, a price gone stale, or a review coming due."
    />
  );
}

// The client's / individual's own view.
export function ClientAlertsCard() {
  return (
    <AlertsBody
      clientId={null}
      title="What's changed"
      blurb="Anything worth knowing about your plan right now."
    />
  );
}
