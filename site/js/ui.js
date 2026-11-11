import { fileUrl } from './api.js';
import { html, useState, useEffect, useRef } from '../lib/preact.js';

// ---------- Icônes (tracés 24×24, trait) ----------

const PATHS = {
  eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z M12 9a3 3 0 100 6 3 3 0 000-6z',
  'eye-off': 'M3 3l18 18 M10.6 5.1A10 10 0 0112 5c6.5 0 10 7 10 7a17 17 0 01-3.2 4.1 M6.5 6.6A17 17 0 002 12s3.5 7 10 7a10 10 0 004.2-.9 M9.9 9.9a3 3 0 004.2 4.2',
  clock: 'M12 3a9 9 0 100 18 9 9 0 000-18z M12 7v5l3 2',
  grip: 'M9 6h.01 M15 6h.01 M9 12h.01 M15 12h.01 M9 18h.01 M15 18h.01',
  plus: 'M12 5v14 M5 12h14',
  pencil: 'M4 20h4L19 9l-4-4L4 16v4z M13.5 6.5l4 4',
  trash: 'M4 7h16 M9 7V4h6v3 M6 7l1 13h10l1-13 M10 11v5 M14 11v5',
  file: 'M6 3h8l4 4v14H6z M14 3v4h4',
  folder: 'M3 6h6l2 2h10v11H3z',
  link: 'M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1 M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1',
  video: 'M3 6h13v12H3z M16 10l5-3v10l-5-3',
  page: 'M6 3h12v18H6z M9 8h6 M9 12h6 M9 16h4',
  upload: 'M12 16V4 M7 9l5-5 5 5 M4 20h16',
  download: 'M12 4v12 M7 11l5 5 5-5 M4 20h16',
  check: 'M5 12l5 5 9-10',
  chevron: 'M9 6l6 6-6 6',
  users: 'M9 11a3.5 3.5 0 100-7 3.5 3.5 0 000 7z M2.5 20a6.5 6.5 0 0113 0 M16 4.5a3.5 3.5 0 010 6.5 M18 14.5a6 6 0 013.5 5.5',
  settings: 'M4 7h10 M18 7h2 M4 17h2 M10 17h10 M16 7a2 2 0 11-4 0 2 2 0 014 0z M10 17a2 2 0 11-4 0 2 2 0 014 0z',
  logout: 'M14 4h5v16h-5 M10 8l-4 4 4 4 M6 12h10',
  tag: 'M4 6h16 M4 12h16 M4 18h10',
  lock: 'M6 11h12v9H6z M9 11V8a3 3 0 016 0v3',
  x: 'M6 6l12 12 M18 6L6 18',
  send: 'M4 12l16-8-6 16-3-7-7-1z',
  clip: 'M20 11l-8.5 8.5a5 5 0 01-7-7L13 4a3.5 3.5 0 015 5l-8.5 8.5a2 2 0 01-3-3L14 7',
  spark: 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z M19 16l.7 2.3L22 19l-2.3.7L19 22l-.7-2.3L16 19l2.3-.7z',
  home: 'M4 11l8-7 8 7v9h-5v-6H9v6H4z',
  award: 'M12 3a6 6 0 100 12 6 6 0 000-12z M8.5 14L7 21l5-3 5 3-1.5-7',
  inbox: 'M4 13l3-8h10l3 8v6H4z M4 13h5l1 2h4l1-2h5',
  key: 'M8 15a4 4 0 100-8 4 4 0 000 8z M12 11h9 M18 11v3 M15 11v2',
  calendar: 'M4 6h16v14H4z M4 10h16 M8 3v4 M16 3v4',
};

export function Icon({ name, size = 18 }) {
  return html`<svg class="icon" width=${size} height=${size} viewBox="0 0 24 24" fill="none" stroke="currentColor"
    stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    ${PATHS[name].split(' M').map((part, index) => html`<path d=${index ? `M${part}` : part} />`)}
  </svg>`;
}

export const ITEM_TYPES = {
  page: { label: 'Page', icon: 'page', hint: 'Un contenu rédigé directement sur la plateforme.' },
  fichier: { label: 'Fichier', icon: 'file', hint: 'Un document à consulter ou télécharger (PDF, diaporama…).' },
  dossier: { label: 'Dossier', icon: 'folder', hint: 'Plusieurs fichiers regroupés.' },
  lien: { label: 'Lien web', icon: 'link', hint: 'Une adresse vers un site externe.' },
  video: { label: 'Vidéo', icon: 'video', hint: 'Une vidéo YouTube, Vimeo ou un fichier en ligne.' },
  etiquette: { label: 'Étiquette', icon: 'tag', hint: 'Un texte affiché directement dans le parcours.' },
  depot: { label: 'Dépôt', icon: 'inbox', hint: 'Un espace où les stagiaires déposent leur production.' },
};

export const NODE_LABELS = { module: 'Module', sequence: 'Séquence', seance: 'Séance' };
export const CHILD_OF = { module: 'sequence', sequence: 'seance' };

// ---------- Formats ----------

export function duration(minutes) {
  if (!minutes) return '';
  const h = Math.floor(minutes / 60);
  const m = Math.round(minutes % 60);
  return m ? `${h} h ${String(m).padStart(2, '0')}` : `${h} h`;
}

const dateTime = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const dateOnly = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
export const formatDateTime = (iso) => (iso ? dateTime.format(new Date(iso)).replace(' à ', ' à ') : '');
export const formatDate = (iso) => (iso ? dateOnly.format(new Date(iso)) : '');

export function fileSize(bytes) {
  if (bytes < 1024) return `${bytes} o`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} Ko`;
  return `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} Mo`;
}

/** ISO → valeur d'un champ datetime-local (heure locale), et inversement. */
export function toLocalInput(iso) {
  if (!iso) return '';
  const date = new Date(iso);
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
export const fromLocalInput = (value) => (value ? new Date(value).toISOString() : null);

export const fullName = (user) => `${user.first_name || ''} ${user.last_name || ''}`.trim() || user.email;

// ---------- Notifications ----------

let pushToast = () => {};
export const toast = (message, kind = 'info') => pushToast({ message, kind, id: Math.random() });
export const toastError = (error) => toast(error.message || 'Une erreur est survenue.', 'error');

export function Toasts() {
  const [items, setItems] = useState([]);
  useEffect(() => {
    pushToast = (item) => {
      setItems((list) => [...list, item]);
      setTimeout(() => setItems((list) => list.filter((other) => other.id !== item.id)), item.kind === 'error' ? 7000 : 3500);
    };
  }, []);
  return html`<div class="toasts" role="status" aria-live="polite">
    ${items.map((item) => html`<div key=${item.id} class="toast toast-${item.kind}">${item.message}</div>`)}
  </div>`;
}

// ---------- Fenêtre modale ----------

export function Modal({ title, onClose, children, wide = false }) {
  const ref = useRef();
  useEffect(() => {
    const previous = document.activeElement;
    const onKey = (event) => { if (event.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    ref.current?.querySelector('input:not([type=hidden]), textarea, select, button.primary')?.focus();
    return () => { document.removeEventListener('keydown', onKey); previous?.focus?.(); };
  }, []);
  return html`<div class="modal-backdrop" onMouseDown=${(event) => { if (event.target === event.currentTarget) onClose(); }}>
    <div class=${`modal ${wide ? 'modal-wide' : ''}`} role="dialog" aria-modal="true" aria-label=${title} ref=${ref}>
      <header class="modal-head">
        <h2>${title}</h2>
        <button class="icon-btn" onClick=${onClose} aria-label="Fermer"><${Icon} name="x" /></button>
      </header>
      <div class="modal-body">${children}</div>
    </div>
  </div>`;
}

/** Bouton qui se désactive pendant l'action asynchrone et affiche les erreurs. */
export function ActionButton({ onAction, children, class: className = '', ...rest }) {
  const [busy, setBusy] = useState(false);
  const run = async (event) => {
    event.preventDefault();
    setBusy(true);
    try { await onAction(); } catch (error) { toastError(error); } finally { setBusy(false); }
  };
  return html`<button class=${className} disabled=${busy} onClick=${run} ...${rest}>${children}</button>`;
}

/** Formulaire : gère l'état d'envoi et l'affichage de l'erreur. */
export function Form({ onSubmit, children, submitLabel = 'Enregistrer', onCancel }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    try { await onSubmit(); } catch (err) { setError(err.message); } finally { setBusy(false); }
  };
  return html`<form onSubmit=${submit} class="form">
    ${children}
    ${error && html`<p class="form-error" role="alert">${error}</p>`}
    <div class="form-actions">
      ${onCancel && html`<button type="button" class="btn" onClick=${onCancel}>Annuler</button>`}
      <button type="submit" class="btn primary" disabled=${busy}>${busy ? 'Un instant…' : submitLabel}</button>
    </div>
  </form>`;
}

export function Field({ label, hint, children }) {
  return html`<label class="field"><span class="field-label">${label}</span>${children}${hint && html`<span class="field-hint">${hint}</span>`}</label>`;
}

export function Progress({ percent, label }) {
  return html`<div class="progress" role="progressbar" aria-valuenow=${percent} aria-valuemin="0" aria-valuemax="100" aria-label=${label || 'Progression'}>
    <div class="progress-bar" style=${`width:${percent}%`}></div>
  </div>`;
}

export function Empty({ icon = 'page', title, children }) {
  return html`<div class="empty"><${Icon} name=${icon} size=${32} /><h3>${title}</h3><div>${children}</div></div>`;
}

export function CourseBanner({ course, large = false, children }) {
  const style = course.header_file_id ? `background-image:url(${fileUrl(course.header_file_id)})` : '';
  return html`<div class=${`banner banner-${course.color} ${large ? 'banner-large' : ''} ${course.header_file_id ? 'has-image' : ''}`} style=${style}>
    ${!course.header_file_id && html`<div class="banner-shapes" aria-hidden="true"><i></i><i></i><i></i></div>`}
    ${children}
  </div>`;
}

// ---------- Markdown simple, rendu sans HTML brut ----------

function inline(text) {
  const out = [];
  const pattern = /\*\*(.+?)\*\*|\*(.+?)\*|`(.+?)`|\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)|(https?:\/\/[^\s<]+[^\s<.,;:!?)])/g;
  let last = 0;
  let match;
  while ((match = pattern.exec(text))) {
    if (match.index > last) out.push(text.slice(last, match.index));
    if (match[1]) out.push(html`<strong>${inline(match[1])}</strong>`);
    else if (match[2]) out.push(html`<em>${inline(match[2])}</em>`);
    else if (match[3]) out.push(html`<code>${match[3]}</code>`);
    else if (match[4]) out.push(html`<a href=${match[5]} target="_blank" rel="noopener noreferrer">${match[4]}</a>`);
    else out.push(html`<a href=${match[6]} target="_blank" rel="noopener noreferrer">${match[6]}</a>`);
    last = pattern.lastIndex;
  }
  if (last < text.length) out.push(text.slice(last));
  return out;
}

export function Markdown({ text }) {
  const blocks = [];
  let list = null;
  let paragraph = [];
  const flush = () => {
    if (paragraph.length) blocks.push(html`<p>${paragraph.map((line, i) => [i ? html`<br />` : null, inline(line)])}</p>`);
    paragraph = [];
    if (list) blocks.push(list.ordered ? html`<ol>${list.items}</ol>` : html`<ul>${list.items}</ul>`);
    list = null;
  };
  for (const raw of String(text || '').split(/\r?\n/)) {
    const line = raw.trimEnd();
    const heading = /^(#{1,3})\s+(.*)$/.exec(line);
    const bullet = /^\s*[-*•]\s+(.*)$/.exec(line);
    const numbered = /^\s*\d+[.)]\s+(.*)$/.exec(line);
    if (!line.trim()) flush();
    else if (heading) {
      flush();
      const level = heading[1].length;
      blocks.push(level === 1 ? html`<h2>${inline(heading[2])}</h2>` : level === 2 ? html`<h3>${inline(heading[2])}</h3>` : html`<h4>${inline(heading[2])}</h4>`);
    } else if (bullet || numbered) {
      if (paragraph.length || (list && list.ordered !== !!numbered)) flush();
      list ??= { ordered: !!numbered, items: [] };
      list.items.push(html`<li>${inline((bullet || numbered)[1])}</li>`);
    } else {
      if (list) flush();
      paragraph.push(line);
    }
  }
  flush();
  return html`<div class="prose">${blocks}</div>`;
}

export const MARKDOWN_HINT = 'Mise en forme : **gras**, *italique*, # Titre, - liste, [texte](https://adresse)';
