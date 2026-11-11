import crypto from 'node:crypto';
import { db, now } from './db.js';

const SESSION_DAYS = 7;
const COOKIE = 'afparea_sid';

export class HttpError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

export function hashPassword(password) {
  const salt = crypto.randomBytes(16);
  const hash = crypto.scryptSync(password, salt, 64);
  return `scrypt$${salt.toString('hex')}$${hash.toString('hex')}`;
}

export function verifyPassword(password, stored) {
  const [scheme, saltHex, hashHex] = String(stored).split('$');
  if (scheme !== 'scrypt' || !saltHex || !hashHex) return false;
  const expected = Buffer.from(hashHex, 'hex');
  const actual = crypto.scryptSync(password, Buffer.from(saltHex, 'hex'), expected.length);
  return crypto.timingSafeEqual(actual, expected);
}

export function checkPasswordPolicy(password) {
  if (typeof password !== 'string' || password.length < 10) {
    throw new HttpError(400, 'Le mot de passe doit contenir au moins 10 caractères.');
  }
  if (password.length > 200) throw new HttpError(400, 'Mot de passe trop long.');
}

/** Mot de passe provisoire lisible (sans caractères ambigus). */
export function generatePassword() {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
  let out = '';
  for (const byte of crypto.randomBytes(12)) out += alphabet[byte % alphabet.length];
  return out;
}

const sha = (value) => crypto.createHash('sha256').update(value).digest('hex');

function readCookie(req) {
  const header = req.headers.cookie || '';
  for (const part of header.split(';')) {
    const index = part.indexOf('=');
    if (index > 0 && part.slice(0, index).trim() === COOKIE) return part.slice(index + 1).trim();
  }
  return null;
}

function cookieAttributes(req) {
  const secure = process.env.COOKIE_SECURE
    ? process.env.COOKIE_SECURE === 'true'
    : req.secure;
  return `Path=/; HttpOnly; SameSite=Lax${secure ? '; Secure' : ''}`;
}

export function createSession(req, res, userId) {
  const token = crypto.randomBytes(32).toString('hex');
  const expires = new Date(Date.now() + SESSION_DAYS * 86400e3);
  db.prepare('INSERT INTO sessions (token_hash, user_id, expires_at) VALUES (?, ?, ?)').run(
    sha(token), userId, expires.toISOString());
  res.setHeader('Set-Cookie', `${COOKIE}=${token}; Max-Age=${SESSION_DAYS * 86400}; ${cookieAttributes(req)}`);
}

export function destroySession(req, res) {
  const token = readCookie(req);
  if (token) db.prepare('DELETE FROM sessions WHERE token_hash = ?').run(sha(token));
  res.setHeader('Set-Cookie', `${COOKIE}=; Max-Age=0; ${cookieAttributes(req)}`);
}

export function destroyUserSessions(userId) {
  db.prepare('DELETE FROM sessions WHERE user_id = ?').run(userId);
}

export function publicUser(user) {
  return {
    id: user.id,
    email: user.email,
    first_name: user.first_name,
    last_name: user.last_name,
    role: user.role,
    must_change_password: !!user.must_change_password,
    active: !!user.active,
  };
}

/** Charge l'utilisateur de la session (req.user) sans imposer la connexion. */
export function loadUser(req, _res, next) {
  const token = readCookie(req);
  if (token) {
    const user = db.prepare(`
      SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id
      WHERE s.token_hash = ? AND s.expires_at > ? AND u.active = 1`).get(sha(token), now());
    if (user) req.user = user;
  }
  next();
}

export function requireAuth(req, _res, next) {
  if (!req.user) return next(new HttpError(401, 'Connexion requise.'));
  // Tant que le mot de passe provisoire n'est pas changé, seul le compte est accessible.
  if (req.user.must_change_password) return next(new HttpError(403, 'Vous devez d’abord changer votre mot de passe.'));
  next();
}

export const requireRole = (...roles) => (req, _res, next) => {
  if (!roles.includes(req.user.role)) return next(new HttpError(403, 'Accès réservé.'));
  next();
};

/**
 * Protection CSRF : toute requête modifiante doit porter un en-tête personnalisé,
 * qu'un formulaire ou une page tierce ne peut pas ajouter (pas de CORS ouvert).
 */
export function csrfGuard(req, _res, next) {
  if (['GET', 'HEAD', 'OPTIONS'].includes(req.method)) return next();
  if (req.get('X-Requested-With') !== 'afparea') return next(new HttpError(403, 'Requête refusée.'));
  next();
}

// Limitation des tentatives de connexion (par adresse IP + e-mail).
const attempts = new Map();
const MAX_ATTEMPTS = 8;
const WINDOW_MS = 15 * 60e3;

export function loginThrottle(key) {
  const entry = attempts.get(key);
  if (entry && entry.until > Date.now() && entry.count >= MAX_ATTEMPTS) {
    throw new HttpError(429, 'Trop de tentatives. Réessayez dans quelques minutes.');
  }
  return {
    fail() {
      const current = attempts.get(key);
      if (!current || current.until < Date.now()) attempts.set(key, { count: 1, until: Date.now() + WINDOW_MS });
      else current.count += 1;
    },
    success() {
      attempts.delete(key);
    },
  };
}

setInterval(() => {
  const t = Date.now();
  for (const [key, entry] of attempts) if (entry.until < t) attempts.delete(key);
  db.prepare('DELETE FROM sessions WHERE expires_at < ?').run(now());
}, 10 * 60e3).unref();
