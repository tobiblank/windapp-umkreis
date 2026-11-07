<?php
/**
 * Wohinfahrma - Fahrzeiten (seit 7.11.2026)
 * =========================================
 *
 * Fahrzeit mit dem Auto vom Startort (Standard Inning am Ammersee) zu jeder
 * Region, bei openrouteservice (ORS, HeiGIT). Laeuft als GitHub Action
 * (.github/workflows/fahrzeiten.yml), wenn sich der Katalog aendert, und
 * einmal im Monat; das Ergebnis liegt als fahrzeiten.json im Datenordner.
 *
 *     ORS_API_KEY=... php server/fahrzeiten.php daten/regionen.json ausgabe/fahrzeiten.json [--debug]
 *
 * Ziel je Region: anfahrt (Katalog), sonst punkt des ersten Spots, sonst der
 * Vorhersagepunkt. Der liegt meist auf dem Wasser; deshalb erst auf die
 * naechste Strasse einrasten (Snapping, Radius stufenweise bis SNAP_MAX_M),
 * dann EINE Matrix-Anfrage Start -> alle Ziele. Kostenloser ORS-Plan laut
 * ask.openrouteservice.org: 500 Matrix-Anfragen am Tag, je Anfrage bis zu
 * 3500 Strecken; hier eine Anfrage je Lauf.
 *
 * Datenschutz: Es werden nur Katalog-Koordinaten und der feste Startort
 * gesendet, nie der Standort von Besuchern.
 *
 * Ausgabe:
 *   {"stand": "...Z", "quelle": "openrouteservice",
 *    "start": {"name": ..., "punkt": [lat, lon]},
 *    "ziele": {"ammersee": {"min": 14, "km": 9.8, "ziel": [lat, lon], "snap_m": 120}, "tarifa": null, ...}}
 * null = Strasse gefunden, aber keine Route (Insel ohne Faehre im Netz).
 * Fehlt eine Region ganz, gab es keine Strasse im Umkreis des Ziels; die Seite
 * schaetzt dann aus der Luftlinie. Abhilfe: anfahrt im Katalog setzen.
 *
 * Exit 0 = geschrieben, 1 = Fehler beim Abruf (nichts geschrieben),
 * 2 = Aufruf falsch oder ORS_API_KEY fehlt.
 *
 * UNGEPRUEFT gegen das echte ORS (nur gegen tests/fake_ors.php): Pfade
 * /v2/snap/driving-car und /v2/matrix/driving-car, Einheit von snapped_distance
 * (angenommen: Meter), und ob die Matrix bei fehlender Route null oder 0 liefert
 * (beides wird als "keine Route" gewertet). Beim ersten Lauf --debug ansehen.
 */

date_default_timezone_set('UTC');
require __DIR__ . '/web/lib/regionen.php';

const START_NAME = 'Inning am Ammersee';
const START_PUNKT = [48.07, 11.15];
const SNAP_RADIEN = [2000, 10000, 30000];   // Meter, nacheinander fuer die noch fehlenden Ziele
const SNAP_TEIL = 50;                        // Orte je Snapping-Anfrage
const TIMEOUT = 60;

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--debug'));
$DEBUG = in_array('--debug', $argv, true);
if (count($args) !== 2) {
    fwrite(STDERR, "Aufruf: ORS_API_KEY=... php fahrzeiten.php <regionen.json> <ausgabe.json> [--debug]\n");
    exit(2);
}
[$katalogDatei, $ausgabe] = $args;
$key = (string)getenv('ORS_API_KEY');
if ($key === '') {
    fwrite(STDERR, "ORS_API_KEY fehlt (kostenloser Schluessel bei openrouteservice.org)\n");
    exit(2);
}
$basis = rtrim((string)(getenv('UMKREIS_ORS_URL') ?: 'https://api.openrouteservice.org'), '/');

function log_debug(string $s): void
{
    global $DEBUG;
    if ($DEBUG) {
        fwrite(STDERR, $s . "\n");
    }
}

function ors(string $url, string $key, array $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => ['Authorization: ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => TIMEOUT,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'wohinfahrma/0.1 (Hobbyprojekt, https://wohinfahrma.de)',
    ]);
    $antwort = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = is_string($antwort) ? json_decode($antwort, true) : null;
    if ($http !== 200 || !is_array($json)) {
        $text = is_string($antwort) ? substr($antwort, 0, 300) : '';
        throw new RuntimeException("$url: HTTP $http $err $text");
    }
    return $json;
}

try {
    $regionen = regionen_laden($katalogDatei);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

// Ziele [id => [lat, lon]]
$ziele = [];
foreach ($regionen as $r) {
    $p = $r['anfahrt'] ?? null;
    foreach ($r['spots'] ?? [] as $s) {
        $p ??= $s['punkt'] ?? null;
    }
    $ziele[$r['id']] = $p ?? $r['punkt'];
}

try {
    // 1. Einrasten auf die naechste Strasse
    $gerastet = [];   // id => ['punkt' => [lat, lon], 'snap_m' => float]
    $versucht = 0;    // groesster Radius, den der Server angenommen hat
    foreach (SNAP_RADIEN as $radius) {
        $offen = array_diff_key($ziele, $gerastet);
        foreach (array_chunk($offen, SNAP_TEIL, true) as $teil) {
            $ids = array_keys($teil);
            try {
                $j = ors("$basis/v2/snap/driving-car", $key, [
                    'locations' => array_map(fn($p) => [(float)$p[1], (float)$p[0]], array_values($teil)),
                    'radius'    => $radius,
                ]);
            } catch (RuntimeException $e) {
                // Groessere Radien sind vielleicht nicht erlaubt: dann bleibt es beim bisherigen Stand
                if ($radius === SNAP_RADIEN[0]) {
                    throw $e;
                }
                fwrite(STDERR, "Snapping mit $radius m abgelehnt: " . $e->getMessage() . "\n");
                break 2;
            }
            $versucht = $radius;
            foreach ($ids as $i => $id) {
                $l = $j['locations'][$i] ?? null;
                if (is_array($l) && isset($l['location'][0], $l['location'][1])) {
                    $gerastet[$id] = ['punkt' => [round((float)$l['location'][1], 5), round((float)$l['location'][0], 5)],
                                      'snap_m' => round((float)($l['snapped_distance'] ?? 0))];
                }
            }
        }
        log_debug("Snapping $radius m: " . count($gerastet) . ' von ' . count($ziele));
        if (count($gerastet) === count($ziele)) {
            break;
        }
    }
    foreach (array_diff_key($ziele, $gerastet) as $id => $_) {
        log_debug("$id: keine Strasse im Umkreis von $versucht m, wird geschaetzt (anfahrt im Katalog setzen)");
    }

    // 2. Matrix Start -> alle eingerasteten Ziele
    $ids = array_keys($gerastet);
    $orte = [[START_PUNKT[1], START_PUNKT[0]]];
    foreach ($ids as $id) {
        $orte[] = [$gerastet[$id]['punkt'][1], $gerastet[$id]['punkt'][0]];
    }
    $m = $ids ? ors("$basis/v2/matrix/driving-car", $key, [
        'locations'    => $orte,
        'sources'      => [0],
        'destinations' => range(1, count($ids)),
        'metrics'      => ['duration', 'distance'],
        'units'        => 'km',
    ]) : ['durations' => [[]], 'distances' => [[]]];
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Abruf fehlgeschlagen: ' . $e->getMessage() . "\n");
    exit(1);
}

// Nicht eingerastete Ziele fehlen in der Datei (die Seite schaetzt dann);
// null nur, wenn es eine Strasse, aber keine Route gibt (Insel)
$out = [];
foreach ($ids as $id) {
    $out[$id] = null;
}
foreach ($ids as $j => $id) {
    $s = $m['durations'][0][$j] ?? null;
    $km = $m['distances'][0][$j] ?? null;
    if ($s === null || $km === null || $s <= 0) {
        log_debug("$id: keine Route");
        continue;
    }
    $out[$id] = ['min' => (int)round($s / 60), 'km' => round((float)$km, 1),
                 'ziel' => $gerastet[$id]['punkt'], 'snap_m' => $gerastet[$id]['snap_m']];
    log_debug(sprintf('%-20s %4d min %6.0f km  (eingerastet %d m)', $id, $out[$id]['min'], $km, $gerastet[$id]['snap_m']));
}

$json = json_encode([
    'stand'  => gmdate('Y-m-d\TH:i:s\Z'),
    'quelle' => 'openrouteservice',
    'start'  => ['name' => START_NAME, 'punkt' => START_PUNKT],
    'ziele'  => $out,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@mkdir(dirname($ausgabe), 0777, true);
if (file_put_contents($ausgabe, $json . "\n") === false) {
    fwrite(STDERR, "Kann $ausgabe nicht schreiben\n");
    exit(1);
}
exit(0);
