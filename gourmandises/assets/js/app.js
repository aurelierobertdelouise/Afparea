/* Gourmandises AFPAR — interactions. Le site reste utilisable sans JavaScript. */
(function () {
    'use strict';
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var euro = function (c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; };

    // ---- Toast
    var toastEl = $('#toast'), toastTimer;
    function toast(msg, isError) {
        if (!toastEl) return;
        toastEl.textContent = msg;
        toastEl.classList.toggle('toast-error', !!isError);
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, isError ? 4500 : 2200);
    }

    // ---- Ajout au panier sans rechargement (+ alerte quota)
    $$('form[data-add-to-cart]').forEach(function (form) {
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
                    $$('[data-cart-count]').forEach(function (b) { b.textContent = res.count; b.hidden = false; });
                    var cb = $('.cartbtn');
                    if (cb) { cb.classList.remove('bump'); void cb.offsetWidth; cb.classList.add('bump'); }
                    if (res.quota) toast('⚠ ' + res.quota, true);
                    else toast('✓ ' + res.nom + ' ajouté au panier');
                })
                .catch(function () { form.submit(); })
                .finally(function () { btn.disabled = false; });
        });
    });

    // ---- Boutons +/- de quantité
    $$('[data-qty]').forEach(function (box) {
        var input = box.querySelector('input');
        box.querySelectorAll('[data-step]').forEach(function (b) {
            b.addEventListener('click', function () {
                var min = parseInt(input.min || '0', 10), max = parseInt(input.max || '99', 10);
                input.value = Math.max(min, Math.min(max, (parseInt(input.value, 10) || 0) + parseInt(b.dataset.step, 10)));
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
    });

    // ---- Panier : calcul des quotas et du total en temps réel (CDC §6.3)
    var cart = $('[data-cart]');
    if (cart) {
        var saveTimer;
        var recompute = function () {
            var counts = {}, total = 0;
            $$('[data-line]', cart).forEach(function (line) {
                var q = Math.max(0, parseInt(line.querySelector('input').value, 10) || 0);
                var price = parseInt(line.dataset.price, 10);
                counts[line.dataset.cat] = (counts[line.dataset.cat] || 0) + q;
                total += q * price;
                line.querySelector('[data-line-total]').textContent = euro(q * price);
                line.classList.toggle('is-zero', q === 0);
            });
            var errors = [];
            $$('[data-quota]').forEach(function (qEl) {
                var n = counts[qEl.dataset.quota] || 0, max = parseInt(qEl.dataset.max, 10);
                qEl.querySelector('[data-count]').textContent = n;
                var bar = qEl.querySelector('.bar span');
                if (bar) bar.style.width = Math.min(100, max ? n / max * 100 : 0) + '%';
                var over = max > 0 && n > max;
                qEl.classList.toggle('over', over);
                if (over) errors.push('Quota dépassé : ' + max + ' ' + qEl.dataset.label + ' max par commande');
            });
            var box = $('[data-quota-errors]');
            box.innerHTML = '';
            errors.forEach(function (msg) {
                var p = document.createElement('p');
                p.className = 'alert-red';
                p.setAttribute('role', 'alert');
                p.textContent = '⚠ ' + msg;
                box.appendChild(p);
            });
            $('[data-checkout]').hidden = errors.length > 0 || total === 0;
            $('[data-total]').textContent = euro(total);
        };
        var save = function () {
            var fd = new FormData(cart);
            fetch(cart.action, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function () {
                var n = 0;
                $$('[data-line] input', cart).forEach(function (i) { n += parseInt(i.value, 10) || 0; });
                $$('[data-cart-count]').forEach(function (b) { b.textContent = n; b.hidden = n === 0; });
            });
        };
        cart.addEventListener('input', function (e) {
            if (!e.target.matches('[data-line] input')) return;
            recompute();
            clearTimeout(saveTimer);
            saveTimer = setTimeout(save, 600);
        });
        recompute();
    }

    // ---- Onglets du catalogue : Tous / Viennoiseries / Pains
    var tabs = $$('.tabs [data-filter]');
    tabs.forEach(function (t) {
        t.addEventListener('click', function (ev) {
            ev.preventDefault();
            var f = t.dataset.filter;
            tabs.forEach(function (o) { o.setAttribute('aria-selected', o === t ? 'true' : 'false'); });
            $$('[data-grid] .card').forEach(function (c) { c.hidden = f !== 'tous' && c.dataset.cat !== f; });
            history.replaceState(null, '', t.href);
        });
    });

    // ---- Divers
    document.addEventListener('click', function (ev) {
        var el = ev.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) { ev.preventDefault(); return; }
        if (ev.target.closest('[data-print]')) window.print();
    });
    $$('[data-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { el.form.submit(); });
    });

    // Changement de centre : affiche le point relais
    var cs = $('[data-centre-select]');
    if (cs) cs.addEventListener('change', function () {
        $('[data-relais]').textContent = cs.options[cs.selectedIndex].dataset.relais || '';
    });

    // Contrôle du domaine e-mail dès la saisie (le serveur revérifie)
    var mail = $('input[data-domain]');
    if (mail) {
        var domains = mail.dataset.domain.split(',');
        var errEl = $('[data-domain-error]');
        mail.form.addEventListener('submit', function (ev) {
            var v = mail.value.trim().toLowerCase();
            var ok = domains.some(function (d) { return v.slice(-(d.length + 1)) === '@' + d; });
            errEl.hidden = ok;
            if (!ok) { ev.preventDefault(); mail.focus(); }
        });
    }

    // Filtre de lignes (émargement)
    $$('[data-filter-rows]').forEach(function (input) {
        input.addEventListener('input', function () {
            var v = input.value.trim().toLowerCase();
            $$(input.dataset.filterRows).forEach(function (tr) { tr.hidden = v && tr.dataset.name.indexOf(v) === -1; });
        });
    });

    // Aperçu image (admin)
    var pi = $('[data-preview-input]');
    if (pi) pi.addEventListener('change', function () {
        if (pi.files && pi.files[0]) $('[data-preview]').src = URL.createObjectURL(pi.files[0]);
    });

    // QR code du reçu (bibliothèque locale qrcode-generator, licence MIT)
    window.addEventListener('load', function () {
        $$('[data-qr]').forEach(function (el) {
            if (typeof window.qrcode !== 'function') return;
            var qr = window.qrcode(0, 'M');
            qr.addData(el.dataset.qr);
            qr.make();
            el.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
        });
    });
})();
