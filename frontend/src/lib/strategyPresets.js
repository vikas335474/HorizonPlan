// Decumulation strategy presets — one-click parameter bundles an advisor applies
// to a retirement goal to spin up comparable sub-scenarios on the existing
// sequence-risk chart. These are NOT new financial claims: every value here is
// an anchor already established in this repo's own docs, and each preset only
// varies the withdrawal rate so the comparison isolates a single variable (the
// exact point of the safe-withdrawal-rate mechanic, docs/02 §4.2).
//
// Provenance:
// - 3.0% / 3.5%: the Indian safe-withdrawal range cited in
//   docs/05 (freefincal / Arthgyaan-sourced ~3–3.5%, ~28–33× corpus). 3.5% is
//   also HorizonPlan's own default (see NewGoalModal, docs/02 §4.2).
// - 4.0%: the US Trinity Study 25× convention, referenced in
//   tests/test_plan_math.php — included as a contrast point, explicitly the
//   higher-risk end for a higher-inflation market, not a recommendation.
//
// Compliance: these are neutral illustrations to COMPARE, never "best for you"
// advice — they always render under the distribution-mode disclosure. No
// age-suitability claims are made here (that's advice-adjacent and a Phase 2
// item requiring the firm's own CFA/RIA-supplied definitions).

// Each preset overrides only custom_withdrawal_rate; inflation and return stay
// at the goal's own assumptions so the two projection lines differ by the one
// variable being illustrated. custom_multiple is derived (1 ÷ rate), shown as a
// hint on the chip — the same arithmetic as PlanMath::corpusMultiple.
export const RETIREMENT_WITHDRAWAL_PRESETS = [
  {
    id: 'swr-3-0',
    name: '3.0% withdrawal',
    tagline: 'Lower depletion risk · ≈33× corpus',
    params: { custom_withdrawal_rate: 3.0 },
  },
  {
    id: 'swr-3-5',
    name: '3.5% withdrawal',
    tagline: 'India baseline (default) · ≈29× corpus',
    params: { custom_withdrawal_rate: 3.5 },
  },
  {
    id: 'swr-4-0',
    name: '4.0% withdrawal',
    tagline: 'US 25× convention · higher depletion risk here',
    params: { custom_withdrawal_rate: 4.0 },
  },
];

// Only retirement goals have a withdrawal rate to vary; other goal types have
// no decumulation presets in the MVP.
export function presetsForGoalType(goalType) {
  return goalType === 'retirement' ? RETIREMENT_WITHDRAWAL_PRESETS : [];
}
