// E2E: release 1.15.1 — phones. Every page of the site, the client portal and
// the manager app at 320px (the narrowest common phone) and 390px: nothing
// wider than the screen (a wide table scrolls inside its own box), no text
// under 12px. Then every email the earlier journeys sent, at 320px, as sent
// and with its <style> blocks removed, as some phone apps do: it must still
// fit the screen. Runs after phase10.e2e.js, with the sessions phase3 saved.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const S = process.argv[2];
const BASE = 'http://127.0.0.1:8088';
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  — ' + detail : '')); };

// What sticks out of the screen, and text under 12px. Visually hidden helpers (skip link, honeypot) are off screen on purpose.
async function inspect(page) {
  return page.evaluate(() => {
    const vw = window.innerWidth;
    const hidden = (el) => el.closest('.screen-reader-text, .dx-skip, .dxp-hp, .dxp-sr, [aria-hidden="true"]');
    const small = [];
    for (const el of document.querySelectorAll('body *')) {
      const cs = getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden' || hidden(el)) continue;
      const text = [...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim().length > 1);
      if (text && parseFloat(cs.fontSize) < 12 && el.getBoundingClientRect().width > 0) small.push(el.tagName.toLowerCase() + '.' + String(el.className).split(' ')[0] + ' ' + cs.fontSize);
    }
    return { width: document.documentElement.scrollWidth, vw, small: [...new Set(small)].slice(0, 4) };
  });
}

async function sweep(browser, label, state, urls) {
  for (const width of [320, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: 760 }, isMobile: true, hasTouch: true, storageState: state || undefined });
    const page = await ctx.newPage();
    const wide = [];
    const small = [];
    for (const url of urls) {
      await page.goto(BASE + url, { waitUntil: 'load' });
      const r = await inspect(page);
      if (r.width > r.vw) wide.push(url + ' ' + r.width + 'px');
      if (r.small.length) small.push(url + ' ' + r.small.join(', '));
    }
    check(label + ' at ' + width + 'px: every page fits the screen', wide.length === 0, wide.join(' | '));
    check(label + ' at ' + width + 'px: no text under 12px', small.length === 0, small.join(' | '));
    await ctx.close();
  }
}

(async () => {
  const browser = await chromium.launch();
  const client = path.join(S, 'client-state.json');
  const staff = path.join(S, 'staff-state.json');

  const c = await browser.newContext({ storageState: client });
  const cp = await c.newPage();
  await cp.goto(BASE + '/portal/');
  const caseUrl = new URL(cp.url()).pathname;
  await c.close();

  await sweep(browser, 'site', null, ['/', '/portal/login/', '/no-such-page/']);
  await sweep(browser, 'client portal', client, ['', 'finance', 'schedule', 'visits', 'quotes', 'documents', 'decisions', 'photos', 'messages'].map(t => caseUrl + (t ? '?tab=' + t : '')).concat(['/portal/account/']));
  await sweep(browser, 'manager app', staff, ['/portal/manage/', '/portal/manage/cases/', '/portal/manage/board/', '/portal/manage/new/'].concat(['', 'details', 'members', 'budget', 'finance', 'plan', 'schedule', 'documents', 'updates', 'decisions', 'photos', 'messages', 'meetings', 'visits', 'quotes', 'tasks', 'log'].map(t => '/portal/manage/case/1/' + (t ? '?tab=' + t : ''))));

  // Every HTML email the journeys sent, at a 320px phone, with and without <style>.
  const mails = fs.readdirSync(path.join(S, 'mails')).map(f => JSON.parse(fs.readFileSync(path.join(S, 'mails', f), 'utf8'))).filter(m => /<html/i.test(String(m.message || '')));
  const ctx = await browser.newContext({ viewport: { width: 320, height: 700 } });
  const page = await ctx.newPage();
  const wide = [];
  for (const mail of mails) {
    for (const [mode, html] of [['as sent', mail.message], ['without <style>', mail.message.replace(/<style[\s\S]*?<\/style>/g, '')]]) {
      await page.setContent(html, { waitUntil: 'load' });
      const width = await page.evaluate(() => document.documentElement.scrollWidth);
      if (width > 320) wide.push(mail.subject + ' (' + mode + ') ' + width + 'px');
    }
  }
  check('every email (' + mails.length + ') fits a 320px phone, with or without its <style> block', mails.length > 10 && wide.length === 0, wide.slice(0, 5).join(' | '));
  await ctx.close();

  fs.writeFileSync(path.join(S, 'e2e', 'mobile-results.json'), JSON.stringify(results, null, 2));
  await browser.close();
  process.exit(results.every(r => r.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
