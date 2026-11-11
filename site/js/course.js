import { html, useState, useEffect, useRef } from '../lib/preact.js';
import Sortable from '../lib/sortable.js';
import { api, form, fileUrl } from './api.js';
import {
  Icon, Modal, Form, Field, Progress, Empty, Markdown, CourseBanner, ActionButton, toast, toastError,
  ITEM_TYPES, NODE_LABELS, CHILD_OF, MARKDOWN_HINT,
  duration, formatDateTime, formatDate, fileSize, toLocalInput, fromLocalInput,
} from './ui.js';

const sumMinutes = (node) => (node.type === 'seance' ? node.duration_minutes : (node.children || []).reduce((sum, child) => sum + sumMinutes(child), 0));

// ---------- Disponibilité : visible / masqué / programmé ----------

function AvailabilityFields({ value, onChange }) {
  return html`<fieldset class="fieldset">
    <legend>Disponibilité pour les stagiaires</legend>
    <label class="check">
      <input type="checkbox" checked=${value.visible} onChange=${(e) => onChange({ ...value, visible: e.target.checked })} />
      <span>Visible par les stagiaires</span>
    </label>
    <${Field} label="Mise à disposition programmée" hint="Laissez vide pour une disponibilité immédiate. Avant cette date, les stagiaires voient le titre mais pas le contenu.">
      <input type="datetime-local" value=${toLocalInput(value.available_from)} onInput=${(e) => onChange({ ...value, available_from: fromLocalInput(e.target.value) })} />
    <//>
  </fieldset>`;
}

function StateBadges({ row }) {
  return html`${!row.visible && html`<span class="badge badge-muted"><${Icon} name="eye-off" size=${13} /> Masqué</span>`}
    ${row.scheduled && html`<span class="badge badge-yellow"><${Icon} name="clock" size=${13} /> Disponible le ${formatDateTime(row.available_from)}</span>`}`;
}

function ScheduleDialog({ target, onClose, onSaved }) {
  const [value, setValue] = useState(toLocalInput(target.row.available_from));
  const save = async (availableFrom) => {
    await api(`/${target.kind}s/${target.row.id}`, { method: 'PATCH', body: { available_from: availableFrom } });
    toast(availableFrom ? 'Mise à disposition programmée.' : 'Programmation retirée.', 'success');
    onSaved();
  };
  return html`<${Modal} title="Programmer la mise à disposition" onClose=${onClose}>
    <p class="muted">« ${target.row.title} » deviendra accessible aux stagiaires automatiquement à la date choisie.</p>
    <${Form} submitLabel="Programmer" onCancel=${onClose} onSubmit=${() => save(fromLocalInput(value))}>
      <${Field} label="Disponible à partir du">
        <input type="datetime-local" required value=${value} onInput=${(e) => setValue(e.target.value)} />
      <//>
    <//>
    ${target.row.available_from && html`<${ActionButton} class="link" onAction=${() => save(null)}>Retirer la programmation (disponible dès maintenant)<//>`}
  <//>`;
}

// ---------- Formulaires ----------

export function CourseSettingsForm({ course, onSaved, onCancel }) {
  const [titres, setTitres] = useState([]);
  const [state, setState] = useState({
    title: course?.title || '', summary: course?.summary || '', color: course?.color || 'navy',
    titre_id: course?.titre?.id || '', visible: course?.visible || false,
    start_date: course?.start_date?.slice(0, 10) || '', end_date: course?.end_date?.slice(0, 10) || '',
  });
  const set = (patch) => setState((current) => ({ ...current, ...patch }));
  useEffect(() => { api('/titres').then((data) => setTitres(data.titres)).catch(toastError); }, []);

  const submit = async () => {
    const body = { ...state, titre_id: state.titre_id || null, start_date: state.start_date || null, end_date: state.end_date || null };
    if (course) {
      await api(`/courses/${course.id}`, { method: 'PATCH', body });
      onSaved(course.id);
    } else {
      delete body.visible;
      onSaved((await api('/courses', { method: 'POST', body })).id);
    }
  };

  return html`<${Form} submitLabel=${course ? 'Enregistrer' : 'Créer le parcours'} onCancel=${onCancel} onSubmit=${submit}>
    <${Field} label="Titre du parcours">
      <input required maxlength="200" value=${state.title} onInput=${(e) => set({ title: e.target.value })} />
    <//>
    <${Field} label="Titre professionnel préparé" hint=${html`Gérez la liste dans « <a href="#/titres">Titres professionnels</a> ».`}>
      <select value=${state.titre_id} onChange=${(e) => set({ titre_id: e.target.value })}>
        <option value="">— Aucun —</option>
        ${titres.map((titre) => html`<option value=${titre.id}>${titre.name}${titre.level ? ` (${titre.level})` : ''}</option>`)}
      </select>
    <//>
    <${Field} label="Présentation">
      <textarea rows="3" value=${state.summary} onInput=${(e) => set({ summary: e.target.value })}></textarea>
    <//>
    <div class="row">
      <${Field} label="Début"><input type="date" value=${state.start_date} onInput=${(e) => set({ start_date: e.target.value })} /><//>
      <${Field} label="Fin"><input type="date" value=${state.end_date} onInput=${(e) => set({ end_date: e.target.value })} /><//>
    </div>
    <div class="field">
      <span class="field-label">Couleur du parcours</span>
      <div class="swatches" role="radiogroup" aria-label="Couleur du parcours">
        ${[['navy', 'Bleu nuit'], ['green', 'Vert'], ['yellow', 'Jaune']].map(([color, label]) => html`
          <button type="button" role="radio" aria-checked=${state.color === color} aria-label=${label} title=${label}
            class=${`swatch swatch-${color}`} onClick=${() => set({ color })}></button>`)}
      </div>
    </div>
    ${course && html`<label class="check">
      <input type="checkbox" checked=${state.visible} onChange=${(e) => set({ visible: e.target.checked })} />
      <span>Parcours ouvert aux stagiaires inscrits</span>
    </label>`}
  <//>`;
}

function SettingsDialog({ course, onClose, onSaved }) {
  const input = useRef();
  const uploadHeader = async (file) => {
    if (!file) return;
    try {
      await api(`/courses/${course.id}/header`, { method: 'POST', body: form({}, [file], 'image') });
      toast('Image d’en-tête mise à jour.', 'success');
      onSaved(false);
    } catch (error) { toastError(error); }
  };
  const remove = async () => {
    if (!confirm(`Supprimer définitivement le parcours « ${course.title} », son contenu et les productions déposées ?`)) return;
    await api(`/courses/${course.id}`, { method: 'DELETE' });
    location.hash = '#/';
  };
  return html`<${Modal} title="Paramètres du parcours" onClose=${onClose}>
    <div class="field">
      <span class="field-label">Image d’en-tête</span>
      <${CourseBanner} course=${course} />
      <div class="inline-actions">
        <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" hidden ref=${input} onChange=${(e) => uploadHeader(e.target.files[0])} />
        <button type="button" class="btn" onClick=${() => input.current.click()}><${Icon} name="upload" /> ${course.header_file_id ? 'Changer l’image' : 'Choisir une image'}</button>
        ${course.header_file_id && html`<${ActionButton} class="btn" onAction=${async () => { await api(`/courses/${course.id}/header`, { method: 'DELETE' }); onSaved(false); }}>Retirer l’image<//>`}
      </div>
      <span class="field-hint">Format paysage conseillé (1600 × 400 px), PNG, JPEG ou WebP.</span>
    </div>
    <${CourseSettingsForm} course=${course} onCancel=${onClose} onSaved=${() => { toast('Parcours enregistré.', 'success'); onSaved(true); }} />
    <hr />
    <${ActionButton} class="btn danger" onAction=${remove}><${Icon} name="trash" /> Supprimer ce parcours<//>
  <//>`;
}

function NodeDialog({ courseId, node, type, parentId, onClose, onSaved }) {
  const [state, setState] = useState({
    title: node?.title || '', description: node?.description || '',
    hours: node ? node.duration_minutes / 60 || '' : '',
    visible: node ? node.visible : true, available_from: node?.available_from || null,
  });
  const set = (patch) => setState((current) => ({ ...current, ...patch }));
  const label = NODE_LABELS[type].toLowerCase();
  const submit = async () => {
    const body = {
      title: state.title, description: state.description, visible: state.visible, available_from: state.available_from,
      duration_minutes: type === 'seance' ? Math.round((Number(state.hours) || 0) * 60) : 0,
    };
    if (node) await api(`/nodes/${node.id}`, { method: 'PATCH', body });
    else await api(`/courses/${courseId}/nodes`, { method: 'POST', body: { ...body, parent_id: parentId } });
    onSaved();
  };
  return html`<${Modal} title=${node ? `Modifier ${type === 'module' ? 'le' : 'la'} ${label}` : `${type === 'module' ? 'Nouveau' : 'Nouvelle'} ${label}`} onClose=${onClose}>
    <${Form} onCancel=${onClose} onSubmit=${submit} submitLabel=${node ? 'Enregistrer' : 'Ajouter'}>
      <${Field} label="Titre"><input required maxlength="200" value=${state.title} onInput=${(e) => set({ title: e.target.value })} /><//>
      ${type === 'seance' && html`<${Field} label="Durée (en heures)" hint="Par exemple 3,5 pour une demi-journée ou 7 pour une journée.">
        <input type="number" min="0" max="1000" step="0.25" value=${state.hours} onInput=${(e) => set({ hours: e.target.value })} />
      <//>`}
      <${Field} label=${type === 'seance' ? 'Objectifs et déroulé' : 'Description'} hint=${MARKDOWN_HINT}>
        <textarea rows="4" value=${state.description} onInput=${(e) => set({ description: e.target.value })}></textarea>
      <//>
      <${AvailabilityFields} value=${state} onChange=${set} />
    <//>
  <//>`;
}

function ItemDialog({ courseId, nodeId, item, onClose, onSaved }) {
  const [type, setType] = useState(item?.type || null);
  const [state, setState] = useState({
    title: item?.title || '', text: item?.data.text || '', url: item?.data.url || '',
    instructions: item?.data.instructions || '', due_at: item?.data.due_at || null, max_grade: item?.data.max_grade || 20,
    visible: item ? item.visible : true, available_from: item?.available_from || null,
  });
  const [files, setFiles] = useState([]);
  const [existing, setExisting] = useState(item?.files || []);
  const set = (patch) => setState((current) => ({ ...current, ...patch }));

  if (!type) {
    return html`<${Modal} title="Ajouter une ressource ou une activité" onClose=${onClose}>
      <div class="type-grid">
        ${Object.entries(ITEM_TYPES).map(([key, info]) => html`<button class="type-choice" onClick=${() => setType(key)}>
          <span class=${`item-icon type-${key}`}><${Icon} name=${info.icon} size=${22} /></span>
          <strong>${info.label}</strong><small>${info.hint}</small>
        </button>`)}
      </div>
    <//>`;
  }

  const submit = async () => {
    const fields = { title: state.title, visible: state.visible ? '1' : '0', available_from: state.available_from };
    if (type === 'page' || type === 'etiquette') fields.text = state.text;
    if (type === 'lien' || type === 'video') fields.url = state.url;
    if (type === 'depot') Object.assign(fields, { instructions: state.instructions, due_at: state.due_at, max_grade: state.max_grade });
    if (item) await api(`/items/${item.id}`, { method: 'PATCH', body: form(fields, files) });
    else await api(`/courses/${courseId}/items`, { method: 'POST', body: form({ ...fields, type, node_id: nodeId }, files) });
    onSaved();
  };
  const removeFile = async (file) => {
    await api(`/files/${file.id}`, { method: 'DELETE' });
    setExisting((list) => list.filter((other) => other.id !== file.id));
  };
  const info = ITEM_TYPES[type];

  return html`<${Modal} title=${`${item ? 'Modifier' : 'Ajouter'} : ${info.label.toLowerCase()}`} onClose=${onClose} wide=${type === 'page'}>
    <${Form} onCancel=${onClose} onSubmit=${submit} submitLabel=${item ? 'Enregistrer' : 'Ajouter'}>
      ${type !== 'etiquette' && html`<${Field} label="Titre"><input required maxlength="200" value=${state.title} onInput=${(e) => set({ title: e.target.value })} /><//>`}
      ${(type === 'page' || type === 'etiquette') && html`<${Field} label=${type === 'page' ? 'Contenu de la page' : 'Texte affiché'} hint=${MARKDOWN_HINT}>
        <textarea rows=${type === 'page' ? 14 : 4} required value=${state.text} onInput=${(e) => set({ text: e.target.value })}></textarea>
      <//>`}
      ${(type === 'lien' || type === 'video') && html`<${Field} label=${type === 'video' ? 'Adresse de la vidéo' : 'Adresse web'}
        hint=${type === 'video' ? 'Lien YouTube ou Vimeo (lecture intégrée), ou lien direct vers un fichier vidéo.' : null}>
        <input type="url" required placeholder="https://" value=${state.url} onInput=${(e) => set({ url: e.target.value })} />
      <//>`}
      ${(type === 'fichier' || type === 'dossier') && html`<div class="field">
        <span class="field-label">${type === 'fichier' ? 'Fichier' : 'Fichiers du dossier'}</span>
        ${existing.length > 0 && html`<ul class="file-list">${existing.map((file) => html`<li>
          <${Icon} name="file" /> <span>${file.name}</span> <small class="muted">${fileSize(file.size)}</small>
          ${type === 'dossier' && html`<${ActionButton} class="icon-btn" aria-label=${`Retirer ${file.name}`} onAction=${() => removeFile(file)}><${Icon} name="trash" /><//>`}
        </li>`)}</ul>`}
        <input type="file" multiple=${type === 'dossier'} required=${!item && type === 'fichier'} onChange=${(e) => setFiles([...e.target.files])} />
        ${item && type === 'fichier' && html`<span class="field-hint">Choisir un nouveau fichier remplace l’actuel.</span>`}
      </div>`}
      ${type === 'depot' && html`
        <${Field} label="Consigne" hint=${MARKDOWN_HINT}>
          <textarea rows="6" value=${state.instructions} onInput=${(e) => set({ instructions: e.target.value })}></textarea>
        <//>
        <div class="row">
          <${Field} label="Date limite de dépôt (facultative)">
            <input type="datetime-local" value=${toLocalInput(state.due_at)} onInput=${(e) => set({ due_at: fromLocalInput(e.target.value) })} />
          <//>
          <${Field} label="Note sur">
            <input type="number" min="1" max="1000" required value=${state.max_grade} onInput=${(e) => set({ max_grade: e.target.value })} />
          <//>
        </div>`}
      <${AvailabilityFields} value=${state} onChange=${set} />
    <//>
  <//>`;
}

// ---------- Glisser-déposer ----------

/**
 * Liste réordonnable. Après un déplacement, le DOM est remis dans son état d'origine
 * (Preact reste maître de l'affichage) puis l'ordre est enregistré et le parcours rechargé.
 */
function SortableList({ group, parentId, enabled, onMove, class: className, children }) {
  const ref = useRef();
  const handler = useRef(onMove);
  handler.current = onMove;
  useEffect(() => {
    if (!enabled) return undefined;
    const sortable = Sortable.create(ref.current, {
      group, handle: '.grip', animation: 150, ghostClass: 'drag-ghost', chosenClass: 'drag-chosen',
      // Déplacement géré par Sortable (et non le glisser-déposer natif) : même comportement à la souris et au doigt.
      forceFallback: true, fallbackOnBody: true, fallbackTolerance: 4, swapThreshold: 0.65, emptyInsertThreshold: 24,
      onStart() { document.body.classList.add('dragging'); },
      onEnd(event) {
        document.body.classList.remove('dragging');
        if (event.from === event.to && event.oldIndex === event.newIndex) return;
        const ids = [...event.to.children].map((child) => Number(child.dataset.id));
        const destination = event.to.dataset.parent ? Number(event.to.dataset.parent) : null;
        event.item.remove();
        event.from.insertBefore(event.item, event.from.children[event.oldIndex] || null);
        handler.current(destination, ids);
      },
    });
    return () => sortable.destroy();
  }, [enabled]);
  return html`<div ref=${ref} class=${`${className} ${enabled ? 'sortable' : ''}`} data-parent=${parentId || ''}>${children}</div>`;
}

// ---------- Éléments ----------

function itemLink(item) {
  if (item.type === 'fichier' && item.files[0]) return { href: fileUrl(item.files[0].id), target: '_blank' };
  if (item.type === 'lien') return { href: item.data.url, target: '_blank', rel: 'noopener noreferrer' };
  return { href: `#/element/${item.id}` };
}

function DepotStatus({ item, trainer }) {
  if (trainer) {
    const stats = item.submission_stats;
    return html`<span class="badge ${stats.pending ? 'badge-yellow' : 'badge-muted'}">${stats.n} remise${stats.n > 1 ? 's' : ''}${stats.pending ? ` · ${stats.pending} à évaluer` : ''}</span>`;
  }
  if (item.submission) {
    return item.submission.grade !== null
      ? html`<span class="badge badge-green">Évalué : ${item.submission.grade}/${item.data.max_grade}</span>`
      : html`<span class="badge badge-green">Remis</span>`;
  }
  return item.data.due_at ? html`<span class="badge badge-yellow">À rendre avant le ${formatDateTime(item.data.due_at)}</span>` : html`<span class="badge badge-muted">À rendre</span>`;
}

function ItemRow({ item, ctx }) {
  const info = ITEM_TYPES[item.type];
  const dimmed = ctx.trainer && (!item.visible || item.scheduled);
  const tools = ctx.edit && html`<div class="tools">
    <button class="icon-btn" title=${item.visible ? 'Masquer aux stagiaires' : 'Rendre visible'} aria-label=${item.visible ? 'Masquer aux stagiaires' : 'Rendre visible'}
      aria-pressed=${!item.visible} onClick=${() => ctx.toggle('item', item)}><${Icon} name=${item.visible ? 'eye' : 'eye-off'} /></button>
    <button class=${`icon-btn ${item.available_from ? 'on' : ''}`} title="Programmer la mise à disposition" aria-label="Programmer la mise à disposition"
      onClick=${() => ctx.open({ kind: 'schedule', target: { kind: 'item', row: item } })}><${Icon} name="clock" /></button>
    <button class="icon-btn" title="Modifier" aria-label="Modifier" onClick=${() => ctx.open({ kind: 'item', item })}><${Icon} name="pencil" /></button>
    <button class="icon-btn" title="Supprimer" aria-label="Supprimer" onClick=${() => ctx.remove('item', item)}><${Icon} name="trash" /></button>
  </div>`;
  const grip = ctx.edit && html`<span class="grip" title="Glisser pour déplacer"><${Icon} name="grip" /></span>`;

  if (item.locked) {
    return html`<div class="item item-locked" data-id=${item.id}>
      <span class="item-icon"><${Icon} name="lock" /></span>
      <span class="item-title">${item.title}</span>
      <span class="badge badge-yellow"><${Icon} name="clock" size=${13} /> Disponible le ${formatDateTime(item.available_from)}</span>
    </div>`;
  }

  if (item.type === 'etiquette') {
    return html`<div class=${`item item-label ${dimmed ? 'dimmed' : ''}`} data-id=${item.id}>
      ${grip}<div class="item-main"><${Markdown} text=${item.data.text} />${ctx.trainer && html`<div class="badges"><${StateBadges} row=${item} /></div>`}</div>${tools}
    </div>`;
  }

  return html`<div class=${`item ${dimmed ? 'dimmed' : ''}`} data-id=${item.id}>
    ${grip}
    <span class=${`item-icon type-${item.type}`}><${Icon} name=${info.icon} /></span>
    <div class="item-main">
      ${item.type === 'dossier'
        ? html`<details><summary class="item-title">${item.title} <small class="muted">· ${item.files.length} fichier${item.files.length > 1 ? 's' : ''}</small></summary>
            <ul class="file-list">${item.files.map((file) => html`<li><${Icon} name="file" />
              <a href=${fileUrl(file.id)} target="_blank">${file.name}</a> <small class="muted">${fileSize(file.size)}</small></li>`)}</ul></details>`
        : html`<a class="item-title" ...${itemLink(item)}>${item.title}</a>`}
      <div class="badges">
        <span class="item-kind">${info.label}${item.type === 'fichier' && item.files[0] ? ` · ${fileSize(item.files[0].size)}` : ''}</span>
        ${item.type === 'depot' && html`<${DepotStatus} item=${item} trainer=${ctx.trainer} />`}
        ${ctx.trainer && html`<${StateBadges} row=${item} />`}
      </div>
    </div>
    ${tools}
    ${!ctx.trainer && html`<label class="done-check" title=${item.done ? 'Terminé' : 'Marquer comme terminé'}>
      <input type="checkbox" checked=${item.done} onChange=${(e) => ctx.complete(item, e.target.checked)} aria-label=${`Terminé : ${item.title}`} />
      <span><${Icon} name="check" size=${14} /></span>
    </label>`}
  </div>`;
}

// ---------- Modules, séquences, séances ----------

function NodeView({ node, ctx }) {
  const collapsed = ctx.collapsed.has(node.id);
  const childType = CHILD_OF[node.type];
  const minutes = sumMinutes(node);
  const dimmed = ctx.trainer && (!node.visible || node.scheduled);

  if (node.locked) {
    return html`<section class=${`node node-${node.type} node-locked`} data-id=${node.id}>
      <header class="node-head">
        <span class="node-lock"><${Icon} name="lock" /></span>
        <div class="node-title"><span class="node-kind">${NODE_LABELS[node.type]}</span><h3>${node.title}</h3></div>
        <span class="badge badge-yellow"><${Icon} name="clock" size=${13} /> Disponible le ${formatDateTime(node.available_from)}</span>
      </header>
    </section>`;
  }

  return html`<section class=${`node node-${node.type} ${dimmed ? 'dimmed' : ''}`} data-id=${node.id}>
    <header class="node-head">
      ${ctx.edit && html`<span class="grip" title="Glisser pour déplacer"><${Icon} name="grip" /></span>`}
      <button class=${`chevron ${collapsed ? '' : 'open'}`} aria-expanded=${!collapsed} aria-label=${collapsed ? 'Déplier' : 'Replier'} onClick=${() => ctx.toggleCollapse(node.id)}><${Icon} name="chevron" /></button>
      <div class="node-title" onClick=${() => ctx.toggleCollapse(node.id)}>
        <span class="node-kind">${NODE_LABELS[node.type]}</span>
        <h3>${node.title}</h3>
      </div>
      <div class="badges">
        ${ctx.trainer && html`<${StateBadges} row=${node} />`}
        ${minutes > 0 && html`<span class="badge badge-time"><${Icon} name="clock" size=${13} /> ${duration(minutes)}</span>`}
      </div>
      ${ctx.edit && html`<div class="tools">
        <button class="icon-btn" title=${node.visible ? 'Masquer aux stagiaires' : 'Rendre visible'} aria-label=${node.visible ? 'Masquer aux stagiaires' : 'Rendre visible'}
          aria-pressed=${!node.visible} onClick=${() => ctx.toggle('node', node)}><${Icon} name=${node.visible ? 'eye' : 'eye-off'} /></button>
        <button class=${`icon-btn ${node.available_from ? 'on' : ''}`} title="Programmer la mise à disposition" aria-label="Programmer la mise à disposition"
          onClick=${() => ctx.open({ kind: 'schedule', target: { kind: 'node', row: node } })}><${Icon} name="clock" /></button>
        <button class="icon-btn" title="Modifier" aria-label="Modifier" onClick=${() => ctx.open({ kind: 'node', node, type: node.type })}><${Icon} name="pencil" /></button>
        <button class="icon-btn" title="Supprimer" aria-label="Supprimer" onClick=${() => ctx.remove('node', node)}><${Icon} name="trash" /></button>
      </div>`}
    </header>
    ${!collapsed && html`<div class="node-body">
      ${node.description && html`<${Markdown} text=${node.description} />`}
      <${SortableList} group="items" parentId=${node.id} enabled=${ctx.edit} class="items" onMove=${(parent, ids) => ctx.move('item', parent, ids)}>
        ${node.items.map((item) => html`<${ItemRow} key=${item.id} item=${item} ctx=${ctx} />`)}
      <//>
      ${childType && html`<${SortableList} group=${childType} parentId=${node.id} enabled=${ctx.edit} class="nodes" onMove=${(parent, ids) => ctx.move('node', parent, ids)}>
        ${node.children.map((child) => html`<${NodeView} key=${child.id} node=${child} ctx=${ctx} />`)}
      <//>`}
      ${ctx.edit && html`<div class="add-row">
        <button class="btn small" onClick=${() => ctx.open({ kind: 'item', nodeId: node.id })}><${Icon} name="plus" size=${15} /> Ressource ou activité</button>
        ${childType && html`<button class="btn small" onClick=${() => ctx.open({ kind: 'node', type: childType, parentId: node.id })}>
          <${Icon} name="plus" size=${15} /> ${NODE_LABELS[childType]}</button>`}
      </div>`}
    </div>`}
  </section>`;
}

// ---------- Page du parcours ----------

export function CoursePage({ courseId }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [edit, setEdit] = useState(() => sessionStorage.getItem('afparea-edit') === '1');
  const [dialog, setDialog] = useState(null);
  const [collapsed, setCollapsed] = useState(new Set());

  const reload = () => api(`/courses/${courseId}`).then(setData).catch((err) => setError(err.message));
  useEffect(() => { reload(); }, [courseId]);

  if (error) return html`<div class="page"><${Empty} icon="lock" title="Parcours inaccessible">${error} <a href="#/">Retour à mes parcours</a><//></div>`;
  if (!data) return html`<div class="page"><p class="muted">Chargement…</p></div>`;

  const { course, tree } = data;
  const trainer = course.role === 'formateur';
  const guard = (promise) => promise.then(reload).catch((err) => { toastError(err); reload(); });

  const ctx = {
    trainer, edit: trainer && edit, collapsed,
    open: setDialog,
    toggleCollapse: (id) => setCollapsed((set) => { const next = new Set(set); if (!next.delete(id)) next.add(id); return next; }),
    toggle: (kind, row) => guard(api(`/${kind}s/${row.id}`, { method: 'PATCH', body: { visible: !row.visible } })),
    move: (kind, parentId, ids) => guard(api(`/courses/${course.id}/reorder`, { method: 'POST', body: { kind, parent_id: parentId, ids } })),
    remove: (kind, row) => {
      const what = kind === 'node' ? `${NODE_LABELS[row.type].toLowerCase()} « ${row.title} » et tout son contenu` : `« ${row.title} »`;
      if (confirm(`Supprimer ${what} ?`)) guard(api(`/${kind}s/${row.id}`, { method: 'DELETE' }));
    },
    complete: (item, done) => guard(api(`/items/${item.id}/completion`, { method: 'PUT', body: { done } })),
  };
  const setEditMode = (value) => { setEdit(value); sessionStorage.setItem('afparea-edit', value ? '1' : '0'); };
  const allIds = (nodes) => nodes.flatMap((node) => [node.id, ...allIds(node.children || [])]);
  const close = () => setDialog(null);
  const saved = () => { close(); reload(); };

  return html`<div class="page course-page">
    <${CourseBanner} course=${course} large>
      <div class="banner-content">
        <a class="back" href="#/"><${Icon} name="chevron" size=${14} /> Mes parcours</a>
        ${course.titre && html`<span class="badge badge-titre">${course.titre.name}${course.titre.level ? ` · ${course.titre.level}` : ''}</span>`}
        <h1>${course.title}</h1>
        <p class="banner-meta">
          ${course.duration_minutes > 0 && html`<span><${Icon} name="clock" size=${15} /> ${duration(course.duration_minutes)}</span>`}
          ${course.start_date && html`<span><${Icon} name="calendar" size=${15} /> ${formatDate(course.start_date)}${course.end_date ? ` → ${formatDate(course.end_date)}` : ''}</span>`}
        </p>
      </div>
    <//>

    <div class="course-bar">
      ${course.progress && html`<div class="progress-line wide"><strong>Ma progression</strong><${Progress} percent=${course.progress.percent} />
        <span>${course.progress.done}/${course.progress.total} · ${course.progress.percent} %</span></div>`}
      ${trainer && html`
        ${!course.visible && html`<span class="badge badge-muted"><${Icon} name="eye-off" size=${13} /> Parcours masqué aux stagiaires</span>`}
        <span class="spacer"></span>
        <label class="switch"><input type="checkbox" checked=${edit} onChange=${(e) => setEditMode(e.target.checked)} /><span></span> Mode édition</label>
        <a class="btn" href=${`#/parcours/${course.id}/participants`}><${Icon} name="users" /> Participants (${course.student_count})</a>
        <button class="btn" onClick=${() => setDialog({ kind: 'settings' })}><${Icon} name="settings" /> Paramètres</button>`}
      ${tree.length > 0 && html`<button class="link" onClick=${() => setCollapsed(collapsed.size ? new Set() : new Set(allIds(tree)))}>${collapsed.size ? 'Tout déplier' : 'Tout replier'}</button>`}
    </div>

    ${course.summary && html`<p class="course-summary">${course.summary}</p>`}
    ${ctx.edit && html`<p class="edit-help"><${Icon} name="grip" size=${15} /> Glissez la poignée pour réorganiser.
      <${Icon} name="eye" size=${15} /> masque ou affiche un élément. <${Icon} name="clock" size=${15} /> programme sa mise à disposition.</p>`}

    ${!tree.length && !ctx.edit && html`<${Empty} icon="page" title="Ce parcours est encore vide">
      ${trainer ? 'Activez le mode édition pour ajouter un premier module.' : 'Le contenu sera bientôt mis à disposition par votre formateur·rice.'}
    <//>`}
    <${SortableList} group="module" parentId=${null} enabled=${ctx.edit} class="nodes root" onMove=${(parent, ids) => ctx.move('node', parent, ids)}>
      ${tree.map((node) => html`<${NodeView} key=${node.id} node=${node} ctx=${ctx} />`)}
    <//>
    ${ctx.edit && html`<div class="add-row"><button class="btn primary" onClick=${() => setDialog({ kind: 'node', type: 'module', parentId: null })}>
      <${Icon} name="plus" /> Ajouter un module</button></div>`}

    ${dialog?.kind === 'settings' && html`<${SettingsDialog} course=${course} onClose=${close} onSaved=${(shouldClose) => { if (shouldClose) close(); reload(); }} />`}
    ${dialog?.kind === 'node' && html`<${NodeDialog} courseId=${course.id} ...${dialog} onClose=${close} onSaved=${saved} />`}
    ${dialog?.kind === 'item' && html`<${ItemDialog} courseId=${course.id} ...${dialog} onClose=${close} onSaved=${saved} />`}
    ${dialog?.kind === 'schedule' && html`<${ScheduleDialog} target=${dialog.target} onClose=${close} onSaved=${saved} />`}
  </div>`;
}
