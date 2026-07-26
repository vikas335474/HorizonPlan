// Minimal RFC4180-ish CSV parser — no dependency added for this (frontend has
// no parsing library today; a CAMS/KFintech/MFCentral CAS export is small
// enough, a few dozen to a few hundred rows, that a hand-rolled parser is
// plenty). Handles quoted fields (fund names routinely contain commas, e.g.
// "HDFC Balanced Advantage Fund, Direct Growth"), escaped quotes ("") inside
// a quoted field, and \r\n / \n line endings.
//
// Returns an array of rows, each row an array of string cells. Blank
// trailing lines (common at the end of a CAS export) are skipped entirely.
export function parseCsv(text) {
  const rows = [];
  let row = [];
  let field = '';
  let inQuotes = false;

  const pushField = () => { row.push(field); field = ''; };
  const pushRow = () => {
    pushField();
    // Skip a row that's entirely empty (a single blank cell from a trailing
    // blank line) rather than emitting a phantom row.
    if (!(row.length === 1 && row[0] === '')) rows.push(row);
    row = [];
  };

  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (inQuotes) {
      if (c === '"') {
        if (text[i + 1] === '"') { field += '"'; i++; } // escaped quote
        else { inQuotes = false; }
      } else {
        field += c;
      }
    } else if (c === '"') {
      inQuotes = true;
    } else if (c === ',') {
      pushField();
    } else if (c === '\r') {
      // ignore — \n (if present) handles the row break
    } else if (c === '\n') {
      pushRow();
    } else {
      field += c;
    }
  }
  // Final field/row if the file doesn't end with a newline.
  if (field !== '' || row.length > 0) pushRow();

  return rows;
}

// Parses an amount cell that may carry currency formatting from a CAS export
// (₹ symbol, thousands separators, stray whitespace) into a plain number.
// Returns null if the cell doesn't contain a usable number.
export function parseAmount(cell) {
  if (cell == null) return null;
  const cleaned = String(cell).replace(/[^0-9.\-]/g, '');
  if (cleaned === '' || cleaned === '-' || cleaned === '.') return null;
  const n = Number(cleaned);
  return Number.isFinite(n) ? n : null;
}
