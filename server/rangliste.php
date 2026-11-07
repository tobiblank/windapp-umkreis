<?php
/**
 * Rangliste auf der Kommandozeile, aus den Rohdaten des Sammlers.
 * Zum Ausprobieren, bevor es eine Seite gibt.
 *
 *   php rangliste.php [sportart] [radius_km] [lat,lon]
 *   php rangliste.php wingfoil 800 48.07,11.15
 *
 * Sportarten: siehe SPORTARTEN in web/lib/bewertung.php.
 * Ohne Ort: Inning am Ammersee. Tage: heute bis +6.
 */

date_default_timezone_set('UTC');
$WEB_DIR = __DIR__ . '/web';   // wie in sammler.php anpassen
require "$WEB_DIR/lib/regionen.php";
require "$WEB_DIR/lib/bewertung.php";
$CFG = require "$WEB_DIR/config.php";

$sportId = $argv[1] ?? 'kite_twintip';
$radius  = (float)($argv[2] ?? 1500);
$heim    = isset($argv[3]) ? array_map('floatval', explode(',', $argv[3])) : [48.07, 11.15];
if (!isset(SPORTARTEN[$sportId])) {
    echo "Unbekannte Sportart. Möglich: ", implode(', ', array_keys(SPORTARTEN)), "\n";
    exit(1);
}
$sport = SPORTARTEN[$sportId];

$tage = [];
for ($i = 0; $i < 7; $i++) {
    $tage[] = gmdate('Y-m-d', time() + 86400 * $i);
}
$regionen = regionen_laden($CFG['regionen_datei']);
$stand = daten_laden(rtrim($CFG['data_dir'], '/'));
$liste = rangliste($regionen, $stand, $sport, $heim, $radius, $tage);

printf("%s ab %d kn, Umkreis %d km, ROHWERTE (nicht kalibriert)\n\n", $sport['name'], $sport['min'], $radius);
printf("%-22s %6s %7s  %s\n", 'Region', 'km', 'Tage', implode(' ', array_map(fn($t) => substr($t, 5), $tage)));
foreach ($liste as $e) {
    $zellen = [];
    foreach ($tage as $t) {
        $d = $e['tage'][$t] ?? null;
        $zellen[] = $d && $d['ens'] !== null ? sprintf('%4d%%', round(100 * $d['ens']['chance']))
                                             : ($d && $d['det']['geht'] ? '   ja' : '    -');
    }
    printf("%-22s %6d %7s  %s\n", mb_substr($e['region']['name'], 0, 22), $e['km'],
           $e['erwartete_tage'] !== null ? number_format($e['erwartete_tage'], 1, ',', '') : '-', implode(' ', $zellen));
    foreach ($tage as $t) {
        $d = $e['tage'][$t] ?? null;
        if ($d && $d['det']['geht']) {
            printf("    %s %s: %s, %d-%d Uhr, Ø %s kn%s%s\n", substr($t, 5),
                   $d['spot_name'] ?? 'ganze Region',
                   $d['det']['windlage'] ?? 'jede Richtung (grob)',
                   $d['det']['von'], $d['det']['bis'],
                   number_format($d['det']['mittel_kn'], 1, ',', ''),
                   $d['det']['boe_max'] !== null ? ', Böen bis ' . round($d['det']['boe_max']) . ' kn' : '',
                   $d['det']['charakter'] ? ' (' . $d['det']['charakter'] . ')' : '');
        }
    }
}
echo "\nTage = erwartete gute Tage (Summe der Ensemble-Chancen). Prozent = Anteil der 51 ECMWF-Läufe.\n";
