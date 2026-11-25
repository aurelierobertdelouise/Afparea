/* Mon Budget — application de gestion de budget (PWA, données stockées sur l'appareil). */
(() => {
  'use strict';

  // ------------------------------------------------------------------ Données
  const STORAGE_KEY = 'mon-budget-v1';
  const CARRY = 'carry'; // catégorie spéciale « Solde précédent »
  const PALETTE = ['#9FBD39', '#51470A', '#2F6B4F', '#C9A227', '#7A9E7E', '#B5651D', '#4F7CAC',
    '#8E5572', '#D17A22', '#5B8C5A', '#A23B2A', '#6D6875', '#3D8D99', '#C1A57B'];

  const DEFAULT_CATEGORIES = [
    ['logement', 'Logement', '🏠', '#51470A', 'depense'],
    ['alimentation', 'Alimentation', '🛒', '#9FBD39', 'depense'],
    ['transport', 'Transport', '🚗', '#4F7CAC', 'depense'],
    ['sante', 'Santé', '🩺', '#3D8D99', 'depense'],
    ['ecole', 'École / Enfants', '🎒', '#C9A227', 'depense'],
    ['telecom', 'Internet / Téléphone', '📱', '#7A9E7E', 'depense'],
    ['credits', 'Crédits', '💳', '#A23B2A', 'depense'],
    ['epargne', 'Épargne', '🐷', '#2F6B4F', 'depense'],
    ['loisirs', 'Loisirs', '🎉', '#8E5572', 'depense'],
    ['autres', 'Autres dépenses', '📦', '#6D6875', 'depense'],
    ['salaire', 'Salaire', '💼', '#5B8C5A', 'revenu'],
    ['aides', 'Aides / Allocations', '🏛️', '#3D8D99', 'revenu'],
    ['virements', 'Virements reçus', '↩️', '#C9A227', 'revenu'],
    ['autres-rev', 'Autres revenus', '➕', '#9FBD39', 'revenu'],
  ].map(([id, name, icon, color, type]) => ({ id, name, icon, color, type, budget: 0 }));

  const DEFAULT_SETTINGS = {
    currency: 'EUR',
    payDay: 28,             // jour de paye dans le mois
    payWeekendShift: true,  // si week-end : paye le vendredi précédent
    carryOver: true,        // reporter le solde du mois clôturé
    minReste: 0,            // seuil d'alerte du reste à vivre
    theme: 'auto',
    showPerDay: true,
    showDonut: true,
    showBudgets: true,
    showForecast: true,
  };

  const uid = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
  const monthKey = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  const addMonths = (key, n) => {
    const [y, m] = key.split('-').map(Number);
    return monthKey(new Date(y, m - 1 + n, 1));
  };
  const todayKey = () => monthKey(new Date());
  const isoDate = (d) => `${monthKey(d)}-${String(d.getDate()).padStart(2, '0')}`;

  function freshState() {
    const key = todayKey();
    return {
      version: 1,
      settings: { ...DEFAULT_SETTINGS },
      categories: DEFAULT_CATEGORIES.map((c) => ({ ...c })),
      current: key,
      months: { [key]: { key, closed: false, items: [] } },
    };
  }

  function load() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return freshState();
      const s = JSON.parse(raw);
      s.settings = { ...DEFAULT_SETTINGS, ...(s.settings || {}) };
      if (!s.months || !Object.keys(s.months).length) return freshState();
      if (!s.months[s.current]) s.current = Object.keys(s.months).sort().pop();
      return s;
    } catch (e) {
      return freshState();
    }
  }

  let state = load();
  let saveFailed = false;
  function save() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch (e) {
      if (!saveFailed) toast('⚠️ Sauvegarde impossible sur cet appareil. Exportez vos données.');
      saveFailed = true;
    }
  }

  // ------------------------------------------------------------------ UI state
  const ui = { tab: 'home', viewing: state.current, filter: 'depense', search: '' };

  // ------------------------------------------------------------------ Outils
  const $ = (sel, root = document) => root.querySelector(sel);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const parseAmount = (v) => {
    const n = parseFloat(String(v).replace(/\s| /g, '').replace(',', '.'));
    return Number.isFinite(n) ? Math.round(n * 100) / 100 : NaN;
  };
  let fmtCache = {};
  const money = (n, opts = {}) => {
    const k = state.settings.currency + (opts.short ? 's' : '');
    if (!fmtCache[k]) {
      try {
        fmtCache[k] = new Intl.NumberFormat('fr-FR', {
          style: 'currency', currency: state.settings.currency,
          maximumFractionDigits: opts.short ? 0 : 2, minimumFractionDigits: opts.short ? 0 : 2,
        });
      } catch (e) { fmtCache[k] = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }); }
    }
    return fmtCache[k].format(n || 0);
  };
  const monthName = (key, style = 'long') => {
    const [y, m] = key.split('-').map(Number);
    const d = new Date(y, m - 1, 1);
    return style === 'short'
      ? d.toLocaleDateString('fr-FR', { month: 'short' }).replace('.', '')
      : d.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
  };
  const fmtDate = (iso) => {
    if (!iso) return '';
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
  };
  const catById = (id) => {
    if (id === CARRY) return { id: CARRY, name: 'Solde précédent', icon: '⏮️', color: '#6B5E14', type: 'any', budget: 0 };
    return state.categories.find((c) => c.id === id) || { id, name: 'Sans catégorie', icon: '❔', color: '#999', type: 'depense', budget: 0 };
  };
  const hexA = (hex, a) => {
    const h = hex.replace('#', '');
    const n = parseInt(h.length === 3 ? h.split('').map((x) => x + x).join('') : h, 16);
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
  };

  // ------------------------------------------------------------------ Calculs
  function monthStats(key) {
    const m = state.months[key];
    const items = m ? m.items : [];
    let inPlan = 0, outPlan = 0, inDone = 0, outDone = 0;
    const byCat = {};
    for (const it of items) {
      if (it.type === 'revenu') { inPlan += it.amount; if (it.done) inDone += it.amount; }
      else {
        outPlan += it.amount; if (it.done) outDone += it.amount;
        if (it.cat !== CARRY) byCat[it.cat] = (byCat[it.cat] || 0) + it.amount;
      }
    }
    return {
      inPlan, outPlan, inDone, outDone,
      reste: inPlan - outPlan,          // reste à vivre prévisionnel
      solde: inDone - outDone,          // solde réel (opérations pointées)
      outPending: outPlan - outDone,    // dépenses encore à passer
      inPending: inPlan - inDone,
      byCat, count: items.length,
    };
  }

  function nextPayDate(from = new Date()) {
    const { payDay, payWeekendShift } = state.settings;
    const make = (y, m) => {
      const last = new Date(y, m + 1, 0).getDate();
      const d = new Date(y, m, Math.min(payDay, last));
      if (payWeekendShift) {
        if (d.getDay() === 6) d.setDate(d.getDate() - 1);
        if (d.getDay() === 0) d.setDate(d.getDate() - 2);
      }
      return d;
    };
    const today = new Date(from.getFullYear(), from.getMonth(), from.getDate());
    let d = make(today.getFullYear(), today.getMonth());
    if (d <= today) d = make(today.getFullYear(), today.getMonth() + 1);
    return d;
  }

  function daysUntil(d) {
    const t = new Date(); const t0 = new Date(t.getFullYear(), t.getMonth(), t.getDate());
    return Math.max(1, Math.round((d - t0) / 86400000));
  }

  // Prévision des mois suivants à partir des opérations récurrentes.
  function forecast(key, n = 6) {
    const m = state.months[key];
    const rec = (m ? m.items : []).filter((it) => it.recurring && it.cat !== CARRY);
    const out = [];
    let carry = state.settings.carryOver ? monthStats(key).reste : 0;
    for (let i = 1; i <= n; i++) {
      const k = addMonths(key, i);
      let inc = 0, exp = 0;
      for (const it of rec) {
        if (it.endMonth && it.endMonth < k) continue;
        if (it.type === 'revenu') inc += it.amount; else exp += it.amount;
      }
      const reste = inc - exp + carry;
      out.push({ key: k, inc, exp, reste });
      if (state.settings.carryOver) carry = reste;
    }
    return out;
  }

  // ------------------------------------------------------------------ Gestion des mois
  function ensureMonth(key) {
    if (!state.months[key]) state.months[key] = { key, closed: false, items: [] };
    return state.months[key];
  }

  // Clôture le mois `key` et prépare le suivant (récurrents + report du solde).
  function closeMonth(key) {
    const m = state.months[key];
    const nextKey = addMonths(key, 1);
    const already = !!state.months[nextKey];
    const next = ensureMonth(nextKey);
    m.closed = true;
    if (!already || !next.items.length) {
      const [ny, nm] = nextKey.split('-').map(Number);
      for (const it of m.items) {
        if (!it.recurring || it.cat === CARRY) continue;
        if (it.endMonth && it.endMonth < nextKey) continue;
        const day = it.date ? Number(it.date.slice(8, 10)) : 1;
        const last = new Date(ny, nm, 0).getDate();
        next.items.push({ ...it, id: uid(), done: false, date: `${nextKey}-${String(Math.min(day, last)).padStart(2, '0')}` });
      }
    }
    if (state.settings.carryOver) {
      next.items = next.items.filter((it) => it.cat !== CARRY);
      const reste = monthStats(key).reste;
      if (Math.abs(reste) >= 0.005) {
        next.items.unshift({
          id: uid(), type: reste >= 0 ? 'revenu' : 'depense', cat: CARRY,
          label: `Solde de ${monthName(key)}`, amount: Math.abs(Math.round(reste * 100) / 100),
          recurring: false, done: true, date: `${nextKey}-01`,
        });
      }
    }
    if (state.current === key || state.current < nextKey) state.current = nextKey;
    save();
    return nextKey;
  }

  // ------------------------------------------------------------------ Rendu
  const view = $('#view');

  function render() {
    document.documentElement.dataset.theme = state.settings.theme === 'auto' ? '' : state.settings.theme;
    if (state.settings.theme === 'auto') delete document.documentElement.dataset.theme;
    const keys = Object.keys(state.months).sort();
    if (!state.months[ui.viewing]) ui.viewing = state.current;
    const m = state.months[ui.viewing];
    $('#month-label').textContent = monthName(ui.viewing);
    $('#month-status').textContent = m.closed ? '🔒 Mois clôturé' : (ui.viewing === state.current ? 'Mois en cours' : 'Mois ouvert');
    $('[data-action="prev-month"]').disabled = keys.indexOf(ui.viewing) <= 0;
    $('[data-action="next-month"]').disabled = keys.indexOf(ui.viewing) >= keys.length - 1;
    document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('is-active', t.dataset.tab === ui.tab));
    const screens = { home: renderHome, moves: renderMoves, history: renderHistory, settings: renderSettings };
    view.innerHTML = screens[ui.tab]();
  }

  function renderHome() {
    const s = monthStats(ui.viewing);
    const st = state.settings;
    const m = state.months[ui.viewing];
    const isNow = ui.viewing === todayKey();
    let html = '';

    // Proposition d'ouvrir le nouveau mois
    const tk = todayKey();
    if (!state.months[tk] && tk > state.current) {
      html += `<div class="alert info" style="margin:0 0 12px"><span>📅</span><span>Nous sommes en <b>${esc(monthName(tk))}</b>. Clôturer ${esc(monthName(state.current))} et démarrer le nouveau mois ?</span><button class="btn btn-sm" data-action="close-month" data-key="${state.current}">Démarrer</button></div>`;
    }

    const pct = s.inPlan > 0 ? Math.min(100, (s.outPlan / s.inPlan) * 100) : (s.outPlan > 0 ? 100 : 0);
    let perDay = '';
    if (st.showPerDay && isNow && !m.closed) {
      const pay = nextPayDate();
      const days = daysUntil(pay);
      const daily = s.reste / days;
      perDay = `<div class="hero__perday">${daily > 0
        ? `Tu peux dépenser <b class="num">${money(daily)}</b> par jour`
        : `<b>Attention</b> : plus de marge jusqu'à la paye`}
        <div class="small" style="opacity:.85">${days} jour${days > 1 ? 's' : ''} avant la prochaine paye (${pay.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })})</div></div>`;
    }

    html += `<section class="hero">
      <div class="hero__label">Reste à vivre ${m.closed ? '' : 'prévu'}</div>
      <div class="hero__amount num ${s.reste < 0 ? 'neg' : 'pos'}">${money(s.reste)}</div>
      <div class="hero__sub">Revenus ${money(s.inPlan)} − Dépenses ${money(s.outPlan)}</div>
      <div class="meter" title="Part des revenus déjà engagée"><span style="width:${pct.toFixed(1)}%"></span></div>
      <div class="hero__sub small" style="margin-top:6px">${pct.toFixed(0)} % des revenus engagés</div>
      ${perDay}
    </section>`;

    html += `<div class="tiles">
      <div class="tile"><div class="tile__label">Revenus</div><div class="tile__value num pos">${money(s.inPlan)}</div><div class="tile__hint">${money(s.inDone)} reçus</div></div>
      <div class="tile"><div class="tile__label">Dépenses</div><div class="tile__value num">${money(s.outPlan)}</div><div class="tile__hint">${money(s.outDone)} passées</div></div>
      <div class="tile"><div class="tile__label">Solde réel (pointé)</div><div class="tile__value num ${s.solde < 0 ? 'neg' : ''}">${money(s.solde)}</div><div class="tile__hint">opérations cochées</div></div>
      <div class="tile"><div class="tile__label">Reste à payer</div><div class="tile__value num">${money(s.outPending)}</div><div class="tile__hint">${money(s.inPending)} à recevoir</div></div>
    </div>`;

    // Alertes
    const alerts = [];
    if (s.reste < st.minReste) alerts.push(`Le reste à vivre (${money(s.reste)}) est sous votre seuil d'alerte (${money(st.minReste)}).`);
    for (const c of state.categories) {
      if (c.type === 'depense' && c.budget > 0 && (s.byCat[c.id] || 0) > c.budget) {
        alerts.push(`${c.icon} ${esc(c.name)} : budget dépassé de ${money((s.byCat[c.id] || 0) - c.budget)}.`);
      }
    }
    html += alerts.map((a) => `<div class="alert"><span>⚠️</span><span>${a}</span></div>`).join('');

    if (!s.count) {
      html += `<div class="card empty" style="margin-top:14px"><div class="empty__ico">🌱</div><p>Aucune opération pour ce mois.<br>Touchez <b>+</b> pour saisir vos revenus et dépenses.</p></div>`;
      return html;
    }

    // Répartition
    const cats = Object.entries(s.byCat).filter(([, v]) => v > 0).sort((a, b) => b[1] - a[1]);
    if (st.showDonut && cats.length) {
      html += `<h2 class="section-title">Répartition des dépenses</h2><div class="card"><div class="donut-wrap">${donut(cats, cats.reduce((a, [, v]) => a + v, 0))}
        <ul class="legend">${cats.slice(0, 7).map(([id, v]) => { const c = catById(id); return `<li><span class="dot" style="background:${c.color}"></span><span class="name">${c.icon} ${esc(c.name)}</span><span class="num">${money(v, { short: true })}</span></li>`; }).join('')}
        ${cats.length > 7 ? `<li class="muted small">+ ${cats.length - 7} autres</li>` : ''}</ul></div></div>`;
    }

    // Budgets
    const budgeted = state.categories.filter((c) => c.type === 'depense' && c.budget > 0);
    if (st.showBudgets && budgeted.length) {
      html += `<h2 class="section-title">Budgets par catégorie</h2><div class="card">${budgeted.map((c) => {
        const spent = s.byCat[c.id] || 0; const p = Math.min(100, (spent / c.budget) * 100);
        const left = c.budget - spent;
        return `<div class="budget-row"><div class="budget-row__head"><span>${c.icon} ${esc(c.name)}</span><span class="num ${left < 0 ? 'neg' : 'muted'}">${left >= 0 ? `reste ${money(left)}` : `+${money(-left)}`}</span></div>
          <div class="bar ${left < 0 ? 'over' : ''}"><span style="width:${p.toFixed(1)}%"></span></div>
          <div class="small muted num" style="margin-top:4px">${money(spent)} / ${money(c.budget)}</div></div>`;
      }).join('')}</div>`;
    }

    // Prévisions
    if (st.showForecast) {
      const fc = forecast(ui.viewing);
      const max = Math.max(1, ...fc.map((f) => Math.abs(f.reste)));
      html += `<h2 class="section-title">Prévisions (6 mois)</h2><div class="card">
        <div class="small muted">Reste prévu selon vos opérations récurrentes${st.carryOver ? ', avec report du solde' : ''}.</div>
        <div class="forecast">${fc.map((f) => `<div class="forecast__col"><span class="forecast__val num ${f.reste < 0 ? 'neg' : ''}">${money(f.reste, { short: true })}</span>
          <span class="forecast__bar ${f.reste < 0 ? 'neg' : ''}" style="height:${Math.max(3, (Math.abs(f.reste) / max) * 80).toFixed(0)}%"></span>
          <span class="forecast__m">${esc(monthName(f.key, 'short'))}</span></div>`).join('')}</div></div>`;
    }
    return html;
  }

  function donut(entries, total) {
    const r = 52, c = 2 * Math.PI * r;
    let off = 0;
    const segs = entries.map(([id, v]) => {
      const len = total > 0 ? (v / total) * c : 0;
      const seg = `<circle r="${r}" cx="66" cy="66" fill="none" stroke="${catById(id).color}" stroke-width="22" stroke-dasharray="${len.toFixed(2)} ${(c - len).toFixed(2)}" stroke-dashoffset="${(-off).toFixed(2)}" transform="rotate(-90 66 66)"/>`;
      off += len; return seg;
    }).join('');
    return `<svg class="donut" viewBox="0 0 132 132" role="img" aria-label="Répartition des dépenses">${segs}
      <text x="66" y="62" text-anchor="middle" font-size="10" fill="currentColor" opacity=".7">Total</text>
      <text x="66" y="78" text-anchor="middle" font-size="14" font-weight="800" fill="currentColor">${esc(money(total, { short: true }))}</text></svg>`;
  }

  function renderMoves() {
    const m = state.months[ui.viewing];
    const q = ui.search.trim().toLowerCase();
    let items = m.items.filter((it) => ui.filter === 'all' || it.type === ui.filter);
    if (q) items = items.filter((it) => (it.label + ' ' + catById(it.cat).name).toLowerCase().includes(q));
    const tot = items.reduce((a, it) => a + (it.type === 'revenu' ? it.amount : -it.amount), 0);
    const done = items.filter((it) => it.done).length;

    let html = `<div class="segmented" role="tablist">
      ${[['depense', 'Dépenses'], ['revenu', 'Revenus'], ['all', 'Tout']].map(([k, l]) => `<button class="${ui.filter === k ? 'is-active' : ''}" data-action="filter" data-filter="${k}">${l}</button>`).join('')}
    </div>
    <input class="search" type="search" placeholder="Rechercher une opération…" value="${esc(ui.search)}" data-input="search" aria-label="Rechercher">
    <div class="totals-strip"><span>${items.length} opération${items.length > 1 ? 's' : ''} · ${done} pointée${done > 1 ? 's' : ''}</span><b class="num ${tot < 0 ? 'neg' : 'pos'}">${money(tot)}</b></div>`;

    if (!items.length) {
      return html + `<div class="card empty" style="margin-top:14px"><div class="empty__ico">🧾</div><p>${q ? 'Aucun résultat.' : 'Rien ici pour le moment.'}</p>${q ? '' : '<button class="btn" data-action="add">Ajouter une opération</button>'}</div>`;
    }

    // Regroupement par catégorie
    const groups = {};
    for (const it of items) (groups[it.cat] = groups[it.cat] || []).push(it);
    const order = Object.keys(groups).sort((a, b) => {
      if (a === CARRY) return -1; if (b === CARRY) return 1;
      return catById(a).name.localeCompare(catById(b).name, 'fr');
    });
    for (const cid of order) {
      const c = catById(cid);
      const list = groups[cid].sort((a, b) => (a.date || '').localeCompare(b.date || '') || a.label.localeCompare(b.label, 'fr'));
      const sum = list.reduce((a, it) => a + it.amount, 0);
      html += `<div class="group"><div class="group__head"><span>${c.icon}</span><span>${esc(c.name)}</span><span class="sum num muted">${money(sum)}</span>
        ${c.budget > 0 ? `<span class="pill">budget ${money(c.budget, { short: true })}</span>` : ''}</div><div class="list">
        ${list.map((it) => `<div class="row ${it.done ? 'is-done' : ''}">
          <button class="check ${it.done ? 'is-on' : ''}" data-action="toggle" data-id="${it.id}" aria-label="${it.done ? 'Dépointer' : 'Pointer'} ${esc(it.label)}" aria-pressed="${it.done}">✓</button>
          <button class="row__main" data-action="edit" data-id="${it.id}">
            <div class="row__title">${esc(it.label)}</div>
            <div class="row__meta">${it.date ? `<span>${fmtDate(it.date)}</span>` : ''}${it.recurring ? '<span class="pill">↻ récurrent</span>' : ''}${it.endMonth ? `<span class="pill">jusqu'à ${esc(monthName(it.endMonth, 'short'))} ${it.endMonth.slice(0, 4)}</span>` : ''}${it.note ? `<span>· ${esc(it.note)}</span>` : ''}</div>
          </button>
          <span class="row__amount num ${it.type === 'revenu' ? 'pos' : ''}">${it.type === 'revenu' ? '+' : '−'}${money(it.amount)}</span>
        </div>`).join('')}</div></div>`;
    }
    return html;
  }

  function renderHistory() {
    const keys = Object.keys(state.months).sort();
    const stats = keys.map((k) => ({ k, ...monthStats(k), closed: state.months[k].closed }));
    const max = Math.max(1, ...stats.map((s) => Math.max(s.inPlan, s.outPlan)));
    const avgOut = stats.reduce((a, s) => a + s.outPlan, 0) / stats.length;
    const avgReste = stats.reduce((a, s) => a + s.reste, 0) / stats.length;
    const cur = state.months[state.current];

    let html = `<div class="card"><div class="hist-chart">${stats.map((s) => `<button class="hist-col ${s.k === ui.viewing ? 'is-current' : ''}" data-action="goto" data-key="${s.k}" style="border:0;background:none" aria-label="${esc(monthName(s.k))}">
        <div class="hist-bars"><span class="in" style="height:${(s.inPlan / max * 100).toFixed(1)}%"></span><span class="out" style="height:${(s.outPlan / max * 100).toFixed(1)}%"></span></div>
        <span class="hist-m">${esc(monthName(s.k, 'short'))}</span></button>`).join('')}</div>
      <div class="chart-legend"><span><i style="background:var(--green)"></i>Revenus</span><span><i style="background:var(--olive-2)"></i>Dépenses</span></div></div>
      <div class="tiles">
        <div class="tile"><div class="tile__label">Dépenses moyennes</div><div class="tile__value num">${money(avgOut)}</div><div class="tile__hint">sur ${stats.length} mois</div></div>
        <div class="tile"><div class="tile__label">Reste moyen</div><div class="tile__value num ${avgReste < 0 ? 'neg' : 'pos'}">${money(avgReste)}</div><div class="tile__hint">par mois</div></div>
      </div>
      <div class="card" style="margin-top:12px">
        <b>Passer au mois suivant</b>
        <p class="small muted" style="margin:6px 0 12px">Clôture et sauvegarde ${esc(monthName(state.current))}, puis crée ${esc(monthName(addMonths(state.current, 1)))} avec vos opérations récurrentes${state.settings.carryOver ? ' et le report du solde' : ''}.</p>
        <button class="btn btn-block btn-olive" data-action="close-month" data-key="${state.current}" ${cur.closed ? 'disabled' : ''}>🔒 Clôturer ${esc(monthName(state.current))}</button>
      </div>
      <h2 class="section-title">Mois sauvegardés</h2><div class="list">`;
    html += stats.slice().reverse().map((s) => `<div class="row">
      <div class="row__ico" style="background:var(--olive-soft)">${s.closed ? '🔒' : '📂'}</div>
      <button class="row__main" data-action="goto" data-key="${s.k}">
        <div class="row__title" style="text-transform:capitalize">${esc(monthName(s.k))}</div>
        <div class="row__meta num"><span>+${money(s.inPlan, { short: true })}</span><span>−${money(s.outPlan, { short: true })}</span><span>${s.count} op.</span></div>
      </button>
      <span class="row__amount num ${s.reste < 0 ? 'neg' : 'pos'}">${money(s.reste)}</span>
      ${s.closed ? `<button class="btn btn-ghost btn-sm" data-action="reopen" data-key="${s.k}">Rouvrir</button>` : ''}
    </div>`).join('');
    html += `</div>
      <div class="btn-row"><button class="btn btn-ghost" data-action="new-past-month">+ Ajouter un mois passé</button></div>`;
    return html;
  }

  function renderSettings() {
    const st = state.settings;
    const sw = (key, label, hint) => `<label class="switch-row"><span class="switch-row__text">${label}${hint ? `<small>${hint}</small>` : ''}</span>
      <span class="switch"><input type="checkbox" data-setting="${key}" ${st[key] ? 'checked' : ''}><span></span></span></label>`;
    const catList = (type) => state.categories.filter((c) => c.type === type).map((c) => `<div class="cat-row">
      <div class="row__ico" style="background:${hexA(c.color, .18)}">${c.icon}</div>
      <div class="cat-row__name"><b>${esc(c.name)}</b>${c.budget > 0 ? `<small>Budget : ${money(c.budget)} / mois</small>` : ''}</div>
      <button class="btn btn-ghost btn-sm" data-action="edit-cat" data-id="${c.id}">Modifier</button></div>`).join('');

    return `<h2 class="section-title" style="margin-top:4px">Paye & calculs</h2><div class="card">
      <label class="field" style="margin-top:0"><span class="field__label">Jour de paye (dans le mois)</span>
        <input class="input" type="number" min="1" max="31" inputmode="numeric" value="${st.payDay}" data-setting-num="payDay"></label>
      ${sw('payWeekendShift', 'Paye avancée si week-end', 'Le samedi ou dimanche devient le vendredi')}
      ${sw('carryOver', 'Reporter le solde', 'Le reste du mois clôturé est ajouté au mois suivant')}
      <label class="field"><span class="field__label">Seuil d'alerte du reste à vivre</span>
        <input class="input" type="text" inputmode="decimal" value="${st.minReste}" data-setting-num="minReste"></label>
      <label class="field"><span class="field__label">Devise</span>
        <select class="input" data-setting-sel="currency">${['EUR', 'CHF', 'CAD', 'USD', 'GBP', 'XPF', 'XOF', 'MAD'].map((c) => `<option ${st.currency === c ? 'selected' : ''}>${c}</option>`).join('')}</select></label>
    </div>

    <h2 class="section-title">Catégories de dépenses</h2><div class="card">${catList('depense')}
      <button class="btn btn-ghost btn-block" style="margin-top:10px" data-action="new-cat" data-type="depense">+ Nouvelle catégorie de dépense</button></div>
    <h2 class="section-title">Catégories de revenus</h2><div class="card">${catList('revenu')}
      <button class="btn btn-ghost btn-block" style="margin-top:10px" data-action="new-cat" data-type="revenu">+ Nouvelle catégorie de revenu</button></div>

    <h2 class="section-title">Affichage</h2><div class="card">
      <label class="field" style="margin-top:0"><span class="field__label">Thème</span>
        <select class="input" data-setting-sel="theme"><option value="auto" ${st.theme === 'auto' ? 'selected' : ''}>Automatique</option><option value="light" ${st.theme === 'light' ? 'selected' : ''}>Clair</option><option value="dark" ${st.theme === 'dark' ? 'selected' : ''}>Sombre</option></select></label>
      ${sw('showPerDay', 'Montant disponible par jour')}
      ${sw('showDonut', 'Répartition des dépenses')}
      ${sw('showBudgets', 'Budgets par catégorie')}
      ${sw('showForecast', 'Prévisions sur 6 mois')}
    </div>

    <h2 class="section-title">Sauvegarde des données</h2><div class="card">
      <p class="small muted" style="margin-top:0">Vos données restent sur cet appareil. Exportez-les régulièrement pour les conserver ou les transférer sur un autre téléphone.</p>
      <div class="btn-row" style="margin-top:0"><button class="btn" data-action="export-json">⬇ Sauvegarder</button><button class="btn btn-ghost" data-action="import-json">⬆ Restaurer</button></div>
      <button class="btn btn-ghost btn-block" style="margin-top:10px" data-action="export-csv">Exporter le mois affiché (tableur CSV)</button>
      <button class="btn btn-danger btn-block" style="margin-top:10px" data-action="reset">Tout effacer</button>
      <input type="file" accept="application/json,.json" id="import-file" hidden>
    </div>
    <p class="small muted" style="text-align:center;margin-top:20px">Mon Budget · données locales, aucun compte requis</p>`;
  }

  // ------------------------------------------------------------------ Feuilles (modales)
  const sheet = $('#sheet'), backdrop = $('#sheet-backdrop');
  let lastFocus = null;
  function openSheet(html) {
    if (sheet.hidden) lastFocus = document.activeElement;
    sheet.innerHTML = `<div class="sheet__grip"></div>${html}`;
    sheet.hidden = false; backdrop.hidden = false;
    document.body.style.overflow = 'hidden';
    const f = sheet.querySelector('[autofocus]') || sheet.querySelector('input,button');
    if (f) setTimeout(() => f.focus(), 60);
  }
  function closeSheet() {
    sheet.hidden = true; backdrop.hidden = true; sheet.innerHTML = '';
    document.body.style.overflow = '';
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  let draft = null;
  function openItemSheet(item) {
    const m = state.months[ui.viewing];
    const isNew = !item;
    const defType = ui.tab === 'moves' && ui.filter === 'revenu' ? 'revenu' : 'depense';
    const now = new Date();
    const defDate = ui.viewing === todayKey() ? isoDate(now) : `${ui.viewing}-01`;
    draft = item ? { ...item } : { id: uid(), type: defType, cat: '', label: '', amount: '', recurring: false, done: true, date: defDate, endMonth: '', note: '' };
    if (!draft.cat) draft.cat = (state.categories.find((c) => c.type === draft.type) || {}).id || '';
    renderItemSheet(isNew, m.closed);
  }

  function renderItemSheet(isNew, locked) {
    const d = draft;
    const cats = state.categories.filter((c) => c.type === d.type);
    const isCarry = d.cat === CARRY;
    // Suggestions issues de l'historique (libellés déjà saisis)
    const seen = new Map();
    Object.keys(state.months).sort().forEach((k) => state.months[k].items.forEach((it) => { if (it.cat !== CARRY) seen.set(it.label, it); }));
    const sugg = [...seen.keys()].slice(-200);

    openSheet(`<h2 class="sheet__title">${isNew ? 'Nouvelle opération' : 'Modifier l\'opération'}<button class="sheet__close" data-action="close-sheet" aria-label="Fermer">✕</button></h2>
      ${locked ? '<div class="alert info"><span>🔒</span><span>Ce mois est clôturé : les modifications restent possibles mais ne changent pas le report déjà effectué.</span></div>' : ''}
      ${isCarry ? '' : `<div class="segmented" style="margin-top:10px">
        <button type="button" class="${d.type === 'depense' ? 'is-active' : ''}" data-action="set-type" data-type="depense">− Dépense</button>
        <button type="button" class="${d.type === 'revenu' ? 'is-active' : ''}" data-action="set-type" data-type="revenu">+ Revenu</button></div>`}
      <form id="item-form" autocomplete="off">
        <input class="amount-input num ${d.type === 'revenu' ? 'pos' : ''}" name="amount" inputmode="decimal" placeholder="0,00 ${esc(state.settings.currency === 'EUR' ? '€' : state.settings.currency)}" value="${d.amount === '' ? '' : String(d.amount).replace('.', ',')}" aria-label="Montant" ${isNew ? 'autofocus' : ''} required>
        <label class="field"><span class="field__label">Libellé</span>
          <input class="input" name="label" list="label-sugg" placeholder="${d.type === 'revenu' ? 'Ex : Salaire' : 'Ex : Courses, Loyer…'}" value="${esc(d.label)}" required></label>
        <datalist id="label-sugg">${sugg.map((l) => `<option value="${esc(l)}">`).join('')}</datalist>
        ${isCarry ? '' : `<div class="field"><span class="field__label">Catégorie</span><div class="chips">
          ${cats.map((c) => `<button type="button" class="chip ${d.cat === c.id ? 'is-active' : ''}" data-action="set-cat" data-id="${c.id}">${c.icon} ${esc(c.name)}</button>`).join('')}</div></div>`}
        <label class="field"><span class="field__label">Date</span><input class="input" type="date" name="date" value="${esc(d.date || '')}"></label>
        <div class="field" style="margin-top:8px">
          <label class="switch-row"><span class="switch-row__text">${d.type === 'revenu' ? 'Reçu' : 'Payé'} (pointé)<small>Cochez quand l'opération apparaît sur votre compte</small></span><span class="switch"><input type="checkbox" name="done" ${d.done ? 'checked' : ''}><span></span></span></label>
          ${isCarry ? '' : `<label class="switch-row"><span class="switch-row__text">Récurrent chaque mois<small>Recopié automatiquement le mois suivant</small></span><span class="switch"><input type="checkbox" name="recurring" ${d.recurring ? 'checked' : ''} data-action="toggle-rec"><span></span></span></label>`}
        </div>
        <label class="field" id="end-field" ${d.recurring ? '' : 'hidden'}><span class="field__label">Dernier mois (facultatif, ex. fin d'un crédit)</span><input class="input" type="month" name="endMonth" value="${esc(d.endMonth || '')}"></label>
        <label class="field"><span class="field__label">Note (facultatif)</span><input class="input" name="note" value="${esc(d.note || '')}"></label>
        <div class="btn-row">${isNew ? '' : '<button type="button" class="btn btn-danger" data-action="delete-item">Supprimer</button>'}<button class="btn" type="submit">${isNew ? 'Ajouter' : 'Enregistrer'}</button></div>
        ${isNew ? '<button type="button" class="btn btn-ghost btn-block" style="margin-top:10px" data-action="save-and-new">Ajouter et saisir une autre</button>' : ''}
      </form>`);
    sheet.dataset.isNew = isNew ? '1' : '';
  }

  // Lit le formulaire dans le brouillon (sans valider).
  function syncDraft() {
    const f = $('#item-form'); if (!f) return;
    draft.amount = f.amount.value;
    draft.label = f.label.value;
    draft.date = f.date.value;
    draft.done = f.done.checked;
    if (f.recurring) draft.recurring = f.recurring.checked;
    draft.endMonth = f.endMonth.value;
    draft.note = f.note.value;
  }

  function commitDraft() {
    syncDraft();
    const amount = parseAmount(draft.amount);
    if (!Number.isFinite(amount) || amount <= 0) { toast('Indiquez un montant valide'); $('#item-form').amount.focus(); return false; }
    if (!draft.label.trim()) { toast('Indiquez un libellé'); $('#item-form').label.focus(); return false; }
    const item = { ...draft, amount, label: draft.label.trim(), note: draft.note.trim(), endMonth: draft.recurring ? draft.endMonth : '' };
    const m = state.months[ui.viewing];
    const i = m.items.findIndex((x) => x.id === item.id);
    if (i >= 0) m.items[i] = item; else m.items.push(item);
    save();
    return true;
  }

  function openCatSheet(cat, type) {
    const isNew = !cat;
    const c = cat ? { ...cat } : { id: uid(), name: '', icon: type === 'revenu' ? '💶' : '🏷️', color: PALETTE[state.categories.length % PALETTE.length], type, budget: 0 };
    const icons = ['🏠', '🛒', '🚗', '⛽', '🩺', '💊', '🎒', '👶', '📱', '💡', '💧', '💳', '🐷', '🎉', '🍽️', '👕', '🐶', '🎁', '✈️', '📚', '🏋️', '🛠️', '💼', '🏛️', '↩️', '💶', '📦', '🏷️'];
    openSheet(`<h2 class="sheet__title">${isNew ? 'Nouvelle catégorie' : 'Modifier la catégorie'}<button class="sheet__close" data-action="close-sheet" aria-label="Fermer">✕</button></h2>
      <form id="cat-form" data-id="${c.id}" data-type="${c.type}" data-new="${isNew ? 1 : ''}">
        <label class="field"><span class="field__label">Nom</span><input class="input" name="name" value="${esc(c.name)}" required ${isNew ? 'autofocus' : ''}></label>
        <div class="field"><span class="field__label">Icône</span><input type="hidden" name="icon" value="${esc(c.icon)}"><div class="chips">${icons.map((i) => `<button type="button" class="chip ${c.icon === i ? 'is-active' : ''}" data-action="pick-icon" data-icon="${i}">${i}</button>`).join('')}</div></div>
        <div class="field"><span class="field__label">Couleur</span><input type="hidden" name="color" value="${c.color}"><div class="swatches">${PALETTE.map((p) => `<button type="button" class="swatch ${c.color === p ? 'is-active' : ''}" style="background:${p}" data-action="pick-color" data-color="${p}" aria-label="Couleur ${p}"></button>`).join('')}</div></div>
        ${c.type === 'depense' ? `<label class="field"><span class="field__label">Budget mensuel (0 = aucun)</span><input class="input" name="budget" inputmode="decimal" value="${c.budget ? String(c.budget).replace('.', ',') : ''}" placeholder="0"></label>` : ''}
        <div class="btn-row">${isNew ? '' : '<button type="button" class="btn btn-danger" data-action="delete-cat">Supprimer</button>'}<button class="btn" type="submit">Enregistrer</button></div>
      </form>`);
  }

  function confirmSheet(title, text, okLabel, onOk, danger) {
    openSheet(`<h2 class="sheet__title">${esc(title)}<button class="sheet__close" data-action="close-sheet" aria-label="Fermer">✕</button></h2>
      <p class="muted">${text}</p>
      <div class="btn-row"><button class="btn btn-ghost" data-action="close-sheet">Annuler</button><button class="btn ${danger ? 'btn-danger' : 'btn-olive'}" data-action="confirm-ok">${esc(okLabel)}</button></div>`);
    pendingConfirm = onOk;
  }
  let pendingConfirm = null;

  // ------------------------------------------------------------------ Toast
  let toastTimer;
  function toast(msg, actionLabel, onAction) {
    const t = $('#toast');
    t.innerHTML = `<span>${esc(msg)}</span>${actionLabel ? `<button>${esc(actionLabel)}</button>` : ''}`;
    t.hidden = false;
    if (actionLabel) t.querySelector('button').onclick = () => { onAction(); t.hidden = true; };
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.hidden = true; }, actionLabel ? 5000 : 2600);
  }

  // ------------------------------------------------------------------ Export / import
  function download(name, content, type) {
    const blob = new Blob([content], { type });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  }
  function exportCSV() {
    const m = state.months[ui.viewing];
    const rows = [['Type', 'Catégorie', 'Libellé', 'Montant', 'Date', 'Récurrent', 'Pointé', 'Fin', 'Note']];
    for (const it of m.items) rows.push([it.type === 'revenu' ? 'Revenu' : 'Dépense', catById(it.cat).name, it.label,
      String(it.type === 'revenu' ? it.amount : -it.amount).replace('.', ','), it.date || '', it.recurring ? 'Oui' : 'Non', it.done ? 'Oui' : 'Non', it.endMonth || '', it.note || '']);
    const s = monthStats(ui.viewing);
    rows.push([], ['', '', 'Total revenus', String(s.inPlan).replace('.', ',')], ['', '', 'Total dépenses', String(-s.outPlan).replace('.', ',')], ['', '', 'Reste à vivre', String(s.reste).replace('.', ',')]);
    const csv = '﻿' + rows.map((r) => r.map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(';')).join('\r\n');
    download(`budget-${ui.viewing}.csv`, csv, 'text/csv;charset=utf-8');
  }

  // ------------------------------------------------------------------ Événements
  document.addEventListener('click', (e) => {
    const tab = e.target.closest('[data-tab]');
    if (tab) { ui.tab = tab.dataset.tab; render(); window.scrollTo(0, 0); return; }
    const el = e.target.closest('[data-action]');
    if (!el) { if (e.target === backdrop) closeSheet(); return; }
    const a = el.dataset.action;
    const keys = Object.keys(state.months).sort();
    const m = state.months[ui.viewing];

    switch (a) {
      case 'prev-month': ui.viewing = keys[Math.max(0, keys.indexOf(ui.viewing) - 1)]; render(); break;
      case 'next-month': ui.viewing = keys[Math.min(keys.length - 1, keys.indexOf(ui.viewing) + 1)]; render(); break;
      case 'goto': ui.viewing = el.dataset.key; ui.tab = 'home'; render(); window.scrollTo(0, 0); break;
      case 'add': openItemSheet(null); break;
      case 'edit': openItemSheet(m.items.find((x) => x.id === el.dataset.id)); break;
      case 'toggle': {
        const it = m.items.find((x) => x.id === el.dataset.id);
        if (it) { it.done = !it.done; save(); render(); }
        break;
      }
      case 'filter': ui.filter = el.dataset.filter; render(); break;
      case 'close-sheet': closeSheet(); break;
      case 'set-type': {
        syncDraft(); draft.type = el.dataset.type;
        draft.cat = (state.categories.find((c) => c.type === draft.type) || {}).id || '';
        renderItemSheet(sheet.dataset.isNew === '1', m.closed); break;
      }
      case 'set-cat': {
        syncDraft(); draft.cat = el.dataset.id;
        sheet.querySelectorAll('[data-action="set-cat"]').forEach((b) => b.classList.toggle('is-active', b === el)); break;
      }
      case 'toggle-rec': $('#end-field').hidden = !el.checked; break;
      case 'save-and-new': {
        if (commitDraft()) { const t = draft.type, c = draft.cat; toast('Opération ajoutée'); render(); openItemSheet(null); draft.type = t; draft.cat = c; renderItemSheet(true, m.closed); }
        break;
      }
      case 'delete-item': {
        const idx = m.items.findIndex((x) => x.id === draft.id);
        if (idx >= 0) {
          const [removed] = m.items.splice(idx, 1); save(); closeSheet(); render();
          toast('Opération supprimée', 'Annuler', () => { m.items.splice(idx, 0, removed); save(); render(); });
        }
        break;
      }
      case 'close-month': {
        const k = el.dataset.key;
        confirmSheet(`Clôturer ${monthName(k)} ?`,
          `Le mois sera sauvegardé dans l'historique. ${esc(monthName(addMonths(k, 1)))} sera créé avec vos opérations récurrentes (à pointer)${state.settings.carryOver ? ` et le report du solde (${esc(money(monthStats(k).reste))})` : ''}.`,
          'Clôturer', () => { const nk = closeMonth(k); ui.viewing = nk; ui.tab = 'home'; render(); toast(`Bienvenue en ${monthName(nk)} !`); });
        break;
      }
      case 'reopen': state.months[el.dataset.key].closed = false; save(); render(); toast('Mois rouvert'); break;
      case 'new-past-month': {
        const first = keys[0]; const k = addMonths(first, -1);
        ensureMonth(k).closed = true; save(); ui.viewing = k; ui.tab = 'moves'; render();
        toast(`${monthName(k)} ajouté : saisissez ses opérations`); break;
      }
      case 'confirm-ok': { const fn = pendingConfirm; pendingConfirm = null; closeSheet(); if (fn) fn(); break; }
      case 'edit-cat': openCatSheet(state.categories.find((c) => c.id === el.dataset.id)); break;
      case 'new-cat': openCatSheet(null, el.dataset.type); break;
      case 'pick-icon': {
        $('#cat-form').icon.value = el.dataset.icon;
        sheet.querySelectorAll('[data-action="pick-icon"]').forEach((b) => b.classList.toggle('is-active', b === el)); break;
      }
      case 'pick-color': {
        $('#cat-form').color.value = el.dataset.color;
        sheet.querySelectorAll('[data-action="pick-color"]').forEach((b) => b.classList.toggle('is-active', b === el)); break;
      }
      case 'delete-cat': {
        const f = $('#cat-form'); const id = f.dataset.id;
        const used = Object.values(state.months).some((mm) => mm.items.some((it) => it.cat === id));
        const same = state.categories.filter((c) => c.type === f.dataset.type);
        if (same.length <= 1) { toast('Gardez au moins une catégorie'); break; }
        const fallback = same.find((c) => c.id !== id);
        confirmSheet('Supprimer la catégorie ?', used ? `Les opérations de cette catégorie seront déplacées vers « ${esc(fallback.name)} ».` : 'Cette catégorie n\'est utilisée par aucune opération.', 'Supprimer', () => {
          Object.values(state.months).forEach((mm) => mm.items.forEach((it) => { if (it.cat === id) it.cat = fallback.id; }));
          state.categories = state.categories.filter((c) => c.id !== id); save(); render();
        }, true);
        break;
      }
      case 'export-json':
        download(`mon-budget-sauvegarde-${isoDate(new Date())}.json`, JSON.stringify(state, null, 2), 'application/json');
        toast('Sauvegarde téléchargée'); break;
      case 'import-json': $('#import-file').click(); break;
      case 'export-csv': exportCSV(); break;
      case 'reset':
        confirmSheet('Tout effacer ?', 'Toutes les opérations, catégories et réglages seront supprimés de cet appareil. Pensez à sauvegarder avant.', 'Tout effacer', () => {
          state = freshState(); ui.viewing = state.current; fmtCache = {}; save(); render(); toast('Données réinitialisées');
        }, true);
        break;
    }
  });

  document.addEventListener('submit', (e) => {
    e.preventDefault();
    if (e.target.id === 'item-form') {
      if (commitDraft()) { const wasNew = sheet.dataset.isNew === '1'; closeSheet(); render(); toast(wasNew ? 'Opération ajoutée' : 'Modifications enregistrées'); }
    } else if (e.target.id === 'cat-form') {
      const f = e.target;
      const name = f.name.value.trim(); if (!name) return;
      const budget = f.budget ? parseAmount(f.budget.value || '0') : 0;
      const cat = { id: f.dataset.id, name, icon: f.icon.value, color: f.color.value, type: f.dataset.type, budget: Number.isFinite(budget) && budget > 0 ? budget : 0 };
      const i = state.categories.findIndex((c) => c.id === cat.id);
      if (i >= 0) state.categories[i] = cat; else state.categories.push(cat);
      save(); closeSheet(); render(); toast('Catégorie enregistrée');
    }
  });

  // Saisie : suggestions de libellé -> pré-remplit catégorie et montant
  document.addEventListener('input', (e) => {
    const t = e.target;
    if (t.dataset.input === 'search') {
      ui.search = t.value; const pos = t.selectionStart; render();
      const s = $('[data-input="search"]'); s.focus(); s.setSelectionRange(pos, pos); return;
    }
    if (t.name === 'label' && t.form && t.form.id === 'item-form') {
      let found = null;
      Object.keys(state.months).sort().forEach((k) => state.months[k].items.forEach((it) => { if (it.label === t.value && it.cat !== CARRY) found = it; }));
      if (found) {
        syncDraft();
        draft.type = found.type; draft.cat = found.cat; draft.recurring = found.recurring;
        if (!parseAmount(draft.amount)) draft.amount = found.amount;
        renderItemSheet(sheet.dataset.isNew === '1', state.months[ui.viewing].closed);
        const l = $('#item-form').label; l.focus(); l.setSelectionRange(l.value.length, l.value.length);
      }
    }
  });

  document.addEventListener('change', (e) => {
    const t = e.target;
    if (t.dataset.setting) { state.settings[t.dataset.setting] = t.checked; save(); render(); }
    else if (t.dataset.settingSel) { state.settings[t.dataset.settingSel] = t.value; fmtCache = {}; save(); render(); }
    else if (t.dataset.settingNum) {
      let v = parseAmount(t.value);
      if (t.dataset.settingNum === 'payDay') v = Math.min(31, Math.max(1, Math.round(v || 1)));
      state.settings[t.dataset.settingNum] = Number.isFinite(v) ? v : 0; save(); render(); toast('Réglage enregistré');
    } else if (t.id === 'import-file' && t.files[0]) {
      const reader = new FileReader();
      reader.onload = () => {
        try {
          const s = JSON.parse(reader.result);
          if (!s.months || !s.categories) throw new Error('format');
          confirmSheet('Restaurer cette sauvegarde ?', `${Object.keys(s.months).length} mois seront chargés et remplaceront les données actuelles.`, 'Restaurer', () => {
            state = s; state.settings = { ...DEFAULT_SETTINGS, ...(s.settings || {}) };
            ui.viewing = state.current; fmtCache = {}; save(); render(); toast('Sauvegarde restaurée');
          });
        } catch (err) { toast('Fichier de sauvegarde invalide'); }
        t.value = '';
      };
      reader.readAsText(t.files[0]);
    }
  });

  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !sheet.hidden) closeSheet(); });

  // ------------------------------------------------------------------ Démarrage
  render();
  if ('serviceWorker' in navigator && location.protocol.startsWith('http')) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }
})();
