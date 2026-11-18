// E2E: the client's journey — sign in by link, read the case, download, and the negative paths.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
// Work dir with mails/ and e2e/ (screenshots); created by setup.sh.
const S = process.argv[2];
const BASE = 'http://127.0.0.1:8088';
const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const out = (n) => path.join(S, 'e2e', n);
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  — ' + detail : '')); };

async function axe(page, label) {
  await page.addScriptTag({ content: AXE });
  const r = await page.evaluate(async () => (await axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] })).violations.map(v => ({ id: v.id, impact: v.impact, nodes: v.nodes.length, sample: v.nodes[0] && v.nodes[0].target.join(' ') })));
  check('axe: ' + label, r.length === 0, r.length ? JSON.stringify(r) : '0 violations');
}

function latestMail() {
  const files = fs.readdirSync(path.join(S, 'mails')).sort();
  return JSON.parse(fs.readFileSync(path.join(S, 'mails', files[files.length - 1]), 'utf8'));
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const page = await ctx.newPage();
  const consoleErrors = [];
  page.on('console', m => { if (m.type() === 'error' && !/status of 404/.test(m.text())) consoleErrors.push(m.text()); });
  page.on('response', r => { if (r.status() >= 400 && r.request().resourceType() !== 'document') consoleErrors.push(r.status() + ' ' + r.url()); });
  page.on('pageerror', e => consoleErrors.push(e.message));

  // 1. Not signed in → login page, with private headers.
  let resp = await page.goto(BASE + '/portal/');
  check('portal redirects anonymous visitors to login', page.url().startsWith(BASE + '/portal/login/'));
  const h = resp.headers();
  check('login page: no-store + noindex + nosniff + frame', /no-store/.test(h['cache-control'] || '') && /noindex/.test(h['x-robots-tag'] || '') && h['x-content-type-options'] === 'nosniff' && /SAMEORIGIN/.test(h['x-frame-options'] || ''), JSON.stringify({ cc: h['cache-control'], robots: h['x-robots-tag'], ref: h['referrer-policy'] }));
  await page.screenshot({ path: out('01-login.png') });
  await axe(page, 'login form');

  // 2. Unknown address → same answer as a known one.
  await page.fill('#dxp-email', 'stranger@example.org');
  await page.click('button[type=submit]');
  const unknownText = await page.textContent('main');
  const mailsBefore = fs.readdirSync(path.join(S, 'mails')).length;
  await page.goto(BASE + '/portal/login/');
  await page.fill('#dxp-email', 'cohen@example.org');
  await page.click('button[type=submit]');
  const knownText = await page.textContent('main');
  check('same response for known and unknown addresses', unknownText.replace(/\s+/g, ' ') === knownText.replace(/\s+/g, ' '));
  await page.screenshot({ path: out('02-link-sent.png') });

  // 3. Link from the email → confirm page → signed in.
  await page.waitForTimeout(300);
  check('exactly one email was sent (to the known address)', fs.readdirSync(path.join(S, 'mails')).length === mailsBefore + 1);
  const mail = latestMail();
  check('login email goes to the client', mail.to === 'cohen@example.org', mail.subject);
  const link = (mail.message.match(/href="([^"]*\/portal\/login\/\?t=[^"]+)"/) || [])[1].replace(/&#038;/g, '&');
  await page.goto(link);
  check('link opens a confirmation page (prefetch-safe)', (await page.textContent('h1')).includes('כניסה לאזור האישי') && await page.isVisible('button:has-text("כניסה")'));
  await axe(page, 'link confirmation');
  await page.click('button:has-text("כניסה")');
  await page.waitForLoadState('networkidle');
  check('single case → lands straight on the case', /\/portal\/case\/[a-f0-9]{24}\//.test(page.url()), page.url());
  const caseUrl = page.url().split('?')[0];

  // 4. Link cannot be reused.
  const ctx2 = await browser.newContext();
  const p2 = await ctx2.newPage();
  await p2.goto(link);
  check('used link is refused', (await p2.textContent('main')).includes('פג תוקף או שכבר נעשה בו שימוש'));
  await ctx2.close();

  // 5. The case page.
  resp = await page.goto(caseUrl);
  const ch = resp.headers();
  check('case page is private, no-store', /private/.test(ch['cache-control']) && /no-store/.test(ch['cache-control']));
  const body = await page.textContent('main');
  check('case shows the spec numbers', ['480,000 ₪', '78,800 ₪', '45,000 ₪', '9,700 ₪', '24,000 ₪', '40,000 ₪'].every(v => body.includes(v)));
  check('hidden entry and internal note are absent', !body.includes('פריט פנימי') && !body.includes('סעיף 6'));
  check('stage 05 is current', await page.getAttribute('.dxp-stage--active', 'aria-current') === 'step' && (await page.textContent('.dxp-stage--active')).includes('05'));
  check('no horizontal scroll at 1366', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  await page.screenshot({ path: out('03-case-finance.png'), fullPage: true });
  await axe(page, 'case — finance');

  // 6. Filters (progressive enhancement).
  await page.selectOption('#dxp-f-type', 'payment');
  const visibleRows = await page.$$eval('.dxp-table tbody tr', rows => rows.filter(r => !r.hidden).length);
  check('type filter narrows the table', visibleRows === 1, String(visibleRows));
  await page.selectOption('#dxp-f-type', '');

  // 7. Other tabs.
  await page.goto(caseUrl + '?tab=documents');
  await page.screenshot({ path: out('04-case-documents.png'), fullPage: true });
  await axe(page, 'case — documents');
  const docHref = await page.getAttribute('.dxp-agr a.dxp-btn', 'href');
  const dl = await page.request.get(docHref);
  const dh = dl.headers();
  const pdf = (await dl.body()).toString('latin1');
  check('agreement downloads decrypted, as attachment', dl.status() === 200 && pdf.startsWith('%PDF') && /attachment/.test(dh['content-disposition']) && dh['x-content-type-options'] === 'nosniff', dh['content-disposition']);
  await page.goto(caseUrl + '?tab=updates');
  await page.screenshot({ path: out('05-case-updates.png'), fullPage: true });
  await axe(page, 'case — updates');

  // 8. Negative paths.
  resp = await page.goto(BASE + '/portal/case/' + 'a'.repeat(24) + '/');
  check('unknown case → 404', resp.status() === 404);
  resp = await page.goto(BASE + '/portal/doc/' + 'b'.repeat(24) + '/');
  check('unknown document → 404', resp.status() === 404);
  await page.goto(BASE + '/wp-admin/');
  check('client is sent from wp-admin back to the portal', page.url().startsWith(BASE + '/portal/'), page.url());
  check('no admin bar for clients', !(await page.$('#wpadminbar')));
  const privateDir = await page.request.get(BASE + '/wp-content/uploads/dx-private/');
  check('no public listing of the private store', privateDir.status() !== 200 || !(await privateDir.text()).includes('.bin'));

  // 9. Mobile.
  const m = await browser.newContext({ ...require('playwright').devices['iPhone 13'] });
  await m.addCookies(await ctx.cookies());
  const mp = await m.newPage();
  await mp.goto(caseUrl);
  check('no horizontal scroll on iPhone 13', await mp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  await mp.screenshot({ path: out('06-case-mobile.png'), fullPage: true });
  await m.close();

  // 10. Sign out.
  await page.goto(caseUrl);
  await page.click('.dxp-account button');
  await page.waitForLoadState('networkidle');
  check('sign out returns to login with a message', page.url().includes('/portal/login/') && (await page.textContent('main')).includes('יצאת'));

  check('no browser console errors', consoleErrors.length === 0, consoleErrors.join(' | '));
  fs.writeFileSync(out('client-results.json'), JSON.stringify(results, null, 2));
  await browser.close();
  process.exit(results.every(r => r.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
