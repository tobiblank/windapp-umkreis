<?php
/**
 * Vorrechnen fuer eine Sportart (seit 8.11.2026), siehe lib/vorrechnen.php.
 *
 *   POST vorrechnen.php   schluessel=<vorrechnen_schluessel aus config.php>&sport=<id>
 *
 * Die Action "Sammeln" ruft das nach dem Hochladen fuer jede Sportart auf, damit
 * der erste Besucher nach einem Lauf nicht warten muss. Ohne Schluessel in
 * config.php (mindestens 20 Zeichen) oder mit falschem Schluessel: 404, damit
 * niemand von aussen Rechenlast erzeugen kann. Antwort: Kennzahlen als JSON.
 */

declare(strict_types=1);
date_default_timezone_set('UTC');
require __DIR__ . '/lib/regionen.php';
require __DIR__ . '/lib/bewertung.php';
require __DIR__ . '/lib/vorrechnen.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$cfg = @include __DIR__ . '/config.php';
$soll = is_array($cfg) ? ($cfg['vorrechnen_schluessel'] ?? null) : null;
$ist = $_POST['schluessel'] ?? '';
if (!is_string($soll) || strlen($soll) < 20 || !is_string($ist) || !hash_equals($soll, $ist) || empty($cfg['data_dir'])) {
    http_response_code(404);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
$sportId = $_POST['sport'] ?? '';
if (!is_string($sportId) || !isset(SPORTARTEN[$sportId])) {
    http_response_code(400);
    echo json_encode(['fehler' => 'unbekannte Sportart', 'sportarten' => array_keys(SPORTARTEN)]);
    exit;
}

// Wie index.php: mehr Speicher erbitten, wo erlaubt; nicht abbrechen, wenn curl auflegt
$grenze = ini_get('memory_limit');
if ($grenze !== false && $grenze !== '-1' && (int)$grenze > 0 && stripos($grenze, 'M') !== false && (int)$grenze < 256) {
    @ini_set('memory_limit', '256M');
}
ignore_user_abort(true);

$dataDir = rtrim((string)$cfg['data_dir'], '/');
$cacheDir = array_key_exists('cache_dir', $cfg) ? ($cfg['cache_dir'] === null ? null : rtrim((string)$cfg['cache_dir'], '/')) : "$dataDir/cache";
if (!cache_bereit($cacheDir)) {
    http_response_code(503);
    echo json_encode(['fehler' => 'Zwischenspeicher nicht beschreibbar (cache_dir)']);
    exit;
}
$katalog = $cfg['regionen_datei'] ?? __DIR__ . '/lib/regionen.json';
try {
    $regionen = regionen_laden($katalog);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['fehler' => 'Katalog nicht lesbar']);
    exit;
}
echo json_encode(vorrechnen($regionen, $dataDir, $cacheDir, $sportId, 'Europe/Berlin', $katalog) + ['memory_limit' => ini_get('memory_limit')]);
