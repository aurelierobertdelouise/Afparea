<?php

declare(strict_types=1);

/** Lecture des listes de stagiaires : CSV et Excel (.xlsx), sans bibliothèque externe. */

/** Analyse CSV tolérante : séparateur ; , ou tabulation, guillemets, BOM, fins de ligne Windows. */
function parse_csv(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $firstLine = strtok($text, "\r\n") ?: '';
    $delimiter = ';';
    $best = -1;
    foreach ([';', "\t", ','] as $candidate) {
        $count = substr_count($firstLine, $candidate);
        if ($count > $best) {
            $best = $count;
            $delimiter = $candidate;
        }
    }

    $rows = [];
    $row = [];
    $cell = '';
    $quoted = false;
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
        $char = $text[$i];
        if ($quoted) {
            if ($char === '"' && ($text[$i + 1] ?? '') === '"') {
                $cell .= '"';
                $i++;
            } elseif ($char === '"') {
                $quoted = false;
            } else {
                $cell .= $char;
            }
        } elseif ($char === '"' && $cell === '') {
            $quoted = true;
        } elseif ($char === $delimiter) {
            $row[] = $cell;
            $cell = '';
        } elseif ($char === "\n" || $char === "\r") {
            if ($char === "\r" && ($text[$i + 1] ?? '') === "\n") {
                $i++;
            }
            $row[] = $cell;
            $rows[] = $row;
            $row = [];
            $cell = '';
        } else {
            $cell .= $char;
        }
    }
    if ($cell !== '' || $row) {
        $row[] = $cell;
        $rows[] = $row;
    }
    return $rows;
}

/** Indice de colonne (0 pour A) à partir d'une référence de cellule comme « BC12 ». */
function xlsx_column_index(string $reference): int
{
    $index = 0;
    foreach (str_split(preg_replace('/[^A-Z]/', '', strtoupper($reference))) as $letter) {
        $index = $index * 26 + ord($letter) - 64;
    }
    return max(0, $index - 1);
}

function xlsx_entry(ZipArchive $zip, string $name): ?SimpleXMLElement
{
    $stat = $zip->statName($name);
    if ($stat === false) {
        return null;
    }
    if ($stat['size'] > 30 * 1024 * 1024) {
        throw new HttpError(400, 'Fichier Excel trop volumineux.');
    }
    $xml = simplexml_load_string($zip->getFromName($name), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    return $xml === false ? null : $xml;
}

/** Texte d'un élément de chaîne Excel (<si> ou <is>), en ignorant les annotations phonétiques. */
function xlsx_text(SimpleXMLElement $element): string
{
    $text = (string) ($element->t ?? '');
    foreach ($element->r as $run) {
        $text .= (string) $run->t;
    }
    return $text;
}

/** Lit la première feuille d'un classeur .xlsx sous forme de tableau de lignes. */
function read_xlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new HttpError(400, 'Fichier Excel illisible. Enregistrez-le au format .xlsx ou .csv.');
    }
    try {
        // Première feuille déclarée dans le classeur.
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $workbook = xlsx_entry($zip, 'xl/workbook.xml');
        $relations = xlsx_entry($zip, 'xl/_rels/workbook.xml.rels');
        if ($workbook && $relations && isset($workbook->sheets->sheet[0])) {
            $relationId = (string) $workbook->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($relations->Relationship as $relation) {
                if ((string) $relation['Id'] === $relationId) {
                    $target = (string) $relation['Target'];
                    $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                }
            }
        }
        $sheet = xlsx_entry($zip, $sheetPath);
        if (!$sheet) {
            throw new HttpError(400, 'Fichier Excel illisible. Enregistrez-le au format .xlsx ou .csv.');
        }
        $shared = [];
        $strings = xlsx_entry($zip, 'xl/sharedStrings.xml');
        if ($strings) {
            foreach ($strings->si as $si) {
                $shared[] = xlsx_text($si);
            }
        }

        $rows = [];
        foreach ($sheet->sheetData->row as $rowElement) {
            $cells = [];
            $next = 0;
            foreach ($rowElement->c as $cell) {
                $index = isset($cell['r']) ? xlsx_column_index((string) $cell['r']) : $next;
                $type = (string) $cell['t'];
                $value = match ($type) {
                    's' => $shared[(int) $cell->v] ?? '',
                    'inlineStr' => isset($cell->is) ? xlsx_text($cell->is) : '',
                    default => (string) $cell->v,
                };
                $cells[$index] = $value;
                $next = $index + 1;
            }
            if ($cells) {
                $filled = array_fill(0, max(array_keys($cells)) + 1, '');
                $rows[] = array_replace($filled, $cells);
            } else {
                $rows[] = [];
            }
            if (count($rows) > 5000) {
                throw new HttpError(400, 'Fichier trop volumineux (2000 lignes maximum).');
            }
        }
        return $rows;
    } finally {
        $zip->close();
    }
}

function normalize_header(string $value): string
{
    $value = strtr(mb_strtolower($value, 'UTF-8'), [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
    ]);
    return preg_replace('/[^a-z]/', '', $value);
}

const ROSTER_HEADERS = [
    'email' => ['email', 'mail', 'courriel', 'adressemail', 'adresseemail', 'adressecourriel', 'adresseelectronique'],
    'last_name' => ['nom', 'lastname', 'nomdefamille', 'name', 'surname'],
    'first_name' => ['prenom', 'firstname', 'prenoms'],
];

/**
 * Lit un fichier .xlsx ou .csv et renvoie les stagiaires à inscrire.
 * @return array{rows: list<array{line:int,email:string,first_name:string,last_name:string}>, errors: list<array{line:int,message:string}>}
 */
function read_roster(string $path, string $fileName): array
{
    if (preg_match('/\.xlsx$/i', $fileName)) {
        $table = read_xlsx($path);
    } elseif (preg_match('/\.(csv|txt)$/i', $fileName)) {
        $text = file_get_contents($path);
        // Les exports Excel « CSV » sont souvent en Windows-1252.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $table = parse_csv($text);
    } else {
        throw new HttpError(400, 'Format non pris en charge : utilisez un fichier .xlsx ou .csv.');
    }

    $lines = [];
    foreach ($table as $index => $cells) {
        $cells = array_map(fn ($cell) => trim((string) $cell), $cells);
        if (array_filter($cells, fn ($cell) => $cell !== '')) {
            $lines[] = ['cells' => $cells, 'line' => $index + 1];
        }
    }
    if (!$lines) {
        throw new HttpError(400, 'Le fichier est vide.');
    }

    // Ligne d'en-tête reconnue ? Sinon on repère la colonne e-mail par son contenu.
    $columns = [];
    $header = array_map('normalize_header', $lines[0]['cells']);
    foreach (ROSTER_HEADERS as $field => $names) {
        foreach ($header as $index => $name) {
            if (in_array($name, $names, true)) {
                $columns[$field] = $index;
                break;
            }
        }
    }
    if (isset($columns['email'])) {
        array_shift($lines);
    } else {
        $emailIndex = null;
        foreach ($lines[0]['cells'] as $index => $cell) {
            if (preg_match(EMAIL_RE, $cell)) {
                $emailIndex = $index;
                break;
            }
        }
        if ($emailIndex === null) {
            throw new HttpError(400, 'Colonne e-mail introuvable. Attendu : colonnes « nom », « prénom », « email ».');
        }
        $others = array_values(array_diff(array_keys($lines[0]['cells']), [$emailIndex]));
        $columns = ['email' => $emailIndex, 'last_name' => $others[0] ?? -1, 'first_name' => $others[1] ?? -1];
    }

    $rows = [];
    $errors = [];
    $seen = [];
    foreach ($lines as ['cells' => $cells, 'line' => $line]) {
        $email = mb_strtolower($cells[$columns['email']] ?? '', 'UTF-8');
        if (!preg_match(EMAIL_RE, $email) || text_len($email) > 190) {
            $errors[] = ['line' => $line, 'message' => $email !== '' ? "Adresse e-mail invalide : $email" : 'Adresse e-mail manquante'];
        } elseif (isset($seen[$email])) {
            $errors[] = ['line' => $line, 'message' => "Doublon dans le fichier : $email"];
        } else {
            $seen[$email] = true;
            $rows[] = [
                'line' => $line,
                'email' => $email,
                'last_name' => mb_substr($cells[$columns['last_name'] ?? -1] ?? '', 0, 100, 'UTF-8'),
                'first_name' => mb_substr($cells[$columns['first_name'] ?? -1] ?? '', 0, 100, 'UTF-8'),
            ];
        }
    }
    if (count($rows) > 2000) {
        throw new HttpError(400, 'Fichier trop volumineux (2000 lignes maximum).');
    }
    return ['rows' => $rows, 'errors' => $errors];
}
