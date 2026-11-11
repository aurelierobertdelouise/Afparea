import { html, useState, useEffect } from '../lib/preact.js';
import { api, form, fileUrl } from './api.js';
import { Icon, Form, Field, Empty, Markdown, ActionButton, toast, toastError, ITEM_TYPES, formatDateTime, fileSize, fullName } from './ui.js';

/** Adresse d'intégration pour YouTube et Vimeo ; null pour les autres sites. */
function embedUrl(url) {
  try {
    const u = new URL(url);
    const host = u.hostname.replace(/^www\./, '');
    let id = null;
    if (host === 'youtu.be') id = u.pathname.slice(1);
    else if (host.endsWith('youtube.com')) id = u.searchParams.get('v') || (/^\/(embed|shorts)\/([\w-]+)/.exec(u.pathname) || [])[2];
    if (id && /^[\w-]{6,20}$/.test(id)) return `https://www.youtube-nocookie.com/embed/${id}`;
    const vimeo = host === 'vimeo.com' && /^\/(\d+)/.exec(u.pathname);
    if (vimeo) return `https://player.vimeo.com/video/${vimeo[1]}`;
  } catch { /* adresse invalide : simple lien */ }
  return null;
}

function Files({ files }) {
  if (!files.length) return null;
  return html`<ul class="file-list">${files.map((file) => html`<li><${Icon} name="file" />
    <a href=${fileUrl(file.id)} target="_blank">${file.name}</a> <small class="muted">${fileSize(file.size)}</small></li>`)}</ul>`;
}

function StudentDepot({ item, reload }) {
  const submission = item.submission;
  const graded = submission && submission.grade !== null;
  const [files, setFiles] = useState([]);
  const [comment, setComment] = useState(submission?.comment || '');
  const late = item.data.due_at && new Date(item.data.due_at) < new Date();
  const submit = async () => {
    await api(`/items/${item.id}/submission`, { method: 'POST', body: form({ comment }, files) });
    toast('Production déposée.', 'success');
    setFiles([]);
    reload();
  };
  return html`<section class="card pad">
    <h2>Ma production</h2>
    ${submission
      ? html`<p><span class="badge badge-green"><${Icon} name="check" size=${13} /> Déposée le ${formatDateTime(submission.submitted_at)}</span></p>
          <${Files} files=${submission.files} />`
      : html`<p class="muted">Vous n’avez encore rien déposé.${late ? ' La date limite est dépassée : votre dépôt sera signalé en retard.' : ''}</p>`}
    ${graded
      ? html`<div class="feedback">
          <strong>Évaluation : ${submission.grade}/${item.data.max_grade}</strong>
          ${submission.feedback && html`<${Markdown} text=${submission.feedback} />`}
        </div>`
      : html`${submission?.feedback && html`<div class="feedback"><strong>Commentaire du formateur</strong><${Markdown} text=${submission.feedback} /></div>`}
        <${Form} submitLabel=${submission ? 'Ajouter à mon dépôt' : 'Déposer ma production'} onSubmit=${submit}>
          <${Field} label=${submission ? 'Ajouter des fichiers' : 'Fichiers à déposer'}>
            <input type="file" multiple required=${!submission} onChange=${(e) => setFiles([...e.target.files])} key=${submission?.submitted_at} />
          <//>
          <${Field} label="Message au formateur (facultatif)">
            <textarea rows="3" value=${comment} onInput=${(e) => setComment(e.target.value)}></textarea>
          <//>
        <//>`}
  </section>`;
}

function GradeRow({ entry, item, reload }) {
  const { student, submission } = entry;
  const [grade, setGrade] = useState(submission?.grade ?? '');
  const [feedback, setFeedback] = useState(submission?.feedback || '');
  const [open, setOpen] = useState(false);
  const late = submission && item.data.due_at && submission.submitted_at > item.data.due_at;
  const save = async () => {
    await api(`/submissions/${submission.id}`, { method: 'PATCH', body: { grade: grade === '' ? null : grade, feedback } });
    toast('Évaluation enregistrée.', 'success');
    setOpen(false);
    reload();
  };
  return html`<tr>
    <td><strong>${fullName(student)}</strong><br /><small class="muted">${student.email}</small></td>
    <td>${submission
      ? html`<span class="small">${formatDateTime(submission.submitted_at)}</span> ${late && html`<span class="badge badge-yellow">En retard</span>`}
          <${Files} files=${submission.files} />${submission.comment && html`<p class="small quote">${submission.comment}</p>`}`
      : html`<span class="muted">Rien déposé</span>`}</td>
    <td>${submission && (open
      ? html`<div class="grade-form">
          <label class="small">Note sur ${item.data.max_grade}
            <input type="number" min="0" max=${item.data.max_grade} step="0.25" value=${grade} onInput=${(e) => setGrade(e.target.value)} /></label>
          <textarea rows="3" placeholder="Commentaire pour le stagiaire" aria-label="Commentaire pour le stagiaire" value=${feedback} onInput=${(e) => setFeedback(e.target.value)}></textarea>
          <div class="inline-actions"><${ActionButton} class="btn primary small" onAction=${save}>Enregistrer<//>
            <button class="btn small" onClick=${() => setOpen(false)}>Annuler</button></div>
        </div>`
      : html`${submission.grade !== null ? html`<strong>${submission.grade}/${item.data.max_grade}</strong>` : html`<span class="badge badge-yellow">À évaluer</span>`}
          <button class="link" onClick=${() => setOpen(true)}>${submission.grade !== null ? 'Modifier' : 'Évaluer'}</button>`)}</td>
  </tr>`;
}

export function ItemPage({ itemId }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const reload = () => api(`/items/${itemId}`).then(setData).catch((err) => setError(err.message));
  useEffect(() => { reload(); }, [itemId]);

  if (error) return html`<div class="page"><${Empty} icon="lock" title="Élément inaccessible">${error} <a href="#/">Retour à mes parcours</a><//></div>`;
  if (!data) return html`<div class="page"><p class="muted">Chargement…</p></div>`;
  const { item, role, course } = data;
  const info = ITEM_TYPES[item.type];
  const embed = item.type === 'video' ? embedUrl(item.data.url) : null;
  const complete = (done) => api(`/items/${item.id}/completion`, { method: 'PUT', body: { done } }).then(reload).catch(toastError);
  const submitted = (item.submissions || []).filter((entry) => entry.submission).length;

  return html`<div class="page narrow item-page">
    <a class="back dark" href=${`#/parcours/${course.id}`}><${Icon} name="chevron" size=${14} /> ${course.title}</a>
    <header class="item-head">
      <span class=${`item-icon big type-${item.type}`}><${Icon} name=${info.icon} size=${24} /></span>
      <div><span class="item-kind">${info.label}</span><h1>${item.title}</h1></div>
    </header>

    ${item.type === 'page' && html`<article class="card pad"><${Markdown} text=${item.data.text} /></article>`}
    ${item.type === 'video' && html`<div class="card pad">
      ${embed ? html`<div class="video"><iframe src=${embed} title=${item.title} allow="fullscreen; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>`
        : html`<p><a class="btn primary" href=${item.data.url} target="_blank" rel="noopener noreferrer"><${Icon} name="video" /> Ouvrir la vidéo</a></p>`}
    </div>`}
    ${(item.type === 'dossier' || item.type === 'fichier') && html`<div class="card pad"><${Files} files=${item.files} /></div>`}
    ${item.type === 'lien' && html`<div class="card pad"><a class="btn primary" href=${item.data.url} target="_blank" rel="noopener noreferrer"><${Icon} name="link" /> Ouvrir le lien</a></div>`}

    ${item.type === 'depot' && html`
      <section class="card pad">
        <h2>Consigne</h2>
        ${item.data.instructions ? html`<${Markdown} text=${item.data.instructions} />` : html`<p class="muted">Aucune consigne particulière.</p>`}
        <p class="small muted">${item.data.due_at ? `À rendre avant le ${formatDateTime(item.data.due_at)} · ` : ''}Noté sur ${item.data.max_grade}</p>
      </section>
      ${role === 'stagiaire' ? html`<${StudentDepot} item=${item} reload=${reload} />` : html`<section class="card pad">
        <h2>Productions des stagiaires <small class="muted">${submitted}/${item.submissions.length} remise${submitted > 1 ? 's' : ''}</small></h2>
        ${item.submissions.length ? html`<div class="table-wrap"><table class="table">
          <thead><tr><th>Stagiaire</th><th>Production</th><th>Évaluation</th></tr></thead>
          <tbody>${item.submissions.map((entry) => html`<${GradeRow} key=${entry.student.id} entry=${entry} item=${item} reload=${reload} />`)}</tbody>
        </table></div>` : html`<p class="muted">Aucun stagiaire inscrit au parcours pour l’instant.</p>`}
      </section>`}`}

    ${role === 'stagiaire' && item.type !== 'depot' && html`<div class="done-bar">
      ${item.done ? html`<span class="badge badge-green"><${Icon} name="check" size=${13} /> Terminé</span> <button class="link" onClick=${() => complete(false)}>Annuler</button>`
        : html`<button class="btn primary" onClick=${() => complete(true)}><${Icon} name="check" /> Marquer comme terminé</button>`}
    </div>`}
  </div>`;
}
