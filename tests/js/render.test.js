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
  return { dom, RMW: dom.window.RMW, box: dom.window.document.getElementById('c') };
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
    const { RMW, box } = load();
    const doc = box.ownerDocument;
    const cases = {
      seo: () => RMW.renderSeoResults(box, seoData(p)),
      content: () => { let copied; RMW.renderContentResult(box, { content: p, content_type: p, source: 'gemini', powered_by: p, ai_error: p }, t => { copied = t; }); assert.equal(copied, p, 'copy uses the raw JS string'); },
      contentFallback: () => RMW.renderContentResult(box, { content: p, content_type: 'title', source: 'template_fallback', ai_error: p }),
      image: () => RMW.renderImageResults(box, { images: [{ src: p, has_alt: false, size: 10, dimensions: p, optimized: null }], found: 1, skipped: 1, truncated: true, limit: p }),
      analytics: () => RMW.renderAnalytics(box, { keywords: [{ keyword: p, total_impressions: 1, total_clicks: 1, avg_position: '2.5', is_sample: 1 }], summary: {}, range: { start: p, end: p } }),
      logs: () => RMW.render404Logs(box, { logs: [{ id: 1, uri: p, hits: p, last_accessed: p, is_sample: 0 }] }),
      redirects: () => RMW.renderRedirections(box, [{ id: p, source_url: p, target_url: p, redirect_type: p, is_sample: 1 }]),
      locations: () => RMW.renderLocations(box, [{ id: 1, name: p, address: p, city: p, state: p, postal_code: p, country: p, latitude: p, longitude: p }]),
      sitemap: () => RMW.renderSitemapResult(box, { fetched: p, total_found: p, added: p, stopped_early: true, max_pages: p }, '/api/sitemap/generate-xml'),
    };
    for (const [name, run] of Object.entries(cases)) {
      box.replaceChildren();
      run();
      assertInert(box, p, name);
      assert.ok(box.textContent.includes(p) || name === 'sitemap' || name === 'content', `${name}: payload should be visible as plain text`);
    }
    // dashboard writes into fixed ids
    doc.body.insertAdjacentHTML('beforeend', '<p id="recent-activity"></p><p id="stat-seo"></p><p id="stat-keywords"></p><p id="stat-impressions"></p><p id="stat-clicks"></p><p id="dashboard-note"></p>');
    RMW.renderDashboard({ seo_score: 1, total_keywords: 1, impressions: 1, clicks: 1, includes_sample_data: true, recent_activity: [{ type: p, url: p, score: p, date: p, is_sample: 1 }] });
    assertInert(doc.getElementById('recent-activity'), p, 'dashboard');
    assert.ok(doc.getElementById('recent-activity').textContent.includes(p));
  });
}

test('delete buttons carry ids only in data-* attributes (delegated listeners, no inline handlers)', () => {
  const { RMW, box } = load();
  RMW.renderRedirections(box, [{ id: 7, source_url: '/a', target_url: '/b', redirect_type: '301' }]);
  const b = box.querySelector('button');
  assert.equal(b.dataset.action, 'delete-redirection'); assert.equal(b.dataset.id, '7'); assert.equal(b.getAttribute('onclick'), null);
});

test('no HTML-string sinks exist anywhere in app.js', () => {
  for (const sink of [/\.innerHTML\b/, /\.outerHTML\b/, /insertAdjacentHTML/, /document\.write/, /\beval\s*\(/, /new Function\b/, /\bsetTimeout\s*\(\s*['"`]/, /\bon(click|error|load)\s*=/i, /createContextualFragment/, /srcdoc/]) {
    assert.ok(!sink.test(SRC), `forbidden sink ${sink}`);
  }
});
