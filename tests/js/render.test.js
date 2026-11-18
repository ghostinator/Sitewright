// XSS regression tests for public/assets/js/app.js (XSS-001, XSS-002, FUNC-018). Run: cd tests/js && npm install && npm test
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const SRC = fs.readFileSync(path.join(__dirname, '../../public/assets/js/app.js'), 'utf8');
const PAYLOADS = [
  '<img src=x onerror=alert(1)>', '"\';alert(1);//', '</script><script>alert(1)</script>', '<svg onload=alert(1)>',
  '<a href="javascript:alert(1)">x</a>', '"><iframe src=javascript:alert(1)>', "'+alert(1)+'", '<style>*{display:none}</style>', '${alert(1)}', '`${1+1}`',
];

function load() {
  const dom = new JSDOM('<!doctype html><body><div id="c"></div></body>', { runScripts: 'outside-only', url: 'http://localhost/' });
  dom.window.eval(SRC);
  return { dom, SW: dom.window.SW, box: dom.window.document.getElementById('c') };
}

function assertInert(box, payload, where) {
  const bad = box.querySelectorAll('img,script,svg,iframe,object,embed,style,link,form,input,base,meta');
  assert.equal(bad.length, 0, `${where}: injected element <${bad[0] && bad[0].tagName}> for ${payload}`);
  for (const n of box.querySelectorAll('*')) {
    for (const a of n.attributes) {
      assert.ok(!/^on/i.test(a.name), `${where}: event handler attribute ${a.name}`);
      assert.ok(!/^\s*javascript:/i.test(a.value), `${where}: javascript: URL in ${a.name}`);
    }
  }
}

const seoData = p => ({ url: p, score: 77, results: {
  fetch: { status_code: 200, redirect_count: 0, final_url: p },
  basic: { title: p, title_length: 5, title_optimal: true, word_count: 3, canonical: p, lang: p, noindex: false },
  meta: { description: p, description_length: 5, description_optimal: false },
  headings: { h1_count: 1, h1_optimal: true, headings: { h1: [p] } },
  images: { total_images: 1, images_with_alt: 1, alt_ratio: 100 },
  links: { internal_links: 1, external_links: 1, nofollow_links: 0 },
  performance: { load_time: 0.2, note: p }, viewport: { has_viewport: true }, security: { uses_https: true }, structured_data: { has_schema: false } } });

for (const p of PAYLOADS) {
  test(`renderers neutralise payload: ${p.slice(0, 30)}`, () => {
    const { SW, box } = load();
    const doc = box.ownerDocument;
    const cases = {
      seo: () => SW.renderSeoResults(box, seoData(p)),
      content: () => { let copied; SW.renderContentResult(box, { content: p, content_type: p, source: 'gemini', powered_by: p, ai_error: p }, t => { copied = t; }); assert.equal(copied, p, 'copy uses the raw JS string'); },
      contentFallback: () => SW.renderContentResult(box, { content: p, content_type: 'title', source: 'template_fallback', ai_error: p }),
      image: () => SW.renderImageResults(box, { images: [{ src: p, has_alt: false, size: 10, dimensions: p, optimized: null }], found: 1, skipped: 1, truncated: true, limit: p }),
      analytics: () => SW.renderAnalytics(box, { keywords: [{ keyword: p, total_impressions: 1, total_clicks: 1, avg_position: '2.5', is_sample: 1 }], summary: {}, range: { start: p, end: p } }),
      logs: () => SW.render404Logs(box, { logs: [{ id: 1, uri: p, hits: p, last_accessed: p, is_sample: 0 }] }),
      redirects: () => SW.renderRedirections(box, [{ id: p, source_url: p, target_url: p, redirect_type: p, is_sample: 1 }]),
      locations: () => SW.renderLocations(box, [{ id: 1, name: p, address: p, city: p, state: p, postal_code: p, country: p, latitude: p, longitude: p }]),
      users: () => SW.renderUsers(box, [{ id: 1, username: p, email: p, role: 'viewer', disabled: true, mfa: true, created_at: p }, { id: 2, username: 'me', email: 'me@example.test', role: 'admin', disabled: false, mfa: false, created_at: 'x' }], 2),
      sites: () => SW.renderSites(box, [{ id: 1, name: p, base_url: p, last_status: p, last_sync_at: p, plugin_version: p, last_error: p }]),
      gsc: () => SW.renderGsc(box, { configured: true, redirect_uri: p, connections: [{ id: 1, property_url: p, last_status: p, last_sync_at: p, rows_synced: 5, last_error: p }] }, { 1: [{ site_url: p, permission: p }] }),
      audit: () => SW.renderAudit(box, [{ id: 1, created_at: p, username: p, action: p, ip: p, detail: p }]),
      sitemap: () => SW.renderSitemapResult(box, { fetched: p, total_found: p, added: p, stopped_early: true, max_pages: p }, '/api/sitemap/generate-xml'),
    };
    for (const [name, run] of Object.entries(cases)) {
      box.replaceChildren();
      run();
      assertInert(box, p, name);
      assert.ok(box.textContent.includes(p) || name === 'sitemap' || name === 'content', `${name}: payload should be visible as plain text`);
    }
    // dashboard writes into fixed ids
    doc.body.insertAdjacentHTML('beforeend', '<p id="recent-activity"></p><p id="stat-seo"></p><p id="stat-keywords"></p><p id="stat-impressions"></p><p id="stat-clicks"></p><p id="dashboard-note"></p>');
    SW.renderDashboard({ seo_score: 1, total_keywords: 1, impressions: 1, clicks: 1, includes_sample_data: true, recent_activity: [{ type: p, url: p, score: p, date: p, is_sample: 1 }] });
    assertInert(doc.getElementById('recent-activity'), p, 'dashboard');
    assert.ok(doc.getElementById('recent-activity').textContent.includes(p));
  });
}

test('delete buttons carry ids only in data-* attributes (delegated listeners, no inline handlers)', () => {
  const { SW, box } = load();
  SW.renderRedirections(box, [{ id: 7, source_url: '/a', target_url: '/b', redirect_type: '301' }]);
  const b = box.querySelector('button');
  assert.equal(b.dataset.action, 'delete-redirection'); assert.equal(b.dataset.id, '7'); assert.equal(b.getAttribute('onclick'), null);
});

test('no HTML-string sinks exist anywhere in app.js', () => {
  for (const sink of [/\.innerHTML\b/, /\.outerHTML\b/, /insertAdjacentHTML/, /document\.write/, /\beval\s*\(/, /new Function\b/, /\bsetTimeout\s*\(\s*['"`]/, /\bon(click|error|load)\s*=/i, /createContextualFragment/, /srcdoc/]) {
    assert.ok(!sink.test(SRC), `forbidden sink ${sink}`);
  }
});

// ---- two-step login (MFA) -------------------------------------------------------------------------------------------------
function loginPage(responses) {
  const calls = [];
  const html = '<!doctype html><body><script type="application/json" id="app-config">{"basePath":"","csrfToken":"tok"}</script>'
    + '<form id="login-form"><div id="login-error" class="hidden"></div>'
    + '<div class="form-group"><input name="username" value="admin"></div><div class="form-group"><input name="password" type="password" value="pw"></div>'
    + '<div id="login-mfa" class="form-group hidden"><input id="login-code" name="code"></div><button type="submit">Sign in</button></form></body>';
  const { VirtualConsole } = require('jsdom');
  const vc = new VirtualConsole();   // jsdom does not implement location.reload(); the error is expected
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/', virtualConsole: vc });
  dom.window.fetch = async (url, opts) => {
    calls.push({ url, body: JSON.parse(opts.body), csrf: opts.headers['X-CSRF-Token'] });
    const r = responses.shift();
    return { ok: r.status < 400, status: r.status, json: async () => r.json };
  };
  dom.window.eval(SRC);
  return { dom, calls, doc: dom.window.document };
}
const submit = async (dom, doc) => { doc.getElementById('login-form').dispatchEvent(new dom.window.Event('submit', { cancelable: true })); await new Promise(r => setTimeout(r, 20)); };

test('login: server asks for a code => credentials hidden, code field shown, password cleared; code goes to /api/auth/mfa', async () => {
  const { dom, calls, doc } = loginPage([
    { status: 200, json: { success: true, message: 'Verification code required', data: { mfa_required: true } } },
    { status: 401, json: { success: false, message: 'Invalid verification code' } },
  ]);
  await submit(dom, doc);
  assert.equal(calls[0].url, '/api/auth/login'); assert.deepEqual(calls[0].body, { username: 'admin', password: 'pw', remember: false }, 'no checkbox on the page means no request to be remembered');
  assert.ok(doc.getElementById('login-mfa').className.indexOf('hidden') === -1, 'code field visible');
  assert.ok(doc.querySelector('[name=username]').closest('.form-group').classList.contains('hidden'));
  assert.ok(doc.querySelector('[name=password]').closest('.form-group').classList.contains('hidden'));
  assert.equal(doc.querySelector('[name=password]').value, '', 'password not kept in the DOM');
  doc.querySelector('[name=code]').value = '123456';
  await submit(dom, doc);
  assert.equal(calls[1].url, '/api/auth/mfa'); assert.deepEqual(calls[1].body, { code: '123456' }); assert.equal(calls[1].csrf, 'tok');
  assert.equal(doc.getElementById('login-error').textContent, 'Invalid verification code');
  assert.ok(!doc.getElementById('login-error').classList.contains('hidden'));
});

test('login: server error text is shown as text, never parsed as HTML', async () => {
  const { dom, calls, doc } = loginPage([{ status: 401, json: { success: false, message: '<img src=x onerror=alert(1)>' } }]);
  await submit(dom, doc);
  assert.equal(doc.querySelectorAll('#login-error img').length, 0);
  assert.equal(doc.getElementById('login-error').textContent, '<img src=x onerror=alert(1)>');
  assert.equal(calls.length, 1);
});

// ---- background job polling -----------------------------------------------------------------------------------------------
test('crawl form: submits a job, polls its status, then renders the result (and a failed job shows its error as text)', async () => {
  for (const [final, expect] of [[{ status: 200, json: { success: true, data: { status: 'done', result: { data: { total_found: 3, added: 3, fetched: 3, stopped_early: false, max_pages: 100 } } } } }, '3'],
                                  [{ status: 200, json: { success: true, data: { status: 'failed', error: '<img src=x onerror=alert(1)>', error_status: 502 } } }, '<img src=x onerror=alert(1)>']]) {
    const calls = []; const polls = [{ status: 200, json: { success: true, data: { status: 'running' } } }, final];
    const html = '<!doctype html><body><script type="application/json" id="app-config">{"basePath":"","csrfToken":"tok"}</script>'
      + '<aside></aside><main><h1 id="page-title"></h1><section class="view" id="view-sitemaps"></section><form id="sitemap-crawl-form"><input id="sitemap-url" value="https://example.com"><button type="submit">Go</button></form><div id="sitemap-results"></div><div id="sitemap-list"></div></main></body>';
    const { VirtualConsole } = require('jsdom');
    const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/', virtualConsole: new VirtualConsole(), pretendToBeVisual: true });
    dom.window.fetch = async (url, opts) => {
      const key = opts.method + ' ' + url; if (/sitemap\/crawl|jobs\//.test(url)) calls.push(key);
      const r = key === 'POST /api/sitemap/crawl' ? { status: 202, json: { success: true, data: { job_id: 7 } } }
        : /jobs\/7/.test(url) ? (polls.length > 1 ? polls.shift() : polls[0]) : { status: 200, json: { success: true, data: [] } };
      return { ok: r.status < 400, status: r.status, json: async () => r.json };
    };
    dom.window.eval(SRC);
    dom.window.document.getElementById('sitemap-crawl-form').dispatchEvent(new dom.window.Event('submit', { cancelable: true, bubbles: true }));
    await new Promise(r => setTimeout(r, 3500));
    assert.equal(calls[0], 'POST /api/sitemap/crawl'); assert.equal(calls[1], 'GET /api/jobs/7');
    const box = dom.window.document.getElementById('sitemap-results');
    assert.ok(box.textContent.includes(expect), 'result/error shown: ' + box.textContent.slice(0, 80));
    assert.equal(box.querySelectorAll('img').length, 0);
  }
});

test('users list: own row has no delete/disable and a locked role selector; others get actions with ids only in data-*', () => {
  const { SW, box } = load();
  SW.renderUsers(box, [{ id: 1, username: 'a', email: 'a@x', role: 'editor', disabled: false, mfa: true, created_at: 'd' }, { id: 2, username: 'me', email: 'm@x', role: 'admin', disabled: false, mfa: true, created_at: 'd' }], 2);
  const rows = box.querySelectorAll('.list-item');
  const acts = r => [...r.querySelectorAll('button')].map(b => b.dataset.action);
  assert.deepEqual(acts(rows[0]), ['user-toggle', 'user-reset-mfa', 'user-send-reset', 'delete-user']);
  assert.deepEqual(acts(rows[1]), []);
  assert.equal(rows[1].querySelector('select').disabled, true); assert.equal(rows[0].querySelector('select').value, 'editor');
  for (const b of box.querySelectorAll('button')) assert.equal(b.getAttribute('onclick'), null);
});

// ---- location form: opening hours --------------------------------------------------------------------------------------------
function locationForm() {
  const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
  const fields = ['loc_id', 'name', 'phone', 'address', 'city', 'state', 'postal_code', 'country', 'email', 'website', 'latitude', 'longitude']
    .map(n => `<input name="${n}">`).join('') + days.map(d => `<input type="time" name="open_${d}"><input type="time" name="close_${d}"><input type="checkbox" name="closed_${d}">`).join('');
  const dom = new JSDOM(`<!doctype html><body><h4 id="loc-form-title"></h4><button id="loc-cancel" class="hidden"></button><form id="location-form" class="hidden">${fields}</form></body>`, { runScripts: 'outside-only', url: 'http://localhost/' });
  dom.window.eval(SRC);
  return { SW: dom.window.SW, form: dom.window.document.getElementById('location-form'), doc: dom.window.document };
}
test('hours: only days with both times and not marked closed are sent; none => null', () => {
  const { SW, form } = locationForm();
  assert.equal(SW.hoursFromForm(form), null);
  form.elements.open_monday.value = '09:00'; form.elements.close_monday.value = '17:30';
  form.elements.open_tuesday.value = '09:00';                                   // half-filled: ignored
  form.elements.open_wednesday.value = '08:00'; form.elements.close_wednesday.value = '12:00'; form.elements.closed_wednesday.checked = true;
  assert.equal(JSON.stringify(SW.hoursFromForm(form)), JSON.stringify({ monday: { open: '09:00', close: '17:30' } }));
});
test('editing a location fills the form from data without parsing HTML, shows hours and "closed" days', () => {
  const { SW, form, doc } = locationForm();
  const p = '<img src=x onerror=alert(1)>';
  SW.editLocation({ id: 7, name: p, phone: null, address: p, city: 'X', state: null, postal_code: '1', country: 'US', email: null, website: null, latitude: '1.5', longitude: '2.5', business_hours: { friday: { open: '10:00', close: '18:00' } } });
  assert.equal(form.elements.loc_id.value, '7'); assert.equal(form.elements.name.value, p); assert.equal(form.elements.phone.value, '');
  assert.equal(form.elements.open_friday.value, '10:00'); assert.equal(form.elements.closed_friday.checked, false); assert.equal(form.elements.closed_monday.checked, true);
  assert.equal(doc.getElementById('loc-form-title').textContent, 'Edit ' + p); assert.equal(doc.querySelectorAll('img').length, 0);
  assert.ok(!form.classList.contains('hidden'));
});

// ---- settings page ---------------------------------------------------------------------------------------------------------
function settingsData(p) {
  const row = (o) => Object.assign({ key: 'limits.crawl_seconds', label: 'Longest crawl', help: 'A crawl stops after this long.', unit: 'seconds', type: 'int', enum: null, min: 10, max: 900, value: 120, default: 120, source: 'default', locked: false, env: null, fallback: 'built-in default 120', secret: false, danger: false, danger_effects: [], apply: 'live', changed_by: null, changed_at: null }, o);
  return { encryption_ready: true,
    readonly: [{ key: 'db.pass', value: '(hidden)', source: 'file' }, { key: 'base_url', value: 'https://x.example', source: 'file' }],
    groups: [
      { id: 'mail', label: 'Mail & alerts', description: 'How mail works.', changed_count: 1, settings: [
        row({ key: 'mail.postal_url', label: p || 'Postal address', help: p || 'Where mail goes', type: 'url', unit: '', value: 'https://app.example.test', default: '', source: 'saved', danger: true, danger_effects: ['Clears the stored Postal API key; enter it again.'], changed_by: 'root', changed_at: '2026-11-15 10:00:00', fallback: 'built-in default ""' }),
        row({ key: 'mail.postal_key', label: 'Postal API key', type: 'secret', unit: '', value: { set: true }, default: null, secret: true, source: 'saved', fallback: 'built-in default (none)' }),
        row({ key: 'mail.from', label: 'From address', type: 'email', unit: '', value: 'a@example.test', default: '', source: 'env', locked: true, env: 'SW_MAIL_FROM' }) ] },
      { id: 'crawl', label: 'Crawling & jobs', description: '', changed_count: 0, settings: [ row({}), row({ key: 'enable_geocoding', label: 'Look up coordinates', type: 'bool', unit: '', value: false, default: false, min: null, max: null }) ] },
    ] };
}
function settingsPage(data) {
  const { SW, dom, box, doc } = (() => { const l = load(); return { SW: l.SW, dom: l.dom, box: l.box, doc: l.dom.window.document }; })();
  const st = SW.newSettingsState(); SW.renderSettings(box, data, st);
  return { SW, st, box, doc, data, redraw: () => SW.renderSettings(box, data, st) };
}
test('settings: hostile text in label/help/value/unit renders as text only', () => {
  const p = '<img src=x onerror=alert(1)>';
  const d = settingsData(p); d.groups[0].settings[0].unit = p; d.groups[0].settings[0].fallback = p; d.groups[0].settings[0].changed_by = p;
  const { box } = settingsPage(d);
  assert.equal(box.querySelectorAll('img,script,svg,iframe,style').length, 0);
  assert.ok(box.textContent.includes(p));
});
test('settings: secret rows show set/not set and never an input holding the value; Replace reveals an EMPTY password field', () => {
  const { box, redraw, st, SW } = settingsPage(settingsData());
  const row = box.querySelector('[data-setting-row="mail.postal_key"]');
  assert.ok(/set/i.test(row.textContent)); assert.equal(row.querySelector('input'), null);
  st.replacing['mail.postal_key'] = true; redraw();
  const inp = box.querySelector('[data-setting-row="mail.postal_key"] input'); assert.equal(inp.type, 'password'); assert.equal(inp.value, ''); assert.equal(inp.getAttribute('autocomplete'), 'new-password');
});
test('settings: locked rows are disabled and name their environment variable; saved rows offer Reset and say what it falls back to', () => {
  const { box } = settingsPage(settingsData());
  const locked = box.querySelector('[data-setting-row="mail.from"]');
  assert.ok(locked.querySelector('input').disabled); assert.ok(locked.textContent.includes('SW_MAIL_FROM'));
  const saved = box.querySelector('[data-setting-row="mail.postal_url"]');
  assert.ok(saved.querySelector('[data-action="settings-reset"]')); assert.ok(saved.textContent.includes('built-in default'));
  assert.equal(locked.querySelector('[data-action="settings-reset"]'), null);
});
test('settings: every group is a fieldset with a legend and every control has a label', () => {
  const { box } = settingsPage(settingsData());
  const fs = box.querySelectorAll('fieldset.settings-group'); assert.ok(fs.length >= 1); for (const f of fs) assert.ok(f.querySelector('legend'));
  for (const c of box.querySelectorAll('input:not([type=hidden]), select, textarea')) { assert.ok(c.getAttribute('aria-label') || (c.id && box.querySelector(`label[for="${c.id}"]`)), 'unlabelled control ' + (c.name || c.id)); }
});
test('settings: diffSettings lists only real changes, masks secrets, attaches danger effects', () => {
  const { SW, data } = settingsPage(settingsData());
  const d = SW.diffSettings(data, { 'limits.crawl_seconds': 120, 'mail.postal_url': 'https://other.example.test', 'mail.postal_key': 'NEW-SECRET-VALUE', 'enable_geocoding': true });
  const keys = d.changes.map(c => c.key).sort(); assert.equal(JSON.stringify(keys), JSON.stringify(['enable_geocoding', 'mail.postal_key', 'mail.postal_url']));
  const sec = d.changes.find(c => c.key === 'mail.postal_key'); assert.equal(sec.to, '(new value)'); assert.ok(!JSON.stringify(d).includes('NEW-SECRET-VALUE'));
  assert.equal(d.changes.find(c => c.key === 'mail.postal_url').effects.length, 1); assert.equal(d.changes.find(c => c.key === 'mail.postal_url').from, 'https://app.example.test');
});
test('settings: search matches label, key and env name; "only changed" hides defaults; group counts shown', () => {
  const { box, st, redraw } = settingsPage(settingsData());
  st.q = 'SW_MAIL_FROM'; redraw(); assert.ok(box.querySelector('[data-setting-row="mail.from"]')); assert.equal(box.querySelector('[data-setting-row="mail.postal_url"]'), null);
  st.q = 'crawl'; redraw(); assert.ok(box.querySelector('[data-setting-row="limits.crawl_seconds"]'));
  st.q = 'zzzz-nothing'; redraw(); assert.ok(/No setting matches/.test(box.textContent));
  st.q = ''; st.only = true; st.group = 'crawl'; redraw(); assert.ok(/Nothing here differs|No setting/.test(box.textContent));
  st.only = false; redraw(); assert.ok(box.querySelector('.settings-nav').textContent.includes('Mail & alerts'));
});
test('settings: the save bar names unsaved settings and clears after discard', () => {
  const { SW, st, data, doc } = settingsPage(settingsData());
  const bar = doc.createElement('div'); doc.body.append(bar);
  st.edits['limits.crawl_seconds'] = 60; SW.renderSettingsBar(bar, data, st);
  assert.ok(bar.textContent.includes('Longest crawl')); assert.ok(!bar.classList.contains('hidden'));
  st.edits = {}; SW.renderSettingsBar(bar, data, st); assert.ok(bar.classList.contains('hidden'));
});

test('dashboard: window text, card titles, order and hidden cards follow the stats response', () => {
  const dom = new JSDOM('<!doctype html><body><div id="view-dashboard"><div class="stats-grid">'
    + ['seo', 'keywords', 'impressions', 'clicks'].map(c => `<div class="stat-card" data-card="${c}"><h3>${c}</h3><p id="stat-${c === 'seo' ? 'seo' : c}"></p></div>`).join('')
    + '</div><p id="dashboard-note"></p></div></body>', { runScripts: 'outside-only', url: 'http://localhost/' });
  dom.window.eval(SRC);
  dom.window.SW.renderDashboard({ seo_score: 80, total_keywords: 1, impressions: 2, clicks: 3, includes_sample_data: false, recent_activity: [], window_days: 7, cards: ['clicks', 'seo'] });
  const d = dom.window.document;
  assert.equal(d.getElementById('dashboard-note').textContent, 'Showing the last 7 days.');
  assert.equal(d.querySelector('[data-card=clicks] h3').textContent, 'Clicks (7d)');
  const order = [...d.querySelectorAll('.stat-card')].filter(c => !c.classList.contains('hidden')).map(c => c.dataset.card);
  assert.equal(JSON.stringify(order), JSON.stringify(['clicks', 'seo']));
  assert.ok(d.querySelector('[data-card=keywords]').classList.contains('hidden'));
});

test('settings: a non-secret value from the config file offers "Save this value here"; only secrets say "encrypted storage"', () => {
  const d = settingsData(); d.groups[0].settings.push({ key: 'contact', label: 'Contact', help: 'h', unit: '', type: 'string', enum: null, min: null, max: null, value: 'x', default: '', source: 'file', locked: false, env: null, fallback: 'config file value "x"', secret: false, danger: false, danger_effects: [], apply: 'live', changed_by: null, changed_at: null });
  d.groups[0].settings.push({ key: 'gemini.api_key', label: 'Gemini', help: 'h', unit: '', type: 'secret', enum: null, min: null, max: null, value: { set: true }, default: null, source: 'file', locked: false, env: null, fallback: 'config file value (set)', secret: true, danger: false, danger_effects: [], apply: 'live', changed_by: null, changed_at: null });
  const { box } = settingsPage(d);
  assert.ok(box.querySelector('[data-setting-row="contact"] [data-action="settings-promote"]').textContent.includes('Save this value here'));
  assert.ok(box.querySelector('[data-setting-row="gemini.api_key"] [data-action="settings-promote"]').textContent.includes('encrypted storage'));
});

test('settings: SMTP fields are hidden while Postal is chosen (and the other way round); searching still finds them', () => {
  const d = settingsData();
  const drv = { key: 'mail.driver', label: 'Mail method', help: '', unit: '', type: 'enum', enum: ['smtp', 'postal'], min: null, max: null, value: 'postal', default: 'smtp', source: 'saved', locked: false, secret: false, danger: false, danger_effects: [], show_when: null, env: 'SW_MAIL_DRIVER' };
  const host = Object.assign({}, d.groups[0].settings[0], { key: 'mail.host', label: 'SMTP server', value: 'smtp.example.test', type: 'string', show_when: ['mail.driver', 'smtp'] });
  d.groups[0].settings[0].show_when = ['mail.driver', 'postal']; d.groups[0].settings.unshift(drv, host);
  const { box, st, redraw } = settingsPage(d);
  assert.ok(box.querySelector('[data-setting-row="mail.postal_url"]')); assert.equal(box.querySelector('[data-setting-row="mail.host"]'), null);
  st.edits['mail.driver'] = 'smtp'; redraw();
  assert.ok(box.querySelector('[data-setting-row="mail.host"]')); assert.equal(box.querySelector('[data-setting-row="mail.postal_url"]'), null);
  st.edits = {}; st.q = 'SMTP server'; redraw(); assert.ok(box.querySelector('[data-setting-row="mail.host"]'));
});

test('result checks are labelled in words (Pass / Fix), not emoji, and the label is text a screen reader can read', () => {
  const { SW, box } = load();
  SW.renderSeoResults(box, seoData('x'));
  assert.ok(!/[✅⚠❌]/u.test(box.textContent), 'no emoji status marks');
  const tags = [...box.querySelectorAll('.check')].map(n => n.textContent + ':' + n.className);
  assert.ok(tags.some(t => t.startsWith('Pass:') && t.includes('check-ok')), 'a passing check says Pass');
  assert.ok(tags.some(t => t.startsWith('Fix:') && t.includes('check-warn')), 'a failing check says Fix (description_optimal is false in the fixture)');
  assert.ok(/HTTPS \(final URL\)Yes/.test(box.textContent) && /Structured data \(JSON-LD\)No/.test(box.textContent), 'Yes/No answers are plain words');
  box.replaceChildren();
  SW.renderImageResults(box, { images: [{ src: 'a', has_alt: false, size: 10, dimensions: '1x1', optimized: null }, { src: 'b', has_alt: true, size: 10, dimensions: '1x1', optimized: true }], found: 2, skipped: 0, truncated: false, limit: 5 });
  assert.ok(!/[✅⚠❌]/u.test(box.textContent));
  assert.ok(box.querySelector('.check-warn') && box.querySelector('.check-ok'));
});

test('overview: one row per site with score, search numbers and 404s; "no data" is a dash, not 0; the site name picks that site', () => {
  const { SW, box } = load();
  SW.renderOverview(box, { sites: [
    { id: 4, name: 'Blog', base_url: 'https://blog.example', plugin: true, last_score: 70, last_analyzed_at: '2026-11-17 01:00:00', impressions: 1500, clicks: 20, not_found: 3, watched: 4, down: 1 },
    { id: 5, name: 'Shop', base_url: 'https://shop.example', plugin: false, last_score: null, last_analyzed_at: null, impressions: null, clicks: null, not_found: 0 }] });
  const rows = [...box.querySelectorAll('tbody tr')];
  assert.equal(rows.length, 2);
  assert.match(rows[0].textContent, /Blog/); assert.match(rows[0].textContent, /70/); assert.match(rows[0].textContent, /1,500/);
  assert.match(rows[1].textContent, /Shop/); assert.doesNotMatch(rows[1].textContent, /\b0\b.*\b0\b.*\b0\b/);
  assert.equal(rows[1].querySelectorAll('td')[1].textContent, '–', 'no score yet is a dash');
  assert.match(rows[0].textContent, /4.*1 down/); assert.equal(rows[1].querySelectorAll('td')[2].textContent, '–', 'nothing watched is a dash');
  const pick = rows[0].querySelector('[data-pick-site]'); assert.equal(pick.dataset.pickSite, '4');
  box.replaceChildren(); SW.renderOverview(box, { sites: [] });
  assert.match(box.textContent, /No sites yet/);
});
test('overview text is inert even for hostile site names', () => {
  const { SW, box } = load();
  for (const p of PAYLOADS) {
    box.replaceChildren(); SW.renderOverview(box, { sites: [{ id: 1, name: p, base_url: p, plugin: true, last_score: 1, last_analyzed_at: p, impressions: 1, clicks: 1, not_found: 1 }] });
    assertInert(box, p, 'overview'); assert.ok(box.textContent.includes(p));
  }
});
test('sites list: a site without the plugin says so and offers no Sync or New secret', () => {
  const { SW, box } = load();
  SW.renderSites(box, [{ id: 1, name: 'Blog', base_url: 'https://b.example', last_status: 'ok', last_sync_at: null, plugin_version: '1.0', last_error: null, sync_enabled: 1 },
    { id: 2, name: 'Shop', base_url: 'https://s.example', last_status: 'never', last_sync_at: null, plugin_version: null, last_error: null, sync_enabled: 0 }]);
  const items = [...box.querySelectorAll('.list-item')];
  assert.ok(items[0].querySelector('[data-action="site-sync"]') && items[0].querySelector('[data-action="site-rotate"]'));
  assert.ok(!items[1].querySelector('[data-action="site-sync"]') && !items[1].querySelector('[data-action="site-rotate"]'));
  assert.ok(items[1].querySelector('[data-action="delete-site"]'), 'it can still be removed');
  assert.match(items[1].textContent, /no plugin/i);
});
test('Search Console panel: each connection can be tied to a site', () => {
  const { SW, box } = load();
  SW.renderGsc(box, { configured: true, redirect_uri: 'x', connections: [{ id: 9, property_url: 'sc-domain:shop.example', site_id: 5, last_status: 'ok', last_sync_at: null, rows_synced: 0, last_error: null }] }, {}, [{ id: 4, name: 'Blog' }, { id: 5, name: 'Shop' }]);
  const sel = box.querySelector('select[data-change="gsc-site"]');
  assert.ok(sel && sel.dataset.id === '9'); assert.equal(sel.value, '5');
  assert.deepEqual([...sel.options].map(o => o.textContent), ['Not tied to a site', 'Blog', 'Shop']);
});

const watchItem = (o = {}) => Object.assign({ id: 3, site: 'Corner', url: 'https://w.example/', label: 'Home', interval_hours: 24, enabled: true, last_run_at: '2026-11-17 02:00:00',
  next_run_at: '2026-11-18 02:00:00', last_score: 72, last_status: 'ok', last_error: null, fail_count: 0, trend: [60, 65, 72] }, o);
test('watchlist: status, score with a trend line, and actions only for people who can edit', () => {
  const { SW, box } = load();
  SW.renderWatchlist(box, { items: [watchItem(), watchItem({ id: 4, url: 'https://d.example/', label: '', last_status: 'down', last_error: 'The site answered with HTTP 503', last_score: 80, fail_count: 3 }),
    watchItem({ id: 5, url: 'https://n.example/', last_status: 'new', last_score: null, trend: [], last_run_at: null }), watchItem({ id: 6, url: 'https://p.example/', enabled: false })] }, true);
  const rows = [...box.querySelectorAll('tbody tr')];
  assert.equal(rows.length, 4);
  assert.match(rows[0].textContent, /OK/); assert.match(rows[0].textContent, /72/); assert.match(rows[0].textContent, /Home/);
  assert.match(rows[1].textContent, /Down/); assert.match(rows[1].textContent, /HTTP 503/);
  assert.match(rows[2].textContent, /waiting for the first check/i); assert.doesNotMatch(rows[2].textContent, /\b0\b/);
  assert.match(rows[3].textContent, /Paused/);
  const spark = rows[0].querySelector('svg.spark'); assert.ok(spark); assert.equal(spark.getAttribute('role'), 'img'); assert.match(spark.getAttribute('aria-label'), /60, 65, 72/);
  assert.equal(spark.querySelector('polyline').getAttribute('points').trim().split(/\s+/).length, 3);
  assert.equal(rows[2].querySelector('svg'), null, 'no trend yet, no chart');
  for (const a of ['watch-run', 'watch-toggle', 'delete-watch', 'watch-history']) assert.ok(rows[0].querySelector('[data-action="' + a + '"]'), a);
  assert.equal(rows[0].querySelector('[data-action="watch-toggle"]').textContent, 'Pause'); assert.equal(rows[3].querySelector('[data-action="watch-toggle"]').textContent, 'Resume');
  box.replaceChildren(); SW.renderWatchlist(box, { items: [watchItem()] }, false);
  assert.ok(box.querySelector('[data-action="watch-history"]')); for (const a of ['watch-run', 'watch-toggle', 'delete-watch']) assert.equal(box.querySelector('[data-action="' + a + '"]'), null, a);
  box.replaceChildren(); SW.renderWatchlist(box, { items: [] }, true); assert.match(box.textContent, /Nothing is watched yet/);
});
test('watchlist: hostile text is shown as text only', () => {
  const { SW, box } = load();
  for (const p of PAYLOADS) {
    box.replaceChildren(); SW.renderWatchlist(box, { items: [watchItem({ url: p, label: p, site: p, last_error: p, last_status: 'down' })] }, true);
    const bad = box.querySelectorAll('img,script,iframe,object,embed,style,link,form,input,base,meta');
    assert.equal(bad.length, 0, 'injected element for ' + p);
    for (const n of box.querySelectorAll('*')) for (const a of n.attributes) assert.ok(!/^on/i.test(a.name) && !/^\s*javascript:/i.test(a.value), 'attribute ' + a.name);
    assert.ok(box.textContent.includes(p));
  }
});
test('watch history: a table of dates, scores and fetch times; empty says so', () => {
  const { SW, box } = load();
  SW.renderWatchHistory(box, { points: [{ at: '2026-11-16 02:00:00', score: 60, load_time: 0.4, word_count: 700, noindex: false, title_length: 45 }, { at: '2026-11-17 02:00:00', score: 72, load_time: 0.3, word_count: 710, noindex: true, title_length: 45 }] });
  const rows = [...box.querySelectorAll('tbody tr')]; assert.equal(rows.length, 2);
  assert.match(rows[0].textContent, /2026-11-17/, 'newest first'); assert.match(rows[0].textContent, /noindex/i);
  box.replaceChildren(); SW.renderWatchHistory(box, { points: [] }); assert.match(box.textContent, /No checks yet/);
});
test('analysis result offers "Watch this page" to people who can edit, with the analysed address in a data attribute', () => {
  const { SW, box } = load();
  SW.renderSeoResults(box, seoData('x'), { canWatch: true });
  const b = box.querySelector('[data-action="watch-add"]'); assert.ok(b); assert.equal(b.dataset.url, 'x');
  box.replaceChildren(); SW.renderSeoResults(box, seoData('x'));
  assert.equal(box.querySelector('[data-action="watch-add"]'), null);
});

const auditRow = (o = {}) => Object.assign({ id: 8, project_id: 2, site: 'Corner', base_url: 'https://c.example', status: 'done', pages: 41, error: null, created_at: '2026-11-17 02:00:00',
  finished_at: '2026-11-17 02:03:00', by_severity: { high: 2, medium: 5, low: 9 }, new: 3, fixed: 1, avg_score: 74 }, o);
test('audit list: counts by severity, what changed since last time, state of a running or failed audit, and actions by role', () => {
  const { SW, box } = load();
  SW.renderSiteAudits(box, { audits: [auditRow(), auditRow({ id: 9, status: 'running', by_severity: null, new: null, fixed: null, pages: 0 }), auditRow({ id: 10, status: 'failed', error: 'No page of the site could be read.', by_severity: null }),
    auditRow({ id: 11, by_severity: { high: 0, medium: 0, low: 0 }, new: null, fixed: null })] }, true);
  const rows = [...box.querySelectorAll('tbody tr')]; assert.equal(rows.length, 4);
  assert.match(rows[0].textContent, /Corner/); assert.match(rows[0].textContent, /41/); assert.match(rows[0].textContent, /2 high/); assert.match(rows[0].textContent, /3 new.*1 fixed/);
  assert.match(rows[1].textContent, /Running/i); assert.match(rows[2].textContent, /Failed/); assert.match(rows[2].textContent, /No page of the site could be read/);
  assert.match(rows[3].textContent, /No issues/); assert.doesNotMatch(rows[3].textContent, /new/);
  for (const a of ['audit-view', 'delete-audit']) assert.ok(rows[0].querySelector('[data-action="' + a + '"]'), a);
  assert.equal(rows[1].querySelector('[data-action="audit-view"]'), null, 'nothing to view yet while it runs');
  box.replaceChildren(); SW.renderSiteAudits(box, { audits: [auditRow()] }, false); assert.ok(box.querySelector('[data-action="audit-view"]')); assert.equal(box.querySelector('[data-action="delete-audit"]'), null);
  box.replaceChildren(); SW.renderSiteAudits(box, { audits: [] }, true); assert.match(box.textContent, /No audits yet/);
});
test('audit report: summary, issue types worst first with advice and a button to list them, note when it stopped early', () => {
  const { SW, box } = load();
  const data = { audit: { id: 8, base_url: 'https://c.example', status: 'done', pages: 41, stopped_early: 1, summary: { pages: 41, avg_score: 74, new: 3, fixed: 1, by_severity: { high: 2, medium: 5, low: 9 } } },
    types: [{ code: 'broken_link', severity: 'high', title: 'Broken internal link', advice: 'Fix or remove the link.', count: 2 }, { code: 'thin_content', severity: 'medium', title: 'Very little content', advice: 'Add content.', count: 5 }] };
  SW.renderSiteAuditReport(box, data);
  assert.match(box.textContent, /41 pages/); assert.match(box.textContent, /74/); assert.match(box.textContent, /stopped early/i); assert.match(box.textContent, /3 new/);
  const rows = [...box.querySelectorAll('tbody tr')]; assert.equal(rows.length, 2); assert.match(rows[0].textContent, /High/); assert.match(rows[0].textContent, /Broken internal link/); assert.match(rows[0].textContent, /Fix or remove the link/);
  const b = rows[0].querySelector('[data-action="audit-issues"]'); assert.ok(b); assert.equal(b.dataset.code, 'broken_link'); assert.equal(b.dataset.id, '8');
  box.replaceChildren(); SW.renderSiteAuditReport(box, { audit: { id: 1, status: 'done', pages: 3, stopped_early: 0, summary: { pages: 3, avg_score: 90, new: null, fixed: null, by_severity: { high: 0, medium: 0, low: 0 } } }, types: [] });
  assert.match(box.textContent, /No issues found/); assert.doesNotMatch(box.textContent, /stopped early/i);
});
test('audit issues and worst pages render as tables; hostile text stays text', () => {
  const { SW, box } = load();
  for (const p of PAYLOADS) {
    box.replaceChildren(); SW.renderSiteAuditIssues(box, { issues: [{ code: 'broken_link', severity: 'high', url: p, detail: p }] });
    assert.equal(box.querySelectorAll('img,script,iframe,object,embed,style,link,form,input,base,meta,svg').length, 0, p); assert.ok(box.textContent.includes(p));
    box.replaceChildren(); SW.renderSiteAuditPages(box, { pages: [{ url: p, title: p, title_len: 1, desc_len: 1, h1_count: 1, word_count: 1000, noindex: 1, score: 55, load_ms: 300, inbound: 0 }] });
    assert.equal(box.querySelectorAll('img,script,iframe,object,embed,style,link,form,input,base,meta,svg').length, 0, p); assert.ok(box.textContent.includes(p));
  }
  box.replaceChildren(); SW.renderSiteAuditPages(box, { pages: [{ url: 'https://c.example/', title: 'T', title_len: 1, desc_len: 1, h1_count: 1, word_count: 1000, noindex: 1, score: 55, load_ms: 300, inbound: 4 }] });
  assert.match(box.textContent, /55/); assert.match(box.textContent, /1,000/); assert.match(box.textContent, /noindex/i);
  box.replaceChildren(); SW.renderSiteAuditIssues(box, { issues: [] }); assert.match(box.textContent, /Nothing to list/);
});

test('redirect suggestions: one button per suggested page with its match strength, the data an Accept needs, and clear messages when there is nothing to show', () => {
  const { SW, box } = load();
  const data = { has_inventory: true, source: 'audit', unmatched: 4, items: [{ id: 1, project_id: 2, uri: '/old/blue-widget', hits: 9,
    suggestions: [{ target: '/products/blue-widget', score: 85, reason: 'Same page name in a different folder' }, { target: '/products', score: 40, reason: 'Closest section page (low confidence)' }] }] };
  SW.renderRedirectSuggestions(box, data, true);
  const rows = [...box.querySelectorAll('tbody tr')]; assert.equal(rows.length, 1);
  assert.match(rows[0].textContent, /\/old\/blue-widget/); assert.match(rows[0].textContent, /9/);
  const btns = [...rows[0].querySelectorAll('[data-action="redirect-accept"]')]; assert.equal(btns.length, 2);
  assert.equal(btns[0].dataset.uri, '/old/blue-widget'); assert.equal(btns[0].dataset.target, '/products/blue-widget'); assert.equal(btns[0].dataset.project, '2');
  assert.match(rows[0].textContent, /85%/); assert.match(rows[0].textContent, /low confidence/i); assert.match(box.textContent, /4 more.*no good match/i);
  box.replaceChildren(); SW.renderRedirectSuggestions(box, data, false); assert.equal(box.querySelectorAll('[data-action="redirect-accept"]').length, 0, 'viewers see the suggestion but cannot accept'); assert.match(box.textContent, /\/products\/blue-widget/);
  box.replaceChildren(); SW.renderRedirectSuggestions(box, { has_inventory: false, source: 'none', unmatched: 0, items: [] }, true);
  assert.match(box.textContent, /run a site audit/i); assert.ok(box.querySelector('[data-goto="site-audit"]'));
  box.replaceChildren(); SW.renderRedirectSuggestions(box, { has_inventory: true, source: 'sitemap', unmatched: 0, items: [] }, true); assert.match(box.textContent, /Nothing to suggest/);
});
test('redirect suggestions: hostile paths stay text', () => {
  const { SW, box } = load();
  for (const p of PAYLOADS) {
    box.replaceChildren(); SW.renderRedirectSuggestions(box, { has_inventory: true, source: 'audit', unmatched: 0, items: [{ id: 1, project_id: 0, uri: p, hits: 1, suggestions: [{ target: p, score: 70, reason: p }] }] }, true);
    assert.equal(box.querySelectorAll('img,script,iframe,object,embed,style,link,form,input,base,meta,svg').length, 0, p); assert.ok(box.textContent.includes(p));
  }
});

const opp = (o = {}) => Object.assign({ type: 'ctr_gap', project_id: 1, site: 'Shop', query: 'blue widgets', page: 'https://s.example/blue', impressions: 1000, clicks: 30, ctr: 3, position: 3, potential_clicks: 80, advice: 'Rewrite the title.' }, o);
test('opportunities: a plain-words label per type, what could be gained, the numbers, advice; the note says it is an estimate', () => {
  const { SW, box } = load();
  SW.renderOpportunities(box, { has_data: true, summary: { ctr_gap: 1, striking_distance: 1, cannibalisation: 1, falling: 1, potential_clicks: 190 }, note: 'Potential clicks are a rough estimate.', last_sync_at: '2026-11-17 02:00:00',
    items: [opp(), opp({ type: 'striking_distance', query: 'green widgets', position: 12, potential_clicks: 60 }),
      opp({ type: 'cannibalisation', query: 'red widgets', other_pages: ['https://s.example/r2'], potential_clicks: 0 }), opp({ type: 'falling', query: 'old kw', page: '', previous_clicks: 100, clicks: 30, ctr: 0, position: 0, potential_clicks: 70 })] });
  const rows = [...box.querySelectorAll('tbody tr')]; assert.equal(rows.length, 4);
  assert.match(box.textContent, /4 opportunities/); assert.match(box.textContent, /190 more clicks/);
  assert.match(rows[0].textContent, /Low click-through/); assert.match(rows[0].textContent, /blue widgets/); assert.match(rows[0].textContent, /1,000/); assert.match(rows[0].textContent, /\+80/); assert.match(rows[0].textContent, /Rewrite the title/);
  assert.match(rows[1].textContent, /Within reach of page one/); assert.match(rows[2].textContent, /Pages competing/); assert.match(rows[2].textContent, /s\.example\/r2/);
  assert.match(rows[3].textContent, /Losing clicks/); assert.match(rows[3].textContent, /100.*30/); assert.doesNotMatch(rows[3].textContent, /\+0/);
  assert.match(box.textContent, /rough estimate/);
});
test('opportunities: no data explains how to get some; no findings says so; hostile text stays text', () => {
  const { SW, box } = load();
  SW.renderOpportunities(box, { has_data: false, items: [], summary: {}, note: '' }); assert.match(box.textContent, /Search Console/); assert.ok(box.querySelector('[data-goto="analytics"]'));
  box.replaceChildren(); SW.renderOpportunities(box, { has_data: true, items: [], summary: { potential_clicks: 0 }, note: '' }); assert.match(box.textContent, /Nothing stands out/);
  for (const p of PAYLOADS) {
    box.replaceChildren(); SW.renderOpportunities(box, { has_data: true, summary: { ctr_gap: 1, potential_clicks: 1 }, note: p, items: [opp({ query: p, page: p, site: p, advice: p, other_pages: [p], type: 'cannibalisation' })] });
    assert.equal(box.querySelectorAll('img,script,iframe,object,embed,style,link,form,input,base,meta,svg').length, 0, p); assert.ok(box.textContent.includes(p));
  }
});

const psiRun = (o = {}) => Object.assign({ status: 'ok', error: null, strategy: 'mobile', perf_score: 87, lcp_ms: 2412, cls: 0.045, tbt_ms: 130, fcp_ms: 1210, ttfb_ms: 311, field_category: 'AVERAGE', field_lcp_ms: 4500, field_cls: 0.12, field_inp_ms: 190, fetched_at: '2026-11-17 03:00:00' }, o);
test('Web Vitals card: score, each metric in plain units with Good / Needs work / Poor in words, real-user data when Google has it', () => {
  const { SW, box } = load();
  SW.renderPageSpeed(box, psiRun());
  assert.match(box.textContent, /87/); assert.match(box.textContent, /mobile/i);
  const row = label => [...box.querySelectorAll('tbody tr')].find(r => r.textContent.includes(label));
  assert.match(row('Largest Contentful Paint').textContent, /2\.4 s/); assert.match(row('Largest Contentful Paint').textContent, /Good/);
  assert.match(row('Layout shift').textContent, /0\.045/); assert.match(row('Layout shift').textContent, /Good/);
  assert.match(row('Total blocking time').textContent, /130 ms/); assert.match(row('Server response').textContent, /311 ms/);
  assert.match(box.textContent, /real users/i); assert.match(row('Largest Contentful Paint (real users)').textContent, /Poor/); assert.match(row('Layout shift (real users)').textContent, /Needs work/); assert.match(row('Interaction to Next Paint').textContent, /Good/);
  box.replaceChildren(); SW.renderPageSpeed(box, psiRun({ field_category: null, field_lcp_ms: null, field_cls: null, field_inp_ms: null })); assert.doesNotMatch(box.textContent, /real users/i);
});
test('Web Vitals card: a refused or failed measurement shows the reason, not zeros', () => {
  const { SW, box } = load();
  SW.renderPageSpeed(box, psiRun({ status: 'rate_limited', error: 'Google quota used up. Create a free key.', perf_score: null, lcp_ms: null }));
  assert.match(box.textContent, /quota used up/); assert.equal(box.querySelectorAll('table').length, 0);
  for (const p of PAYLOADS) { box.replaceChildren(); SW.renderPageSpeed(box, psiRun({ status: 'error', error: p })); assert.equal(box.querySelectorAll('img,script,iframe,object,embed,style,link,form,input,base,meta,svg').length, 0, p); assert.ok(box.textContent.includes(p)); }
});
test('analysis result offers Web Vitals only when it is switched on and the person can edit; the watchlist shows the newest measurement and a switch', () => {
  const { SW, box } = load();
  SW.renderSeoResults(box, seoData('x'), { canWatch: true, canPsi: true }); const b = box.querySelector('[data-action="psi-run"]'); assert.ok(b); assert.equal(b.dataset.url, 'x'); assert.match(b.parentElement.textContent, /sent to Google/i, 'the disclosure sits right next to the button');
  box.replaceChildren(); SW.renderSeoResults(box, seoData('x'), { canWatch: true }); assert.equal(box.querySelector('[data-action="psi-run"]'), null);
  box.replaceChildren(); SW.renderWatchlist(box, { items: [watchItem({ pagespeed: true, psi: { perf_score: 82, lcp_ms: 2100, cls: 0.04, at: '2026-11-17' } }), watchItem({ id: 9, url: 'https://n.example/', pagespeed: false, psi: null })] }, true, true);
  const rows = [...box.querySelectorAll('tbody tr')]; assert.match(rows[0].textContent, /82/); assert.match(rows[0].textContent, /2\.1 s/);
  assert.equal(rows[0].querySelector('[data-action="watch-psi"]').textContent, 'Web Vitals: on'); assert.equal(rows[1].querySelector('[data-action="watch-psi"]').textContent, 'Web Vitals: off');
  box.replaceChildren(); SW.renderWatchlist(box, { items: [watchItem()] }, true, false); assert.equal(box.querySelector('[data-action="watch-psi"]'), null, 'not offered while Settings has it off');
});

const exportsOf = box => [...box.querySelectorAll('a.export-link')].map(a => a.dataset.export + (a.dataset.scoped === '1' ? ' [site]' : ''));
test('export and print: reports offer CSV downloads and a "Print or save as PDF" button; the link carries only a path, never user text', () => {
  const { SW, box } = load();
  SW.renderSiteAuditReport(box, { audit: { id: 8, base_url: 'https://c.example', status: 'done', pages: 3, stopped_early: 0, summary: { pages: 3, avg_score: 80, new: null, fixed: null, by_severity: { high: 0, medium: 0, low: 0 } } }, types: [] });
  assert.deepEqual(exportsOf(box), ['/audit/8?part=issues', '/audit/8?part=pages']); const pr = box.querySelector('[data-action="print-report"]'); assert.ok(pr); assert.match(pr.dataset.title, /c\.example/); assert.match(pr.textContent, /PDF/);
  box.replaceChildren(); SW.renderOpportunities(box, { has_data: true, summary: { potential_clicks: 5 }, note: '', items: [opp()] }); assert.deepEqual(exportsOf(box), ['/opportunities [site]']); assert.ok(box.querySelector('[data-action="print-report"]'));
  box.replaceChildren(); SW.renderWatchlist(box, { items: [watchItem()] }, true, false); assert.deepEqual(exportsOf(box), ['/watch [site]']);
  box.replaceChildren(); SW.renderWatchHistory(box, { points: [{ at: 'x', score: 1, load_time: 1, word_count: 1, noindex: false }] }, 3); assert.deepEqual(exportsOf(box), ['/watch/3/history']);
  box.replaceChildren(); SW.renderWatchHistory(box, { points: [{ at: 'x', score: 1, load_time: 1, word_count: 1, noindex: false }] }); assert.deepEqual(exportsOf(box), [], 'without an id there is nothing to link to');
  box.replaceChildren(); SW.renderOverview(box, { sites: [{ id: 1, name: 'A', base_url: 'u', plugin: true, last_score: 1, last_analyzed_at: null, impressions: 1, clicks: 1, not_found: 0, watched: 0, down: 0 }] }); assert.deepEqual(exportsOf(box), ['/overview']);
  box.replaceChildren(); SW.renderRedirectSuggestions(box, { has_inventory: true, source: 'audit', unmatched: 0, items: [{ id: 1, project_id: 0, uri: '/a', hits: 1, suggestions: [{ target: '/b', score: 90, reason: 'r' }] }] }, true); assert.deepEqual(exportsOf(box), ['/redirect-suggestions [site]']);
  box.replaceChildren(); SW.renderSeoResults(box, seoData('https://c.example/x')); const p2 = box.querySelector('[data-action="print-report"]'); assert.ok(p2); assert.match(p2.dataset.title, /c\.example\/x/);
  for (const p of PAYLOADS) { box.replaceChildren(); SW.renderSiteAuditReport(box, { audit: { id: 1, base_url: p, status: 'done', pages: 1, stopped_early: 0, summary: { pages: 1, avg_score: 1, new: null, fixed: null, by_severity: { high: 0, medium: 0, low: 0 } } }, types: [] });
    for (const a of box.querySelectorAll('a')) assert.ok(!/^\s*javascript:/i.test(a.getAttribute('href') || ''), 'no javascript: link for ' + p); }
});

test('signed-in devices: browser, when it was added and last used, which one is this browser, and remove buttons; empty says so', () => {
  const { SW, box } = load();
  SW.renderDevices(box, { devices: [{ id: 5, browser: 'Mozilla/5.0 (Macintosh) Safari/605', created_at: '2026-11-01 08:00:00', last_used_at: '2026-11-17 09:00:00', expires_at: '2026-12-17 09:00:00', current: true },
    { id: 6, browser: 'Firefox on Linux', created_at: '2026-10-01 08:00:00', last_used_at: '2026-11-10 09:00:00', expires_at: '2026-12-10 09:00:00', current: false }] });
  const rows = [...box.querySelectorAll('tbody tr')]; assert.equal(rows.length, 2);
  assert.match(rows[0].textContent, /This browser/); assert.match(rows[0].textContent, /2026-11-17/); assert.doesNotMatch(rows[1].textContent, /This browser/);
  assert.equal(rows[1].querySelector('[data-action="device-forget"]').dataset.id, '6'); assert.ok(box.querySelector('[data-action="devices-forget-all"]'));
  box.replaceChildren(); SW.renderDevices(box, { devices: [] }); assert.match(box.textContent, /No remembered devices/); assert.equal(box.querySelector('[data-action="devices-forget-all"]'), null);
  for (const p of PAYLOADS) { box.replaceChildren(); SW.renderDevices(box, { devices: [{ id: 1, browser: p, created_at: p, last_used_at: p, expires_at: p, current: false }] });
    assert.equal(box.querySelectorAll('img,script,iframe,object,embed,style,link,form,input,base,meta,svg').length, 0, p); assert.ok(box.textContent.includes(p)); }
});
test('sign-in form sends the "keep me signed in" choice with the credentials', async () => {
  const sent = [];
  const html = '<!doctype html><body><script type="application/json" id="app-config">{"basePath":"","csrfToken":"tok"}</script>'
    + '<form id="login-form"><div class="form-group"><input name="username" value="u"></div><div class="form-group"><input name="password" value="p"></div><div id="login-mfa" class="hidden"><input name="code"></div>'
    + '<label><input type="checkbox" name="remember" id="login-remember" checked> Keep me signed in</label><div id="login-error" class="hidden"></div><button type="submit">Go</button></form></body>';
  const { VirtualConsole } = require('jsdom');
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/', virtualConsole: new VirtualConsole() });
  dom.window.fetch = async (url, opts) => { sent.push(JSON.parse(opts.body)); return { ok: true, status: 200, json: async () => ({ success: true, data: { mfa_required: true } }) }; };
  dom.window.eval(SRC);
  dom.window.document.getElementById('login-form').dispatchEvent(new dom.window.Event('submit', { cancelable: true, bubbles: true }));
  await new Promise(r => setTimeout(r, 100));
  assert.equal(sent[0].remember, true); assert.equal(sent[0].username, 'u');
});

const alertData = (o = {}) => Object.assign({ summary: { sent: 4, suppressed: 30, batched: 6, digest: 2, muted: 1 }, waiting: { batch: 3, digest: 2 }, channels: ['email', 'teams'],
  mutes: [{ id: 9, project_id: 2, site: 'Corner', until: '2026-11-17 20:00:00', reason: 'deploying' }], settings: { max_per_hour: 30, batch_minutes: 15, digest_time: '08:00', quiet_start: '22:00', quiet_end: '07:00', timezone: 'America/Denver', cooldown: 86400 } }, o);
test('alerts page: plain-words summary of the last 24 hours, what is waiting, active pauses with Resume, pause buttons and the two presets', () => {
  const { SW, box } = load();
  SW.renderAlertSummary(box, alertData(), [{ id: 2, name: 'Corner' }]);
  assert.match(box.textContent, /4 sent/); assert.match(box.textContent, /30 held back/); assert.match(box.textContent, /6 batched/);
  assert.match(box.textContent, /3 alerts are waiting for the next batch/); assert.match(box.textContent, /2.*daily digest.*08:00.*America\/Denver/s);
  assert.match(box.textContent, /Quiet hours 22:00.*07:00/s); assert.match(box.textContent, /e-mail.*teams/i);
  assert.equal(box.querySelector('[data-action="alerts-resume"]').dataset.id, '9'); assert.match(box.textContent, /Corner.*deploying/s);
  assert.deepEqual([...box.querySelectorAll('[data-action="alerts-pause"]')].map(b => b.dataset.hours), ['1', '4', '24']);
  assert.deepEqual([...box.querySelectorAll('#alerts-pause-site option')].map(o => o.textContent), ['Everything', 'Corner']);
  assert.deepEqual([...box.querySelectorAll('[data-action="alerts-preset"]')].map(b => b.dataset.name), ['calm', 'everything']);
  box.replaceChildren(); SW.renderAlertSummary(box, alertData({ mutes: [], waiting: { batch: 0, digest: 0 }, settings: { max_per_hour: 0, batch_minutes: 15, digest_time: '08:00', quiet_start: '', quiet_end: '', timezone: 'UTC', cooldown: 300 } }), []);
  assert.equal(box.querySelector('[data-action="alerts-resume"]'), null); assert.doesNotMatch(box.textContent, /Quiet hours/); assert.doesNotMatch(box.textContent, /waiting/);
});
test('alert history: each row says what was decided and why, in words', () => {
  const { SW, box } = load();
  SW.renderAlertLog(box, { rows: [
    { id: 1, created_at: '2026-11-17 14:00:00', event: 'job_failed', level: 'warning', project_id: 0, site: null, message: 'Job #1 failed', outcome: 'sent', channels: 'email,teams', reason: '' },
    { id: 2, created_at: '2026-11-17 13:59:00', event: 'job_failed', level: 'warning', project_id: 0, site: null, message: 'Job #2 failed', outcome: 'suppressed', channels: '', reason: 'cooldown: the same alert was sent less than 24 h ago' },
    { id: 3, created_at: '2026-11-17 13:58:00', event: 'watch_down', level: 'error', project_id: 2, site: 'Corner', message: 'Home is down', outcome: 'muted', channels: '', reason: 'muted: paused until 2026-11-17 20:00:00 UTC (deploying)' },
    { id: 4, created_at: '2026-11-17 13:57:00', event: 'user_created', level: 'info', project_id: 0, site: null, message: 'bob', outcome: 'digest', channels: 'teams', reason: 'daily digest' },
    { id: 5, created_at: '2026-11-17 13:56:00', event: 'a', level: 'info', project_id: 0, site: null, message: 'x', outcome: 'batched', channels: 'teams', reason: 'held for quiet hours' }] });
  const rows = [...box.querySelectorAll('tbody tr')]; assert.equal(rows.length, 5);
  assert.match(rows[0].textContent, /Sent/); assert.match(rows[0].textContent, /email, teams/); assert.match(rows[1].textContent, /Held back/); assert.match(rows[1].textContent, /cooldown/);
  assert.match(rows[2].textContent, /Paused/); assert.match(rows[2].textContent, /Corner/); assert.match(rows[3].textContent, /Daily digest/); assert.match(rows[4].textContent, /Batched/); assert.match(rows[4].textContent, /quiet hours/);
  box.replaceChildren(); SW.renderAlertLog(box, { rows: [] }); assert.match(box.textContent, /No alerts recorded/);
  for (const p of PAYLOADS) { box.replaceChildren(); SW.renderAlertLog(box, { rows: [{ id: 1, created_at: p, event: p, level: 'warning', project_id: 0, site: p, message: p, outcome: 'sent', channels: p, reason: p }] });
    assert.equal(box.querySelectorAll('img,script,iframe,object,embed,style,link,form,input,base,meta,svg').length, 0, p); assert.ok(box.textContent.includes(p)); }
});

// ---- setup guide (site profile) -------------------------------------------------------------------------------------------
const profileData = (over = {}) => ({ site: { id: 3, name: 'Corner', base_url: 'https://corner.example', wordpress: false, profile_at: '2026-11-17 10:00:00', profile_error: null }, fields: [
  { field: 'site_title', label: 'Home page title', group: 'site', max: 200, info: false, found: 'Corner Bakery', value: null, state: 'pending' },
  { field: 'phone', label: 'Phone', group: 'business', max: 30, info: false, found: '+1 505 555 0100', value: '+1 505 555 0199', state: 'manual' },
  { field: 'city', label: 'City', group: 'business', max: 100, info: false, found: 'Santa Fe', value: 'Santa Fe', state: 'used' },
  { field: 'country', label: 'Country', group: 'business', max: 100, info: false, found: null, value: null, state: 'empty' },
  { field: 'https', label: 'Secure connection (HTTPS)', group: 'check', max: 60, info: true, found: 'Yes', value: null, state: 'info' },
  { field: 'robots', label: 'Search engines allowed in', group: 'check', max: 100, info: true, found: 'Blocked: robots.txt tells every search engine to stay away (Disallow: /)', value: null, state: 'info' },
], ...over });
test('setup guide: each item offers only the choices that make sense, and every button carries the site and item', () => {
  const { SW, box } = load();
  SW.renderSiteProfile(box, profileData());
  const row = f => [...box.querySelectorAll('.profile-row')].find(r => r.querySelector('th').textContent === f);
  const actions = f => [...row(f).querySelectorAll('[data-action]')].map(b => b.dataset.action).sort();   // order on the page is not what is being tested
  assert.deepEqual(actions('Home page title'), ['profile-save', 'profile-skip', 'profile-type', 'profile-use']);
  assert.deepEqual(actions('Phone'), ['profile-reset', 'profile-save', 'profile-skip', 'profile-type', 'profile-use'], 'a typed value can still be replaced by what the site says');
  assert.deepEqual(actions('City'), ['profile-reset', 'profile-save', 'profile-skip', 'profile-type'], 'already using the site: no second "Use this"');
  assert.deepEqual(actions('Country'), ['profile-save', 'profile-skip', 'profile-type'], 'nothing found: only typing or skipping');
  assert.deepEqual(actions('Secure connection (HTTPS)'), [], 'checks are not choices');
  assert.match(row('Phone').textContent, /\+1 505 555 0199/); assert.match(row('Phone').textContent, /The site now says: \+1 505 555 0100/);
  for (const b of box.querySelectorAll('.profile-row [data-action]')) { assert.equal(b.dataset.id, '3'); assert.ok(b.dataset.field, 'field set on ' + b.dataset.action); }
  assert.ok(row('Phone').querySelector('.profile-edit.hidden input'), 'the typing box starts hidden'); assert.equal(row('Phone').querySelector('.profile-edit input').value, '+1 505 555 0199');
  assert.equal(row('Secure connection (HTTPS)').querySelector('.badge-ok').textContent, 'Good'); assert.equal(row('Search engines allowed in').querySelector('.badge-bad').textContent, 'Look at this');
  assert.ok(box.querySelector('[data-action="profile-refresh"]') && box.querySelector('[data-action="profile-watch"]') && box.querySelector('[data-action="profile-audit"]'));
});
test('setup guide: before the first read it says so; a failed read shows the reason', () => {
  const { SW, box } = load();
  SW.renderSiteProfile(box, profileData({ site: { id: 3, name: 'Corner', base_url: 'https://corner.example', profile_at: null, profile_error: null } }));
  assert.match(box.textContent, /Reading https:\/\/corner\.example now/);
  SW.renderSiteProfile(box, profileData({ site: { id: 3, name: 'Corner', base_url: 'https://corner.example', profile_at: '2026-11-17 10:00:00', profile_error: 'The site answered HTTP 503 for its home page' } }));
  assert.match(box.querySelector('.alert-warn').textContent, /HTTP 503/);
});
test('setup guide: everything a site said is shown as text, never as markup', () => {
  const { SW, box } = load();
  for (const p of PAYLOADS) {
    const d = profileData(); d.site.name = p; d.site.base_url = p; d.fields[0].found = p; d.fields[1].value = p; d.fields[1].found = p + '2'; d.fields[2].value = p;
    box.replaceChildren(); SW.renderSiteProfile(box, d);
    const inputs = [...box.querySelectorAll('input')]; assert.equal(inputs.length, 4, 'one typing box per choosable item and nothing injected'); inputs.forEach(i => i.remove());
    assertInert(box, p, 'profile'); assert.ok(box.textContent.includes(p));
  }
});
test('Search Console: the property that matches the site is preselected (domain property first)', () => {
  const { SW, box } = load();
  const state = { configured: true, redirect_uri: 'x', connections: [{ id: 9, property_url: null, site_id: 5, last_status: 'ok', last_sync_at: null, rows_synced: 0, last_error: null }] };
  const props = { 9: [{ site_url: 'https://other.example/', permission: 'owner' }, { site_url: 'https://www.shop.example/', permission: 'owner' }, { site_url: 'sc-domain:shop.example', permission: 'owner' }] };
  SW.renderGsc(box, state, props, [{ id: 5, name: 'Shop', base_url: 'https://www.shop.example' }]);
  assert.equal(box.querySelector('#gsc-prop-9').value, 'sc-domain:shop.example'); assert.match(box.textContent, /matches Shop/);
  box.replaceChildren(); SW.renderGsc(box, state, { 9: [{ site_url: 'https://other.example/', permission: 'owner' }, { site_url: 'https://www.shop.example/', permission: 'owner' }] }, [{ id: 5, name: 'Shop', base_url: 'https://shop.example' }]);
  assert.equal(box.querySelector('#gsc-prop-9').value, 'https://www.shop.example/', 'www and non-www are the same site');
  box.replaceChildren(); SW.renderGsc(box, state, { 9: [{ site_url: 'https://other.example/', permission: 'owner' }] }, [{ id: 5, name: 'Shop', base_url: 'https://shop.example' }]);
  assert.equal(box.querySelector('#gsc-prop-9').value, 'https://other.example/'); assert.doesNotMatch(box.textContent, /Preselected/);
});
