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
const { wpSignIn, portalSignIn } = require('./signin');
const EMAILS = { daniel: 'daniel@example.org', dxadmin: 'admin@example.org' };
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  — ' + detail : '')); };

async function axe(page, label) {
  await page.addScriptTag({ content: AXE });
  const r = await page.evaluate(async () => (await axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] })).violations.map(v => ({ id: v.id, nodes: v.nodes.length, sample: v.nodes[0] && v.nodes[0].target.join(' ') })));
  check('axe: ' + label, r.length === 0, r.length ? JSON.stringify(r) : '0 violations');
}

async function signIn(page, user, pass, redirect) {
  await wpSignIn(page, S, BASE, { user, pass, email: EMAILS[user], redirect });
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

  // ---------- 1.4.0: cache-busting URLs, dashboard actions ----------
  const css = await page.getAttribute('link[href*="admin.min.css"]', 'href');
  check('stylesheet URL carries the version and a content hash', /[?&]ver=\d+\.\d+\.\d+-[a-f0-9]{8}/.test(css), css);
  await page.locator('h1').click();
  await page.keyboard.press('/');
  check('no single-character shortcut takes the focus (standard 8.3.8, WCAG 2.1.4)', await page.evaluate(() => !document.activeElement || document.activeElement.id !== 'dxa-top-search'));

  const settleLink = page.locator('.dxa-row-actions a:has-text("סימון כשולמה")').first();
  check('payment requests on the dashboard have actions', (await page.locator('.dxa-row-actions').count()) > 0);
  await settleLink.click();
  await page.waitForLoadState('load');
  const settleId = new URL(page.url()).searchParams.get('settle');
  check('"mark as paid" opens that request\'s form in the case, ready to fill', page.url().includes('tab=finance') && (await page.locator('#form-settle-' + settleId).evaluate(e => e.open)) && await page.evaluate((id) => !!document.activeElement.closest('#form-settle-' + id), settleId), page.url());

  await page.goto(BASE + '/portal/manage/');
  const remind = page.locator('.dxa-row-actions button:has-text("תזכורת")').first();
  if (await remind.count()) {
    page.once('dialog', d => d.accept());
    await remind.click();
    await page.waitForLoadState('load');
    check('a reminder sent from the dashboard comes back to the dashboard with a notice', page.url() === BASE + '/portal/manage/' && (await page.locator('.notice').count()) > 0, page.url());
  } else {
    check('every request on the dashboard was already reminded today (button hidden)', (await page.locator('.dxa-row-actions:has-text("תזכורת נשלחה היום")').count()) > 0);
  }
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
  check('case screen in the app, with stages and tabs', /\/portal\/manage\/case\/\d+\/$/.test(caseUrl) && (await page.locator('.dxa-step').count()) === 7 && (await page.locator('.dxa-tabs .nav-tab').count()) === 17, caseUrl);
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
  // ---------- Ledger filter ----------
  await page.goto(caseUrl + '?tab=finance');
  check('the ledger filter appears', await page.isVisible('.dxa-filterbar'));
  const allRows = await page.locator('#dxa-ledger-table tbody > tr[data-dxa-row]').count();
  await page.selectOption('#dxa-filter-type', 'payment');
  const visibleTypes = await page.locator('#dxa-ledger-table tbody > tr[data-dxa-row]:not([hidden])').evaluateAll(rows => rows.map(r => r.getAttribute('data-dxa-row')));
  check('filtering by type shows only that type, and says how many', visibleTypes.length > 0 && visibleTypes.length < allRows && visibleTypes.every(t => t === 'payment') && (await page.textContent('[data-dxa-filter-count]')).includes(String(visibleTypes.length)), visibleTypes.join(','));
  await page.selectOption('#dxa-filter-type', '');
  await page.fill('#dxa-filter-text', 'מדידה');
  const textRows = await page.locator('#dxa-ledger-table tbody > tr[data-dxa-row]:not([hidden])').allTextContents();
  check('free-text search narrows the rows', textRows.length >= 1 && textRows.length < allRows && textRows.every(t => t.includes('מדידה')));

  // ---------- Several stages in progress ----------
  await page.goto(caseUrl + '?tab=details');
  check('each stage has its own four-way status', (await page.locator('#dxa-stages fieldset').count()) === 7 && (await page.locator('#dxa-stages input[type=radio]').count()) === 28);
  const firstActive = await page.locator('#dxa-stages .dxa-seg__in--active:checked').evaluateAll(els => els.map(e => e.name));
  await page.locator('label[for="dxa-stage-3-active"]').click();
  await page.locator('label[for="dxa-stage-4-active"]').click();
  await page.locator('#dxa-stage-6-skipped').focus();
  await page.keyboard.press('ArrowLeft');
  const keyChoice = await page.evaluate(() => document.activeElement && document.activeElement.id);
  await page.keyboard.press('ArrowRight');
  await page.locator('label[for="dxa-stage-6-skipped"]').click();
  await page.locator('label[for="dxa-stage-2-done"]').click();
  await page.click('#form-details input[type=submit], #form-details button[type=submit]');
  await page.waitForLoadState('load');
  const nowActive = await page.locator('.dxa-step--active .dxa-step__n').allTextContents();
  check('two stages saved as in progress, back in the app', page.url().startsWith(caseUrl) && nowActive.join(',').includes('03') && nowActive.join(',').includes('04') && (await page.textContent('.notice-success')).includes('פרטי התיק נשמרו'), nowActive.join(',') + ' (before: ' + firstActive.join(',') + ')');
  check('the status choice works with the arrow keys', keyChoice !== null && keyChoice.startsWith('dxa-stage-6-') && keyChoice !== 'dxa-stage-6-skipped', String(keyChoice));
  check('the stepper dates the stages in progress', (await page.textContent('.dxa-step--active .dxa-step__d')).includes('מאז'));
  check('a not-relevant stage is struck through', (await page.locator('.dxa-step--skipped').count()) >= 1);
  await page.goto(BASE + '/portal/manage/cases/');
  check('the cases list says several stages are in progress', (await page.textContent('#the-list, .dxa-table, table')).includes('בתהליך'));
  await page.goto(caseUrl + '?tab=details');
  await page.screenshot({ path: out('52-app-stages.png'), fullPage: false });

  // ---------- Stage board ----------
  await page.click('.dxa-top__nav a:has-text("לוח שלבים")');
  await page.waitForLoadState('load');
  const col3 = await page.textContent('section[aria-labelledby="dxa-board-3"]');
  const col4 = await page.textContent('section[aria-labelledby="dxa-board-4"]');
  check('stage board: seven columns, and the case under both stages it is in', page.url() === BASE + '/portal/manage/board/' && (await page.locator('.dxa-board__col').count()) === 7 && col3.includes('במקביל גם בשלב 04') && col4.includes('במקביל גם בשלב 03'), page.url());
  check('stage board: the nav marks where we are', (await page.getAttribute('.dxa-top__nav a:has-text("לוח שלבים")', 'aria-current')) === 'page');
  await axe(page, 'app stage board');
  await page.screenshot({ path: out('53-app-board.png'), fullPage: true });

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
  check('a new case shows its setup checklist, 0 of 5, each step with a link', (await admin.textContent('.dxa-next h2')).includes('0 מתוך 5') && (await admin.locator('.dxa-checklist .is-todo a').count()) === 5);
  await axe(admin, 'case setup checklist');
  r = await page.goto(otherCase);
  check('a project manager cannot open another manager\'s case (404)', r.status() === 404 && !(await page.textContent('body')).includes('רח׳ האלון'));

  const cctx = await browser.newContext();
  const client = await cctx.newPage();
  // The client signs in with the password set in the phase 2 journey (the
  // sign-in link is rate limited per address, and earlier journeys used it).
  await client.goto(BASE + '/portal/login/');
  await portalSignIn(client, S, { email: 'cohen@example.org', pass: CLIENT_PASSWORD });
  check('the client is signed in to the portal', client.url().includes('/portal/') && !client.url().includes('/login/'), client.url());
  await client.goto(BASE + '/portal/manage/');
  check('a client is sent back to the portal', !client.url().includes('/manage/') && client.url().includes('/portal/'), client.url());
  r = await client.request.post(BASE + '/wp-admin/admin-post.php', { form: { action: 'dx_portal_demo_case', _wpnonce: 'x' } });
  check('a client cannot use the staff actions', r.status() >= 400);

  // ---------- Home screen ----------
  const anon = await browser.newContext();
  const manifestRes = await anon.request.get(BASE + '/portal/manage/manifest.webmanifest');
  const manifest = manifestRes.ok() ? await manifestRes.json() : {};
  check('the web app manifest is public JSON with the app\'s start page', manifestRes.status() === 200 && /manifest\+json/.test(manifestRes.headers()['content-type']) && manifest.start_url === BASE + '/portal/manage/' && manifest.display === 'standalone' && manifest.dir === 'rtl', JSON.stringify(manifest).slice(0, 160));
  const icon = await anon.request.get(manifest.icons ? manifest.icons[1].src : BASE + '/none');
  check('its 512px icon is served as PNG', icon.status() === 200 && icon.headers()['content-type'] === 'image/png');
  check('the app page links the manifest and an iPhone icon', (await page.locator('link[rel="manifest"]').count()) === 1 && (await page.locator('link[rel="apple-touch-icon"]').count()) === 1);
  check('the app page preloads its two first fonts (no late font swap)', (await page.locator('link[rel="preload"][as="font"]').count()) === 2);
  await anon.close();

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
