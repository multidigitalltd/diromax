// E2E: release 1.11.0 — the payment plan. The office sets a template for new
// cases; the manager adds payments to a case; a stage marked done makes a
// draft, which reaches the client only after the manager approves it; the
// client sees the whole plan. axe on every new screen. Runs after
// phase6.e2e.js, with the sessions phase3.e2e.js saved (sign-ins are rate limited).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const S = process.argv[2];
const BASE = 'http://127.0.0.1:8088';
const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const out = (n) => path.join(S, 'e2e', n);
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  — ' + detail : '')); };

async function axe(page, label) {
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

  // ---------- Office: the template for new cases ----------
  const actx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'admin-state.json') });
  const admin = await actx.newPage();
  watch(admin);
  await admin.goto(BASE + '/wp-admin/admin.php?page=dx-portal-settings');
  check('settings have an empty payment plan template', (await admin.textContent('#dxa-plan-template')).includes('תוכנית תשלומים לתיק חדש') && (await admin.locator('.dxa-plan-template tbody tr').count()) === 3);
  await admin.fill('#dxa-plan-0-label', 'מקדמה בחתימה');
  await admin.selectOption('#dxa-plan-0-when', 'manual');
  await admin.selectOption('#dxa-plan-0-kind', 'fixed');
  await admin.fill('#dxa-plan-0-value', '10000');
  await admin.fill('#dxa-plan-1-label', 'עם קבלת ההיתר');
  await admin.selectOption('#dxa-plan-1-when', 'stage:4');
  await admin.fill('#dxa-plan-1-value', '30');
  await admin.fill('#dxa-plan-2-label', 'לספק בלי שם');
  await admin.selectOption('#dxa-plan-2-pay_to', 'supplier');
  await admin.fill('#dxa-plan-2-value', '5');
  await submit(admin, 'form:has(input[value="dx_portal_save_settings"]) [type=submit]');
  check('the valid rows are saved, and the row without a supplier is named', (await admin.textContent('.notice-error')).includes('שורה 3') && (await admin.inputValue('#dxa-plan-0-label')) === 'מקדמה בחתימה' && (await admin.inputValue('#dxa-plan-1-value')) === '30' && (await admin.inputValue('#dxa-plan-2-label')) === '');
  await axe(admin, 'settings with the plan template');

  // ---------- Manager: a payment plan in the case ----------
  const mctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'staff-state.json') });
  const page = await mctx.newPage();
  watch(page);
  const caseUrl = BASE + '/portal/manage/case/1/';
  await page.goto(caseUrl + '?tab=plan');
  check('the case has a payment plan tab, with the form open', (await page.locator('.dxa-tabs .nav-tab', { hasText: 'תוכנית תשלומים' }).count()) === 1 && await page.locator('#form-plan').evaluate(e => e.open));
  check('the summary shows the base: the budget total', (await page.textContent('.dxa-plan-sum')).includes('סך התקציב') && (await page.textContent('.dxa-plan-sum')).includes('₪'));
  await axe(page, 'plan tab, empty');

  await page.fill('#dxa-plan-label', 'תשלום עם המסירה');
  await page.selectOption('#dxa-plan-trigger_on', 'stage');
  await page.selectOption('#dxa-plan-stage', '7');
  await page.selectOption('#dxa-plan-kind', 'percent');
  await page.fill('#dxa-plan-value', '150');
  await submit(page, '#form-plan [type=submit]');
  check('a share over 100% is refused on its field', (await page.getAttribute('#dxa-plan-value', 'aria-invalid')) === 'true' && (await page.textContent('#dxa-plan-value-error')).includes('בין 0.01 ל-100') && (await page.inputValue('#dxa-plan-label')) === 'תשלום עם המסירה');
  // The jump to the form must not leave it under the sticky top bar (WCAG 2.4.11).
  const jump = await page.evaluate(() => ({ bar: Math.round(document.querySelector('.dxa-top').getBoundingClientRect().bottom), form: Math.round(document.querySelector('#form-plan').getBoundingClientRect().top) }));
  check('after an error, the form is shown below the sticky bar', jump.form >= jump.bar, JSON.stringify(jump));
  // axe at the top: halfway down a short page, the tabs sit under the sticky bar and read as small targets.
  await page.evaluate(() => window.scrollTo(0, 0));
  await axe(page, 'plan form with an error');

  await page.selectOption('#dxa-plan-kind', 'fixed');
  await page.fill('#dxa-plan-value', '15000');
  await submit(page, '#form-plan [type=submit]');
  check('the payment is added as planned', (await page.textContent('.notice-success')).includes('התשלום נוסף לתוכנית') && (await page.textContent('.dxa-plan')).includes('תשלום עם המסירה') && (await page.textContent('.dxa-plan')).includes('15,000 ₪') && (await page.textContent('.dxa-plan')).includes('מתוכנן'));

  await page.click('#form-plan summary');
  await page.fill('#dxa-plan-label', 'שכר טרחה על הליווי');
  await page.selectOption('#dxa-plan-trigger_on', 'manual');
  await page.selectOption('#dxa-plan-kind', 'percent');
  await page.fill('#dxa-plan-value', '12.5');
  await submit(page, '#form-plan [type=submit]');
  check('a share of the base is shown exactly', (await page.textContent('.dxa-plan')).includes('12.5%'));
  await axe(page, 'plan tab with payments');

  // ---------- The stage is done: a draft, not a request ----------
  await page.goto(caseUrl + '?tab=details');
  await page.click('label[for="dxa-stage-7-done"]');
  await submit(page, '#form-details [type=submit]');
  check('marking the stage done makes a draft, and says so', (await page.textContent('.notice-success')).includes('נוצרה 1 טיוטה של דרישת תשלום'));
  await page.goto(caseUrl + '?tab=plan');
  const draft = page.locator('.dxa-plan-draft').first();
  check('the draft waits for approval, with its amount', (await draft.textContent()).includes('תשלום עם המסירה') && (await draft.locator('input[name="amount"]').inputValue()) === '15000' && (await page.textContent('.dxa-tabs .nav-tab:has-text("תוכנית תשלומים")')).includes('1'));
  await axe(page, 'plan tab with a draft');

  const cctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'client-state.json') });
  const client = await cctx.newPage();
  watch(client);
  await client.goto(BASE + '/portal/');
  const portalCase = client.url().split('?')[0];
  check('the client sees the plan, with the draft still as planned', (await client.textContent('.dxp-plan')).includes('תשלום עם המסירה') && (await client.textContent('.dxp-plan__item--planned')).includes('15,000 ₪') && !(await client.textContent('.dxp-panel')).includes('נשלחה דרישה'));

  await page.goto(BASE + '/portal/manage/');
  check('the dashboard lists the draft', (await page.textContent('#dxa-dash-drafts')).includes('דרישות תשלום לאישור') && (await page.textContent('.dxa-card:has(#dxa-dash-drafts)')).includes('תשלום עם המסירה'));
  await page.click('.dxa-card:has(#dxa-dash-drafts) a');
  await page.waitForLoadState('load');

  // ---------- Approve: the request goes to the client ----------
  await draft.locator('input[name="amount"]').fill('15500');
  await submit(page, '.dxa-plan-draft [type=submit].button-primary');
  check('approving records the request', (await page.textContent('.notice-success')).includes('דרישת התשלום נשלחה ללקוח') && (await page.textContent('.dxa-plan')).includes('נשלחה דרישה') && (await page.textContent('.dxa-plan')).includes('15,500 ₪') && (await page.locator('.dxa-plan-draft').count()) === 0);
  await page.goto(caseUrl + '?tab=finance');
  check('…in the ledger, as an open request', (await page.textContent('#dxa-ledger-table')).includes('תשלום עם המסירה'));

  await client.goto(portalCase);
  check('the client sees it sent, with the amount approved', (await client.textContent('.dxp-plan__item--sent')).includes('15,500 ₪') && (await client.textContent('.dxp-table')).includes('תשלום עם המסירה'));
  await client.screenshot({ path: out('96-portal-plan.png'), fullPage: false });
  await axe(client, 'portal finance with the plan');

  // ---------- By hand: draft now, then back to the plan ----------
  await page.goto(caseUrl + '?tab=plan');
  await submit(page, '.dxa-plan tr:has-text("שכר טרחה על הליווי") button:has-text("להכין דרישה עכשיו")');
  check('a payment by hand becomes a draft', (await page.textContent('.notice-success')).includes('נוצרה טיוטה') && (await page.textContent('.dxa-plan-drafts')).includes('שכר טרחה על הליווי'));
  await submit(page, '.dxa-plan-draft button:has-text("חזרה לתוכנית")');
  check('…and goes back to the plan', (await page.textContent('.notice-success')).includes('התשלום חזר לתוכנית') && (await page.locator('.dxa-plan-draft').count()) === 0);

  check('no script errors or failed assets', errors.length === 0, errors.join(' | '));
  fs.writeFileSync(out('phase7-results.json'), JSON.stringify(results, null, 2));
  await browser.close();
  process.exit(results.every(r => r.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
