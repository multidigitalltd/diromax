// Sign-in helpers for the journeys. Since release 1.7.0 every sign-in with a
// password asks for a 6-digit code sent by email (decision 16); these helpers
// read the code from the captured mail files and enter it.
const fs = require('fs');
const path = require('path');

// Mail files are named by the second; compare sets of names, not their order.
const mailNames = (S) => new Set(fs.readdirSync(path.join(S, 'mails')));

// The code in the first sign-in-code email to `to` that is not in `seen`.
async function codeFor(S, seen, to, timeoutMs = 10000) {
  const end = Date.now() + timeoutMs;
  while (Date.now() < end) {
    for (const name of fs.readdirSync(path.join(S, 'mails'))) {
      if (seen.has(name)) continue;
      const mail = JSON.parse(fs.readFileSync(path.join(S, 'mails', name), 'utf8'));
      const match = mail.to === to && String(mail.subject).match(/קוד כניסה: (\d{6})/);
      if (match) return match[1];
    }
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  throw new Error('No sign-in code was emailed to ' + to);
}

// wp-login.php: user name and password, then the code from the email.
async function wpSignIn(page, S, base, { user, pass, email, redirect }) {
  await page.goto(base + '/wp-login.php' + (redirect ? '?redirect_to=' + encodeURIComponent(redirect) : ''));
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  const seen = mailNames(S);
  await page.click('#wp-submit');
  await page.waitForSelector('#dx-code');
  await page.fill('#dx-code', await codeFor(S, seen, email));
  await page.click('#dx-code-submit');
  await page.waitForLoadState('load');
}

// The portal's password form (the page must be /portal/login/): email and
// password, then the code from the email.
async function portalSignIn(page, S, { email, pass }) {
  await page.click('.dxp-alt summary');
  await page.fill('#dxp-pw-email', email);
  await page.fill('#dxp-pw', pass);
  const seen = mailNames(S);
  await page.click('.dxp-alt button[type=submit]');
  await page.waitForSelector('#dxp-code');
  await page.fill('#dxp-code', await codeFor(S, seen, email));
  await page.click('form:has(#dxp-code) button[type=submit]');
  await page.waitForLoadState('load');
}

module.exports = { mailNames, codeFor, wpSignIn, portalSignIn };
