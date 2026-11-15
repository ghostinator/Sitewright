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

    const smallButton = (label, action, id, cls) => el('button', { class: 'btn btn-sm ' + (cls || 'btn-secondary'), text: label, attrs: { type: 'button' }, data: { action, id } });

    function renderUsers(container, users, meId) {
        if (!users.length) return setContent(container, muted('No users.'));
        setContent(container, ...users.map(u => {
            const me = u.id === meId;
            const roleSel = el('select', { attrs: { 'aria-label': 'Role of ' + u.username }, data: { change: 'user-role', id: u.id } },
                ...['viewer', 'editor', 'admin'].map(r => { const o = el('option', { text: r, attrs: { value: r } }); if (r === u.role) o.selected = true; return o; }));
            if (me) roleSel.disabled = true;
            return listItem([
                el('strong', { text: u.username }), me ? ' (you) ' : ' ', el('span', { class: 'badge', text: u.role }),
                u.disabled ? el('span', { class: 'badge', text: 'disabled' }) : null, u.mfa ? el('span', { class: 'badge', text: '2-step on' }) : null,
                el('br'), el('small', { class: 'text-muted', text: u.email + ' | created ' + u.created_at })],
                [roleSel, me ? null : smallButton(u.disabled ? 'Enable' : 'Disable', 'user-toggle', u.id),
                 u.mfa && !me ? smallButton('Reset 2-step', 'user-reset-mfa', u.id) : null,
                 me ? null : smallButton('Send reset link', 'user-send-reset', u.id),
                 me ? null : deleteButton('delete-user', u.id)]);
        }));
    }

    function renderSites(container, sites) {
        if (!sites.length) return setContent(container, muted('No sites connected yet.'));
        setContent(container, ...sites.map(s => listItem([
            el('strong', { text: s.name }), ' ', el('span', { class: 'badge', text: s.last_status }), el('br'),
            el('small', { class: 'text-muted break-all', text: s.base_url + ' | last sync: ' + (s.last_sync_at || 'never') + (s.plugin_version ? ' | plugin ' + s.plugin_version : '') }),
            s.last_error ? el('div', { class: 'alert alert-error', text: s.last_error }) : null],
            [smallButton('Sync now', 'site-sync', s.id), smallButton('New secret', 'site-rotate', s.id), deleteButton('delete-site', s.id)])));
    }

    /** Search Console panel. state = {configured, redirect_uri, connections:[...]}; properties = {id: [{site_url}]} for connections being set up. */
    function renderGsc(container, state, properties) {
        const nodes = [];
        if (!state.configured) {
            nodes.push(el('div', { class: 'alert' }, 'Not configured yet. Create an OAuth client (type "Web application") in the Google Cloud console, add ', el('code', { text: state.redirect_uri || 'https://YOUR-HOST/api/gsc/callback' }),
                ' as an authorized redirect URI, then store the client with ', el('code', { text: 'sudo sw-set-secrets google' }), '. The redirect happens in your browser, so the server does not need to be reachable from the internet.'));
        }
        for (const c of state.connections) {
            const props = properties && properties[c.id];
            const row = [el('strong', { text: c.property_url || 'No property chosen' }), ' ', el('span', { class: 'badge', text: c.last_status }), el('br'),
                el('small', { class: 'text-muted', text: 'last sync: ' + (c.last_sync_at || 'never') + (c.rows_synced ? ' | ' + c.rows_synced + ' rows' : '') })];
            if (c.last_error) row.push(el('div', { class: 'alert alert-error', text: c.last_error }));
            const actions = [smallButton(c.property_url ? 'Change property' : 'Choose property', 'gsc-properties', c.id)];
            if (c.property_url) actions.push(smallButton('Sync now', 'gsc-sync', c.id));
            actions.push(smallButton('Disconnect', 'gsc-disconnect', c.id, 'btn-danger'));
            nodes.push(listItem(row, actions));
            if (props) {
                const sel = el('select', { attrs: { id: 'gsc-prop-' + c.id, 'aria-label': 'Search Console property' } }, ...props.map(p => el('option', { text: p.site_url + ' (' + p.permission + ')', attrs: { value: p.site_url } })));
                nodes.push(el('div', { class: 'form-inline' }, sel, smallButton('Use this property', 'gsc-set-property', c.id, 'btn-primary')));
            }
        }
        if (state.configured) nodes.push(el('p', {}, smallButton(state.connections.length ? 'Connect another Google account' : 'Connect Google Search Console', 'gsc-connect', 0, 'btn-primary')));
        setContent(container, el('h4', { text: 'Google Search Console' }), ...nodes);
    }

    function renderAudit(container, rows, append) {
        const items = rows.map(r => listItem([
            el('strong', { text: r.action }), ' ', el('span', { class: 'text-muted', text: (r.username || '-') + ' · ' + r.ip }), el('br'),
            el('small', { class: 'text-muted break-all', text: r.created_at + (r.detail ? ' · ' + r.detail : '') })], []));
        if (append) { container.append(...items); return; }
        setContent(container, ...(items.length ? items : [muted('No entries.')]));
    }

    function renderLocations(container, locs) {
        if (!locs.length) return setContent(container, muted('No locations added yet.'));
        setContent(container, ...locs.map(l => listItem([
            el('strong', { text: l.name }), sampleBadge(l), el('br'),
            el('small', { class: 'text-muted', text: [l.address, l.city, l.state, l.postal_code, l.country].filter(Boolean).join(', ') + (l.latitude !== null && l.latitude !== undefined ? ' | ' + l.latitude + ', ' + l.longitude : '') })],
            [smallButton('Edit', 'edit-location', l.id), smallButton('JSON-LD', 'location-schema', l.id), deleteButton('delete-location', l.id)])));
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

    const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    let locationsById = {};

    function resetLocationForm(form) {
        form.reset(); form.elements.loc_id.value = '';
        document.getElementById('loc-form-title').textContent = 'New location';
        document.getElementById('loc-cancel').classList.add('hidden');
    }

    /** Fill the form from a stored location (all values via .value: never HTML). */
    function editLocation(l) {
        const form = document.getElementById('location-form');
        resetLocationForm(form);
        form.elements.loc_id.value = String(l.id);
        for (const f of ['name', 'phone', 'address', 'city', 'state', 'postal_code', 'country', 'email', 'website', 'latitude', 'longitude']) { form.elements[f].value = l[f] === null || l[f] === undefined ? '' : String(l[f]); }
        const hours = l.business_hours || {};
        for (const d of DAYS) { form.elements['open_' + d].value = hours[d] ? hours[d].open : ''; form.elements['close_' + d].value = hours[d] ? hours[d].close : ''; form.elements['closed_' + d].checked = !hours[d]; }
        document.getElementById('loc-form-title').textContent = 'Edit ' + l.name;
        document.getElementById('loc-cancel').classList.remove('hidden');
        form.classList.remove('hidden'); if (form.scrollIntoView) form.scrollIntoView({ block: 'nearest' });
    }

    /** {monday:{open,close},...} from the form; days that are empty or ticked "closed" are left out. null when no day has hours. */
    function hoursFromForm(form) {
        const out = {};
        for (const d of DAYS) {
            const o = form.elements['open_' + d].value, c = form.elements['close_' + d].value;
            if (!form.elements['closed_' + d].checked && o && c) out[d] = { open: o, close: c };
        }
        return Object.keys(out).length ? out : null;
    }

    // ------------------------------------------------------------------ test export (no side effects)

    const exported = { el, renderSeoResults, renderContentResult, renderImageResults, renderAnalytics, render404Logs, renderRedirections, renderLocations, renderSitemapResult, renderDashboard, renderUsers, renderAudit, renderSites, renderGsc, hoursFromForm, editLocation };
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
        if (res.status === 401 && path !== '/auth/login' && path !== '/auth/mfa' && path !== '/auth/reset' && path !== '/auth/forgot') { window.location.reload(); throw new ApiError('Session expired', 401); }
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

    const resetForm = document.getElementById('reset-form');
    if (resetForm) {
        const token = new URLSearchParams(window.location.search).get('token') || '';
        resetForm.addEventListener('submit', async e => {
            e.preventDefault();
            const err = document.getElementById('reset-error'); err.classList.add('hidden');
            await withPending(resetForm, async () => {
                try {
                    await apiRequest('POST', '/auth/reset', { token, password: resetForm.elements.password.value });
                    resetForm.elements.password.value = '';
                    const done = document.getElementById('reset-done'); done.textContent = 'Password changed. You can sign in now.'; done.classList.remove('hidden');
                } catch (ex) { err.textContent = ex.message; err.classList.remove('hidden'); }
            });
        });
        return;
    }

    const loginForm = document.getElementById('login-form');
    if (loginForm) {
        let awaitingCode = false;
        const field = n => loginForm.elements[n];
        const forgotForm = document.getElementById('forgot-form');
        if (forgotForm) {
            document.getElementById('forgot-toggle').addEventListener('click', () => { loginForm.classList.add('hidden'); forgotForm.classList.remove('hidden'); });
            document.getElementById('forgot-back').addEventListener('click', () => { forgotForm.classList.add('hidden'); loginForm.classList.remove('hidden'); });
            forgotForm.addEventListener('submit', async e => {
                e.preventDefault();
                const msg = document.getElementById('forgot-msg');
                await withPending(forgotForm, async () => {
                    try { await apiRequest('POST', '/auth/forgot', { email: forgotForm.elements.email.value }); msg.textContent = 'If that address belongs to an account, a reset link has been sent.'; }
                    catch (ex) { msg.textContent = ex.message; }
                    msg.classList.remove('hidden');
                });
            });
        }
        loginForm.addEventListener('submit', async e => {
            e.preventDefault();
            const err = document.getElementById('login-error');
            err.classList.add('hidden');
            await withPending(loginForm, async () => {
                try {
                    if (!awaitingCode) {
                        const r = await apiRequest('POST', '/auth/login', { username: field('username').value, password: field('password').value });
                        if (r && r.mfa_required) {
                            // Second step: hide the credentials, ask for the code.
                            awaitingCode = true;
                            field('username').closest('.form-group').classList.add('hidden');
                            field('password').closest('.form-group').classList.add('hidden');
                            field('password').value = '';
                            field('username').required = false; field('password').required = false; field('code').required = true;   // hidden required fields would make the browser block the submit
                            document.getElementById('login-mfa').classList.remove('hidden');
                            field('code').focus();
                            return;
                        }
                    } else {
                        await apiRequest('POST', '/auth/mfa', { code: field('code').value });
                    }
                    window.location.reload();
                } catch (ex) {
                    err.textContent = ex.message; err.classList.remove('hidden');
                    if (awaitingCode && ex.status === 401 && /sign in again/i.test(ex.message)) { window.location.reload(); }
                }
            });
        });
        return;
    }

    // ------------------------------------------------------------------ navigation

    const TITLES = { dashboard: 'Dashboard', 'seo-analysis': 'SEO Analysis', competitor: 'Competitor Analysis', analytics: 'Analytics', 'content-ai': 'Content AI',
        'local-seo': 'Local SEO', 'image-seo': 'Image SEO', sitemaps: 'Sitemaps', '404-monitor': '404 Log', redirections: 'Redirect Rules',
        account: 'My Account', sites: 'Sites', users: 'Users', audit: 'Audit Log' };
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
        const loaders = { dashboard: loadDashboard, analytics: () => { loadAnalytics(); loadGsc(); }, 'content-ai': loadAiStatus, 'local-seo': loadLocations, '404-monitor': load404, redirections: loadRedirections, sitemaps: loadSitemaps, account: loadAccount, sites: loadSites, users: loadUsers, audit: () => loadAudit(false) };
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
    // The site selector (404 log and redirect rules) scopes data to one connected site; 0 = everything / not tied to a site.
    let siteId = 0;
    async function loadSiteOptions() {
        let sites = [];
        try { sites = await apiRequest('GET', '/sites/options'); } catch (e) { /* viewers cannot list sites: selector stays on "All sites" */ }
        document.querySelectorAll('.site-select').forEach(sel => {
            sel.replaceChildren(el('option', { text: 'All sites', attrs: { value: '0' } }), ...sites.map(s => el('option', { text: s.name, attrs: { value: String(s.id) } })));
            sel.value = String(siteId);
        });
    }
    const siteQuery = sep => (siteId > 0 ? sep + 'project_id=' + siteId : '');
    function syncExportLinks() {
        const e404 = document.getElementById('export-404'); if (e404) e404.href = BASE + '/api/404-monitor/export' + siteQuery('?');
        document.querySelectorAll('.export-rules').forEach(a => { a.href = BASE + '/api/redirections/export?format=' + a.dataset.format + siteQuery('&'); });
    }
    const load404 = () => { syncExportLinks(); return loadInto('404-logs', '/404-monitor/logs?per_page=100' + siteQuery('&'), render404Logs, '404 log'); };
    const loadRedirections = () => { syncExportLinks(); return loadInto('redirections-list', '/redirections' + siteQuery('?'), renderRedirections, 'redirect rules'); };
    const loadLocations = () => loadInto('locations-list', '/local-seo/locations', (box, locs) => { locationsById = {}; locs.forEach(l => { locationsById[l.id] = l; }); renderLocations(box, locs); }, 'locations');
    const gscProps = {};
    async function loadGsc() {
        const box = document.getElementById('gsc-box');
        if (cfg.user && cfg.user.role !== 'admin') { setContent(box, muted('Ask an administrator to connect Google Search Console.')); return; }
        try { renderGsc(box, await apiRequest('GET', '/gsc'), gscProps); } catch (e) { showError(box, 'Could not load Search Console: ' + e.message); }
    }
    const loadSites = () => loadInto('sites-list', '/sites', (box, d) => renderSites(box, d.sites), 'sites');
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

    // ------------------------------------------------------------------ accounts

    async function loadUsers() {
        const me = await apiRequest('GET', '/auth/me');
        loadInto('users-list', '/users', (box, users) => renderUsers(box, users, me.user.id), 'users');
    }

    let auditBefore = null;
    async function loadAudit(more) {
        const box = document.getElementById('audit-list');
        const filter = document.getElementById('audit-action').value.trim();
        const q = '/audit?limit=100' + (filter ? '&action=' + encodeURIComponent(filter) : '') + (more && auditBefore ? '&before_id=' + auditBefore : '');
        try {
            const d = await apiRequest('GET', q);
            renderAudit(box, d.rows, more);
            auditBefore = d.next_before_id;
            document.getElementById('audit-more').classList.toggle('hidden', !d.next_before_id);
        } catch (e) { showError(box, 'Could not load the audit log: ' + e.message); }
    }

    async function loadAccount() {
        try {
            const me = await apiRequest('GET', '/auth/me');
            document.getElementById('mfa-state').textContent = me.mfa_enabled ? 'Two-step verification is ON for your account.' : 'Two-step verification is OFF. Turn it on to protect your account.';
            document.getElementById('mfa-setup-form').classList.toggle('hidden', me.mfa_enabled);
            document.getElementById('mfa-disable-form').classList.toggle('hidden', !me.mfa_enabled);
            document.getElementById('mfa-secret').classList.add('hidden');
            if (me.csrf_token) csrf = me.csrf_token;
        } catch (e) { document.getElementById('mfa-state').textContent = 'Could not load: ' + e.message; }
    }

    // ------------------------------------------------------------------ background jobs

    /** Submit a job (202 + job_id), then poll its status until it finishes. Resolves with the job's result data. */
    async function runJob(path, body, box, label) {
        const { job_id: id } = await apiRequest('POST', path, body);
        const started = Date.now();
        for (;;) {
            await new Promise(r => setTimeout(r, Math.min(4000, 800 + (Date.now() - started) / 10)));
            const job = await apiRequest('GET', '/jobs/' + encodeURIComponent(id));
            if (job.status === 'done') return job.result.data;
            if (job.status === 'failed') throw new ApiError(job.error || 'The job failed', job.error_status || 500);
            if (Date.now() - started > 15 * 60 * 1000) throw new ApiError('Still running; check back later (job #' + id + ')', 504);
            setContent(box, muted(label + (job.status === 'queued' ? ' (waiting for a worker…)' : ' (working…)')));
        }
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
            try { renderImageResults(box, await runJob('/image-seo/bulk-analyze', { url: form.querySelector('#image-url').value }, box, 'Analyzing images')); } catch (e) { showError(box, e.message); }
        },
        'sitemap-crawl-form': async form => {
            const box = document.getElementById('sitemap-results');
            setContent(box, muted('Crawling… this can take a couple of minutes.'));
            try {
                renderSitemapResult(box, await runJob('/sitemap/crawl', { base_url: form.querySelector('#sitemap-url').value, max_pages: 100 }, box, 'Crawling'), BASE + '/api/sitemap/generate-xml');
                loadSitemaps();
            } catch (e) { showError(box, e.message); }
        },
        'location-form': async form => {
            const id = form.elements.loc_id.value;
            const text = ['name', 'phone', 'address', 'city', 'state', 'postal_code', 'country', 'email', 'website'];
            const body = {};
            for (const f of text) { const v = form.elements[f].value.trim(); if (id) body[f] = v === '' ? null : v; else if (v !== '') body[f] = v; }
            const lat = form.elements.latitude.value.trim(), lng = form.elements.longitude.value.trim();
            if (id) { body.latitude = lat === '' ? null : lat; body.longitude = lng === '' ? null : lng; } else { if (lat !== '') body.latitude = lat; if (lng !== '') body.longitude = lng; }
            const hours = hoursFromForm(form);
            if (id || hours) body.business_hours = hours;
            try {
                if (id) await apiRequest('PUT', '/local-seo/locations/' + encodeURIComponent(id), body); else await apiRequest('POST', '/local-seo/locations', body);
                resetLocationForm(form); form.classList.add('hidden'); showToast(id ? 'Location updated' : 'Location added'); loadLocations();
            } catch (e) { showToast(e.message, 'error'); }
        },
        'redirection-form': async form => {
            try {
                await apiRequest('POST', '/redirections', { source_url: form.querySelector('#redir-source').value, target_url: form.querySelector('#redir-target').value, redirect_type: form.querySelector('#redir-type').value, project_id: siteId });
                form.reset(); form.classList.add('hidden'); showToast('Rule saved'); loadRedirections();
            } catch (e) { showToast(e.message, 'error'); }
        },
        'site-create-form': async form => {
            try {
                const d = await apiRequest('POST', '/sites', { name: form.querySelector('#site-name').value.trim(), base_url: form.querySelector('#site-url').value.trim() });
                form.reset(); form.classList.add('hidden');
                const box = document.getElementById('site-secret');
                setContent(box, el('div', { class: 'alert alert-error', text: 'Copy this secret into the plugin now. It is not shown again.' }), el('pre', { class: 'break-all', text: d.secret }));
                box.classList.remove('hidden'); loadSites(); loadSiteOptions();
            } catch (e) { showToast(e.message, 'error'); }
        },
        'user-create-form': async form => {
            const body = { username: form.querySelector('#nu-username').value.trim(), email: form.querySelector('#nu-email').value.trim(), role: form.querySelector('#nu-role').value };
            const pw = form.querySelector('#nu-password').value; if (pw) body.password = pw;
            try { const r = await apiRequest('POST', '/users', body); form.reset(); form.classList.add('hidden'); showToast(pw ? 'User created' : 'Invitation sent'); loadUsers(); return r; } catch (e) { showToast(e.message, 'error'); }
        },
        'password-form': async form => {
            try {
                const d = await apiRequest('POST', '/auth/password', { current_password: form.querySelector('#pw-current').value, new_password: form.querySelector('#pw-new').value });
                if (d && d.csrf_token) csrf = d.csrf_token;
                form.reset(); showToast('Password changed');
            } catch (e) { showToast(e.message, 'error'); }
        },
        'mfa-setup-form': async form => {
            try {
                const d = await apiRequest('POST', '/auth/mfa/setup', { password: form.querySelector('#mfa-setup-pw').value });
                form.reset(); form.classList.add('hidden');
                document.getElementById('mfa-secret-text').textContent = d.secret;
                document.getElementById('mfa-uri').textContent = d.uri;
                document.getElementById('mfa-secret').classList.remove('hidden');
            } catch (e) { showToast(e.message, 'error'); }
        },
        'mfa-enable-form': async form => {
            try {
                const d = await apiRequest('POST', '/auth/mfa/enable', { code: form.querySelector('#mfa-code').value });
                if (d.csrf_token) csrf = d.csrf_token;
                form.reset(); document.getElementById('mfa-secret').classList.add('hidden');
                const box = document.getElementById('mfa-codes');
                setContent(box, el('div', { class: 'alert alert-error', text: 'Save these recovery codes now. Each works once and they are not shown again.' }),
                    el('pre', { text: d.recovery_codes.join('\n') }));
                box.classList.remove('hidden');
                document.getElementById('mfa-state').textContent = 'Two-step verification is ON for your account.';
                document.getElementById('mfa-disable-form').classList.remove('hidden');
            } catch (e) { showToast(e.message, 'error'); }
        },
        'mfa-disable-form': async form => {
            if (!confirm('Turn off two-step verification?')) return;
            try {
                const d = await apiRequest('POST', '/auth/mfa/disable', { password: form.querySelector('#mfa-disable-pw').value });
                if (d.csrf_token) csrf = d.csrf_token;
                form.reset(); document.getElementById('mfa-codes').classList.add('hidden'); showToast('Two-step verification is off'); loadAccount();
            } catch (e) { showToast(e.message, 'error'); }
        },
        'log404-form': async form => {
            try { await apiRequest('POST', '/404-monitor/log', { uri: form.querySelector('#log404-uri').value, project_id: siteId }); form.reset(); showToast('Entry added'); load404(); } catch (e) { showToast(e.message, 'error'); }
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

    document.addEventListener('change', async e => {
        const ss = e.target.closest('[data-site-select]');
        if (ss) { siteId = Number(ss.value) || 0; document.querySelectorAll('.site-select').forEach(x => { x.value = String(siteId); }); if (ss.dataset.siteSelect === '404') load404(); else loadRedirections(); return; }
        const t = e.target.closest('[data-change="user-role"]');
        if (!t) return;
        try { await apiRequest('PATCH', '/users/' + encodeURIComponent(t.dataset.id), { role: t.value }); showToast('Role updated'); } catch (err) { showToast(err.message, 'error'); loadUsers(); }
    });

    // ------------------------------------------------------------------ delegated clicks

    const clickActions = {
        'delete-404': async t => { if (confirm('Delete this log entry?')) { await apiRequest('DELETE', '/404-monitor/' + encodeURIComponent(t.dataset.id)); showToast('Entry deleted'); load404(); } },
        'clear-404': async () => { if (confirm(siteId > 0 ? 'Clear all 404 entries of the selected site? This cannot be undone.' : 'Clear ALL 404 log entries of ALL sites? This cannot be undone.')) { await apiRequest('POST', '/404-monitor/clear', { confirm: true, project_id: siteId }); showToast('Log cleared'); load404(); } },
        'refresh-404': () => load404(),
        'delete-redirection': async t => { if (confirm('Delete this rule?')) { await apiRequest('DELETE', '/redirections/' + encodeURIComponent(t.dataset.id)); showToast('Rule deleted'); loadRedirections(); } },
        'edit-location': t => { const l = locationsById[t.dataset.id]; if (l) editLocation(l); },
        'loc-cancel': () => { const f = document.getElementById('location-form'); resetLocationForm(f); f.classList.add('hidden'); },
        'location-schema': async t => {
            const d = await apiRequest('GET', '/local-seo/locations/' + encodeURIComponent(t.dataset.id) + '/schema');
            const box = document.getElementById('loc-schema');
            box.textContent = '<script type="application/ld+json">\n' + JSON.stringify(d.schema || d, null, 2) + '\n<\/script>';
            box.classList.remove('hidden');
        },
        'delete-location': async t => { if (confirm('Delete this location?')) { await apiRequest('DELETE', '/local-seo/locations/' + encodeURIComponent(t.dataset.id)); showToast('Location deleted'); loadLocations(); } },
        'site-sync': async t => { const d = await apiRequest('POST', '/sites/' + encodeURIComponent(t.dataset.id) + '/sync', {}); showToast('Sync started'); await new Promise(r => setTimeout(r, 2500)); loadSites(); return d; },
        'site-rotate': async t => { if (confirm('Create a new secret? Syncing stops until the plugin has it.')) { const d = await apiRequest('POST', '/sites/' + encodeURIComponent(t.dataset.id) + '/rotate-secret', {}); const box = document.getElementById('site-secret'); setContent(box, el('div', { class: 'alert alert-error', text: 'New secret (shown once): put it into the plugin.' }), el('pre', { class: 'break-all', text: d.secret })); box.classList.remove('hidden'); loadSites(); } },
        'delete-site': async t => { if (confirm('Remove this site from Sitewright? Its rules and 404 entries are kept.')) { await apiRequest('DELETE', '/sites/' + encodeURIComponent(t.dataset.id)); showToast('Site removed'); loadSites(); loadSiteOptions(); } },
        'gsc-connect': async () => { const d = await apiRequest('POST', '/gsc/connect', {}); window.location.assign(d.url); },
        'gsc-properties': async t => { gscProps[t.dataset.id] = await apiRequest('GET', '/gsc/' + encodeURIComponent(t.dataset.id) + '/properties'); loadGsc(); },
        'gsc-set-property': async t => { const v = document.getElementById('gsc-prop-' + t.dataset.id).value; await apiRequest('POST', '/gsc/' + encodeURIComponent(t.dataset.id) + '/property', { site_url: v }); delete gscProps[t.dataset.id]; showToast('Property saved'); loadGsc(); },
        'gsc-sync': async t => { await apiRequest('POST', '/gsc/' + encodeURIComponent(t.dataset.id) + '/sync', {}); showToast('Sync started; refresh in a minute'); },
        'gsc-disconnect': async t => { if (confirm('Disconnect Google Search Console? Imported keyword data is kept.')) { await apiRequest('DELETE', '/gsc/' + encodeURIComponent(t.dataset.id)); showToast('Disconnected'); loadGsc(); } },
        'delete-user': async t => { if (confirm('Delete this user permanently?')) { await apiRequest('DELETE', '/users/' + encodeURIComponent(t.dataset.id)); showToast('User deleted'); loadUsers(); } },
        'user-toggle': async t => { const disabling = t.textContent === 'Disable'; if (!disabling || confirm('Disable this account? Their sessions end immediately.')) { await apiRequest('PATCH', '/users/' + encodeURIComponent(t.dataset.id), { disabled: disabling }); showToast(disabling ? 'Disabled' : 'Enabled'); loadUsers(); } },
        'user-reset-mfa': async t => { if (confirm('Remove two-step verification from this account?')) { await apiRequest('POST', '/users/' + encodeURIComponent(t.dataset.id) + '/reset-mfa', {}); showToast('Two-step verification removed'); loadUsers(); } },
        'user-send-reset': async t => { await apiRequest('POST', '/users/' + encodeURIComponent(t.dataset.id) + '/send-reset', {}); showToast('Reset e-mail queued'); },
        'audit-refresh': () => loadAudit(false),
        'audit-more': () => loadAudit(true),
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

    const gscResult = new URLSearchParams(window.location.search).get('gsc');
    if (gscResult) { showToast(gscResult === 'connected' ? 'Google Search Console connected. Choose a property next.' : 'Could not connect Google Search Console. Try again.', gscResult === 'connected' ? 'success' : 'error'); history.replaceState({}, '', window.location.pathname); }
    loadSiteOptions();
    window.addEventListener('popstate', () => switchView(viewFromLocation(), false));
    switchView(viewFromLocation(), false);
})();
