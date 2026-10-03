// Reproduces the Sales Invoice print page (paperType "half", 210mm x 148.5mm, Epson-dot-matrix
// scenario) against the real built app, with the API layer mocked via page.route so no backend/DB
// is needed. Prints to PDF and counts pages, across the variations from the bug ticket:
// item count (1/5/15), font-family fallback, deviceScaleFactor, and a simulated 1-3px content
// overflow.
//
// Also covers "dotmatrix_auto" (a driver whose registered paper form matches neither 'half' nor
// 'dotmatrix_half', so Chrome substitutes its own size instead of honoring @page — see
// PrintPaperType's own doc comment in printOptions.ts) with `preferCSSPageSize: false`, the
// inverse of every 'half' variant above: this is the one paper type that must stay correct even
// when the browser does NOT honor our requested @page size at all.
//
// Run: `node scripts/print-page-count.mjs` (needs `npm run build` + `npx playwright install chromium` first).
import { chromium } from 'playwright'
import { spawn } from 'node:child_process'
import { setTimeout as delay } from 'node:timers/promises'

const PORT = 4319
const BASE = `http://localhost:${PORT}`
const INVOICE_ID = 'test-invoice-1'

function makeInvoice(itemCount) {
  return {
    id: INVOICE_ID,
    document_number: 'SI2609/0001',
    invoice_type: 'goods',
    status: 'submitted',
    display_status: 'unpaid',
    revision: 1,
    delivery_id: null,
    delivery: null,
    deliveries: [],
    warehouse_id: null,
    warehouse: { id: 'w1', name: 'Gudang Utama', code: 'GU' },
    sales_order_id: 'so1',
    sales_orders: [{ id: 'so1', document_number: 'SO2609/0001' }],
    sales_order: {
      id: 'so1', document_number: 'SO2609/0001', attention: 'Bapak Budi', tel: '0541-123456', fax: null,
      sales_person: { id: 'sp1', code: 'SP1', name: 'Andi' }, branch: { id: 'b1', name: 'Samarinda', code: 'SMD' },
    },
    branch_id: null,
    branch: null,
    customer_id: 'c1',
    customer: { id: 'c1', customer_code: 'C001', customer_name: 'PT Contoh Pelanggan', phone: '0541-654321', address: 'Jl. Contoh No. 1, Samarinda' },
    sales_person_id: 'sp1',
    sales_person: { id: 'sp1', code: 'SP1', name: 'Andi' },
    invoice_date: '2026-09-01',
    due_date: '2026-10-01',
    terms_of_payment_id: 't1',
    terms_of_payment: { id: 't1', code: 'N30', name: 'Net 30', days: 30 },
    subtotal: 1000000 * itemCount,
    discount_amount: 0,
    discount_type: 'percentage',
    discount_percentage: 0,
    tax_id: null,
    tax: null,
    tax_amount: 0,
    grand_total: 1000000 * itemCount,
    paid_amount: 0,
    outstanding_amount: 1000000 * itemCount,
    credited_amount: 0,
    debited_amount: 0,
    creditable_amount: 1000000 * itemCount,
    remarks: null,
    reference_1: 'REF-1',
    reference_2: 'REF-2',
    items: Array.from({ length: itemCount }, (_, i) => ({
      id: `item-${i}`,
      delivery_item_id: null,
      item_id: `master-item-${i}`,
      item_code: `ITM-${String(i + 1).padStart(3, '0')}`,
      item_name: `Barang Contoh ${i + 1}`,
      uom: 'PCS',
      qty: 10,
      rate: 100000,
      amount: 1000000,
      tax_id: null,
      tax: null,
      tax_amount: 0,
      credited_qty: 0,
      credited_amount: 0,
      creditable_qty: 10,
      creditable_amount: 1000000,
      sales_person: { id: 'sp1', code: 'SP1', name: 'Andi' },
    })),
    payment_history: [],
    credit_note_history: [],
    debit_note_history: [],
    submitted_at: '2026-09-01T00:00:00Z',
    cancelled_at: null,
    created_at: '2026-09-01T00:00:00Z',
  }
}

async function mockApi(page, itemCount) {
  await page.route('**/api/v1/auth/me', (route) =>
    route.fulfill({ json: { data: { id: 'u1', name: 'Test User', email: 't@example.com', roles: ['admin'], permissions: ['sales.invoices.view'] } } }),
  )
  await page.route(`**/api/v1/invoices/${INVOICE_ID}`, (route) => route.fulfill({ json: { data: makeInvoice(itemCount) } }))
  await page.route('**/api/v1/print-settings', (route) => route.fulfill({ json: { data: { invoice: null, 'delivery-order': null } } }))
  await page.route('**/api/v1/company/branding', (route) => route.fulfill({ json: { data: { name: 'PT. KALINDO ETAM', logo_url: null } } }))
  await page.route('**/api/v1/company/print-header', (route) =>
    route.fulfill({ json: { data: { name: 'PT. KALINDO ETAM', address: 'Jl. Contoh No. 1', phone: '0541-000000', email: null, npwp: '00.000.000.0-000.000' } } }),
  )
}

function countPdfPages(buffer) {
  const text = buffer.toString('latin1')
  const matches = text.match(/\/Type\s*\/Page(?!s)/g)
  return matches ? matches.length : 0
}

async function run() {
  const server = spawn('npx', ['vite', 'preview', '--port', String(PORT), '--strictPort'], { shell: true, stdio: 'pipe' })
  server.stdout.on('data', () => {})
  server.stderr.on('data', () => {})
  await delay(2500)

  const results = []
  const browser = await chromium.launch()
  try {
    // Half: lastCapacity=4 rows (last page, footer reserved), middleCapacity=14 rows (non-last
    // pages, no footer). Dot Matrix Half (default 135mm sheet height): lastCapacity=2,
    // middleCapacity=12. See InvoiceLandscapeLayout.tsx's own file doc comment for the geometry
    // these numbers come from — every `expectedPages` below is hand-derived from them, not guessed.
    const variants = [
      // 5 and 15 used to live here expecting 1 page each — that was asserting the very bug this
      // ticket reports (Half's real per-page capacity is 4 rows; those counts only rendered as
      // "1 page" because content silently overlapped the footer instead of paginating). Superseded
      // by the 'half-pagination' variants below, which assert the correct (2-page) outcome.
      ...([1].map((itemCount) => ({
        label: `items=${itemCount}`, itemCount, deviceScaleFactor: 1, fontOverride: null, extraHeightPx: 0, paperType: 'half', preferCSSPageSize: true, expectedPages: 1,
      }))),
      ...(['Arial', '"Times New Roman"', 'monospace'].map((f) => ({
        label: `font=${f}`, itemCount: 3, deviceScaleFactor: 1, fontOverride: f, extraHeightPx: 0, paperType: 'half', preferCSSPageSize: true, expectedPages: 1,
      }))),
      ...([1, 1.25, 1.5].map((dsf) => ({
        label: `dsf=${dsf}`, itemCount: 3, deviceScaleFactor: dsf, fontOverride: null, extraHeightPx: 0, paperType: 'half', preferCSSPageSize: true, expectedPages: 1,
      }))),
      ...([1, 2, 3].map((px) => ({
        label: `extraHeightPx=${px}`, itemCount: 3, deviceScaleFactor: 1, fontOverride: null, extraHeightPx: px, paperType: 'half', preferCSSPageSize: true, expectedPages: 1,
      }))),
      // dotmatrix_auto, with preferCSSPageSize:false — simulates a driver that ignores our @page
      // size entirely and substitutes its own (the actual SIMPLIDOTS/EPSON LX-310 failure mode).
      // Nothing above tests this: every 'half' variant asserts the OPPOSITE premise
      // (preferCSSPageSize:true, i.e. @page IS honored).
      // items=15 intentionally expects 2, not 1 — dotmatrix_auto's pagination budget
      // (DOTMATRIX_AUTO_PAGE_HEIGHT_MM, invoicePrintConstants.ts) is deliberately generous (reuses
      // Continuous's own 279.4mm, not Half's 148.5mm — see that constant's own comment for why
      // 148.5mm was forcing every invoice onto 2 pages regardless of item count), so this only
      // uses more than 1 page once content genuinely runs long. Never overlapping content.
      ...(
        [
          [1, 1], [5, 1], [15, 2],
        ].map(([itemCount, expectedPages]) => ({
          label: `dotmatrix_auto items=${itemCount}`, itemCount, deviceScaleFactor: 1, fontOverride: null, extraHeightPx: 0, paperType: 'dotmatrix_auto', preferCSSPageSize: false, expectedPages,
        }))
      ),
      // Half explicit multi-page pagination (SI/KE/00022/09/2026 is the real 8-item case that
      // started this ticket) — 1 (well under capacity), 4 (exact capacity, no overlap), 5
      // (capacity+1, must split), 8 (the ticket's own example), 15 (spans the middle-page
      // boundary too), 30 (3+ pages).
      ...(
        [
          [1, 1], [4, 1], [5, 2], [8, 2], [15, 2], [30, 3],
        ].map(([itemCount, expectedPages]) => ({
          label: `half-pagination items=${itemCount}`, itemCount, deviceScaleFactor: 1, fontOverride: null, extraHeightPx: 0, paperType: 'half', preferCSSPageSize: true, expectedPages,
        }))
      ),
      // Dot Matrix Half — same idea, smaller capacity (2 last / 12 middle at the 135mm default
      // sheet height).
      ...(
        [
          [1, 1], [2, 1], [3, 2], [8, 2], [13, 2], [30, 4],
        ].map(([itemCount, expectedPages]) => ({
          label: `dotmatrix_half-pagination items=${itemCount}`, itemCount, deviceScaleFactor: 1, fontOverride: null, extraHeightPx: 0, paperType: 'dotmatrix_half', preferCSSPageSize: true, expectedPages,
        }))
      ),
    ]

    for (const v of variants) {
      const context = await browser.newContext({ deviceScaleFactor: v.deviceScaleFactor })
      const page = await context.newPage()
      await mockApi(page, v.itemCount)

      // Seed the auth token before the SPA boots so ProtectedRoute/AuthContext see a logged-in user.
      // `load` not `networkidle` — React Query's own background refetch/polling never goes fully
      // idle, so `networkidle` hangs forever here; `load` + an explicit selector wait is enough.
      await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 15000 })
      await page.evaluate(() => localStorage.setItem('auth_token', 'fake-token'))
      // Force the paper type via the same localStorage key the page itself reads on init
      // (loadInvoicePaperTypePreference) — avoids driving the Print Options UI.
      await page.evaluate((paperType) => localStorage.setItem('print-paper-type-invoice', paperType), v.paperType)
      await page.goto(`${BASE}/sales/invoices/${INVOICE_ID}/print`, { waitUntil: 'load', timeout: 15000 })
      await page.waitForSelector('h1:has-text("Invoice Print Preview")', { timeout: 15000 })
      await page.waitForTimeout(300)

      if (v.fontOverride) {
        await page.addStyleTag({ content: `* { font-family: ${v.fontOverride} !important; }` })
      }
      if (v.extraHeightPx) {
        // Simulates a sub-pixel mm→px rounding overshoot (hypothesis a) by growing the EXISTING
        // print canvas itself by a hair — not by adding a new trailing element (that's a genuinely
        // different, always-real-content scenario covered separately, not this bug).
        await page.evaluate((px) => {
          const canvas = document.querySelector('[data-testid="invoice-landscape-canvas"]')
          if (!canvas) throw new Error('invoice-landscape-canvas not found')
          canvas.style.paddingBottom = `${px}px`
        }, v.extraHeightPx)
      }

      const pdf = await page.pdf({ preferCSSPageSize: v.preferCSSPageSize })
      const pages = countPdfPages(pdf)
      results.push({ ...v, pages })
      await context.close()
    }
  } finally {
    await browser.close()
    server.kill()
  }

  console.table(
    results.map((r) => ({
      variant: r.label,
      expected: r.expectedPages,
      pages: r.pages,
      ok: r.pages === r.expectedPages ? 'OK' : `FAIL (expected ${r.expectedPages})`,
    })),
  )
  const failures = results.filter((r) => r.pages !== r.expectedPages)
  if (failures.length) {
    console.error(`\n${failures.length}/${results.length} variants did not match their expected page count.`)
    process.exitCode = 1
  } else {
    console.log(`\nAll ${results.length} variants matched their expected page count.`)
  }
}

run()
