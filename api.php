<?php
/**
 * Sitzplan — gemeinsamer Speicher
 *
 * Legt Versionsstände als Dateien im Unterordner "daten" ab.
 * Der Inhalt kommt bereits im Browser verschlüsselt an (AES-GCM mit der
 * Passphrase der Seite) — der Server sieht also nie Klartext.
 *
 * Endpunkte
 *   GET  api.php?a=list              Liste aller Stände (Name, Autor, Zeit, Größe)
 *   GET  api.php?a=load&id=<id>      einen Stand laden
 *   POST api.php?a=save              {id?, name, author, blob} speichern
 *   POST api.php?a=delete            {id} löschen
 */

const DATA_DIR  = __DIR__ . '/daten';
const MAX_BYTES = 2000000;   // 2 MB je Stand
const MAX_FILES = 200;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function ok($data = array()) {
    echo json_encode(array_merge(array('ok' => true), $data), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_dir(DATA_DIR)) {
    if (!@mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        fail('Datenordner konnte nicht angelegt werden.', 500);
    }
}
// Direktzugriff auf die Rohdateien unterbinden
$ht = DATA_DIR . '/.htaccess';
if (!file_exists($ht)) {
    @file_put_contents($ht, "Require all denied\nDeny from all\n");
}

$action = $_GET['a'] ?? '';

function safeId($id) {
    if (!preg_match('/^[A-Za-z0-9_-]{6,40}$/', $id)) fail('Ungültige ID.');
    return $id;
}

function readBody() {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > MAX_BYTES) fail('Anfrage zu groß.', 413);
    $j = json_decode($raw, true);
    if (!is_array($j)) fail('Ungültiges JSON.');
    return $j;
}

switch ($action) {

    case 'list': {
        $out = [];
        foreach (glob(DATA_DIR . '/*.json') ?: [] as $f) {
            $j = json_decode((string)file_get_contents($f), true);
            if (!is_array($j)) continue;
            $out[] = [
                'id'      => $j['id']      ?? basename($f, '.json'),
                'name'    => $j['name']    ?? 'ohne Namen',
                'author'  => $j['author']  ?? '',
                'updated' => $j['updated'] ?? filemtime($f),
                'size'    => strlen((string)($j['blob'] ?? '')),
            ];
        }
        usort($out, function($a, $b){ return $b['updated'] - $a['updated']; });
        ok(['items' => $out]);
    }

    case 'load': {
        $id = safeId((string)($_GET['id'] ?? ''));
        $f  = DATA_DIR . '/' . $id . '.json';
        if (!is_file($f)) fail('Nicht gefunden.', 404);
        $j = json_decode((string)file_get_contents($f), true);
        if (!is_array($j)) fail('Datei beschädigt.', 500);
        ok(['item' => $j]);
    }

    case 'save': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST erwartet.', 405);
        $b = readBody();

        $blob = (string)($b['blob'] ?? '');
        if ($blob === '') fail('Kein Inhalt.');
        if (strlen($blob) > MAX_BYTES) fail('Stand zu groß.', 413);

        $name   = trim((string)($b['name'] ?? 'Ohne Namen'));
        $author = trim((string)($b['author'] ?? ''));
        if (mb_strlen($name) > 80)   $name   = mb_substr($name, 0, 80);
        if (mb_strlen($author) > 60) $author = mb_substr($author, 0, 60);

        $id = (string)($b['id'] ?? '');
        if ($id === '') {
            if (count(glob(DATA_DIR . '/*.json') ?: []) >= MAX_FILES) fail('Zu viele Stände gespeichert.', 507);
            $id = bin2hex(random_bytes(8));
        } else {
            $id = safeId($id);
        }

        $rec = [
            'id'      => $id,
            'name'    => $name,
            'author'  => $author,
            'updated' => time(),
            'blob'    => $blob,
        ];

        $tmp = DATA_DIR . '/.' . $id . '.tmp';
        if (file_put_contents($tmp, json_encode($rec, JSON_UNESCAPED_UNICODE)) === false) {
            fail('Schreiben fehlgeschlagen.', 500);
        }
        rename($tmp, DATA_DIR . '/' . $id . '.json');
        ok(['id' => $id, 'updated' => $rec['updated']]);
    }

    case 'delete': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST erwartet.', 405);
        $b  = readBody();
        $id = safeId((string)($b['id'] ?? ''));
        $f  = DATA_DIR . '/' . $id . '.json';
        if (is_file($f)) unlink($f);
        ok();
    }

    default:
        fail('Unbekannte Aktion.', 404);
}
