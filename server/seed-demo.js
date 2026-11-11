/** Jeu de données de démonstration : `npm run seed:demo`. À ne pas utiliser en production. */
import { db, now, tx } from './db.js';
import { hashPassword, generatePassword } from './auth.js';
import { insertCourse, insertNode, insertItem } from './model.js';

const password = process.env.DEMO_PASSWORD || generatePassword();
const hash = hashPassword(password);

function user(email, first, last, role) {
  const existing = db.prepare('SELECT * FROM users WHERE email = ?').get(email);
  if (existing) return existing;
  const id = db.prepare(`INSERT INTO users (email, password_hash, first_name, last_name, role, must_change_password, created_at)
    VALUES (?, ?, ?, ?, ?, 0, ?)`).run(email, hash, first, last, role, now()).lastInsertRowid;
  return { id, email, role };
}

const inDays = (days) => new Date(Date.now() + days * 86400e3).toISOString();

tx(() => {
  const trainer = user('formatrice.demo@afpar.re', 'Marie', 'Hoarau', 'formateur');
  const students = [
    ['Lucas', 'Payet'], ['Anaïs', 'Grondin'], ['Mathieu', 'Rivière'], ['Océane', 'Fontaine'], ['Kevin', 'Boyer'], ['Sarah', 'Técher'],
  ].map(([first, last]) => user(`${first}.${last}@demo.afpar.re`.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''), first, last, 'stagiaire'));

  const titre = (code, name, level) => db.prepare('SELECT id FROM titres WHERE name = ?').pluck().get(name)
    ?? db.prepare('INSERT INTO titres (code, name, level) VALUES (?, ?, ?)').run(code, name, level).lastInsertRowid;
  const fpa = titre('FPA', 'Formateur professionnel d’adultes', 'Niveau 5');
  titre('SC', 'Secrétaire comptable', 'Niveau 4');
  titre('DWWM', 'Développeur web et web mobile', 'Niveau 5');

  if (db.prepare("SELECT 1 FROM courses WHERE title LIKE 'TP Formateur professionnel%'").get()) return;

  const course = insertCourse(trainer, {
    titre_id: fpa,
    title: 'TP Formateur professionnel d’adultes — session 2026',
    summary: 'Parcours préparant au titre professionnel FPA : concevoir, préparer et animer des actions de formation, accompagner les apprenants.',
    start_date: inDays(-20), end_date: inDays(160),
  });
  db.prepare('UPDATE courses SET visible = 1 WHERE id = ?').run(course);
  for (const student of students) {
    db.prepare("INSERT INTO enrollments (course_id, user_id, role, created_at) VALUES (?, ?, 'stagiaire', ?)").run(course, student.id, now());
  }

  const accueil = insertNode(course, null, 'module', { title: 'Accueil et intégration', description: 'Découverte du parcours, du titre et de la plateforme.' });
  const seq0 = insertNode(course, accueil, 'sequence', { title: 'Démarrer la formation' });
  const s0 = insertNode(course, seq0, 'seance', { title: 'Présentation du titre et du parcours', duration_minutes: 210, description: '- Situer les deux activités-types du titre\n- Identifier les modalités de certification' });
  insertItem(course, s0, 'etiquette', 'Étiquette', { text: '**Bienvenue !** Retrouvez ici toutes les ressources de votre première journée.' });
  const page = insertItem(course, s0, 'page', 'Livret d’accueil', { text: '## Votre parcours\n\nLe titre **Formateur professionnel d’adultes** se compose de deux certificats de compétences professionnelles (CCP).\n\n- CCP 1 : préparer et animer des actions de formation collectives\n- CCP 2 : construire des parcours individualisés et accompagner les apprenants\n\n## Votre plateforme\n\nCochez chaque élément une fois terminé : votre progression se met à jour.' });
  insertItem(course, s0, 'lien', 'Fiche du titre sur France Compétences', { url: 'https://www.francecompetences.fr/recherche/rncp/' });
  const s1 = insertNode(course, seq0, 'seance', { title: 'Contrat pédagogique et positionnement', duration_minutes: 210 });
  insertItem(course, s1, 'depot', 'Dépôt : auto-positionnement', { instructions: 'Complétez la grille d’auto-positionnement puis déposez-la ici au format PDF.', due_at: inDays(5), max_grade: 20 });

  const ccp1 = insertNode(course, null, 'module', { title: 'CCP 1 — Préparer et animer des actions de formation collectives', description: 'Activité-type 1 du référentiel.' });
  const seq1 = insertNode(course, ccp1, 'sequence', { title: 'Élaborer la progression pédagogique d’une action de formation' });
  const s2 = insertNode(course, seq1, 'seance', { title: 'Analyser une demande de formation', duration_minutes: 420 });
  insertItem(course, s2, 'page', 'Du besoin à l’objectif de formation', { text: 'Une demande de formation se traduit en **objectifs de formation**, puis en **objectifs pédagogiques**.' });
  insertItem(course, s2, 'video', 'Vidéo : formuler un objectif pédagogique', { url: 'https://www.youtube.com/watch?v=aqz-KE-bpKQ' });
  const s3 = insertNode(course, seq1, 'seance', { title: 'Construire une progression pédagogique', duration_minutes: 420 });
  insertItem(course, s3, 'depot', 'Dépôt : ma progression pédagogique', { instructions: 'Déposez la progression pédagogique construite en sous-groupe.', due_at: inDays(12), max_grade: 20 });
  const seq2 = insertNode(course, ccp1, 'sequence', { title: 'Concevoir un scénario pédagogique et une séquence de formation', available_from: inDays(9) });
  insertNode(course, seq2, 'seance', { title: 'Méthodes et techniques pédagogiques', duration_minutes: 420 });
  insertNode(course, seq2, 'seance', { title: 'Scénariser une séance multimodale', duration_minutes: 420 });
  const seq3 = insertNode(course, ccp1, 'sequence', { title: 'Animer un temps de formation collectif', visible: false });
  insertNode(course, seq3, 'seance', { title: 'Techniques d’animation de groupe', duration_minutes: 420 });

  const ccp2 = insertNode(course, null, 'module', { title: 'CCP 2 — Construire des parcours individualisés et accompagner les apprenants', available_from: inDays(45) });
  const seq4 = insertNode(course, ccp2, 'sequence', { title: 'Concevoir l’ingénierie de parcours individualisés' });
  insertNode(course, seq4, 'seance', { title: 'Positionnement et individualisation', duration_minutes: 420 });

  // Un peu d'activité pour illustrer la progression et les dépôts.
  const complete = db.prepare('INSERT OR IGNORE INTO completions (item_id, user_id, completed_at) VALUES (?, ?, ?)');
  students.slice(0, 4).forEach((student) => complete.run(page, student.id, now()));
});

console.log(`Données de démonstration prêtes.
  Formatrice : formatrice.demo@afpar.re
  Stagiaire  : lucas.payet@demo.afpar.re
  Mot de passe des comptes de démonstration : ${password}`);
