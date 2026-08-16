// docs/10 P0-2 · Client-side PDF export for the printable client report and
// the Meeting Mode summary. Decided via AskUserQuestion: client-side over a
// server-rendered PDF, because Hostinger's shared hosting has no persistent
// Node process / background workers (rules out a headless-browser render
// step) and this codebase has zero PHP dependencies today (a PHP PDF library
// would be the first). A JS library rasterising the DOM the report already
// renders means the PDF can never show numbers the on-screen view doesn't —
// there is no second data path to drift.
//
// html2canvas + jsPDF: rasterise the given element at 2x scale (crisp on a
// retina display and when the client zooms the PDF), then slice the tall
// canvas into A4 pages. This is deliberately NOT "one screenshot on one
// giant page" — a compliance leave-behind that only opens correctly at 8%
// zoom is not a usable artefact.
import jsPDF from 'jspdf';
import html2canvas from 'html2canvas';

const A4_WIDTH_MM = 210;
const A4_HEIGHT_MM = 297;
const PAGE_MARGIN_MM = 10;

/**
 * Render `element` to a downloadable multi-page A4 PDF.
 *
 * @param {HTMLElement} element - the DOM node to capture (must be attached
 *   and laid out — offscreen via absolute positioning is fine, `display:none`
 *   is not, since html2canvas reads real layout/paint).
 * @param {string} filename - without extension.
 */
export async function downloadElementAsPdf(element, filename) {
  if (!element) throw new Error('Nothing to export — the report has not finished loading yet.');

  const canvas = await html2canvas(element, {
    scale: 2,
    useCORS: true,
    backgroundColor: '#FFFFFF',
    // Reports carry an image only for a firm's uploaded logo, hosted on
    // Hostinger — not always CORS-friendly. A logo that can't be captured
    // should silently produce a blank spot, not fail the whole export.
    onclone: (doc) => {
      doc.querySelectorAll('img').forEach((img) => {
        img.onerror = () => { img.style.visibility = 'hidden'; };
      });
    },
  });

  const contentWidthMm = A4_WIDTH_MM - PAGE_MARGIN_MM * 2;
  const contentHeightMm = A4_HEIGHT_MM - PAGE_MARGIN_MM * 2;
  // px-per-mm at the captured content width, so a horizontal mm→px slice
  // stays proportional to the canvas's own pixel density.
  const pxPerMm = canvas.width / contentWidthMm;
  const pageHeightPx = Math.floor(contentHeightMm * pxPerMm);

  const pdf = new jsPDF({ unit: 'mm', format: 'a4' });
  let renderedPx = 0;
  let pageIndex = 0;

  while (renderedPx < canvas.height) {
    const sliceHeightPx = Math.min(pageHeightPx, canvas.height - renderedPx);

    const pageCanvas = document.createElement('canvas');
    pageCanvas.width = canvas.width;
    pageCanvas.height = sliceHeightPx;
    const ctx = pageCanvas.getContext('2d');
    ctx.drawImage(canvas, 0, renderedPx, canvas.width, sliceHeightPx, 0, 0, canvas.width, sliceHeightPx);

    const imgData = pageCanvas.toDataURL('image/png');
    if (pageIndex > 0) pdf.addPage();
    const sliceHeightMm = sliceHeightPx / pxPerMm;
    pdf.addImage(imgData, 'PNG', PAGE_MARGIN_MM, PAGE_MARGIN_MM, contentWidthMm, sliceHeightMm);

    renderedPx += sliceHeightPx;
    pageIndex += 1;
  }

  pdf.save(`${filename}.pdf`);
}
