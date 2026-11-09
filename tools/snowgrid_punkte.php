<?php
/**
 * Punkte fuer SNOWGRID-CL (GeoSphere Austria, 1-km-Raster, taeglich) suchen (seit 9.11.2026).
 *
 *   php tools/snowgrid_punkte.php daten/schneeregionen.json [punkte.json] [km] [region,region]
 *
 * Je Schnee-Region ein Raster von Punkten im Umkreis km (Standard 10) um den
 * Vorhersagepunkt, Hoehe aus der Open-Meteo Elevation API (Copernicus DEM 90 m).
 * Gewaehlt wird je Zielhoehe (Tourhoehe und Mitte zwischen Tal und Tour) der Punkt
 * naechst dem Vorhersagepunkt, dessen Hoehe hoechstens 100 m abweicht. Fuer diese
 * Punkte die SNOWGRID-Schneehoehe der letzten Tage (in einem Abruf mit mehreren lat_lon).
 * SNOWGRID deckt nur Oesterreich ab; ausserhalb kommen nulls.
 * Ausserdem: Zeitreihe einer Suedtiroler Station (Format fuer die Aenderung in 24 h).
 * Laeuft als GitHub Action "SNOWGRID-Punkte suchen".
 */

declare(strict_types=1);
date_default_timezone_set('UTC');
require __DIR__ . '/../server/web/lib/regionen.php';
require __DIR__ . '/../server/web/lib/bewertung.php';
require __DIR__ . '/../server/web/lib/schnee.php';

$katalog = $argv[1] ?? null;
$aus = $argv[2] ?? null;
$km = (float)($argv[3] ?? 10);
$nur = array_filter(explode(',', (string)($argv[4] ?? '')));
if (!$katalog) {
    fwrite(STDERR, "Aufruf: php tools/snowgrid_punkte.php <schneeregionen.json> [punkte.json] [km]\n");
    exit(2);
}
$regionen = schnee_katalog_laden($katalog)['regionen'];

function holen(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_ENCODING => '',
                            CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'windapp-umkreis/0.1 (Hobbyprojekt)']);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($code === 429) {
        fwrite(STDERR, "429, warte 61 s\n");
        sleep(61);
        return holen($url);
    }
    if ($body === false || $code < 200 || $code >= 300) {
        fwrite(STDERR, substr($url, 0, 160) . ": http=$code " . substr((string)$body, 0, 300) . "\n");
        return null;
    }
    return (string)$body;
}

/** Hoehen fuer Punkte [[lat, lon], ...] (je 100 ein Abruf) */
function hoehen(array $punkte): array
{
    $out = [];
    foreach (array_chunk($punkte, 100) as $teil) {
        $url = 'https://api.open-meteo.com/v1/elevation?' . http_build_query([
            'latitude' => implode(',', array_map(fn($p) => $p[0], $teil)),
            'longitude' => implode(',', array_map(fn($p) => $p[1], $teil))]);
        $d = json_decode((string)holen($url), true);
        $h = $d['elevation'] ?? array_fill(0, count($teil), null);
        foreach ($h as $x) {
            $out[] = is_numeric($x) ? (float)$x : null;
        }
        usleep(400000);
    }
    return $out;
}

$ergebnis = [];
foreach ($regionen as $r) {
    if ($nur && !in_array($r['id'], $nur, true) || $r['land'] !== 'AT') {
        continue;
    }
    [$la, $lo] = $r['punkt'];
    $dla = $km / 111.0;
    $dlo = $km / (111.0 * cos(deg2rad($la)));
    $gitter = [];
    for ($y = $la - $dla; $y <= $la + $dla + 1e-9; $y += 0.015) {
        for ($x = $lo - $dlo; $x <= $lo + $dlo + 1e-9; $x += 0.021) {
            if (entfernung_km($r['punkt'], [$y, $x]) <= $km) {
                $gitter[] = [round($y, 4), round($x, 4)];
            }
        }
    }
    $h = hoehen($gitter);
    $ziele = ['tour' => $r['hoehe_tour'], 'mitte' => (int)round(($r['hoehe_tal'] + $r['hoehe_tour']) / 2 / 100) * 100];
    $wahl = [];
    foreach ($ziele as $name => $ziel) {
        $best = null;
        foreach ($gitter as $i => $p) {
            if ($h[$i] === null || abs($h[$i] - $ziel) > 100) {
                continue;
            }
            $d = entfernung_km($r['punkt'], $p);
            if ($best === null || $d < $best['km']) {
                $best = ['lat' => $p[0], 'lon' => $p[1], 'hoehe' => (int)round($h[$i]), 'km' => round($d, 1)];
            }
        }
        if ($best) {
            $wahl[$name] = $best + ['ziel' => $ziel];
        }
    }
    $sg = null;
    if ($wahl) {
        $q = http_build_query(['parameters' => 'snow_depth', 'start' => gmdate('Y-m-d', time() - 4 * 86400), 'end' => gmdate('Y-m-d')]);
        foreach ($wahl as $w) {
            $q .= '&lat_lon=' . $w['lat'] . ',' . $w['lon'];
        }
        $body = holen('https://dataset.api.hub.geosphere.at/v1/timeseries/historical/snowgrid_cl-v2-1d-1km?' . $q);
        $d = json_decode((string)$body, true);
        $i = 0;
        foreach ($wahl as $name => $w) {
            $f = $d['features'][$i++] ?? null;
            $wahl[$name]['zelle'] = $f['geometry']['coordinates'] ?? null;
            $wahl[$name]['snow_depth'] = $f['properties']['parameters']['snow_depth']['data'] ?? null;
        }
        $sg = ['timestamps' => $d['timestamps'] ?? null, 'features' => count($d['features'] ?? [])];
        usleep(400000);
    }
    $ergebnis[$r['id']] = ['punkte' => count($gitter), 'hoehe_max' => $h ? max(array_filter($h, 'is_float') ?: [0]) : null, 'wahl' => $wahl, 'snowgrid' => $sg];
    printf("## %s (%s, Tal %d m, Tour %d m): %d Punkte, hoechster %s m\n", $r['id'], $r['land'], $r['hoehe_tal'], $r['hoehe_tour'], count($gitter),
           json_encode($ergebnis[$r['id']]['hoehe_max']));
    foreach ($wahl as $name => $w) {
        printf("   %-5s Ziel %4d m: %.4f,%.4f %4d m (%4.1f km)  Zelle %s  snow_depth %s\n", $name, $w['ziel'], $w['lat'], $w['lon'], $w['hoehe'], $w['km'],
               json_encode($w['zelle']), json_encode($w['snow_depth']));
    }
    echo '   ' . json_encode($sg) . "\n";
}

// Suedtirol: Zeitreihe einer Station
$bz = 'https://geoservices.buergernetz.bz.it/services/meteo/v1/';
foreach ([
    $bz . 'timeseries?' . http_build_query(['station_code' => '54100SF', 'sensor_code' => 'HS', 'output_format' => 'JSON',
        'date_from' => gmdate('YmdHi', time() - 26 * 3600), 'date_to' => gmdate('YmdHi')]),
    $bz . 'timeseries?' . http_build_query(['station_code' => '54100SF', 'sensor_code' => 'HS',
        'date_from' => gmdate('Ymd', time() - 86400), 'date_to' => gmdate('Ymd')]),
    $bz . 'sensors?' . http_build_query(['station_code' => '54100SF']),
    $bz . 'sensors?' . http_build_query(['sensor_code' => 'HS']),
] as $url) {
    $b = holen($url);
    echo "\nSUEDTIROL $url\n  " . strlen((string)$b) . ' Bytes: ' . substr(preg_replace('/\s+/', ' ', (string)$b), 0, 900) . "\n";
}

if ($aus) {
    file_put_contents($aus, json_encode($ergebnis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
