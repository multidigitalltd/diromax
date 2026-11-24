// E2E: the theme's Multi Digital web defaults — footer credit and legal links,
// the first-visit privacy notice, a lead with its campaign, and the leads CRM.
// Runs after the other journeys on the same seeded site.
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
const { wpSignIn } = require('./signin');

async function axe(page, label) {
  await page.addScriptTag({ content: AXE });
  const r = await page.evaluate(async () => (await axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] })).violations.map(v => ({ id: v.id, nodes: v.nodes.length, sample: v.nodes[0] && v.nodes[0].target.join(' ') })));
  check('axe: ' + label, r.length === 0, r.length ? JSON.stringify(r) : '0 violations');
}

(async () => {
  const browser = await chromium.launch();
  const errors = [];
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const page = await ctx.newPage();
  page.on('pageerror', e => errors.push(e.message));
  page.on('response', r => { if (r.status() >= 400 && r.request().resourceType() !== 'document') errors.push(r.status() + ' ' + r.url()); });

  // ---------- Footer and privacy notice ----------
  await page.goto(BASE + '/?utm_source=google&utm_medium=cpc&utm_campaign=roof');
  const credit = page.locator('a.dx-footer__credit');
  check('footer credit links to m-d.co.il in a new tab, with noopener', (await credit.getAttribute('href')) === 'https://m-d.co.il' && (await credit.getAttribute('target')) === '_blank' && (await credit.getAttribute('rel')) === 'noopener');
  const legal = await page.textContent('.dx-footer nav[aria-label="מידע משפטי ונגישות"]');
  check('footer links the privacy policy, terms and accessibility statement', legal.includes('מדיניות פרטיות') && legal.includes('תנאי שימוש') && legal.includes('הצהרת נגישות'), legal.replace(/\s+/g, ' ').trim());

  // Theme 2.2.3-2.2.4: the footer and the business description Google reads leave out "תיווך".
  const org = (await page.$$eval('script[type="application/ld+json"]', (scripts) => scripts.map(s => JSON.parse(s.textContent)))).flatMap(d => d['@graph'] || [d]).find(n => n['@type'] === 'RealEstateAgent');
  check('the business description for search engines and the footer tagline leave out "תיווך"', !!org && org.description === 'יזמות וניהול השבחת נכסים.' && !(await page.textContent('.dx-footer')).includes('תיווך, יזמות'), org ? org.description : 'no RealEstateAgent');

  check('the home page has no before/after section, and the FAQ teaser is section 07', (await page.locator('.dx-projects, #dx-s07').count()) === 0 && (await page.locator('section[aria-labelledby="dx-s08"] .dx-kicker__n').textContent()) === '07');

  const notice = page.locator('.dx-privacy');
  await notice.waitFor({ state: 'visible' });
  check('privacy notice shows on the first visit, as a non-modal dialog', (await notice.getAttribute('role')) === 'dialog' && (await notice.getAttribute('aria-modal')) === 'false');
  check('privacy notice does not take focus on page load', await page.evaluate(() => !document.activeElement.closest('.dx-privacy')));
  check('privacy notice links the full policy', (await notice.locator('a').count()) === 1);
  await page.screenshot({ path: out('40-theme-privacy-notice.png') });
  await axe(page, 'front page with the privacy notice');

  await notice.locator('[data-dx-privacy-ok]').focus();
  await page.keyboard.press('Enter');
  check('the button closes it, from the keyboard', await notice.isHidden());
  await page.reload();
  await page.waitForTimeout(300);
  check('not shown again after the choice', await notice.isHidden());

  const settings = page.locator('[data-dx-privacy-open]');
  check('"privacy settings" button in the footer', await settings.isVisible());
  await settings.focus();
  await page.keyboard.press('Enter');
  check('it reopens the notice with focus inside', (await notice.isVisible()) && await page.evaluate(() => !!document.activeElement.closest('.dx-privacy')));
  await page.keyboard.press('Escape');
  check('Escape closes it and focus returns to the button', (await notice.isHidden()) && await page.evaluate(() => document.activeElement.hasAttribute('data-dx-privacy-open')));

  // ---------- A lead with its campaign ----------
  const form = page.locator('form.dx-lead-form').first();
  check('the form carries the campaign from the landing URL', (await form.locator('[data-dx-utm]').inputValue()) === 'google / cpc / roof');
  let before = mailFiles();
  await form.locator('input[name=dx_name]').fill('יעל לוי');
  await form.locator('input[name=dx_phone]').fill('052-7654321');
  await form.locator('input[name=dx_address]').fill('רח׳ הגפן 3, רחובות');
  await form.locator('button[type=submit]').click();
  await page.waitForSelector('.dx-sent, [data-dx-sent], .dx-form-card [role=status]', { timeout: 10000 }).catch(() => {});
  await page.waitForTimeout(500);
  const leadMail = mailsSince(before)[0];
  check('lead accepted, office emailed without the details', !!leadMail && leadMail.message.includes('052-7654321') && leadMail.message.includes('post.php?post='), leadMail ? leadMail.subject : 'no mail');

  // ---------- Leads CRM in the admin ----------
  const admin = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  admin.on('pageerror', e => errors.push(e.message));
  await wpSignIn(admin, S, BASE, { user: 'daniel', pass: 'Manager-Dev-Pass-2026!', email: 'daniel@example.org' });
  await admin.waitForLoadState('networkidle');

  await admin.goto(BASE + '/wp-admin/edit.php?post_type=dx_lead');
  const row = admin.locator('tr', { hasText: 'יעל לוי' });
  check('the project manager sees the lead, status "new", with "open case"', (await row.count()) === 1 && (await row.textContent()).includes('חדש') && (await row.locator('a:has-text("פתיחת תיק")').count()) === 1);
  await axe(admin, 'leads list');

  const exportHref = await admin.getAttribute('a:has-text("ייצוא ל-CSV")', 'href');
  const csv = await admin.request.get(exportHref);
  const csvText = (await csv.body()).toString('utf8');
  check('lead export: CSV with BOM, includes the lead and its campaign', csv.status() === 200 && /text\/csv/.test(csv.headers()['content-type']) && csvText.charCodeAt(0) === 0xFEFF && csvText.includes('יעל לוי') && csvText.includes('google / cpc / roof'));

  await admin.goto(BASE + '/wp-admin/' + (await row.locator('a.row-title').getAttribute('href')).split('/wp-admin/')[1]);
  check('the lead shows its campaign and the consent record', (await admin.textContent('#dx-lead-crm')).includes('google / cpc / roof') && (await admin.textContent('#dx-lead-crm')).includes('הודעת הפרטיות הוצגה'));
  await admin.selectOption('#dx-lead-status', 'progress');
  await admin.fill('#dx-lead-notes', 'חזרנו אליה, נקבעה פגישה');
  await admin.click('#publish');
  await admin.waitForLoadState('networkidle');
  check('status and notes saved', (await admin.inputValue('#dx-lead-status')) === 'progress' && (await admin.inputValue('#dx-lead-notes')) === 'חזרנו אליה, נקבעה פגישה');
  await admin.screenshot({ path: out('41-theme-lead.png'), fullPage: true });

  await admin.goto(BASE + '/wp-admin/edit.php?post_type=dx_lead&page=dx-lead-audit');
  const audit = await admin.textContent('.wrap');
  check('audit log has the view, the export, the status and the notes', ['צפייה', 'ייצוא', 'שינוי סטטוס', 'עדכון הערות'].every(a => audit.includes(a)));

  const forged = await admin.request.get(BASE + '/wp-admin/admin-post.php?action=dx_leads_export&_wpnonce=forged');
  check('export with a forged nonce is refused', !/text\/csv/.test(forged.headers()['content-type'] || ''));

  check('no script errors or failed assets', errors.length === 0, errors.join(' | '));
  fs.writeFileSync(out('theme-results.json'), JSON.stringify(results, null, 2));
  await browser.close();
  process.exit(results.every(r => r.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
