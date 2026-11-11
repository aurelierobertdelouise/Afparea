import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
if (fs.existsSync(path.join(root, '.env'))) process.loadEnvFile(path.join(root, '.env'));

const express = (await import('express')).default;
const multer = (await import('multer')).default;
const { db, now } = await import('./db.js');
const { HttpError, loadUser, requireAuth, csrfGuard, hashPassword, generatePassword } = await import('./auth.js');
const { MAX_UPLOAD_MB } = await import('./util.js');
const core = await import('./routes/core.js');
const courses = await import('./routes/courses.js');
const ai = await import('./routes/ai.js');

// Premier démarrage : création du compte administrateur.
if (!db.prepare("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1").get()) {
  const email = (process.env.ADMIN_EMAIL || 'admin@afpar.re').toLowerCase();
  const password = process.env.ADMIN_PASSWORD || generatePassword();
  db.prepare(`INSERT INTO users (email, password_hash, first_name, last_name, role, must_change_password, created_at)
    VALUES (?, ?, 'Administration', 'AFPAR', 'admin', 1, ?)`).run(email, hashPassword(password), now());
  console.log(`\nCompte administrateur créé — e-mail : ${email}${process.env.ADMIN_PASSWORD ? '' : ` — mot de passe provisoire : ${password}`}`);
  console.log('Ce mot de passe devra être changé à la première connexion.\n');
}

const app = express();
app.disable('x-powered-by');
if (process.env.TRUST_PROXY) app.set('trust proxy', Number(process.env.TRUST_PROXY) || process.env.TRUST_PROXY);

app.use((_req, res, next) => {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('X-Frame-Options', 'SAMEORIGIN');
  res.setHeader('Referrer-Policy', 'same-origin');
  res.setHeader('Content-Security-Policy', [
    "default-src 'self'",
    "img-src 'self' data: blob:",
    "style-src 'self' 'unsafe-inline'",
    "frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'self'",
  ].join('; '));
  next();
});

// Bibliothèques du client servies depuis node_modules (aucune étape de compilation).
const vendor = {
  '/vendor/preact.js': 'htm/preact/standalone.module.js',
  '/vendor/sortable.js': 'sortablejs/modular/sortable.esm.js',
};
for (const [url, file] of Object.entries(vendor)) {
  app.get(url, (_req, res) => res.sendFile(path.join(root, 'node_modules', file), { maxAge: '7d' }));
}
app.use(express.static(path.join(root, 'public'), { maxAge: 0 }));

const api = express.Router();
api.use((_req, res, next) => { res.setHeader('Cache-Control', 'no-store'); next(); });
api.use(csrfGuard);
api.use(express.json({ limit: '2mb' }));
api.use(loadUser);
api.use(core.router); // connexion, puis requireAuth pour tout le reste
api.use(requireAuth);
api.use(courses.router);
api.use(ai.router);
api.use((_req, _res, next) => next(new HttpError(404, 'Ressource introuvable.')));

api.use((error, _req, res, _next) => {
  let status = error.status || 500;
  let message = error.message;
  if (error instanceof multer.MulterError) {
    status = 400;
    message = error.code === 'LIMIT_FILE_SIZE' ? `Fichier trop volumineux (${MAX_UPLOAD_MB} Mo maximum).` : 'Envoi de fichier refusé.';
  } else if (error.type === 'entity.parse.failed' || error.type === 'entity.too.large') {
    status = 400;
    message = 'Requête invalide.';
  } else if (status >= 500) {
    console.error(error);
    message = 'Erreur interne du serveur.';
  }
  if (res.headersSent) return res.end();
  res.status(status).json({ error: message });
});

app.use('/api', api);

const port = Number(process.env.PORT || 3000);
app.listen(port, () => console.log(`AFPAREA est en ligne : http://localhost:${port}`));
