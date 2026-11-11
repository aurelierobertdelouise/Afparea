import Database from 'better-sqlite3';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

export const DATA_DIR = path.resolve(process.env.DATA_DIR || path.join(root, 'data'));
export const UPLOAD_DIR = path.join(DATA_DIR, 'uploads');
fs.mkdirSync(UPLOAD_DIR, { recursive: true });

export const db = new Database(path.join(DATA_DIR, 'afparea.db'));
db.pragma('journal_mode = WAL');
db.pragma('foreign_keys = ON');

db.exec(`
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY,
  email TEXT NOT NULL UNIQUE COLLATE NOCASE,
  password_hash TEXT NOT NULL,
  first_name TEXT NOT NULL DEFAULT '',
  last_name TEXT NOT NULL DEFAULT '',
  role TEXT NOT NULL CHECK (role IN ('admin','formateur','stagiaire')),
  must_change_password INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS sessions (
  token_hash TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  expires_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS titres (
  id INTEGER PRIMARY KEY,
  code TEXT NOT NULL DEFAULT '',
  name TEXT NOT NULL,
  level TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS courses (
  id INTEGER PRIMARY KEY,
  titre_id INTEGER REFERENCES titres(id) ON DELETE SET NULL,
  title TEXT NOT NULL,
  summary TEXT NOT NULL DEFAULT '',
  header_file_id INTEGER,
  color TEXT NOT NULL DEFAULT 'navy',
  visible INTEGER NOT NULL DEFAULT 0,
  start_date TEXT,
  end_date TEXT,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS enrollments (
  course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role TEXT NOT NULL CHECK (role IN ('formateur','stagiaire')),
  created_at TEXT NOT NULL,
  PRIMARY KEY (course_id, user_id)
);
CREATE TABLE IF NOT EXISTS nodes (
  id INTEGER PRIMARY KEY,
  course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  parent_id INTEGER REFERENCES nodes(id) ON DELETE CASCADE,
  type TEXT NOT NULL CHECK (type IN ('module','sequence','seance')),
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  duration_minutes INTEGER NOT NULL DEFAULT 0,
  position INTEGER NOT NULL DEFAULT 0,
  visible INTEGER NOT NULL DEFAULT 1,
  available_from TEXT
);
CREATE INDEX IF NOT EXISTS nodes_course ON nodes(course_id, parent_id, position);
CREATE TABLE IF NOT EXISTS items (
  id INTEGER PRIMARY KEY,
  course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  node_id INTEGER NOT NULL REFERENCES nodes(id) ON DELETE CASCADE,
  type TEXT NOT NULL CHECK (type IN ('etiquette','page','fichier','dossier','lien','video','depot')),
  title TEXT NOT NULL,
  data TEXT NOT NULL DEFAULT '{}',
  position INTEGER NOT NULL DEFAULT 0,
  visible INTEGER NOT NULL DEFAULT 1,
  available_from TEXT
);
CREATE INDEX IF NOT EXISTS items_node ON items(node_id, position);
CREATE TABLE IF NOT EXISTS submissions (
  id INTEGER PRIMARY KEY,
  item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  comment TEXT NOT NULL DEFAULT '',
  submitted_at TEXT NOT NULL,
  grade REAL,
  feedback TEXT NOT NULL DEFAULT '',
  graded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  graded_at TEXT,
  UNIQUE (item_id, user_id)
);
CREATE TABLE IF NOT EXISTS files (
  id INTEGER PRIMARY KEY,
  stored_name TEXT NOT NULL,
  original_name TEXT NOT NULL,
  mime TEXT NOT NULL,
  size INTEGER NOT NULL,
  owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  course_id INTEGER REFERENCES courses(id) ON DELETE CASCADE,
  item_id INTEGER REFERENCES items(id) ON DELETE CASCADE,
  submission_id INTEGER REFERENCES submissions(id) ON DELETE CASCADE,
  kind TEXT NOT NULL CHECK (kind IN ('header','resource','submission')),
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS completions (
  item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  completed_at TEXT NOT NULL,
  PRIMARY KEY (item_id, user_id)
);
CREATE TABLE IF NOT EXISTS ai_conversations (
  id INTEGER PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS ai_documents (
  id INTEGER PRIMARY KEY,
  conversation_id INTEGER NOT NULL REFERENCES ai_conversations(id) ON DELETE CASCADE,
  kind TEXT NOT NULL CHECK (kind IN ('REAC','REV','AUTRE')),
  name TEXT NOT NULL,
  mime TEXT NOT NULL,
  size INTEGER NOT NULL,
  stored_name TEXT NOT NULL,
  message_id INTEGER
);
CREATE TABLE IF NOT EXISTS ai_messages (
  id INTEGER PRIMARY KEY,
  conversation_id INTEGER NOT NULL REFERENCES ai_conversations(id) ON DELETE CASCADE,
  role TEXT NOT NULL CHECK (role IN ('user','assistant')),
  content TEXT NOT NULL,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS ai_proposals (
  id INTEGER PRIMARY KEY,
  conversation_id INTEGER NOT NULL REFERENCES ai_conversations(id) ON DELETE CASCADE,
  message_id INTEGER REFERENCES ai_messages(id) ON DELETE CASCADE,
  data TEXT NOT NULL,
  course_id INTEGER REFERENCES courses(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL
);
`);

export const now = () => new Date().toISOString();

export function tx(fn) {
  return db.transaction(fn)();
}

/** Supprime du disque les fichiers dont la ligne en base a disparu (après suppressions en cascade). */
export function sweepOrphanFiles() {
  const known = new Set([
    ...db.prepare('SELECT stored_name FROM files').pluck().all(),
    ...db.prepare('SELECT stored_name FROM ai_documents').pluck().all(),
  ]);
  for (const name of fs.readdirSync(UPLOAD_DIR)) {
    if (!known.has(name)) fs.rmSync(path.join(UPLOAD_DIR, name), { force: true });
  }
}
