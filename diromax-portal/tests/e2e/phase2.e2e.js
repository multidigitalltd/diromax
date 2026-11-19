// E2E: phase 2 — dashboard, decisions, reminders, email modes, CSV, agreement confirmation, password sign-in.
// Runs after client.e2e.js and manager.e2e.js on the same seeded site.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const S = process.argv[2];
const BASE = 'http://127.0.0.1:8088';
const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const out = (n) => path.join(S, 'e2e', n);
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  — ' + detail : '')); };
const mailFiles = () => fs.readdirSync(path.join(S, 'mails')).sort();
const mailsSince = (n) => mailFiles().slice(n).map(f => JSON.parse(fs.readFileSync(path.join(S, 'mails', f), 'utf8')));
const PASSWORD = 'גג חדש בקריית אונו 2026';

async function axe(page, label, scope) {
  await page.addScriptTag({ content: AXE });
  const r = await page.evaluate(async (sel) => (await axe.run(sel ? document.querySelector(sel) || document : document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] })).violations.map(v => ({ id: v.id, nodes: v.nodes.length, sample: v.nodes[0] && v.nodes[0].target.join(' ') })), scope || null);
  check('axe: ' + label, r.length === 0, r.length ? JSON.stringify(r) : '0 violations');
}

function localDate(offsetDays) {
  const d = new Date(Date.now() + offsetDays * 86400000);
  return d.toISOString().slice(0, 10);
}

// The theme's first-visit privacy notice is tested in theme.e2e.js; here it is acknowledged once per browser.
async function dismissPrivacy(page) {
  const ok = page.locator('[data-dx-privacy-ok]');
  if (await ok.isVisible().catch(() => false)) {
    await ok.click();
  }
}

(async () => {
  const browser = await chromium.launch();
  const errors = [];
  const watch = (p) => {
    p.on('pageerror', e => errors.push(e.message));
    p.on('response', r => { if (r.status() >= 400 && r.request().resourceType() !== 'document') errors.push(r.status() + ' ' + r.url()); });
  };

  // ---------- Project manager ----------
  const admin = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  watch(admin);
  await admin.goto(BASE + '/wp-login.php');
  await admin.fill('#user_login', 'daniel');
  await admin.fill('#user_pass', 'Manager-Dev-Pass-2026!');
  await admin.click('#wp-submit');
  await admin.waitForLoadState('networkidle');

  await admin.goto(BASE + '/wp-admin/admin.php?page=dx-dashboard');
  check('dashboard is the menu landing page', (await admin.textContent('h1')).includes('לוח בקרה') && (await admin.getAttribute('#toplevel_page_dx-dashboard > a', 'href')).includes('page=dx-dashboard'));
  check('dashboard says it is scoped to the manager', (await admin.textContent('.dxa-dash')).includes('התיקים הפעילים שלך'));
  await axe(admin, 'dashboard', '.dxa');

  await admin.goto(BASE + '/wp-admin/admin.php?page=dx-cases');
  const caseId = new URL(await admin.getAttribute('a.row-title', 'href'), BASE).searchParams.get('case');
  const tab = (t) => BASE + `/wp-admin/admin.php?page=dx-cases&case=${caseId}&tab=${t}`;

  // A request due in three days → upcoming card and a manual reminder.
  await admin.goto(tab('finance'));
  await admin.click('#form-entry > summary');
  await admin.selectOption('#dxa-entry-type', 'request');
  await admin.fill('#dxa-entry-description', 'מקדמה לקבלן הגג');
  await admin.fill('#dxa-entry-amount', '15000');
  await admin.fill('#dxa-entry-due_date', localDate(3));
  await admin.selectOption('#dxa-entry-pay_to', 'diromax');
  await admin.click('#form-entry button[type=submit]');
  await admin.waitForLoadState('networkidle');
  check('request saved', (await admin.textContent('.notice-success')).includes('התנועה נשמרה'));

  let before = mailFiles().length;
  const row = admin.locator('tr', { hasText: 'מקדמה לקבלן הגג' });
  await row.locator('button:has-text("שליחת תזכורת עכשיו")').click();
  await admin.waitForLoadState('networkidle');
  const reminder = mailsSince(before);
  check('manual reminder sent to every client who gets email', (await admin.textContent('.notice-success')).includes('התזכורת נשלחה') && reminder.length >= 1 && reminder.every(m => m.subject.includes('תזכורת: דרישת תשלום של 15,000 ₪')), reminder.map(m => m.to + ': ' + m.subject).join(' | '));
  check('reminder button gone for the rest of the day; count shown', (await admin.locator('tr', { hasText: 'מקדמה לקבלן הגג' }).locator('button:has-text("שליחת תזכורת עכשיו")').count()) === 0 && (await admin.locator('tr', { hasText: 'מקדמה לקבלן הגג' }).textContent()).includes('תזכורות שנשלחו: 1'));

  // Staff CSV includes the hidden entry.
  const staffCsv = await admin.request.get(await admin.getAttribute('a:has-text("ייצוא כל התנועות ל-CSV")', 'href'));
  const staffText = (await staffCsv.body()).toString('utf8');
  check('staff CSV: text/csv attachment with BOM, includes hidden entries', staffCsv.status() === 200 && /text\/csv/.test(staffCsv.headers()['content-type']) && /attachment/.test(staffCsv.headers()['content-disposition']) && staffText.charCodeAt(0) === 0xFEFF && staffText.includes('פריט פנימי מוסתר'));

  // A decision for the client, with an email.
  await admin.goto(tab('decisions'));
  await axe(admin, 'decisions tab (empty)', '.dxa');
  await admin.fill('#dxa-decision-title', 'אישור הצעת המחיר של הקבלן');
  await admin.fill('#dxa-decision-body', 'שלד ובנייה של יחידת הגג לפי התוכנית המאושרת, כולל איטום.');
  await admin.fill('#dxa-decision-amount', '84,000');
  before = mailFiles().length;
  await admin.click('#form-decision button[type=submit]');
  await admin.waitForLoadState('networkidle');
  const asked = mailsSince(before);
  check('decision sent and emailed to the clients', (await admin.textContent('.notice-success')).includes('הבקשה נשלחה') && asked.some(m => m.to === 'cohen@example.org' && m.subject.includes('מבקשים את אישורך')), asked.map(m => m.to).join(','));
  await admin.screenshot({ path: out('30-admin-decisions.png'), fullPage: true });

  // Email mode for the partner added by manager.e2e.js.
  await admin.goto(tab('members'));
  const modeSelect = admin.locator('tr', { hasText: 'moshe@example.org' }).locator('select[name=mode]');
  await modeSelect.selectOption('daily');
  await admin.locator('tr', { hasText: 'moshe@example.org' }).locator('button:has-text("שמירה")').click();
  await admin.waitForLoadState('networkidle');
  check('member switched to the daily digest', (await admin.textContent('.notice-success')).includes('העדפת המיילים נשמרה') && (await admin.locator('tr', { hasText: 'moshe@example.org' }).locator('select[name=mode]').inputValue()) === 'daily');

  await admin.goto(BASE + '/wp-admin/admin.php?page=dx-dashboard');
  const dash = await admin.textContent('.dxa-dash');
  check('dashboard lists the upcoming request and the pending decision', dash.includes('מקדמה לקבלן הגג') && dash.includes('אישור הצעת המחיר של הקבלן'));
  await admin.screenshot({ path: out('31-admin-dashboard.png'), fullPage: true });

  // ---------- Client ----------
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const page = await ctx.newPage();
  watch(page);
  await page.goto(BASE + '/portal/login/');
  await dismissPrivacy(page);
  before = mailFiles().length;
  await page.fill('#dxp-email', 'cohen@example.org');
  await page.click('form:has(#dxp-email) button[type=submit]');
  await page.waitForTimeout(300);
  const linkMail = mailsSince(before).find(m => m.to === 'cohen@example.org');
  await page.goto(linkMail.message.match(/href="([^"]*\/portal\/login\/\?t=[^"]+)"/)[1].replace(/&#038;/g, '&'));
  await page.click('button:has-text("כניסה")');
  await page.waitForLoadState('networkidle');
  const caseUrl = page.url().split('?')[0];

  check('"waiting for you" banner lists the decision and the agreement', (await page.textContent('.dxp-todo')).includes('החלטה אחת שמחכה לאישורך') && (await page.textContent('.dxp-todo')).includes('אישור קריאה'));
  await page.screenshot({ path: out('32-portal-todo.png') });

  await page.goto(caseUrl + '?tab=decisions');
  await axe(page, 'portal decisions tab');
  await page.click('button[value=approve]');
  await page.waitForLoadState('networkidle');
  check('approve without the confirmation box → explained, nothing saved', (await page.textContent('.dxp-notice--error')).includes('צריך לסמן') && await page.isVisible('button[value=approve]'));
  before = mailFiles().length;
  await page.check('input[name=confirm]');
  await page.fill('textarea[name=note]', 'מאשרים, תודה');
  await page.click('button[value=approve]');
  await page.waitForLoadState('networkidle');
  const toManager = mailsSince(before);
  check('approval recorded, form gone, manager emailed', (await page.textContent('.dxp-notice--ok')).includes('האישור נרשם') && !(await page.isVisible('button[value=approve]')) && toManager.some(m => m.to === 'daniel@example.org' && m.subject.includes('הלקוח אישר')));
  await page.screenshot({ path: out('33-portal-decision-answered.png'), fullPage: true });

  await page.goto(caseUrl + '?tab=documents');
  await page.check('#dxp-ack-ok');
  await page.click('.dxp-ack button[type=submit]');
  await page.waitForLoadState('networkidle');
  check('agreement confirmed once', (await page.textContent('.dxp-notice--ok')).includes('אישור הקריאה נרשם') && (await page.textContent('.dxp-agr')).includes('אישרת שקראת את ההסכם') && !(await page.$('.dxp-todo')));
  await axe(page, 'portal documents with confirmation');

  await page.goto(caseUrl);
  const clientCsv = await page.request.get(await page.getAttribute('a:has-text("CSV")', 'href'));
  const clientText = (await clientCsv.body()).toString('utf8');
  check('client CSV: visible entries only, no staff columns', clientCsv.status() === 200 && clientText.includes('מדידה ומפת מצב') && !clientText.includes('פריט פנימי') && !clientText.includes('הערה פנימית'));
  const forged = await page.request.get(caseUrl + '?export=csv&_dxn=forged');
  check('CSV with a forged nonce is not served', !/text\/csv/.test(forged.headers()['content-type'] || ''));

  await page.click('.dxp-account a:has-text("החשבון שלי")');
  await page.waitForLoadState('networkidle');
  check('account page opens from the header', page.url().endsWith('/portal/account/'));
  await axe(page, 'account page');
  await page.check('input[name^="mode"][value=daily]');
  await page.click('form:has(input[name^="mode"]) button[type=submit]');
  await page.waitForLoadState('networkidle');
  check('client switched own email mode', (await page.textContent('.dxp-notice--ok')).includes('נשמרו') && await page.isChecked('input[name^="mode"][value=daily]'));

  await page.fill('#dxp-password', 'קצר');
  await page.fill('#dxp-password2', 'קצר');
  await page.evaluate(() => document.querySelectorAll('#dxp-password, #dxp-password2').forEach(i => i.removeAttribute('minlength')));
  await page.click('form:has(#dxp-password) button[type=submit]');
  await page.waitForLoadState('networkidle');
  check('short password refused on the server too', (await page.textContent('.dxp-notice--error')).includes('15 תווים'));
  before = mailFiles().length;
  await page.fill('#dxp-password', PASSWORD);
  await page.click('[data-dxp-reveal="dxp-password"]');
  check('"show password" reveals the field and says so', (await page.getAttribute('#dxp-password', 'type')) === 'text' && (await page.getAttribute('[data-dxp-reveal="dxp-password"]', 'aria-pressed')) === 'true');
  await page.fill('#dxp-password2', PASSWORD);
  await page.click('form:has(#dxp-password) button[type=submit]');
  await page.waitForLoadState('networkidle');
  check('password set after a fresh link sign-in, security email sent', (await page.textContent('.dxp-notice--ok')).includes('הסיסמה נשמרה') && mailsSince(before).some(m => m.subject.includes('הסיסמה לאזור האישי עודכנה')));
  await page.screenshot({ path: out('34-portal-account.png'), fullPage: true });

  await page.click('.dxp-account button');
  await page.waitForLoadState('networkidle');
  await page.click('.dxp-alt summary');
  await axe(page, 'login with the password form open');
  await page.fill('#dxp-pw-email', 'cohen@example.org');
  await page.fill('#dxp-pw', 'סיסמה לא נכונה בכלל');
  await page.click('.dxp-alt button[type=submit]');
  await page.waitForLoadState('networkidle');
  check('wrong password → one generic message', (await page.textContent('.dxp-alert')).includes('כתובת המייל או הסיסמה לא נכונות'));
  await page.fill('#dxp-pw', PASSWORD);
  await page.click('.dxp-alt button[type=submit]');
  await page.waitForLoadState('networkidle');
  check('right password → signed in to the case', /\/portal\/case\/[a-f0-9]{24}\//.test(page.url()), page.url());

  const staff = await (await browser.newContext()).newPage();
  await staff.goto(BASE + '/portal/login/');
  await dismissPrivacy(staff);
  await staff.click('.dxp-alt summary');
  await staff.fill('#dxp-pw-email', 'daniel@example.org');
  await staff.fill('#dxp-pw', 'Manager-Dev-Pass-2026!');
  await staff.click('.dxp-alt button[type=submit]');
  await staff.waitForLoadState('networkidle');
  check('staff cannot use the portal password form', (await staff.textContent('main')).includes('כתובת המייל או הסיסמה לא נכונות'));

  const m = await browser.newContext({ ...require('playwright').devices['iPhone 13'] });
  await m.addCookies(await ctx.cookies());
  const mp = await m.newPage();
  for (const [label, url] of [['decisions', caseUrl + '?tab=decisions'], ['account', BASE + '/portal/account/']]) {
    await mp.goto(url);
    check('no horizontal scroll on iPhone 13: ' + label, await mp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  }
  await mp.screenshot({ path: out('35-portal-account-mobile.png'), fullPage: true });
  await m.close();

  // The sample case, from the dashboard: a full case, and no email at all.
  await admin.goto(BASE + '/wp-admin/admin.php?page=dx-dashboard');
  const mailsBeforeDemo = mailFiles().length;
  await admin.click('form:has(input[value="dx_portal_demo_case"]) button[type=submit]');
  await admin.waitForLoadState('networkidle');
  check('sample case opens on its overview with a notice', (await admin.textContent('h1')).includes('תיק לדוגמה') && (await admin.textContent('.notice-success')).includes('נוצר תיק לדוגמה'));
  check('sample case: stage 05 is the current step', (await admin.textContent('.dxa-step--active')).includes('05'));
  await axe(admin, 'case header, stages and tabs', '.dxa');
  await admin.goto(BASE + '/wp-admin/admin.php?page=dx-dashboard');
  const demoDash = await admin.textContent('.dxa-dash');
  check('dashboard: totals band, the sample case listed, its overdue request flagged', (await admin.locator('.dxa-kpis li').count()) === 6 && demoDash.includes('תיק לדוגמה') && demoDash.includes('השלמת אגרות לוועדה'));
  check('dashboard: recent activity across the manager\'s cases', (await admin.locator('.dxa-feed li').count()) > 0);
  await axe(admin, 'dashboard with totals and activity', '.dxa');
  await admin.screenshot({ path: out('36-admin-dashboard-upgraded.png'), fullPage: true });
  check('the sample case sent no email', mailFiles().length === mailsBeforeDemo);

  check('no script errors or failed assets', errors.length === 0, errors.join(' | '));
  fs.writeFileSync(out('phase2-results.json'), JSON.stringify(results, null, 2));
  await browser.close();
  process.exit(results.every(r => r.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
