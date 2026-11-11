import fs from 'node:fs';
import path from 'node:path';
import { Router } from 'express';
import Anthropic from '@anthropic-ai/sdk';
import { db, now, tx, UPLOAD_DIR, sweepOrphanFiles } from '../db.js';
import { HttpError, requireRole } from '../auth.js';
import { route, upload, originalName, str, int } from '../util.js';
import { insertCourse, insertNode, insertItem } from '../model.js';
import { SYSTEM_PROMPT, PROPOSAL_TOOL, normalizeProposal } from '../fpa.js';

export const router = Router();

// L'assistant FPA est strictement réservé aux formateurs (et administrateurs).
router.use('/ai', requireRole('admin', 'formateur'));

const MODEL = process.env.FPA_MODEL || 'claude-opus-5-5';
const EFFORT = process.env.FPA_EFFORT || 'high';
const MAX_DOC_BYTES = 12 * 1024 * 1024;
const MAX_TOTAL_DOC_BYTES = 24 * 1024 * 1024; // la requête API est limitée à 32 Mo, base64 compris

const configured = () => !!(process.env.ANTHROPIC_API_KEY || process.env.ANTHROPIC_AUTH_TOKEN);
let client;
const getClient = () => (client ??= new Anthropic());

function loadConversation(user, id) {
  const conversation = db.prepare('SELECT * FROM ai_conversations WHERE id = ? AND user_id = ?').get(int(id), user.id);
  if (!conversation) throw new HttpError(404, 'Conversation introuvable.');
  return conversation;
}

const documentView = (doc) => ({ id: doc.id, kind: doc.kind, name: doc.name, size: doc.size, sent: doc.message_id !== null });

/** Version affichable d'un message enregistré (texte, pièces jointes, propositions). */
function messageView(row) {
  const blocks = JSON.parse(row.content);
  const text = blocks.filter((block) => block.type === 'text').map((block) => block.text).join('\n\n');
  const documents = blocks.filter((block) => block.type === 'doc_ref')
    .map((block) => db.prepare('SELECT * FROM ai_documents WHERE id = ?').get(block.document_id)).filter(Boolean).map(documentView);
  const proposals = db.prepare('SELECT id, data, course_id FROM ai_proposals WHERE message_id = ?').all(row.id)
    .map((proposal) => ({ id: proposal.id, course_id: proposal.course_id, data: JSON.parse(proposal.data) }));
  return { id: row.id, role: row.role, text, documents, proposals };
}

router.get('/ai/status', route((_req, res) => {
  res.json({ configured: configured() });
}));

router.get('/ai/conversations', route((req, res) => {
  res.json({ conversations: db.prepare('SELECT id, title, created_at FROM ai_conversations WHERE user_id = ? ORDER BY id DESC').all(req.user.id) });
}));

router.post('/ai/conversations', route((req, res) => {
  const id = db.prepare('INSERT INTO ai_conversations (user_id, title, created_at) VALUES (?, ?, ?)')
    .run(req.user.id, str(req.body.title, { max: 120 }) || 'Nouvelle conversation', now()).lastInsertRowid;
  res.status(201).json({ id });
}));

router.get('/ai/conversations/:id', route((req, res) => {
  const conversation = loadConversation(req.user, req.params.id);
  const rows = db.prepare('SELECT * FROM ai_messages WHERE conversation_id = ? ORDER BY id').all(conversation.id);
  res.json({
    conversation: { id: conversation.id, title: conversation.title },
    // Les lignes ne contenant qu'un résultat d'outil sont internes au dialogue avec le modèle.
    messages: rows.map(messageView).filter((message) => message.text || message.proposals.length || message.documents.length),
    pending_documents: db.prepare('SELECT * FROM ai_documents WHERE conversation_id = ? AND message_id IS NULL ORDER BY id').all(conversation.id).map(documentView),
  });
}));

router.delete('/ai/conversations/:id', route((req, res) => {
  const conversation = loadConversation(req.user, req.params.id);
  db.prepare('DELETE FROM ai_conversations WHERE id = ?').run(conversation.id);
  sweepOrphanFiles();
  res.json({ ok: true });
}));

// ---------- Référentiels joints (REAC, REV…) ----------

router.post('/ai/conversations/:id/documents', upload.single('file'), route((req, res) => {
  try {
    const conversation = loadConversation(req.user, req.params.id);
    if (!req.file) throw new HttpError(400, 'Fichier manquant.');
    const name = originalName(req.file);
    const isPdf = /\.pdf$/i.test(name);
    if (!isPdf && !/\.(txt|md)$/i.test(name)) throw new HttpError(400, 'Formats acceptés : PDF, TXT ou MD.');
    if (isPdf && fs.readFileSync(req.file.path).subarray(0, 5).toString() !== '%PDF-') throw new HttpError(400, 'Ce fichier n’est pas un PDF valide.');
    if (req.file.size > MAX_DOC_BYTES) throw new HttpError(400, 'Document trop volumineux (12 Mo maximum).');
    const total = db.prepare('SELECT COALESCE(SUM(size), 0) FROM ai_documents WHERE conversation_id = ?').pluck().get(conversation.id);
    if (total + req.file.size > MAX_TOTAL_DOC_BYTES) throw new HttpError(400, 'Trop de documents dans cette conversation (24 Mo au total).');
    const kind = ['REAC', 'REV'].includes(req.body.kind) ? req.body.kind : 'AUTRE';
    const id = db.prepare('INSERT INTO ai_documents (conversation_id, kind, name, mime, size, stored_name) VALUES (?, ?, ?, ?, ?, ?)')
      .run(conversation.id, kind, name, isPdf ? 'application/pdf' : 'text/plain', req.file.size, req.file.filename).lastInsertRowid;
    res.status(201).json({ document: documentView({ id, kind, name, size: req.file.size, message_id: null }) });
  } catch (error) {
    fs.rmSync(req.file?.path ?? '', { force: true });
    throw error;
  }
}));

router.delete('/ai/documents/:id', route((req, res) => {
  const doc = db.prepare(`SELECT d.* FROM ai_documents d JOIN ai_conversations c ON c.id = d.conversation_id
    WHERE d.id = ? AND c.user_id = ?`).get(int(req.params.id), req.user.id);
  if (!doc) throw new HttpError(404, 'Document introuvable.');
  if (doc.message_id !== null) throw new HttpError(400, 'Ce document a déjà été transmis à l’assistant.');
  db.prepare('DELETE FROM ai_documents WHERE id = ?').run(doc.id);
  sweepOrphanFiles();
  res.json({ ok: true });
}));

// ---------- Dialogue ----------

const KIND_LABEL = { REAC: 'REAC (Référentiel Emploi Activités Compétences)', REV: 'REV (Référentiel d’Évaluation)', AUTRE: 'Document complémentaire' };

/** Remplace les références de documents par leur contenu, à l'identique d'un appel à l'autre. */
function expandBlock(block) {
  if (block.type !== 'doc_ref') return block;
  const doc = db.prepare('SELECT * FROM ai_documents WHERE id = ?').get(block.document_id);
  const bytes = fs.readFileSync(path.join(UPLOAD_DIR, doc.stored_name));
  return {
    type: 'document',
    title: `${KIND_LABEL[doc.kind]} — ${doc.name}`,
    source: doc.mime === 'application/pdf'
      ? { type: 'base64', media_type: 'application/pdf', data: bytes.toString('base64') }
      : { type: 'text', media_type: 'text/plain', data: bytes.toString('utf8') },
  };
}

/** Historique au format de l'API ; les messages utilisateur consécutifs (résultat d'outil puis texte) sont fusionnés. */
function buildMessages(rows) {
  const messages = [];
  for (const row of rows) {
    const content = row.content.map(expandBlock);
    const last = messages.at(-1);
    if (last && last.role === row.role && row.role === 'user') last.content.push(...content);
    else messages.push({ role: row.role, content });
  }
  return messages;
}

function friendlyError(error) {
  if (error instanceof Anthropic.AuthenticationError) return 'Clé API Anthropic invalide. Vérifiez la configuration du serveur.';
  if (error instanceof Anthropic.RateLimitError) return 'L’assistant est très sollicité. Réessayez dans un instant.';
  if (error instanceof Anthropic.BadRequestError) return `La demande a été refusée par le service d’IA : ${error.message}`;
  if (error instanceof Anthropic.APIConnectionError) return 'Impossible de joindre le service d’IA. Vérifiez la connexion réseau du serveur.';
  if (error instanceof Anthropic.APIError) return 'Le service d’IA est momentanément indisponible. Réessayez dans un instant.';
  return 'Une erreur est survenue pendant la réponse de l’assistant.';
}

/** Envoi d'un message ; la réponse est diffusée en continu (une ligne JSON par événement). */
router.post('/ai/conversations/:id/messages', route(async (req, res) => {
  const conversation = loadConversation(req.user, req.params.id);
  if (!configured()) throw new HttpError(503, 'L’assistant FPA n’est pas configuré : la variable ANTHROPIC_API_KEY est absente sur le serveur.');
  const text = str(req.body.text, { max: 20000, required: true, label: 'Message' });

  const pending = db.prepare('SELECT * FROM ai_documents WHERE conversation_id = ? AND message_id IS NULL ORDER BY id').all(conversation.id);
  const userContent = [...pending.map((doc) => ({ type: 'doc_ref', document_id: doc.id })), { type: 'text', text }];
  const history = db.prepare('SELECT role, content FROM ai_messages WHERE conversation_id = ? ORDER BY id').all(conversation.id)
    .map((row) => ({ role: row.role, content: JSON.parse(row.content) }));
  const messages = buildMessages([...history, { role: 'user', content: userContent }]);
  // Point de cache sur le dernier bloc : les référentiels et l'historique sont relus à tarif réduit au tour suivant.
  const lastBlock = messages.at(-1).content.at(-1);
  messages.at(-1).content[messages.at(-1).content.length - 1] = { ...lastBlock, cache_control: { type: 'ephemeral' } };

  res.writeHead(200, { 'Content-Type': 'application/x-ndjson; charset=utf-8', 'Cache-Control': 'no-cache', 'X-Accel-Buffering': 'no' });
  const send = (event) => res.write(`${JSON.stringify(event)}\n`);

  const params = {
    model: MODEL,
    max_tokens: 64000,
    system: SYSTEM_PROMPT,
    output_config: { effort: EFFORT },
    tools: [PROPOSAL_TOOL],
    messages,
  };
  // Si le modèle décline une demande, l'API la rejoue sur un modèle de repli (désactivable : FPA_FALLBACKS=off).
  if (process.env.FPA_FALLBACKS !== 'off') {
    params.betas = ['server-side-fallback-2026-07-01'];
    params.fallbacks = 'default';
  }

  const stream = getClient().beta.messages.stream(params);
  res.on('close', () => { if (!res.writableEnded) stream.abort(); });

  try {
    let toolJson = '';
    for await (const event of stream) {
      if (event.type === 'content_block_start' && event.content_block.type === 'tool_use') {
        toolJson = '';
        send({ type: 'status', text: 'Construction du découpage pédagogique…' });
      } else if (event.type === 'content_block_delta' && event.delta.type === 'text_delta') {
        send({ type: 'text', text: event.delta.text });
      } else if (event.type === 'content_block_delta' && event.delta.type === 'input_json_delta') {
        const before = toolJson.split('"duree_heures"').length;
        toolJson += event.delta.partial_json;
        const count = toolJson.split('"duree_heures"').length - 1;
        if (count >= before && count > 0) send({ type: 'status', text: `Construction du découpage pédagogique… ${count} séance${count > 1 ? 's' : ''}` });
      }
    }
    const message = await stream.finalMessage();

    if (message.stop_reason === 'refusal') {
      send({ type: 'error', message: 'L’assistant n’a pas pu traiter cette demande. Reformulez-la ou vérifiez les documents joints.' });
      return res.end();
    }
    const toolUses = message.content.filter((block) => block.type === 'tool_use');
    if (message.stop_reason === 'max_tokens' && toolUses.length) {
      send({ type: 'error', message: 'Le découpage demandé est trop volumineux pour être généré en une fois. Demandez-le par CCP ou par bloc de modules.' });
      return res.end();
    }

    // Validation des propositions avant tout enregistrement : une entrée invalide est renvoyée au modèle comme erreur.
    const results = toolUses.map((block) => {
      try {
        return { block, proposal: normalizeProposal(block.input) };
      } catch (error) {
        return { block, error: error.message };
      }
    });

    const saved = tx(() => {
      const insert = db.prepare('INSERT INTO ai_messages (conversation_id, role, content, created_at) VALUES (?, ?, ?, ?)');
      const userId = insert.run(conversation.id, 'user', JSON.stringify(userContent), now()).lastInsertRowid;
      for (const doc of pending) db.prepare('UPDATE ai_documents SET message_id = ? WHERE id = ?').run(userId, doc.id);
      // Le contenu de l'assistant est conservé tel quel (blocs de réflexion compris) pour être renvoyé inchangé.
      const assistantId = insert.run(conversation.id, 'assistant', JSON.stringify(message.content), now()).lastInsertRowid;
      const proposals = [];
      if (results.length) {
        const toolResults = results.map(({ block, proposal, error }) => {
          if (error) return { type: 'tool_result', tool_use_id: block.id, is_error: true, content: `Proposition invalide : ${error}` };
          const id = db.prepare('INSERT INTO ai_proposals (conversation_id, message_id, data, created_at) VALUES (?, ?, ?, ?)')
            .run(conversation.id, assistantId, JSON.stringify(proposal), now()).lastInsertRowid;
          proposals.push({ id, course_id: null, data: proposal });
          return { type: 'tool_result', tool_use_id: block.id, content: 'Proposition affichée au formateur. Il peut la valider pour créer le parcours sur la plateforme, ou demander des ajustements.' };
        });
        insert.run(conversation.id, 'user', JSON.stringify(toolResults), now());
      }
      if (conversation.title === 'Nouvelle conversation') {
        db.prepare('UPDATE ai_conversations SET title = ? WHERE id = ?').run(text.slice(0, 80), conversation.id);
      }
      return { proposals };
    });

    for (const proposal of saved.proposals) send({ type: 'proposal', proposal });
    const invalid = results.find((result) => result.error);
    if (invalid) send({ type: 'error', message: `La proposition générée était incomplète (${invalid.error}). Demandez à l’assistant de la reprendre.` });
    if (message.stop_reason === 'max_tokens') send({ type: 'error', message: 'La réponse a été interrompue car elle était trop longue.' });
    send({ type: 'done' });
  } catch (error) {
    if (!(error instanceof Anthropic.APIUserAbortError)) {
      console.error('[FPA]', error);
      send({ type: 'error', message: friendlyError(error) });
    }
  }
  res.end();
}));

// ---------- Génération du parcours à partir d'une proposition validée ----------

router.post('/ai/proposals/:id/generate', route((req, res) => {
  const row = db.prepare(`SELECT p.* FROM ai_proposals p JOIN ai_conversations c ON c.id = p.conversation_id
    WHERE p.id = ? AND c.user_id = ?`).get(int(req.params.id), req.user.id);
  if (!row) throw new HttpError(404, 'Proposition introuvable.');
  const proposal = JSON.parse(row.data);
  const titreId = req.body.titre_id ? int(req.body.titre_id) : null;
  if (titreId && !db.prepare('SELECT 1 FROM titres WHERE id = ?').get(titreId)) throw new HttpError(400, 'Titre professionnel introuvable.');

  const courseId = tx(() => {
    const id = insertCourse(req.user, { title: proposal.titre, summary: proposal.resume, titre_id: titreId });
    for (const module of proposal.modules) {
      const moduleId = insertNode(id, null, 'module', { title: module.titre, description: module.description });
      for (const sequence of module.sequences) {
        const sequenceId = insertNode(id, moduleId, 'sequence', { title: sequence.titre, description: sequence.description });
        for (const seance of sequence.seances) {
          const seanceId = insertNode(id, sequenceId, 'seance', {
            title: seance.titre,
            description: seance.objectifs.map((objectif) => `- ${objectif}`).join('\n'),
            duration_minutes: Math.round(seance.duree_heures * 60),
          });
          for (const activite of seance.activites) {
            if (activite.type === 'depot') insertItem(id, seanceId, 'depot', activite.titre, { instructions: activite.contenu, max_grade: 20 });
            else insertItem(id, seanceId, activite.type, activite.titre, { text: activite.contenu });
          }
        }
      }
    }
    db.prepare('UPDATE ai_proposals SET course_id = ? WHERE id = ?').run(id, row.id);
    return id;
  });
  res.status(201).json({ course_id: courseId });
}));
