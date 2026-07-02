/**
 * QCD Redis - Back office behaviour (Symfony routes + JSON endpoints).
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */
(function () {
    'use strict';

    var app = document.getElementById('qcd-redis-app');
    if (!app) {
        return;
    }

    // PrestaShop admin routes are protected by a security token carried on the
    // current page URL. Propagate it to every AJAX call.
    var pageToken = new URLSearchParams(window.location.search).get('_token');

    function url(name) {
        var base = app.getAttribute('data-url-' + name);
        if (pageToken && base.indexOf('_token=') === -1) {
            base += (base.indexOf('?') === -1 ? '?' : '&') + '_token=' + encodeURIComponent(pageToken);
        }
        return base;
    }

    function post(name, data) {
        return send(name, 'POST', data);
    }

    function get(name) {
        return send(name, 'GET', null);
    }

    function send(name, method, data) {
        var opts = { method: method, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        if (data) {
            var body = new URLSearchParams();
            Object.keys(data).forEach(function (k) {
                var v = data[k];
                if (Array.isArray(v)) {
                    v.forEach(function (item) { body.append(k + '[]', item); });
                } else {
                    body.set(k, v);
                }
            });
            opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
            opts.body = body.toString();
        }
        return fetch(url(name), opts).then(function (r) { return r.json(); });
    }

    /* ---------------- Tabs ---------------- */
    app.querySelectorAll('[data-qcd-tab]').forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            var name = tab.getAttribute('data-qcd-tab');
            app.querySelectorAll('[data-qcd-tab]').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            app.querySelectorAll('[data-qcd-panel]').forEach(function (p) {
                p.classList.toggle('active', p.getAttribute('data-qcd-panel') === name);
            });
        });
    });

    /* ---------------- Connection test ---------------- */
    var testBtn = document.getElementById('qcd-test-connection');
    if (testBtn) {
        testBtn.addEventListener('click', function () {
            var form = testBtn.closest('form');
            var result = document.getElementById('qcd-connection-result');
            result.textContent = 'Testing...';
            result.className = 'qcd-result';
            post('test', {
                host: field(form, 'host'),
                port: field(form, 'port'),
                password: field(form, 'password'),
                db: field(form, 'db'),
                timeout: field(form, 'timeout'),
                tls: checkbox(form, 'tls') ? 1 : 0
            }).then(function (res) {
                result.textContent = res.message;
                result.className = 'qcd-result ' + (res.success ? 'ok' : 'err');
            });
        });
    }

    function field(form, suffix) {
        var el = form.querySelector('[name$="[' + suffix + ']"]');
        return el ? el.value : '';
    }

    function checkbox(form, suffix) {
        var el = form.querySelector('[name$="[' + suffix + ']"]');
        return el ? el.checked : false;
    }

    /* ---------------- Dashboard / statistics ---------------- */
    function renderOverview(container, overview) {
        Object.keys(overview).forEach(function (metric) {
            var el = container.querySelector('[data-metric="' + metric + '"]');
            if (el) { el.textContent = overview[metric]; }
        });
    }

    app.querySelectorAll('[data-qcd-refresh]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = btn.getAttribute('data-qcd-refresh');
            if (target === 'dashboard') {
                get('stats').then(function (res) {
                    if (res.success) { renderOverview(document.getElementById('qcd-dashboard-grid'), res.overview); }
                });
            } else if (target === 'statistics') {
                refreshStatistics();
            } else if (target === 'diagnostic') {
                runDiagnostics();
            }
        });
    });

    function refreshStatistics() {
        get('stats').then(function (res) {
            if (!res.success) { return; }
            var summary = document.getElementById('qcd-stats-summary');
            summary.innerHTML = '';
            var fields = { hits: 'Hits', misses: 'Misses', hit_ratio: 'Hit %', keys: 'Keys', latency_ms: 'Latency ms', used_memory_human: 'Memory' };
            Object.keys(fields).forEach(function (k) {
                summary.insertAdjacentHTML('beforeend',
                    '<div class="qcd-card"><span class="qcd-metric">' + (res.overview[k] !== undefined ? res.overview[k] : '-') + '</span><span class="qcd-label">' + fields[k] + '</span></div>');
            });
            summary.insertAdjacentHTML('beforeend',
                '<div class="qcd-card"><span class="qcd-metric">' + res.average_ttl + '</span><span class="qcd-label">Avg TTL</span></div>');
            var body = document.querySelector('#qcd-heavy-keys tbody');
            body.innerHTML = '';
            (res.heavy_keys || []).forEach(function (row) {
                body.insertAdjacentHTML('beforeend', '<tr><td>' + row.key + '</td><td>' + row.bytes + '</td><td>' + row.ttl + '</td></tr>');
            });
        });
    }

    /* ---------------- CSV export ---------------- */
    var csvBtn = document.getElementById('qcd-export-csv');
    if (csvBtn) {
        csvBtn.setAttribute('href', url('export'));
    }

    /* ---------------- Purge ---------------- */
    app.querySelectorAll('[data-qcd-purge]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var type = btn.getAttribute('data-qcd-purge');
            var result = document.getElementById('qcd-purge-result');
            var data = { type: type };
            if (type === 'all' && !window.confirm('Flush the whole cache namespace?')) { return; }
            if (type === 'prefix') { data.prefix = document.getElementById('qcd-purge-prefix').value; }
            if (type === 'tags') { data.tags = document.getElementById('qcd-purge-tags').value; }
            result.textContent = 'Working...';
            result.className = 'qcd-result';
            post('purge', data).then(function (res) {
                result.className = 'qcd-result ' + (res.success ? 'ok' : 'err');
                if (res.deleted !== undefined) { result.textContent = res.deleted + ' key(s) deleted.'; }
                else if (res.expired_keys !== undefined) { result.textContent = res.expired_keys + ' expired key(s) reported by Redis.'; }
                else { result.textContent = 'Done.'; }
            });
        });
    });

    /* ---------------- Warmup ---------------- */
    var warmupBtn = document.getElementById('qcd-warmup-start');
    if (warmupBtn) {
        warmupBtn.addEventListener('click', function () {
            var types = [];
            app.querySelectorAll('.qcd-warmup-type:checked').forEach(function (c) { types.push(c.value); });
            var bar = document.getElementById('qcd-warmup-bar');
            var result = document.getElementById('qcd-warmup-result');
            result.textContent = 'Building queue...';
            warmupBtn.disabled = true;
            post('warmup-build', { types: types }).then(function (res) {
                runWarmupBatches(0, res.total || 0, bar, result, warmupBtn);
            });
        });
    }

    function runWarmupBatches(offset, total, bar, result, btn) {
        if (total === 0) { result.textContent = 'Nothing to warm up.'; btn.disabled = false; return; }
        post('warmup-batch', { offset: offset, size: 5 }).then(function (res) {
            var done = Math.min(offset + res.processed, total);
            var pct = Math.round((done / total) * 100);
            bar.style.width = pct + '%';
            bar.textContent = pct + '%';
            result.textContent = done + ' / ' + total + ' URLs processed.';
            if (done < total && res.processed > 0) {
                runWarmupBatches(done, total, bar, result, btn);
            } else {
                result.textContent = 'Warmup complete: ' + done + ' URLs.';
                btn.disabled = false;
            }
        });
    }

    /* ---------------- Diagnostics ---------------- */
    function runDiagnostics() {
        get('diagnostics').then(function (res) {
            var list = document.getElementById('qcd-diagnostic-list');
            list.innerHTML = '';
            (res.checks || []).forEach(function (c) {
                list.insertAdjacentHTML('beforeend',
                    '<li class="qcd-check ' + c.level + '"><span class="dot"></span><span class="label">' + c.label + '</span><span>' + c.message + '</span></li>');
            });
        });
    }

    var benchBtn = document.getElementById('qcd-run-benchmark');
    if (benchBtn) {
        benchBtn.addEventListener('click', function () {
            var box = document.getElementById('qcd-benchmark-result');
            box.innerHTML = '<p>Running 1000 ops per operation...</p>';
            post('benchmark', {}).then(function (res) {
                box.innerHTML = '';
                Object.keys(res.benchmark || {}).forEach(function (op) {
                    box.insertAdjacentHTML('beforeend',
                        '<div class="qcd-card"><span class="qcd-metric">' + res.benchmark[op] + '</span><span class="qcd-label">' + op + ' (ms/1k)</span></div>');
                });
            });
        });
    }
})();
