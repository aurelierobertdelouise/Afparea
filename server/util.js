import crypto from 'node:crypto';
import path from 'node:path';
import multer from 'multer';
import { UPLOAD_DIR } from './db.js';
import { HttpError } from './auth.js';

export const MAX_UPLOAD_MB = Number(process.env.MAX_UPLOAD_MB || 50);

/** Enveloppe un gestionnaire (éventuellement async) pour transmettre les erreurs à Express. */
export const route = (handler) => (req, res, next) => {
  try {
    Promise.resolve(handler(req, res, next)).catch(next);
  } catch (error) {
    next(error);
  }
};

const storage = multer.diskStorage({
  destination: UPLOAD_DIR,
  filename: (_req, _file, cb) => cb(null, crypto.randomBytes(20).toString('hex')),
});

export const upload = multer({
  storage,
  limits: { fileSize: MAX_UPLOAD_MB * 1024 * 1024, files: 20 },
});

/** Les navigateurs envoient le nom de fichier en UTF-8, que multer lit en latin1. */
export function originalName(file) {
  const decoded = Buffer.from(file.originalname, 'latin1').toString('utf8');
  const name = decoded.includes('�') ? file.originalname : decoded;
  return path.basename(name).slice(0, 200) || 'fichier';
}

export function str(value, { max = 500, required = false, label = 'Champ' } = {}) {
  const text = typeof value === 'string' ? value.trim() : '';
  if (required && !text) throw new HttpError(400, `${label} obligatoire.`);
  if (text.length > max) throw new HttpError(400, `${label} trop long (${max} caractères maximum).`);
  return text;
}

/** Date ISO ou null ; refuse les valeurs invalides. */
export function isoDate(value, label = 'Date') {
  if (value === null || value === undefined || value === '') return null;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) throw new HttpError(400, `${label} invalide.`);
  return date.toISOString();
}

export function int(value, label = 'Identifiant') {
  const number = Number(value);
  if (!Number.isInteger(number)) throw new HttpError(400, `${label} invalide.`);
  return number;
}

export const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

export function safeUrl(value) {
  const text = str(value, { max: 2000, required: true, label: 'Adresse' });
  let url;
  try {
    url = new URL(text);
  } catch {
    throw new HttpError(400, 'Adresse web invalide (elle doit commencer par https://).');
  }
  if (!['http:', 'https:'].includes(url.protocol)) throw new HttpError(400, 'Seules les adresses http(s) sont acceptées.');
  return url.toString();
}
