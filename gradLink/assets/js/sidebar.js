/**
 * Sidebar behaviour:
 *  - mobile drawer: open with the menu button, close with backdrop / X / Esc / link tap
 *  - on desktop and mobile, scroll the menu so the active item is visible
 */
(function () {
    'use strict';

    var MOBILE_QUERY = window.matchMedia('(max-width: 991px)');
    var body = document.body;
    var sidebar = document.getElementById('appSidebar');
    if (!sidebar) return;

    var toggleBtn = document.querySelector('[data-sidebar-toggle]');

    function setOpen(open) {
        body.classList.toggle('sidebar-open', open);
        if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggleBtn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        }
        if (open) {
            var first = sidebar.querySelector('.sidebar-nav a.active') || sidebar.querySelector('.sidebar-nav a');
            if (first) { try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); } }
        } else if (toggleBtn && MOBILE_QUERY.matches) {
            toggleBtn.focus();
        }
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-sidebar-toggle]')) {
            setOpen(!body.classList.contains('sidebar-open'));
        } else if (e.target.closest('[data-sidebar-close]')) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && body.classList.contains('sidebar-open')) setOpen(false);
    });

    // Leaving mobile width: make sure the drawer state is reset
    var onChange = function () { if (!MOBILE_QUERY.matches) body.classList.remove('sidebar-open'); };
    if (MOBILE_QUERY.addEventListener) MOBILE_QUERY.addEventListener('change', onChange);
    else if (MOBILE_QUERY.addListener) MOBILE_QUERY.addListener(onChange);

    // Back/forward cache: never restore the page with the drawer open
    window.addEventListener('pageshow', function () { body.classList.remove('sidebar-open'); });

    // Keep the current page's menu item in view when the menu is scrollable
    function revealActive() {
        var a = sidebar.querySelector('.sidebar-nav a.active');
        if (!a || sidebar.scrollHeight <= sidebar.clientHeight) return;
        var top = a.offsetTop - sidebar.clientHeight / 2 + a.offsetHeight / 2;
        sidebar.scrollTop = Math.max(0, top);
    }
    revealActive();
})();
