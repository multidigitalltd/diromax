// E2E: release 1.14.0 — quote comparison. The manager opens a comparison on
// a budget line, adds quotes (one with the supplier's PDF), cannot send it
// with one quote, sends it with two; the client sees them side by side,
// picks one with a confirmation; the manager records the choice in the
// ledger as a commitment. axe on every new screen, and the phone width.
// Runs after phase9.e2e.js, with the sessions phase3.e2e.js saved.
const { chromium, devices } = require('playwright');
const fs = require('fs');
const path = require('path');
const S = process.argv[2];
const BASE = 'http://127.0.0.1:8088';
const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const out = (n) => path.join(S, 'e2e', n);
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  — ' + detail : '')); };
const localDate = (days) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jerusalem' }).format(new Date(Date.now() + days * 864e5));

async function axe(page, label) {
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.addScriptTag({ content: AXE });
  const r = await page.evaluate(async () => (await axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] })).violations.map(v => ({ id: v.id, nodes: v.nodes.length, sample: v.nodes[0] && v.nodes[0].target.join(' ') })));
  check('axe: ' + label, r.length === 0, r.length ? JSON.stringify(r) : '0 violations');
}

async function submit(page, selector) {
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click(selector)]);
}

(async () => {
  const browser = await chromium.launch();
  const errors = [];
  const watch = (p) => {
    p.on('pageerror', e => errors.push(e.message));
    p.on('response', r => { if (r.status() >= 400 && r.request().resourceType() !== 'document') errors.push(r.status() + ' ' + r.url()); });
  };

  // ---------- Manager: a comparison ----------
  const mctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'staff-state.json') });
  const page = await mctx.newPage();
  watch(page);
  const caseUrl = BASE + '/portal/manage/case/1/';
  await page.goto(caseUrl + '?tab=quotes');
  check('the case has a quotes tab, with the form open', (await page.locator('.dxa-tabs .nav-tab', { hasText: 'הצעות מחיר' }).count()) === 1 && await page.locator('#form-quote').evaluate(e => e.open));
  await axe(page, 'quotes tab, empty');

  await page.fill('#dxa-quote-title', 'מודד מוסמך');
  await page.selectOption('#dxa-quote-category', 'consultants');
  await page.fill('#dxa-quote-due_on', localDate(10));
  await page.fill('#dxa-quote-note', 'מדידה לצורך הגשת ההיתר.');
  await submit(page, '#form-quote [type=submit]');
  check('the comparison is opened as a draft', (await page.textContent('.notice-success')).includes('ההשוואה נוצרה') && (await page.textContent('.dxa-quote')).includes('טיוטה'));

  const box = page.locator('.dxa-quote').first();
  const id = (await box.getAttribute('id')).replace('dxa-quote-', '');
  await page.fill('#dxa-offer' + id + '-supplier', 'מדידות כהן');
  await page.fill('#dxa-offer' + id + '-amount', '4,800');
  await page.fill('#dxa-offer' + id + '-duration', '3 שבועות');
  await submit(page, '#form-offer' + id + ' [type=submit]');
  check('a first quote is added', (await page.textContent('.notice-success')).includes('ההצעה נוספה') && (await page.textContent('.dxa-offers')).includes('4,800 ₪'));

  await submit(page, '#form-send' + id + ' [type=submit]');
  check('one quote cannot be sent', (await page.textContent('#dxa-send' + id + '-send-error')).includes('לפחות שתי הצעות'));
  await axe(page, 'quotes tab with an error');

  if (!(await page.locator('#form-offer' + id).evaluate(e => e.open))) await page.click('#form-offer' + id + ' summary');
  await page.fill('#dxa-offer' + id + '-supplier', 'גיאו-מפות');
  await page.fill('#dxa-offer' + id + '-amount', '5,500');
  await page.fill('#dxa-offer' + id + '-note', 'כולל מדידה חוזרת אחרי היציקה');
  await page.check('#form-offer' + id + ' input[name="recommended"]');
  await page.setInputFiles('#dxa-offer' + id + '-quote_file', { name: 'geo-quote.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n' + Date.now() + '\ntrailer << /Root 1 0 R >>\n%%EOF\n') });
  await submit(page, '#form-offer' + id + ' [type=submit]');
  check('a second quote is added with its document', (await page.textContent('.dxa-offers')).includes('גיאו-מפות') && (await page.locator('.dxa-offers a', { hasText: 'ההצעה המקורית' }).count()) === 1);

  await submit(page, '#form-send' + id + ' [type=submit]');
  check('the comparison is sent to the client', (await page.textContent('.notice-success')).includes('נשלחה ללקוח לבחירה') && (await page.textContent('.dxa-quote')).includes('ממתינה לבחירת הלקוח'));
  await axe(page, 'quotes tab with a comparison sent');

  // ---------- Client: side by side, then a choice ----------
  const cctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'client-state.json') });
  const client = await cctx.newPage();
  watch(client);
  await client.goto(BASE + '/portal/');
  const portalCase = client.url().split('?')[0];
  check('the client has a quotes tab, flagged as waiting', (await client.locator('.dxp-tab', { hasText: 'הצעות מחיר' }).locator('.dxp-cnt--hot').count()) === 1);
  await client.goto(portalCase + '?tab=quotes');
  check('the client sees both quotes, the recommendation and the cheapest', (await client.locator('.dxp-offer').count()) === 2 && (await client.textContent('.dxp-offer--recommended')).includes('ההמלצה של מנהל הפרויקט') && (await client.textContent('.dxp-offers')).includes('הזולה ביותר'));
  const doc = await client.request.get(await client.getAttribute('.dxp-offer__doc', 'href'));
  check('the supplier\'s quote opens for the client', doc.status() === 200);
  await axe(client, 'portal quotes to choose from');

  await client.check('.dxp-offer--recommended .dxp-offer__pick');
  await client.fill('.dxp-quote__form textarea[name="note"]', 'מעדיפים את המומלצת');
  await client.check('.dxp-quote__form input[name="confirm"]');
  await submit(client, '.dxp-quote__form [type=submit]');
  check('the choice is recorded', (await client.textContent('.dxp-notice')).includes('הבחירה נרשמה') && (await client.textContent('.dxp-offer--chosen')).includes('גיאו-מפות') && (await client.locator('.dxp-quote__form').count()) === 0);
  await client.screenshot({ path: out('99-portal-quotes.png'), fullPage: false });
  await axe(client, 'portal quotes after the choice');

  const phone = await browser.newContext({ ...devices['iPhone 13'], storageState: path.join(S, 'client-state.json') });
  const mp = await phone.newPage();
  watch(mp);
  await mp.goto(portalCase + '?tab=quotes');
  check('the quotes fit a phone screen', await mp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  await phone.close();

  // ---------- Manager: into the ledger ----------
  await page.goto(caseUrl + '?tab=quotes');
  check('the manager sees the choice and the client\'s note', (await page.textContent('.dxa-quote')).includes('הלקוח בחר') && (await page.textContent('.dxa-quote')).includes('מעדיפים את המומלצת'));
  await page.selectOption('#dxa-commit' + id + '-funding', 'client');
  await submit(page, '#form-commit' + id + ' [type=submit]');
  check('the choice is recorded in the ledger as a commitment', (await page.textContent('.notice-success')).includes('נרשמה בכספים כהתחייבות') && (await page.locator('#form-commit' + id).count()) === 0);
  await page.goto(caseUrl + '?tab=finance');
  check('…with the supplier and the amount', (await page.textContent('#dxa-ledger-table')).includes('גיאו-מפות') && (await page.textContent('#dxa-ledger-table')).includes('5,500'));

  check('no script errors or failed assets', errors.length === 0, errors.join(' | '));
  fs.writeFileSync(out('phase10-results.json'), JSON.stringify(results, null, 2));
  await browser.close();
  process.exit(results.every(r => r.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
