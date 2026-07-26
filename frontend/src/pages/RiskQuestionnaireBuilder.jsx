import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import AppHeader from '../components/AppHeader';
import { Card, Button, Spinner } from '../components/ui';
import { ApprovalBadge } from '../components/TemplateUI';

// A firm-editable starting point ONLY — never persisted anywhere until the
// advisor clicks Save, and Save never auto-approves it. docs/06 guardrail 2:
// no HorizonPlan-authored rubric is ever presented as authoritative or
// pre-approved on a firm's behalf; this is just a convenience so an advisor
// isn't starting from a blank page, same spirit as the strategy templates'
// clearly-labelled seeded starters.
const EXAMPLE_QUESTIONS = [
  {
    text: 'How many years until this money is needed?',
    options: [
      { label: 'Less than 3 years', score: 0 },
      { label: '3–7 years', score: 2 },
      { label: 'More than 7 years', score: 4 },
    ],
  },
  {
    text: 'If your portfolio fell 20% in a month, what would you do?',
    options: [
      { label: 'Sell to limit further loss', score: 0 },
      { label: 'Hold and wait it out', score: 2 },
      { label: 'Invest more while prices are down', score: 4 },
    ],
  },
  {
    text: 'How would you describe your investing experience?',
    options: [
      { label: 'New to investing', score: 0 },
      { label: 'Some experience', score: 1 },
      { label: 'Experienced across market cycles', score: 2 },
    ],
  },
];
const EXAMPLE_BANDS = [
  { name: 'conservative', min: 0, max: 3, suggested_return_pct: '6' },
  { name: 'moderate', min: 4, max: 7, suggested_return_pct: '9' },
  { name: 'aggressive', min: 8, max: 10, suggested_return_pct: '12' },
];

export default function RiskQuestionnaireBuilder() {
  const { user } = useAuth();
  // docs/09 Piece 2: approval requires sr_advisor/firm_admin (or super_admin) —
  // mirrors the server-side requireFirmRole() gate in risk_question_set_approve.php.
  const canApprove = user?.role === 'super_admin' || user?.firmRole !== 'jr_advisor';
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState(null); // 'draft' | 'approved' | null (never created)
  const [questions, setQuestions] = useState([]);
  const [bands, setBands] = useState([]);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const [saveMessage, setSaveMessage] = useState('');

  function load() {
    setLoading(true);
    setError('');
    api
      .getRiskQuestionSet()
      .then((res) => {
        const set = res.question_set;
        if (!set) {
          setStatus(null);
          setQuestions([]);
          setBands([]);
          return;
        }
        setStatus(set.status);
        setQuestions(
          (set.questions || []).map((q) => ({
            text: q.text,
            options: (q.options || []).map((o) => ({ label: String(o.value), score: String(o.score) })),
          }))
        );
        setBands(
          (set.scoring_rubric?.bands || []).map((b) => ({
            name: b.name,
            min: String(b.min),
            max: String(b.max),
            suggested_return_pct: String(b.suggested_return_pct),
          }))
        );
      })
      .catch((err) => setError(err.message || 'Could not load the questionnaire.'))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
  }, []);

  function loadExample() {
    setQuestions(EXAMPLE_QUESTIONS.map((q) => ({ text: q.text, options: q.options.map((o) => ({ label: o.label, score: String(o.score) })) })));
    setBands(EXAMPLE_BANDS.map((b) => ({ ...b, min: String(b.min), max: String(b.max) })));
    setSaveMessage('');
  }

  function addQuestion() {
    setQuestions((qs) => [...qs, { text: '', options: [{ label: '', score: '0' }, { label: '', score: '1' }] }]);
  }
  function removeQuestion(qi) {
    setQuestions((qs) => qs.filter((_, i) => i !== qi));
  }
  function updateQuestionText(qi, text) {
    setQuestions((qs) => qs.map((q, i) => (i === qi ? { ...q, text } : q)));
  }
  function addOption(qi) {
    setQuestions((qs) => qs.map((q, i) => (i === qi ? { ...q, options: [...q.options, { label: '', score: '0' }] } : q)));
  }
  function removeOption(qi, oi) {
    setQuestions((qs) => qs.map((q, i) => (i === qi ? { ...q, options: q.options.filter((_, j) => j !== oi) } : q)));
  }
  function updateOption(qi, oi, field, value) {
    setQuestions((qs) =>
      qs.map((q, i) =>
        i === qi ? { ...q, options: q.options.map((o, j) => (j === oi ? { ...o, [field]: value } : o)) } : q
      )
    );
  }

  function addBand() {
    setBands((bs) => [...bs, { name: '', min: '', max: '', suggested_return_pct: '' }]);
  }
  function removeBand(bi) {
    setBands((bs) => bs.filter((_, i) => i !== bi));
  }
  function updateBand(bi, field, value) {
    setBands((bs) => bs.map((b, i) => (i === bi ? { ...b, [field]: value } : b)));
  }

  function validate() {
    if (questions.length === 0) return 'Add at least one question.';
    for (const q of questions) {
      if (!q.text.trim()) return 'Every question needs its text filled in.';
      if (q.options.length < 2) return 'Every question needs at least two options.';
      for (const o of q.options) {
        if (!o.label.trim()) return 'Every option needs a label.';
        if (o.score === '' || Number.isNaN(Number(o.score))) return 'Every option needs a numeric score.';
      }
    }
    if (bands.length === 0) return 'Add at least one rubric band.';
    for (const b of bands) {
      if (!b.name.trim()) return 'Every band needs a name.';
      if (b.min === '' || b.max === '' || b.suggested_return_pct === '') return 'Every band needs min, max, and a suggested return.';
      if (Number(b.min) > Number(b.max)) return `Band "${b.name}": min cannot be greater than max.`;
    }
    return null;
  }

  async function handleSave() {
    const validationError = validate();
    if (validationError) {
      setError(validationError);
      return;
    }
    setError('');
    setSaveMessage('');
    setSaving(true);
    try {
      const payload = {
        questions: questions.map((q, i) => ({
          id: `q${i + 1}`,
          text: q.text.trim(),
          options: q.options.map((o) => ({ value: o.label.trim(), score: Number(o.score) })),
        })),
        scoring_rubric: {
          bands: bands.map((b) => ({
            name: b.name.trim(),
            min: Number(b.min),
            max: Number(b.max),
            suggested_return_pct: Number(b.suggested_return_pct),
          })),
        },
      };
      const res = await api.updateRiskQuestionSet(payload);
      setSaveMessage(res.approval_reverted ? 'Saved — this edit reverted the questionnaire to draft.' : 'Saved.');
      await load();
    } catch (err) {
      setError(err.message || 'Could not save the questionnaire.');
    } finally {
      setSaving(false);
    }
  }

  async function handleApprove() {
    setError('');
    try {
      await api.approveRiskQuestionSet();
      await load();
    } catch (err) {
      setError(err.message || 'Could not approve the questionnaire.');
    }
  }

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-3xl px-5 py-8">
        <div className="mb-5 flex items-start justify-between gap-4">
          <div>
            <h1 className="text-xl font-semibold tracking-tight text-[var(--color-ink)]">Risk questionnaire</h1>
            <p className="mt-0.5 text-sm text-[var(--color-ink-2)]">
              The question set and scoring your firm uses to gauge a client's risk tolerance. Define it, review it,
              then approve it — a suggested return band never surfaces to a client plan until it's approved.
            </p>
          </div>
          {status !== null && <ApprovalBadge status={status} />}
        </div>

        {/* docs/06 guardrail 2 — this content is never HorizonPlan's own
            authoritative advice. Made explicit here, not just in code comments. */}
        <div
          className="mb-5 flex items-start gap-2 rounded-[var(--radius-ctrl)] px-3 py-2.5"
          style={{ backgroundColor: 'var(--color-amber-soft)' }}
        >
          <svg width="15" height="15" viewBox="0 0 15 15" fill="none" className="mt-0.5 shrink-0" aria-hidden="true">
            <path d="M7.5 1.5 1 12.5h13L7.5 1.5Z" stroke="var(--color-amber)" strokeWidth="1.1" strokeLinejoin="round" />
            <path d="M7.5 6v3M7.5 10.8h.01" stroke="var(--color-amber)" strokeWidth="1.3" strokeLinecap="round" />
          </svg>
          <p className="text-xs leading-relaxed" style={{ color: 'var(--color-amber)' }}>
            <strong>This is your firm's own content, not HorizonPlan's.</strong> Questions, scores, and suggested
            return assumptions below should come from your CFA/RIA — review them before approving. A suggested
            return only ever <em>informs</em> a plan; the advisor still sets the final number.
          </p>
        </div>

        {loading && <Spinner label="Loading questionnaire…" />}
        {error && (
          <p className="mb-4 text-sm rounded-[var(--radius-ctrl)] bg-[var(--color-alert-soft)] px-3 py-2" style={{ color: 'var(--color-alert)' }}>
            {error}
          </p>
        )}
        {saveMessage && !error && (
          <p className="mb-4 text-sm rounded-[var(--radius-ctrl)] px-3 py-2" style={{ color: 'var(--color-teal-ink)', backgroundColor: 'var(--color-teal-soft)' }}>
            {saveMessage}
          </p>
        )}

        {!loading && (
          <>
            {questions.length === 0 && (
              <Card className="p-6 text-center mb-4">
                <p className="text-sm text-[var(--color-ink-2)] mb-3">No questionnaire set up yet for this firm.</p>
                <div className="flex justify-center gap-2">
                  <Button onClick={loadExample} variant="outline">Prefill an example to edit</Button>
                  <Button onClick={addQuestion}>Start from scratch</Button>
                </div>
              </Card>
            )}

            {questions.map((q, qi) => (
              <Card key={qi} className="p-4 mb-3">
                <div className="flex items-start gap-2 mb-3">
                  <span className="tnum mt-2 text-xs font-semibold text-[var(--color-ink-3)]">Q{qi + 1}</span>
                  <input
                    type="text"
                    value={q.text}
                    onChange={(e) => updateQuestionText(qi, e.target.value)}
                    placeholder="Question text"
                    className="field flex-1"
                  />
                  <Button variant="ghost" size="sm" onClick={() => removeQuestion(qi)}>Remove</Button>
                </div>

                <div className="space-y-2 pl-6">
                  {q.options.map((o, oi) => (
                    <div key={oi} className="flex items-center gap-2">
                      <input
                        type="text"
                        value={o.label}
                        onChange={(e) => updateOption(qi, oi, 'label', e.target.value)}
                        placeholder="Option label"
                        className="field flex-1"
                      />
                      <input
                        type="number"
                        value={o.score}
                        onChange={(e) => updateOption(qi, oi, 'score', e.target.value)}
                        placeholder="Score"
                        className="field w-20"
                      />
                      <Button variant="ghost" size="sm" onClick={() => removeOption(qi, oi)}>×</Button>
                    </div>
                  ))}
                  <Button variant="outline" size="sm" onClick={() => addOption(qi)}>+ Option</Button>
                </div>
              </Card>
            ))}

            {questions.length > 0 && (
              <Button variant="outline" onClick={addQuestion} className="mb-6">+ Question</Button>
            )}

            {questions.length > 0 && (
              <>
                <h2 className="text-base font-semibold text-[var(--color-ink)] mb-1 mt-2">Scoring rubric</h2>
                <p className="text-xs text-[var(--color-ink-2)] mb-3">
                  Bands map a total score to a suggested return assumption. Ranges are inclusive of both min and max.
                </p>
                <Card className="p-4 mb-4">
                  <div className="grid grid-cols-[1fr_5rem_5rem_6rem_2rem] gap-2 mb-2 text-[11px] uppercase tracking-wide text-[var(--color-ink-3)]">
                    <span>Band name</span><span>Min</span><span>Max</span><span>Return %</span><span />
                  </div>
                  {bands.map((b, bi) => (
                    <div key={bi} className="grid grid-cols-[1fr_5rem_5rem_6rem_2rem] gap-2 mb-2">
                      <input type="text" value={b.name} onChange={(e) => updateBand(bi, 'name', e.target.value)} placeholder="e.g. moderate" className="field" />
                      <input type="number" value={b.min} onChange={(e) => updateBand(bi, 'min', e.target.value)} className="field" />
                      <input type="number" value={b.max} onChange={(e) => updateBand(bi, 'max', e.target.value)} className="field" />
                      <input type="number" step="any" value={b.suggested_return_pct} onChange={(e) => updateBand(bi, 'suggested_return_pct', e.target.value)} className="field" />
                      <button type="button" onClick={() => removeBand(bi)} className="text-[var(--color-ink-3)] hover:text-[var(--color-alert)]">×</button>
                    </div>
                  ))}
                  <Button variant="outline" size="sm" onClick={addBand}>+ Band</Button>
                </Card>

                <div className="flex items-center gap-2">
                  <Button onClick={handleSave} disabled={saving}>{saving ? 'Saving…' : 'Save'}</Button>
                  {status === 'draft' && canApprove && (
                    <Button variant="teal" onClick={handleApprove}>Approve</Button>
                  )}
                </div>
                {status === 'draft' && canApprove && (
                  <p className="mt-2 text-xs text-[var(--color-ink-3)]">
                    Approving requires senior advisor or firm admin privileges (docs/09 Piece 2) — still the same
                    deliberate, logged second step before a suggested return can reach a client plan.
                  </p>
                )}
                {status === 'draft' && !canApprove && (
                  <p className="mt-2 text-xs text-[var(--color-ink-3)]">
                    Approving this set requires senior advisor or firm admin privileges — ask one of them to review and approve it.
                  </p>
                )}
              </>
            )}
          </>
        )}
      </main>
    </div>
  );
}
