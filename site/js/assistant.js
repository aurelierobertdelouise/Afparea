import { html, useState, useEffect, useRef } from '../lib/preact.js';
import { api, form } from './api.js';
import { Icon, Markdown, ActionButton, Modal, Form, Field, toast, toastError, duration, fileSize } from './ui.js';

const hours = (value) => duration(Math.round(value * 60));
const sum = (list, pick) => list.reduce((total, entry) => total + pick(entry), 0);
const sequenceHours = (sequence) => sum(sequence.seances, (seance) => seance.duree_heures);
const moduleHours = (module) => sum(module.sequences, sequenceHours);

const SUGGESTIONS = [
  'Propose un découpage complet du parcours à partir du REAC et du REV joints, pour 800 h en centre et 280 h en entreprise.',
  'Détaille le module du premier CCP en séquences et séances d’une demi-journée, avec les ECF prévues par le REV.',
  'Quelles évaluations en cours de formation dois-je planifier d’après le REV, et à quel moment du parcours ?',
];

function GenerateDialog({ proposal, onClose }) {
  const [titres, setTitres] = useState([]);
  const [titreId, setTitreId] = useState('');
  useEffect(() => { api('/titres').then((data) => setTitres(data.titres)).catch(toastError); }, []);
  const submit = async () => {
    const { course_id: courseId } = await api(`/ai/proposals/${proposal.id}/generate`, { method: 'POST', body: { titre_id: titreId || null } });
    toast('Parcours créé. Il reste masqué aux stagiaires tant que vous ne l’ouvrez pas.', 'success');
    sessionStorage.setItem('afparea-edit', '1');
    location.hash = `#/parcours/${courseId}`;
  };
  return html`<${Modal} title="Générer le parcours" onClose=${onClose}>
    <p>Le parcours « <strong>${proposal.data.titre}</strong> » sera créé avec ses ${proposal.data.modules.length} modules et ${hours(proposal.data.duree_totale_heures)} de formation.
      Vous pourrez ensuite tout ajuster par glisser-déposer. Il reste masqué aux stagiaires jusqu’à ce que vous l’ouvriez.</p>
    <${Form} submitLabel="Créer le parcours" onCancel=${onClose} onSubmit=${submit}>
      <${Field} label="Titre professionnel préparé">
        <select value=${titreId} onChange=${(e) => setTitreId(e.target.value)}>
          <option value="">— Aucun —</option>
          ${titres.map((titre) => html`<option value=${titre.id}>${titre.name}</option>`)}
        </select>
      <//>
    <//>
  <//>`;
}

function ProposalCard({ proposal }) {
  const [generating, setGenerating] = useState(false);
  const { data } = proposal;
  const seanceCount = sum(data.modules, (module) => sum(module.sequences, (sequence) => sequence.seances.length));
  return html`<article class="proposal">
    <header>
      <span class="badge badge-green"><${Icon} name="spark" size=${13} /> Proposition de parcours</span>
      <h3>${data.titre}</h3>
      ${data.resume && html`<p class="muted small">${data.resume}</p>`}
      <p class="proposal-stats"><strong>${hours(data.duree_totale_heures)}</strong> · ${data.modules.length} modules · ${seanceCount} séances</p>
    </header>
    <div class="proposal-tree">
      ${data.modules.map((module, index) => html`<details open=${index === 0}>
        <summary><span>${module.titre}</span><span class="badge badge-time">${hours(moduleHours(module))}</span></summary>
        ${module.description && html`<p class="muted small">${module.description}</p>`}
        ${module.sequences.map((sequence) => html`<details class="proposal-sequence">
          <summary><span>${sequence.titre}</span><span class="badge badge-time">${hours(sequenceHours(sequence))}</span></summary>
          <ul>${sequence.seances.map((seance) => html`<li>
            <div class="proposal-seance"><span>${seance.titre}</span><span class="muted small">${hours(seance.duree_heures)}</span></div>
            ${seance.objectifs.length > 0 && html`<ul class="objectifs">${seance.objectifs.map((objectif) => html`<li>${objectif}</li>`)}</ul>`}
            ${seance.activites.length > 0 && html`<div class="badges">${seance.activites.map((activite) => html`<span class="badge badge-muted">
              <${Icon} name=${activite.type === 'depot' ? 'inbox' : activite.type === 'page' ? 'page' : 'tag'} size=${13} /> ${activite.titre}</span>`)}</div>`}
          </li>`)}</ul>
        </details>`)}
      </details>`)}
    </div>
    <footer>
      ${proposal.course_id
        ? html`<a class="btn" href=${`#/parcours/${proposal.course_id}`}><${Icon} name="check" /> Parcours généré — l’ouvrir</a>`
        : html`<button class="btn primary" onClick=${() => setGenerating(true)}><${Icon} name="spark" /> Générer ce parcours sur AFPAREA</button>
            <span class="muted small">ou demandez un ajustement dans la conversation.</span>`}
    </footer>
    ${generating && html`<${GenerateDialog} proposal=${proposal} onClose=${() => setGenerating(false)} />`}
  </article>`;
}

function Message({ message }) {
  return html`<div class=${`msg msg-${message.role}`}>
    ${message.role === 'assistant' && html`<span class="avatar bot" aria-hidden="true">FPA</span>`}
    <div class="msg-body">
      ${message.documents?.length > 0 && html`<div class="badges">${message.documents.map((doc) => html`<span class="badge badge-doc"><${Icon} name="clip" size=${13} /> ${doc.kind} · ${doc.name}</span>`)}</div>`}
      ${message.text && (message.role === 'assistant' ? html`<${Markdown} text=${message.text} />` : html`<p class="pre">${message.text}</p>`)}
      ${message.status && html`<p class="msg-status"><span class="dots"><i></i><i></i><i></i></span> ${message.status}</p>`}
      ${(message.proposals || []).map((proposal) => html`<${ProposalCard} key=${proposal.id} proposal=${proposal} />`)}
    </div>
  </div>`;
}

export function AssistantPage() {
  const [configured, setConfigured] = useState(true);
  const [conversations, setConversations] = useState([]);
  const [currentId, setCurrentId] = useState(null);
  const [messages, setMessages] = useState([]);
  const [pending, setPending] = useState([]);
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [uploading, setUploading] = useState(false);
  const scroller = useRef();
  const fileInput = useRef();
  const uploadKind = useRef('REAC');
  const idRef = useRef(null); // identifiant courant, lisible dans les fonctions asynchrones en cours

  const loadList = () => api('/ai/conversations').then((data) => setConversations(data.conversations)).catch(toastError);
  const open = async (id) => {
    idRef.current = id;
    setCurrentId(id);
    if (!id) { setMessages([]); setPending([]); return; }
    try {
      const data = await api(`/ai/conversations/${id}`);
      setMessages(data.messages);
      setPending(data.pending_documents);
      if (data.busy) toast('Une réponse est encore en cours de rédaction pour cette conversation. Rouvrez-la dans un instant.');
    } catch (error) { toastError(error); }
  };
  useEffect(() => {
    api('/ai/status').then((data) => setConfigured(data.configured)).catch(() => {});
    loadList();
  }, []);
  useEffect(() => { scroller.current?.scrollTo(0, scroller.current.scrollHeight); }, [messages]);

  const ensureConversation = async () => {
    if (idRef.current) return idRef.current;
    const { id } = await api('/ai/conversations', { method: 'POST', body: {} });
    idRef.current = id;
    setCurrentId(id);
    loadList();
    return id;
  };

  const pickFile = (kind) => { uploadKind.current = kind; fileInput.current.click(); };
  const upload = async (file) => {
    if (!file) return;
    setUploading(true);
    try {
      const id = await ensureConversation();
      const { document } = await api(`/ai/conversations/${id}/documents`, { method: 'POST', body: form({ kind: uploadKind.current }, [file], 'file') });
      setPending((list) => [...list, document]);
    } catch (error) { toastError(error); } finally {
      setUploading(false);
      fileInput.current.value = '';
    }
  };
  const removeDocument = async (doc) => {
    await api(`/ai/documents/${doc.id}`, { method: 'DELETE' });
    setPending((list) => list.filter((other) => other.id !== doc.id));
  };

  const send = async (content = text) => {
    const question = content.trim();
    if (!question || busy) return;
    setBusy(true);
    const sentDocuments = pending;
    const draft = { id: 'draft', role: 'assistant', text: '', status: 'FPA réfléchit…', proposals: [] };
    const patchDraft = (patch) => setMessages((list) => list.map((message) => (message.id === 'draft' ? { ...message, ...patch(message) } : message)));
    setMessages((list) => [...list, { id: 'sending', role: 'user', text: question, documents: sentDocuments }, draft]);
    setText('');
    setPending([]);
    let failed = null;
    let finished = false; // le serveur a annoncé la fin de la réponse (réussie ou non)
    try {
      const id = await ensureConversation();
      const response = await api(`/ai/conversations/${id}/messages`, { method: 'POST', body: { text: question }, raw: true });
      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';
      for (;;) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop();
        for (const line of lines.filter(Boolean)) {
          const event = JSON.parse(line);
          if (event.type === 'text') patchDraft((message) => ({ text: message.text + event.text, status: null }));
          else if (event.type === 'status') patchDraft(() => ({ status: event.text }));
          else if (event.type === 'proposal') patchDraft((message) => ({ proposals: [...message.proposals, event.proposal], status: null }));
          else if (event.type === 'error') { failed = event.message; finished = true; }
          else if (event.type === 'done') finished = true;
        }
      }
    } catch (error) {
      // Erreur annoncée par le serveur avant la réponse : rien n'est en cours. Sinon la connexion a été coupée.
      if (error.status) { failed = error.message; finished = true; }
    }

    // Recharge l'état enregistré. Si la connexion a été coupée (délai de l'hébergeur), le serveur continue
    // de rédiger : on attend la fin en interrogeant la conversation.
    const id = idRef.current;
    let data = null;
    for (let attempt = 0; id && attempt < 300; attempt++) {
      data = await api(`/ai/conversations/${id}`).catch(() => null);
      if (finished || !data || !data.busy || idRef.current !== id) break;
      patchDraft(() => ({ status: 'FPA rédige toujours sa réponse, merci de patienter…' }));
      await new Promise((resolve) => setTimeout(resolve, 4000));
    }
    setBusy(false);
    if (data && idRef.current === id) {
      if (!finished && data.last_error) failed = data.last_error;
      const kept = data.messages.some((message) => message.role === 'user' && message.text === question);
      setMessages(data.messages);
      setPending(data.pending_documents);
      // Si rien n'a été enregistré, le message redevient modifiable.
      if (!kept) {
        setText(question);
        failed ??= 'La connexion a été interrompue avant la réponse. Réessayez.';
      }
      loadList();
    }
    if (failed) toast(failed, 'error');
  };

  const remove = async (conversation) => {
    if (!confirm(`Supprimer la conversation « ${conversation.title} » ?`)) return;
    await api(`/ai/conversations/${conversation.id}`, { method: 'DELETE' });
    if (conversation.id === currentId) open(null);
    loadList();
  };

  return html`<div class="assistant">
    <aside class="assistant-side">
      <button class="btn primary block" onClick=${() => open(null)}><${Icon} name="plus" /> Nouvelle conversation</button>
      <nav aria-label="Conversations">
        ${conversations.map((conversation) => html`<div class=${`conv ${conversation.id === currentId ? 'active' : ''}`} key=${conversation.id}>
          <button class="conv-title" onClick=${() => open(conversation.id)}>${conversation.title}</button>
          <${ActionButton} class="icon-btn" aria-label=${`Supprimer la conversation ${conversation.title}`} title="Supprimer" onAction=${() => remove(conversation)}><${Icon} name="trash" size=${15} /><//>
        </div>`)}
      </nav>
    </aside>
    <section class="assistant-main">
      <header class="assistant-head">
        <span class="avatar bot big" aria-hidden="true">FPA</span>
        <div><h1>FPA, votre assistant pédagogique</h1>
          <p class="muted small">Réservé aux formateurs. Joignez le REAC et le REV du titre : FPA propose un découpage en modules, séquences et séances, puis génère le parcours.</p></div>
      </header>
      ${!configured && html`<div class="notice notice-warn"><strong>Assistant non configuré.</strong> La clé d’accès au service d’IA (ANTHROPIC_API_KEY) n’est pas définie sur le serveur. Contactez l’administration de la plateforme.</div>`}
      <div class="chat" ref=${scroller}>
        ${!messages.length && html`<div class="chat-empty">
          <h2>Par où commencer ?</h2>
          <ol>
            <li>Joignez le <strong>REAC</strong> et le <strong>REV</strong> du titre (PDF).</li>
            <li>Indiquez la durée en centre et en entreprise, et votre public.</li>
            <li>Ajustez la proposition en discutant, puis générez le parcours en un clic.</li>
          </ol>
          <div class="suggestions">${SUGGESTIONS.map((suggestion) => html`<button onClick=${() => setText(suggestion)}>${suggestion}</button>`)}</div>
        </div>`}
        ${messages.map((message) => html`<${Message} key=${message.id} message=${message} />`)}
      </div>
      <form class="composer" onSubmit=${(event) => { event.preventDefault(); send(); }}>
        ${pending.length > 0 && html`<div class="badges">${pending.map((doc) => html`<span class="badge badge-doc">
          <${Icon} name="clip" size=${13} /> ${doc.kind} · ${doc.name} <small>${fileSize(doc.size)}</small>
          <${ActionButton} class="chip-x" aria-label=${`Retirer ${doc.name}`} onAction=${() => removeDocument(doc)}><${Icon} name="x" size=${12} /><//>
        </span>`)}</div>`}
        <textarea rows="3" placeholder="Décrivez votre besoin : titre visé, durée, public, contraintes…" aria-label="Votre message" value=${text}
          onInput=${(e) => setText(e.target.value)}
          onKeyDown=${(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } }}></textarea>
        <div class="composer-bar">
          <input type="file" accept=".pdf,.txt,.md" hidden ref=${fileInput} onChange=${(e) => upload(e.target.files[0])} />
          <button type="button" class="btn small" disabled=${uploading || busy} onClick=${() => pickFile('REAC')}><${Icon} name="clip" size=${15} /> Joindre le REAC</button>
          <button type="button" class="btn small" disabled=${uploading || busy} onClick=${() => pickFile('REV')}><${Icon} name="clip" size=${15} /> Joindre le REV</button>
          <button type="button" class="btn small" disabled=${uploading || busy} onClick=${() => pickFile('AUTRE')}><${Icon} name="clip" size=${15} /> Autre document</button>
          ${uploading && html`<span class="muted small">Envoi du document…</span>`}
          <span class="spacer"></span>
          <button type="submit" class="btn primary" disabled=${busy || !text.trim() || !configured}><${Icon} name="send" /> ${busy ? 'Réponse en cours…' : 'Envoyer'}</button>
        </div>
      </form>
    </section>
  </div>`;
}
