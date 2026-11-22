// E2E: release 1.9.0 — the budget tree (sections and sub-sections per case,
// an entry on a sub-section, the client's view that opens a section), the
// project manager's phone on the client's case page, and deleting a case
// with the client account that was only in it. axe on every new screen.
// Runs after phase4.e2e.js, as the staff member, the client and the
// administrator phase3.e2e.js saved (sign-ins are rate limited).
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

  // ---------- Manager: the case's own budget ----------
  const mctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'staff-state.json') });
  const page = await mctx.newPage();
  watch(page);
  const caseUrl = BASE + '/portal/manage/case/1/';
  await page.goto(caseUrl + '?tab=budget');
  check('the budget is the default tree, with sub-sections under their section', (await page.inputValue('#dxa-budget-consultants-label')) === 'יועצים ומדידות' && (await page.locator('.dxa-budget__child').count()) >= 6 && (await page.inputValue('#dxa-budget-consultants-planned')) === '18000');
  await axe(page, 'budget tree');

  await page.fill('#dxa-budget-add-consultants-label', 'יועץ תנועה');
  await page.fill('#dxa-budget-add-consultants-planned', '4000');
  await page.fill('#dxa-budget-add-new-label', 'שיווק דיגיטלי');
  await submit(page, '.dxa-budget__top button');
  const names = await page.$$eval('.dxa-budget__child .dxa-budget__name', (inputs) => inputs.map(i => i.value));
  check('a new sub-section and a new section are saved', (await page.textContent('.notice-success')).includes('התקציב נשמר') && names.includes('יועץ תנועה') && (await page.$$eval('.dxa-budget__head .dxa-budget__name', (inputs) => inputs.map(i => i.value))).includes('שיווק דיגיטלי'));
  check('the total adds the new sub-section', (await page.textContent('.dxa-budget tfoot')).includes('484,000'));

  await page.fill('#dxa-budget-fees-planned', 'abc');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.keyboard.press('Enter')]);
  check('Enter saves (it does not move a line), and a bad amount is refused on its field', (await page.getAttribute('#dxa-budget-fees-planned', 'aria-invalid')) === 'true' && (await page.inputValue('#dxa-budget-research-label')) === 'בדיקות ומידע תכנוני' && (await page.locator('.dxa-budget__head').first().locator('.dxa-budget__name').inputValue()) === 'בדיקות ומידע תכנוני');
  await axe(page, 'budget with an error');

  await page.goto(caseUrl + '?tab=budget');
  await submit(page, 'button[name="move"][value="research:down"]');
  check('a section moves down', (await page.locator('.dxa-budget__head .dxa-budget__name').first().inputValue()) === 'אדריכלות ותכנון');

  // An expense charged to the new sub-section.
  await page.goto(caseUrl + '?tab=finance&add=1');
  check('the budget picker groups each section with its sub-sections', (await page.locator('#dxa-entry-category optgroup[label="יועצים ומדידות"] option').count()) >= 7);
  await page.fill('#dxa-entry-description', 'ספירת תנועה בצומת');
  await page.fill('#dxa-entry-amount', '1500');
  await page.fill('#dxa-entry-payee', 'יועץ תנועה בע״מ');
  await page.selectOption('#dxa-entry-category', { label: 'יועץ תנועה' });
  await page.selectOption('#dxa-entry-funding', 'client');
  await submit(page, '#form-entry button[type=submit]');
  check('the entry is saved on the sub-section', (await page.textContent('.notice-success')).includes('התנועה נשמרה') && (await page.textContent('.dxa-ledger')).includes('יועצים ומדידות › יועץ תנועה'));

  // The manager's phone, from the profile.
  await page.goto(BASE + '/wp-admin/profile.php');
  await page.fill('#dx_portal_phone', '050-7654321');
  await submit(page, '#submit');
  check('the manager saves a phone on the profile', (await page.inputValue('#dx_portal_phone')) === '050-7654321');

  // ---------- Client: the phone, and a section that opens ----------
  const cctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'client-state.json') });
  const client = await cctx.newPage();
  watch(client);
  await client.goto(BASE + '/portal/');
  const portalCase = client.url().split('?')[0];
  await client.goto(portalCase + '?tab=finance');
  check('the project manager card shows the phone number', (await client.textContent('.dxp-pm__tel')).includes('050-7654321') && (await client.getAttribute('.dxp-pm__tel a', 'href')) === 'tel:0507654321');

  const section = client.locator('details.dxp-bva__more', { hasText: 'יועצים ומדידות' });
  check('a section with sub-sections is closed, with its total', (await section.count()) === 1 && !(await section.evaluate(d => d.open)) && (await section.locator('summary').textContent()).includes('22,000'));
  await section.locator('summary').focus();
  await client.keyboard.press('Enter');
  const subs = await section.locator('.dxp-bva--sub').textContent();
  check('it opens from the keyboard and shows the sub-section and the general part', (await section.evaluate(d => d.open)) && subs.includes('יועץ תנועה') && subs.includes('1,500') && subs.includes('(כללי)'));
  check('a section with nothing planned or spent is not shown to the client', !(await client.textContent('.dxp-bva')).includes('שיווק דיגיטלי'));
  await client.screenshot({ path: out('91-portal-budget-tree.png'), fullPage: true });
  await axe(client, 'client budget with a section open');

  // ---------- Administrator: delete a case with its own client ----------
  const actx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'admin-state.json') });
  const admin = await actx.newPage();
  watch(admin);
  await admin.goto(BASE + '/wp-admin/admin.php?page=dx-case-new');
  await admin.fill('#dxa-case-title', 'תיק לבדיקת מחיקה');
  await submit(admin, '#form-case [type=submit]');
  const deleteUrl = admin.url().split('&tab')[0].split('#')[0];
  check('a case to delete is opened', /[?&]case=\d+/.test(deleteUrl), deleteUrl);
  await admin.goto(deleteUrl + '&tab=members');
  await admin.fill('#dxa-member-email', 'temp-client@example.org');
  await admin.fill('#dxa-member-name', 'לקוח זמני');
  await admin.uncheck('#dxa-member-invite');
  await submit(admin, 'form:has(#dxa-member-email) [type=submit]');

  await admin.goto(deleteUrl + '&tab=details');
  const zone = admin.locator('#dxa-delete');
  check('the open case shows what deleting removes, with the client account', (await zone.count()) === 1 && (await zone.textContent()).includes('temp-client@example.org') && (await zone.textContent()).includes('אי אפשר לשחזר'));
  await axe(admin, 'delete preview');
  const number = (await admin.textContent('label[for="dxa-confirm"]')).split(':').pop().trim();

  await admin.fill('#dxa-confirm', 'not-the-number');
  await submit(admin, '#dxa-delete [type=submit]');
  check('a wrong number deletes nothing', (await admin.locator('#dxa-confirm-error').count()) === 1 && (await admin.locator('#dxa-delete').count()) === 1);
  await axe(admin, 'delete with a wrong number');

  await admin.fill('#dxa-confirm', number);
  await submit(admin, '#dxa-delete [type=submit]');
  check('the case is deleted with its only client account', (await admin.textContent('.notice-success')).includes('1 חשבון לקוח'), number);
  check('the case list has a delete link on each row', (await admin.locator('.row-actions .dxa-delete-link').count()) >= 1);
  await admin.goto(BASE + '/wp-admin/users.php?s=temp-client');
  check('the client account is gone', !(await admin.content()).includes('temp-client@example.org'));

  check('no script errors or failed assets', errors.length === 0, errors.join(' | '));
  fs.writeFileSync(out('phase5-results.json'), JSON.stringify(results, null, 2));
  await browser.close();
  process.exit(results.every(r => r.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
