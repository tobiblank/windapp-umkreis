<?php
/**
 * Webcams fuer den Schnee-Modus suchen (seit 12.11.2026).
 *
 *   php tools/webcams_suchen.php daten/schneeregionen.json [webcams.json] [max_km]
 *
 * Holt die Webcam-Liste von foto-webcam.eu (Name, Lage, Hoehe, Link) und die Lage aller
 * Messstationen aus dem Katalog (GeoSphere, MeteoSchweiz, LWD Tirol, Suedtirol) und
 * waehlt je Station die naechste Webcam (Luftlinie, hoechstens max_km, Standard 25).
 * Regionen ohne Messstation (nur SNOWGRID oder gar keine) bekommen die naechste Webcam
 * am Vorhersagepunkt. Ergebnis im Log und als webcams.json im Format des Katalogteils
 * "webcams" (daten/schneeregionen.json); eingetragen wird es von Hand.
 * Laeuft als GitHub Action "Webcams suchen" (von hier aus sind die Dienste nicht erreichbar).
 * Verlinkt wird nur die Seite der Webcam, kein Bild.
 */

declare(strict_types=1);
date_default_timezone_set('UTC');
require __DIR__ . '/../server/web/lib/regionen.php';
require __DIR__ . '/../server/web/lib/bewertung.php';
require __DIR__ . '/../server/web/lib/schnee.php';

const GEO_META = 'https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min/metadata';
const CH_META = 'https://data.geo.admin.ch/ch.meteoschweiz.ogd-smn/ogd-smn_meta_stations.csv';
const TI_GEOJSON = 'https://wiski.tirol.gv.at/lawine/produkte/ogd.geojson';
const BZ_STATIONEN = 'https://geoservices.buergernetz.bz.it/services/meteo/v1/stations?coord_sys=EPSG:4326';
// Kandidaten fuer die Liste von foto-webcam.eu; die erste, die Kameras mit Lage liefert, gilt
const FW_LISTEN = [
    'https://www.foto-webcam.eu/webcam/include/list.php',
    'https://www.foto-webcam.eu/webcam/include/metadata.php',
];

$katalog = $argv[1] ?? null;
$aus = $argv[2] ?? null;
$maxKm = (float)($argv[3] ?? 25);
if (!$katalog) {
    fwrite(STDERR, "Aufruf: php tools/webcams_suchen.php <schneeregionen.json> [webcams.json] [max_km]\n");
    exit(2);
}
$kat = schnee_katalog_laden($katalog);
$regionen = $kat['regionen'];

function holen(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_ENCODING => '',
                            CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'windapp-umkreis/0.1 (Hobbyprojekt, wohinfahrma.de)']);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($body === false || $code < 200 || $code >= 300) {
        fwrite(STDERR, "$url: http=$code " . substr((string)$body, 0, 200) . "\n");
        return null;
    }
    return (string)$body;
}

/** Zahl aus mehreren moeglichen Feldern */
function feld(array $a, array $namen): mixed
{
    foreach ($namen as $n) {
        if (isset($a[$n]) && $a[$n] !== '') {
            return $a[$n];
        }
    }
    return null;
}

// --- Webcams ------------------------------------------------------------------
$cams = [];
foreach (FW_LISTEN as $url) {
    $body = holen($url);
    if ($body === null) {
        continue;
    }
    echo "$url: " . strlen($body) . " Bytes, Anfang: " . substr(preg_replace('/\s+/', ' ', $body), 0, 300) . "\n";
    $d = json_decode($body, true);
    $liste = is_array($d) ? ($d['cams'] ?? $d['webcams'] ?? (array_is_list($d) ? $d : [])) : [];
    if ($liste) {
        echo 'Felder der ersten Kamera: ' . json_encode($liste[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    foreach ($liste as $c) {
        if (!is_array($c)) {
            continue;
        }
        $lat = feld($c, ['latitude', 'lat']);
        $lon = feld($c, ['longitude', 'lon', 'lng']);
        $id = (string)feld($c, ['id', 'name']);
        if (!is_numeric($lat) || !is_numeric($lon) || $id === '' || !empty($c['offline'])) {
            continue;
        }
        $link = (string)(feld($c, ['link', 'url']) ?? "https://www.foto-webcam.eu/webcam/$id/");
        if (!str_starts_with($link, 'https://')) {
            $link = "https://www.foto-webcam.eu/webcam/$id/";
        }
        $hoehe = feld($c, ['elevation', 'altitude', 'height', 'hoehe']);
        $cams["foto-webcam:$id"] = ['quelle' => 'foto-webcam', 'name' => trim((string)(feld($c, ['title', 'name']) ?? $id)),
                                    'url' => $link, 'lat' => (float)$lat, 'lon' => (float)$lon,
                                    'hoehe' => is_numeric($hoehe) ? (int)round((float)$hoehe) : null];
    }
    if ($cams) {
        break;
    }
}
echo 'Webcams mit Lage: ' . count($cams) . "\n";
if (!$cams) {
    fwrite(STDERR, "Keine Webcam-Liste gefunden\n");
    exit(1);
}

// --- Lage der Messstationen -----------------------------------------------------
$lage = [];   // "netz:id" => [lat, lon, hoehe, name]
$geo = json_decode((string)holen(GEO_META), true);
foreach ($geo['stations'] ?? [] as $s) {
    if (isset($s['lat'], $s['lon'])) {
        $lage['geosphere:' . $s['id']] = [(float)$s['lat'], (float)$s['lon'], (int)round((float)($s['altitude'] ?? 0)), (string)$s['name']];
    }
}
$csv = holen(CH_META);
if ($csv !== null) {
    $zeilen = preg_split('/\r?\n/', trim(mb_convert_encoding($csv, 'UTF-8', 'Windows-1252')));
    $kopf = str_getcsv(array_shift($zeilen), ';', '"', '');
    foreach ($zeilen as $z) {
        $c = array_combine($kopf, array_pad(str_getcsv($z, ';', '"', ''), count($kopf), ''));
        $lage['meteoswiss:' . strtolower($c['station_abbr'])] = [(float)$c['station_coordinates_wgs84_lat'], (float)$c['station_coordinates_wgs84_lon'],
                                                                (int)round((float)$c['station_height_masl']), $c['station_name']];
    }
}
foreach (json_decode((string)holen(TI_GEOJSON), true)['features'] ?? [] as $f) {
    $p = $f['properties'] ?? [];
    $c = $f['geometry']['coordinates'] ?? [];
    if (is_string($p['plot'] ?? null) && is_numeric($c[1] ?? null)) {
        $lage['tirol:' . $p['plot']] = [(float)$c[1], (float)$c[0], (int)round((float)($c[2] ?? 0)), (string)($p['name'] ?? $p['plot'])];
    }
}
foreach (json_decode((string)holen(BZ_STATIONEN), true)['features'] ?? [] as $f) {
    $p = $f['properties'] ?? [];
    $c = $f['geometry']['coordinates'] ?? [];
    $lat = $p['LAT'] ?? $c[1] ?? null;
    $lon = $p['LONG'] ?? $c[0] ?? null;
    if (is_numeric($lat) && is_numeric($lon)) {
        $lage['suedtirol:' . $p['SCODE']] = [(float)$lat, (float)$lon, (int)($p['ALT'] ?? 0), (string)($p['NAME_D'] ?? $p['SCODE'])];
    }
}
echo 'Stationen mit Lage: ' . count($lage) . "\n";

/** naechste Webcam zu einem Punkt, null wenn keine im Umkreis */
function naechste(array $cams, array $punkt, float $maxKm): ?array
{
    $best = null;
    foreach ($cams as $id => $c) {
        $d = entfernung_km($punkt, [$c['lat'], $c['lon']]);
        if ($d <= $maxKm && ($best === null || $d < $best['km'])) {
            $best = ['id' => $id, 'km' => $d] + $c;
        }
    }
    return $best;
}

$eintrag = fn(array $c) => ['name' => $c['name'], 'url' => $c['url'], 'km' => round($c['km'], 1)]
                          + ($c['hoehe'] !== null ? ['hoehe' => $c['hoehe']] : []);

// --- je Station und Region ---------------------------------------------------------
$stationen = [];
$regionenAus = [];
$fehlt = [];
foreach ($regionen as $r) {
    $echte = 0;
    printf("\n## %s\n", $r['id']);
    foreach ($r['stationen'] ?? [] as $netz => $ids) {
        if ($netz === 'snowgrid') {
            continue;
        }
        foreach ($ids as $id) {
            $echte++;
            $k = "$netz:$id";
            if (isset($stationen[$k])) {
                continue;
            }
            if (!isset($lage[$k])) {
                $fehlt[] = $k;
                printf("  %-28s keine Lage gefunden\n", $k);
                continue;
            }
            [$lat, $lon, $h, $name] = $lage[$k];
            $c = naechste($cams, [$lat, $lon], $maxKm);
            printf("  %-28s %-28s %5d m -> %s\n", $k, mb_substr($name, 0, 28), $h,
                   $c ? sprintf('%4.1f km %5s m  %s  %s', $c['km'], $c['hoehe'] ?? '?', $c['name'], $c['url']) : 'keine Webcam im Umkreis');
            if ($c) {
                $stationen[$k] = $eintrag($c);
            }
        }
    }
    if ($echte === 0) {
        $c = naechste($cams, $r['punkt'], $maxKm);
        printf("  Region (Vorhersagepunkt %s) -> %s\n", json_encode($r['punkt']),
               $c ? sprintf('%4.1f km %5s m  %s  %s', $c['km'], $c['hoehe'] ?? '?', $c['name'], $c['url']) : 'keine Webcam im Umkreis');
        if ($c) {
            $regionenAus[$r['id']] = $eintrag($c);
        }
    }
}
if ($fehlt) {
    echo "\nOhne Lage: " . implode(', ', $fehlt) . "\n";
}
if ($aus) {
    file_put_contents($aus, json_encode(['stationen' => $stationen, 'regionen' => $regionenAus],
                      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
