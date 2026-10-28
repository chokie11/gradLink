/**
 * GradLink global loader.
 *  - Form submit  -> full-page overlay + spinner on the clicked button, extra submits are blocked.
 *  - Action links (delete / update / invite / resend / refresh ...) -> same overlay.
 *  - Normal page links -> thin top progress bar.
 * Opt out with data-no-loader on a form/link. Customise text with data-loader-text="...".
 * Buttons are NOT disabled (a disabled button would drop its name/value from the POST).
 */
(function () {
    'use strict';

    var FAILSAFE_MS = 90000;           // give up after 90s so the page never stays locked
    var ACTION_LINK = /(delete_|update_user_status|update_job_status|invite_alumni|resend|refresh_ai|generate)/i;
    var SKIP_LINK = /(export|download)/i;
    var overlay, textEl, failsafe, topbar, topTimer;

    function ensureOverlay() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'gl-overlay';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML =
            '<div class="gl-overlay-box">' +
            '<div class="gl-spinner" aria-hidden="true"></div>' +
            '<div class="gl-overlay-text"></div>' +
            '<div class="gl-overlay-sub">Please do not click again or close this page.</div>' +
            '</div>';
        textEl = overlay.querySelector('.gl-overlay-text');
        document.body.appendChild(overlay);
    }

    function showOverlay(text) {
        if (!document.body) return;
        ensureOverlay();
        textEl.textContent = text || 'Please wait…';
        overlay.classList.add('show');
        document.body.classList.add('gl-busy');
        clearTimeout(failsafe);
        failsafe = setTimeout(reset, FAILSAFE_MS);
    }

    function showTopbar() {
        if (!document.body) return;
        if (!topbar) {
            topbar = document.createElement('div');
            topbar.className = 'gl-topbar';
            document.body.appendChild(topbar);
        }
        topbar.style.width = '12%';
        clearTimeout(topTimer);
        topTimer = setTimeout(function () { topbar.style.width = '70%'; }, 120);
        document.body.classList.add('gl-busy');
        clearTimeout(failsafe);
        failsafe = setTimeout(reset, FAILSAFE_MS);
    }

    function reset() {
        clearTimeout(failsafe);
        clearTimeout(topTimer);
        if (overlay) overlay.classList.remove('show');
        if (topbar) topbar.style.width = '0';
        if (document.body) document.body.classList.remove('gl-busy');
        document.querySelectorAll('.gl-btn-loading').forEach(function (b) {
            b.classList.remove('gl-btn-loading');
            b.removeAttribute('aria-busy');
        });
        document.querySelectorAll('form[data-gl-submitting]').forEach(function (f) {
            f.removeAttribute('data-gl-submitting');
        });
    }

    // ---- forms ----
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;

        if (form.hasAttribute('data-gl-submitting')) {   // already sent: block double submit
            e.preventDefault();
            return;
        }
        if (e.defaultPrevented) return;                  // cancelled by confirm() or custom code
        if (form.hasAttribute('data-no-loader')) return;
        if (form.target && form.target !== '_self') return;

        var submitter = e.submitter || form.querySelector('[type="submit"]');
        var text = (submitter && submitter.getAttribute('data-loader-text')) ||
                   form.getAttribute('data-loader-text') || 'Processing, please wait…';

        // Let the browser finish building the form data first, then lock the UI.
        form.setAttribute('data-gl-submitting', '1');
        setTimeout(function () {
            if (submitter && submitter.tagName === 'BUTTON') {
                submitter.classList.add('gl-btn-loading');
                submitter.setAttribute('aria-busy', 'true');
            }
            showOverlay(text);
        }, 0);
    });

    // ---- links ----
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || a.hasAttribute('data-no-loader') || a.hasAttribute('download')) return;
        if (a.target && a.target !== '_self') return;

        var href = a.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#' || /^(javascript:|mailto:|tel:)/i.test(href)) return;
        if (a.hasAttribute('data-bs-toggle')) return;
        if (SKIP_LINK.test(href)) return;

        var url;
        try { url = new URL(a.href, location.href); } catch (err) { return; }
        if (url.origin !== location.origin) return;
        if (url.pathname === location.pathname && url.search === location.search && url.hash) return;

        if (a.hasAttribute('data-loader-text') || ACTION_LINK.test(url.pathname + url.search)) {
            a.classList.add('gl-btn-loading');
            showOverlay(a.getAttribute('data-loader-text') || 'Processing, please wait…');
        } else {
            showTopbar();
        }
    });

    // Back/forward cache restores the page as it was: clear any stuck loader.
    window.addEventListener('pageshow', function (e) { if (e.persisted) reset(); });
})();
