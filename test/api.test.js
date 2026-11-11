/** Tests d'intégration : démarre le serveur sur une base temporaire et vérifie les règles d'accès. */
import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const PORT = 3900 + Math.floor(Math.random() * 90);
const BASE = `http://localhost:${PORT}/api`;
let server;

function client() {
  let cookie = '';
  return async (method, url, body, { headers = {} } = {}) => {
    const init = { method, headers: { 'X-Requested-With': 'afparea', Cookie: cookie, ...headers } };
    if (body instanceof FormData) init.body = body;
    else if (body !== undefined) { init.body = JSON.stringify(body); init.headers['Content-Type'] = 'application/json'; }
    const response = await fetch(BASE + url, init);
    const set = response.headers.get('set-cookie');
    if (set) cookie = set.split(';')[0];
    const type = response.headers.get('content-type') || '';
    return { status: response.status, body: type.includes('json') ? await response.json() : await response.text(), headers: response.headers };
  };
}

const admin = client();
const trainer = client();
const student = client();
const outsider = client();
const state = {};

async function createUser(email, role) {
  const { body } = await admin('POST', '/users', { email, role, first_name: 'Test', last_name: role });
  return { id: body.user.id, password: body.password };
}
async function firstLogin(session, email, provisional) {
  assert.equal((await session('POST', '/login', { email, password: provisional })).status, 200);
  assert.equal((await session('GET', '/courses')).status, 403, 'accès bloqué tant que le mot de passe provisoire n’est pas changé');
  assert.equal((await session('POST', '/password', { current: provisional, next: `${provisional}-nouveau` })).status, 200);
}

before(async () => {
  const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'afparea-api-'));
  server = spawn(process.execPath, ['server/index.js'], {
    env: { ...process.env, PORT, DATA_DIR: dataDir, ADMIN_EMAIL: 'admin@test.re', ADMIN_PASSWORD: 'provisoire-admin', ANTHROPIC_API_KEY: '' },
    stdio: 'ignore',
  });
  for (let i = 0; i < 50; i++) {
    try { await fetch(`${BASE}/me`); break; } catch { await new Promise((resolve) => setTimeout(resolve, 100)); }
  }
  await firstLogin(admin, 'admin@test.re', 'provisoire-admin');
  const t = await createUser('formateur@test.re', 'formateur');
  const s = await createUser('stagiaire@test.re', 'stagiaire');
  const o = await createUser('autre@test.re', 'stagiaire');
  state.studentId = s.id;
  await firstLogin(trainer, 'formateur@test.re', t.password);
  await firstLogin(student, 'stagiaire@test.re', s.password);
  await firstLogin(outsider, 'autre@test.re', o.password);

  state.course = (await trainer('POST', '/courses', { title: 'Parcours test' })).body.id;
  const node = async (parent, title, extra = {}) => (await trainer('POST', `/courses/${state.course}/nodes`, { parent_id: parent, title, ...extra })).body.id;
  state.module = await node(null, 'Module 1');
  state.sequence = await node(state.module, 'Séquence 1');
  state.seance = await node(state.sequence, 'Séance 1', { duration_minutes: 210 });
  state.hiddenSeance = await node(state.sequence, 'Séance masquée', { visible: false });
  state.futureSeance = await node(state.sequence, 'Séance programmée', { available_from: '2999-01-01T00:00:00Z' });
  const item = async (nodeId, type, title, extra = {}) => {
    const data = new FormData();
    for (const [key, value] of Object.entries({ node_id: nodeId, type, title, ...extra })) data.append(key, value);
    return (await trainer('POST', `/courses/${state.course}/items`, data)).body.id;
  };
  state.page = await item(state.seance, 'page', 'Page ouverte', { text: 'Bonjour' });
  state.hiddenPage = await item(state.seance, 'page', 'Page masquée', { text: 'Secret', visible: '0' });
  state.futurePage = await item(state.seance, 'page', 'Page programmée', { text: 'Bientôt', available_from: '2999-01-01T00:00:00Z' });
  state.inHidden = await item(state.hiddenSeance, 'page', 'Dans une séance masquée', { text: 'Secret' });
  state.depot = await item(state.seance, 'depot', 'Dépôt', { instructions: 'À rendre' });
  await trainer('POST', `/courses/${state.course}/enroll`, { email: 'stagiaire@test.re' });
});

after(() => server?.kill());

test('requêtes sans session ou sans en-tête anti-CSRF refusées', async () => {
  assert.equal((await client()('GET', '/courses')).status, 401);
  const response = await fetch(`${BASE}/logout`, { method: 'POST' });
  assert.equal(response.status, 403);
});

test('un parcours masqué est invisible pour le stagiaire inscrit', async () => {
  assert.equal((await student('GET', `/courses/${state.course}`)).status, 404);
  assert.deepEqual((await student('GET', '/courses')).body.courses, []);
  await trainer('PATCH', `/courses/${state.course}`, { visible: true });
  assert.equal((await student('GET', `/courses/${state.course}`)).status, 200);
});

test('le stagiaire ne reçoit ni les éléments masqués ni le contenu des éléments programmés', async () => {
  const { body } = await student('GET', `/courses/${state.course}`);
  const seances = body.tree[0].children[0].children;
  assert.deepEqual(seances.map((s) => s.title), ['Séance 1', 'Séance programmée']);
  assert.equal(seances[1].locked, true);
  assert.equal(seances[1].description, undefined);
  const items = seances[0].items;
  assert.deepEqual(items.map((i) => i.title), ['Page ouverte', 'Page programmée', 'Dépôt']);
  assert.equal(items[1].locked, true);
  assert.equal(items[1].data, undefined);
  assert.ok(!JSON.stringify(body).includes('Secret'));
  for (const id of [state.hiddenPage, state.futurePage, state.inHidden]) {
    assert.equal((await student('GET', `/items/${id}`)).status, 404);
  }
  assert.equal((await student('GET', `/items/${state.page}`)).body.item.data.text, 'Bonjour');
});

test('un élément programmé s’ouvre automatiquement une fois la date passée', async () => {
  await trainer('PATCH', `/items/${state.futurePage}`, { available_from: '2020-01-01T00:00:00Z' });
  assert.equal((await student('GET', `/items/${state.futurePage}`)).body.item.data.text, 'Bientôt');
});

test('une personne non inscrite n’accède à rien du parcours', async () => {
  assert.equal((await outsider('GET', `/courses/${state.course}`)).status, 404);
  assert.equal((await outsider('GET', `/items/${state.page}`)).status, 404);
});

test('les actions de formateur sont refusées au stagiaire', async () => {
  assert.equal((await student('PATCH', `/courses/${state.course}`, { title: 'Piraté' })).status, 403);
  assert.equal((await student('PATCH', `/nodes/${state.seance}`, { visible: false })).status, 403);
  assert.equal((await student('DELETE', `/items/${state.page}`)).status, 403);
  assert.equal((await student('POST', `/courses/${state.course}/reorder`, { kind: 'node', parent_id: null, ids: [state.module] })).status, 403);
  assert.equal((await student('GET', `/courses/${state.course}/participants`)).status, 403);
  assert.equal((await student('POST', '/courses', { title: 'x' })).status, 403);
  assert.equal((await student('GET', '/users')).status, 403);
  assert.equal((await trainer('GET', '/users')).status, 403);
});

test('l’assistant FPA est réservé aux formateurs', async () => {
  assert.equal((await student('GET', '/ai/status')).status, 403);
  assert.equal((await student('POST', '/ai/conversations', {})).status, 403);
  assert.deepEqual((await trainer('GET', '/ai/status')).body, { configured: false });
});

test('réorganisation : la hiérarchie module > séquence > séance est respectée', async () => {
  const bad = await trainer('POST', `/courses/${state.course}/reorder`, { kind: 'node', parent_id: null, ids: [state.seance] });
  assert.equal(bad.status, 400);
  const ok = await trainer('POST', `/courses/${state.course}/reorder`, { kind: 'node', parent_id: state.sequence, ids: [state.futureSeance, state.seance, state.hiddenSeance] });
  assert.equal(ok.status, 200);
  const { body } = await trainer('GET', `/courses/${state.course}`);
  assert.deepEqual(body.tree[0].children[0].children.map((s) => s.title), ['Séance programmée', 'Séance 1', 'Séance masquée']);
});

test('dépôt : remise, confidentialité entre stagiaires, évaluation et progression', async () => {
  const data = new FormData();
  data.append('comment', 'Voici');
  data.append('files', new Blob(['contenu']), 'production été.txt');
  assert.equal((await student('POST', `/items/${state.depot}/submission`, data)).status, 200);

  const mine = (await student('GET', `/items/${state.depot}`)).body.item.submission;
  assert.equal(mine.files[0].name, 'production été.txt');
  const download = await student('GET', `/files/${mine.files[0].id}`);
  assert.equal(download.body, 'contenu');
  assert.match(download.headers.get('content-disposition'), /^attachment/);

  await trainer('POST', `/courses/${state.course}/enroll`, { email: 'autre@test.re' });
  assert.equal((await outsider('GET', `/files/${mine.files[0].id}`)).status, 404, 'un autre stagiaire ne lit pas la production');
  assert.equal((await outsider('GET', `/items/${state.depot}`)).body.item.submission, null);

  const all = (await trainer('GET', `/items/${state.depot}`)).body.item.submissions;
  const entry = all.find((e) => e.student.email === 'stagiaire@test.re');
  assert.equal((await student('PATCH', `/submissions/${entry.submission.id}`, { grade: 20 })).status, 403);
  assert.equal((await trainer('PATCH', `/submissions/${entry.submission.id}`, { grade: 25 })).status, 400);
  assert.equal((await trainer('PATCH', `/submissions/${entry.submission.id}`, { grade: 15, feedback: 'Bien' })).status, 200);
  assert.equal((await student('POST', `/items/${state.depot}/submission`, data)).status, 400, 'plus de modification après évaluation');

  const people = (await trainer('GET', `/courses/${state.course}/participants`)).body.participants;
  assert.deepEqual(people.find((p) => p.email === 'stagiaire@test.re').progress, { done: 1, total: 3, percent: 33 });
});

test('inscription par lot : aperçu sans écriture, puis création des comptes', async () => {
  const csv = 'nom;prénom;email\nDijoux;Émilie;emilie@test.re\nDéjà;Inscrit;stagiaire@test.re\nMauvais;Mail;nope\nForm;Ateur;formateur@test.re\n';
  const upload = () => { const data = new FormData(); data.append('file', new Blob([csv]), 'liste.csv'); return data; };
  const preview = (await trainer('POST', `/courses/${state.course}/import?preview`, upload())).body;
  assert.deepEqual(preview.rows.map((r) => r.status), ['cree', 'deja_inscrit', 'deja_inscrit']);
  assert.equal(preview.errors.length, 1);
  assert.equal((await admin('GET', '/users')).body.users.some((u) => u.email === 'emilie@test.re'), false);

  const result = (await trainer('POST', `/courses/${state.course}/import`, upload())).body;
  const created = result.rows[0];
  assert.equal(created.status, 'cree');
  const fresh = client();
  assert.equal((await fresh('POST', '/login', { email: 'emilie@test.re', password: created.password })).body.user.must_change_password, true);
  assert.equal((await student('POST', `/courses/${state.course}/import`, upload())).status, 403);
});

test('réinitialisation de mot de passe : formateur pour ses stagiaires uniquement', async () => {
  assert.equal((await student('POST', `/users/${state.studentId}/reset-password`)).status, 403);
  assert.equal((await trainer('POST', '/users/1/reset-password')).status, 403);
  const { body } = await trainer('POST', `/users/${state.studentId}/reset-password`);
  assert.equal((await student('GET', '/courses')).status, 401, 'les sessions existantes sont fermées');
  assert.equal((await client()('POST', '/login', { email: 'stagiaire@test.re', password: body.password })).status, 200);
});
