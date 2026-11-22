// Performance budget (standard chapter 9): Lighthouse on the key pages, on a
// phone and on a desktop, against the targets of section 9.1. A page over
// budget fails the job, and so blocks the merge (9.2.3). Runs last, on the
// site the other journeys seeded.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { wpSignIn, portalSignIn } = require('./signin');
const S = process.argv[2];
const BASE = 'http://127.0.0.1:8088';
const PORT = 9333;
const CLIENT_PASSWORD = 'גג חדש בקריית אונו 2026'; // Set by phase2.e2e.js.
const out = (n) => path.join(S, 'e2e', n);
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  — ' + detail : '')); };

// Section 9.1. INP has no lab measure; Total Blocking Time stands in for it.
const BUDGET = {
  mobile: { score: 90, lcp: 2500, cls: 0.1, tbt: 200 },
  desktop: { score: 95, lcp: 2500, cls: 0.1, tbt: 200 },
  ttfb: 800,
  scriptKb: 200, // JavaScript on first load, compressed.
};

const cookieHeader = (cookies) => cookies.map(c => c.name + '=' + c.value).join('; ');

async function audit(lighthouse, configs, label, url, cookie) {
  for (const [form, config] of Object.entries(configs)) {
    const flags = { port: PORT, output: 'json', logLevel: 'error', onlyCategories: ['performance'] };
    if (cookie) flags.extraHeaders = { Cookie: cookie };
    const run = await lighthouse(url, flags, config);
    const lhr = run.lhr;
    const a = lhr.audits;
    const score = Math.round((lhr.categories.performance.score || 0) * 100);
    const lcp = Math.round(a['largest-contentful-paint'].numericValue);
    const cls = Number(a['cumulative-layout-shift'].numericValue.toFixed(3));
    const tbt = Math.round(a['total-blocking-time'].numericValue);
    const ttfb = Math.round(a['server-response-time'].numericValue);
    const items = (a['network-requests'].details && a['network-requests'].details.items) || [];
    const scriptKb = Math.round(items.filter(i => i.resourceType === 'Script').reduce((sum, i) => sum + (i.transferSize || 0), 0) / 1024);
    const b = BUDGET[form];
    const name = `${label} (${form})`;
    const summary = `score ${score}, LCP ${lcp}ms, CLS ${cls}, TBT ${tbt}ms, TTFB ${ttfb}ms, JS ${scriptKb}KB`;
    check(`${name}: within budget`, score >= b.score && lcp <= b.lcp && cls <= b.cls && tbt <= b.tbt && ttfb <= BUDGET.ttfb && scriptKb <= BUDGET.scriptKb, summary);
    // A signed-out request would be redirected to a sign-in page and measure that instead.
    check(`${name}: measured the page asked for`, lhr.finalDisplayedUrl === url, lhr.finalDisplayedUrl);
    fs.writeFileSync(out(`lighthouse-${label.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}-${form}.json`), JSON.stringify(lhr));
  }
}

(async () => {
  const { default: lighthouse } = await import('lighthouse');
  const { default: desktop } = await import('lighthouse/core/config/desktop-config.js');
  const configs = { mobile: undefined, desktop };

  // Measure as the staff member and the client of phase3.e2e.js (their saved
  // sessions); sign in only when a session is missing. Lighthouse measures in a
  // fresh tab of its own, so it gets the cookies as a header.
  const signer = await chromium.launch();
  const session = async (file, signIn) => {
    const saved = path.join(S, file);
    const context = await signer.newContext(fs.existsSync(saved) ? { storageState: saved } : {});
    const page = await context.newPage();
    if (!fs.existsSync(saved)) await signIn(page);
    return page;
  };
  const staff = await session('staff-state.json', (p) => wpSignIn(p, S, BASE, { user: 'daniel', pass: 'Manager-Dev-Pass-2026!', email: 'daniel@example.org' }));
  const client = await session('client-state.json', async (p) => {
    await p.goto(BASE + '/portal/login/');
    await portalSignIn(p, S, { email: 'cohen@example.org', pass: CLIENT_PASSWORD });
  });
  await client.goto(BASE + '/portal/');
  const caseUrl = client.url().split('?')[0];
  const staffCookie = cookieHeader(await staff.context().cookies());
  const clientCookie = cookieHeader(await client.context().cookies());
  check('signed in for the measurements', /\/portal\/case\/[a-f0-9]{24}\/$/.test(caseUrl) && staffCookie.includes('wordpress_logged_in'), caseUrl);
  await signer.close();

  const browser = await chromium.launch({ args: ['--remote-debugging-port=' + PORT] });
  // The theme's privacy notice shows on a first visit; measure it as a first visit does.
  await audit(lighthouse, configs, 'home page', BASE + '/', '');
  await audit(lighthouse, configs, 'portal sign-in', BASE + '/portal/login/', '');
  await audit(lighthouse, configs, 'client case', caseUrl, clientCookie);
  await audit(lighthouse, configs, 'client documents', caseUrl + '?tab=documents', clientCookie);
  await audit(lighthouse, configs, 'client photo journal', caseUrl + '?tab=photos', clientCookie);
  await audit(lighthouse, configs, 'client messages', caseUrl + '?tab=messages', clientCookie);
  await audit(lighthouse, configs, 'management dashboard', BASE + '/portal/manage/', staffCookie);
  await audit(lighthouse, configs, 'management case', BASE + '/portal/manage/case/1/', staffCookie);
  await browser.close();

  fs.writeFileSync(out('perf-results.json'), JSON.stringify(results, null, 2));
  process.exit(results.every(r => r.ok) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
