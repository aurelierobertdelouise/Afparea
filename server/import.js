import fs from 'node:fs';
import { readSheet } from 'read-excel-file/node';
import { HttpError } from './auth.js';
import { EMAIL_RE } from './util.js';

/** Analyse CSV tolérante : séparateur ; , ou tabulation, guillemets, BOM, fins de ligne Windows. */
export function parseCsv(text) {
  text = text.replace(/^﻿/, '');
  const firstLine = text.split(/\r?\n/, 1)[0] || '';
  const count = (char) => firstLine.split(char).length - 1;
  const delimiter = [';', '\t', ','].sort((a, b) => count(b) - count(a))[0];

  const rows = [];
  let row = [];
  let cell = '';
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    if (quoted) {
      if (char === '"' && text[i + 1] === '"') { cell += '"'; i++; }
      else if (char === '"') quoted = false;
      else cell += char;
    } else if (char === '"' && cell === '') quoted = true;
    else if (char === delimiter) { row.push(cell); cell = ''; }
    else if (char === '\n' || char === '\r') {
      if (char === '\r' && text[i + 1] === '\n') i++;
      row.push(cell); rows.push(row); row = []; cell = '';
    } else cell += char;
  }
  if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
  return rows;
}

function decodeText(buffer) {
  const utf8 = buffer.toString('utf8');
  // Les exports Excel « CSV » sont souvent en Windows-1252.
  return utf8.includes('�') ? new TextDecoder('windows-1252').decode(buffer) : utf8;
}

const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '')
  .toLowerCase().replace(/[^a-z]/g, '');

const HEADERS = {
  email: ['email', 'mail', 'courriel', 'adressemail', 'adresseemail', 'adressecourriel', 'adresseelectronique'],
  last_name: ['nom', 'lastname', 'nomdefamille', 'name', 'surname'],
  first_name: ['prenom', 'firstname', 'prenoms'],
};

/**
 * Lit un fichier .xlsx ou .csv et renvoie les stagiaires à inscrire.
 * @returns {Promise<{rows: {line:number,email:string,first_name:string,last_name:string}[], errors: {line:number,message:string}[]}>}
 */
export async function readRoster(filePath, fileName) {
  let table;
  if (/\.xlsx$/i.test(fileName)) {
    try {
      table = await readSheet(filePath);
    } catch {
      throw new HttpError(400, 'Fichier Excel illisible. Enregistrez-le au format .xlsx ou .csv.');
    }
  } else if (/\.(csv|txt)$/i.test(fileName)) {
    table = parseCsv(decodeText(fs.readFileSync(filePath)));
  } else {
    throw new HttpError(400, 'Format non pris en charge : utilisez un fichier .xlsx ou .csv.');
  }

  table = table.map((row) => row.map((cell) => String(cell ?? '').trim()))
    .map((cells, index) => ({ cells, line: index + 1 }))
    .filter(({ cells }) => cells.some(Boolean));
  if (!table.length) throw new HttpError(400, 'Le fichier est vide.');

  // Ligne d'en-tête reconnue ? Sinon on repère la colonne e-mail par son contenu.
  const columns = {};
  const header = table[0].cells.map(normalize);
  for (const [field, names] of Object.entries(HEADERS)) {
    const index = header.findIndex((name) => names.includes(name));
    if (index >= 0) columns[field] = index;
  }
  if (columns.email !== undefined) {
    table.shift();
  } else {
    const emailIndex = table[0].cells.findIndex((cell) => EMAIL_RE.test(cell));
    if (emailIndex < 0) {
      throw new HttpError(400, 'Colonne e-mail introuvable. Attendu : colonnes « nom », « prénom », « email ».');
    }
    const others = table[0].cells.map((_, index) => index).filter((index) => index !== emailIndex);
    columns.email = emailIndex;
    columns.last_name = others[0];
    columns.first_name = others[1];
  }

  const rows = [];
  const errors = [];
  const seen = new Set();
  for (const { cells, line } of table) {
    const email = (cells[columns.email] || '').toLowerCase();
    if (!EMAIL_RE.test(email)) {
      errors.push({ line, message: email ? `Adresse e-mail invalide : ${email}` : 'Adresse e-mail manquante' });
    } else if (seen.has(email)) {
      errors.push({ line, message: `Doublon dans le fichier : ${email}` });
    } else {
      seen.add(email);
      rows.push({
        line,
        email,
        last_name: (cells[columns.last_name] || '').slice(0, 100),
        first_name: (cells[columns.first_name] || '').slice(0, 100),
      });
    }
  }
  if (rows.length > 2000) throw new HttpError(400, 'Fichier trop volumineux (2000 lignes maximum).');
  return { rows, errors };
}
