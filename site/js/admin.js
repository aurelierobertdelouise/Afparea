import { html, useState, useEffect } from '../lib/preact.js';
import { api } from './api.js';
import { Icon, Modal, Form, Field, Empty, ActionButton, toast, toastError, fullName } from './ui.js';
import { PasswordNotice } from './participants.js';

const ROLES = { stagiaire: 'Stagiaire', formateur: 'Formateur·rice', admin: 'Administration' };

function UserDialog({ user, onClose, onDone }) {
  const [state, setState] = useState({ email: user?.email || '', first_name: user?.first_name || '', last_name: user?.last_name || '', role: user?.role || 'formateur' });
  const set = (patch) => setState((current) => ({ ...current, ...patch }));
  const submit = async () => {
    if (user) {
      await api(`/users/${user.id}`, { method: 'PATCH', body: state });
      onDone(null);
    } else {
      const { password } = await api('/users', { method: 'POST', body: state });
      onDone({ email: state.email, password });
    }
  };
  return html`<${Modal} title=${user ? 'Modifier le compte' : 'Nouveau compte'} onClose=${onClose}>
    <${Form} submitLabel=${user ? 'Enregistrer' : 'Créer le compte'} onCancel=${onClose} onSubmit=${submit}>
      <${Field} label="Adresse e-mail"><input type="email" required disabled=${!!user} value=${state.email} onInput=${(e) => set({ email: e.target.value })} /><//>
      <div class="row">
        <${Field} label="Prénom"><input value=${state.first_name} onInput=${(e) => set({ first_name: e.target.value })} /><//>
        <${Field} label="Nom"><input value=${state.last_name} onInput=${(e) => set({ last_name: e.target.value })} /><//>
      </div>
      <${Field} label="Profil">
        <select value=${state.role} onChange=${(e) => set({ role: e.target.value })}>
          ${Object.entries(ROLES).map(([value, label]) => html`<option value=${value}>${label}</option>`)}
        </select>
      <//>
    <//>
  <//>`;
}

export function UsersPage({ me }) {
  const [users, setUsers] = useState(null);
  const [dialog, setDialog] = useState(null);
  const [query, setQuery] = useState('');
  const reload = () => api('/users').then((data) => setUsers(data.users)).catch(toastError);
  useEffect(() => { reload(); }, []);
  if (!users) return html`<div class="page"><p class="muted">Chargement…</p></div>`;

  const needle = query.trim().toLowerCase();
  const shown = users.filter((user) => !needle || `${fullName(user)} ${user.email}`.toLowerCase().includes(needle));
  const reset = async (user) => {
    if (!confirm(`Générer un nouveau mot de passe provisoire pour ${fullName(user)} ?`)) return;
    const { password } = await api(`/users/${user.id}/reset-password`, { method: 'POST' });
    setDialog({ kind: 'password', email: user.email, password });
  };
  const setActive = async (user, active) => {
    await api(`/users/${user.id}`, { method: 'PATCH', body: { active } });
    toast(active ? 'Compte réactivé.' : 'Compte désactivé.', 'success');
    reload();
  };

  return html`<div class="page">
    <header class="page-head">
      <div><h1>Utilisateurs</h1><p class="muted">${users.length} compte${users.length > 1 ? 's' : ''}</p></div>
      <div class="page-actions">
        <input type="search" placeholder="Rechercher un nom ou un e-mail" aria-label="Rechercher" value=${query} onInput=${(e) => setQuery(e.target.value)} />
        <button class="btn primary" onClick=${() => setDialog({ kind: 'user' })}><${Icon} name="plus" /> Nouveau compte</button>
      </div>
    </header>
    <section class="card"><div class="table-wrap"><table class="table">
      <thead><tr><th>Nom</th><th>E-mail</th><th>Profil</th><th>État</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>${shown.map((user) => html`<tr key=${user.id}>
        <td><strong>${fullName(user)}</strong></td><td>${user.email}</td><td>${ROLES[user.role]}</td>
        <td>${user.active ? html`<span class="badge badge-green">Actif</span>` : html`<span class="badge badge-muted">Désactivé</span>`}</td>
        <td class="actions">
          <button class="icon-btn" title="Modifier" aria-label=${`Modifier ${fullName(user)}`} onClick=${() => setDialog({ kind: 'user', user })}><${Icon} name="pencil" /></button>
          <${ActionButton} class="icon-btn" title="Nouveau mot de passe provisoire" aria-label=${`Nouveau mot de passe pour ${fullName(user)}`} onAction=${() => reset(user)}><${Icon} name="key" /><//>
          ${user.id !== me.id && html`<${ActionButton} class="icon-btn" title=${user.active ? 'Désactiver le compte' : 'Réactiver le compte'}
            aria-label=${`${user.active ? 'Désactiver' : 'Réactiver'} ${fullName(user)}`} onAction=${() => setActive(user, !user.active)}><${Icon} name=${user.active ? 'lock' : 'check'} /><//>`}
        </td>
      </tr>`)}</tbody>
    </table></div></section>
    ${dialog?.kind === 'user' && html`<${UserDialog} user=${dialog.user} onClose=${() => setDialog(null)}
      onDone=${(created) => { setDialog(created ? { kind: 'password', ...created } : null); reload(); }} />`}
    ${dialog?.kind === 'password' && html`<${PasswordNotice} email=${dialog.email} password=${dialog.password} onClose=${() => setDialog(null)} />`}
  </div>`;
}

function TitreDialog({ titre, onClose, onDone }) {
  const [state, setState] = useState({ code: titre?.code || '', name: titre?.name || '', level: titre?.level || '', description: titre?.description || '' });
  const set = (patch) => setState((current) => ({ ...current, ...patch }));
  const submit = async () => {
    await api(titre ? `/titres/${titre.id}` : '/titres', { method: titre ? 'PATCH' : 'POST', body: state });
    onDone();
  };
  return html`<${Modal} title=${titre ? 'Modifier le titre professionnel' : 'Nouveau titre professionnel'} onClose=${onClose}>
    <${Form} submitLabel=${titre ? 'Enregistrer' : 'Ajouter'} onCancel=${onClose} onSubmit=${submit}>
      <${Field} label="Intitulé"><input required maxlength="200" placeholder="Formateur professionnel d’adultes" value=${state.name} onInput=${(e) => set({ name: e.target.value })} /><//>
      <div class="row">
        <${Field} label="Sigle"><input maxlength="40" placeholder="FPA" value=${state.code} onInput=${(e) => set({ code: e.target.value })} /><//>
        <${Field} label="Niveau"><input maxlength="40" placeholder="Niveau 5" value=${state.level} onInput=${(e) => set({ level: e.target.value })} /><//>
      </div>
      <${Field} label="Description"><textarea rows="3" value=${state.description} onInput=${(e) => set({ description: e.target.value })}></textarea><//>
    <//>
  <//>`;
}

export function TitresPage({ user }) {
  const [titres, setTitres] = useState(null);
  const [dialog, setDialog] = useState(null);
  const reload = () => api('/titres').then((data) => setTitres(data.titres)).catch(toastError);
  useEffect(() => { reload(); }, []);
  if (!titres) return html`<div class="page"><p class="muted">Chargement…</p></div>`;
  const remove = async (titre) => {
    if (!confirm(`Supprimer le titre « ${titre.name} » ? Les parcours associés sont conservés, sans titre.`)) return;
    await api(`/titres/${titre.id}`, { method: 'DELETE' });
    reload();
  };
  return html`<div class="page">
    <header class="page-head">
      <div><h1>Titres professionnels</h1><p class="muted">Les titres préparés à l’AFPAR, auxquels se rattachent les parcours.</p></div>
      <div class="page-actions"><button class="btn primary" onClick=${() => setDialog({})}><${Icon} name="plus" /> Nouveau titre</button></div>
    </header>
    ${!titres.length ? html`<${Empty} icon="award" title="Aucun titre professionnel">Ajoutez les titres préparés dans votre centre pour y rattacher vos parcours.<//>`
      : html`<div class="titre-grid">${titres.map((titre) => html`<article class="card pad titre-card" key=${titre.id}>
          <div class="badges">${titre.code && html`<span class="badge badge-titre">${titre.code}</span>`}${titre.level && html`<span class="badge badge-muted">${titre.level}</span>`}</div>
          <h3>${titre.name}</h3>
          ${titre.description && html`<p class="muted small">${titre.description}</p>`}
          <div class="titre-foot">
            <span class="muted small">${titre.course_count} parcours</span>
            <span>
              <button class="icon-btn" title="Modifier" aria-label=${`Modifier ${titre.name}`} onClick=${() => setDialog({ titre })}><${Icon} name="pencil" /></button>
              ${user.role === 'admin' && html`<${ActionButton} class="icon-btn" title="Supprimer" aria-label=${`Supprimer ${titre.name}`} onAction=${() => remove(titre)}><${Icon} name="trash" /><//>`}
            </span>
          </div>
        </article>`)}</div>`}
    ${dialog && html`<${TitreDialog} titre=${dialog.titre} onClose=${() => setDialog(null)} onDone=${() => { setDialog(null); reload(); }} />`}
  </div>`;
}
