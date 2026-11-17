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
    /** A check result labelled in words ("Pass" / "Fix"), so it never relies on colour or emoji alone. */
    const checkTag = ok => el('span', { class: 'check ' + (ok ? 'check-ok' : 'check-warn'), text: ok ? 'Pass' : 'Fix' });
    const check = (ok, textIfOk, textIfNot) => el('p', { class: 'text-muted' }, checkTag(ok), ' ' + (ok ? textIfOk : textIfNot));
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

    function renderSeoResults(container, data, opts) {
        const r = data.results;
        const score = Number(data.score);
        const cls = score >= 80 ? 'good' : score >= 50 ? 'average' : 'poor';
        const yn = v => v ? 'Yes' : 'No';
        setContent(container, el('div', { class: 'result-card' },
            el('h3', { class: 'text-center', text: 'SEO Score' }),
            el('div', { class: 'result-score ' + cls, text: score + '/100' }),
            el('p', { class: 'text-muted text-center break-all', text: 'Analyzed: ' + data.url + ' (HTTP ' + r.fetch.status_code + ', ' + r.fetch.redirect_count + ' redirect(s))' }),
            section('Title', resultRow(r.basic.title || 'No title found', r.basic.title_length + ' chars'),
                check(r.basic.title_optimal, 'Title length is optimal', 'Title should be 30-60 characters')),
            section('Meta Description', resultRow(r.meta.description || 'No description found', r.meta.description_length + ' chars'),
                check(r.meta.description_optimal, 'Description length is optimal', 'Description should be 120-160 characters')),
            section('Headings', resultRow('H1 tags', r.headings.h1_count),
                ...(r.headings.headings.h1 || []).slice(0, 5).map(h => resultRow('H1', h)),
                check(r.headings.h1_optimal, 'Exactly one H1 tag', 'Should have exactly one H1 tag')),
            section('Images', resultRow('Total images', r.images.total_images),
                resultRow('With alt text', r.images.images_with_alt + ' (' + r.images.alt_ratio + '%)'),
                check(r.images.alt_ratio >= 90, 'Great alt text coverage', 'Add alt text to all images')),
            section('Links', resultRow('Internal', r.links.internal_links), resultRow('External', r.links.external_links), resultRow('nofollow', r.links.nofollow_links)),
            section('Fetch time', resultRow('HTML document fetched in', r.performance.load_time + 's'), muted(r.performance.note)),
            section('Basics', resultRow('HTTPS (final URL)', yn(r.security.uses_https)), resultRow('Viewport meta tag present', yn(r.viewport.has_viewport)),
                resultRow('Structured data (JSON-LD)', yn(r.structured_data.has_schema)), resultRow('Word count', r.basic.word_count),
                resultRow('Canonical', r.basic.canonical || 'none'), resultRow('Language', r.basic.lang || 'not set'), resultRow('noindex', yn(r.basic.noindex))),
            muted('Heuristic checks based on one server-side fetch.'),
            opts && opts.canWatch ? el('p', {}, el('button', { class: 'btn btn-secondary', text: 'Watch this page', attrs: { type: 'button' }, data: { action: 'watch-add', url: data.url } }),
                ' ', el('small', { class: 'text-muted', text: 'Re-checks it on a schedule and alerts you if it gets worse or goes down.' })) : null));
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
            el('small', { class: 'text-muted' }, checkTag(img.has_alt), ' ' + (img.has_alt ? 'Has alt text' : 'Missing alt text') + ' | Size: ' + fmtKB(img.size) + ' | ' + (img.dimensions || 'unknown dimensions')
                + ' | Optimized: ' + (img.optimized === null ? 'unknown' : (img.optimized ? 'yes' : 'no')))]));
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
            el('strong', { text: s.name }), ' ', el('span', { class: 'badge', text: Number(s.sync_enabled) === 0 ? 'no plugin' : s.last_status }), el('br'),
            el('small', { class: 'text-muted break-all', text: Number(s.sync_enabled) === 0 ? s.base_url + ' | analysed and monitored from here, no plugin'
                : s.base_url + ' | last sync: ' + (s.last_sync_at || 'never') + (s.plugin_version ? ' | plugin ' + s.plugin_version : '') }),
            s.last_error ? el('div', { class: 'alert alert-error', text: s.last_error }) : null],
            (Number(s.sync_enabled) === 0 ? [] : [smallButton('Sync now', 'site-sync', s.id), smallButton('New secret', 'site-rotate', s.id)]).concat([deleteButton('delete-site', s.id)]))));
    }

    const SVG_NS = 'http://www.w3.org/2000/svg';
    /** Small score trend (values 0-100, oldest first). Fewer than two points draws nothing. */
    function sparkline(values) {
        const v = (values || []).filter(x => x !== null && x !== undefined).map(Number);
        if (v.length < 2) return null;
        const W = 80, H = 22, step = W / (v.length - 1);
        const svg = document.createElementNS(SVG_NS, 'svg');
        for (const [k, val] of Object.entries({ class: 'spark', viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img', 'aria-label': 'Score trend: ' + v.join(', ') })) svg.setAttribute(k, String(val));
        const line = document.createElementNS(SVG_NS, 'polyline');
        line.setAttribute('points', v.map((x, i) => (i * step).toFixed(1) + ',' + (H - 1 - (Math.max(0, Math.min(100, x)) / 100) * (H - 2)).toFixed(1)).join(' '));
        svg.append(line);
        return svg;
    }
    const everyText = h => h === 24 ? 'daily' : h === 168 ? 'weekly' : 'every ' + h + (h === 1 ? ' hour' : ' hours');

    /** Watchlist table. canEdit: editors and admins may run, pause and remove. */
    function renderWatchlist(container, data, canEdit) {
        const items = data.items || [];
        if (!items.length) return setContent(container, muted('Nothing is watched yet. Add a page above, or press "Watch this page" on an analysis result.'));
        const status = w => !w.enabled ? el('span', { class: 'badge', text: 'Paused' })
            : w.last_status === 'down' ? el('span', { class: 'badge badge-bad', text: 'Down' })
            : w.last_status === 'new' ? el('span', { class: 'badge', text: 'Waiting for the first check' }) : el('span', { class: 'badge badge-ok', text: 'OK' });
        const head = el('thead', {}, el('tr', {}, ...['Page', 'Status', 'Score', 'Last check', 'Actions'].map(h => el('th', { text: h, attrs: { scope: 'col' } }))));
        const body = el('tbody', {}, ...items.map(w => {
            const actions = [smallButton('History', 'watch-history', w.id)];
            if (canEdit) actions.push(smallButton('Check now', 'watch-run', w.id), smallButton(w.enabled ? 'Pause' : 'Resume', 'watch-toggle', w.id), smallButton('Remove', 'delete-watch', w.id, 'btn-danger'));
            actions.forEach(a => { if (a.dataset.action === 'watch-toggle') a.dataset.enabled = w.enabled ? '1' : '0'; });
            return el('tr', {},
                el('td', {}, el('strong', { text: w.label || w.url }), el('br'), el('small', { class: 'text-muted break-all', text: (w.label ? w.url + ' | ' : '') + (w.site ? w.site + ' | ' : '') + everyText(w.interval_hours) })),
                el('td', {}, status(w), w.last_status === 'down' && w.last_error ? el('div', { class: 'text-muted', text: w.last_error }) : null),
                el('td', {}, w.last_score === null || w.last_score === undefined ? '–' : String(w.last_score), ' ', sparkline(w.trend)),
                el('td', { text: w.last_run_at || 'never' }),
                el('td', { class: 'row-actions' }, ...actions.flatMap((a, i) => i ? [' ', a] : [a])));
        }));
        setContent(container, el('div', { class: 'table-wrap' }, el('table', { class: 'data-table' }, head, body)));
    }

    function renderWatchHistory(container, data) {
        const pts = (data.points || []).slice().reverse();
        if (!pts.length) return setContent(container, muted('No checks yet.'));
        const head = el('thead', {}, el('tr', {}, ...['Checked', 'Score', 'Fetch time', 'Words', 'Notes'].map(h => el('th', { text: h, attrs: { scope: 'col' } }))));
        const body = el('tbody', {}, ...pts.map(p => el('tr', {}, el('td', { text: p.at }), el('td', { text: p.score === null ? '–' : String(p.score) }),
            el('td', { text: p.load_time === null || p.load_time === undefined ? '–' : p.load_time + 's' }), el('td', { text: p.word_count === null || p.word_count === undefined ? '–' : fmtInt(p.word_count) }),
            el('td', { text: p.noindex ? 'noindex' : '' }))));
        setContent(container, el('div', { class: 'table-wrap' }, el('table', { class: 'data-table' }, head, body)));
    }

    const sevBadge = sev => el('span', { class: 'badge ' + (sev === 'high' ? 'badge-bad' : ''), text: sev.charAt(0).toUpperCase() + sev.slice(1) });
    const tableOf = (heads, rows) => el('div', { class: 'table-wrap' }, el('table', { class: 'data-table' },
        el('thead', {}, el('tr', {}, ...heads.map(h => el('th', { text: h, attrs: { scope: 'col' } })))), el('tbody', {}, ...rows)));

    /** Audit history. A running audit has nothing to view yet; a failed one shows why. */
    function renderSiteAudits(container, data, canEdit) {
        const audits = data.audits || [];
        if (!audits.length) return setContent(container, muted('No audits yet. Run one above.'));
        const rows = audits.map(a => {
            const sev = a.by_severity;
            const state = a.status === 'done' ? (sev && (sev.high + sev.medium + sev.low) === 0 ? [el('span', { class: 'badge badge-ok', text: 'No issues' })]
                    : sev ? [el('span', { class: 'badge badge-bad', text: sev.high + ' high' }), ' ', el('span', { class: 'badge', text: sev.medium + ' medium' }), ' ', el('span', { class: 'badge', text: sev.low + ' low' })] : [])
                : a.status === 'failed' ? [el('span', { class: 'badge badge-bad', text: 'Failed' }), el('div', { class: 'text-muted', text: a.error || '' })]
                : [el('span', { class: 'badge', text: a.status === 'queued' ? 'Waiting for a worker' : 'Running…' })];
            const change = a.status === 'done' && a.new !== null && a.new !== undefined ? el('div', { class: 'text-muted', text: a.new + ' new, ' + a.fixed + ' fixed since the previous audit' }) : null;
            const actions = [];
            if (a.status === 'done') actions.push(smallButton('View', 'audit-view', a.id));
            if (canEdit && a.status !== 'queued' && a.status !== 'running') actions.push(smallButton('Delete', 'delete-audit', a.id, 'btn-danger'));
            return el('tr', {}, el('td', {}, el('strong', { text: a.site || a.base_url }), el('br'), el('small', { class: 'text-muted break-all', text: (a.site ? a.base_url + ' | ' : '') + a.created_at })),
                el('td', {}, ...state, change), el('td', { text: a.status === 'done' ? String(a.pages) : '–' }), el('td', { text: a.avg_score === null || a.avg_score === undefined ? '–' : String(a.avg_score) }),
                el('td', { class: 'row-actions' }, ...actions.flatMap((x, i) => i ? [' ', x] : [x])));
        });
        setContent(container, tableOf(['Site', 'Result', 'Pages', 'Average score', 'Actions'], rows));
    }

    function renderSiteAuditReport(container, data) {
        const a = data.audit, s = a.summary || {};
        const lines = [el('p', {}, el('strong', { text: a.base_url }), ' ' + a.pages + ' pages checked, average score ' + (s.avg_score === null || s.avg_score === undefined ? '–' : s.avg_score) + '/100.'
            + (s.new !== null && s.new !== undefined ? ' Since the previous audit: ' + s.new + ' new, ' + s.fixed + ' fixed.' : ' This is the first audit of this site, so there is nothing to compare with yet.'))];
        if (Number(a.stopped_early)) lines.push(el('div', { class: 'alert alert-warn', text: 'The audit stopped early at its page or time limit, so some pages were not checked. Raise the limits under Settings → Monitoring to cover more.' }));
        if (s.issues_truncated) lines.push(muted('There were more issues than are stored; the list shows the first 5,000.'));
        const types = data.types || [];
        if (!types.length) { lines.push(el('div', { class: 'alert alert-info', text: 'No issues found.' })); return setContent(container, ...lines); }
        const rows = types.map(t => el('tr', {}, el('td', {}, sevBadge(t.severity)), el('td', {}, el('strong', { text: t.title }), el('br'), el('small', { class: 'text-muted', text: t.advice })), el('td', { text: String(t.count) }),
            el('td', {}, el('button', { class: 'btn btn-secondary btn-sm', text: 'Show pages', attrs: { type: 'button' }, data: { action: 'audit-issues', id: a.id, code: t.code } }))));
        setContent(container, ...lines, tableOf(['Priority', 'Problem', 'Count', ''], rows));
    }

    function renderSiteAuditIssues(container, data) {
        const rows = (data.issues || []).map(i => el('tr', {}, el('td', {}, sevBadge(i.severity)), el('td', { class: 'break-all', text: i.url }), el('td', { text: i.detail || '' })));
        setContent(container, rows.length ? tableOf(['Priority', 'Page', 'Detail'], rows) : muted('Nothing to list.'));
    }

    function renderSiteAuditPages(container, data) {
        const rows = (data.pages || []).map(p => el('tr', {}, el('td', { class: 'break-all' }, p.url, p.title ? el('br') : null, p.title ? el('small', { class: 'text-muted', text: p.title }) : null),
            el('td', { text: p.score === null ? '–' : String(p.score) }), el('td', { text: fmtInt(p.word_count) }), el('td', { text: p.load_ms + ' ms' }), el('td', { text: String(p.inbound) }), el('td', { text: Number(p.noindex) ? 'noindex' : '' })));
        setContent(container, rows.length ? tableOf(['Page (worst first)', 'Score', 'Words', 'Fetch time', 'Linked from', 'Notes'], rows) : muted('No pages.'));
    }

    /** Suggested redirects for the 404 log. Accepting creates a 301 rule; viewers can read but not accept. */
    function renderRedirectSuggestions(container, data, canEdit) {
        if (!data.has_inventory) return setContent(container, muted('No list of your live pages yet. Run a site audit (or crawl a sitemap) for this site, then come back: suggestions are matched against it.'),
            el('p', {}, el('button', { class: 'btn btn-secondary btn-sm', text: 'Go to Site Audit', attrs: { type: 'button' }, data: { goto: 'site-audit' } })));
        const items = data.items || [];
        const more = data.unmatched ? muted(data.unmatched + ' more 404 address' + (data.unmatched === 1 ? ' has' : 'es have') + ' no good match; add a rule for those by hand.') : null;
        if (!items.length) return setContent(container, muted('Nothing to suggest right now.'), more);
        const rows = items.map(i => el('tr', {}, el('td', { class: 'break-all' }, el('strong', { text: i.uri }), el('br'), el('small', { class: 'text-muted', text: fmtInt(i.hits) + ' hit' + (Number(i.hits) === 1 ? '' : 's') })),
            el('td', {}, ...i.suggestions.flatMap(s => [el('div', { class: 'suggestion' },
                canEdit ? el('button', { class: 'btn btn-secondary btn-sm', text: 'Redirect to ' + s.target, attrs: { type: 'button' }, data: { action: 'redirect-accept', uri: i.uri, target: s.target, project: i.project_id } }) : el('span', { class: 'break-all', text: s.target }),
                ' ', el('span', { class: 'badge', text: s.score + '% match' + (s.score < 55 ? ', low confidence' : '') }), ' ', el('small', { class: 'text-muted', text: s.reason }))]))));
        setContent(container, tableOf(['404 address', 'Suggested pages'], rows), more);
    }

    const OPP_LABELS = { ctr_gap: 'Low click-through', striking_distance: 'Within reach of page one', cannibalisation: 'Pages competing', falling: 'Losing clicks' };
    /** Search opportunities: what to fix first, ranked by the clicks it could win back (a rough estimate). */
    function renderOpportunities(container, data) {
        if (!data.has_data) return setContent(container, muted('No Search Console data for this view yet. Connect Search Console on the Analytics page and choose which site each connection belongs to; the first import runs within a day (or press Sync now there).'),
            el('p', {}, el('button', { class: 'btn btn-secondary btn-sm', text: 'Go to Analytics', attrs: { type: 'button' }, data: { goto: 'analytics' } })));
        const items = data.items || [];
        if (!items.length) return setContent(container, muted('Nothing stands out right now. Opportunities need at least the minimum number of impressions set under Settings.'));
        const lead = el('p', {}, el('strong', { text: items.length + ' opportunit' + (items.length === 1 ? 'y' : 'ies') }), ' worth roughly ' + fmtInt((data.summary && data.summary.potential_clicks) || 0) + ' more clicks over 28 days.' + (data.last_sync_at ? ' Data from ' + data.last_sync_at + '.' : ''));
        const rows = items.map(i => el('tr', {},
            el('td', {}, el('span', { class: 'badge' + (i.type === 'falling' ? ' badge-bad' : ''), text: OPP_LABELS[i.type] || i.type })),
            el('td', { class: 'break-all' }, el('strong', { text: i.query }), i.site ? el('small', { class: 'text-muted', text: '  ' + i.site }) : null, i.page ? el('br') : null, i.page ? el('small', { class: 'text-muted', text: i.page }) : null,
                ...(i.other_pages || []).flatMap(p => [el('br'), el('small', { class: 'text-muted', text: 'also: ' + p })]), el('br'), el('small', { class: 'text-muted', text: i.advice })),
            el('td', { text: fmtInt(i.impressions) }), el('td', { text: i.type === 'falling' ? fmtInt(i.previous_clicks) + ' → ' + fmtInt(i.clicks) : fmtInt(i.clicks) }),
            el('td', { text: i.type === 'falling' ? '–' : i.ctr + '% at #' + i.position }), el('td', { text: i.potential_clicks > 0 ? '+' + fmtInt(i.potential_clicks) : '–' })));
        setContent(container, lead, tableOf(['Type', 'Query and page', 'Views', 'Clicks', 'Click-through', 'Could gain'], rows), data.note ? muted(data.note) : null);
    }

    /** "All sites" dashboard table. A missing number is a dash, never 0. */
    function renderOverview(container, data) {
        const sites = data.sites || [];
        if (!sites.length) return setContent(container, muted('No sites yet. Add your first site under Sites.'));
        const num = v => (v === null || v === undefined) ? '–' : fmtInt(v);
        const head = el('thead', {}, el('tr', {}, ...['Site', 'Latest score', 'Watched pages', 'Impressions (28d)', 'Clicks (28d)', '404s (30d)'].map(h => el('th', { text: h, attrs: { scope: 'col' } }))));
        const body = el('tbody', {}, ...sites.map(s => el('tr', {},
            el('td', {}, el('button', { class: 'btn-link', text: s.name, attrs: { type: 'button' }, data: { pickSite: s.id } }), ' ', Number(s.plugin) ? null : el('span', { class: 'badge', text: 'no plugin' }), el('br'),
                el('small', { class: 'text-muted break-all', text: s.base_url + (s.last_analyzed_at ? ' | analysed ' + s.last_analyzed_at : '') })),
            el('td', { text: s.last_score === null || s.last_score === undefined ? '–' : String(s.last_score) }),
            el('td', {}, s.watched ? String(s.watched) : '–', ...(Number(s.down) > 0 ? [' ', el('span', { class: 'badge badge-bad', text: s.down + ' down' })] : [])),
            el('td', { text: num(s.impressions) }), el('td', { text: num(s.clicks) }), el('td', { text: num(s.not_found) }))));
        setContent(container, el('div', { class: 'table-wrap' }, el('table', { class: 'data-table' }, head, body)));
    }

    /** Search Console panel. state = {configured, redirect_uri, connections:[...]}; properties = {id: [{site_url}]} for connections being set up. */
    function renderGsc(container, state, properties, sites) {
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
            if (Array.isArray(sites)) {
                const pick = el('select', { attrs: { id: 'gsc-site-' + c.id, 'aria-label': 'Site this Search Console data belongs to' }, data: { change: 'gsc-site', id: c.id } },
                    el('option', { text: 'Not tied to a site', attrs: { value: '0' } }), ...sites.map(s => el('option', { text: s.name, attrs: { value: String(s.id) } })));
                pick.value = String(c.site_id || 0);
                nodes.push(el('div', { class: 'form-inline' }, el('label', { class: 'inline-label', text: 'Belongs to site', attrs: { for: 'gsc-site-' + c.id } }), pick));
            }
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
        const win = stats.window_days || 30;
        set('dashboard-note', 'Showing the last ' + win + ' days.' + (stats.includes_sample_data ? ' Includes generated SAMPLE data.' : ''));
        document.querySelectorAll('[data-card="impressions"] h3').forEach(h => { h.textContent = 'Impressions (' + win + 'd)'; });
        document.querySelectorAll('[data-card="clicks"] h3').forEach(h => { h.textContent = 'Clicks (' + win + 'd)'; });
        if (Array.isArray(stats.cards) && stats.cards.length) {
            const grid = document.querySelector('#view-dashboard .stats-grid');
            if (grid) { stats.cards.forEach(id => { const c = grid.querySelector('[data-card="' + id + '"]'); if (c) { c.classList.remove('hidden'); grid.append(c); } });
                grid.querySelectorAll('.stat-card').forEach(c => c.classList.toggle('hidden', !stats.cards.includes(c.dataset.card))); }
        }
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

    // ------------------------------------------------------------------ settings page (pure renderers; wiring is further down)

    const SETTING_SOURCES = { default: 'Built-in default', file: 'Config file', saved: 'Saved here', env: 'Locked by server' };
    const newSettingsState = () => ({ group: 'mail', q: '', only: false, edits: {}, replacing: {}, errors: {}, testResult: {} });
    const settingId = key => 'set-' + key.replace(/[^a-z0-9]+/gi, '-');
    const allSettings = data => data.groups.flatMap(g => g.settings.map(s => Object.assign({ group: g.id }, s)));

    function settingValueText(s, v) {
        if (s.secret) return v ? '(new value)' : (s.value && s.value.set ? '(set)' : '(not set)');
        if (Array.isArray(v)) return v.length ? v.join(', ') : '(empty)';
        if (typeof v === 'boolean') return v ? 'On' : 'Off';
        return v === null || v === undefined || v === '' ? '(empty)' : String(v) + (s.unit ? ' ' + s.unit : '');
    }

    /** Changes the user has made but not saved: [{key,label,from,to,secret,effects}]. Equal-to-current edits are not changes. */
    function diffSettings(data, edits) {
        const changes = [];
        for (const s of allSettings(data)) {
            if (!(s.key in edits) || s.locked) continue;
            const v = edits[s.key];
            if (s.secret) { if (v === '' || v === undefined) continue; changes.push({ key: s.key, label: s.label, from: s.value && s.value.set ? '(set)' : '(not set)', to: '(new value)', secret: true, effects: s.danger ? s.danger_effects : [] }); continue; }
            if (JSON.stringify(v) === JSON.stringify(s.value)) continue;
            changes.push({ key: s.key, label: s.label, from: settingValueText(s, s.value), to: settingValueText(s, v), secret: false, effects: s.danger ? s.danger_effects : [] });
        }
        return { changes };
    }

    function settingMatches(s, q) {
        if (!q) return true;
        const hay = [s.label, s.help, s.key, s.env || ''].join(' ').toLowerCase();
        return q.toLowerCase().split(/\s+/).filter(Boolean).every(w => hay.includes(w));
    }

    function settingControl(s, st) {
        const id = settingId(s.key), cur = s.key in st.edits ? st.edits[s.key] : s.value;
        const common = { data: { setting: s.key, type: s.type }, attrs: { id, name: s.key } };
        let c;
        if (s.secret) {
            if (!st.replacing[s.key]) return null;
            c = el('input', { data: common.data, attrs: Object.assign({ type: 'password', autocomplete: 'new-password', placeholder: 'Paste the new value', spellcheck: 'false' }, common.attrs) });
            c.value = '';
        } else if (s.type === 'bool') {
            c = el('input', { data: common.data, attrs: Object.assign({ type: 'checkbox' }, common.attrs) }); c.checked = !!cur;
        } else if (s.type === 'enum') {
            c = el('select', { data: common.data, attrs: common.attrs }, ...s.enum.map(o => { const op = el('option', { text: o, attrs: { value: o } }); if (o === cur) op.selected = true; return op; }));
        } else if (s.type === 'list' || s.type === 'email_list') {
            c = el('textarea', { data: common.data, attrs: Object.assign({ rows: '3', spellcheck: 'false' }, common.attrs) }); c.value = (cur || []).join('\n');
        } else if (s.type === 'int' || s.type === 'float') {
            c = el('input', { data: common.data, attrs: Object.assign({ type: 'number', step: s.type === 'int' ? '1' : 'any' }, s.min !== null && s.min !== undefined ? { min: String(s.min) } : {}, s.max !== null && s.max !== undefined ? { max: String(s.max) } : {}, common.attrs) }); c.value = cur === null || cur === undefined ? '' : String(cur);
        } else {
            c = el('input', { data: common.data, attrs: Object.assign({ type: s.type === 'email' ? 'email' : 'text', autocomplete: 'off', spellcheck: 'false' }, common.attrs) }); c.value = cur === null || cur === undefined ? '' : String(cur);
        }
        if (s.locked) c.disabled = true;
        return c;
    }

    // A setting that only matters for one choice of another (SMTP fields vs Postal fields) is hidden while the other choice is selected; searching shows everything.
    function settingApplies(s, data, st) {
        if (!s.show_when) return true;
        const dep = s.show_when[0], other = data.groups.flatMap(g => g.settings).find(x => x.key === dep);
        if (!other) return true;
        return (dep in st.edits ? st.edits[dep] : other.value) === s.show_when[1];
    }

    function settingRow(s, st) {
        const id = settingId(s.key), ctl = settingControl(s, st), err = st.errors[s.key];
        const dirty = s.key in st.edits && !s.locked;
        const badge = el('span', { class: 'badge settings-src settings-src-' + s.source, text: (s.locked ? 'Locked · ' : '') + (SETTING_SOURCES[s.source] || s.source) });
        const head = el('div', { class: 'settings-row-head' }, el('label', { text: s.label, attrs: { for: ctl ? id : id + '-none' } }), badge, dirty ? el('span', { class: 'badge', text: 'Unsaved' }) : null);
        const body = [];
        if (s.secret) {
            const isSet = s.value && s.value.set;
            body.push(el('span', { class: 'settings-secret-state', text: isSet ? 'Set' + (s.changed_by ? ' by ' + s.changed_by : '') + (s.changed_at ? ', ' + s.changed_at : '') : 'Not set' }));
            if (ctl) body.push(ctl); else if (!s.locked) body.push(el('button', { class: 'btn btn-secondary btn-sm', text: isSet ? 'Replace' : 'Set a value', attrs: { type: 'button' }, data: { action: 'settings-replace-secret', key: s.key } }));
            if (isSet && s.source === 'saved') body.push(el('button', { class: 'btn btn-secondary btn-sm', text: 'Remove', attrs: { type: 'button' }, data: { action: 'settings-reset', key: s.key } }));
        } else {
            body.push(ctl);
            if (s.unit) body.push(el('span', { class: 'settings-unit', text: s.unit }));
        }
        const meta = [el('span', { class: 'mono settings-key', text: s.key + (s.env ? ' · ' + s.env : '') })];
        if (s.locked) meta.push(el('span', { text: 'Set by ' + s.env + ' on the server; change it there.' }));
        else if (s.source === 'saved') {
            meta.push(el('span', { text: 'Reset would use the ' + s.fallback + '.' }));
            if (!s.secret) meta.push(el('button', { class: 'btn btn-link btn-sm', text: 'Reset', attrs: { type: 'button' }, data: { action: 'settings-reset', key: s.key } }));
            if (!s.secret && s.changed_by) meta.push(el('span', { text: 'Saved by ' + s.changed_by + (s.changed_at ? ', ' + s.changed_at : '') }));
        } else if (s.source === 'file') {
            meta.push(el('button', { class: 'btn btn-link btn-sm', text: s.secret ? 'Move to encrypted storage' : 'Save this value here', attrs: { type: 'button' }, data: { action: 'settings-promote', key: s.key } }));
        }
        if (s.danger) meta.push(el('span', { class: 'settings-danger', text: 'Needs confirmation: ' + s.danger_effects.join(' ') }));
        return el('div', { class: 'settings-row' + (dirty ? ' settings-row-dirty' : '') + (err ? ' settings-row-error' : ''), data: { settingRow: s.key } },
            head, el('div', { class: 'settings-row-body' }, ...body), s.help ? el('p', { class: 'settings-help', text: s.help }) : null,
            err ? el('p', { class: 'settings-error', text: err, attrs: { role: 'alert' } }) : null, el('div', { class: 'settings-meta' }, ...meta));
    }

    function renderSettings(container, data, st) {
        const shown = (st.q ? data.groups : data.groups.filter(g => g.id === st.group));
        const nav = el('nav', { class: 'settings-nav', attrs: { 'aria-label': 'Settings groups' } },
            ...data.groups.map(g => {
                const n = g.changed_count + g.settings.filter(s => s.key in st.edits).length;
                return el('button', { class: 'settings-nav-item' + (g.id === st.group && !st.q ? ' active' : ''), attrs: { type: 'button', 'aria-current': g.id === st.group && !st.q ? 'true' : 'false' }, data: { action: 'settings-group', id: g.id } },
                    el('span', { text: g.label }), n ? el('span', { class: 'badge', text: String(n), attrs: { title: 'Changed from the default' } }) : null);
            }));
        const sel = el('select', { class: 'settings-nav-select', attrs: { 'aria-label': 'Settings group', id: 'settings-group-select' }, data: { change: 'settings-group-select' } },
            ...data.groups.map(g => { const o = el('option', { text: g.label, attrs: { value: g.id } }); if (g.id === st.group) o.selected = true; return o; }));
        const panels = [];
        for (const g of shown) {
            const rows = g.settings.filter(s => settingMatches(s, st.q) && (!st.only || s.source === 'saved' || s.key in st.edits) && (st.q || settingApplies(s, data, st)));
            if (!rows.length) continue;
            const kids = [el('legend', { text: g.label }), g.description && !st.q ? el('p', { class: 'text-muted', text: g.description }) : null];
            if (g.id === 'mail' && !st.q) kids.push(el('p', {}, el('button', { class: 'btn btn-secondary btn-sm', text: 'Send test mail to me', attrs: { type: 'button' }, data: { action: 'settings-test', name: 'mail' } }), ' ',
                el('span', { class: 'settings-test-result', text: st.testResult.mail || '', attrs: { role: 'status', 'aria-live': 'polite' } })));
            kids.push(...rows.map(s => settingRow(s, st)));
            panels.push(el('fieldset', { class: 'settings-group' }, ...kids));
        }
        if (!panels.length) panels.push(muted(st.q ? 'No setting matches “' + st.q + '”. Try a word from its description.' : 'Nothing here differs from its default.'));
        const ro = !st.q && data.readonly.length ? el('details', { class: 'settings-readonly' }, el('summary', { text: 'Set on the server (read-only)' }),
            ...data.readonly.map(r => el('div', { class: 'settings-row settings-row-ro' }, el('span', { class: 'mono', text: r.key }), ' ', el('span', { class: 'mono', text: Array.isArray(r.value) ? r.value.join(', ') : String(r.value === null ? '' : r.value) }), ' ', el('span', { class: 'badge', text: SETTING_SOURCES[r.source] || r.source })))) : null;
        setContent(container, data.encryption_ready ? null : el('div', { class: 'alert settings-note', text: 'app_key is not set, so secrets cannot be saved yet. Run php bin/gen-key.php and put the result in the config file as app_key.' }),
            el('div', { class: 'settings-layout' }, el('div', { class: 'settings-side' }, sel, nav), el('div', { class: 'settings-main' }, ...panels, ro)));
    }

    function renderSettingsBar(bar, data, st) {
        const { changes } = diffSettings(data, st.edits);
        bar.classList.toggle('hidden', !changes.length);
        // Do not rebuild the bar when nothing in it changed: a blur-triggered re-render would swap the button out from under a click.
        const sig = JSON.stringify(changes.map(c => c.key + '=' + c.to));
        if (bar.dataset.sig === sig) return;
        bar.dataset.sig = sig;
        if (!changes.length) { setContent(bar); return; }
        setContent(bar, el('span', { class: 'settings-bar-text' }, el('strong', { text: changes.length + (changes.length === 1 ? ' unsaved change' : ' unsaved changes') }), ': ' + changes.map(c => c.label).join(', ')),
            el('span', { class: 'settings-bar-actions' },
                el('button', { class: 'btn btn-secondary btn-sm', text: 'Review', attrs: { type: 'button' }, data: { action: 'settings-review' } }), ' ',
                el('button', { class: 'btn btn-secondary btn-sm', text: 'Discard', attrs: { type: 'button' }, data: { action: 'settings-discard' } }), ' ',
                el('button', { class: 'btn btn-primary btn-sm', text: 'Save changes', attrs: { type: 'button' }, data: { action: 'settings-save' } })));
    }

    // ------------------------------------------------------------------ test export (no side effects)

    const exported = { el, renderOpportunities, renderRedirectSuggestions, renderSiteAudits, renderSiteAuditReport, renderSiteAuditIssues, renderSiteAuditPages, renderOverview, renderWatchlist, renderWatchHistory, sparkline, renderSeoResults, renderContentResult, renderImageResults, renderAnalytics, render404Logs, renderRedirections, renderLocations, renderSitemapResult, renderDashboard, renderUsers, renderAudit, renderSites, renderGsc, hoursFromForm, editLocation, renderSettings, renderSettingsBar, diffSettings, newSettingsState };
    window.SW = exported;

    const cfgNode = document.getElementById('app-config');
    if (!cfgNode) return; // loaded by a test harness
    const cfg = JSON.parse(cfgNode.textContent);
    const BASE = cfg.basePath || '';
    let csrf = cfg.csrfToken;

    // ------------------------------------------------------------------ API

    class ApiError extends Error {
        constructor(message, status, errors) { super(message); this.status = status; this.errors = errors || {}; }
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
        if (!res.ok || !payload || payload.success !== true) throw new ApiError((payload && payload.message) || ('Request failed (HTTP ' + res.status + ')'), res.status, payload && payload.errors);
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

    const TITLES = { dashboard: 'Dashboard', 'seo-analysis': 'SEO Analysis', competitor: 'Competitor Analysis', watchlist: 'Watchlist', 'site-audit': 'Site Audit', opportunities: 'Search Opportunities', analytics: 'Analytics', 'content-ai': 'Content AI',
        'local-seo': 'Local SEO', 'image-seo': 'Image SEO', sitemaps: 'Sitemaps', '404-monitor': '404 Log', redirections: 'Redirect Rules',
        account: 'My Account', sites: 'Sites', users: 'Users', audit: 'Audit Log', settings: 'Settings' };
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
        const loaders = { dashboard: loadDashboard, watchlist: loadWatchlist, 'site-audit': loadSiteAudits, opportunities: loadOpportunities, analytics: () => { loadAnalytics(); loadGsc(); }, 'content-ai': loadAiStatus, 'local-seo': loadLocations, '404-monitor': load404, redirections: loadRedirections, sitemaps: loadSitemaps, account: loadAccount, sites: loadSites, users: loadUsers, audit: () => loadAudit(false), settings: loadSettings };
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
        const ov = document.getElementById('overview-card'); if (ov) ov.classList.toggle('hidden', siteId > 0);
        try { renderDashboard(await apiRequest('GET', '/dashboard/stats' + siteQuery('?'))); } catch (e) { showToast(e.message, 'error'); }
        if (siteId === 0 && document.getElementById('overview-list')) loadInto('overview-list', '/overview', renderOverview, 'the site overview');
    }
    const loadAnalytics = () => loadInto('analytics-dashboard', '/analytics/dashboard' + siteQuery('?'), renderAnalytics, 'analytics');
    // The site selector (404 log and redirect rules) scopes data to one connected site; 0 = everything / not tied to a site.
    let siteId = 0;
    try { siteId = Number(window.localStorage.getItem('sw-site')) || 0; } catch (e) { /* storage blocked: start on All sites */ }
    let siteList = [];
    async function loadSiteOptions() {
        try { siteList = await apiRequest('GET', '/sites/options'); } catch (e) { siteList = []; }
        if (siteId > 0 && !siteList.some(s => s.id === siteId)) setSite(0, false);   // the remembered site was removed
        const sel = document.getElementById('site-switch');
        if (sel) {
            sel.replaceChildren(el('option', { text: 'All sites', attrs: { value: '0' } }), ...siteList.map(s => el('option', { text: s.name, attrs: { value: String(s.id) } })));
            sel.value = String(siteId);
        }
    }
    function setSite(id, reload) {
        siteId = Number(id) || 0;
        try { window.localStorage.setItem('sw-site', String(siteId)); } catch (e) { /* ignore */ }
        const sel = document.getElementById('site-switch'); if (sel) sel.value = String(siteId);
        if (reload) switchView(viewFromLocation(), false);
    }
    const siteQuery = sep => (siteId > 0 ? sep + 'project_id=' + siteId : '');
    function syncExportLinks() {
        const e404 = document.getElementById('export-404'); if (e404) e404.href = BASE + '/api/404-monitor/export' + siteQuery('?');
        document.querySelectorAll('.export-rules').forEach(a => { a.href = BASE + '/api/redirections/export?format=' + a.dataset.format + siteQuery('&'); });
    }
    const loadSuggestions = () => loadInto('redirect-suggestions', '/404-monitor/suggestions' + siteQuery('?'), (box, d) => renderRedirectSuggestions(box, d, canEdit()), 'redirect suggestions');
    const load404 = () => { syncExportLinks(); loadSuggestions(); return loadInto('404-logs', '/404-monitor/logs?per_page=100' + siteQuery('&'), render404Logs, '404 log'); };
    const loadRedirections = () => { syncExportLinks(); return loadInto('redirections-list', '/redirections' + siteQuery('?'), renderRedirections, 'redirect rules'); };
    const loadLocations = () => loadInto('locations-list', '/local-seo/locations', (box, locs) => { locationsById = {}; locs.forEach(l => { locationsById[l.id] = l; }); renderLocations(box, locs); }, 'locations');
    const gscProps = {};
    async function loadGsc() {
        const box = document.getElementById('gsc-box');
        if (cfg.user && cfg.user.role !== 'admin') { setContent(box, muted('Ask an administrator to connect Google Search Console.')); return; }
        try { renderGsc(box, await apiRequest('GET', '/gsc'), gscProps, siteList); } catch (e) { showError(box, 'Could not load Search Console: ' + e.message); }
    }
    const canEdit = () => !!(cfg.user && cfg.user.role !== 'viewer');
    const loadWatchlist = () => { setContent(document.getElementById('watch-history'), null); return loadInto('watch-list', '/watch' + siteQuery('?'), (box, d) => renderWatchlist(box, d, canEdit()), 'the watchlist'); };
    const loadOpportunities = () => { const t = document.getElementById('opp-type'); return loadInto('opp-list', '/opportunities' + siteQuery('?') + (t && t.value ? (siteId > 0 ? '&' : '?') + 'type=' + encodeURIComponent(t.value) : ''), renderOpportunities, 'search opportunities'); };
    let auditPoll = null;
    async function loadSiteAudits() {
        clearTimeout(auditPoll);
        const box = document.getElementById('audit-runs');
        const url = document.getElementById('audit-url'), site = siteList.find(s => s.id === siteId);
        if (url) { url.readOnly = !!site; if (site) url.value = site.base_url; }
        try {
            const d = await apiRequest('GET', '/site-audits' + siteQuery('?'));
            renderSiteAudits(box, d, canEdit());
            if (d.audits.some(a => a.status === 'queued' || a.status === 'running') && viewFromLocation() === 'site-audit') auditPoll = setTimeout(loadSiteAudits, 4000);
        } catch (e) { showError(box, 'Could not load audits: ' + e.message); }
    }
    const loadSites = () => loadInto('sites-list', '/sites', (box, d) => renderSites(box, d.sites), 'sites');
    const loadSitemaps = () => loadInto('sitemap-list', '/sitemap' + siteQuery('?'), (box, rows) => setContent(box,
        rows.length ? el('p', { class: 'text-muted', text: rows.length + ' URL(s) stored. ' }, el('a', { text: 'Download XML', attrs: { href: BASE + '/api/sitemap/generate-xml', target: '_blank', rel: 'noopener' } })) : muted('No sitemap URLs stored yet.')), 'sitemap');

    async function loadAiStatus() {
        const box = document.getElementById('ai-status');
        try {
            const s = await apiRequest('GET', '/content-ai/status');
            box.className = 'alert ' + (s.ai_configured ? 'alert-info' : 'alert-warn');
            box.textContent = s.ai_configured ? 'AI: Google Gemini (' + s.model + ') is configured. Prompts you enter are sent to Google.' : 'No AI is configured: output is simple fixed templates, not AI-generated.';
            // only offer what Settings allows
            const TYPE_LABELS = { paragraph: 'Paragraph', title: 'Title', meta_description: 'Meta Description', heading: 'Heading', conclusion: 'Conclusion' };
            const fill = (id, values, label, current) => { const sel = document.getElementById(id); if (!sel || !Array.isArray(values) || !values.length) return; const keep = sel.value;
                sel.replaceChildren(...values.map(v => { const o = el('option', { text: label(v), attrs: { value: v } }); if (v === (values.includes(keep) ? keep : current)) o.selected = true; return o; })); };
            fill('ai-content-type', s.types, v => TYPE_LABELS[v] || v, s.types && s.types[0]);
            fill('ai-tone', s.tones, v => v.charAt(0).toUpperCase() + v.slice(1), s.default_tone);
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
        'audit-form': async form => {
            try {
                await apiRequest('POST', '/site-audits', siteId > 0 ? { project_id: siteId } : { base_url: form.querySelector('#audit-url').value.trim() });
                showToast('Audit started. It can take a few minutes.'); loadSiteAudits();
            } catch (e) { showToast(e.message, 'error'); }
        },
        'watch-form': async form => {
            try {
                await apiRequest('POST', '/watch', { url: form.querySelector('#watch-url').value.trim(), label: form.querySelector('#watch-label').value.trim(), interval_hours: Number(form.querySelector('#watch-interval').value), project_id: siteId });
                form.reset(); showToast('Added. The first check runs within a minute or two.'); loadWatchlist();
            } catch (e) { showToast(e.message, 'error'); }
        },
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
            try { renderImageResults(box, await runJob('/image-seo/bulk-analyze', { url: form.querySelector('#image-url').value, project_id: siteId }, box, 'Analyzing images')); } catch (e) { showError(box, e.message); }
        },
        'sitemap-crawl-form': async form => {
            const box = document.getElementById('sitemap-results');
            setContent(box, muted('Crawling… this can take a couple of minutes.'));
            try {
                renderSitemapResult(box, await runJob('/sitemap/crawl', { base_url: form.querySelector('#sitemap-url').value, max_pages: 100, project_id: siteId }, box, 'Crawling'), BASE + '/api/sitemap/generate-xml');
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
                const d = await apiRequest('POST', '/sites', { name: form.querySelector('#site-name').value.trim(), base_url: form.querySelector('#site-url').value.trim(), wordpress: form.querySelector('#site-wordpress').checked });
                form.reset(); form.classList.add('hidden');
                const box = document.getElementById('site-secret');
                if (d.secret) setContent(box, el('div', { class: 'alert alert-error', text: 'Copy this secret into the plugin now. It is not shown again.' }), el('pre', { class: 'break-all', text: d.secret }));
                else setContent(box, el('div', { class: 'alert alert-info', text: 'Site added. It will be analysed and monitored from here.' }));
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
            const data = await apiRequest('POST', '/seo-analysis/analyze', { url: form.querySelector('#' + inputId).value, is_competitor: competitor, project_id: siteId });
            renderSeoResults(box, data, { canWatch: !competitor && cfg.user && cfg.user.role !== 'viewer' });
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
        if (e.target.closest('#opp-type')) { loadOpportunities(); return; }
        const sw = e.target.closest('#site-switch');
        if (sw) { setSite(sw.value, true); return; }
        const gs = e.target.closest('[data-change="gsc-site"]');
        if (gs) { try { await apiRequest('POST', '/gsc/' + encodeURIComponent(gs.dataset.id) + '/site', { site_id: Number(gs.value) || 0 }); showToast('Saved'); } catch (err) { showToast(err.message, 'error'); loadGsc(); } return; }
        const t = e.target.closest('[data-change="user-role"]');
        if (!t) return;
        try { await apiRequest('PATCH', '/users/' + encodeURIComponent(t.dataset.id), { role: t.value }); showToast('Role updated'); } catch (err) { showToast(err.message, 'error'); loadUsers(); }
    });

    // ------------------------------------------------------------------ settings page wiring

    let settingsData = null;
    const settingsState = newSettingsState();

    function drawSettings() {
        renderSettings(document.getElementById('settings-root'), settingsData, settingsState);
        renderSettingsBar(document.getElementById('settings-bar'), settingsData, settingsState);
    }
    async function loadSettings() {
        const box = document.getElementById('settings-root');
        try {
            settingsData = await apiRequest('GET', '/settings');
            const g = window.location.hash.replace('#', '');
            if (settingsData.groups.some(x => x.id === g)) settingsState.group = g;
            drawSettings();
        } catch (e) { showError(box, 'Could not load settings: ' + e.message); }
    }
    function settingFromControl(node) {
        const s = allSettings(settingsData).find(x => x.key === node.dataset.setting);
        if (!s) return;
        let v;
        if (s.type === 'bool') v = node.checked;
        else if (s.type === 'int' || s.type === 'float') v = node.value.trim() === '' ? null : Number(node.value);
        else if (s.type === 'list' || s.type === 'email_list') v = node.value.split(/[\n,]+/).map(x => x.trim()).filter(Boolean);
        else v = node.value;
        if (!s.secret && JSON.stringify(v) === JSON.stringify(s.value)) delete settingsState.edits[s.key]; else if (s.secret && v === '') delete settingsState.edits[s.key]; else settingsState.edits[s.key] = v;
        delete settingsState.errors[s.key];
        renderSettingsBar(document.getElementById('settings-bar'), settingsData, settingsState);
        const row = node.closest('.settings-row'); if (row) row.classList.toggle('settings-row-dirty', s.key in settingsState.edits);
        if (allSettings(settingsData).some(x => x.show_when && x.show_when[0] === s.key)) { drawSettings(); const again = document.querySelector('[data-setting="' + s.key + '"]'); if (again) again.focus(); }
    }
    function openSettingsDialog() {
        const { changes } = diffSettings(settingsData, settingsState.edits);
        if (!changes.length) return;
        const effects = [...new Set(changes.flatMap(c => c.effects))];
        const dlg = document.getElementById('settings-dialog');
        setContent(dlg, el('h3', { text: 'Review changes', attrs: { id: 'settings-dialog-title' } }),
            el('ul', { class: 'settings-review' }, ...changes.map(c => el('li', {}, el('strong', { text: c.label }), ': ', el('span', { class: 'mono', text: c.from }), ' → ', el('span', { class: 'mono', text: c.to })))),
            effects.length ? el('div', { class: 'alert alert-error' }, el('strong', { text: 'Side effects' }), el('ul', {}, ...effects.map(e => el('li', { text: e })))) : null,
            el('div', { class: 'settings-dialog-actions' },
                el('button', { class: 'btn btn-secondary', text: 'Keep editing', attrs: { type: 'button' }, data: { action: 'settings-dialog-cancel' } }), ' ',
                el('button', { class: 'btn ' + (effects.length ? 'btn-danger' : 'btn-primary'), text: effects.length ? 'Save and apply these effects' : 'Save changes', attrs: { type: 'button' }, data: { action: 'settings-confirm' } })));
        if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
    }
    function closeSettingsDialog() { const dlg = document.getElementById('settings-dialog'); if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); }
    async function saveSettings(confirmed) {
        const { changes } = diffSettings(settingsData, settingsState.edits);
        if (!changes.length) return;
        const payload = {}; for (const c of changes) payload[c.key] = settingsState.edits[c.key];
        const box = document.getElementById('settings-error'); box.classList.add('hidden');
        try {
            const r = await apiRequest('PUT', '/settings', { changes: payload, confirm: confirmed });
            closeSettingsDialog();
            settingsState.edits = {}; settingsState.replacing = {}; settingsState.errors = {};
            showToast(r.changed.length ? 'Changes saved' + (r.cleared.length ? '; cleared ' + r.cleared.join(', ') : '') : 'Nothing changed');
            await loadSettings();
        } catch (e) {
            closeSettingsDialog();
            const errs = e.errors && typeof e.errors === 'object' ? e.errors : {};
            if (e.status === 409 && errs.code === 'confirm_required') { openSettingsDialog(); return; }
            for (const k of Object.keys(errs)) if (typeof errs[k] === 'string') settingsState.errors[k] = errs[k];
            drawSettings();
            box.textContent = e.message + (Object.keys(settingsState.errors).length ? ' Fix the highlighted settings.' : '');
            box.classList.remove('hidden'); box.focus();
        }
    }
    window.addEventListener('beforeunload', e => { if (settingsData && Object.keys(settingsState.edits).length) { e.preventDefault(); e.returnValue = ''; } });
    document.addEventListener('input', e => {
        if (e.target.id === 'settings-search') { settingsState.q = e.target.value.trim(); if (settingsData) drawSettings(); return; }
        if (e.target.matches && e.target.matches('[data-setting]')) settingFromControl(e.target);
    });
    document.addEventListener('change', e => {
        if (e.target.id === 'settings-only') { settingsState.only = e.target.checked; if (settingsData) drawSettings(); return; }
        if (e.target.dataset && e.target.dataset.change === 'settings-group-select') { settingsState.group = e.target.value; settingsState.q = ''; document.getElementById('settings-search').value = ''; drawSettings(); return; }
        if (e.target.matches && e.target.matches('[data-setting]')) settingFromControl(e.target);
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
        'redirect-accept': async t => {
            await apiRequest('POST', '/redirections', { source_url: t.dataset.uri, target_url: t.dataset.target, redirect_type: '301', project_id: Number(t.dataset.project) || 0 });
            showToast('Redirect created: ' + t.dataset.uri + ' → ' + t.dataset.target); load404();
        },
        'watch-add': async t => { await apiRequest('POST', '/watch', { url: t.dataset.url, project_id: siteId }); showToast('Added to the watchlist'); },
        'watch-run': async t => { await apiRequest('POST', '/watch/' + encodeURIComponent(t.dataset.id) + '/run', {}); showToast('Check queued'); setTimeout(loadWatchlist, 6000); },
        'watch-toggle': async t => { await apiRequest('PATCH', '/watch/' + encodeURIComponent(t.dataset.id), { enabled: t.dataset.enabled !== '1' }); loadWatchlist(); },
        'delete-watch': async t => { if (confirm('Remove this page from the watchlist? Its check history is deleted too.')) { await apiRequest('DELETE', '/watch/' + encodeURIComponent(t.dataset.id)); showToast('Removed'); loadWatchlist(); } },
        'watch-history': async t => {
            const box = document.getElementById('watch-history');
            setContent(box, muted('Loading…'));
            const d = await apiRequest('GET', '/watch/' + encodeURIComponent(t.dataset.id) + '/history');
            const tmp = el('div'); renderWatchHistory(tmp, d);
            setContent(box, el('h4', { text: 'Check history' }), tmp);
        },
        'audit-view': async t => {
            const box = document.getElementById('audit-report');
            setContent(box, muted('Loading…'));
            const id = encodeURIComponent(t.dataset.id);
            const [d, pages] = await Promise.all([apiRequest('GET', '/site-audits/' + id), apiRequest('GET', '/site-audits/' + id + '/pages?limit=20')]);
            const rep = el('div'), worst = el('div'); renderSiteAuditReport(rep, d); renderSiteAuditPages(worst, pages);
            setContent(box, el('h4', { text: 'Report' }), rep, el('div', { attrs: { id: 'audit-issue-list' } }), el('h4', { text: 'Lowest-scoring pages' }), worst);
        },
        'audit-issues': async t => {
            const box = document.getElementById('audit-issue-list');
            setContent(box, muted('Loading…'));
            const d = await apiRequest('GET', '/site-audits/' + encodeURIComponent(t.dataset.id) + '/issues?code=' + encodeURIComponent(t.dataset.code) + '&limit=200');
            const tmp = el('div'); renderSiteAuditIssues(tmp, d); setContent(box, tmp);
            box.scrollIntoView && box.scrollIntoView({ block: 'nearest' });
        },
        'delete-audit': async t => { if (confirm('Delete this audit and its results?')) { await apiRequest('DELETE', '/site-audits/' + encodeURIComponent(t.dataset.id)); showToast('Audit deleted'); setContent(document.getElementById('audit-report'), null); loadSiteAudits(); } },
        'delete-site': async t => { if (confirm('Remove this site from Sitewright? Its rules and 404 entries are kept.')) { await apiRequest('DELETE', '/sites/' + encodeURIComponent(t.dataset.id)); showToast('Site removed'); loadSites(); loadSiteOptions(); } },
        'gsc-connect': async () => { const d = await apiRequest('POST', '/gsc/connect', {}); window.location.assign(d.url); },
        'gsc-properties': async t => { gscProps[t.dataset.id] = await apiRequest('GET', '/gsc/' + encodeURIComponent(t.dataset.id) + '/properties'); loadGsc(); },
        'gsc-set-property': async t => { const v = document.getElementById('gsc-prop-' + t.dataset.id).value; await apiRequest('POST', '/gsc/' + encodeURIComponent(t.dataset.id) + '/property', { site_url: v }); delete gscProps[t.dataset.id]; showToast('Property saved'); loadGsc(); },
        'gsc-sync': async t => { await apiRequest('POST', '/gsc/' + encodeURIComponent(t.dataset.id) + '/sync', {}); showToast('Sync started; refresh in a minute'); },
        'gsc-disconnect': async t => { if (confirm('Disconnect Google Search Console? Imported keyword data is kept.')) { await apiRequest('DELETE', '/gsc/' + encodeURIComponent(t.dataset.id)); showToast('Disconnected'); loadGsc(); } },
        'settings-group': t => { settingsState.group = t.dataset.id; settingsState.q = ''; document.getElementById('settings-search').value = ''; drawSettings(); },
        'settings-replace-secret': t => { settingsState.replacing[t.dataset.key] = true; drawSettings(); const c = document.getElementById(settingId(t.dataset.key)); if (c) c.focus(); },
        'settings-reset': async t => { if (confirm('Reset this setting? It falls back to the config file or built-in default.')) { const d = await apiRequest('DELETE', '/settings/' + encodeURIComponent(t.dataset.key)); delete settingsState.edits[t.dataset.key]; showToast('Reset to the ' + d.fallback); await loadSettings(); } },
        'settings-promote': async t => { await apiRequest('POST', '/settings/' + encodeURIComponent(t.dataset.key) + '/promote', {}); showToast('Moved to encrypted storage'); await loadSettings(); },
        'settings-test': async t => { settingsState.testResult[t.dataset.name] = 'Sending…'; drawSettings(); const d = await apiRequest('POST', '/settings/test/' + encodeURIComponent(t.dataset.name), {}); settingsState.testResult[t.dataset.name] = (d.ok ? 'Worked: ' : 'Failed: ') + d.message; drawSettings(); },
        'settings-discard': () => { settingsState.edits = {}; settingsState.replacing = {}; settingsState.errors = {}; drawSettings(); },
        'settings-review': () => openSettingsDialog(),
        'settings-save': async () => { const { changes } = diffSettings(settingsData, settingsState.edits); if (changes.some(c => c.effects.length)) openSettingsDialog(); else await saveSettings(false); },
        'settings-confirm': async () => { await saveSettings(true); },
        'settings-dialog-cancel': () => closeSettingsDialog(),
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
        const pickSite = e.target.closest('[data-pick-site]');
        if (pickSite) { setSite(pickSite.dataset.pickSite, true); return; }
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
