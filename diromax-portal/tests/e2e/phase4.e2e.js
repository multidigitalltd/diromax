// E2E: release 1.8.0 — the photo journal (upload by stage, show/hide, the
// client's timeline, protected image URLs) and the conversation (the client
// writes in the portal, the manager answers from the case), with axe on
// every new screen. Runs after phase3.e2e.js, as the staff member and the
// client it saved (sign-ins are rate limited).
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
// Mail files are named by the second; several in one second do not sort in order, so compare sets.
const mailFiles = () => new Set(fs.readdirSync(path.join(S, 'mails')));
const mailsSince = (seen) => [...mailFiles()].filter(f => !seen.has(f)).sort().map(f => JSON.parse(fs.readFileSync(path.join(S, 'mails', f), 'utf8')));

async function axe(page, label) {
  await page.addScriptTag({ content: AXE });
  const r = await page.evaluate(async () => (await axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] })).violations.map(v => ({ id: v.id, nodes: v.nodes.length, sample: v.nodes[0] && v.nodes[0].target.join(' ') })));
  check('axe: ' + label, r.length === 0, r.length ? JSON.stringify(r) : '0 violations');
}

// Thumbnails really arrive (decrypted and served as images), not just their tags.
// Fetched directly: lazy ones below the fold are not loaded until scrolled to.
async function loaded(page, selector) {
  let ok = 0;
  for (const src of await page.$$eval(selector, (imgs) => imgs.map(i => i.currentSrc || i.src))) {
    const res = await page.request.get(src);
    ok += res.status() === 200 && res.headers()['content-type'] === 'image/jpeg' && (await res.body()).length > 500 ? 1 : 0;
  }
  return ok;
}

function photo(name, width, height, rgb) {
  const file = path.join(S, 'e2e', name);
  execFileSync('php', ['-r', '$i = imagecreatetruecolor((int) $argv[2], (int) $argv[3]); imagefill($i, 0, 0, imagecolorallocate($i, ...array_map("intval", explode(",", $argv[4])))); imagejpeg($i, $argv[1], 85);', file, String(width), String(height), rgb]);
  return file;
}

(async () => {
  const browser = await chromium.launch();
  const errors = [];
  const watch = (p) => {
    p.on('pageerror', e => errors.push(e.message));
    p.on('response', r => { if (r.status() >= 400 && r.request().resourceType() !== 'document' && !r.url().includes('/photo/')) errors.push(r.status() + ' ' + r.url()); });
  };
  const files = [photo('site-1.jpg', 1600, 1200, '190,110,40'), photo('site-2.jpg', 1200, 1600, '40,120,170'), photo('site-3.jpg', 800, 600, '120,120,120')];

  // ---------- Manager: add photos to the journal ----------
  const mctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'staff-state.json') });
  const page = await mctx.newPage();
  watch(page);
  const caseUrl = BASE + '/portal/manage/case/1/';
  await page.goto(caseUrl + '?tab=photos&add=1');
  check('the case has a photos tab, with the upload form open', (await page.locator('.dxa-tabs .nav-tab', { hasText: 'צילומים' }).count()) === 1 && await page.locator('#form-photos').evaluate(e => e.open));
  await axe(page, 'photos tab, empty');
  await page.setInputFiles('#dxa-photos-files', files);
  await page.selectOption('#dxa-photos-stage', '4');
  await page.fill('#dxa-photos-caption', 'קורות הגג');
  await page.click('#form-photos button[type=submit]');
  await page.waitForLoadState('load');
  check('three photos are added to stage 4', (await page.textContent('.notice-success')).includes('3 צילומים נוספו ליומן') && (await page.locator('.dxa-photo').count()) === 3 && (await page.textContent('.dxa-photos h3')).includes('04'));
  check('the thumbnails load, with their size set (no layout shift)', (await loaded(page, '.dxa-photo img')) === 3 && (await page.$$eval('.dxa-photo img', imgs => imgs.every(i => Number(i.getAttribute('width')) > 0 && Number(i.getAttribute('height')) > 0 && Number(i.getAttribute('width')) <= 640))));
  const hiddenThumb = await page.getAttribute('.dxa-photo:nth-child(3) img', 'src');
  const hiddenFull = await page.getAttribute('.dxa-photo:nth-child(3) a', 'href');
  await page.click('.dxa-photo:nth-child(3) button:has-text("להסתיר מהלקוח")');
  await page.waitForLoadState('load');
  check('one photo is kept internal', (await page.textContent('.notice-success')).includes('הוסתר') && (await page.locator('.dxa-photo.is-hidden').count()) === 1);
  await page.screenshot({ path: out('80-admin-photos.png'), fullPage: true });
  await axe(page, 'photos tab with the journal');

  // The client hears of the shown photos in the batched update (this
  // client chose the daily digest in phase2, so the preview shows it).
  await page.goto(caseUrl);
  const preview = await page.request.get(await page.getAttribute('a:has-text("תצוגה מקדימה של המייל")', 'href'));
  const previewHtml = await preview.text();
  check('the waiting update email tells the client of the two shown photos', preview.status() === 200 && previewHtml.includes('2 צילומים מהשטח') && previewHtml.includes('tab=photos') && previewHtml.includes('קורות הגג'));
  let before = mailFiles();

  // ---------- Client: the journal ----------
  const cctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, storageState: path.join(S, 'client-state.json') });
  const client = await cctx.newPage();
  watch(client);
  await client.goto(BASE + '/portal/');
  const portalCase = client.url().split('?')[0];
  await client.goto(portalCase + '?tab=photos');
  check('the client sees the two shown photos, by stage, with their caption', (await client.locator('.dxp-photo').count()) === 2 && (await client.textContent('.dxp-photos h3')).includes('04') && (await client.textContent('.dxp-photos')).includes('קורות הגג'));
  check('…and they load', (await loaded(client, '.dxp-photo img')) === 2);
  const full = await client.request.get(await client.getAttribute('.dxp-photo a', 'href'));
  check('the full photo opens as an image, never cached', full.status() === 200 && full.headers()['content-type'] === 'image/jpeg' && full.headers()['cache-control'].includes('no-store') && full.headers()['content-disposition'].startsWith('inline'));
  check('the internal photo is a 404 for the client (thumbnail and full)', (await client.request.get(hiddenThumb)).status() === 404 && (await client.request.get(hiddenFull)).status() === 404);
  const anon = await browser.newContext();
  const anonRes = await anon.request.get(await client.getAttribute('.dxp-photo img', 'src'), { maxRedirects: 0 });
  check('a signed-out visitor gets no photo', anonRes.status() !== 200, String(anonRes.status()));
  await anon.close();
  await client.screenshot({ path: out('81-portal-photos.png'), fullPage: true });
  await axe(client, 'portal photo journal');

  const phone = await browser.newContext({ ...devices['iPhone 13'], storageState: path.join(S, 'client-state.json') });
  const mp = await phone.newPage();
  await mp.goto(portalCase + '?tab=photos');
  check('the journal fits a phone screen', await mp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  await mp.screenshot({ path: out('82-portal-photos-phone.png'), fullPage: true });
  await phone.close();

  // ---------- Client: write to the manager ----------
  await client.goto(portalCase + '?tab=messages');
  await axe(client, 'portal messages, before the first one');
  await client.fill('#dxp-msg-body', 'שלום דניאל,\nמתי מתחילים בקומה העליונה?');
  before = mailFiles();
  await client.click('.dxp-chat__form button[type=submit]');
  await client.waitForLoadState('load');
  const toManager = mailsSince(before).find(m => m.to === 'daniel@example.org');
  check('the message is saved and the manager gets all of it, answering to the client', (await client.textContent('.dxp-notice--ok')).includes('ההודעה נשלחה') && (await client.textContent('.dxp-chat')).includes('מתי מתחילים בקומה העליונה?') && !!toManager && toManager.message.includes('מתי מתחילים בקומה העליונה?') && toManager.headers.some(h => h.startsWith('Reply-To:') && h.includes('cohen@example.org')), toManager ? toManager.subject : 'no mail');

  // ---------- Manager: the dashboard, then the answer ----------
  await page.goto(BASE + '/portal/manage/');
  check('the dashboard lists the conversation waiting for an answer', (await page.textContent('#dxa-dash-awaiting')).includes('הודעות שממתינות לתשובה') && (await page.textContent('.dxa-card:has(#dxa-dash-awaiting)')).includes('משפחת כהן'));
  await page.goto(caseUrl + '?tab=messages');
  check('the case shows the client\'s message', (await page.textContent('.dxa-messages')).includes('מתי מתחילים בקומה העליונה?'));
  await axe(page, 'case conversation');
  await page.fill('#dxa-message-body', 'מתחילים ביום ראשון בבוקר.');
  before = mailFiles();
  await page.click('#form-message button[type=submit]');
  await page.waitForLoadState('load');
  const answer = mailsSince(before).find(m => m.to === 'cohen@example.org');
  check('the answer is saved and the client gets all of it by email', (await page.textContent('.notice-success')).includes('התשובה נשלחה') && !!answer && answer.subject.includes('תשובה ממנהל הפרויקט') && answer.message.includes('מתחילים ביום ראשון בבוקר.'), answer ? answer.subject : 'no mail');
  await page.goto(BASE + '/portal/manage/');
  check('once answered, it leaves the dashboard', (await page.locator('#dxa-dash-awaiting').count()) === 0);

  await client.goto(portalCase + '?tab=messages');
  check('the client sees the answer, marked as the project manager\'s', (await client.textContent('.dxp-chat__msg.is-staff')).includes('מתחילים ביום ראשון בבוקר.') && (await client.textContent('.dxp-chat__msg.is-staff')).includes('מנהל הפרויקט'));
  await client.screenshot({ path: out('83-portal-messages.png'), fullPage: true });
  await axe(client, 'portal conversation');

  check('no script errors or failed assets', errors.length === 0, errors.join(' | '));
  fs.writeFileSync(out('phase4-results.json'), JSON.stringify(results, null, 2));
  await browser.close();
  process.exit(results.every(r => r.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
