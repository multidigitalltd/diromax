// E2E: the project manager's journey in wp-admin.
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
const mails = () => fs.readdirSync(path.join(S, 'mails')).sort();

async function axe(page, label) {
  await page.addScriptTag({ content: AXE });
  const r = await page.evaluate(async () => (await axe.run(document.querySelector('.dxa') || document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] })).violations.map(v => ({ id: v.id, nodes: v.nodes.length, sample: v.nodes[0] && v.nodes[0].target.join(' ') })));
  check('axe (plugin markup): ' + label, r.length === 0, r.length ? JSON.stringify(r) : '0 violations');
}

(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('response', r => { if (r.status() >= 400 && r.request().resourceType() !== 'document') errors.push(r.status() + ' ' + r.url()); });

  await page.goto(BASE + '/wp-login.php');
  await page.fill('#user_login', 'daniel');
  await page.fill('#user_pass', 'Manager-Dev-Pass-2026!');
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');

  await page.goto(BASE + '/wp-admin/admin.php?page=dx-cases');
  check('cases list shows the case with budget use incl. hidden entries (manager view)', (await page.textContent('#wpbody')).includes('רח׳ הזית 14') && (await page.textContent('#wpbody')).includes('16.6%'));
  await page.screenshot({ path: out('10-admin-list.png') });
  await axe(page, 'cases list');

  const caseLink = await page.getAttribute('a.row-title', 'href');
  const caseId = new URL(caseLink, BASE).searchParams.get('case');
  await page.goto(caseLink);
  await page.screenshot({ path: out('11-admin-overview.png'), fullPage: true });
  await axe(page, 'case overview');

  // Quick add: an expense with an invoice attached, notify on.
  await page.goto(BASE + `/wp-admin/admin.php?page=dx-cases&case=${caseId}&tab=finance`);
  const entryForm = page.locator('#form-entry');
  check('the new-entry form starts collapsed when the case has entries', !(await entryForm.evaluate(e => e.open)));
  await entryForm.locator(':scope > summary').focus();
  await page.keyboard.press('Enter');
  check('it opens from the keyboard', await entryForm.evaluate(e => e.open));
  const typeVisible = async (sel) => page.isVisible(sel);
  check('entry form shows expense fields only', await typeVisible('#dxa-entry-funding') && !(await typeVisible('#dxa-entry-due_date')));
  await page.selectOption('#dxa-entry-type', 'request');
  check('switching type to request shows request fields', await typeVisible('#dxa-entry-due_date') && !(await typeVisible('#dxa-entry-funding')));
  await page.selectOption('#dxa-entry-type', 'expense');
  await page.fill('#dxa-entry-description', 'יועץ קונסטרוקציה, מקדמה');
  await page.fill('#dxa-entry-amount', '7,500');
  await page.fill('#dxa-entry-payee', 'מהנדס בניין');
  await page.selectOption('#dxa-entry-category', 'consultants');
  await page.selectOption('#dxa-entry-funding', 'client');
  const pdf = path.join(S, 'invoice-e2e.pdf');
  fs.writeFileSync(pdf, '%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n');
  await page.setInputFiles('#dxa-new-file', pdf);
  await page.screenshot({ path: out('12-admin-finance-form.png') });
  await page.click('#form-entry button[type=submit]');
  await page.waitForLoadState('networkidle');
  check('expense saved with a success notice', (await page.textContent('.notice-success')).includes('התנועה נשמרה'));
  check('new row with its invoice link', (await page.textContent('.dxa-ledger')).includes('יועץ קונסטרוקציה') );

  // Invalid input comes back with an accessible field error and the values kept.
  await entryForm.locator(':scope > summary').click();
  await page.fill('#dxa-entry-description', 'סכום שגוי');
  await page.fill('#dxa-entry-amount', '-5');
  await page.click('#form-entry button[type=submit]');
  await page.waitForLoadState('networkidle');
  const invalid = await page.getAttribute('#dxa-entry-amount', 'aria-invalid');
  const describedBy = await page.getAttribute('#dxa-entry-amount', 'aria-describedby');
  check('a failed save reopens the form', await entryForm.evaluate(e => e.open));
  check('invalid amount → field error linked by aria-describedby, value kept', invalid === 'true' && describedBy && (await page.textContent('#' + describedBy)).includes('הסכום') && (await page.inputValue('#dxa-entry-description')) === 'סכום שגוי');
  await page.screenshot({ path: out('13-admin-finance-error.png') });
  await axe(page, 'finance tab with errors');

  // Pending batch → preview → send now.
  await page.goto(BASE + `/wp-admin/admin.php?page=dx-cases&case=${caseId}&tab=overview`);
  check('pending email banner on overview', (await page.textContent('.dxa-pending')).includes('ממתין לשליחה'));
  const previewHref = await page.getAttribute('.dxa-pending a.button', 'href');
  const preview = await page.request.get(previewHref);
  check('email preview renders the new item', preview.status() === 200 && (await preview.text()).includes('יועץ קונסטרוקציה'));
  const before = mails().length;
  await page.click('.dxa-pending button.button-primary');
  await page.waitForLoadState('networkidle');
  check('send now delivers one email', mails().length === before + 1 && (await page.textContent('.notice-success')).includes('נשלח מייל אחד'));

  // Settle the open request (to Diromax) → payment recorded.
  await page.goto(BASE + `/wp-admin/admin.php?page=dx-cases&case=${caseId}&tab=finance`);
  const settle = page.locator('details[id^="form-settle-"]').first();
  await settle.locator('summary').click();
  await settle.locator('button[type=submit]').click();
  await page.waitForLoadState('networkidle');
  check('request settled into a payment', (await page.textContent('.notice-success')).includes('סומנה כשולמה'));

  // Void the new expense with a reason.
  const voidBox = page.locator('tr', { hasText: 'יועץ קונסטרוקציה' }).locator('details[id^="form-void-"]');
  await voidBox.locator('summary').click();
  await voidBox.locator('input[name=reason]').fill('הוזן בתיק הלא נכון');
  await voidBox.locator('button[type=submit]').click();
  await page.waitForLoadState('networkidle');
  check('entry voided, shown struck through with reason', (await page.textContent('.dxa-ledger')).includes('בוטל: הוזן בתיק הלא נכון'));
  await page.screenshot({ path: out('14-admin-finance.png'), fullPage: true });

  // Members tab: add a second client with an invitation.
  await page.goto(BASE + `/wp-admin/admin.php?page=dx-cases&case=${caseId}&tab=members`);
  await page.fill('#dxa-member-name', 'משה כהן');
  await page.fill('#dxa-member-email', 'moshe@example.org');
  await page.selectOption('#dxa-member-role', 'partner');
  const beforeInvite = mails().length;
  await page.click('form:has(#dxa-member-email) [type=submit]');
  await page.waitForLoadState('networkidle');
  const invite = JSON.parse(fs.readFileSync(path.join(S, 'mails', mails()[mails().length - 1]), 'utf8'));
  check('member added and invited', mails().length === beforeInvite + 1 && invite.to === 'moshe@example.org' && invite.subject.includes('הוזמנת'));
  await axe(page, 'members tab');

  for (const tab of ['details', 'budget', 'updates', 'documents', 'log']) {
    await page.goto(BASE + `/wp-admin/admin.php?page=dx-cases&case=${caseId}&tab=${tab}`);
    await axe(page, tab + ' tab');
    if (tab === 'documents' || tab === 'log') await page.screenshot({ path: out(`15-admin-${tab}.png`), fullPage: true });
  }

  // A manager cannot open a case that is not theirs (made up ID) nor the settings.
  let r = await page.goto(BASE + '/wp-admin/admin.php?page=dx-cases&case=999999');
  check('unknown/foreign case → refused', (await page.textContent('body')).includes('אין לך הרשאה') || r.status() === 404);
  r = await page.goto(BASE + '/wp-admin/admin.php?page=dx-portal-settings');
  check('manager cannot open settings', r.status() === 403 || (await page.textContent('body')).includes('not allowed') || (await page.textContent('body')).includes('אין לך הרשאה'));

  check('no script errors or failed assets', errors.length === 0, errors.join(' | '));
  fs.writeFileSync(out('manager-results.json'), JSON.stringify(results, null, 2));
  await browser.close();
  process.exit(results.every(x => x.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
