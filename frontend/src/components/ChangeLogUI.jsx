// Audit-trail UI. ChangeLogPanel({entityType, entityId, tenantId, pageSize})
// fetches paginated change_log history via api.getChangeLog and renders it;
// ChangeLogCard wraps that panel in a titled Card. Used by GoalDetail (a goal's
// own History) and ActivityLog (the firm-wide feed, no entity filter).

import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { Card, Spinner, Button } from './ui';

// docs/09 Session 6 — read-only audit trail. Renders change_log rows as
// "advisor@firm.in changed withdrawal_rate from 3.5 to 4.0 on ...". Used both
// entity-scoped (GoalDetail.jsx's History tab) and firm-wide (ActivityLog.jsx).

const ENTITY_LABELS = {
  base_plan: 'goal',
  sub_scenario: 'scenario',
};

function formatFieldName(field) {
  if (field === 'created') return null; // handled specially below
  if (field === 'is_overridden') return 'override status';
  return field.replace(/_/g, ' ');
}

function formatValue(value) {
  if (value === null || value === undefined || value === '') return '(empty)';
  return value;
}

function formatTimestamp(ts) {
  try {
    return new Date(ts.replace(' ', 'T') + 'Z').toLocaleString(undefined, {
      dateStyle: 'medium',
      timeStyle: 'short',
    });
  } catch {
    return ts;
  }
}

function EntryLine({ entry }) {
  const who = entry.changed_by_email || `user #${entry.changed_by_user_id}`;
  const entityLabel = ENTITY_LABELS[entry.entity_type] || entry.entity_type;

  let description;
  if (entry.field_changed === 'created') {
    description = <>created this {entityLabel}</>;
  } else if (entry.field_changed === 'is_overridden' && entry.new_value === '1') {
    description = <>froze this {entityLabel} from cascade updates</>;
  } else if (entry.field_changed === 'is_overridden' && entry.new_value === '0') {
    description = <>reset this {entityLabel} to follow cascade updates</>;
  } else {
    description = (
      <>
        changed <strong className="font-medium text-[var(--color-ink)]">{formatFieldName(entry.field_changed)}</strong> from{' '}
        <span className="tnum">{formatValue(entry.old_value)}</span> to{' '}
        <span className="tnum font-medium text-[var(--color-ink)]">{formatValue(entry.new_value)}</span>
      </>
    );
  }

  return (
    <div className="py-2.5 text-sm text-[var(--color-ink-2)]">
      <span className="font-medium text-[var(--color-ink)]">{who}</span> {description}
      <span className="text-[var(--color-ink-3)]"> · {formatTimestamp(entry.changed_at)}</span>
      {entry.entity_type === 'sub_scenario' && (
        <span className="text-[var(--color-ink-3)]"> · scenario #{entry.entity_id}</span>
      )}
    </div>
  );
}

// entityType/entityId scope this to one goal (or one sub-scenario's parent
// goal, via base_plan + goal id — sub_scenario rows for that goal's children
// are pulled in separately by GoalDetail via the per-scenario history, out
// of scope here). Omit both for a firm-wide feed. tenantId is an optional
// super_admin cross-tenant override, ignored server-side otherwise.
export default function ChangeLogPanel({ entityType, entityId, tenantId, pageSize = 20, emptyMessage }) {
  const [data, setData] = useState(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError('');
    api.getChangeLog({ entityType, entityId, tenantId, page, perPage: pageSize })
      .then((res) => { if (!cancelled) setData(res); })
      .catch((err) => { if (!cancelled) setError(err.message || 'Could not load history.'); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [entityType, entityId, tenantId, page, pageSize]);

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

  return (
    <div>
      {loading && <Spinner label="Loading history…" />}

      {error && (
        <p className="text-sm" style={{ color: 'var(--color-alert)' }}>{error}</p>
      )}

      {!loading && !error && data && data.entries.length === 0 && (
        <p className="text-sm text-[var(--color-ink-2)]">
          {emptyMessage || 'No changes recorded yet.'}
        </p>
      )}

      {!loading && !error && data && data.entries.length > 0 && (
        <>
          <div className="divide-y divide-[var(--color-line)]">
            {data.entries.map((entry) => (
              <EntryLine key={entry.id} entry={entry} />
            ))}
          </div>

          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-3 pt-3 border-t border-[var(--color-line)]">
              <span className="text-xs text-[var(--color-ink-3)]">
                Page {data.page} of {totalPages} · {data.total} total
              </span>
              <div className="flex gap-2">
                <Button
                  variant="outline" size="sm"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                >
                  ← Newer
                </Button>
                <Button
                  variant="outline" size="sm"
                  disabled={page >= totalPages}
                  onClick={() => setPage((p) => p + 1)}
                >
                  Older →
                </Button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}

export function ChangeLogCard(props) {
  return (
    <Card className="p-4 mb-4">
      <h2 className="text-base font-semibold text-[var(--color-ink)] mb-2">History</h2>
      <ChangeLogPanel {...props} />
    </Card>
  );
}
