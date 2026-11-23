// E2E: release 1.10.0 — meetings (the manager schedules and changes one,
// the invited client sees it and downloads the calendar file, cancelling
// takes it off) and the payments forecast (administrators only, with CSV).
// axe on every new screen. Runs after phase5.e2e.js, with the sessions
// phase3.e2e.js saved (sign-ins are rate limited).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const S = process.argv[2];
const BASE = 'http://127.0.0.1:8088';
const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const out = (n) => path.join(S, 'e2e', n);
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  — ' + detail : '')); };
// Mail files are named by the second; several in one second do not sort in order, so compare sets.
const mailFiles = () => new Set(fs.readdirSync(path.join(S, 'mails')));
const mailsSince = (seen) => [...mailFiles()].filter(f => !seen.has(f)).sort().map(f => JSON.parse(fs.readFileSync(path.join(S, 'mails', f), 'utf8')));
// A date in the site's time zone, some days from now.
const localDate = (days) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jerusalem' }).format(new Date(Date.now() + days * 864e5));

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

  // ---------- Manager: schedule a meeting ----------
  const mctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'staff-state.json') });
  const page = await mctx.newPage();
  watch(page);
  const caseUrl = BASE + '/portal/manage/case/1/';
  await page.goto(caseUrl + '?tab=meetings');
  check('the case has a meetings tab, with the form open', (await page.locator('.dxa-tabs .nav-tab', { hasText: 'פגישות' }).count()) === 1 && await page.locator('#form-meeting').evaluate(e => e.open));
  const invite = page.locator('#form-meeting input[name="participants[]"]');
  check('the clients who get email are ticked by default', (await invite.count()) >= 1 && await invite.first().isChecked());
  await axe(page, 'meetings tab, empty');

  await page.fill('#dxa-meeting-title', 'סיור בדירה עם האדריכלית');
  await page.selectOption('#dxa-meeting-kind', 'site');
  await page.fill('#dxa-meeting-date', localDate(-2));
  await page.fill('#dxa-meeting-time', '10:00');
  await submit(page, '#form-meeting [type=submit]');
  check('a meeting in the past is refused on its field', (await page.getAttribute('#dxa-meeting-date', 'aria-invalid')) === 'true' && (await page.inputValue('#dxa-meeting-title')) === 'סיור בדירה עם האדריכלית');
  await axe(page, 'meeting form with an error');

  let before = mailFiles();
  await page.fill('#dxa-meeting-date', localDate(3));
  await page.fill('#dxa-meeting-time', '10:00');
  await page.selectOption('#dxa-meeting-minutes', '90');
  await page.fill('#dxa-meeting-location', 'רח׳ הזית 14, קריית אונו');
  await page.fill('#dxa-meeting-note', 'להביא את התוכניות');
  await submit(page, '#form-meeting [type=submit]');
  let mails = mailsSince(before);
  check('the meeting is saved and listed', (await page.textContent('.notice-success')).includes('הפגישה נקבעה') && (await page.textContent('.dxa-meetings')).includes('סיור בדירה עם האדריכלית'));
  check('the invited client gets an invitation with a calendar file', mails.some(m => m.to === 'cohen@example.org' && m.subject.includes('הזמנה לפגישה') && m.attachments && Object.keys(m.attachments).includes('meeting.ics')), JSON.stringify(mails.map(m => [m.to, m.subject])));
  await axe(page, 'meetings tab with a meeting');

  // ---------- Client: sees it and adds it to the calendar ----------
  const cctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'client-state.json') });
  const client = await cctx.newPage();
  watch(client);
  await client.goto(BASE + '/portal/');
  const portalCase = client.url().split('?')[0];
  check('the client sees the coming meeting on the case page', (await client.textContent('.dxp-meets')).includes('סיור בדירה עם האדריכלית') && (await client.textContent('.dxp-meets')).includes('10:00–11:30'));
  const ics = await client.request.get(await client.getAttribute('.dxp-meet a[download]', 'href'));
  const icsBody = await ics.text();
  check('"add to calendar" downloads an iCalendar file', ics.status() === 200 && ics.headers()['content-type'].startsWith('text/calendar') && icsBody.includes('BEGIN:VCALENDAR') && icsBody.includes('METHOD:REQUEST'));
  await client.screenshot({ path: out('95-portal-meeting.png'), fullPage: false });
  await axe(client, 'portal case page with a meeting');
  const anon = await browser.newContext();
  const anonRes = await anon.request.get(await client.getAttribute('.dxp-meet a[download]', 'href'), { maxRedirects: 0 });
  check('a signed-out visitor gets no calendar file', anonRes.status() !== 200, String(anonRes.status()));
  await anon.close();

  // ---------- Manager: change the time, then cancel ----------
  await page.goto(caseUrl + '?tab=meetings');
  const card = page.locator('.dxa-meeting').first();
  await card.locator('details summary').click();
  const editId = await card.locator('form:has(input[value="dx_portal_update_meeting"]) input[name="meeting_id"]').inputValue();
  before = mailFiles();
  await page.fill('#dxa-meeting' + editId + '-time', '12:00');
  await submit(page, '#form-meeting' + editId + ' [type=submit]');
  mails = mailsSince(before);
  check('a new time sends the update', (await page.textContent('.notice-success')).includes('הפגישה עודכנה') && mails.some(m => m.to === 'cohen@example.org' && m.subject.includes('הפגישה עודכנה')));
  await client.goto(portalCase);
  check('the client sees the new time', (await client.textContent('.dxp-meets')).includes('12:00–13:30'));

  page.once('dialog', d => d.accept());
  before = mailFiles();
  await submit(page, '.dxa-meeting form:has(input[value="dx_portal_cancel_meeting"]) [type=submit]');
  mails = mailsSince(before);
  check('cancelling tells the client and moves the meeting to the past list', (await page.textContent('.notice-success')).includes('הפגישה בוטלה') && mails.some(m => m.to === 'cohen@example.org' && m.subject.includes('הפגישה בוטלה')) && (await page.textContent('.dxa-meetings--past')).includes('בוטלה'));
  await client.goto(portalCase);
  check('a cancelled meeting is off the client page', (await client.locator('.dxp-meets').count()) === 0);

  // ---------- Forecast: administrators only ----------
  const managerForecast = await page.goto(BASE + '/portal/manage/forecast/');
  check('a project manager has no forecast screen', managerForecast.status() === 404);
  const actx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'admin-state.json') });
  const admin = await actx.newPage();
  watch(admin);
  await admin.goto(BASE + '/wp-admin/admin.php?page=dx-forecast');
  check('the administrator sees the forecast by month', (await admin.textContent('h1')).includes('תחזית תשלומים') && (await admin.locator('.dxa-forecast tbody tr').count()) === 6 && (await admin.textContent('.dxa-forecast tfoot')).includes('₪'));
  check('…with the budget of each case', (await admin.textContent('#dxa-forecast-budget')).includes('תקציב מול ביצוע') && (await admin.locator('section[aria-labelledby="dxa-forecast-budget"] tbody tr').count()) >= 1);
  await axe(admin, 'forecast (wp-admin)');
  const csv = await admin.request.get(await admin.getAttribute('.dxa-forecast__tools a', 'href'));
  check('the forecast downloads as CSV', csv.status() === 200 && csv.headers()['content-type'].startsWith('text/csv') && (await csv.text()).includes('למי משלמים'));
  await admin.goto(BASE + '/portal/manage/forecast/');
  check('the forecast is in the app too, with a link in the menu', (await admin.textContent('h1')).includes('תחזית תשלומים') && (await admin.locator('.dxa-top__nav a', { hasText: 'תחזית תשלומים' }).count()) === 1);
  await axe(admin, 'forecast (app)');

  check('no script errors or failed assets', errors.length === 0, errors.join(' | '));
  fs.writeFileSync(out('phase6-results.json'), JSON.stringify(results, null, 2));
  await browser.close();
  process.exit(results.every(r => r.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
