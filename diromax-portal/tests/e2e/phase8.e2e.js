// E2E: release 1.12.0 — the client timeline. The office sets typical stage
// lengths; the manager works out the dates from a first day, fixes a bad
// date, publishes the timeline and adds a milestone; the client sees plan
// against actual; a moved date with a note and an email shows the date first
// given. axe on every new screen, and the phone width. Runs after
// phase7.e2e.js, with the sessions phase3.e2e.js saved (sign-ins are rate limited).
const { chromium, devices } = require('playwright');
const fs = require('fs');
const path = require('path');
const S = process.argv[2];
const BASE = 'http://127.0.0.1:8088';
const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const out = (n) => path.join(S, 'e2e', n);
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  — ' + detail : '')); };
const mailFiles = () => new Set(fs.readdirSync(path.join(S, 'mails')));
const mailsSince = (seen) => [...mailFiles()].filter(f => !seen.has(f)).sort().map(f => JSON.parse(fs.readFileSync(path.join(S, 'mails', f), 'utf8')));
// A date in the site's time zone, some days from now.
const localDate = (days) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jerusalem' }).format(new Date(Date.now() + days * 864e5));
const shown = (ymd) => ymd.split('-').reverse().join('.');

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

  // ---------- Office: typical stage lengths ----------
  const actx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'admin-state.json') });
  const admin = await actx.newPage();
  watch(admin);
  await admin.goto(BASE + '/wp-admin/admin.php?page=dx-portal-settings');
  await admin.fill('#dxa-weeks-1', '2');
  await admin.fill('#dxa-weeks-2', '6');
  await admin.fill('#dxa-weeks-3', '8');
  await submit(admin, 'form:has(input[value="dx_portal_save_settings"]) [type=submit]');
  check('the stage lengths are saved', (await admin.inputValue('#dxa-weeks-2')) === '6' && (await admin.inputValue('#dxa-weeks-4')) === '0');

  // ---------- Manager: work out, fix, publish ----------
  const mctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'staff-state.json') });
  const page = await mctx.newPage();
  watch(page);
  const caseUrl = BASE + '/portal/manage/case/1/';
  await page.goto(caseUrl + '?tab=schedule');
  check('the case has a timeline tab, not yet shown to the client', (await page.locator('.dxa-tabs .nav-tab', { hasText: 'לוח זמנים' }).count()) === 1 && (await page.textContent('.dxa-plan-sum__status')).includes('עדיין לא מוצג ללקוח'));
  await axe(page, 'timeline tab before publishing');

  const start = localDate(0);
  if (!(await page.locator('#form-compute').evaluate(e => e.open))) await page.click('#form-compute summary');
  await page.fill('#dxa-compute-start_on', start);
  await submit(page, '#form-compute [type=submit]');
  check('the dates are worked out from the stage lengths', (await page.textContent('.notice-success')).includes('התאריכים חושבו') && (await page.inputValue('#dxa-schedule-start-1')) === start && (await page.inputValue('#dxa-schedule-end-3')) !== '');

  const end2 = await page.inputValue('#dxa-schedule-end-2');
  await page.fill('#dxa-schedule-end-2', localDate(-30));
  await submit(page, '#form-schedule [type=submit]');
  check('an end before its start is refused on its field', (await page.getAttribute('#dxa-schedule-end-2', 'aria-invalid')) === 'true' && (await page.textContent('#dxa-schedule-end-2-error')).includes('לפני ההתחלה'));
  await axe(page, 'timeline form with an error');

  await page.fill('#dxa-schedule-end-2', end2);
  await page.check('#form-schedule input[name="visible"]');
  await submit(page, '#form-schedule [type=submit]');
  check('the timeline is saved and shown to the client', (await page.textContent('.notice-success')).includes('מוצג ללקוח') && (await page.locator('.dxa-gantt .dxa-gantt__plan').count()) >= 3);

  await page.click('#form-milestone summary');
  await page.fill('#dxa-milestone-label', 'קבלת היתר בנייה');
  await page.selectOption('#dxa-milestone-stage', '2');
  await page.fill('#dxa-milestone-date', localDate(40));
  await submit(page, '#form-milestone [type=submit]');
  check('a milestone is added and drawn', (await page.textContent('.notice-success')).includes('אבן הדרך נוספה') && (await page.textContent('.dxa-milestones')).includes('קבלת היתר בנייה') && (await page.locator('.dxa-gantt__mark').count()) >= 1);
  await axe(page, 'timeline tab with bars and a milestone');

  // ---------- Client: plan against actual ----------
  const cctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'client-state.json') });
  const client = await cctx.newPage();
  watch(client);
  await client.goto(BASE + '/portal/');
  const portalCase = client.url().split('?')[0];
  check('the client has a timeline tab', (await client.locator('.dxp-tab', { hasText: 'לוח זמנים' }).count()) === 1);
  await client.goto(portalCase + '?tab=schedule');
  check('the client sees the bars, the dates and the milestone in words', (await client.locator('.dxp-gantt .dxp-gantt__plan').count()) >= 3 && (await client.textContent('.dxp-sched')).includes('מתוכנן: ' + shown(start)) && (await client.textContent('.dxp-sched')).includes('קבלת היתר בנייה'));
  check('the drawing is hidden from screen readers, the list is not', (await client.getAttribute('.dxp-gantt', 'aria-hidden')) === 'true');
  await axe(client, 'portal timeline');

  // ---------- A date moves: note, email, first date ----------
  const moved = localDate(70);
  await page.goto(caseUrl + '?tab=schedule');
  await page.fill('#dxa-schedule-end-2', moved);
  await page.fill('#dxa-schedule-note', 'הוועדה המקומית דחתה את הדיון');
  await page.check('#form-schedule input[name="notify"]');
  await submit(page, '#form-schedule [type=submit]');
  check('saving with "email the client" says so', (await page.textContent('.notice-success')).includes('יקבל עליו עדכון במייל') && (await page.textContent('.dxa-schedule')).includes('במקור: ' + shown(end2)));
  const before = mailFiles();
  await page.goto(caseUrl + '?tab=overview');
  await submit(page, 'form:has(input[value="dx_portal_send_now"]) [type=submit]');
  const mails = mailsSince(before);
  check('the client gets the change by email, with the date first given and the note', mails.some(m => m.to === 'cohen@example.org' && m.message.includes('לוח הזמנים של הפרויקט עודכן') && m.message.includes('במקור ' + shown(end2)) && m.message.includes('הוועדה המקומית דחתה את הדיון')), JSON.stringify(mails.map(m => [m.to, m.subject])));

  await client.goto(portalCase + '?tab=schedule');
  check('the client sees the new date, the first one and why', (await client.textContent('.dxp-sched')).includes(shown(moved)) && (await client.textContent('.dxp-sched__moved')).includes('במקור: ' + shown(end2)) && (await client.textContent('.dxp-sched__moved')).includes('הוועדה המקומית דחתה את הדיון'));
  await client.screenshot({ path: out('97-portal-timeline.png'), fullPage: false });
  await axe(client, 'portal timeline with a moved date');

  const phone = await browser.newContext({ ...devices['iPhone 13'], storageState: path.join(S, 'client-state.json') });
  const mp = await phone.newPage();
  watch(mp);
  await mp.goto(portalCase + '?tab=schedule');
  check('the timeline fits a phone screen', await mp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  await phone.close();

  check('no script errors or failed assets', errors.length === 0, errors.join(' | '));
  fs.writeFileSync(out('phase8-results.json'), JSON.stringify(results, null, 2));
  await browser.close();
  process.exit(results.every(r => r.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
