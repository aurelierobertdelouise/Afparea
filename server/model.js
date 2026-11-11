import { db, now } from './db.js';
import { HttpError } from './auth.js';

export const NODE_TYPES = ['module', 'sequence', 'seance'];
export const ITEM_TYPES = ['etiquette', 'page', 'fichier', 'dossier', 'lien', 'video', 'depot'];
/** Type de nœud attendu selon le type du parent (null = racine du parcours). */
export const CHILD_TYPE = { root: 'module', module: 'sequence', sequence: 'seance' };

/** Rôle de l'utilisateur dans le parcours : 'formateur', 'stagiaire' ou null. Les admins sont formateurs partout. */
export function courseRole(user, courseId) {
  if (user.role === 'admin') return 'formateur';
  const row = db.prepare('SELECT role FROM enrollments WHERE course_id = ? AND user_id = ?').get(courseId, user.id);
  return row ? row.role : null;
}

export function getCourse(id) {
  const course = db.prepare('SELECT * FROM courses WHERE id = ?').get(id);
  if (!course) throw new HttpError(404, 'Parcours introuvable.');
  return course;
}

/** Charge le parcours et vérifie l'accès. `trainer: true` exige les droits de formateur. */
export function accessCourse(user, courseId, { trainer = false } = {}) {
  const course = getCourse(courseId);
  const role = courseRole(user, course.id);
  if (!role || (role === 'stagiaire' && !course.visible)) throw new HttpError(404, 'Parcours introuvable.');
  if (trainer && role !== 'formateur') throw new HttpError(403, 'Action réservée aux formateurs du parcours.');
  return { course, role };
}

const isOpen = (row, at) => row.visible && (!row.available_from || row.available_from <= at);

/** Un élément est-il consultable par un stagiaire (lui-même et tous ses ancêtres visibles et disponibles) ? */
export function itemOpenForStudents(item) {
  const at = now();
  if (!isOpen(item, at)) return false;
  let node = db.prepare('SELECT * FROM nodes WHERE id = ?').get(item.node_id);
  while (node) {
    if (!isOpen(node, at)) return false;
    node = node.parent_id ? db.prepare('SELECT * FROM nodes WHERE id = ?').get(node.parent_id) : null;
  }
  return true;
}

/** Identifiants des éléments comptés dans la progression : non masqués (ni leurs ancêtres), hors étiquettes. */
export function trackableItemIds(courseId) {
  const nodes = db.prepare('SELECT id, parent_id, visible FROM nodes WHERE course_id = ?').all(courseId);
  const byId = new Map(nodes.map((node) => [node.id, node]));
  const shown = (node) => !!node && node.visible && (!node.parent_id || shown(byId.get(node.parent_id)));
  return db.prepare("SELECT id, node_id FROM items WHERE course_id = ? AND visible = 1 AND type != 'etiquette'")
    .all(courseId).filter((item) => shown(byId.get(item.node_id))).map((item) => item.id);
}

export function progressFor(courseId, userId, trackable = trackableItemIds(courseId)) {
  if (!trackable.length) return { done: 0, total: 0, percent: 0 };
  const completed = new Set(db.prepare(`SELECT c.item_id FROM completions c JOIN items i ON i.id = c.item_id
    WHERE i.course_id = ? AND c.user_id = ?`).pluck().all(courseId, userId));
  const done = trackable.filter((id) => completed.has(id)).length;
  return { done, total: trackable.length, percent: Math.round((done / trackable.length) * 100) };
}

const fileView = (file) => ({ id: file.id, name: file.original_name, size: file.size, mime: file.mime });

/**
 * Arborescence complète du parcours pour l'utilisateur.
 * Formateur : tout, avec les indicateurs de visibilité.
 * Stagiaire : éléments masqués absents ; éléments programmés présents mais verrouillés (titre et date seulement).
 */
export function courseTree(course, role, userId) {
  const at = now();
  const trainer = role === 'formateur';
  const nodes = db.prepare('SELECT * FROM nodes WHERE course_id = ? ORDER BY position, id').all(course.id);
  const items = db.prepare('SELECT * FROM items WHERE course_id = ? ORDER BY position, id').all(course.id);
  const files = db.prepare("SELECT * FROM files WHERE course_id = ? AND kind = 'resource' ORDER BY id").all(course.id);
  const done = new Set(db.prepare(`SELECT c.item_id FROM completions c JOIN items i ON i.id = c.item_id
    WHERE i.course_id = ? AND c.user_id = ?`).pluck().all(course.id, userId));
  const submissions = new Map(db.prepare(`SELECT s.item_id, s.submitted_at, s.grade FROM submissions s
    JOIN items i ON i.id = s.item_id WHERE i.course_id = ? AND s.user_id = ?`).all(course.id, userId)
    .map((row) => [row.item_id, row]));
  const submissionCounts = trainer ? new Map(db.prepare(`SELECT s.item_id, COUNT(*) AS n, SUM(s.grade IS NULL) AS pending
    FROM submissions s JOIN items i ON i.id = s.item_id WHERE i.course_id = ? GROUP BY s.item_id`).all(course.id)
    .map((row) => [row.item_id, row])) : null;

  const filesByItem = new Map();
  for (const file of files) {
    if (!filesByItem.has(file.item_id)) filesByItem.set(file.item_id, []);
    filesByItem.get(file.item_id).push(fileView(file));
  }
  const itemsByNode = new Map();
  for (const item of items) {
    if (!itemsByNode.has(item.node_id)) itemsByNode.set(item.node_id, []);
    itemsByNode.get(item.node_id).push(item);
  }
  const childrenByParent = new Map();
  for (const node of nodes) {
    const key = node.parent_id ?? 0;
    if (!childrenByParent.has(key)) childrenByParent.set(key, []);
    childrenByParent.get(key).push(node);
  }

  const flags = (row) => ({
    visible: !!row.visible,
    available_from: row.available_from,
    scheduled: !!row.available_from && row.available_from > at,
  });

  const viewItem = (item) => {
    const base = { id: item.id, node_id: item.node_id, type: item.type, title: item.title, ...flags(item) };
    if (!trainer && !item.visible) return null;
    if (!trainer && base.scheduled) return { ...base, locked: true };
    const view = { ...base, data: JSON.parse(item.data), files: filesByItem.get(item.id) || [], done: done.has(item.id) };
    if (item.type === 'depot') {
      view.submission = submissions.get(item.id) || null;
      if (trainer) view.submission_stats = submissionCounts.get(item.id) || { n: 0, pending: 0 };
    }
    return view;
  };

  const viewNode = (node) => {
    const base = {
      id: node.id, parent_id: node.parent_id, type: node.type, title: node.title,
      duration_minutes: node.duration_minutes, ...flags(node),
    };
    if (!trainer && !node.visible) return null;
    if (!trainer && base.scheduled) return { ...base, locked: true, children: [], items: [] };
    return {
      ...base,
      description: node.description,
      items: (itemsByNode.get(node.id) || []).map(viewItem).filter(Boolean),
      children: (childrenByParent.get(node.id) || []).map(viewNode).filter(Boolean),
    };
  };

  return (childrenByParent.get(0) || []).map(viewNode).filter(Boolean);
}

export function courseSummary(course, role, userId) {
  const titre = course.titre_id ? db.prepare('SELECT id, code, name, level FROM titres WHERE id = ?').get(course.titre_id) : null;
  const minutes = db.prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM nodes WHERE course_id = ? AND type = 'seance'").pluck().get(course.id);
  return {
    id: course.id, title: course.title, summary: course.summary, color: course.color,
    visible: !!course.visible, start_date: course.start_date, end_date: course.end_date,
    header_file_id: course.header_file_id, titre, role,
    duration_minutes: minutes,
    progress: role === 'stagiaire' ? progressFor(course.id, userId) : null,
    student_count: role === 'formateur'
      ? db.prepare("SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND role = 'stagiaire'").pluck().get(course.id)
      : undefined,
  };
}

const nextPosition = (table, column, parentId, courseId) => db.prepare(
  `SELECT COALESCE(MAX(position), -1) + 1 FROM ${table} WHERE course_id = ? AND ${column} IS ?`).pluck().get(courseId, parentId);

export function insertNode(courseId, parentId, type, fields) {
  return db.prepare(`INSERT INTO nodes (course_id, parent_id, type, title, description, duration_minutes, position, visible, available_from)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`).run(courseId, parentId, type, fields.title, fields.description || '',
    fields.duration_minutes || 0, nextPosition('nodes', 'parent_id', parentId, courseId),
    fields.visible === false ? 0 : 1, fields.available_from || null).lastInsertRowid;
}

export function insertItem(courseId, nodeId, type, title, data = {}, extra = {}) {
  return db.prepare(`INSERT INTO items (course_id, node_id, type, title, data, position, visible, available_from)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)`).run(courseId, nodeId, type, title, JSON.stringify(data),
    nextPosition('items', 'node_id', nodeId, courseId), extra.visible === false ? 0 : 1, extra.available_from || null).lastInsertRowid;
}

export function insertCourse(user, fields) {
  const id = db.prepare(`INSERT INTO courses (titre_id, title, summary, color, visible, start_date, end_date, created_by, created_at)
    VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)`).run(fields.titre_id || null, fields.title, fields.summary || '',
    fields.color || 'navy', fields.start_date || null, fields.end_date || null, user.id, now()).lastInsertRowid;
  db.prepare("INSERT INTO enrollments (course_id, user_id, role, created_at) VALUES (?, ?, 'formateur', ?)").run(id, user.id, now());
  return id;
}
