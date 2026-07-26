import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import AppHeader from '../components/AppHeader';
import { Card } from '../components/ui';

// A quick-reference run book explaining what each feature is for and when to
// use it — advisor/super_admin only (route gated in App.jsx and AppHeader),
// since this is about using the tool, not client-facing content. Static
// content, no schema/endpoint — same "pure frontend composition" precedent
// as the demo tour and onboarding checklist.
const SECTIONS = [
  {
    id: 'building-a-plan',
    title: 'Building a plan',
    items: [
      {
        name: 'Goals & scenarios',
        body: "Each client can have multiple goals (retirement, education, home purchase, ...). A goal's own numbers are the baseline; a scenario is a what-if copy you can adjust without touching that baseline. Editing a scenario's inflation or withdrawal rate freezes it from picking up future changes to the goal — reset it any time to make it follow the goal again.",
      },
      {
        name: 'Retirement Readiness Score',
        body: 'A single 0–100 score blending how the corpus survives under an average return and under a bad-early-years sequence, plus a corpus-multiple sanity check. It\'s the number to put in front of a client instead of a wall of numbers — "How is this calculated?" on the score card shows the full formula for when they ask.',
      },
      {
        name: 'Sequence-of-returns chart',
        body: "Two lines with the same average return — one steady, one front-loading the weak years. The point: the average return alone doesn't tell you if a plan survives. Use this when a client pushes back on a withdrawal rate that looks fine \"on average.\"",
      },
      {
        name: 'Historical replay',
        body: "Instead of a synthetic bad sequence, replay an actual historical year and see what really happened to the corpus from there. The amber banner means that specific year's market data hasn't been verified against a primary source yet — useful for illustration, not yet citable as fact to a client.",
      },
      {
        name: 'Accumulation phase',
        body: "For a client who hasn't retired yet: set their current age, retirement age, monthly SIP, and step-up rate, and the projection shows the corpus growing pre-retirement and then draws down post-retirement as one continuous line. Leave it unset for a goal that's decumulation-only.",
      },
      {
        name: 'Corpus composition',
        body: "Split a goal's starting corpus into liquid (mutual funds, stocks, FDs) and locked (PPF, EPF, NPS) buckets, each with its own return assumption. Withdrawals draw from liquid first — useful when a meaningful chunk of a client's money genuinely isn't accessible on demand.",
      },
    ],
  },
  {
    id: 'talking-to-clients',
    title: 'Talking to clients',
    items: [
      {
        name: 'Meeting Mode',
        body: "A full-screen, keyboard-navigable slide sequence for a live client conversation — goal overview, what inflation does, will the corpus survive, a real bad-decade replay, and the readiness score, closing on the one number worth remembering. Launch it from a goal's own page.",
      },
      {
        name: 'Client report',
        body: 'A shareable, printable single-page view of a goal — the same readiness score and survival chart as Meeting Mode, but a static page instead of a presentation. Good for leaving something behind after a meeting.',
      },
      {
        name: 'Client self-service login',
        body: 'Clients have their own login. They can see their own goals, run their own what-if scenarios on the sliders, and see the portfolio you\'ve recorded for them — all read-only where it should be (they can\'t edit a goal\'s core assumptions, that stays with you).',
      },
      {
        name: 'Distribution-mode disclosure',
        body: "The banner that appears on every client-facing view when a firm is in distribution mode — required, not optional, and always sits right next to the withdrawal-rate slider and projection chart specifically. It can't be turned off per-goal.",
      },
    ],
  },
  {
    id: 'firm-tools',
    title: 'Firm tools & governance',
    items: [
      {
        name: 'Risk questionnaire',
        body: "Your firm's own risk-tolerance questionnaire and scoring rubric — HorizonPlan doesn't ship one, your CFA/RIA supplies the questions and bands. A captured client response only surfaces a suggested return assumption once the question set is approved; editing an approved set's content reverts it to draft.",
      },
      {
        name: 'Strategy templates',
        body: 'Reusable withdrawal-strategy presets — a global library plus your own firm\'s templates and forks. Applying an approved template to a goal sets its post-retirement return assumption and cascades to any non-frozen scenarios. Like the risk questionnaire, a template only applies once approved.',
      },
      {
        name: 'Client portfolio ledger',
        body: "A factual record of what a client already owns, independent of any one goal — pull from it when setting up a new goal's corpus split, or import a CAMS/KFintech/MFCentral CSV statement directly instead of typing each holding in.",
      },
      {
        name: 'Activity log',
        body: 'Every change to a goal or scenario — who changed what, from what value to what, and when. A per-goal History panel lives on that goal\'s own page; the firm-wide feed is under "Activity" in the header. Read-only — reverting a value means making the change again through the normal edit flow, not an undo button.',
      },
      {
        name: 'Firm roles',
        body: "Jr. Advisor, Sr. Advisor, and Firm Admin. Approving a template or risk questionnaire needs Sr. Advisor or above — a Jr. Advisor can still build and edit plans freely, just not sign off on firm-wide content. Firm Admin also gets self-service branding edits; only a HorizonPlan Super Admin can change a firm's advisory/distribution compliance mode.",
      },
    ],
  },
];

export default function FeatureGuide() {
  const { user } = useAuth();
  // Advisor/super_admin only, same client-side-guard-plus-no-endpoint-to-leak
  // pattern as AdminConsole.jsx — a run book for using the tool isn't
  // client-facing content (it covers firm-internal things like approval
  // roles), even though nothing here is a security boundary since the page
  // has no backend calls to protect.
  if (user && user.role === 'client') return <Navigate to="/goals" replace />;

  return (
    <div className="min-h-screen">
      <AppHeader />
      <main className="mx-auto max-w-3xl px-5 py-8">
        <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">Feature guide</h1>
        <p className="mt-1 mb-6 text-sm text-[var(--color-ink-2)]">
          What each feature is for, and when to reach for it — a quick reference, not a full manual.
        </p>

        <nav className="mb-6 flex flex-wrap gap-2">
          {SECTIONS.map((s) => (
            <a
              key={s.id}
              href={`#${s.id}`}
              className="rounded-full border border-[var(--color-line-2)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-2)] hover:text-[var(--color-ink)] hover:border-[var(--color-teal)] transition-colors"
            >
              {s.title}
            </a>
          ))}
        </nav>

        {SECTIONS.map((section) => (
          <section key={section.id} id={section.id} className="mb-8 scroll-mt-20">
            <h2 className="text-lg font-semibold tracking-tight text-[var(--color-ink)] mb-3">{section.title}</h2>
            <div className="space-y-3">
              {section.items.map((item) => (
                <Card key={item.name} className="p-4">
                  <h3 className="text-sm font-semibold text-[var(--color-ink)] mb-1">{item.name}</h3>
                  <p className="text-sm leading-relaxed text-[var(--color-ink-2)]">{item.body}</p>
                </Card>
              ))}
            </div>
          </section>
        ))}
      </main>
    </div>
  );
}
