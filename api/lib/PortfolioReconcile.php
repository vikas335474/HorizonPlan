<?php
declare(strict_types=1);

/**
 * docs/12 Prompt D-1 · Reconcile-by-holding CAS import. Replaces the old
 * client_portfolio_import.php behavior of unconditionally INSERTing every
 * row on every upload, which silently duplicated a client's entire mutual
 * fund book on a second import.
 *
 * Pure diff computation — no DB, no network — same split as
 * MfNavSync.php/ReferenceCosts.php: the DB-touching endpoints
 * (client_portfolio_reconcile_preview.php, client_portfolio_import.php)
 * call computePortfolioReconciliation() with already-loaded rows and
 * already-parsed CSV items, and act on what it returns.
 *
 * THE MATCH KEY. A folio number is the one field a real CAMS/KFintech/
 * MFCentral statement carries that uniquely identifies a specific holding
 * across two exports taken months apart — a scheme's display name routinely
 * varies in tiny ways between statements ("Growth" vs "Growth Option",
 * plan-name suffix changes on an AMC rename) that would break a name-only
 * match. So: match on folio_number when the imported statement had one,
 * falling back to a normalised scheme name only when it didn't — and that
 * fallback is flagged lower-confidence in the returned diff, never silently
 * treated as equally reliable.
 *
 * RECONCILIATION IS SCOPED TO source='cas_import' ROWS, ENFORCED BY THE
 * CALLER, NOT HERE. This function only ever sees the rows its caller passes
 * in — client_portfolio_reconcile_preview.php/client_portfolio_import.php
 * are responsible for only ever loading a client's source='cas_import' rows
 * before calling this, so a hand-entered holding can never be matched,
 * updated, or flagged by an import, even if its description happens to
 * collide with a name in the new statement.
 */

/**
 * Normalises a scheme name for the fallback match path: lowercase, collapse
 * all whitespace runs to one space, trim. Deliberately NOT stripping
 * "growth"/"direct"/"regular" style tokens — normalising too aggressively
 * risks merging two genuinely different funds; this only absorbs the kind
 * of incidental whitespace/casing noise a CSV export commonly introduces.
 */
function normalizeSchemeName(string $name): string
{
    return trim(preg_replace('/\s+/', ' ', mb_strtolower($name)) ?? '');
}

/**
 * The match key for one holding: folio-based when a folio is present (the
 * reliable path), name-based otherwise (the flagged-lower-confidence
 * fallback). Returns [key, matchType] so callers can label which path a
 * given match came from.
 *
 * @return array{0: string, 1: 'folio'|'name'}
 */
function portfolioMatchKey(?string $folioNumber, string $description): array
{
    $folio = $folioNumber !== null ? trim($folioNumber) : '';
    if ($folio !== '') {
        return ['folio:' . $folio, 'folio'];
    }
    return ['name:' . normalizeSchemeName($description), 'name'];
}

/**
 * Diffs a client's existing CAS-sourced holdings against a freshly-parsed
 * statement. Never mutates anything — purely computes what WOULD happen.
 *
 * @param list<array{id:int,description:string,value:float,folio_number:?string}> $existingCasRows
 *   Must already be scoped by the caller to this one client's source='cas_import' rows.
 * @param list<array{description:string,value:float,folio_number:?string}> $incomingItems
 *   Parsed from the new CSV upload, already column-mapped by the frontend.
 *
 * @return array{
 *   to_update: list<array{id:int,description:string,new_description:string,old_value:float,new_value:float,match_type:string}>,
 *   to_add: list<array{description:string,value:float,folio_number:?string}>,
 *   to_flag: list<array{id:int,description:string,value:float}>,
 *   unchanged_count: int
 * }
 */
function computePortfolioReconciliation(array $existingCasRows, array $incomingItems): array
{
    // Index existing rows by match key. A collision (two existing rows
    // normalising to the same key) keeps the FIRST — should not happen in
    // practice for real folio numbers, and for the name fallback is exactly
    // the kind of ambiguity that path is already flagged as lower-confidence
    // for.
    $existingByKey = [];
    foreach ($existingCasRows as $row) {
        [$key] = portfolioMatchKey($row['folio_number'], $row['description']);
        if (!isset($existingByKey[$key])) {
            $existingByKey[$key] = $row;
        }
    }

    // Index incoming rows the same way — later rows win on a collision
    // (a genuine duplicate line in a statement is unusual; taking the last
    // is a defensible, documented tie-break rather than summing or erroring).
    $incomingByKey = [];
    foreach ($incomingItems as $item) {
        [$key] = portfolioMatchKey($item['folio_number'] ?? null, $item['description']);
        $incomingByKey[$key] = $item;
    }

    $toUpdate = [];
    $unchangedCount = 0;
    $matchedKeys = [];
    foreach ($incomingByKey as $key => $item) {
        if (!isset($existingByKey[$key])) {
            continue; // handled below as to_add
        }
        $matchedKeys[$key] = true;
        $existing = $existingByKey[$key];
        [, $matchType] = portfolioMatchKey($item['folio_number'] ?? null, $item['description']);

        $valueChanged = abs((float) $existing['value'] - (float) $item['value']) > 0.005;
        $descriptionChanged = $existing['description'] !== $item['description'];
        if (!$valueChanged && !$descriptionChanged) {
            $unchangedCount++;
            continue;
        }

        $toUpdate[] = [
            'id'              => (int) $existing['id'],
            'description'     => $existing['description'],
            'new_description' => $item['description'],
            'old_value'       => (float) $existing['value'],
            'new_value'       => (float) $item['value'],
            'match_type'      => $matchType,
        ];
    }

    $toAdd = [];
    foreach ($incomingByKey as $key => $item) {
        if (!isset($existingByKey[$key])) {
            $toAdd[] = [
                'description'   => $item['description'],
                'value'         => (float) $item['value'],
                'folio_number'  => $item['folio_number'] ?? null,
            ];
        }
    }

    $toFlag = [];
    foreach ($existingByKey as $key => $row) {
        if (!isset($matchedKeys[$key])) {
            $toFlag[] = [
                'id'          => (int) $row['id'],
                'description' => $row['description'],
                'value'       => (float) $row['value'],
            ];
        }
    }

    return [
        'to_update'       => $toUpdate,
        'to_add'          => $toAdd,
        'to_flag'         => $toFlag,
        'unchanged_count' => $unchangedCount,
    ];
}

/**
 * The one piece of user judgment client_portfolio_import.php takes on
 * trust — which of the diff's to_flag ids the user actually confirmed
 * removing — is still never trusted blindly: this intersects the
 * client-submitted id list against the diff's OWN to_flag ids, computed
 * fresh from the current DB state, so a request can never cause a deletion
 * beyond what this same reconciliation independently derived as safe
 * ("missing from this statement, and belongs to this client's own
 * CAS-sourced rows" — both already enforced by how $diff was built).
 *
 * @param array{to_flag: list<array{id:int,...}>,...} $diff
 * @param list<int> $requestedRemoveIds
 * @return list<int>
 */
function confirmedPortfolioRemovalIds(array $diff, array $requestedRemoveIds): array
{
    $flaggedIds = array_column($diff['to_flag'], 'id');
    return array_values(array_intersect($requestedRemoveIds, $flaggedIds));
}
