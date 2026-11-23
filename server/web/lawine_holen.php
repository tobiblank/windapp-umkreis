<?php
/**
 * Lageberichte und Messstationen auf dem Server holen (seit 13.11.2026), seit 23.11.2026
 * auch die Gardasee-Vorhersage von Marco Schanda; siehe server_abruf() in lib/abruf.php.
 *
 *   POST lawine_holen.php   schluessel=<abruf_schluessel aus config.php>
 *
 * Angestossen von melden.php auf ammerseewind.de (laeuft dort alle 10 Minuten als
 * Cronjob) etwa alle 30 Minuten. Grund: Der Zeitplan der GitHub Action "Sammeln"
 * startete oft Stunden zu spaet; ein neuer Lagebericht soll aber bald in der App
 * stehen. Wind, Welle und Schnee-Vorhersage holt weiter die Action (Open-Meteo-
 * Kontingent, lange Laufzeit); hier nur die kleinen Abrufe bei den Warndiensten und
 * Messnetzen, zusammen wenige Sekunden.
 *
 * Ohne Schluessel in config.php (mindestens 20 Zeichen) oder mit falschem Schluessel:
 * 404, damit niemand von aussen Abrufe ausloesen kann. Zu frueh angestossen (Lagebericht
 * unter 20, Stationen unter 50 Minuten seit dem letzten Versuch): nichts tun.
 * Antwort: JSON mit dem Ergebnis je Quelle.
 */

declare(strict_types=1);
date_default_timezone_set('UTC');
require __DIR__ . '/lib/regionen.php';
require_once __DIR__ . '/lib/bewertung.php';
require __DIR__ . '/lib/schnee.php';
require __DIR__ . '/lib/lawine.php';
require __DIR__ . '/lib/abruf.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$cfg = @include __DIR__ . '/config.php';
$soll = is_array($cfg) ? ($cfg['abruf_schluessel'] ?? null) : null;
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
ignore_user_abort(true);
@set_time_limit(300);

$dataDir = rtrim((string)$cfg['data_dir'], '/');
@mkdir("$dataDir/.state", 0700, true);
$sperre = @fopen("$dataDir/.state/server_abruf.lock", 'c');
if (!$sperre || !flock($sperre, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    echo json_encode(['fehler' => 'laeuft schon']);
    exit;
}
try {
    $schnee = schnee_katalog_laden($cfg['schneeregionen_datei'] ?? __DIR__ . '/lib/schneeregionen.json');
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['fehler' => 'Schnee-Katalog nicht lesbar']);
    exit;
}
$ersatz = $cfg['test_url_ersatz'] ?? null;
// Wind-Katalog fuer fremde Prognosen, die der Server holt (seit 23.11.2026: Gardasee)
$windKatalog = $cfg['regionen_datei'] ?? __DIR__ . '/lib/regionen.json';
try {
    $windRegionen = is_file($windKatalog) ? regionen_laden($windKatalog) : [];
} catch (RuntimeException $e) {
    $windRegionen = [];
}
echo json_encode(server_abruf($dataDir, $schnee, is_string($ersatz) ? $ersatz : null, null, $windRegionen), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
