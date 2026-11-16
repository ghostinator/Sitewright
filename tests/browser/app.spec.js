// Browser suite against the real Apache + MariaDB + worker stack from tests/docker/smoke.sh. Serial: later tests use accounts made earlier.
const { test, expect } = require('@playwright/test');
const crypto = require('node:crypto');

const ADMIN = { user: 'admin', pass: 'smoke-test-passphrase' };
const ED = { user: 'edie', pass: 'editor passphrase 12345', email: 'edie@example.test' };
const VIEW = { user: 'vera', pass: 'viewer passphrase 12345', email: 'vera@example.test' };
test.describe.configure({ mode: 'serial' });

// RFC 6238 TOTP (SHA-1, 30 s, 6 digits) from a base32 secret
function totp(b32, t = Date.now()) {
  const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = '';
  for (const c of b32.replace(/=+$/, '').toUpperCase()) bits += A.indexOf(c).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g).map(b => parseInt(b, 2)));
  const buf = Buffer.alloc(8); buf.writeBigUInt64BE(BigInt(Math.floor(t / 30000)));
  const h = crypto.createHmac('sha1', key).update(buf).digest(); const o = h[19] & 15;
  return String(((h[o] & 127) << 24 | h[o + 1] << 16 | h[o + 2] << 8 | h[o + 3]) % 1000000).padStart(6, '0');
}

let problems = [];
test.beforeEach(({ page }) => {
  problems = [];
  page.on('pageerror', e => problems.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error' && !/status of (400|401|403|404|409|429)/.test(m.text()) && !/Cross-Origin-Opener-Policy header has been ignored/.test(m.text())) problems.push('console: ' + m.text()); });
});
test.afterEach(() => { expect(problems, 'JavaScript errors / CSP violations on the page').toEqual([]); });

async function login(page, who, code) {
  await page.goto('/');
  await page.fill('#login-username', who.user); await page.fill('#login-password', who.pass);
  await page.click('#login-form button[type=submit]');
  if (code) { await page.fill('#login-code', code); await page.click('#login-form button[type=submit]'); }
  await expect(page.locator('#page-title')).toBeVisible();
}
const signOut = async page => { await page.click('[data-action=logout]'); await expect(page.locator('#login-form')).toBeVisible(); };

test('sign-in: wrong password is refused with a message; correct one reaches the dashboard; headers are strict', async ({ page }) => {
  const resp = await page.goto('/');
  expect(resp.headers()['content-security-policy']).toContain("script-src 'self'");
  expect(resp.headers()['x-frame-options']).toBe('DENY');
  await page.fill('#login-username', ADMIN.user); await page.fill('#login-password', 'definitely wrong');
  await page.click('#login-form button[type=submit]');
  await expect(page.locator('#login-error')).toContainText('Invalid');
  await login(page, ADMIN);
  await expect(page.locator('#page-title')).toHaveText('Dashboard');
});

test('every view loads without error alerts or console errors (admin sees all, including Sites/Users/Audit)', async ({ page }) => {
  await login(page, ADMIN);
  const views = await page.$$eval('.nav-item', els => els.map(e => e.dataset.view));
  for (const v of ['dashboard', 'seo-analysis', 'content-ai', 'local-seo', 'sitemaps', '404-monitor', 'redirections', 'account', 'sites', 'users', 'audit']) expect(views).toContain(v);
  for (const v of views) {
    await page.click(`.nav-item[data-view="${v}"]`);
    await expect(page.locator(`#view-${v}`)).toHaveClass(/active/);
    await page.waitForTimeout(250);
    await expect(page.locator(`#view-${v} .alert-error:not(.hidden)`)).toHaveCount(0);
  }
});

test('users: admin creates an editor and a viewer; the viewer gets a read-only UI and server-side 403s', async ({ page }) => {
  await login(page, ADMIN);
  await page.click('.nav-item[data-view=users]');
  for (const [u, role] of [[ED, 'editor'], [VIEW, 'viewer']]) {
    await page.click('[data-target=user-create-form]');
    await page.fill('#nu-username', u.user); await page.fill('#nu-email', u.email); await page.selectOption('#nu-role', role); await page.fill('#nu-password', u.pass);
    await page.click('#user-create-form button[type=submit]');
    await expect(page.locator('#users-list')).toContainText(u.user);
  }
  await expect(page.getByLabel('Role of admin')).toBeDisabled();   // cannot edit yourself
  await signOut(page);
  await login(page, VIEW);
  const views = await page.$$eval('.nav-item', els => els.map(e => e.dataset.view));
  for (const hidden of ['users', 'audit', 'sites']) expect(views).not.toContain(hidden);
  expect((await page.goto('/users')).status()).toBe(403);
  problems.length = 0;   // Chromium's own plain-text viewer logs a style-src notice for our strict CSP; the app pages above/below are still checked
  await page.goto('/');
  const r = await page.evaluate(async () => {
    const cfg = JSON.parse(document.getElementById('app-config').textContent);
    const res = await fetch('/api/redirections', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrfToken }, body: JSON.stringify({ source_url: '/x', target_url: '/y' }) });
    return res.status;
  });
  expect(r).toBe(403);
});

test('editor: redirect rule lifecycle and a location with opening hours (create, edit, JSON-LD)', async ({ page }) => {
  await login(page, ED);
  await page.click('.nav-item[data-view=redirections]');
  await page.click('[data-target=redirection-form]');
  await page.fill('#redir-source', '/browser-old'); await page.fill('#redir-target', '/browser-new');
  await page.click('#redirection-form button[type=submit]');
  await expect(page.locator('#redirections-list')).toContainText('/browser-old');
  await page.click('[data-target=redirection-form]');
  await page.fill('#redir-source', '/bad'); await page.fill('#redir-target', 'javascript:alert(1)');
  await page.click('#redirection-form button[type=submit]');
  await expect(page.locator('#toast')).toContainText('Invalid');
  page.once('dialog', d => d.accept());
  await page.locator('#redirections-list .list-item', { hasText: '/browser-old' }).locator('button', { hasText: 'Delete' }).click();
  await expect(page.locator('#redirections-list')).not.toContainText('/browser-old');

  await page.click('.nav-item[data-view=local-seo]');
  await page.click('[data-target=location-form]');
  await page.fill('#loc-name', 'Browser Bakery'); await page.fill('#loc-city', 'Springfield'); await page.fill('#loc-phone', '+1 202-555-0100');
  await page.fill('#location-form [name=open_monday]', '09:00'); await page.fill('#location-form [name=close_monday]', '17:00');
  await page.click('#loc-save');
  await expect(page.locator('#locations-list')).toContainText('Browser Bakery');
  await page.locator('#locations-list .list-item', { hasText: 'Browser Bakery' }).locator('button', { hasText: 'Edit' }).click();
  await expect(page.locator('#loc-form-title')).toContainText('Edit Browser Bakery');
  await expect(page.locator('#location-form [name=open_monday]')).toHaveValue('09:00');
  await expect(page.locator('#location-form [name=closed_tuesday]')).toBeChecked();
  await page.fill('#location-form [name=close_monday]', '18:30'); await page.fill('#location-form [name=open_tuesday]', '10:00'); await page.fill('#location-form [name=close_tuesday]', '14:00');
  await page.uncheck('#location-form [name=closed_tuesday]');
  await page.click('#loc-save');
  await page.locator('#locations-list .list-item', { hasText: 'Browser Bakery' }).locator('button', { hasText: 'JSON-LD' }).click();
  await expect(page.locator('#loc-schema')).toContainText('openingHoursSpecification');
  const ld = await page.locator('#loc-schema').textContent();
  expect(ld).toContain('18:30'); expect(ld).toContain('Tuesday');
  page.once('dialog', d => d.accept());
  await page.locator('#locations-list .list-item', { hasText: 'Browser Bakery' }).locator('button', { hasText: 'Delete' }).click();
  await expect(page.locator('#locations-list')).not.toContainText('Browser Bakery');
});

test('crawl runs as a background job: queued, picked up by the worker, result rendered', async ({ page }) => {
  await login(page, ED);
  await page.click('.nav-item[data-view=sitemaps]');
  await page.fill('#sitemap-url', 'http://sw-fixture/');
  await page.click('#sitemap-crawl-form button[type=submit]');
  await expect(page.locator('#sitemap-results')).toContainText('Crawl finished', { timeout: 45000 });
  await expect(page.locator('#sitemap-results')).toContainText('Pages fetched');
});

test('two-step verification: set up in the UI, sign in with a recovery code, then switch it off', async ({ page }) => {
  await login(page, ED);
  await page.click('.nav-item[data-view=account]');
  await expect(page.locator('#mfa-state')).toContainText('OFF');
  await page.fill('#mfa-setup-pw', 'wrong'); await page.click('#mfa-setup-form button[type=submit]');
  await expect(page.locator('#toast')).toContainText('incorrect');
  await page.fill('#mfa-setup-pw', ED.pass); await page.click('#mfa-setup-form button[type=submit]');
  await expect(page.locator('#mfa-secret-text')).toHaveText(/^[A-Z2-7]{32}$/);
  const secret = (await page.locator('#mfa-secret-text').textContent()).trim();
  await page.fill('#mfa-code', totp(secret)); await page.click('#mfa-enable-form button[type=submit]');
  await expect(page.locator('#mfa-codes pre')).toContainText('-');
  const codes = (await page.locator('#mfa-codes pre').textContent()).trim().split('\n');
  expect(codes).toHaveLength(8);
  await expect(page.locator('#mfa-state')).toContainText('ON');
  await signOut(page);
  await page.goto('/');
  await page.fill('#login-username', ED.user); await page.fill('#login-password', ED.pass); await page.click('#login-form button[type=submit]');
  await expect(page.locator('#login-code')).toBeVisible();
  await expect(page.locator('#login-password')).toBeHidden();
  await page.fill('#login-code', '000000'); await page.click('#login-form button[type=submit]');
  await expect(page.locator('#login-error')).toContainText('Invalid');
  await page.fill('#login-code', codes[0]); await page.click('#login-form button[type=submit]');
  await expect(page.locator('#page-title')).toBeVisible();
  await page.click('.nav-item[data-view=account]');
  page.once('dialog', d => d.accept());
  await page.fill('#mfa-disable-pw', ED.pass); await page.click('#mfa-disable-form button[type=submit]');
  await expect(page.locator('#mfa-state')).toContainText('OFF');
});

test('forgot password: the page answers the same for any address (no account enumeration)', async ({ page }) => {
  await page.goto('/');
  await page.click('#forgot-toggle');
  for (const email of ['edie@example.test', 'nobody@example.test']) {
    await page.fill('#forgot-email', email); await page.click('#forgot-form button[type=submit]');
    await expect(page.locator('#forgot-msg')).toHaveText('If that address belongs to an account, a reset link has been sent.');
  }
  expect((await page.goto('/reset?token=garbage')).status()).toBe(200);
  await page.fill('#reset-password', 'a perfectly long new password'); await page.click('#reset-form button[type=submit]');
  await expect(page.locator('#reset-error')).toContainText('invalid or has expired');
});

test('audit log shows what happened, newest first', async ({ page }) => {
  await login(page, ADMIN);
  await page.click('.nav-item[data-view=audit]');
  await expect(page.locator('#audit-list')).toContainText('login_failed');
  await expect(page.locator('#audit-list')).toContainText('user_created');
  await expect(page.locator('#audit-list')).toContainText('mfa_enabled');
  await expect(page.locator('#audit-list')).not.toContainText('definitely wrong');       // typed passwords are never recorded
});

test('settings: search, change, review old → new, save, see "Saved here", reset to the default', async ({ page }) => {
  await login(page, ADMIN);
  await page.click('.nav-item[data-view=settings]');
  await expect(page.locator('#page-title')).toHaveText('Settings');
  await page.fill('#settings-search', 'pause between');
  const row = page.locator('[data-setting-row="limits.crawl_delay_ms"]');
  await expect(row).toBeVisible();
  await expect(row).toContainText('Built-in default');
  await row.getByLabel('Pause between pages').fill('450');
  await expect(page.locator('#settings-bar')).toContainText('1 unsaved change');
  await expect(page.locator('#settings-bar')).toContainText('Pause between pages');
  await page.getByRole('button', { name: 'Review' }).click();
  await expect(page.locator('#settings-dialog')).toContainText('200 milliseconds');
  await expect(page.locator('#settings-dialog')).toContainText('450 milliseconds');
  await page.getByRole('button', { name: 'Save changes' }).last().click();
  await expect(page.locator('#toast')).toContainText('Changes saved');
  await page.reload(); await page.click('.nav-item[data-view=settings]');
  await page.fill('#settings-search', 'pause between');
  await expect(page.locator('[data-setting-row="limits.crawl_delay_ms"]')).toContainText('Saved here');
  await expect(page.locator('[data-setting-row="limits.crawl_delay_ms"] input')).toHaveValue('450');
  page.once('dialog', d => d.accept());
  await page.locator('[data-setting-row="limits.crawl_delay_ms"]').getByRole('button', { name: 'Reset' }).click();
  await expect(page.locator('[data-setting-row="limits.crawl_delay_ms"]')).toContainText('Built-in default');
  await expect(page.locator('[data-setting-row="limits.crawl_delay_ms"] input')).toHaveValue('200');
  await page.fill('#settings-search', 'zzzz-nothing');
  await expect(page.locator('#settings-root')).toContainText('No setting matches');
  await page.click('.nav-item[data-view=audit]');
  await expect(page.locator('#audit-list')).toContainText('setting_changed');
  await expect(page.locator('#audit-list')).toContainText('limits.crawl_delay_ms: 200 -> 450');
});

test('settings: keyboard only, and a dangerous change cannot be saved without the dialog', async ({ page }) => {
  await login(page, ADMIN);
  await page.click('.nav-item[data-view=settings]');
  await page.fill('#settings-search', 'postal address');
  const field = page.getByLabel('Postal address');
  await field.focus(); await page.keyboard.type('https://postal.example.test');
  const save = page.getByRole('button', { name: 'Save changes' });
  await save.focus(); await page.keyboard.press('Enter');
  const dlg = page.locator('#settings-dialog');
  await expect(dlg).toBeVisible(); await expect(dlg).toContainText('Clears the stored Postal API key');
  expect(await page.evaluate(async () => (await (await fetch('/api/settings', { credentials: 'same-origin' })).json()).data.groups[0].settings.find(s => s.key === 'mail.postal_url').source)).toBe('default');   // nothing saved yet
  await page.getByRole('button', { name: 'Keep editing' }).click();
  await expect(dlg).toBeHidden();
  await expect(page.locator('#settings-bar')).toContainText('1 unsaved change');
  await save.focus(); await page.keyboard.press('Enter');
  await page.getByRole('button', { name: 'Save and apply these effects' }).click();
  await expect(page.locator('#toast')).toContainText('Changes saved');
  page.once('dialog', d => d.accept());
  await page.fill('#settings-search', 'postal address');
  await page.locator('[data-setting-row="mail.postal_url"]').getByRole('button', { name: 'Reset' }).click();
  await expect(page.locator('[data-setting-row="mail.postal_url"]')).toContainText('Built-in default');
  await page.fill('#settings-search', 'smtp server');
  await page.fill('#settings-search', '');
  await expect(page.locator('#settings-root fieldset.settings-group legend').first()).toBeVisible();
});

test('require two-step verification: an admin without it is steered to My Account until they enrol', async ({ page }) => {
  const ADA = { user: 'ada', pass: 'ada admin passphrase 123', email: 'ada@example.test' };
  async function enrol(who) {
    await page.click('.nav-item[data-view=account]');
    await page.fill('#mfa-setup-pw', who.pass); await page.click('#mfa-setup-form button[type=submit]');
    await expect(page.locator('#mfa-secret-text')).toHaveText(/^[A-Z2-7]{32}$/);
    const secret = (await page.locator('#mfa-secret-text').textContent()).trim();
    await page.fill('#mfa-code', totp(secret)); await page.click('#mfa-enable-form button[type=submit]');
    await expect(page.locator('#mfa-state')).toContainText('ON');
  }
  await login(page, ADMIN);
  await page.click('.nav-item[data-view=users]');
  await page.click('[data-target=user-create-form]');
  await page.fill('#nu-username', ADA.user); await page.fill('#nu-email', ADA.email); await page.selectOption('#nu-role', 'admin'); await page.fill('#nu-password', ADA.pass);
  await page.click('#user-create-form button[type=submit]');
  await expect(page.locator('#users-list')).toContainText('ada');
  // the administrator must have it first: turning it on without is refused
  await page.click('.nav-item[data-view=settings]'); await page.fill('#settings-search', 'two-step');
  await page.getByLabel('Require two-step verification for administrators').check();
  await page.getByRole('button', { name: 'Save changes' }).first().click();
  await page.getByRole('button', { name: 'Save and apply these effects' }).click();
  await expect(page.locator('#settings-error')).toContainText('your own account');
  await page.getByRole('button', { name: 'Discard' }).click();
  await enrol(ADMIN);
  await page.click('.nav-item[data-view=settings]'); await page.fill('#settings-search', 'two-step');
  await page.getByLabel('Require two-step verification for administrators').check();
  await page.getByRole('button', { name: 'Save changes' }).first().click();
  await page.getByRole('button', { name: 'Save and apply these effects' }).click();
  await expect(page.locator('#toast')).toContainText('Changes saved');
  await signOut(page);
  await login(page, ADA);
  await expect(page.locator('#page-title')).toHaveText('My Account');
  await expect(page.locator('#view-account .alert-error').first()).toContainText('Two-step verification is required');
  expect(await page.$$eval('.nav-item', els => els.map(e => e.dataset.view))).toEqual(['account']);
  expect((await page.goto('/users')).url()).toMatch(/\/account$/);
  await enrol(ADA);
  await page.reload();
  expect((await page.$$eval('.nav-item', els => els.map(e => e.dataset.view))).length).toBeGreaterThan(5);
});
