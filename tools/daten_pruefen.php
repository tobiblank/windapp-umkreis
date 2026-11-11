<?php
/**
 * Abgleich mit den echten Daten (seit 11.11.2026). Liest die neuesten Dateien, die der
 * Sammler auf den Server geladen hat (die Action "Daten prüfen" holt sie per FTPS), und
 * beantwortet die Fragen aus README.md und SCHNEE.md ("beim ersten echten Lauf prüfen"):
 *
 *   1. Stand je Quelle
 *   2. Welche Variablen liefern Werte (je Modell, Ensemble zusammengefasst), welche nur null
 *   3. Gitterzellen: geliefert gegen angefragt (Abstand, Höhe), vor allem bei den Seen
 *   4. Schnee: Tourhöhe angenommen? past_days angekommen?
 *   5. Neuschnee-Raster: Punkte mit Werten
 *   6. Schneehöhe: Modell gegen Messstationen und SNOWGRID je Region
 *   7. Lageberichte je Dienst
 *
 *   php tools/daten_pruefen.php <datenordner> [daten/regionen.json] [daten/schneeregionen.json]
 *
 * Ändert nichts. Exit 0, auch wenn etwas auffällt: Das ist eine Übersicht, kein Test.
 */

require __DIR__ . '/../server/web/lib/regionen.php';
require __DIR__ . '/../server/web/lib/bewertung.php';
require __DIR__ . '/../server/web/lib/schnee.php';
require __DIR__ . '/../server/web/lib/lawine.php';

$dir = rtrim($argv[1] ?? '', '/');
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Aufruf: php tools/daten_pruefen.php <datenordner> [regionen.json] [schneeregionen.json]\n");
    exit(2);
}
$wind = json_decode((string)file_get_contents($argv[2] ?? __DIR__ . '/../daten/regionen.json'), true);
$schnee = json_decode((string)file_get_contents($argv[3] ?? __DIR__ . '/../daten/schneeregionen.json'), true);
$jetzt = time();

/** Neueste ok-Zeile einer Quelle (alle Zeilen der neuesten Datei mit ok) */
function zeilen_neueste(string $dir, string $q): array
{
    $dateien = array_merge(glob("$dir/$q/*.ndjson.gz") ?: [], glob("$dir/$q/*.ndjson") ?: []);
    rsort($dateien);
    if (!$dateien) {
        return [null, []];
    }
    $txt = (string)file_get_contents($dateien[0]);
    if (str_ends_with($dateien[0], '.gz')) {
        $txt = (string)gzdecode($txt);
    }
    $z = array_values(array_filter(array_map(fn($l) => json_decode($l, true), explode("\n", $txt))));
    return [basename($dateien[0]), $z];
}

function km(float $a1, float $o1, float $a2, float $o2): float
{
    $r = M_PI / 180;
    $d = sin(($a2 - $a1) * $r / 2) ** 2 + cos($a1 * $r) * cos($a2 * $r) * sin(($o2 - $o1) * $r / 2) ** 2;
    return 2 * 6371 * asin(min(1, sqrt($d)));
}

function titel(string $t): void
{
    echo "\n=== $t ===\n";
}

$omQuellen = ['vorhersage', 'ensemble', 'druck', 'druck_ensemble', 'hoehe', 'hoehe_ensemble', 'welle', 'wasser',
              'schnee_vorhersage', 'schnee_ensemble'];
$alle = array_merge($omQuellen, ['seewasser', 'schnee_raster', 'stationen', 'lawine']);
$daten = [];

// ---------------------------------------------------------------- 1. Stand
titel('1. Stand je Quelle (neueste Datei auf dem Server)');
foreach ($alle as $q) {
    [$datei, $zeilen] = zeilen_neueste($dir, $q);
    $daten[$q] = $zeilen;
    if ($datei === null) {
        echo sprintf("%-18s keine Datei\n", $q);
        continue;
    }
    $ok = count(array_filter($zeilen, fn($z) => !empty($z['ok'])));
    $ts = max(array_map(fn($z) => (string)($z['ts'] ?? ''), $zeilen) ?: ['']);
    $alter = $ts !== '' ? ($jetzt - strtotime($ts)) / 3600 : null;
    echo sprintf("%-18s %s  %d Zeilen, %d ok, Stand %s (vor %.1f h)\n", $q, $datei, count($zeilen), $ok, $ts, $alter ?? -1);
}

// ---------------------------------------------------------------- 2. Variablen
titel('2. Werte je Variable (Anteil Stunden mit Wert, über alle Orte; Ensemble-Läufe zusammengefasst)');
echo "Außerhalb seines Gebiets liefert ein Modell null (z. B. ICON-D2 am Atlantik); 0 % bei einem Regionalmodell ist dort normal.\n";
$eintraege = [];   // q => [id => eintrag]
foreach ($omQuellen as $q) {
    $n = [];
    $voll = [];
    foreach ($daten[$q] as $z) {
        if (empty($z['ok'])) {
            continue;
        }
        foreach ($z['regionen'] as $k => $id) {
            $e = $z['payload'][$k] ?? null;
            if (!is_array($e) || !isset($e['hourly'])) {
                continue;
            }
            $eintraege[$q][$id] = $e;
            foreach ($e['hourly'] as $var => $werte) {
                if ($var === 'time' || !is_array($werte)) {
                    continue;
                }
                $g = preg_replace('/_member\d+$/', '_member*', $var);
                $n[$g] = ($n[$g] ?? 0) + count($werte);
                $voll[$g] = ($voll[$g] ?? 0) + count(array_filter($werte, fn($v) => $v !== null));
            }
        }
    }
    if (!$n) {
        echo "$q: keine Einträge\n";
        continue;
    }
    ksort($n);
    echo "$q (" . count($eintraege[$q] ?? []) . " Orte):\n";
    foreach ($n as $g => $c) {
        $p = $c ? 100 * $voll[$g] / $c : 0;
        echo sprintf("  %-46s %5.1f %%%s\n", $g, $p, $p == 0 ? '  <- nur null' : '');
    }
}

// ---------------------------------------------------------------- 3. Gitterzellen
titel('3. Gitterzellen: angefragt gegen geliefert');
$regionen = [];
foreach ($wind['regionen'] as $r) {
    $regionen[$r['id']] = $r;
}
echo sprintf("%-22s %-5s %8s %8s %7s %8s %7s\n", 'Region', 'Typ', 'Modell km', 'Höhe m', 'Ens km', 'Ens Höhe', 'Wasser');
foreach ($regionen as $id => $r) {
    $v = $eintraege['vorhersage'][$id] ?? null;
    $en = $eintraege['ensemble'][$id] ?? null;
    if (!$v && !$en) {
        continue;
    }
    $f = fn($e) => isset($e['latitude'], $e['longitude']) ? sprintf('%.1f', km($r['punkt'][0], $r['punkt'][1], (float)$e['latitude'], (float)$e['longitude'])) : '-';
    $h = fn($e) => $e && isset($e['elevation']) ? (string)round((float)$e['elevation']) : '-';
    $wasser = '-';
    if ($r['typ'] === 'meer') {
        $w = $eintraege['wasser'][$id]['hourly']['sea_surface_temperature'] ?? [];
        $wasser = $w ? (count(array_filter($w, fn($x) => $x !== null)) ? 'ja' : 'null') : 'fehlt';
    }
    echo sprintf("%-22s %-5s %8s %8s %7s %8s %7s\n", mb_substr($r['name'], 0, 22), $r['typ'], $f($v), $h($v), $f($en), $h($en), $wasser);
}
echo "Bei Seen ist eine Höhe deutlich über dem Seespiegel ein Zeichen, dass die Zelle an Land liegt (Wind dort meist schwächer).\n";

// ---------------------------------------------------------------- 4. Schnee: Tourhöhe, past_days
titel('4. Schnee: Tourhöhe und Rückblick');
$sreg = [];
foreach ($schnee['regionen'] as $r) {
    $sreg[$r['id']] = $r;
}
echo sprintf("%-26s %7s %9s %9s %18s\n", 'Region', 'Tour m', 'Modell m', 'Ens m', 'erste Stunde UTC');
foreach ($sreg as $id => $r) {
    $v = $eintraege['schnee_vorhersage'][$id] ?? null;
    $en = $eintraege['schnee_ensemble'][$id] ?? null;
    $erste = $v['hourly']['time'][0] ?? '-';
    echo sprintf("%-26s %7d %9s %9s %18s\n", mb_substr($r['name'], 0, 26), $r['hoehe_tour'],
        isset($v['elevation']) ? round((float)$v['elevation']) : '-', isset($en['elevation']) ? round((float)$en['elevation']) : '-', $erste);
}
echo "Erwartet: Modell- und Ensemble-Höhe = Tourhöhe (elevation je Ort angenommen), erste Stunde rund 3 Tage zurück.\n";

// ---------------------------------------------------------------- 5. Raster
titel('5. Neuschnee-Raster');
$punkte = 0;
$mitWert = 0;
$tage = [];
foreach ($daten['schnee_raster'] as $z) {
    if (empty($z['ok'])) {
        continue;
    }
    foreach ($z['payload'] as $p) {
        $punkte++;
        if (!empty($p['neu'])) {
            $mitWert++;
            foreach (array_keys($p['neu']) as $t) {
                $tage[$t] = true;
            }
        }
    }
}
ksort($tage);
echo "$punkte Punkte, $mitWert mit Neuschnee-Werten; Tage: " . implode(', ', array_keys($tage)) . "\n";

// ---------------------------------------------------------------- 6. Schneehöhe Modell gegen Messung
titel('6. Schneehöhe heute 8 Uhr: Modell (Tourhöhe) gegen Stationen');
$geladen = stationen_laden($dir, $jetzt);
foreach ($geladen as $netz => $g) {
    echo sprintf("Netz %-11s Stand %s, %d Stationen\n", $netz, $g['ts'], count($g['stationen']));
}
echo "\n";
$heute = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
foreach ($sreg as $id => $r) {
    $tz = new DateTimeZone($r['zeitzone']);
    $st = schnee_stunden($eintraege['schnee_vorhersage'][$id] ?? null, $tz, (int)$r['hoehe_tour']);
    $modell = $st[$heute][SCHNEE_MORGEN]['hs'] ?? null;
    $mess = [];
    foreach (stationen_region($r, $geladen, $jetzt) as $s) {
        $w = $s['wert'];
        $mess[] = sprintf('%s %s m: %d cm%s%s', $w['name'], $w['hoehe'] ?? '?', round($w['hs']),
            $w['modell'] ? ' (SNOWGRID)' : '', $w['alt'] ? ' (alt)' : '');
    }
    echo sprintf("%-26s Modell %s | %s\n", mb_substr($r['name'], 0, 26),
        $modell === null ? '–' : round($modell) . ' cm', $mess ? implode('; ', $mess) : 'keine Station');
}
echo "Stationen messen am Standort (oft tiefer als die Tourhöhe); große Abweichungen nur als Hinweis lesen.\n";

// ---------------------------------------------------------------- 7. Lawine
titel('7. Lageberichte');
foreach ($daten['lawine'] as $z) {
    $teile = [];
    foreach ($z['tage'] ?? [] as $tag => $b) {
        $teile[] = $tag . ': ' . ($b === null ? 'kein Bericht' : count($b) . ' Teile');
    }
    echo sprintf("%-10s %s %s%s\n", $z['dienst'] ?? '?', empty($z['ok']) ? 'FEHLER' : 'ok', implode(', ', $teile),
        isset($z['err']) ? ' – ' . $z['err'] : '');
}
echo "\nFertig.\n";
