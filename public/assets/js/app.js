/**
 * Sitewright - front end.
 *
 * Security rules for this file (CSP: script-src 'self', no inline handlers):
 *  - Data from the server (which includes data from third-party sites) is NEVER parsed as HTML.
 *    Every node is built with createElement + textContent; no HTML-string APIs are used (a test greps for them).
 *  - Actions use data-action / data-id attributes and one delegated listener; no inline onclick.
 *  - All requests go through apiRequest(): CSRF header, same-origin credentials, error handling, 401 -> login.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------ DOM helpers

    /** el('div', {class:'x', text:'safe text', data:{id:1}, attrs:{href:'/x'}}, child1, 'text child') */
    function el(tag, props, ...children) {
        const node = document.createElement(tag);
        const p = props || {};
        if (p.class) node.className = p.class;
        if (p.text !== undefined && p.text !== null) node.textContent = String(p.text);
        if (p.data) for (const k of Object.keys(p.data)) node.dataset[k] = String(p.data[k]);
        if (p.attrs) for (const k of Object.keys(p.attrs)) node.setAttribute(k, String(p.attrs[k]));
        for (const c of children) {
            if (c === null || c === undefined || c === false) continue;
            node.append(c instanceof Node ? c : document.createTextNode(String(c)));
        }
        return node;
    }

    function setContent(container, ...nodes) {
        container.replaceChildren(...nodes.filter(n => n !== null && n !== undefined && n !== false));
    }

    const muted = text => el('p', { class: 'text-muted', text });
    const fmtInt = n => (Number(n) || 0).toLocaleString();
    const fmtKB = bytes => (bytes === null || bytes === undefined) ? 'unknown' : (Number(bytes) / 1024).toFixed(1) + ' KB';
    const sampleBadge = row => (row && Number(row.is_sample) === 1) ? el('span', { class: 'badge', text: 'sample data' }) : null;

    function listItem(mainNodes, actionNodes) {
        return el('div', { class: 'list-item' }, el('div', {}, ...mainNodes),
            actionNodes ? el('div', { class: 'list-item-actions' }, ...actionNodes) : null);
    }

    function deleteButton(action, id) {
        return el('button', { class: 'btn btn-danger btn-sm', text: 'Delete', attrs: { type: 'button' }, data: { action, id } });
    }

    // ------------------------------------------------------------------ renderers (exported for tests)

    function resultRow(label, value) {
        return el('div', { class: 'result-item' }, el('span', { text: label }), el('span', { text: value }));
    }

    function section(title, ...nodes) {
        return el('div', { class: 'result-section' }, el('h4', { text: title }), ...nodes);
    }

    function renderSeoResults(container, data) {
        const r = data.results;
        const score = Number(data.score);
        const cls = score >= 80 ? 'good' : score >= 50 ? 'average' : 'poor';
        const yn = v => v ? '✅ Yes' : '❌ No';
        setContent(container, el('div', { class: 'result-card' },
            el('h3', { class: 'text-center', text: 'SEO Score' }),
            el('div', { class: 'result-score ' + cls, text: score + '/100' }),
            el('p', { class: 'text-muted text-center break-all', text: 'Analyzed: ' + data.url + ' (HTTP ' + r.fetch.status_code + ', ' + r.fetch.redirect_count + ' redirect(s))' }),
            section('Title', resultRow(r.basic.title || 'No title found', r.basic.title_length + ' chars'),
                muted(r.basic.title_optimal ? '✅ Title length is optimal' : '⚠️ Title should be 30-60 characters')),
            section('Meta Description', resultRow(r.meta.description || 'No description found', r.meta.description_length + ' chars'),
                muted(r.meta.description_optimal ? '✅ Description length is optimal' : '⚠️ Description should be 120-160 characters')),
            section('Headings', resultRow('H1 tags', r.headings.h1_count),
                ...(r.headings.headings.h1 || []).slice(0, 5).map(h => resultRow('H1', h)),
                muted(r.headings.h1_optimal ? '✅ Exactly one H1 tag' : '⚠️ Should have exactly one H1 tag')),
            section('Images', resultRow('Total images', r.images.total_images),
                resultRow('With alt text', r.images.images_with_alt + ' (' + r.images.alt_ratio + '%)'),
                muted(r.images.alt_ratio >= 90 ? '✅ Great alt text coverage' : '⚠️ Add alt text to all images')),
            section('Links', resultRow('Internal', r.links.internal_links), resultRow('External', r.links.external_links), resultRow('nofollow', r.links.nofollow_links)),
            section('Fetch time', resultRow('HTML document fetched in', r.performance.load_time + 's'), muted(r.performance.note)),
            section('Basics', resultRow('HTTPS (final URL)', yn(r.security.uses_https)), resultRow('Viewport meta tag present', yn(r.viewport.has_viewport)),
                resultRow('Structured data (JSON-LD)', yn(r.structured_data.has_schema)), resultRow('Word count', r.basic.word_count),
                resultRow('Canonical', r.basic.canonical || 'none'), resultRow('Language', r.basic.lang || 'not set'), resultRow('noindex', yn(r.basic.noindex))),
            muted('Heuristic checks based on one server-side fetch.')));
    }

    function renderContentResult(container, data, onCopy) {
        const notes = [];
        if (data.source === 'gemini') notes.push(el('div', { class: 'alert alert-info', text: 'Generated by ' + data.powered_by }));
        else if (data.source === 'template_fallback') notes.push(el('div', { class: 'alert alert-warn', text: 'AI was configured but the request failed (' + (data.ai_error || 'unknown error') + '). Showing template text instead.' }));
        else notes.push(el('div', { class: 'alert', text: 'Template text - no AI is configured. This is not AI-generated.' }));
        setContent(container, el('div', { class: 'result-card' },
            el('h4', { text: 'Generated ' + String(data.content_type).replace('_', ' ') }), ...notes,
            el('p', { class: 'pre-wrap', text: data.content }),
            el('button', { class: 'btn btn-secondary mt-20', text: 'Copy to Clipboard', attrs: { type: 'button' }, data: { action: 'copy' } })));
        if (onCopy) onCopy(String(data.content));
    }

    function renderImageResults(container, data) {
        const items = data.images.map(img => listItem([
            el('strong', { class: 'break-all', text: img.src }), el('br'),
            el('small', { class: 'text-muted', text: (img.has_alt ? '✅ Has alt text' : '⚠️ Missing alt text') + ' | Size: ' + fmtKB(img.size) + ' | ' + (img.dimensions || 'unknown dimensions')
                + ' | Optimized: ' + (img.optimized === null ? 'unknown' : (img.optimized ? 'yes' : 'no')) })]));
        const notes = [];
        if (data.truncated) notes.push(muted('Only the first ' + data.limit + ' of ' + data.found + ' images were analyzed.'));
        if (data.skipped) notes.push(muted(data.skipped + ' image(s) skipped (unreachable, not an image, or not allowed).'));
        setContent(container, el('h4', { text: 'Image Analysis Results' }), ...notes, el('div', { class: 'list-area' }, ...(items.length ? items : [muted('No images found.')])));
    }

    function renderAnalytics(container, data) {
        const s = data.summary || {};
        const rows = data.keywords.map(k => listItem([
            el('strong', { text: k.keyword }), sampleBadge(k), el('br'),
            el('small', { class: 'text-muted', text: 'Impressions: ' + fmtInt(k.total_impressions) + ' | Clicks: ' + fmtInt(k.total_clicks) })],
            [el('span', { text: 'Pos: ' + (k.avg_position === null ? '-' : Number(k.avg_position).toFixed(1)) })]));
        setContent(container,
            el('p', { class: 'text-muted', text: 'Range ' + data.range.start + ' to ' + data.range.end + ' | ' + fmtInt(s.total_keywords) + ' keywords | ' + fmtInt(s.total_impressions) + ' impressions | ' + fmtInt(s.total_clicks) + ' clicks' }),
            el('h4', { text: 'Top Keywords' }),
            el('div', { class: 'list-area' }, ...(rows.length ? rows : [muted('No keyword data yet. Add or import data through the API.')])));
    }

    function render404Logs(container, data) {
        if (!data.logs.length) return setContent(container, muted('No entries in the 404 log.'));
        setContent(container, ...data.logs.map(l => listItem([
            el('strong', { class: 'break-all', text: l.uri }), sampleBadge(l), el('br'),
            el('small', { class: 'text-muted', text: 'Hits: ' + l.hits + ' | Last: ' + l.last_accessed })], [deleteButton('delete-404', l.id)])));
    }

    function renderRedirections(container, rules) {
        if (!rules.length) return setContent(container, muted('No redirect rules yet.'));
        setContent(container, ...rules.map(r => listItem([
            el('strong', { class: 'break-all', text: r.source_url }), ' → ', el('span', { class: 'break-all', text: r.target_url || '(none)' }), sampleBadge(r), el('br'),
            el('small', { class: 'text-muted', text: 'Type: ' + r.redirect_type })], [deleteButton('delete-redirection', r.id)])));
    }

    function renderLocations(container, locs) {
        if (!locs.length) return setContent(container, muted('No locations added yet.'));
        setContent(container, ...locs.map(l => listItem([
            el('strong', { text: l.name }), sampleBadge(l), el('br'),
            el('small', { class: 'text-muted', text: [l.address, l.city, l.state, l.postal_code, l.country].filter(Boolean).join(', ') + (l.latitude !== null && l.latitude !== undefined ? ' | ' + l.latitude + ', ' + l.longitude : '') })],
            [deleteButton('delete-location', l.id)])));
    }

    function renderSitemapResult(container, data, xmlHref) {
        setContent(container, el('div', { class: 'result-card' }, el('h4', { text: 'Crawl finished' }),
            el('p', { text: 'Pages fetched: ' + data.fetched + ' | URLs found: ' + data.total_found + ' | New: ' + data.added }),
            data.stopped_early ? muted('Stopped early (page cap ' + data.max_pages + ' or time budget reached).') : null,
            el('a', { class: 'btn btn-primary', text: 'Download XML Sitemap', attrs: { href: xmlHref, target: '_blank', rel: 'noopener' } })));
    }

    function renderDashboard(stats) {
        const set = (id, text) => { const n = document.getElementById(id); if (n) n.textContent = text; };
        set('stat-seo', stats.seo_score === null ? 'N/A' : String(stats.seo_score));
        set('stat-keywords', fmtInt(stats.total_keywords));
        set('stat-impressions', fmtInt(stats.impressions));
        set('stat-clicks', fmtInt(stats.clicks));
        set('dashboard-note', stats.includes_sample_data ? 'Includes generated SAMPLE data.' : '');
        const box = document.getElementById('recent-activity');
        if (!box) return;
        const items = (stats.recent_activity || []).map(a => el('p', { class: 'text-muted break-all', text:
            (a.type === 'competitor' ? 'Competitor analysis' : 'Analysis') + ' of ' + a.url + ' - score ' + a.score + '/100 (' + a.date + ')' + (Number(a.is_sample) === 1 ? ' [sample]' : '') }));
        setContent(box, ...(items.length ? items : [muted('No recent activity')]));
    }

    // ------------------------------------------------------------------ test export (no side effects)

    const exported = { el, renderSeoResults, renderContentResult, renderImageResults, renderAnalytics, render404Logs, renderRedirections, renderLocations, renderSitemapResult, renderDashboard };
    window.SW = exported;

    const cfgNode = document.getElementById('app-config');
    if (!cfgNode) return; // loaded by a test harness
    const cfg = JSON.parse(cfgNode.textContent);
    const BASE = cfg.basePath || '';
    let csrf = cfg.csrfToken;

    // ------------------------------------------------------------------ API

    class ApiError extends Error {
        constructor(message, status) { super(message); this.status = status; }
    }

    async function apiRequest(method, path, body) {
        const opts = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
        if (method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-Token'] = csrf;
            opts.body = JSON.stringify(body || {});
        }
        let res;
        try { res = await fetch(BASE + '/api' + path, opts); } catch (e) { throw new ApiError('Network error - is the server reachable?', 0); }
        let payload = null;
        try { payload = await res.json(); } catch (e) { /* non-JSON error page */ }
        if (res.status === 401 && path !== '/auth/login') { window.location.reload(); throw new ApiError('Session expired', 401); }
        if (!res.ok || !payload || payload.success !== true) throw new ApiError((payload && payload.message) || ('Request failed (HTTP ' + res.status + ')'), res.status);
        return payload.data;
    }

    function showToast(message, type) {
        const toast = document.getElementById('toast');
        if (!toast) return;
        toast.textContent = message;
        toast.className = 'toast ' + (type || 'success') + ' show';
        clearTimeout(showToast.t);
        showToast.t = setTimeout(() => toast.classList.remove('show'), 4000);
    }

    /** Runs fn with the form's submit button disabled; shows failures; returns result or undefined. */
    async function withPending(trigger, fn) {
        const buttons = trigger ? trigger.querySelectorAll('button[type="submit"], button[data-action]') : [];
        buttons.forEach(b => { b.disabled = true; });
        try { return await fn(); } finally { buttons.forEach(b => { b.disabled = false; }); }
    }

    // ------------------------------------------------------------------ login

    const loginForm = document.getElementById('login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', async e => {
            e.preventDefault();
            const err = document.getElementById('login-error');
            err.classList.add('hidden');
            await withPending(loginForm, async () => {
                try {
                    await apiRequest('POST', '/auth/login', { username: loginForm.username.value, password: loginForm.password.value });
                    window.location.reload();
                } catch (ex) {
                    err.textContent = ex.message; err.classList.remove('hidden');
                }
            });
        });
        return;
    }

    // ------------------------------------------------------------------ navigation

    const TITLES = { dashboard: 'Dashboard', 'seo-analysis': 'SEO Analysis', competitor: 'Competitor Analysis', analytics: 'Analytics', 'content-ai': 'Content AI',
        'local-seo': 'Local SEO', 'image-seo': 'Image SEO', sitemaps: 'Sitemaps', '404-monitor': '404 Log', redirections: 'Redirect Rules' };
    const viewUrl = v => BASE + (v === 'dashboard' ? '/' : '/' + v);

    function viewFromLocation() {
        const rel = window.location.pathname.slice(BASE.length).replace(/^\/+|\/+$/g, '');
        return TITLES[rel] ? rel : 'dashboard';
    }

    function switchView(view, push) {
        if (!TITLES[view]) view = 'dashboard';
        document.querySelectorAll('.nav-item').forEach(n => n.classList.toggle('active', n.dataset.view === view));
        document.querySelectorAll('.view').forEach(v => v.classList.toggle('active', v.id === 'view-' + view));
        document.getElementById('page-title').textContent = TITLES[view];
        document.title = 'Sitewright - ' + TITLES[view];
        if (push) history.pushState({ view }, '', viewUrl(view));
        const loaders = { dashboard: loadDashboard, analytics: loadAnalytics, 'content-ai': loadAiStatus, 'local-seo': loadLocations, '404-monitor': load404, redirections: loadRedirections, sitemaps: loadSitemaps };
        if (loaders[view]) loaders[view]();
    }

    function showError(container, message) {
        setContent(container, el('div', { class: 'alert alert-error', text: message, attrs: { role: 'alert' } }));
    }

    // ------------------------------------------------------------------ loaders

    async function loadInto(containerId, path, render, label) {
        const box = document.getElementById(containerId);
        setContent(box, muted('Loading…'));
        try { render(box, await apiRequest('GET', path)); } catch (e) { showError(box, 'Could not load ' + label + ': ' + e.message); }
    }

    async function loadDashboard() {
        try { renderDashboard(await apiRequest('GET', '/dashboard/stats')); } catch (e) { showToast(e.message, 'error'); }
    }
    const loadAnalytics = () => loadInto('analytics-dashboard', '/analytics/dashboard', renderAnalytics, 'analytics');
    const loadLocations = () => loadInto('locations-list', '/local-seo/locations', renderLocations, 'locations');
    const load404 = () => loadInto('404-logs', '/404-monitor/logs?per_page=100', render404Logs, '404 log');
    const loadRedirections = () => loadInto('redirections-list', '/redirections', renderRedirections, 'redirect rules');
    const loadSitemaps = () => loadInto('sitemap-list', '/sitemap', (box, rows) => setContent(box,
        rows.length ? el('p', { class: 'text-muted', text: rows.length + ' URL(s) stored. ' }, el('a', { text: 'Download XML', attrs: { href: BASE + '/api/sitemap/generate-xml', target: '_blank', rel: 'noopener' } })) : muted('No sitemap URLs stored yet.')), 'sitemap');

    async function loadAiStatus() {
        const box = document.getElementById('ai-status');
        try {
            const s = await apiRequest('GET', '/content-ai/status');
            box.className = 'alert ' + (s.ai_configured ? 'alert-info' : 'alert-warn');
            box.textContent = s.ai_configured ? 'AI: Google Gemini (' + s.model + ') is configured. Prompts you enter are sent to Google.' : 'No AI is configured: output is simple fixed templates, not AI-generated.';
        } catch (e) { box.className = 'alert hidden'; }
    }

    // ------------------------------------------------------------------ forms

    let lastGenerated = '';

    const submitHandlers = {
        'seo-analysis-form': form => analyse(form, 'seo-url', 'seo-results', false),
        'competitor-form': form => analyse(form, 'competitor-url', 'competitor-results', true),
        'content-ai-form': async form => {
            const box = document.getElementById('ai-results');
            setContent(box, muted('Generating…'));
            try {
                const d = await apiRequest('POST', '/content-ai/generate', { keyword: form.querySelector('#ai-keyword').value, content_type: form.querySelector('#ai-content-type').value, tone: form.querySelector('#ai-tone').value });
                renderContentResult(box, d, t => { lastGenerated = t; });
            } catch (e) { showError(box, e.message); }
        },
        'image-bulk-form': async form => {
            const box = document.getElementById('image-results');
            setContent(box, muted('Analyzing images… this can take a minute.'));
            try { renderImageResults(box, await apiRequest('POST', '/image-seo/bulk-analyze', { url: form.querySelector('#image-url').value })); } catch (e) { showError(box, e.message); }
        },
        'sitemap-crawl-form': async form => {
            const box = document.getElementById('sitemap-results');
            setContent(box, muted('Crawling… this can take a couple of minutes.'));
            try {
                renderSitemapResult(box, await apiRequest('POST', '/sitemap/crawl', { base_url: form.querySelector('#sitemap-url').value, max_pages: 100 }), BASE + '/api/sitemap/generate-xml');
                loadSitemaps();
            } catch (e) { showError(box, e.message); }
        },
        'location-form': async form => {
            const body = {};
            for (const [k, v] of new FormData(form).entries()) if (String(v).trim() !== '') body[k] = String(v).trim();
            try { await apiRequest('POST', '/local-seo/locations', body); form.reset(); form.classList.add('hidden'); showToast('Location added'); loadLocations(); } catch (e) { showToast(e.message, 'error'); }
        },
        'redirection-form': async form => {
            try {
                await apiRequest('POST', '/redirections', { source_url: form.querySelector('#redir-source').value, target_url: form.querySelector('#redir-target').value, redirect_type: form.querySelector('#redir-type').value });
                form.reset(); form.classList.add('hidden'); showToast('Rule saved'); loadRedirections();
            } catch (e) { showToast(e.message, 'error'); }
        },
        'log404-form': async form => {
            try { await apiRequest('POST', '/404-monitor/log', { uri: form.querySelector('#log404-uri').value }); form.reset(); showToast('Entry added'); load404(); } catch (e) { showToast(e.message, 'error'); }
        },
    };

    async function analyse(form, inputId, resultId, competitor) {
        const box = document.getElementById(resultId);
        setContent(box, muted('Analyzing… this may take a moment.'));
        try {
            const data = await apiRequest('POST', '/seo-analysis/analyze', { url: form.querySelector('#' + inputId).value, is_competitor: competitor });
            renderSeoResults(box, data);
            showToast(competitor ? 'Competitor analysis complete' : 'Analysis complete');
        } catch (e) { showError(box, e.message); }
    }

    document.addEventListener('submit', e => {
        const h = submitHandlers[e.target.id];
        if (!h) return;
        e.preventDefault();
        withPending(e.target, () => h(e.target));
    });

    // ------------------------------------------------------------------ delegated clicks

    const clickActions = {
        'delete-404': async t => { if (confirm('Delete this log entry?')) { await apiRequest('DELETE', '/404-monitor/' + encodeURIComponent(t.dataset.id)); showToast('Entry deleted'); load404(); } },
        'clear-404': async () => { if (confirm('Clear ALL 404 log entries? This cannot be undone.')) { await apiRequest('POST', '/404-monitor/clear', { confirm: true }); showToast('Log cleared'); load404(); } },
        'refresh-404': () => load404(),
        'delete-redirection': async t => { if (confirm('Delete this rule?')) { await apiRequest('DELETE', '/redirections/' + encodeURIComponent(t.dataset.id)); showToast('Rule deleted'); loadRedirections(); } },
        'delete-location': async t => { if (confirm('Delete this location?')) { await apiRequest('DELETE', '/local-seo/locations/' + encodeURIComponent(t.dataset.id)); showToast('Location deleted'); loadLocations(); } },
        'toggle-form': t => document.getElementById(t.dataset.target).classList.toggle('hidden'),
        'copy': async () => { try { await navigator.clipboard.writeText(lastGenerated); showToast('Copied to clipboard'); } catch (e) { showToast('Copy failed', 'error'); } },
        'logout': async () => { try { await apiRequest('POST', '/auth/logout', {}); } finally { window.location.reload(); } },
    };

    document.addEventListener('click', async e => {
        const nav = e.target.closest('.nav-item');
        if (nav) { e.preventDefault(); switchView(nav.dataset.view, true); return; }
        const go = e.target.closest('[data-goto]');
        if (go) { switchView(go.dataset.goto, true); return; }
        const t = e.target.closest('[data-action]');
        if (!t || !clickActions[t.dataset.action]) return;
        try { await withPending(null, () => clickActions[t.dataset.action](t)); } catch (err) { showToast(err.message, 'error'); }
    });

    window.addEventListener('popstate', () => switchView(viewFromLocation(), false));
    switchView(viewFromLocation(), false);
})();
