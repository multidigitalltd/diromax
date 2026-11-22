// E2E: release 1.6.0 — stage due dates, team tasks, a document request and
// client uploads, the case report, and digital signatures (portal and an
// emailed link for someone outside the system), with axe on every new screen.
// Runs last, on the site the other journeys seeded.
const { chromium, devices, request } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const S = process.argv[2];
const BASE = 'http://127.0.0.1:8088';
const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const out = (n) => path.join(S, 'e2e', n);
const CLIENT_PASSWORD = 'גג חדש בקריית אונו 2026'; // Set by phase2.e2e.js.
const VALID_ID = '123456782';
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  — ' + detail : '')); };
// Mail files are named by the second; several in one second do not sort in order, so compare sets.
const mailFiles = () => new Set(fs.readdirSync(path.join(S, 'mails')));
const mailsSince = (seen) => [...mailFiles()].filter(f => !seen.has(f)).sort().map(f => JSON.parse(fs.readFileSync(path.join(S, 'mails', f), 'utf8')));
const pdf = path.join(S, 'e2e', 'nesach.pdf');
const { wpSignIn, portalSignIn } = require('./signin');

async function axe(page, label) {
  await page.addScriptTag({ content: AXE });
  const r = await page.evaluate(async () => (await axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] })).violations.map(v => ({ id: v.id, nodes: v.nodes.length, sample: v.nodes[0] && v.nodes[0].target.join(' ') })));
  check('axe: ' + label, r.length === 0, r.length ? JSON.stringify(r) : '0 violations');
}

async function draw(page, canvas) {
  await page.locator(canvas).scrollIntoViewIfNeeded();
  const box = await page.locator(canvas).boundingBox();
  await page.mouse.move(box.x + 30, box.y + 50);
  await page.mouse.down();
  await page.mouse.move(box.x + 140, box.y + 80, { steps: 10 });
  await page.mouse.move(box.x + 260, box.y + 30, { steps: 10 });
  await page.mouse.up();
}

// The theme's first-visit privacy notice is tested in theme.e2e.js; here it is acknowledged once per browser.
async function dismissPrivacy(page) {
  const ok = page.locator('[data-dx-privacy-ok]');
  if (await ok.isVisible().catch(() => false)) {
    await ok.click();
  }
}

function localDate(offsetDays) {
  return new Date(Date.now() + offsetDays * 86400000).toISOString().slice(0, 10);
}

(async () => {
  fs.writeFileSync(pdf, '%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n');
  const browser = await chromium.launch();
  const errors = [];
  const watch = (p) => {
    p.on('pageerror', e => errors.push(e.message));
    p.on('response', r => { if (r.status() >= 400 && r.request().resourceType() !== 'document') errors.push(r.status() + ' ' + r.url()); });
  };

  // ---------- Manager: due date, tasks ----------
  const mctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const page = await mctx.newPage();
  watch(page);
  await wpSignIn(page, S, BASE, { user: 'daniel', pass: 'Manager-Dev-Pass-2026!', email: 'daniel@example.org', redirect: BASE + '/portal/manage/case/1/?tab=details' });
  const caseUrl = BASE + '/portal/manage/case/1/';

  const activeStage = await page.locator('#dxa-stages .dxa-seg__in--active:checked').first().getAttribute('name');
  const number = activeStage.match(/\[(\d)\]/)[1];
  await page.fill('#dxa-stage-due-' + number, localDate(-3));
  await page.click('#form-details input[type=submit], #form-details button[type=submit]');
  await page.waitForLoadState('load');
  check('a stage past its due date is flagged in the stepper', (await page.textContent('.dxa-steps')).includes('עבר את היעד'));
  await page.goto(caseUrl + '?tab=details');
  await axe(page, 'details tab with a due date and a late stage');
  await page.goto(BASE + '/portal/manage/board/');
  check('…and on the stage board', (await page.textContent('.dxa-board')).includes('עבר את היעד'));
  await page.goto(BASE + '/portal/manage/');
  check('…and on the dashboard', (await page.textContent('#dxa-dash-late')).includes('1') && (await page.textContent('.dxa-cards')).includes('שלבים שעברו את היעד'));

  await page.goto(caseUrl + '?tab=tasks');
  await page.fill('#dxa-task-title', 'לתאם מדידה עם המודד');
  await page.fill('#dxa-task-due_date', localDate(-1));
  await page.click('#form-task button[type=submit]');
  await page.waitForLoadState('load');
  check('a task is added, late, in the app', page.url().startsWith(caseUrl) && (await page.textContent('.dxa-tasks')).includes('לתאם מדידה עם המודד') && (await page.textContent('.dxa-tasks')).includes('באיחור'));
  await axe(page, 'tasks tab');
  await page.goto(BASE + '/portal/manage/');
  check('the dashboard lists my tasks', (await page.textContent('#dxa-dash-tasks + table, .dxa-cards')).includes('לתאם מדידה עם המודד'));
  await page.goto(caseUrl + '?tab=tasks');
  await page.click('.dxa-task button:has-text("בוצע")');
  await page.waitForLoadState('load');
  check('a task is marked done', (await page.textContent('.notice-success')).includes('המשימה סומנה כבוצעה') && (await page.textContent('.dxa-muted')).includes('אין משימות פתוחות'));

  // ---------- Manager: ask for a document, send one to be signed ----------
  await page.goto(caseUrl + '?tab=documents');
  await page.click('#form-docrequest > summary');
  await page.fill('#dxa-docrequest-title', 'נסח טאבו עדכני');
  let before = mailFiles();
  await page.click('#form-docrequest button[type=submit]');
  await page.waitForLoadState('load');
  check('a document request is sent to the client', (await page.textContent('.notice-success')).includes('הבקשה נשלחה') && mailsSince(before).some(m => m.to === 'cohen@example.org' && m.subject.includes('נסח טאבו עדכני')));

  await page.click('#form-signrequest > summary');
  await page.fill('#dxa-signrequest-signer_name', 'יוסי השכן');
  await page.fill('#dxa-signrequest-signer_email', 'yossi@example.org');
  before = mailFiles();
  await page.click('#form-signrequest button[type=submit]');
  await page.waitForLoadState('load');
  const signMail = mailsSince(before).find(m => m.to === 'yossi@example.org');
  check('a signing link is emailed to someone outside the system', !!signMail && signMail.subject.includes('מסמך לחתימתך'));
  await axe(page, 'documents tab with requests');

  // ---------- Client: upload what was asked, the report ----------
  const cctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const client = await cctx.newPage();
  watch(client);
  await client.goto(BASE + '/portal/login/');
  await dismissPrivacy(client);
  await portalSignIn(client, S, { email: 'cohen@example.org', pass: CLIENT_PASSWORD });
  const portalCase = client.url().split('?')[0];
  check('the banner asks for the document', (await client.textContent('.dxp-todo')).includes('להעלות: נסח טאבו עדכני'));
  await client.goto(portalCase + '?tab=documents');
  await axe(client, 'portal documents with a request');
  before = mailFiles();
  await client.setInputFiles('.dxp-ask input[type=file]', pdf);
  await client.click('.dxp-ask button[type=submit]');
  await client.waitForLoadState('load');
  check('the client uploads the requested document; the manager is told', (await client.textContent('.dxp-notice--ok')).includes('המסמך הועלה') && !(await client.$('.dxp-asks')) && mailsSince(before).some(m => m.to === 'daniel@example.org' && m.subject.includes('מסמך חדש מהלקוח')));
  check('it appears under "מהלקוח"', (await client.textContent('.dxp-docs')).includes('מהלקוח'));

  // Regression: a photo is re-encoded (location data removed) through
  // wp_tempnam(), which WordPress loads only in wp-admin; on the portal it crashed.
  const photo = path.join(S, 'e2e', 'roof.jpg');
  execFileSync('php', ['-r', '$i = imagecreatetruecolor(40, 30); imagefill($i, 0, 0, imagecolorallocate($i, 200, 120, 40)); imagejpeg($i, $argv[1]);', photo]);
  await client.click('#upload summary');
  await client.fill('#dxp-upload-title', 'צילום הגג');
  await client.setInputFiles('#dxp-upload-file', photo);
  await client.click('#upload button[type=submit]');
  await client.waitForLoadState('load');
  check('the client uploads a photo from the portal', (await client.textContent('.dxp-notice--ok')).includes('המסמך הועלה') && (await client.textContent('.dxp-docs')).includes('צילום הגג'));

  await client.goto(portalCase);
  await client.click('a:has-text("דוח מלא")');
  await client.waitForLoadState('load');
  check('the report opens for the client', client.url().endsWith('/report/') && (await client.textContent('h1')).length > 0 && (await client.textContent('.dxr')).includes('שלבי התהליך') && !(await client.textContent('.dxr')).includes('פריט פנימי'), client.url());
  check('…with a "save as PDF" button', await client.isVisible('[data-dxp-print]'));
  await axe(client, 'report page');
  await client.screenshot({ path: out('60-report.png'), fullPage: true });
  const foreign = await client.request.get(BASE + '/portal/case/' + 'f'.repeat(24) + '/report/');
  check('another case\'s report is a 404', foreign.status() === 404);

  // ---------- Someone outside the system signs through the link ----------
  const signUrl = signMail.message.match(/href="([^"]*\/portal\/sign\/[A-Za-z0-9_-]{43}\/)"/)[1];
  const sctx = await browser.newContext({ ...devices['iPhone 13'] });
  const signer = await sctx.newPage();
  watch(signer);
  await signer.goto(signUrl);
  await dismissPrivacy(signer);
  check('the link opens the signing page, without signing in', (await signer.textContent('h1')).length > 0 && await signer.isVisible('a:has-text("קריאת המסמך")'));
  const file = await signer.request.get(signUrl + 'file/');
  check('the link opens its one document', file.status() === 200 && /pdf/.test(file.headers()['content-type'] || ''));
  await axe(signer, 'signing page, step 1');
  before = mailFiles();
  await signer.click('button:has-text("שליחת קוד למייל")');
  await signer.waitForLoadState('load');
  const code = (mailsSince(before).find(m => m.to === 'yossi@example.org') || { message: '' }).message.match(/קוד החתימה: (\d{6})/);
  check('a 6-digit code is emailed', !!code);
  await signer.fill('#dxp-sign-code', code[1]);
  await signer.fill('#dxp-sign-id', '123456789');
  await signer.fill('#dxp-sign-link-name', 'יוסי כהן');
  await draw(signer, '[data-dxp-sign] canvas');
  await signer.check('#dxp-sign-ok');
  await signer.click('[data-dxp-sign] button[type=submit]');
  await signer.waitForLoadState('load');
  check('a wrong ID number is refused, with the field marked', (await signer.getAttribute('#dxp-sign-id', 'aria-invalid')) === 'true');
  await axe(signer, 'signing page with an error');
  await signer.fill('#dxp-sign-code', code[1]);
  await signer.fill('#dxp-sign-id', VALID_ID);
  await draw(signer, '[data-dxp-sign] canvas');
  await signer.check('#dxp-sign-ok');
  before = mailFiles();
  await signer.click('[data-dxp-sign] button[type=submit]');
  await signer.waitForLoadState('load');
  check('signed through the link; confirmation to the signer, note to the manager', (await signer.textContent('h1')).includes('החתימה התקבלה') && mailsSince(before).some(m => m.to === 'yossi@example.org') && mailsSince(before).some(m => m.to === 'daniel@example.org'));
  check('no horizontal scroll on the phone', await signer.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  await signer.screenshot({ path: out('61-signed-phone.png') });
  await signer.goto(signUrl);
  check('the used link is refused', (await signer.textContent('h1')).includes('כבר לא פעיל'));

  // ---------- Manager: the signature and its certificate ----------
  await page.goto(caseUrl + '?tab=documents');
  check('the document shows who signed', (await page.textContent('.dxa-table')).includes('נחתם על ידי יוסי כהן'));
  const certificate = await page.getAttribute('a:has-text("אישור חתימה")', 'href');
  await page.goto(certificate.replace(/&#038;/g, '&'));
  const certText = await page.textContent('body');
  check('the certificate holds the ID number, the method and the drawing', certText.includes(VALID_ID) && certText.includes('קוד חד-פעמי') && (await page.locator('img[alt^="החתימה של"]').count()) === 1);
  await axe(page, 'signature certificate');

  // ---------- Release 1.7.0: a client's email reply reaches the case (spec 17.3) ----------
  // Postmark posts the reply to the inbound webhook over real HTTP, with Basic credentials.
  const webhookKey = fs.readFileSync(path.join(S, 'webhook-key'), 'utf8').trim();
  const publicId = portalCase.match(/\/portal\/case\/([a-f0-9]{24})\//)[1];
  const hash = execFileSync('php', [path.join(S, 'mailbox-hash.php'), publicId]).toString().trim();
  const attachment = Buffer.from('%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n' + Date.now() + '\ntrailer << /Root 1 0 R >>\n%%EOF\n');
  const reply = {
    MessageID: 'e2e-' + Date.now(),
    From: 'cohen@example.org',
    FromName: 'משפחת כהן',
    FromFull: { Email: 'cohen@example.org', Name: 'משפחת כהן', MailboxHash: '' },
    ToFull: [{ Email: 'replies+' + hash + '@inbound.postmarkapp.com', Name: '', MailboxHash: hash }],
    MailboxHash: hash,
    Subject: 'Re: עדכון בתיק',
    TextBody: 'מתי מגיע הקבלן?\n\n> המייל המקורי מדירומקס',
    StrippedTextReply: 'מתי מגיע הקבלן?',
    Headers: [{ Name: 'X-Spam-Status', Value: 'No' }, { Name: 'X-Spam-Tests', Value: 'DKIM_SIGNED,DKIM_VALID,DKIM_VALID_AU,SPF_PASS' }],
    Attachments: [{ Name: 'צילום-גג.pdf', Content: attachment.toString('base64'), ContentType: 'application/pdf', ContentLength: attachment.length }],
  };
  const basic = (password) => ({ Authorization: 'Basic ' + Buffer.from('postmark:' + password).toString('base64') });
  const inboundUrl = BASE + '/wp-json/diromax-portal/v1/postmark/inbound';
  const api = await request.newContext();
  let res = await api.post(inboundUrl, { data: reply, headers: basic('not-the-key') });
  check('the inbound webhook refuses a wrong key', res.status() === 401, String(res.status()));
  before = mailFiles();
  res = await api.post(inboundUrl, { data: reply, headers: basic(webhookKey) });
  const answer = res.ok() ? await res.json() : {};
  check('with the key, WordPress lets the request through and the reply is kept', res.status() === 200 && answer.result === 'stored', res.status() + ' ' + JSON.stringify(answer));
  const forward = mailsSince(before).find(m => m.to === 'daniel@example.org');
  check('the manager gets the whole reply, with the file, answering goes to the client', !!forward && forward.subject.includes('הודעה ממשפחת כהן') && forward.message.includes('המייל המקורי מדירומקס') && forward.headers.some(h => h.startsWith('Reply-To:') && h.includes('cohen@example.org')) && Object.keys(forward.attachments || {}).includes('צילום-גג.pdf'), forward ? forward.subject : 'no mail');
  res = await api.post(inboundUrl, { data: reply, headers: basic(webhookKey) });
  check('the same reply delivered twice is kept once', res.status() === 200 && (await res.json()).result === 'duplicate');
  await api.dispose();

  await page.goto(caseUrl + '?tab=messages');
  const messagesTab = await page.textContent('.dxa-messages');
  check('the messages tab shows the reply and links its file', messagesTab.includes('מתי מגיע הקבלן?') && !messagesTab.includes('המייל המקורי') && (await page.locator('.dxa-message__files a', { hasText: 'צילום-גג.pdf' }).count()) === 1);
  await page.screenshot({ path: out('70-messages-tab.png'), fullPage: true });
  await axe(page, 'case messages tab');

  // The administrator sees how mail is set up, never the key itself.
  const actx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const adminPage = await actx.newPage();
  watch(adminPage);
  await wpSignIn(adminPage, S, BASE, { user: 'dxadmin', pass: 'Admin-Dev-Pass-2026!', email: 'admin@example.org', redirect: BASE + '/wp-admin/admin.php?page=dx-portal-settings' });
  const settingsHtml = await adminPage.content();
  check('settings show the Postmark setup and the webhook addresses, not the key', settingsHtml.includes('שליחה וקבלה של מיילים') && settingsHtml.includes('postmark:KEY@127.0.0.1:8088/wp-json/diromax-portal/v1/postmark/inbound') && !settingsHtml.includes(webhookKey));
  await axe(adminPage, 'settings with the mail section');
  check('a webhook key from wp-config.php locks the button', !settingsHtml.includes('dx_portal_new_webhook_key') && settingsHtml.includes('wp-config.php'));

  // Release 1.8.1: integrations are set on this screen. A bad value saves nothing and is shown on its field.
  await adminPage.fill('#dxa-integrations-from', 'portal@localhost'); // Passes the browser's email check, not the server's.
  await adminPage.click('form:has(input[value="dx_portal_save_integrations"]) [type=submit]');
  await adminPage.waitForLoadState('load');
  check('a bad sender address is refused on its field', (await adminPage.textContent('.notice-error')).includes('לא נשמרו') && (await adminPage.getAttribute('#dxa-integrations-from', 'aria-invalid')) === 'true');
  await axe(adminPage, 'settings with a field error');
  await adminPage.fill('#dxa-integrations-from', 'portal@example.org');
  await adminPage.click('form:has(input[value="dx_portal_save_integrations"]) [type=submit]');
  await adminPage.waitForLoadState('load');
  check('the sender address is saved from the screen', (await adminPage.textContent('.notice-success')).includes('ההגדרות נשמרו') && (await adminPage.inputValue('#dxa-integrations-from')) === 'portal@example.org');
  check('the token field is a password field and empty', (await adminPage.getAttribute('#dxa-integrations-token', 'type')) === 'password' && (await adminPage.inputValue('#dxa-integrations-token')) === '');
  before = mailFiles();
  await adminPage.click('form:has(input[value="dx_portal_test_mail"]) [type=submit]');
  await adminPage.waitForLoadState('load');
  check('a test email goes to the administrator', (await adminPage.textContent('.notice-success')).includes('מייל בדיקה נשלח') && mailsSince(before).some(m => m.to === 'admin@example.org' && m.subject.includes('מייל בדיקה')));

  check('no script errors or failed assets', errors.length === 0, errors.join(' | '));
  fs.writeFileSync(out('phase3-results.json'), JSON.stringify(results, null, 2));
  // The performance budget (perf.e2e.js) measures as these two, without signing in again:
  // sign-ins are rate limited per address, and earlier journeys used them.
  await mctx.storageState({ path: path.join(S, 'staff-state.json') });
  await cctx.storageState({ path: path.join(S, 'client-state.json') });
  await browser.close();
  process.exit(results.every(r => r.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
