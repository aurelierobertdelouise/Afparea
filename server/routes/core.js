import { Router } from 'express';
import { db, now } from '../db.js';
import {
  HttpError, hashPassword, verifyPassword, checkPasswordPolicy, generatePassword,
  createSession, destroySession, destroyUserSessions, publicUser,
  requireAuth, requireRole, loginThrottle,
} from '../auth.js';
import { route, str, int, EMAIL_RE } from '../util.js';

export const router = Router();

// ---------- Authentification ----------

router.post('/login', route((req, res) => {
  const email = str(req.body.email, { max: 200 }).toLowerCase();
  const password = typeof req.body.password === 'string' ? req.body.password : '';
  const throttle = loginThrottle(`${req.ip}|${email}`);
  const user = db.prepare('SELECT * FROM users WHERE email = ?').get(email);
  if (!user || !user.active || !verifyPassword(password, user.password_hash)) {
    throttle.fail();
    throw new HttpError(401, 'Adresse e-mail ou mot de passe incorrect.');
  }
  throttle.success();
  createSession(req, res, user.id);
  res.json({ user: publicUser(user) });
}));

router.post('/logout', route((req, res) => {
  destroySession(req, res);
  res.json({ ok: true });
}));

router.get('/me', route((req, res) => {
  res.json({ user: req.user ? publicUser(req.user) : null });
}));

router.post('/password', route((req, res) => {
  if (!req.user) throw new HttpError(401, 'Connexion requise.');
  const current = typeof req.body.current === 'string' ? req.body.current : '';
  if (!verifyPassword(current, req.user.password_hash)) throw new HttpError(400, 'Mot de passe actuel incorrect.');
  checkPasswordPolicy(req.body.next);
  if (req.body.next === current) throw new HttpError(400, 'Choisissez un mot de passe différent de l’actuel.');
  db.prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
    .run(hashPassword(req.body.next), req.user.id);
  destroyUserSessions(req.user.id);
  createSession(req, res, req.user.id);
  res.json({ ok: true });
}));

router.use(requireAuth);

// ---------- Utilisateurs (administration) ----------

const ROLES = ['admin', 'formateur', 'stagiaire'];

router.get('/users', requireRole('admin'), route((_req, res) => {
  const users = db.prepare('SELECT * FROM users ORDER BY last_name, first_name, email').all();
  res.json({ users: users.map(publicUser) });
}));

router.post('/users', requireRole('admin'), route((req, res) => {
  const email = str(req.body.email, { max: 200, required: true, label: 'E-mail' }).toLowerCase();
  if (!EMAIL_RE.test(email)) throw new HttpError(400, 'Adresse e-mail invalide.');
  if (!ROLES.includes(req.body.role)) throw new HttpError(400, 'Rôle invalide.');
  if (db.prepare('SELECT 1 FROM users WHERE email = ?').get(email)) throw new HttpError(409, 'Un compte existe déjà avec cette adresse.');
  const password = generatePassword();
  const info = db.prepare(`INSERT INTO users (email, password_hash, first_name, last_name, role, must_change_password, created_at)
    VALUES (?, ?, ?, ?, ?, 1, ?)`).run(email, hashPassword(password),
    str(req.body.first_name, { max: 100 }), str(req.body.last_name, { max: 100 }), req.body.role, now());
  res.status(201).json({ user: publicUser(db.prepare('SELECT * FROM users WHERE id = ?').get(info.lastInsertRowid)), password });
}));

router.patch('/users/:id', requireRole('admin'), route((req, res) => {
  const user = db.prepare('SELECT * FROM users WHERE id = ?').get(int(req.params.id));
  if (!user) throw new HttpError(404, 'Utilisateur introuvable.');
  const next = { ...user };
  if ('first_name' in req.body) next.first_name = str(req.body.first_name, { max: 100 });
  if ('last_name' in req.body) next.last_name = str(req.body.last_name, { max: 100 });
  if ('role' in req.body) {
    if (!ROLES.includes(req.body.role)) throw new HttpError(400, 'Rôle invalide.');
    next.role = req.body.role;
  }
  if ('active' in req.body) next.active = req.body.active ? 1 : 0;
  if (user.id === req.user.id && (next.role !== 'admin' || !next.active)) {
    throw new HttpError(400, 'Vous ne pouvez pas retirer vos propres droits d’administration.');
  }
  db.prepare('UPDATE users SET first_name = ?, last_name = ?, role = ?, active = ? WHERE id = ?')
    .run(next.first_name, next.last_name, next.role, next.active, user.id);
  if (!next.active) destroyUserSessions(user.id);
  res.json({ user: publicUser(next) });
}));

/** Réinitialisation : par l'admin, ou par un formateur pour un stagiaire de l'un de ses parcours. */
router.post('/users/:id/reset-password', route((req, res) => {
  const user = db.prepare('SELECT * FROM users WHERE id = ?').get(int(req.params.id));
  if (!user) throw new HttpError(404, 'Utilisateur introuvable.');
  if (req.user.role !== 'admin') {
    const shared = req.user.role === 'formateur' && user.role === 'stagiaire' && db.prepare(`
      SELECT 1 FROM enrollments mine JOIN enrollments theirs ON theirs.course_id = mine.course_id
      WHERE mine.user_id = ? AND mine.role = 'formateur' AND theirs.user_id = ?`).get(req.user.id, user.id);
    if (!shared) throw new HttpError(403, 'Accès réservé.');
  }
  const password = generatePassword();
  db.prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?').run(hashPassword(password), user.id);
  destroyUserSessions(user.id);
  res.json({ password });
}));

// ---------- Titres professionnels ----------

router.get('/titres', route((_req, res) => {
  const titres = db.prepare(`SELECT t.*, (SELECT COUNT(*) FROM courses c WHERE c.titre_id = t.id) AS course_count
    FROM titres t ORDER BY t.name`).all();
  res.json({ titres });
}));

function titreFields(body) {
  return [
    str(body.code, { max: 40 }),
    str(body.name, { max: 200, required: true, label: 'Intitulé' }),
    str(body.level, { max: 40 }),
    str(body.description, { max: 2000 }),
  ];
}

router.post('/titres', requireRole('admin', 'formateur'), route((req, res) => {
  const info = db.prepare('INSERT INTO titres (code, name, level, description) VALUES (?, ?, ?, ?)').run(...titreFields(req.body));
  res.status(201).json({ id: info.lastInsertRowid });
}));

router.patch('/titres/:id', requireRole('admin', 'formateur'), route((req, res) => {
  const info = db.prepare('UPDATE titres SET code = ?, name = ?, level = ?, description = ? WHERE id = ?')
    .run(...titreFields(req.body), int(req.params.id));
  if (!info.changes) throw new HttpError(404, 'Titre introuvable.');
  res.json({ ok: true });
}));

router.delete('/titres/:id', requireRole('admin'), route((req, res) => {
  db.prepare('DELETE FROM titres WHERE id = ?').run(int(req.params.id));
  res.json({ ok: true });
}));
