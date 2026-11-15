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
  assert.equal(calls[0].url, '/api/auth/login'); assert.deepEqual(calls[0].body, { username: 'admin', password: 'pw' });
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
