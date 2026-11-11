import { html, useState, useEffect } from '/vendor/preact.js';
import { api, form } from '/js/api.js';
import { Icon, Modal, Form, Field, Progress, Empty, ActionButton, toast, toastError, fullName } from '/js/ui.js';

const STATUS = {
  cree: ['Compte créé et inscrit', 'badge-green'],
  inscrit: ['Inscrit (compte existant)', 'badge-green'],
  deja_inscrit: ['Déjà inscrit', 'badge-muted'],
  compte_non_stagiaire: ['Ignoré : compte formateur ou administrateur', 'badge-yellow'],
};
const PREVIEW_STATUS = { ...STATUS, cree: ['Compte à créer', 'badge-green'], inscrit: ['À inscrire (compte existant)', 'badge-green'] };

function downloadCsv(name, rows) {
  const cell = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;
  const blob = new Blob([`﻿${rows.map((row) => row.map(cell).join(';')).join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = name;
  link.click();
  setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}

/** Affiche une fois un mot de passe provisoire à transmettre. */
export function PasswordNotice({ email, password, onClose }) {
  return html`<${Modal} title="Mot de passe provisoire" onClose=${onClose}>
    <p>Transmettez ces identifiants à la personne concernée. Elle devra choisir son propre mot de passe à la première connexion.</p>
    <dl class="facts"><div><dt>Adresse e-mail</dt><dd>${email}</dd></div><div><dt>Mot de passe provisoire</dt><dd><code class="secret">${password}</code></dd></div></dl>
    <p class="small muted">Ce mot de passe ne sera plus affiché ensuite.</p>
    <div class="form-actions"><button class="btn primary" onClick=${onClose}>J’ai noté</button></div>
  <//>`;
}

function ImportDialog({ courseId, onClose, onDone }) {
  const [file, setFile] = useState(null);
  const [preview, setPreview] = useState(null);
  const [result, setResult] = useState(null);
  const send = (previewOnly) => api(`/courses/${courseId}/import${previewOnly ? '?preview' : ''}`, { method: 'POST', body: form({}, [file], 'file') });
  const data = result || preview;
  const labels = result ? STATUS : PREVIEW_STATUS;
  const created = (result?.rows || []).filter((row) => row.password);
  const actionable = (preview?.rows || []).filter((row) => row.status === 'cree' || row.status === 'inscrit').length;

  return html`<${Modal} title="Inscrire des stagiaires par lot" onClose=${result ? onDone : onClose} wide>
    ${!data && html`<${Form} submitLabel="Analyser le fichier" onCancel=${onClose} onSubmit=${async () => setPreview(await send(true))}>
      <p>Importez un fichier <strong>Excel (.xlsx)</strong> ou <strong>CSV</strong> contenant une ligne par stagiaire, avec les colonnes
        <code>nom</code>, <code>prénom</code> et <code>email</code>. Les comptes inexistants sont créés automatiquement.</p>
      <${Field} label="Fichier des stagiaires">
        <input type="file" accept=".xlsx,.csv,.txt" required onChange=${(e) => setFile(e.target.files[0])} />
      <//>
      <button type="button" class="link" onClick=${() => downloadCsv('modele-inscription.csv', [['nom', 'prénom', 'email'], ['Payet', 'Lucas', 'lucas.payet@exemple.re']])}>
        <${Icon} name="download" size=${15} /> Télécharger un modèle de fichier</button>
    <//>`}
    ${data && html`
      <p>${result ? html`<strong>Import terminé.</strong> ${created.length} compte${created.length > 1 ? 's' : ''} créé${created.length > 1 ? 's' : ''}.`
        : html`<strong>${actionable}</strong> stagiaire${actionable > 1 ? 's' : ''} à inscrire sur ${data.rows.length} ligne${data.rows.length > 1 ? 's' : ''} valide${data.rows.length > 1 ? 's' : ''}. Vérifiez avant de valider.`}</p>
      ${data.errors.length > 0 && html`<div class="notice notice-warn"><strong>${data.errors.length} ligne${data.errors.length > 1 ? 's' : ''} ignorée${data.errors.length > 1 ? 's' : ''}</strong>
        <ul>${data.errors.map((error) => html`<li>Ligne ${error.line} : ${error.message}</li>`)}</ul></div>`}
      <div class="table-wrap tall"><table class="table">
        <thead><tr><th>Nom</th><th>Prénom</th><th>E-mail</th><th>Résultat</th></tr></thead>
        <tbody>${data.rows.map((row) => html`<tr><td>${row.last_name}</td><td>${row.first_name}</td><td>${row.email}</td>
          <td><span class=${`badge ${labels[row.status][1]}`}>${labels[row.status][0]}</span></td></tr>`)}</tbody>
      </table></div>
      ${result && created.length > 0 && html`<div class="notice">
        <p><strong>Mots de passe provisoires.</strong> Téléchargez-les maintenant : ils ne seront plus affichés. Chaque stagiaire choisira son propre mot de passe à la première connexion.</p>
        <button class="btn primary" onClick=${() => downloadCsv('identifiants-stagiaires.csv', [['nom', 'prénom', 'email', 'mot de passe provisoire'],
          ...created.map((row) => [row.last_name, row.first_name, row.email, row.password])])}><${Icon} name="download" /> Télécharger les identifiants (CSV)</button>
      </div>`}
      <div class="form-actions">
        ${result ? html`<button class="btn primary" onClick=${onDone}>Terminer</button>` : html`
          <button class="btn" onClick=${() => setPreview(null)}>Choisir un autre fichier</button>
          <${ActionButton} class="btn primary" disabled=${!actionable} onAction=${async () => setResult(await send(false))}>Inscrire ${actionable} stagiaire${actionable > 1 ? 's' : ''}<//>`}
      </div>`}
  <//>`;
}

function EnrollDialog({ courseId, onClose, onDone }) {
  const [state, setState] = useState({ email: '', first_name: '', last_name: '', role: 'stagiaire' });
  const set = (patch) => setState((current) => ({ ...current, ...patch }));
  const submit = async () => {
    const { password } = await api(`/courses/${courseId}/enroll`, { method: 'POST', body: state });
    toast('Inscription enregistrée.', 'success');
    onDone(password ? { email: state.email, password } : null);
  };
  return html`<${Modal} title="Inscrire une personne" onClose=${onClose}>
    <${Form} submitLabel="Inscrire" onCancel=${onClose} onSubmit=${submit}>
      <${Field} label="Rôle dans le parcours">
        <select value=${state.role} onChange=${(e) => set({ role: e.target.value })}>
          <option value="stagiaire">Stagiaire</option><option value="formateur">Formateur·rice</option>
        </select>
      <//>
      <${Field} label="Adresse e-mail" hint=${state.role === 'stagiaire' ? 'Si aucun compte n’existe avec cette adresse, il est créé.' : 'Le compte formateur doit déjà exister.'}>
        <input type="email" required value=${state.email} onInput=${(e) => set({ email: e.target.value })} />
      <//>
      ${state.role === 'stagiaire' && html`<div class="row">
        <${Field} label="Prénom"><input value=${state.first_name} onInput=${(e) => set({ first_name: e.target.value })} /><//>
        <${Field} label="Nom"><input value=${state.last_name} onInput=${(e) => set({ last_name: e.target.value })} /><//>
      </div>`}
    <//>
  <//>`;
}

export function ParticipantsPage({ courseId }) {
  const [course, setCourse] = useState(null);
  const [people, setPeople] = useState(null);
  const [dialog, setDialog] = useState(null);
  const [error, setError] = useState('');
  const reload = () => api(`/courses/${courseId}/participants`).then((data) => setPeople(data.participants)).catch((err) => setError(err.message));
  useEffect(() => {
    api(`/courses/${courseId}`).then((data) => setCourse(data.course)).catch((err) => setError(err.message));
    reload();
  }, [courseId]);

  if (error) return html`<div class="page"><${Empty} icon="lock" title="Page inaccessible">${error} <a href="#/">Retour à mes parcours</a><//></div>`;
  if (!course || !people) return html`<div class="page"><p class="muted">Chargement…</p></div>`;

  const students = people.filter((person) => person.role === 'stagiaire');
  const trainers = people.filter((person) => person.role === 'formateur');
  const unenroll = async (person) => {
    if (!confirm(`Désinscrire ${fullName(person)} du parcours ?`)) return;
    await api(`/courses/${courseId}/enroll/${person.id}`, { method: 'DELETE' });
    reload();
  };
  const reset = async (person) => {
    if (!confirm(`Générer un nouveau mot de passe provisoire pour ${fullName(person)} ?`)) return;
    const { password } = await api(`/users/${person.id}/reset-password`, { method: 'POST' });
    setDialog({ kind: 'password', email: person.email, password });
  };
  const table = (rows, withProgress) => html`<div class="table-wrap"><table class="table">
    <thead><tr><th>Nom</th><th>E-mail</th>${withProgress && html`<th>Progression</th>`}<th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>${rows.map((person) => html`<tr key=${person.id}>
      <td><strong>${fullName(person)}</strong>${!person.active && html` <span class="badge badge-muted">Compte désactivé</span>`}</td>
      <td>${person.email}</td>
      ${withProgress && html`<td><div class="progress-line"><${Progress} percent=${person.progress.percent} label=${`Progression de ${fullName(person)}`} />
        <span>${person.progress.done}/${person.progress.total}</span></div></td>`}
      <td class="actions">
        ${withProgress && html`<${ActionButton} class="icon-btn" title="Nouveau mot de passe provisoire" aria-label=${`Nouveau mot de passe pour ${fullName(person)}`} onAction=${() => reset(person)}><${Icon} name="key" /><//>`}
        <${ActionButton} class="icon-btn" title="Désinscrire" aria-label=${`Désinscrire ${fullName(person)}`} onAction=${() => unenroll(person)}><${Icon} name="trash" /><//>
      </td>
    </tr>`)}</tbody>
  </table></div>`;

  return html`<div class="page">
    <a class="back dark" href=${`#/parcours/${courseId}`}><${Icon} name="chevron" size=${14} /> ${course.title}</a>
    <header class="page-head">
      <div><h1>Participants</h1><p class="muted">${students.length} stagiaire${students.length > 1 ? 's' : ''} · ${trainers.length} formateur${trainers.length > 1 ? 's' : ''}</p></div>
      <div class="page-actions">
        <button class="btn" onClick=${() => setDialog({ kind: 'enroll' })}><${Icon} name="plus" /> Inscrire une personne</button>
        <button class="btn primary" onClick=${() => setDialog({ kind: 'import' })}><${Icon} name="upload" /> Importer un fichier Excel / CSV</button>
      </div>
    </header>
    <section class="card">
      <h2 class="card-title">Stagiaires</h2>
      ${students.length ? table(students, true) : html`<${Empty} icon="users" title="Aucun stagiaire inscrit">Importez la liste de la session depuis un fichier Excel ou CSV, ou inscrivez les stagiaires un par un.<//>`}
    </section>
    <section class="card"><h2 class="card-title">Formateurs</h2>${table(trainers, false)}</section>

    ${dialog?.kind === 'import' && html`<${ImportDialog} courseId=${courseId} onClose=${() => setDialog(null)} onDone=${() => { setDialog(null); reload(); }} />`}
    ${dialog?.kind === 'enroll' && html`<${EnrollDialog} courseId=${courseId} onClose=${() => setDialog(null)}
      onDone=${(created) => { setDialog(created ? { kind: 'password', ...created } : null); reload(); }} />`}
    ${dialog?.kind === 'password' && html`<${PasswordNotice} email=${dialog.email} password=${dialog.password} onClose=${() => setDialog(null)} />`}
  </div>`;
}
