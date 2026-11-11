import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

process.env.DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'afparea-unit-'));
const { parseCsv, readRoster } = await import('../server/import.js');
const { normalizeProposal } = await import('../server/fpa.js');

test('CSV : point-virgule, guillemets, BOM et fins de ligne Windows', () => {
  const rows = parseCsv('﻿nom;prénom;email\r\n"Payet; Jr";"Lu ""Lulu""";a@b.re\r\n');
  assert.deepEqual(rows, [['nom', 'prénom', 'email'], ['Payet; Jr', 'Lu "Lulu"', 'a@b.re']]);
});

test('import : en-têtes reconnus, doublons et adresses invalides signalés', async () => {
  const file = path.join(process.env.DATA_DIR, 'liste.csv');
  fs.writeFileSync(file, 'Prénom,E-mail,NOM\nLéa,LEA@exemple.re,Hoarau\nTom,pas-bon,Payet\nLéa,lea@exemple.re,Hoarau\n');
  const { rows, errors } = await readRoster(file, 'liste.csv');
  assert.deepEqual(rows, [{ line: 2, email: 'lea@exemple.re', last_name: 'Hoarau', first_name: 'Léa' }]);
  assert.equal(errors.length, 2);
  assert.equal(errors[0].line, 3);
});

test('import : fichier sans en-tête, colonne e-mail repérée par son contenu', async () => {
  const file = path.join(process.env.DATA_DIR, 'brut.csv');
  fs.writeFileSync(file, 'Payet;Lucas;lucas@exemple.re\n');
  const { rows } = await readRoster(file, 'brut.csv');
  assert.deepEqual(rows[0], { line: 1, email: 'lucas@exemple.re', last_name: 'Payet', first_name: 'Lucas' });
});

test('proposition FPA : total calculé, activités inconnues écartées', () => {
  const proposal = normalizeProposal({
    titre: 'Parcours', modules: [{ titre: 'M1', sequences: [{ titre: 'S1', seances: [
      { titre: 'A', duree_heures: 3.5, objectifs: ['o'], activites: [{ type: 'quiz', titre: 'x', contenu: '' }, { type: 'depot', titre: 'ECF', contenu: 'c' }] },
      { titre: 'B', duree_heures: '7', objectifs: [] },
    ] }] }],
  });
  assert.equal(proposal.duree_totale_heures, 10.5);
  assert.deepEqual(proposal.modules[0].sequences[0].seances[0].activites.map((a) => a.type), ['depot']);
});

test('proposition FPA : entrée tronquée refusée', () => {
  assert.throws(() => normalizeProposal({ titre: 'P', modules: [{ titre: 'M1' }] }), /liste attendue/);
  assert.throws(() => normalizeProposal({ titre: 'P', modules: [{ titre: 'M', sequences: [{ titre: 'S', seances: [{ titre: 'A' }] }] }] }), /durée invalide/);
  assert.throws(() => normalizeProposal({ titre: 'P', modules: [] }), /aucun module/);
});
