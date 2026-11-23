// E2E: release 1.13.0 — site visits and findings. The manager records a
// visit (a date in the future is refused), notes a photographed finding that
// is past its due date and an internal one, sees it flagged on the
// dashboard, and marks it fixed with an "after" photo; the client sees the
// visit and the shown finding only, before and after. axe on every new
// screen, and the phone width. Runs after phase8.e2e.js, with the sessions
// phase3.e2e.js saved (sign-ins are rate limited).
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
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

function photo(name, rgb) {
  const file = path.join(S, 'e2e', name);
  execFileSync('php', ['-r', '$i = imagecreatetruecolor(1200, 900); imagefill($i, 0, 0, imagecolorallocate($i, ...array_map("intval", explode(",", $argv[2])))); imagejpeg($i, $argv[1], 85);', file, rgb]);
  return file;
}

(async () => {
  const browser = await chromium.launch();
  const errors = [];
  const watch = (p) => {
    p.on('pageerror', e => errors.push(e.message));
    p.on('response', r => { if (r.status() >= 400 && r.request().resourceType() !== 'document') errors.push(r.status() + ' ' + r.url()); });
  };

  // ---------- Manager: a visit ----------
  const mctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'staff-state.json') });
  const page = await mctx.newPage();
  watch(page);
  const caseUrl = BASE + '/portal/manage/case/1/';
  await page.goto(caseUrl + '?tab=visits');
  check('the case has a visits tab, with the form open', (await page.locator('.dxa-tabs .nav-tab', { hasText: 'ביקורים וממצאים' }).count()) === 1 && await page.locator('#form-visit').evaluate(e => e.open));
  await axe(page, 'visits tab, empty');

  // The date input's max stops a future date in the browser; take it off to test the server's check.
  await page.evaluate(() => document.querySelector('#dxa-visit-visit_on').removeAttribute('max'));
  await page.fill('#dxa-visit-visit_on', localDate(3));
  await page.fill('#dxa-visit-summary', 'יציקת תקרה בקומה השנייה.');
  await submit(page, '#form-visit [type=submit]');
  check('a visit in the future is refused on its field', (await page.getAttribute('#dxa-visit-visit_on', 'aria-invalid')) === 'true' && (await page.textContent('#dxa-visit-visit_on-error')).includes('בעתיד'));
  await axe(page, 'visit form with an error');

  await page.fill('#dxa-visit-visit_on', localDate(0));
  await page.fill('#dxa-visit-attendees', 'דניאל לוי, קבלן השלד');
  await page.fill('#dxa-visit-summary', 'יציקת תקרה בקומה השנייה. נמצאו שני ליקויים.');
  await page.check('#form-visit input[name="notify"]');
  await submit(page, '#form-visit [type=submit]');
  check('the visit is saved', (await page.textContent('.notice-success')).includes('הביקור נשמר') && (await page.textContent('.dxa-visit')).includes('נמצאו שני ליקויים'));

  // ---------- Findings: one shown, overdue, with a photo; one internal ----------
  const visitBox = page.locator('.dxa-visit').first();
  const visitId = (await visitBox.getAttribute('id')).replace('dxa-visit-', '');
  await page.click('#form-finding' + visitId + ' summary');
  await page.fill('#dxa-finding' + visitId + '-title', 'סדק בקיר המזרחי');
  await page.fill('#dxa-finding' + visitId + '-location', 'חדר שינה');
  await page.selectOption('#dxa-finding' + visitId + '-severity', 'high');
  await page.fill('#dxa-finding' + visitId + '-supplier', 'קבלן השלד');
  await page.fill('#dxa-finding' + visitId + '-due_on', localDate(-2));
  await page.setInputFiles('#dxa-finding' + visitId + '-photo', photo('crack.jpg', '150,150,150'));
  await submit(page, '#form-finding' + visitId + ' [type=submit]');
  check('a finding is added with its photo, and flagged as overdue', (await page.textContent('.notice-success')).includes('הממצא נוסף') && (await page.locator('.dxa-finding__photos img').count()) === 1 && (await page.textContent('.dxa-findings')).includes('עבר את מועד התיקון'));

  await page.click('#form-finding' + visitId + ' summary');
  await page.fill('#dxa-finding' + visitId + '-title', 'הערה פנימית לקבלן');
  await page.uncheck('#form-finding' + visitId + ' input[name="visible"]');
  await submit(page, '#form-finding' + visitId + ' [type=submit]');
  check('an internal finding is marked as such', (await page.locator('.dxa-finding', { hasText: 'הערה פנימית לקבלן' }).locator('.dxa-plan-state', { hasText: 'פנימי' }).count()) === 1);
  await axe(page, 'visits tab with findings');

  await page.goto(BASE + '/portal/manage/');
  check('the dashboard lists the overdue finding', (await page.textContent('#dxa-dash-findings')).includes('ממצאים שעברו את מועד התיקון') && (await page.textContent('.dxa-card:has(#dxa-dash-findings)')).includes('סדק בקיר המזרחי'));

  // ---------- Client: the visit and the shown finding only ----------
  const cctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'client-state.json') });
  const client = await cctx.newPage();
  watch(client);
  await client.goto(BASE + '/portal/');
  const portalCase = client.url().split('?')[0];
  check('the client has a visits tab', (await client.locator('.dxp-tab', { hasText: 'ביקורים וממצאים' }).count()) === 1);
  await client.goto(portalCase + '?tab=visits');
  const panel = await client.textContent('.dxp-panel');
  check('the client sees the visit and the shown finding, not the internal one', panel.includes('נמצאו שני ליקויים') && panel.includes('סדק בקיר המזרחי') && !panel.includes('הערה פנימית לקבלן') && (await client.locator('.dxp-finding__photos img').count()) === 1);
  const img = await client.request.get(await client.getAttribute('.dxp-finding__photos img', 'src'));
  check('the finding photo opens for the client', img.status() === 200 && img.headers()['content-type'] === 'image/jpeg');
  await axe(client, 'portal visits');

  // ---------- Fixed, with an after photo ----------
  await page.goto(caseUrl + '?tab=visits');
  const crack = page.locator('.dxa-finding', { hasText: 'סדק בקיר המזרחי' });
  await crack.locator('details[id^="form-fix"] summary').click();
  const fixId = (await crack.locator('details[id^="form-fix"]').getAttribute('id')).replace('form-fix', '');
  await page.fill('#dxa-fix' + fixId + '-fix_note', 'הסדק תוקן והקיר נצבע');
  await page.setInputFiles('#dxa-fix' + fixId + '-after_photo', photo('fixed.jpg', '230,230,230'));
  await submit(page, '#form-fix' + fixId + ' [type=submit]');
  check('the finding is marked fixed, with its after photo', (await page.textContent('.notice-success')).includes('סומן כתוקן') && (await crack.locator('.dxa-finding__photos img').count()) === 2 && !(await page.textContent('.dxa-findings')).includes('עבר את מועד התיקון'));

  await client.goto(portalCase + '?tab=visits');
  check('the client sees it fixed, before and after', (await client.textContent('.dxp-finding--fixed')).includes('תוקן ב-') && (await client.textContent('.dxp-finding--fixed')).includes('הסדק תוקן והקיר נצבע') && (await client.locator('.dxp-finding--fixed .dxp-finding__photos img').count()) === 2);
  await client.screenshot({ path: out('98-portal-visits.png'), fullPage: false });
  await axe(client, 'portal visits with a fixed finding');

  const phone = await browser.newContext({ ...devices['iPhone 13'], storageState: path.join(S, 'client-state.json') });
  const mp = await phone.newPage();
  watch(mp);
  await mp.goto(portalCase + '?tab=visits');
  check('the visits fit a phone screen', await mp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  await phone.close();

  check('no script errors or failed assets', errors.length === 0, errors.join(' | '));
  fs.writeFileSync(out('phase9-results.json'), JSON.stringify(results, null, 2));
  await browser.close();
  process.exit(results.every(r => r.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
