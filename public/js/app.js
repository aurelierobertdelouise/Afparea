import { html, render, useState, useEffect } from '/vendor/preact.js';
import { api, setUnauthorizedHandler } from '/js/api.js';
import { Icon, Toasts, Modal, CourseBanner, Form, Field, Progress, Empty, toast, toastError, duration, fullName } from '/js/ui.js';
import { CoursePage, CourseSettingsForm } from '/js/course.js';
import { ItemPage } from '/js/item.js';
import { ParticipantsPage } from '/js/participants.js';
import { UsersPage, TitresPage } from '/js/admin.js';
import { AssistantPage } from '/js/assistant.js';

const ROLE_LABELS = { admin: 'Administration', formateur: 'Formateur·rice', stagiaire: 'Stagiaire' };

function useRoute() {
  const read = () => (location.hash.replace(/^#/, '') || '/');
  const [route, setRoute] = useState(read());
  useEffect(() => {
    const onChange = () => { setRoute(read()); window.scrollTo(0, 0); };
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
  }, []);
  return route;
}

function Login({ onLogin }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  return html`<main class="login">
    <section class="login-brand">
      <img src="/img/logo-afpar.png" alt="" />
      <h1>AFPAREA</h1>
      <p>La plateforme de formation de l’AFPAR : vos parcours, vos ressources et vos productions au même endroit.</p>
    </section>
    <section class="login-panel">
      <h2>Connexion</h2>
      <${Form} submitLabel="Se connecter" onSubmit=${async () => onLogin((await api('/login', { method: 'POST', body: { email, password } })).user)}>
        <${Field} label="Adresse e-mail">
          <input type="email" autocomplete="username" required value=${email} onInput=${(e) => setEmail(e.target.value)} />
        <//>
        <${Field} label="Mot de passe">
          <input type="password" autocomplete="current-password" required value=${password} onInput=${(e) => setPassword(e.target.value)} />
        <//>
      <//>
      <p class="muted small">Mot de passe oublié ? Adressez-vous à votre formateur·rice ou à l’administration de l’AFPAR.</p>
    </section>
  </main>`;
}

function PasswordForm({ user, onDone, forced = false }) {
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirm, setConfirm] = useState('');
  const submit = async () => {
    if (next !== confirm) throw new Error('Les deux saisies du nouveau mot de passe ne correspondent pas.');
    await api('/password', { method: 'POST', body: { current, next } });
    toast('Mot de passe modifié.', 'success');
    setCurrent(''); setNext(''); setConfirm('');
    onDone?.();
  };
  return html`<${Form} submitLabel="Changer le mot de passe" onSubmit=${submit}>
    <input type="text" autocomplete="username" value=${user.email} hidden readonly />
    <${Field} label=${forced ? 'Mot de passe provisoire' : 'Mot de passe actuel'}>
      <input type="password" autocomplete="current-password" required value=${current} onInput=${(e) => setCurrent(e.target.value)} />
    <//>
    <${Field} label="Nouveau mot de passe" hint="10 caractères au minimum.">
      <input type="password" autocomplete="new-password" required minlength="10" value=${next} onInput=${(e) => setNext(e.target.value)} />
    <//>
    <${Field} label="Confirmer le nouveau mot de passe">
      <input type="password" autocomplete="new-password" required minlength="10" value=${confirm} onInput=${(e) => setConfirm(e.target.value)} />
    <//>
  <//>`;
}

function ForcedPassword({ user, onDone, onLogout }) {
  return html`<main class="login">
    <section class="login-brand">
      <img src="/img/logo-afpar.png" alt="" />
      <h1>Bienvenue sur AFPAREA</h1>
      <p>Pour protéger votre compte, choisissez votre propre mot de passe avant de continuer.</p>
    </section>
    <section class="login-panel">
      <h2>Choisir mon mot de passe</h2>
      <${PasswordForm} user=${user} forced onDone=${onDone} />
      <button class="link" onClick=${onLogout}>Se déconnecter</button>
    </section>
  </main>`;
}

function AccountPage({ user }) {
  return html`<div class="page narrow">
    <h1>Mon compte</h1>
    <section class="card pad">
      <dl class="facts">
        <div><dt>Nom</dt><dd>${fullName(user)}</dd></div>
        <div><dt>Adresse e-mail</dt><dd>${user.email}</dd></div>
        <div><dt>Profil</dt><dd>${ROLE_LABELS[user.role]}</dd></div>
      </dl>
    </section>
    <section class="card pad">
      <h2>Changer mon mot de passe</h2>
      <${PasswordForm} user=${user} />
    </section>
  </div>`;
}

function CourseCard({ course }) {
  return html`<a class="course-card card" href=${`#/parcours/${course.id}`}>
    <${CourseBanner} course=${course} />
    <div class="course-card-body">
      <div class="badges">
        ${course.titre && html`<span class="badge badge-titre">${course.titre.code || course.titre.name}</span>`}
        ${course.role === 'formateur' && !course.visible && html`<span class="badge badge-muted"><${Icon} name="eye-off" size=${13} /> Masqué aux stagiaires</span>`}
      </div>
      <h3>${course.title}</h3>
      ${course.titre && html`<p class="muted small">${course.titre.name}${course.titre.level ? ` · ${course.titre.level}` : ''}</p>`}
      <div class="course-card-foot">
        ${course.progress
          ? html`<div class="progress-line"><${Progress} percent=${course.progress.percent} /><span>${course.progress.percent} %</span></div>`
          : html`<span class="muted small"><${Icon} name="users" size=${14} /> ${course.student_count} stagiaire${course.student_count > 1 ? 's' : ''}</span>`}
        ${course.duration_minutes > 0 && html`<span class="muted small"><${Icon} name="clock" size=${14} /> ${duration(course.duration_minutes)}</span>`}
      </div>
    </div>
  </a>`;
}

function Dashboard({ user }) {
  const [courses, setCourses] = useState(null);
  const [creating, setCreating] = useState(false);
  const [filter, setFilter] = useState('');
  const staff = user.role !== 'stagiaire';
  useEffect(() => { api('/courses').then((data) => setCourses(data.courses)).catch(toastError); }, []);

  const titres = [...new Map((courses || []).filter((c) => c.titre).map((c) => [c.titre.id, c.titre])).values()];
  const shown = (courses || []).filter((course) => !filter || String(course.titre?.id) === filter);

  return html`<div class="page">
    <header class="page-head">
      <div>
        <h1>Bonjour ${user.first_name || ''}</h1>
        <p class="muted">${staff ? 'Les parcours que vous animez.' : 'Les parcours de formation auxquels vous êtes inscrit·e.'}</p>
      </div>
      <div class="page-actions">
        ${titres.length > 1 && html`<select aria-label="Filtrer par titre professionnel" value=${filter} onChange=${(e) => setFilter(e.target.value)}>
          <option value="">Tous les titres professionnels</option>
          ${titres.map((titre) => html`<option value=${titre.id}>${titre.name}</option>`)}
        </select>`}
        ${staff && html`<a class="btn" href="#/assistant"><${Icon} name="spark" /> Assistant FPA</a>`}
        ${staff && html`<button class="btn primary" onClick=${() => setCreating(true)}><${Icon} name="plus" /> Nouveau parcours</button>`}
      </div>
    </header>
    ${courses === null ? html`<p class="muted">Chargement…</p>`
      : !shown.length ? html`<${Empty} icon="award" title=${staff ? 'Aucun parcours pour l’instant' : 'Vous n’êtes inscrit·e à aucun parcours'}>
          ${staff ? 'Créez votre premier parcours, ou demandez à l’assistant FPA de le construire à partir d’un REAC et d’un REV.'
            : 'Votre formateur·rice vous inscrira prochainement. Revenez un peu plus tard.'}
        <//>`
      : html`<div class="course-grid">${shown.map((course) => html`<${CourseCard} key=${course.id} course=${course} />`)}</div>`}
    ${creating && html`<${Modal} title="Nouveau parcours" onClose=${() => setCreating(false)}>
      <${CourseSettingsForm} onCancel=${() => setCreating(false)} onSaved=${(id) => { location.hash = `#/parcours/${id}`; }} />
    <//>`}
  </div>`;
}

function Shell({ user, route, onLogout, children }) {
  const staff = user.role !== 'stagiaire';
  const link = (href, icon, label) => {
    const path = href.slice(1);
    const active = path === '/' ? route === '/' || route.startsWith('/parcours') || route.startsWith('/element') : route.startsWith(path);
    return html`<a href=${href} class=${active ? 'active' : ''} aria-current=${active ? 'page' : null}><${Icon} name=${icon} /> <span>${label}</span></a>`;
  };
  return html`<div class="shell">
    <header class="topbar">
      <a class="brand" href="#/"><img src="/img/logo-afpar.png" alt="" /><span>AFPAREA</span></a>
      <nav aria-label="Navigation principale">
        ${link('#/', 'home', 'Mes parcours')}
        ${staff && link('#/assistant', 'spark', 'Assistant FPA')}
        ${staff && link('#/titres', 'award', 'Titres professionnels')}
        ${user.role === 'admin' && link('#/utilisateurs', 'users', 'Utilisateurs')}
      </nav>
      <div class="topbar-user">
        <a href="#/compte" class="user-chip" title="Mon compte">
          <span class="avatar">${(user.first_name?.[0] || user.email[0]).toUpperCase()}</span>
          <span class="user-name">${fullName(user)}<small>${ROLE_LABELS[user.role]}</small></span>
        </a>
        <button class="icon-btn" onClick=${onLogout} aria-label="Se déconnecter" title="Se déconnecter"><${Icon} name="logout" /></button>
      </div>
    </header>
    ${children}
  </div>`;
}

function App() {
  const [user, setUser] = useState(undefined);
  const route = useRoute();
  useEffect(() => {
    setUnauthorizedHandler(() => setUser(null));
    api('/me').then((data) => setUser(data.user)).catch(() => setUser(null));
  }, []);
  const logout = async () => {
    await api('/logout', { method: 'POST' }).catch(() => {});
    location.hash = '#/';
    setUser(null);
  };

  let content;
  if (user === undefined) content = html`<p class="boot">Chargement…</p>`;
  else if (!user) content = html`<${Login} onLogin=${setUser} />`;
  else if (user.must_change_password) {
    content = html`<${ForcedPassword} user=${user} onLogout=${logout} onDone=${() => setUser({ ...user, must_change_password: false })} />`;
  } else {
    const staff = user.role !== 'stagiaire';
    let page;
    let match;
    if ((match = /^\/parcours\/(\d+)\/participants$/.exec(route))) page = html`<${ParticipantsPage} key=${route} courseId=${+match[1]} />`;
    else if ((match = /^\/parcours\/(\d+)$/.exec(route))) page = html`<${CoursePage} key=${route} courseId=${+match[1]} user=${user} />`;
    else if ((match = /^\/element\/(\d+)$/.exec(route))) page = html`<${ItemPage} key=${route} itemId=${+match[1]} />`;
    else if (route === '/compte') page = html`<${AccountPage} user=${user} />`;
    else if (route.startsWith('/assistant') && staff) page = html`<${AssistantPage} />`;
    else if (route === '/titres' && staff) page = html`<${TitresPage} user=${user} />`;
    else if (route === '/utilisateurs' && user.role === 'admin') page = html`<${UsersPage} me=${user} />`;
    else page = html`<${Dashboard} user=${user} />`;
    content = html`<${Shell} user=${user} route=${route} onLogout=${logout}>${page}<//>`;
  }
  return html`${content}<${Toasts} />`;
}

render(html`<${App} />`, document.getElementById('app'));
