/* ==========================================================================
 * Autotask Integration — dashboard behaviour (vanilla JS, no dependencies)
 * ========================================================================== */
(function () {
    'use strict';

    /**
     * Confirm destructive bulk actions before submitting.
     */
    function wireConfirms() {
        var bulk = document.querySelectorAll('button[type="submit"].at-btn-warn');
        bulk.forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                if (!window.confirm('Re-queue all failed/dead jobs?')) {
                    ev.preventDefault();
                }
            });
        });
    }

    /**
     * Auto-refresh the dashboard every 60s unless the user is interacting with
     * a form field. Keeps queue/log views current without manual reloads.
     */
    function wireAutoRefresh() {
        var REFRESH_MS = 60000;
        setInterval(function () {
            var active = document.activeElement;
            var typing = active && (active.tagName === 'INPUT' || active.tagName === 'SELECT' || active.tagName === 'TEXTAREA');
            if (!typing) {
                window.location.reload();
            }
        }, REFRESH_MS);
    }

    /**
     * Provide a lightweight "busy" state on sync buttons so admins don't double
     * click long-running operations.
     */
    function wireBusyState() {
        var forms = document.querySelectorAll('form.at-inline');
        forms.forEach(function (form) {
            form.addEventListener('submit', function () {
                var btn = form.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.dataset.label = btn.textContent;
                    btn.textContent = 'Working…';
                }
            });
        });
    }

    /**
     * Full-screen loader (CSS dancer) shown while a submitted action runs.
     * To revert to no-overlay: remove wireLoader() from the init list below.
     */
    function showLoader(msg) {
        if (document.querySelector('.at-loading-overlay')) { return; }
        var ov = document.createElement('div');
        ov.className = 'at-loading-overlay';
        ov.innerHTML = '<div class="at-dancer">'
            + '<span class="at-note n1">&#9835;</span><span class="at-note n2">&#9834;</span>'
            + '<div class="tail l"></div><div class="tail r"></div>'
            + '<div class="head"></div><div class="arm l"></div><div class="arm r"></div>'
            + '<div class="body"></div><div class="skirt"></div>'
            + '<div class="leg l"></div><div class="leg r"></div></div>'
            + '<div class="at-loading-text">' + (msg || 'Syncing with Autotask…') + '</div>';
        document.body.appendChild(ov);
    }
    function wireLoader() {
        document.querySelectorAll('.at-wrap form').forEach(function (form) {
            form.addEventListener('submit', function () {
                var btn = form.querySelector('button[type="submit"]');
                var label = btn ? (btn.dataset.label || btn.textContent).trim() : '';
                showLoader(label ? label + '…' : 'Working…');
            });
        });
    }

    /**
     * Fetch a fresh CSRF token immediately before submitting any dashboard form,
     * so a page that's been open a while (or a rotated multi-tab session token)
     * doesn't fail with "Valid CSRF Token Required".
     */
    function wireCsrfRefresh() {
        var forms = document.querySelectorAll('form');
        forms.forEach(function (form) {
            form.addEventListener('submit', function (ev) {
                var tk = form.querySelector('[name="__CSRFToken__"]');
                if (!tk || form.dataset.atTokenFresh) {
                    return; // no token field, or already refreshed
                }
                ev.preventDefault();
                // form.submit() ignores the clicked submit button, so forms
                // with named submit buttons (e.g. action=save/test on the
                // client form) would lose it — persist it as a hidden field.
                if (ev.submitter && ev.submitter.name && !form.querySelector('input[type="hidden"][name="' + ev.submitter.name + '"]')) {
                    var hid = document.createElement('input');
                    hid.type = 'hidden';
                    hid.name = ev.submitter.name;
                    hid.value = ev.submitter.value;
                    form.appendChild(hid);
                }
                fetch('autotask.php?action=csrf', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d && d.token) { tk.value = d.token; }
                        form.dataset.atTokenFresh = '1';
                        form.submit(); // native submit (bypasses this handler)
                    })
                    .catch(function () { form.dataset.atTokenFresh = '1'; form.submit(); });
            });
        });
    }

    /**
     * Collapsible dashboard sections (Audit Trail, Recent Logs). The chosen
     * state is remembered per browser via localStorage; data-default sets the
     * initial state on first visit.
     */
    function wireCollapse() {
        var heads = document.querySelectorAll('h2.at-collapsible');
        heads.forEach(function (h) {
            var body = h.parentNode.querySelector('.at-body');
            if (!body) { return; }
            var key = 'at-collapse-' + (h.dataset.key || 'section');
            function apply(collapsed) {
                body.style.display = collapsed ? 'none' : '';
                h.classList.toggle('collapsed', collapsed);
            }
            var saved = null;
            try { saved = window.localStorage.getItem(key); } catch (e) { /* private mode */ }
            apply(saved === null ? h.dataset.default === 'collapsed' : saved === '1');
            h.addEventListener('click', function () {
                var collapsed = body.style.display !== 'none';
                apply(collapsed);
                try { window.localStorage.setItem(key, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        wireConfirms();
        wireBusyState();
        wireCsrfRefresh();
        wireAutoRefresh();
        wireCollapse();
        // wireLoader(); — overlay loader reverted at user request (2026-07-10);
        // re-enable by uncommenting if wanted later.
    });
})();
