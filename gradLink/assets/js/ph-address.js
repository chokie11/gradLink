/**
 * Philippine address selector: Province -> City/Municipality -> Barangay.
 * Data lives in assets/data/ph-address/ (works offline, no external API).
 *
 * Markup: a wrapper with  data-ph-address  and  data-base="../assets/data/ph-address/"
 * containing <select name="province|municipality|barangay" data-selected="Saved value">.
 */
(function () {
    'use strict';

    function option(value, label, selected) {
        var o = document.createElement('option');
        o.value = value;
        o.textContent = label;
        if (selected) o.selected = true;
        return o;
    }

    function reset(select, placeholder, disabled) {
        select.innerHTML = '';
        select.appendChild(option('', placeholder, true));
        select.disabled = !!disabled;
    }

    function getJson(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    function init(root) {
        var base = root.getAttribute('data-base') || 'assets/data/ph-address/';
        var provSel = root.querySelector('select[name="province"]');
        var munSel = root.querySelector('select[name="municipality"]');
        var brgySel = root.querySelector('select[name="barangay"]');
        if (!provSel || !munSel || !brgySel) return;

        var provinces = [];
        var munis = []; // current province's municipalities [{n, b:[...]}]
        var cache = {};

        function loadProvince(code) {
            if (cache[code]) return Promise.resolve(cache[code]);
            return getJson(base + 'prov/' + encodeURIComponent(code) + '.json').then(function (d) {
                cache[code] = d;
                return d;
            });
        }

        function fillMunicipalities(selectedMun) {
            reset(munSel, 'Select city / municipality', false);
            munis.forEach(function (m) {
                munSel.appendChild(option(m.n, m.n, selectedMun && m.n === selectedMun));
            });
        }

        function fillBarangays(selectedBrgy) {
            reset(brgySel, 'Select barangay', false);
            var m = munis.filter(function (x) { return x.n === munSel.value; })[0];
            if (!m) { brgySel.disabled = true; return; }
            m.b.forEach(function (b) {
                brgySel.appendChild(option(b, b, selectedBrgy && b === selectedBrgy));
            });
        }

        function onProvinceChange(selectedMun, selectedBrgy) {
            var p = provinces.filter(function (x) { return x.n === provSel.value; })[0];
            munis = [];
            reset(brgySel, 'Select barangay', true);
            if (!p) {
                reset(munSel, 'Select city / municipality', true);
                return Promise.resolve();
            }
            reset(munSel, 'Loading...', true);
            return loadProvince(p.c).then(function (d) {
                munis = d;
                fillMunicipalities(selectedMun);
                if (selectedMun) fillBarangays(selectedBrgy);
                else brgySel.disabled = true;
            }).catch(function () {
                reset(munSel, 'Could not load - reload the page', true);
            });
        }

        var savedProv = provSel.getAttribute('data-selected') || '';
        var savedMun = munSel.getAttribute('data-selected') || '';
        var savedBrgy = brgySel.getAttribute('data-selected') || '';

        reset(provSel, 'Loading...', true);
        reset(munSel, 'Select city / municipality', true);
        reset(brgySel, 'Select barangay', true);

        getJson(base + 'provinces.json').then(function (list) {
            provinces = list;
            reset(provSel, 'Select province', false);
            provinces.forEach(function (p) {
                provSel.appendChild(option(p.n, p.n, savedProv && p.n === savedProv));
            });
            if (savedProv) return onProvinceChange(savedMun, savedBrgy);
        }).catch(function () {
            reset(provSel, 'Could not load - reload the page', true);
        });

        provSel.addEventListener('change', function () { onProvinceChange('', ''); });
        munSel.addEventListener('change', function () { fillBarangays(''); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-ph-address]').forEach(init);
    });
})();
