// E2E: the management app on the site (/portal/manage/) — sign-in through
// WordPress, the screens, quick add, the way back after a form, who may not
// get in, phones, and the absence of wp-admin around it.
// Runs after the other journeys on the same seeded site.
const { chromium, devices } = require('playwright');
const fs = require('fs');
const path = require('path');
const S = process.argv[2];
const BASE = 'http://127.0.0.1:8088';
const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const out = (n) => path.join(S, 'e2e', n);
const CLIENT_PASSWORD = 'גג חדש בקריית אונו 2026'; // Set by phase2.e2e.js.
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  — ' + detail : '')); };

async function axe(page, label) {
  await page.addScriptTag({ content: AXE });
  const r = await page.evaluate(async () => (await axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] })).violations.map(v => ({ id: v.id, nodes: v.nodes.length, sample: v.nodes[0] && v.nodes[0].target.join(' ') })));
  check('axe: ' + label, r.length === 0, r.length ? JSON.stringify(r) : '0 violations');
}

async function signIn(page, user, pass, redirect) {
  await page.goto(BASE + '/wp-login.php' + (redirect ? '?redirect_to=' + encodeURIComponent(redirect) : ''));
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');
  await page.waitForLoadState('load');
}

(async () => {
  const browser = await chromium.launch();
  const errors = [];
  const watch = (p) => {
    p.on('pageerror', e => errors.push(e.message));
    p.on('response', r => { if (r.status() >= 400 && r.request().resourceType() !== 'document') errors.push(r.status() + ' ' + r.url()); });
  };

  // ---------- Sign-in ----------
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const page = await ctx.newPage();
  watch(page);
  await page.goto(BASE + '/portal/manage/cases/');
  check('signed out → WordPress sign-in, coming back to the asked page', page.url().includes('/wp-login.php') && decodeURIComponent(page.url()).includes('/portal/manage/cases/'), page.url());

  await signIn(page, 'daniel', 'Manager-Dev-Pass-2026!');
  check('a project manager lands in the app after signing in', page.url() === BASE + '/portal/manage/', page.url());
  check('the app is Hebrew, right to left, without wp-admin around it', (await page.getAttribute('html', 'dir')) === 'rtl' && (await page.locator('#wpadminbar, #adminmenu').count()) === 0);
  check('dashboard: totals, attention and active cases', (await page.locator('.dxa-kpis li').count()) === 6 && (await page.textContent('h1')).includes('לוח בקרה') && (await page.locator('.dxa-cases__i').count()) >= 1);
  await axe(page, 'app dashboard');
  await page.screenshot({ path: out('50-app-dashboard.png'), fullPage: true });

  // ---------- Search and list ----------
  await page.fill('#dxa-top-search', 'הזית');
  await Promise.all([page.waitForURL(/\/portal\/manage\/cases\//), page.keyboard.press('Enter')]);
  const found = await page.locator('a.row-title').allTextContents();
  check('search from the top bar finds the matching cases only', page.url().includes('/portal/manage/cases/') && found.length >= 1 && found.every(t => t.includes('הזית')), found.join(' | '));
  await axe(page, 'app cases list');
  await page.goto(BASE + '/portal/manage/cases/?s=' + encodeURIComponent('אין כזה'));
  check('a search with no match says so', (await page.textContent('.dxa-allclear')).includes('לא נמצאו תיקים'));

  // ---------- Case, quick add, back to the app ----------
  await page.goto(BASE + '/portal/manage/cases/');
  await page.click('a.row-title');
  await page.waitForLoadState('load');
  const caseUrl = page.url().split('?')[0];
  check('case screen in the app, with stages and tabs', /\/portal\/manage\/case\/\d+\/$/.test(caseUrl) && (await page.locator('.dxa-step').count()) === 7 && (await page.locator('.dxa-tabs .nav-tab').count()) === 9, caseUrl);
  await axe(page, 'app case overview');

  await page.click('.dxa-head__actions a:has-text("+ תנועה")');
  await page.waitForLoadState('load');
  check('"+ תנועה" opens the form, ready to type', (await page.locator('#form-entry').evaluate(e => e.open)) && await page.evaluate(() => document.activeElement && document.activeElement.id === 'dxa-entry-type'));
  await page.fill('#dxa-entry-description', 'בדיקת מכשיר מהשטח');
  await page.fill('#dxa-entry-amount', '850');
  await page.fill('#dxa-entry-payee', 'מודד מוסמך');
  await page.selectOption('#dxa-entry-category', 'consultants');
  await page.selectOption('#dxa-entry-funding', 'client');
  await page.click('#form-entry button[type=submit]');
  await page.waitForLoadState('load');
  check('after saving, back in the app (not wp-admin) with a notice and the new row', page.url().startsWith(caseUrl) && (await page.textContent('.notice-success')).includes('התנועה נשמרה') && (await page.textContent('.dxa-ledger')).includes('בדיקת מכשיר מהשטח'), page.url());

  await page.goto(caseUrl + '?tab=finance&add=1');
  await page.fill('#dxa-entry-description', 'סכום שגוי');
  await page.fill('#dxa-entry-amount', '-5');
  await page.click('#form-entry button[type=submit]');
  await page.waitForLoadState('load');
  check('a failed save comes back to the app with the error and the values', page.url().startsWith(caseUrl) && (await page.getAttribute('#dxa-entry-amount', 'aria-invalid')) === 'true' && (await page.inputValue('#dxa-entry-description')) === 'סכום שגוי');
  for (const tab of ['finance', 'budget', 'members', 'updates', 'decisions', 'documents', 'details', 'log']) {
    await page.goto(caseUrl + '?tab=' + tab);
    await axe(page, 'app case tab: ' + tab);
  }
  await page.screenshot({ path: out('51-app-case.png'), fullPage: false });

  // ---------- Who may not get in ----------
  let r = await page.goto(BASE + '/portal/manage/settings/');
  check('settings are for administrators only (404 for a project manager)', r.status() === 404);
  r = await page.goto(BASE + '/portal/manage/case/999999/');
  check('a case that does not exist → 404', r.status() === 404);

  const actx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const admin = await actx.newPage();
  watch(admin);
  await signIn(admin, 'dxadmin', 'Admin-Dev-Pass-2026!', BASE + '/portal/manage/new/');
  check('an administrator reaches the app too', admin.url() === BASE + '/portal/manage/new/', admin.url());
  await admin.fill('#dxa-case-title', 'רח׳ האלון 2, רמת גן');
  await admin.selectOption('#dxa-case-manager_id', '1');
  await admin.click('#form-case [type=submit]');
  await admin.waitForLoadState('load');
  const otherCase = admin.url().split('?')[0];
  check('a new case from the app opens in the app', /\/portal\/manage\/case\/\d+\/$/.test(otherCase), admin.url());
  r = await admin.goto(BASE + '/portal/manage/settings/');
  check('settings open for an administrator', r.status() === 200 && (await admin.textContent('h1')).includes('הגדרות'));
  await axe(admin, 'app settings');
  await admin.goto(otherCase);
  check('that case is the administrator\'s, not the project manager\'s', !(await admin.textContent('.dxa-head__meta')).includes('דניאל'));
  r = await page.goto(otherCase);
  check('a project manager cannot open another manager\'s case (404)', r.status() === 404 && !(await page.textContent('body')).includes('רח׳ האלון'));

  const cctx = await browser.newContext();
  const client = await cctx.newPage();
  // The client signs in with the password set in the phase 2 journey (the
  // sign-in link is rate limited per address, and earlier journeys used it).
  await client.goto(BASE + '/portal/login/');
  await client.click('.dxp-alt summary');
  await client.fill('#dxp-pw-email', 'cohen@example.org');
  await client.fill('#dxp-pw', CLIENT_PASSWORD);
  await client.click('.dxp-alt button[type=submit]');
  await client.waitForLoadState('load');
  check('the client is signed in to the portal', client.url().includes('/portal/') && !client.url().includes('/login/'), client.url());
  await client.goto(BASE + '/portal/manage/');
  check('a client is sent back to the portal', !client.url().includes('/manage/') && client.url().includes('/portal/'), client.url());
  r = await client.request.post(BASE + '/wp-admin/admin-post.php', { form: { action: 'dx_portal_demo_case', _wpnonce: 'x' } });
  check('a client cannot use the staff actions', r.status() >= 400);

  // ---------- Phones ----------
  const m = await browser.newContext({ ...devices['iPhone 13'] });
  await m.addCookies(await ctx.cookies());
  const mp = await m.newPage();
  for (const [label, url] of [['dashboard', BASE + '/portal/manage/'], ['cases', BASE + '/portal/manage/cases/'], ['case finance', caseUrl + '?tab=finance']]) {
    await mp.goto(url);
    check('no horizontal scroll on iPhone 13: ' + label, await mp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  }
  await mp.screenshot({ path: out('52-app-mobile.png') });
  await m.close();

  // ---------- Sign out ----------
  await page.goto(BASE + '/portal/manage/');
  await page.click('.dxa-top__user button[type=submit]');
  await page.waitForLoadState('load');
  check('sign out from the app', page.url().includes('/portal/login/') && page.url().includes('out=1'), page.url());

  check('no script errors or failed assets', errors.length === 0, errors.join(' | '));
  fs.writeFileSync(out('manage-results.json'), JSON.stringify(results, null, 2));
  await browser.close();
  process.exit(results.every(r => r.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
