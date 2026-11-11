import fs from 'node:fs';
import path from 'node:path';
import { Router } from 'express';
import { db, now, tx, UPLOAD_DIR, sweepOrphanFiles } from '../db.js';
import { HttpError, hashPassword, generatePassword, requireRole } from '../auth.js';
import { route, upload, originalName, str, int, isoDate, safeUrl, EMAIL_RE } from '../util.js';
import { readRoster } from '../import.js';
import {
  NODE_TYPES, ITEM_TYPES, CHILD_TYPE, accessCourse, courseRole, courseTree, courseSummary,
  itemOpenForStudents, trackableItemIds, progressFor, insertNode, insertItem, insertCourse,
} from '../model.js';

export const router = Router();

const COLORS = ['navy', 'green', 'yellow'];
const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

const discard = (files) => { for (const file of files || []) fs.rmSync(file.path, { force: true }); };

function saveFile(file, { user, courseId, itemId = null, submissionId = null, kind }) {
  return db.prepare(`INSERT INTO files (stored_name, original_name, mime, size, owner_id, course_id, item_id, submission_id, kind, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`).run(file.filename, originalName(file), file.mimetype || 'application/octet-stream',
    file.size, user.id, courseId, itemId, submissionId, kind, now()).lastInsertRowid;
}

// ---------- Parcours ----------

router.get('/courses', route((req, res) => {
  const rows = req.user.role === 'admin'
    ? db.prepare('SELECT * FROM courses ORDER BY created_at DESC').all()
    : db.prepare(`SELECT c.* FROM courses c JOIN enrollments e ON e.course_id = c.id
        WHERE e.user_id = ? AND (e.role = 'formateur' OR c.visible = 1) ORDER BY c.created_at DESC`).all(req.user.id);
  res.json({ courses: rows.map((course) => courseSummary(course, courseRole(req.user, course.id), req.user.id)) });
}));

function courseFields(body, current = {}) {
  const fields = { ...current };
  if ('title' in body) fields.title = str(body.title, { max: 200, required: true, label: 'Titre' });
  if ('summary' in body) fields.summary = str(body.summary, { max: 4000 });
  if ('color' in body) {
    if (!COLORS.includes(body.color)) throw new HttpError(400, 'Couleur invalide.');
    fields.color = body.color;
  }
  if ('titre_id' in body) {
    fields.titre_id = body.titre_id ? int(body.titre_id) : null;
    if (fields.titre_id && !db.prepare('SELECT 1 FROM titres WHERE id = ?').get(fields.titre_id)) throw new HttpError(400, 'Titre professionnel introuvable.');
  }
  if ('start_date' in body) fields.start_date = isoDate(body.start_date, 'Date de début');
  if ('end_date' in body) fields.end_date = isoDate(body.end_date, 'Date de fin');
  if ('visible' in body) fields.visible = body.visible ? 1 : 0;
  return fields;
}

router.post('/courses', requireRole('admin', 'formateur'), route((req, res) => {
  const fields = courseFields(req.body);
  if (!fields.title) throw new HttpError(400, 'Titre obligatoire.');
  res.status(201).json({ id: insertCourse(req.user, fields) });
}));

router.get('/courses/:id', route((req, res) => {
  const { course, role } = accessCourse(req.user, int(req.params.id));
  res.json({ course: courseSummary(course, role, req.user.id), tree: courseTree(course, role, req.user.id) });
}));

router.patch('/courses/:id', route((req, res) => {
  const { course } = accessCourse(req.user, int(req.params.id), { trainer: true });
  const f = courseFields(req.body, course);
  db.prepare('UPDATE courses SET title = ?, summary = ?, color = ?, titre_id = ?, start_date = ?, end_date = ?, visible = ? WHERE id = ?')
    .run(f.title, f.summary, f.color, f.titre_id, f.start_date, f.end_date, f.visible, course.id);
  res.json({ ok: true });
}));

router.delete('/courses/:id', route((req, res) => {
  const { course } = accessCourse(req.user, int(req.params.id), { trainer: true });
  db.prepare('DELETE FROM courses WHERE id = ?').run(course.id);
  sweepOrphanFiles();
  res.json({ ok: true });
}));

router.post('/courses/:id/header', upload.single('image'), route((req, res) => {
  try {
    const { course } = accessCourse(req.user, int(req.params.id), { trainer: true });
    if (!req.file) throw new HttpError(400, 'Image manquante.');
    if (!IMAGE_TYPES.includes(req.file.mimetype)) throw new HttpError(400, 'Format d’image non pris en charge (PNG, JPEG, WebP ou GIF).');
    const fileId = tx(() => {
      db.prepare("DELETE FROM files WHERE course_id = ? AND kind = 'header'").run(course.id);
      const id = saveFile(req.file, { user: req.user, courseId: course.id, kind: 'header' });
      db.prepare('UPDATE courses SET header_file_id = ? WHERE id = ?').run(id, course.id);
      return id;
    });
    sweepOrphanFiles();
    res.json({ header_file_id: fileId });
  } catch (error) {
    discard([req.file].filter(Boolean));
    throw error;
  }
}));

router.delete('/courses/:id/header', route((req, res) => {
  const { course } = accessCourse(req.user, int(req.params.id), { trainer: true });
  db.prepare('UPDATE courses SET header_file_id = NULL WHERE id = ?').run(course.id);
  db.prepare("DELETE FROM files WHERE course_id = ? AND kind = 'header'").run(course.id);
  sweepOrphanFiles();
  res.json({ ok: true });
}));

// ---------- Modules, séquences, séances ----------

function loadNode(user, id) {
  const node = db.prepare('SELECT * FROM nodes WHERE id = ?').get(int(id));
  if (!node) throw new HttpError(404, 'Élément introuvable.');
  accessCourse(user, node.course_id, { trainer: true });
  return node;
}

function nodeFields(body, current = {}) {
  const fields = { ...current };
  if ('title' in body) fields.title = str(body.title, { max: 200, required: true, label: 'Titre' });
  if ('description' in body) fields.description = str(body.description, { max: 10000 });
  if ('duration_minutes' in body) {
    const minutes = Number(body.duration_minutes);
    if (!Number.isFinite(minutes) || minutes < 0 || minutes > 100000) throw new HttpError(400, 'Durée invalide.');
    fields.duration_minutes = Math.round(minutes);
  }
  if ('visible' in body) fields.visible = body.visible ? 1 : 0;
  if ('available_from' in body) fields.available_from = isoDate(body.available_from, 'Date de mise à disposition');
  return fields;
}

router.post('/courses/:id/nodes', route((req, res) => {
  const { course } = accessCourse(req.user, int(req.params.id), { trainer: true });
  const parentId = req.body.parent_id ? int(req.body.parent_id) : null;
  let expected = CHILD_TYPE.root;
  if (parentId) {
    const parent = db.prepare('SELECT * FROM nodes WHERE id = ? AND course_id = ?').get(parentId, course.id);
    if (!parent) throw new HttpError(400, 'Parent introuvable.');
    expected = CHILD_TYPE[parent.type];
    if (!expected) throw new HttpError(400, 'Une séance ne peut pas contenir de sous-niveau.');
  }
  const fields = nodeFields(req.body);
  if (!fields.title) throw new HttpError(400, 'Titre obligatoire.');
  res.status(201).json({ id: insertNode(course.id, parentId, expected, { ...fields, visible: fields.visible !== 0 }) });
}));

router.patch('/nodes/:id', route((req, res) => {
  const node = loadNode(req.user, req.params.id);
  const f = nodeFields(req.body, node);
  db.prepare('UPDATE nodes SET title = ?, description = ?, duration_minutes = ?, visible = ?, available_from = ? WHERE id = ?')
    .run(f.title, f.description, f.duration_minutes, f.visible, f.available_from, node.id);
  res.json({ ok: true });
}));

router.delete('/nodes/:id', route((req, res) => {
  const node = loadNode(req.user, req.params.id);
  db.prepare('DELETE FROM nodes WHERE id = ?').run(node.id);
  sweepOrphanFiles();
  res.json({ ok: true });
}));

/**
 * Glisser-déposer : le client envoie le conteneur de destination et l'ordre complet de son contenu.
 * kind = 'node' (parent_id = nœud parent ou null) ou 'item' (parent_id = nœud conteneur).
 */
router.post('/courses/:id/reorder', route((req, res) => {
  const { course } = accessCourse(req.user, int(req.params.id), { trainer: true });
  const ids = Array.isArray(req.body.ids) ? req.body.ids.map((id) => int(id)) : null;
  if (!ids || new Set(ids).size !== ids.length) throw new HttpError(400, 'Ordre invalide.');
  const parentId = req.body.parent_id ? int(req.body.parent_id) : null;
  const parent = parentId ? db.prepare('SELECT * FROM nodes WHERE id = ? AND course_id = ?').get(parentId, course.id) : null;
  if (parentId && !parent) throw new HttpError(400, 'Destination introuvable.');

  tx(() => {
    if (req.body.kind === 'node') {
      const expected = parent ? CHILD_TYPE[parent.type] : CHILD_TYPE.root;
      const move = db.prepare('UPDATE nodes SET parent_id = ?, position = ? WHERE id = ? AND course_id = ? AND type = ?');
      ids.forEach((id, index) => {
        if (!expected || !move.run(parentId, index, id, course.id, expected).changes) {
          throw new HttpError(400, 'Ce déplacement n’est pas possible à ce niveau.');
        }
      });
    } else if (req.body.kind === 'item' && parent) {
      const move = db.prepare('UPDATE items SET node_id = ?, position = ? WHERE id = ? AND course_id = ?');
      ids.forEach((id, index) => {
        if (!move.run(parentId, index, id, course.id).changes) throw new HttpError(400, 'Élément introuvable.');
      });
    } else {
      throw new HttpError(400, 'Déplacement invalide.');
    }
  });
  res.json({ ok: true });
}));

// ---------- Ressources et activités ----------

function loadItem(user, id, { trainer = false } = {}) {
  const item = db.prepare('SELECT * FROM items WHERE id = ?').get(int(id));
  if (!item) throw new HttpError(404, 'Élément introuvable.');
  const { course, role } = accessCourse(user, item.course_id, { trainer });
  if (role === 'stagiaire' && !itemOpenForStudents(item)) throw new HttpError(404, 'Élément introuvable ou pas encore disponible.');
  return { item, course, role };
}

function itemData(type, body, current = {}) {
  const data = { ...current };
  if ('description' in body) data.description = str(body.description, { max: 4000 });
  if (type === 'etiquette' || type === 'page') {
    if ('text' in body) data.text = str(body.text, { max: 200000 });
  } else if (type === 'lien' || type === 'video') {
    if ('url' in body) data.url = safeUrl(body.url);
    if (!data.url) throw new HttpError(400, 'Adresse web obligatoire.');
  } else if (type === 'depot') {
    if ('instructions' in body) data.instructions = str(body.instructions, { max: 50000 });
    if ('due_at' in body) data.due_at = isoDate(body.due_at, 'Date limite');
    if ('max_grade' in body) {
      const max = Number(body.max_grade);
      if (!Number.isFinite(max) || max <= 0 || max > 1000) throw new HttpError(400, 'Barème invalide.');
      data.max_grade = max;
    }
    data.max_grade ??= 20;
  }
  return data;
}

function attachFiles(req, item) {
  const files = req.files || [];
  if (!files.length) return;
  if (!['fichier', 'dossier'].includes(item.type)) throw new HttpError(400, 'Ce type d’élément n’accepte pas de fichier.');
  if (item.type === 'fichier') {
    if (files.length > 1) throw new HttpError(400, 'Une ressource « Fichier » ne contient qu’un seul fichier. Utilisez « Dossier ».');
    db.prepare('DELETE FROM files WHERE item_id = ?').run(item.id);
  }
  for (const file of files) saveFile(file, { user: req.user, courseId: item.course_id, itemId: item.id, kind: 'resource' });
}

// Les champs arrivent en multipart (texte + fichiers éventuels).
router.post('/courses/:id/items', upload.array('files'), route((req, res) => {
  try {
    const { course } = accessCourse(req.user, int(req.params.id), { trainer: true });
    const type = req.body.type;
    if (!ITEM_TYPES.includes(type)) throw new HttpError(400, 'Type d’élément invalide.');
    const node = db.prepare('SELECT id FROM nodes WHERE id = ? AND course_id = ?').get(int(req.body.node_id), course.id);
    if (!node) throw new HttpError(400, 'Emplacement introuvable.');
    const title = str(req.body.title, { max: 200, required: type !== 'etiquette', label: 'Titre' }) || 'Étiquette';
    if (type === 'fichier' && !(req.files || []).length) throw new HttpError(400, 'Sélectionnez un fichier.');
    const id = tx(() => {
      const itemId = insertItem(course.id, node.id, type, title, itemData(type, req.body), {
        visible: req.body.visible !== '0',
        available_from: isoDate(req.body.available_from, 'Date de mise à disposition'),
      });
      attachFiles(req, { id: itemId, type, course_id: course.id });
      return itemId;
    });
    res.status(201).json({ id });
  } catch (error) {
    discard(req.files);
    throw error;
  }
}));

router.patch('/items/:id', upload.array('files'), route((req, res) => {
  try {
    const { item } = loadItem(req.user, req.params.id, { trainer: true });
    const body = req.body;
    const title = 'title' in body ? str(body.title, { max: 200, required: true, label: 'Titre' }) : item.title;
    const visible = 'visible' in body ? (body.visible === '0' || body.visible === false ? 0 : 1) : item.visible;
    const availableFrom = 'available_from' in body ? isoDate(body.available_from, 'Date de mise à disposition') : item.available_from;
    tx(() => {
      db.prepare('UPDATE items SET title = ?, data = ?, visible = ?, available_from = ? WHERE id = ?')
        .run(title, JSON.stringify(itemData(item.type, body, JSON.parse(item.data))), visible, availableFrom, item.id);
      attachFiles(req, item);
    });
    sweepOrphanFiles();
    res.json({ ok: true });
  } catch (error) {
    discard(req.files);
    throw error;
  }
}));

router.delete('/items/:id', route((req, res) => {
  const { item } = loadItem(req.user, req.params.id, { trainer: true });
  db.prepare('DELETE FROM items WHERE id = ?').run(item.id);
  sweepOrphanFiles();
  res.json({ ok: true });
}));

const submissionView = (row) => row && ({
  id: row.id, comment: row.comment, submitted_at: row.submitted_at, grade: row.grade,
  feedback: row.feedback, graded_at: row.graded_at,
  files: db.prepare('SELECT id, original_name AS name, size, mime FROM files WHERE submission_id = ? ORDER BY id').all(row.id),
});

/** Détail d'un élément ; pour un dépôt : ma remise (stagiaire) ou toutes les remises (formateur). */
router.get('/items/:id', route((req, res) => {
  const { item, course, role } = loadItem(req.user, req.params.id);
  const view = {
    id: item.id, course_id: item.course_id, type: item.type, title: item.title, data: JSON.parse(item.data),
    files: db.prepare("SELECT id, original_name AS name, size, mime FROM files WHERE item_id = ? AND kind = 'resource' ORDER BY id").all(item.id),
    done: !!db.prepare('SELECT 1 FROM completions WHERE item_id = ? AND user_id = ?').get(item.id, req.user.id),
  };
  if (item.type === 'depot') {
    if (role === 'formateur') {
      const students = db.prepare(`SELECT u.id, u.first_name, u.last_name, u.email FROM enrollments e JOIN users u ON u.id = e.user_id
        WHERE e.course_id = ? AND e.role = 'stagiaire' ORDER BY u.last_name, u.first_name`).all(course.id);
      const byUser = new Map(db.prepare('SELECT * FROM submissions WHERE item_id = ?').all(item.id).map((row) => [row.user_id, row]));
      view.submissions = students.map((student) => ({ student, submission: submissionView(byUser.get(student.id)) || null }));
    } else {
      view.submission = submissionView(db.prepare('SELECT * FROM submissions WHERE item_id = ? AND user_id = ?').get(item.id, req.user.id)) || null;
    }
  }
  res.json({ item: view, role, course: { id: course.id, title: course.title } });
}));

router.put('/items/:id/completion', route((req, res) => {
  const { item } = loadItem(req.user, req.params.id);
  if (req.body.done) {
    db.prepare('INSERT OR IGNORE INTO completions (item_id, user_id, completed_at) VALUES (?, ?, ?)').run(item.id, req.user.id, now());
  } else {
    db.prepare('DELETE FROM completions WHERE item_id = ? AND user_id = ?').run(item.id, req.user.id);
  }
  res.json({ ok: true });
}));

// ---------- Dépôts des stagiaires ----------

router.post('/items/:id/submission', upload.array('files'), route((req, res) => {
  try {
    const { item, role } = loadItem(req.user, req.params.id);
    if (item.type !== 'depot') throw new HttpError(400, 'Cet élément n’est pas un espace de dépôt.');
    if (role !== 'stagiaire') throw new HttpError(403, 'Seuls les stagiaires déposent une production.');
    const existing = db.prepare('SELECT * FROM submissions WHERE item_id = ? AND user_id = ?').get(item.id, req.user.id);
    if (existing && existing.grade !== null) throw new HttpError(400, 'Votre production a déjà été évaluée : elle ne peut plus être modifiée.');
    const files = req.files || [];
    const kept = existing ? db.prepare('SELECT COUNT(*) FROM files WHERE submission_id = ?').pluck().get(existing.id) : 0;
    if (!files.length && !kept) throw new HttpError(400, 'Ajoutez au moins un fichier.');
    const comment = str(req.body.comment, { max: 4000 });
    tx(() => {
      let id = existing?.id;
      if (existing) db.prepare('UPDATE submissions SET comment = ?, submitted_at = ? WHERE id = ?').run(comment, now(), id);
      else id = db.prepare('INSERT INTO submissions (item_id, user_id, comment, submitted_at) VALUES (?, ?, ?, ?)').run(item.id, req.user.id, comment, now()).lastInsertRowid;
      for (const file of files) saveFile(file, { user: req.user, courseId: item.course_id, submissionId: id, kind: 'submission' });
      db.prepare('INSERT OR IGNORE INTO completions (item_id, user_id, completed_at) VALUES (?, ?, ?)').run(item.id, req.user.id, now());
    });
    res.json({ ok: true });
  } catch (error) {
    discard(req.files);
    throw error;
  }
}));

router.patch('/submissions/:id', route((req, res) => {
  const submission = db.prepare('SELECT s.*, i.course_id, i.data FROM submissions s JOIN items i ON i.id = s.item_id WHERE s.id = ?').get(int(req.params.id));
  if (!submission) throw new HttpError(404, 'Remise introuvable.');
  accessCourse(req.user, submission.course_id, { trainer: true });
  let grade = null;
  if (req.body.grade !== null && req.body.grade !== '' && req.body.grade !== undefined) {
    grade = Number(req.body.grade);
    const max = JSON.parse(submission.data).max_grade || 20;
    if (!Number.isFinite(grade) || grade < 0 || grade > max) throw new HttpError(400, `La note doit être comprise entre 0 et ${max}.`);
  }
  db.prepare('UPDATE submissions SET grade = ?, feedback = ?, graded_by = ?, graded_at = ? WHERE id = ?')
    .run(grade, str(req.body.feedback, { max: 10000 }), req.user.id, now(), submission.id);
  res.json({ ok: true });
}));

// ---------- Téléchargement des fichiers ----------

const INLINE_TYPES = [...IMAGE_TYPES, 'application/pdf', 'video/mp4', 'audio/mpeg'];

router.get('/files/:id', route((req, res) => {
  const file = db.prepare('SELECT * FROM files WHERE id = ?').get(int(req.params.id));
  if (!file) throw new HttpError(404, 'Fichier introuvable.');
  const { role } = accessCourse(req.user, file.course_id);
  if (role === 'stagiaire') {
    if (file.kind === 'resource') {
      const item = db.prepare('SELECT * FROM items WHERE id = ?').get(file.item_id);
      if (!item || !itemOpenForStudents(item)) throw new HttpError(404, 'Fichier introuvable.');
    } else if (file.kind === 'submission') {
      const owner = db.prepare('SELECT user_id FROM submissions WHERE id = ?').pluck().get(file.submission_id);
      if (owner !== req.user.id) throw new HttpError(404, 'Fichier introuvable.');
    }
  }
  const inline = INLINE_TYPES.includes(file.mime) && req.query.download === undefined;
  res.setHeader('Content-Type', inline ? file.mime : 'application/octet-stream');
  res.setHeader('Content-Disposition', `${inline ? 'inline' : 'attachment'}; filename*=UTF-8''${encodeURIComponent(file.original_name)}`);
  res.setHeader('Cache-Control', 'private, max-age=300');
  res.sendFile(path.join(UPLOAD_DIR, file.stored_name));
}));

router.delete('/files/:id', route((req, res) => {
  const file = db.prepare("SELECT * FROM files WHERE id = ? AND kind = 'resource'").get(int(req.params.id));
  if (!file) throw new HttpError(404, 'Fichier introuvable.');
  accessCourse(req.user, file.course_id, { trainer: true });
  db.prepare('DELETE FROM files WHERE id = ?').run(file.id);
  sweepOrphanFiles();
  res.json({ ok: true });
}));

// ---------- Participants et inscriptions ----------

router.get('/courses/:id/participants', route((req, res) => {
  const { course } = accessCourse(req.user, int(req.params.id), { trainer: true });
  const trackable = trackableItemIds(course.id);
  const rows = db.prepare(`SELECT u.id, u.first_name, u.last_name, u.email, u.active, e.role FROM enrollments e
    JOIN users u ON u.id = e.user_id WHERE e.course_id = ? ORDER BY e.role, u.last_name, u.first_name`).all(course.id);
  res.json({
    participants: rows.map((row) => ({
      ...row, active: !!row.active,
      progress: row.role === 'stagiaire' ? progressFor(course.id, row.id, trackable) : null,
    })),
  });
}));

function enroll(courseId, userId, role) {
  return db.prepare('INSERT OR IGNORE INTO enrollments (course_id, user_id, role, created_at) VALUES (?, ?, ?, ?)')
    .run(courseId, userId, role, now()).changes > 0;
}

function createStudent(row) {
  const password = generatePassword();
  const id = db.prepare(`INSERT INTO users (email, password_hash, first_name, last_name, role, must_change_password, created_at)
    VALUES (?, ?, ?, ?, 'stagiaire', 1, ?)`).run(row.email, hashPassword(password), row.first_name, row.last_name, now()).lastInsertRowid;
  return { id, password };
}

/** Inscription individuelle par e-mail. Un compte stagiaire est créé si l'adresse est inconnue. */
router.post('/courses/:id/enroll', route((req, res) => {
  const { course } = accessCourse(req.user, int(req.params.id), { trainer: true });
  const email = str(req.body.email, { max: 200, required: true, label: 'E-mail' }).toLowerCase();
  if (!EMAIL_RE.test(email)) throw new HttpError(400, 'Adresse e-mail invalide.');
  const role = req.body.role === 'formateur' ? 'formateur' : 'stagiaire';
  let user = db.prepare('SELECT * FROM users WHERE email = ?').get(email);
  let password = null;
  if (!user) {
    if (role === 'formateur') throw new HttpError(404, 'Aucun compte formateur avec cette adresse. Demandez sa création à l’administration.');
    const created = createStudent({ email, first_name: str(req.body.first_name, { max: 100 }), last_name: str(req.body.last_name, { max: 100 }) });
    user = { id: created.id, role: 'stagiaire' };
    password = created.password;
  }
  if (role === 'formateur' && user.role === 'stagiaire') throw new HttpError(400, 'Ce compte est un compte stagiaire : il ne peut pas être formateur du parcours.');
  if (!enroll(course.id, user.id, role)) throw new HttpError(409, 'Cette personne est déjà inscrite au parcours.');
  res.status(201).json({ password });
}));

router.delete('/courses/:id/enroll/:userId', route((req, res) => {
  const { course } = accessCourse(req.user, int(req.params.id), { trainer: true });
  const userId = int(req.params.userId);
  const target = db.prepare('SELECT role FROM enrollments WHERE course_id = ? AND user_id = ?').get(course.id, userId);
  if (target?.role === 'formateur') {
    const trainers = db.prepare("SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND role = 'formateur'").pluck().get(course.id);
    if (trainers <= 1) throw new HttpError(400, 'Le parcours doit conserver au moins un formateur.');
  }
  db.prepare('DELETE FROM enrollments WHERE course_id = ? AND user_id = ?').run(course.id, userId);
  res.json({ ok: true });
}));

/**
 * Inscription par lot depuis un fichier Excel (.xlsx) ou CSV.
 * ?preview=1 : analyse sans rien écrire, pour vérification avant validation.
 */
router.post('/courses/:id/import', upload.single('file'), route(async (req, res) => {
  try {
    const { course } = accessCourse(req.user, int(req.params.id), { trainer: true });
    if (!req.file) throw new HttpError(400, 'Fichier manquant.');
    const { rows, errors } = await readRoster(req.file.path, originalName(req.file));
    const preview = req.query.preview !== undefined;
    const findUser = db.prepare('SELECT id, role, first_name, last_name FROM users WHERE email = ?');
    const isEnrolled = db.prepare('SELECT 1 FROM enrollments WHERE course_id = ? AND user_id = ?');

    const result = tx(() => rows.map((row) => {
      const user = findUser.get(row.email);
      if (user && isEnrolled.get(course.id, user.id)) return { ...row, status: 'deja_inscrit' };
      if (user && user.role !== 'stagiaire') return { ...row, status: 'compte_non_stagiaire' };
      if (user) {
        if (!preview) enroll(course.id, user.id, 'stagiaire');
        return { ...row, first_name: user.first_name || row.first_name, last_name: user.last_name || row.last_name, status: 'inscrit' };
      }
      if (preview) return { ...row, status: 'cree' };
      const created = createStudent(row);
      enroll(course.id, created.id, 'stagiaire');
      return { ...row, status: 'cree', password: created.password };
    }));
    res.json({ preview, rows: result, errors });
  } finally {
    discard([req.file].filter(Boolean));
  }
}));
