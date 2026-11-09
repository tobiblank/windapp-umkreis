<?php
/**
 * Messstationen fuer den Schnee-Modus suchen (seit 9.11.2026), zum Auswaehlen von Hand.
 *
 *   php tools/stationen_suchen.php daten/schneeregionen.json [kandidaten.json] [km]
 *
 * Fragt fuer alle aktiven Stationen von GeoSphere Austria (TAWES) und MeteoSchweiz
 * (SwissMetNet) im Umkreis von km (Standard 35) um einen Vorhersagepunkt die
 * Schneehoehe der letzten 26 Stunden ab und listet je Schnee-Region die Stationen mit
 * Wert: Entfernung, Hoehe, letzte Schneehoehe, Aenderung in 24 h. Stationen ganz ohne
 * Wert haben vermutlich keinen Schneepegel. Laeuft als GitHub Action
 * "Stationen suchen" (von hier aus sind die Dienste nicht erreichbar).
 * Die Auswahl traegt man danach in messnetze.*.stationen und regionen[].stationen ein.
 */

declare(strict_types=1);
date_default_timezone_set('UTC');
require __DIR__ . '/../server/web/lib/regionen.php';
require __DIR__ . '/../server/web/lib/bewertung.php';
require __DIR__ . '/../server/web/lib/schnee.php';

const GEO_META = 'https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min/metadata';
const GEO_DATEN = 'https://dataset.api.hub.geosphere.at/v1/station/historical/tawes-v1-10min';
const CH_META = 'https://data.geo.admin.ch/ch.meteoschweiz.ogd-smn/ogd-smn_meta_stations.csv';
const CH_DATEN = 'https://data.geo.admin.ch/ch.meteoschweiz.ogd-smn/{id}/ogd-smn_{id}_t_now.csv';
const TEIL = 40;

$katalog = $argv[1] ?? null;
$aus = $argv[2] ?? null;
$km = (float)($argv[3] ?? 35);
if (!$katalog) {
    fwrite(STDERR, "Aufruf: php tools/stationen_suchen.php <schneeregionen.json> [kandidaten.json] [km]\n");
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
    if ($body === false || $code < 200 || $code >= 300) {
        fwrite(STDERR, "$url: http=$code " . substr((string)$body, 0, 200) . "\n");
        return null;
    }
    return (string)$body;
}

/** Stationen im Umkreis irgendeiner Region: [id => meta] */
function in_der_naehe(array $stationen, array $regionen, float $km): array
{
    return array_filter($stationen, function ($s) use ($regionen, $km) {
        foreach ($regionen as $r) {
            if (entfernung_km($r['punkt'], [$s['lat'], $s['lon']]) <= $km) {
                return true;
            }
        }
        return false;
    });
}

$werte = [];   // "netz:id" => [netz, id, name, hoehe, lat, lon, hs, hsd24, zeit]

// --- GeoSphere -------------------------------------------------------------
$meta = json_decode((string)holen(GEO_META), true);
$geo = [];
foreach ($meta['stations'] ?? [] as $s) {
    if (!empty($s['is_active']) && isset($s['lat'], $s['lon'])) {
        $geo[(string)$s['id']] = ['name' => (string)$s['name'], 'hoehe' => (int)round((float)$s['altitude']), 'lat' => (float)$s['lat'], 'lon' => (float)$s['lon']];
    }
}
$geoNah = in_der_naehe($geo, $regionen, $km);
echo 'GeoSphere: ' . count($geo) . ' aktive Stationen, ' . count($geoNah) . " in der Naehe einer Region\n";
foreach (array_chunk(array_keys($geoNah), TEIL) as $ids) {
    $url = GEO_DATEN . '?' . http_build_query(['parameters' => 'SCHNEE', 'station_ids' => implode(',', $ids),
           'start' => gmdate('Y-m-d\TH:i', time() - STATION_STUNDEN_ZURUECK * 3600), 'end' => gmdate('Y-m-d\TH:i')]);
    $body = holen($url);
    $st = $body === null ? null : geosphere_lesen($body, $geoNah);
    foreach ($st ?? [] as $id => $e) {
        $m = $geoNah[(string)$id];
        $werte["geosphere:$id"] = ['netz' => 'geosphere', 'id' => (string)$id, 'name' => $m['name'], 'hoehe' => $m['hoehe'],
                                   'lat' => $m['lat'], 'lon' => $m['lon'], 'hs' => $e['hs'], 'hsd24' => $e['hsd24'], 'zeit' => $e['zeit']];
    }
    usleep(500000);
}

// --- MeteoSchweiz ------------------------------------------------------------
$csv = holen(CH_META);
$chMeta = [];
if ($csv !== null) {
    $zeilen = preg_split('/\r?\n/', trim(mb_convert_encoding($csv, 'UTF-8', 'Windows-1252')));
    $kopf = str_getcsv(array_shift($zeilen), ';', '"', '');
    foreach ($zeilen as $z) {
        $c = array_combine($kopf, array_pad(str_getcsv($z, ';', '"', ''), count($kopf), ''));
        $chMeta[strtolower($c['station_abbr'])] = ['name' => $c['station_name'], 'hoehe' => (int)round((float)$c['station_height_masl']),
                                                   'lat' => (float)$c['station_coordinates_wgs84_lat'], 'lon' => (float)$c['station_coordinates_wgs84_lon']];
    }
}
$chNah = in_der_naehe($chMeta, $regionen, $km);
echo 'MeteoSchweiz: ' . count($chMeta) . ' Stationen, ' . count($chNah) . " in der Naehe einer Region\n";
foreach ($chNah as $id => $m) {
    $body = holen(str_replace('{id}', $id, CH_DATEN));
    $e = $body === null ? null : meteoswiss_lesen(mb_convert_encoding($body, 'UTF-8', 'Windows-1252'), $id, $m);
    if (is_array($e) && $e !== ['leer']) {
        $werte["meteoswiss:$id"] = ['netz' => 'meteoswiss', 'id' => $id, 'name' => $m['name'], 'hoehe' => $m['hoehe'],
                                    'lat' => $m['lat'], 'lon' => $m['lon'], 'hs' => $e['hs'], 'hsd24' => $e['hsd24'], 'zeit' => $e['zeit']];
    }
}

// --- Suedtirol (Landeswetterdienst, CC0) ----------------------------------------
const BZ_BASIS = 'https://geoservices.buergernetz.bz.it/services/meteo/v1/';
$bz = json_decode((string)holen(BZ_BASIS . 'stations?coord_sys=EPSG:4326'), true);
$bzNah = [];
foreach ($bz['features'] ?? [] as $f) {
    $p = $f['properties'] ?? [];
    $c = $f['geometry']['coordinates'] ?? [];
    $lat = $p['LAT'] ?? $c[1] ?? null;
    $lon = $p['LONG'] ?? $c[0] ?? null;
    if (!is_numeric($lat) || !is_numeric($lon)) {
        continue;
    }
    $bzNah[(string)$p['SCODE']] = ['name' => (string)($p['NAME_D'] ?? $p['SCODE']), 'hoehe' => (int)($p['ALT'] ?? 0), 'lat' => (float)$lat, 'lon' => (float)$lon];
}
echo 'Suedtirol: ' . count($bzNah) . ' Stationen; Beispiel: ' . json_encode(array_slice($bz['features'] ?? [], 0, 1), JSON_UNESCAPED_UNICODE) . "\n";
$bzNah = in_der_naehe($bzNah, array_filter($regionen, fn($r) => $r['warndienst'] === 'euregio'), $km);
foreach ($bzNah as $id => $m) {
    $s = json_decode((string)holen(BZ_BASIS . 'sensors?' . http_build_query(['station_code' => $id, 'sensor_code' => 'HS'])), true);
    $hs = null;
    $zeit = null;
    foreach (is_array($s) ? $s : [] as $x) {
        if (($x['TYPE'] ?? '') === 'HS' && is_numeric($x['VALUE'] ?? null)) {
            $hs = (float)$x['VALUE'];
            $zeit = (string)($x['DATE'] ?? '');
        }
    }
    if ($hs !== null) {
        $werte["suedtirol:$id"] = ['netz' => 'suedtirol', 'id' => $id, 'name' => $m['name'], 'hoehe' => $m['hoehe'],
                                   'lat' => $m['lat'], 'lon' => $m['lon'], 'hs' => $hs, 'hsd24' => null, 'zeit' => $zeit];
    }
}
echo 'Suedtirol: ' . count($bzNah) . ' in der Naehe, davon mit HS: ' . count(array_filter($werte, fn($w) => $w['netz'] === 'suedtirol')) . "\n";

// --- SNOWGRID (GeoSphere, 1 km, taeglich) -------------------------------------------
$sgMeta = holen('https://dataset.api.hub.geosphere.at/v1/timeseries/historical/snowgrid_cl-v2-1d-1km/metadata');
echo "SNOWGRID Metadaten: " . substr((string)$sgMeta, 0, 1500) . "\n";
$sgPar = [];
foreach (json_decode((string)$sgMeta, true)['parameters'] ?? [] as $par) {
    $sgPar[] = $par['name'];
}
foreach ($regionen as $r) {
    if (($r['land'] ?? '') !== 'AT') {
        continue;
    }
    $url = 'https://dataset.api.hub.geosphere.at/v1/timeseries/historical/snowgrid_cl-v2-1d-1km?' . http_build_query([
        'parameters' => implode(',', $sgPar), 'lat_lon' => $r['punkt'][0] . ',' . $r['punkt'][1],
        'start' => gmdate('Y-m-d', time() - 4 * 86400), 'end' => gmdate('Y-m-d')]);
    $body = holen($url);
    echo "SNOWGRID {$r['id']}: " . substr(preg_replace('/\s+/', ' ', (string)$body), 0, 700) . "\n";
    usleep(300000);
}

// --- je Region ---------------------------------------------------------------
$ergebnis = [];
foreach ($regionen as $r) {
    $liste = [];
    foreach ($werte as $w) {
        $d = entfernung_km($r['punkt'], [$w['lat'], $w['lon']]);
        if ($d <= $km) {
            $liste[] = $w + ['km' => round($d, 1)];
        }
    }
    usort($liste, fn($a, $b) => $a['km'] <=> $b['km']);
    $ergebnis[$r['id']] = ['tour' => $r['hoehe_tour'], 'tal' => $r['hoehe_tal'], 'jetzt' => $r['stationen'] ?? [], 'kandidaten' => $liste];
    printf("\n## %s (Tal %d m, Tour %d m) jetzt: %s\n", $r['id'], $r['hoehe_tal'], $r['hoehe_tour'], json_encode($r['stationen'] ?? []));
    foreach ($liste as $w) {
        printf("  %5.1f km %5d m  %-11s %-10s %-32s HS %5.1f  24h %s  %s\n", $w['km'], $w['hoehe'], $w['netz'], $w['id'], mb_substr($w['name'], 0, 32),
               $w['hs'], $w['hsd24'] === null ? '   -' : sprintf('%+5.1f', $w['hsd24']), $w['zeit']);
    }
}
if ($aus) {
    file_put_contents($aus, json_encode($ergebnis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
