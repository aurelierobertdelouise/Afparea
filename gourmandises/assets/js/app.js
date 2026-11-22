/* Gourmandises AFPAR — petites interactions (le site fonctionne aussi sans JS). */
(function () {
    'use strict';

    var toastEl = document.getElementById('toast');
    var toastTimer;
    function toast(msg) {
        if (!toastEl) return;
        toastEl.textContent = msg;
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, 2200);
    }

    // Ajout au panier sans rechargement
    document.querySelectorAll('form[data-add-to-cart]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (!window.fetch || !window.FormData) return;
            ev.preventDefault();
            var data = new FormData(form);
            data.append('ajax', '1');
            var btn = form.querySelector('button');
            btn.disabled = true;
            fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.ok) throw new Error();
                    document.querySelectorAll('[data-cart-count]').forEach(function (b) {
                        b.textContent = res.count;
                        b.hidden = false;
                    });
                    var cb = document.querySelector('.cartbtn');
                    if (cb) { cb.classList.remove('bump'); void cb.offsetWidth; cb.classList.add('bump'); }
                    toast('✓ ' + res.nom + ' ajouté au panier');
                })
                .catch(function () { form.submit(); })
                .finally(function () { btn.disabled = false; });
        });
    });

    // Boutons +/- de quantité
    document.querySelectorAll('[data-qty]').forEach(function (box) {
        var input = box.querySelector('input');
        var auto = box.hasAttribute('data-autosubmit');
        box.querySelectorAll('[data-step]').forEach(function (b) {
            b.addEventListener('click', function () {
                var min = parseInt(input.min || '0', 10);
                var v = Math.max(min, Math.min(99, (parseInt(input.value, 10) || 0) + parseInt(b.dataset.step, 10)));
                input.value = v;
                if (auto) input.form.submit();
            });
        });
        if (auto) input.addEventListener('change', function () { input.form.submit(); });
    });
    document.querySelectorAll('.js-hide').forEach(function (el) { el.hidden = true; });

    // Confirmation avant action destructive
    document.addEventListener('click', function (ev) {
        var el = ev.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) ev.preventDefault();
    });

    // Livraison / Click & Collect : champs adresse + frais
    var modes = document.querySelectorAll('input[name="mode"]');
    if (modes.length) {
        var block = document.querySelector('[data-delivery]');
        var feeRow = document.querySelector('[data-fee-row]');
        var fmt = function (c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; };
        var update = function () {
            var liv = document.querySelector('input[name="mode"]:checked').value === 'livraison';
            block.classList.toggle('is-hidden', !liv);
            if (feeRow) {
                var fee = liv ? parseInt(feeRow.dataset.fee, 10) : 0;
                feeRow.querySelector('[data-fee-val]').textContent = fmt(fee);
                document.querySelector('[data-total]').textContent = fmt(parseInt(feeRow.dataset.sub, 10) + fee);
            }
        };
        modes.forEach(function (m) { m.addEventListener('change', update); });
        update();
    }

    // Aperçu image (admin)
    var pi = document.querySelector('[data-preview-input]');
    if (pi) {
        pi.addEventListener('change', function () {
            if (pi.files && pi.files[0]) document.querySelector('[data-preview]').src = URL.createObjectURL(pi.files[0]);
        });
    }
})();
