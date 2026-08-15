// docs/13 I-9 · The monthly-summary opt-in.
//
// Renders nothing outside a personal tenant — a firm's client has an adviser
// whose job the follow-up is, and personal_digest_pref.php 403s them anyway.
//
// WHY THIS IS A REAL CHOICE AND NOT A PRE-TICKED BOX. The growth-optimal move
// is to default this on and let people unsubscribe. This codebase does not do
// that: sql/041 defaults the column to 0, and the copy below asks rather than
// informs. That follows CLAUDE.md's standing "default new toggles OFF" rule
// and the PlanReviewMailer precedent, and it is the same restraint the rest of
// the product is built on — an inbox is the easiest thing in this business to
// abuse, and this is a planning tool, not a re-engagement funnel.
//
// Placed both in Settings (where a person goes to change it) and on the
// post-onboarding proof screen (where the offer is actually relevant, because
// they have just built the thing being summarised).

import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';

/**
 * @param compact  true on the onboarding proof screen — no card chrome, since
 *                 it already sits inside one.
 */
export default function DigestPreference({ compact = false }) {
  const { tenant } = useAuth();
  const isSelfDirected = tenant?.kind === 'personal';

  const [optIn, setOptIn] = useState(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isSelfDirected) return undefined;
    let cancelled = false;
    api
      .getDigestPref()
      .then((res) => { if (!cancelled) setOptIn(Boolean(res.opt_in)); })
      .catch(() => { if (!cancelled) setOptIn(false); });
    return () => { cancelled = true; };
  }, [isSelfDirected]);

  if (!isSelfDirected || optIn === null) return null;

  async function toggle(next) {
    setSaving(true);
    setError('');
    // Optimistic: a checkbox that lags behind the click feels broken. Reverted
    // below if the write actually fails.
    setOptIn(next);
    try {
      await api.setDigestPref(next);
    } catch (err) {
      setOptIn(!next);
      setError(err.message || 'Could not save that preference.');
    } finally {
      setSaving(false);
    }
  }

  const control = (
    <>
      <label className="flex cursor-pointer items-start gap-3">
        <input
          type="checkbox"
          checked={optIn}
          disabled={saving}
          onChange={(e) => toggle(e.target.checked)}
          className="mt-0.5 h-4 w-4 shrink-0 cursor-pointer accent-[var(--color-teal)]"
        />
        <span>
          <span className="block text-sm font-medium text-[var(--color-ink)]">
            Email me a short summary once a month
          </span>
          <span className="mt-0.5 block text-xs leading-relaxed text-[var(--color-ink-2)]">
            Where your plan stands, in a few lines. Facts about your own plan — never advice, and
            never a sales email. Turn it off here any time.
          </span>
        </span>
      </label>
      {error && (
        <p className="mt-2 text-xs" style={{ color: 'var(--color-alert)' }}>{error}</p>
      )}
    </>
  );

  if (compact) return <div>{control}</div>;

  return (
    <div className="rounded-lg border border-[var(--color-line-2)] p-4">{control}</div>
  );
}
