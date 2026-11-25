/*
 * Scanner de QR code de retrait — page Émargement.
 * Utilise le lecteur natif du navigateur (BarcodeDetector) s'il existe,
 * sinon la bibliothèque locale jsQR (iPhone / iPad).
 * La caméra n'est active que pendant que le scanner est ouvert.
 */
(function () {
    'use strict';
    var root = document.querySelector('[data-scan]');
    var openBtn = document.querySelector('[data-scan-open]');
    if (!root || !openBtn || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;
    openBtn.hidden = false;

    var video = root.querySelector('video');
    var camBox = root.querySelector('[data-scan-camera]');
    var msg = root.querySelector('[data-scan-msg]');
    var result = root.querySelector('[data-scan-result]');
    var canvas = document.createElement('canvas');
    var ctx = canvas.getContext('2d', { willReadFrequently: true });
    var stream = null, timer = null, busy = false, detector = null;

    if ('BarcodeDetector' in window) {
        try { detector = new window.BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { detector = null; }
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }

    function stopCamera() {
        clearInterval(timer);
        timer = null;
        if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
        stream = null;
    }

    function startScanning() {
        result.hidden = true;
        result.innerHTML = '';
        camBox.hidden = false;
        busy = false;
        msg.textContent = 'Visez le QR code du reçu';
        if (stream) { loop(); return; }
        msg.textContent = 'Activation de la caméra…';
        navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
            .then(function (s) {
                stream = s;
                video.srcObject = s;
                return video.play();
            })
            .then(function () { msg.textContent = 'Visez le QR code du reçu'; loop(); })
            .catch(function () {
                msg.textContent = 'Caméra indisponible ou refusée. Autorisez l\'accès à la caméra pour ce site, ou utilisez le champ « code de retrait ».';
            });
    }

    function loop() {
        clearInterval(timer);
        timer = setInterval(scanFrame, 250);
    }

    function scanFrame() {
        if (busy || !video.videoWidth) return;
        if (detector) {
            busy = true;
            detector.detect(video).then(function (codes) {
                busy = false;
                if (codes.length) found(codes[0].rawValue);
            }).catch(function () { busy = false; detector = null; });
            return;
        }
        if (typeof window.jsQR !== 'function') return;
        var w = Math.min(640, video.videoWidth), h = Math.round(video.videoHeight * w / video.videoWidth);
        canvas.width = w; canvas.height = h;
        ctx.drawImage(video, 0, 0, w, h);
        var img = ctx.getImageData(0, 0, w, h);
        var code = window.jsQR(img.data, w, h, { inversionAttempts: 'dontInvert' });
        if (code && code.data) found(code.data);
    }

    /** Extrait la référence (lien du reçu) ou un code de retrait à 4 chiffres. */
    function parse(text) {
        text = String(text).trim();
        try {
            var u = new URL(text, location.href);
            var ref = u.searchParams.get('ref');
            if (ref) return { ref: ref };
        } catch (e) { /* pas une URL */ }
        if (/^GA[\w-]+$/i.test(text)) return { ref: text };
        if (/^\d{4}$/.test(text)) return { code: text };
        return null;
    }

    function found(text) {
        var q = parse(text);
        if (!q) { msg.textContent = 'QR code non reconnu : ce n\'est pas un reçu Gourmandises.'; return; }
        clearInterval(timer);
        busy = true;
        if (navigator.vibrate) navigator.vibrate(60);
        msg.textContent = 'Recherche de la commande…';
        var url = root.dataset.scanUrl + (q.ref ? '&ref=' + encodeURIComponent(q.ref) : '&code=' + encodeURIComponent(q.code));
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(show)
            .catch(function () { show({ ok: false, erreur: 'Erreur de connexion. Réessayez.' }); });
    }

    function show(d) {
        camBox.hidden = true;
        result.hidden = false;
        var next = '<button type="button" class="btn btn-ghost btn-block" data-scan-next>Scanner suivant</button>';
        if (!d.ok) {
            result.innerHTML = '<div class="scan-alert">' + esc(d.erreur) + '</div>' + next;
            return;
        }
        var warn = '';
        if (d.autre_jour) warn += '<div class="scan-warn">⚠ Commande prévue pour le ' + esc(d.date) + '</div>';
        if (d.autre_centre_admin) warn += '<div class="scan-warn">⚠ Centre de retrait : ' + esc(d.centre) + '</div>';
        var lignes = d.lignes.map(function (l) { return '<li>' + esc(l) + '</li>'; }).join('');
        var action;
        if (d.statut === 'recuperee') {
            action = '<div class="scan-alert">Déjà remise' + (d.remise ? ' à ' + esc(d.remise) : '') + '</div>';
        } else if (d.statut === 'annulee') {
            action = '<div class="scan-alert">Commande annulée : ne pas remettre.</div>';
        } else {
            action = '<button type="button" class="btn btn-cta btn-block scan-give" data-scan-give="' + esc(d.ref) + '">Remis ✓</button>';
        }
        result.innerHTML =
            '<p class="scan-name">' + esc(d.nom) + '</p>' +
            '<p class="scan-meta">' + esc(d.ref) + ' · code <b>' + esc(d.code) + '</b> · ' + esc(d.statut_label) + '</p>' +
            warn + '<ul class="scan-lines">' + lignes + '</ul>' + action + next;
    }

    function give(ref, btn) {
        btn.disabled = true;
        var fd = new FormData();
        fd.append('_csrf', root.dataset.csrf);
        fd.append('ref', ref);
        fd.append('statut', 'recuperee');
        fd.append('ajax', '1');
        fetch(root.dataset.statutUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) throw new Error();
                btn.outerHTML = '<div class="scan-ok">✓ Remise enregistrée à ' + esc(res.heure) + '</div>';
                if (navigator.vibrate) navigator.vibrate([40, 60, 40]);
                markRow(ref, res);
            })
            .catch(function () {
                btn.disabled = false;
                btn.insertAdjacentHTML('afterend', '<div class="scan-alert">Échec de l\'enregistrement. Rechargez la page et réessayez.</div>');
            });
    }

    /** Met à jour la ligne correspondante de la liste d'émargement. */
    function markRow(ref, res) {
        var tr = document.querySelector('tr[data-ref="' + ref.replace(/"/g, '') + '"]');
        if (!tr || tr.classList.contains('done')) return;
        tr.classList.add('done');
        var st = tr.querySelector('[data-status]');
        if (st) { st.textContent = res.label; st.className = 'st st-recuperee'; }
        var sig = tr.querySelector('.sigcell');
        if (sig) sig.innerHTML = '<span class="small">✓ ' + esc(res.heure) + '</span>';
        var counter = document.querySelector('[data-remises]');
        if (counter) counter.textContent = (parseInt(counter.textContent, 10) || 0) + 1;
    }

    function close() {
        stopCamera();
        root.hidden = true;
        document.body.classList.remove('scan-open');
    }

    openBtn.addEventListener('click', function () {
        root.hidden = false;
        document.body.classList.add('scan-open');
        startScanning();
    });
    root.addEventListener('click', function (ev) {
        if (ev.target.closest('[data-scan-close]') || ev.target === root) { close(); return; }
        if (ev.target.closest('[data-scan-next]')) { startScanning(); return; }
        var g = ev.target.closest('[data-scan-give]');
        if (g) give(g.getAttribute('data-scan-give'), g);
    });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !root.hidden) close(); });
    document.addEventListener('visibilitychange', function () { if (document.hidden && !root.hidden) close(); });
})();
