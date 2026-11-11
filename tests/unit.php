<?php

declare(strict_types=1);

/** Tests unitaires (sans base de données) : php tests/unit.php */
require __DIR__ . '/../site/app/bootstrap.php';
require __DIR__ . '/../site/app/import.php';
require __DIR__ . '/../site/app/fpa.php';

$failures = 0;
function check(string $name, callable $fn): void
{
    global $failures;
    try {
        $fn();
        echo "ok   $name\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL $name\n     " . get_class($e) . ' : ' . $e->getMessage() . "\n";
    }
}
function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('attendu ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', obtenu ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}
function throws(callable $fn, string $pattern): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (preg_match($pattern, $e->getMessage())) {
            return;
        }
        throw new RuntimeException('message inattendu : ' . $e->getMessage());
    }
    throw new RuntimeException('aucune erreur levée');
}
$tmp = sys_get_temp_dir() . '/afparea-unit-' . bin2hex(random_bytes(4));
mkdir($tmp);

check('CSV : point-virgule, guillemets, BOM et fins de ligne Windows', function () {
    same([['nom', 'prénom', 'email'], ['Payet; Jr', 'Lu "Lulu"', 'a@b.re']], parse_csv("\xEF\xBB\xBFnom;prénom;email\r\n\"Payet; Jr\";\"Lu \"\"Lulu\"\"\";a@b.re\r\n"));
});

check('import CSV : en-têtes reconnus, doublons et adresses invalides signalés', function () use ($tmp) {
    file_put_contents("$tmp/liste.csv", "Prénom,E-mail,NOM\nLéa,LEA@exemple.re,Hoarau\nTom,pas-bon,Payet\nLéa,lea@exemple.re,Hoarau\n");
    $result = read_roster("$tmp/liste.csv", 'liste.csv');
    same([['line' => 2, 'email' => 'lea@exemple.re', 'last_name' => 'Hoarau', 'first_name' => 'Léa']], $result['rows']);
    same(2, count($result['errors']));
    same(3, $result['errors'][0]['line']);
});

check('import CSV : fichier sans en-tête, colonne e-mail repérée par son contenu', function () use ($tmp) {
    file_put_contents("$tmp/brut.csv", "Payet;Lucas;lucas@exemple.re\n");
    same(['line' => 1, 'email' => 'lucas@exemple.re', 'last_name' => 'Payet', 'first_name' => 'Lucas'], read_roster("$tmp/brut.csv", 'brut.csv')['rows'][0]);
});

check('import CSV : export Excel en Windows-1252', function () use ($tmp) {
    file_put_contents("$tmp/win.csv", mb_convert_encoding("nom;prénom;email\nTécher;Zoé;zoe@exemple.re\n", 'Windows-1252', 'UTF-8'));
    same(['line' => 2, 'email' => 'zoe@exemple.re', 'last_name' => 'Técher', 'first_name' => 'Zoé'], read_roster("$tmp/win.csv", 'win.csv')['rows'][0]);
});

check('import Excel : chaînes partagées, texte en ligne, cellules vides', function () use ($tmp) {
    $zip = new ZipArchive();
    $zip->open("$tmp/liste.xlsx", ZipArchive::CREATE);
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Liste" sheetId="1" r:id="rId7"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Target="worksheets/feuille.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Nom</t></si><si><t>Prénom</t></si><si><t>Adresse mail</t></si><si><r><t>Gron</t></r><r><t>din</t></r></si><si><t>anais@exemple.re</t></si></sst>');
    $zip->addFromString('xl/worksheets/feuille.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
        . '<row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2" t="inlineStr"><is><t>Anaïs</t></is></c><c r="C2" t="s"><v>4</v></c></row>'
        . '<row r="3"><c r="A3" t="inlineStr"><is><t>Sautron</t></is></c><c r="C3" t="inlineStr"><is><t>noe@exemple.re</t></is></c></row>'
        . '</sheetData></worksheet>');
    $zip->close();
    same([
        ['line' => 2, 'email' => 'anais@exemple.re', 'last_name' => 'Grondin', 'first_name' => 'Anaïs'],
        ['line' => 3, 'email' => 'noe@exemple.re', 'last_name' => 'Sautron', 'first_name' => ''],
    ], read_roster("$tmp/liste.xlsx", 'liste.xlsx')['rows']);
});

check('proposition FPA : total calculé, activités inconnues écartées', function () {
    $proposal = fpa_normalize_proposal(['titre' => 'Parcours', 'modules' => [['titre' => 'M1', 'sequences' => [['titre' => 'S1', 'seances' => [
        ['titre' => 'A', 'duree_heures' => 3.5, 'objectifs' => ['o'], 'activites' => [['type' => 'quiz', 'titre' => 'x', 'contenu' => ''], ['type' => 'depot', 'titre' => 'ECF', 'contenu' => 'c']]],
        ['titre' => 'B', 'duree_heures' => '7', 'objectifs' => []],
    ]]]]]]);
    same(10.5, $proposal['duree_totale_heures']);
    same(['depot'], array_column($proposal['modules'][0]['sequences'][0]['seances'][0]['activites'], 'type'));
});

check('proposition FPA : entrée tronquée refusée', function () {
    throws(fn () => fpa_normalize_proposal(['titre' => 'P', 'modules' => [['titre' => 'M1']]]), '/liste attendue/');
    throws(fn () => fpa_normalize_proposal(['titre' => 'P', 'modules' => [['titre' => 'M', 'sequences' => [['titre' => 'S', 'seances' => [['titre' => 'A']]]]]]]), '/durée invalide/');
    throws(fn () => fpa_normalize_proposal(['titre' => 'P', 'modules' => []]), '/aucun module/');
});

check('dates : formats acceptés et refusés', function () {
    same('2027-01-15T04:00:00.000Z', iso_in('2027-01-15T04:00:00.000Z'));
    same('2027-01-15T04:00:00.000Z', iso_in('2027-01-15T08:00:00+04:00'));
    same('2026-11-02T00:00:00.000Z', iso_in('2026-11-02'));
    same(null, iso_in(''));
    throws(fn () => iso_in('demain'), '/invalide/');
});

array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
echo $failures ? "\n$failures test(s) en échec\n" : "\nTous les tests unitaires passent.\n";
exit($failures ? 1 : 0);
