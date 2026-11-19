<?php
/**
 * Regel-Windlagen gegen Messstationen pruefen (seit 19.11.2026, Wunsch Tobias:
 * Lago di Santa Croce und Kochelsee standen fast immer gut, am Kochelsee war nichts).
 *
 *   php tools/thermik_pruefen.php daten/regionen.json <ausgabeordner> [von YYYY-MM-DD] [bis] [region,region]
 *
 * Fuer jede Region mit Regel-Windlage (druck/grundstroemung):
 *  1. Windstationen im Umkreis suchen: GeoSphere Austria (TAWES), MeteoSchweiz
 *     (SwissMetNet), Landeswetterdienst Suedtirol, DWD (10-Minuten-Wind). Fuer die
 *     italienischen Seen ausserhalb Suedtirols ist keine offene Quelle bekannt.
 *  2. Rueckblick: die Modelle von damals aus der Historical Forecast API von
 *     Open-Meteo (dieselben Modelle wie der Sammler, aneinandergereihte kurze
 *     Vorhersagen, also eher besser als eine Vorhersage fuer uebermorgen), damit
 *     region_bewerten() aus der App, ohne Ensemble: "Regel sagt: geht".
 *  3. Gemessen: Stundenmittel der Station, Tag geht, wenn mindestens MIN_STUNDEN
 *     Stunden am Stueck im Tagesfenster mindestens so viel Wind wie die Sportart
 *     verlangt aus einem Sektor der Windlagen kommt.
 *  4. Bericht pruefung.md und Tageswerte pruefung.json im Ausgabeordner.
 *
 * Laeuft als GitHub Action "Thermik pruefen" (von hier sind die Dienste nicht
 * erreichbar). Aendert nichts an der App.
 */

declare(strict_types=1);
date_default_timezone_set('UTC');
ini_set('memory_limit', '2G');
require __DIR__ . '/../server/web/lib/regionen.php';
require __DIR__ . '/../server/web/lib/bewertung.php';

const TP_MODELLE = 'icon_d2,icon_eu,ecmwf_ifs,gfs_seamless,meteofrance_arome_france_hd';   // wie der Sammler
const TP_MODELLE_ERSATZ = 'icon_seamless,ecmwf_ifs025,gfs_seamless';
const TP_HIST = 'https://historical-forecast-api.open-meteo.com/v1/forecast';
const TP_SUCH_KM = 25;          // Kandidaten im Umkreis
const TP_WAHL_KM = 12;          // automatisch gewaehlt nur bis hier
const TP_WAHL_DH = 250;         // und hoechstens so viele Meter ueber/unter dem See
const TP_SPORT = 'kite_twintip';
const TP_SCHWACH_KN = 10;       // zweite, weichere Schwelle (Stationen an Land messen oft weniger)
const TP_PAUSE_S = 25;          // zwischen Open-Meteo-Abrufen (Minutenlimit, grosse Abrufe zaehlen mehrfach)
const TP_VORWISSEN = 0.4;       // Trefferquote ohne Messung (REGEL_UNGEPRUEFT in bewertung.php)
const TP_VORWISSEN_TAGE = 10;   // so viele Tage zaehlt das Vorwissen (kleine Stichproben ruecken zur Mitte)
const TP_MIN_TAGE = 45;         // Station zaehlt ab so vielen Tagen mit Messwerten (manche sind neu)
const TP_ENS = 'https://ensemble-api.open-meteo.com/v1/ensemble';
const TP_ENS_TAGE = 60;         // Ensemble wie in der App: die letzten Tage (past_days)

/** Feste Wahl, falls die automatische danebenliegt: region => "netz:id" */
const TP_FEST = [];

const GEO_META = 'https://dataset.api.hub.geosphere.at/v1/station/historical/tawes-v1-10min/metadata';
const GEO_DATEN = 'https://dataset.api.hub.geosphere.at/v1/station/historical/tawes-v1-10min';
const CH_BASIS = 'https://data.geo.admin.ch/ch.meteoschweiz.ogd-smn/';
const BZ_BASIS = 'https://geoservices.buergernetz.bz.it/services/meteo/v1/';
const DWD_BASIS = 'https://opendata.dwd.de/climate_environment/CDC/observations_germany/climate/10_minutes/wind/recent/';

// ===================================================================
// Reine Funktionen (ohne Netz, im Selbsttest geprueft)
// ===================================================================

/** Regionen mit mindestens einer Regel-Windlage */
function tp_regel_regionen(array $regionen): array
{
    return array_values(array_filter($regionen, function ($r) {
        $ws = $r['windlagen'] ?? [];
        foreach ($r['spots'] ?? [] as $sp) {
            $ws = array_merge($ws, $sp['windlagen'] ?? []);
        }
        foreach ($ws as $w) {
            if (ist_regel($w)) {
                return true;
            }
        }
        return false;
    }));
}

/** Alle Sektoren der Windlagen einer Region (Region und Spots) */
function tp_sektoren(array $r): array
{
    $ws = $r['windlagen'] ?? [];
    foreach ($r['spots'] ?? [] as $sp) {
        $ws = array_merge($ws, $sp['windlagen'] ?? []);
    }
    return array_values(array_map(fn($w) => $w['sektor'], array_filter($ws, fn($w) => isset($w['sektor']))));
}

/**
 * 10-Minuten-Werte (UTC-Zeitstempel => [kn, grad]) zu Stundenmitteln in Ortszeit.
 * Stunde h = Messungen von h:00 bis h:59 (Zeitstempel am Ende des Intervalls: h:10 bis h+1:00).
 * Rueckgabe [tag => [stunde => ['kn', 'richtung', 'n']]], nur Stunden mit mindestens 3 Werten.
 */
function tp_stunden(array $reihe, DateTimeZone $tz): array
{
    $roh = [];
    foreach ($reihe as $ts => [$kn, $grad]) {
        if ($kn === null) {
            continue;
        }
        $lok = (new DateTimeImmutable('@' . ($ts - 60)))->setTimezone($tz);
        $roh[$lok->format('Y-m-d')][(int)$lok->format('G')][] = [$kn, $grad];
    }
    $out = [];
    foreach ($roh as $tag => $proStunde) {
        foreach ($proStunde as $h => $werte) {
            if (count($werte) < 3) {
                continue;
            }
            $kn = array_sum(array_column($werte, 0)) / count($werte);
            $ri = richtung_mittel(array_values(array_filter(array_column($werte, 1), fn($g) => $g !== null)));
            $out[$tag][$h] = ['kn' => $kn, 'richtung' => $ri, 'n' => count($werte)];
        }
    }
    return $out;
}

/**
 * Gemessener Tag: bestes Stueck von mindestens MIN_STUNDEN Stunden im Fenster aus
 * einem der Sektoren. Rueckgabe ['spitze' => kleinster Stundenwert im besten
 * 2-Stunden-Block (kn), 'richtung', 'stunden_ab' => [schwelle => laengstes Stueck],
 * 'abdeckung' => Anteil Stunden mit Wert] oder null ohne Werte.
 */
function tp_tag_gemessen(array $stunden, array $fenster, array $sektoren, array $schwellen): ?array
{
    [$ab, $bis] = $fenster;
    $mit = 0;
    $spitze = null;
    $richtung = null;
    $laengste = array_fill_keys($schwellen, 0);
    $lauf = array_fill_keys($schwellen, 0);
    for ($h = $ab; $h < $bis; $h++) {
        $w = $stunden[$h] ?? null;
        if ($w !== null) {
            $mit++;
        }
        $passt = $w !== null && $w['richtung'] !== null && tp_in_sektoren($w['richtung'], $sektoren);
        foreach ($schwellen as $s) {
            $lauf[$s] = $passt && $w['kn'] >= $s ? $lauf[$s] + 1 : 0;
            $laengste[$s] = max($laengste[$s], $lauf[$s]);
        }
        $v = $stunden[$h - 1] ?? null;
        if ($h > $ab && $passt && $v !== null && $v['richtung'] !== null && tp_in_sektoren($v['richtung'], $sektoren)) {
            $block = min($w['kn'], $v['kn']);
            if ($spitze === null || $block > $spitze) {
                $spitze = $block;
                $richtung = $w['richtung'];
            }
        }
    }
    if ($mit === 0) {
        return null;
    }
    return ['spitze' => $spitze === null ? 0.0 : round($spitze, 1), 'richtung' => $richtung === null ? null : round($richtung),
            'stunden_ab' => $laengste, 'abdeckung' => round($mit / max(1, $bis - $ab), 2)];
}

function tp_in_sektoren(float $g, array $sektoren): bool
{
    foreach ($sektoren as $s) {
        if (winkel_in_sektor($g, $s)) {
            return true;
        }
    }
    return false;
}

/** Kandidat bewerten: kleiner = besser (km plus Hoehenunterschied) */
function tp_kandidat_wert(array $k): float
{
    return $k['km'] + abs($k['dh'] ?? 999) / 50;
}

/**
 * Vierfeldertafel und Monate aus den Tageswerten.
 * $tage: [tag => ['regel' => bool|null, 'gemessen' => tp_tag_gemessen|null, 'sperre' => bool]]
 */
function tp_auswerten(array $tage, int $schwelle, int $schwach): array
{
    $z = ['tage' => 0, 'regel_ja' => 0, 'regel_ja_gem' => 0, 'regel_ja_schwach' => 0, 'gem_ja' => 0, 'gem_ja_regel' => 0, 'monate' => []];
    foreach ($tage as $tag => $t) {
        if ($t['regel'] === null || $t['gemessen'] === null || $t['gemessen']['abdeckung'] < 0.6 || !empty($t['sperre'])) {
            continue;
        }
        $m = substr($tag, 0, 7);
        $z['monate'][$m] ??= ['tage' => 0, 'regel_ja' => 0, 'treffer' => 0, 'gem_ja' => 0];
        $gem = $t['gemessen']['stunden_ab'][$schwelle] >= MIN_STUNDEN;
        $gemSchwach = $t['gemessen']['stunden_ab'][$schwach] >= MIN_STUNDEN;
        $z['tage']++;
        $z['monate'][$m]['tage']++;
        if ($t['regel']) {
            $z['regel_ja']++;
            $z['monate'][$m]['regel_ja']++;
            $z['regel_ja_gem'] += (int)$gem;
            $z['regel_ja_schwach'] += (int)$gemSchwach;
            $z['monate'][$m]['treffer'] += (int)$gem;
        }
        if ($gem) {
            $z['gem_ja']++;
            $z['monate'][$m]['gem_ja']++;
            $z['gem_ja_regel'] += (int)$t['regel'];
        }
    }
    ksort($z['monate']);
    return $z;
}

/** Prozent oder Strich */
function tp_pct(int $a, int $b): string
{
    return $b > 0 ? round(100 * $a / $b) . ' %' : '–';
}

// ===================================================================
// Selbsttest (php tools/thermik_pruefen.php --selbsttest), ohne Netz
// ===================================================================

function tp_selbsttest(): int
{
    $f = 0;
    $tz = new DateTimeZone('Europe/Berlin');
    // 10-Minuten-Werte 12:10 bis 15:00 Ortszeit (11:10-14:00 UTC im Winter), 16 kn aus Sued
    $reihe = [];
    $t0 = (new DateTimeImmutable('2026-01-10 11:10', new DateTimeZone('UTC')))->getTimestamp();
    for ($i = 0; $i < 18; $i++) {
        $reihe[$t0 + 600 * $i] = [16.0, 180.0];
    }
    $st = tp_stunden($reihe, $tz);
    $f += (int)!(isset($st['2026-01-10'][12], $st['2026-01-10'][14]) && !isset($st['2026-01-10'][15]) && abs($st['2026-01-10'][13]['kn'] - 16) < 0.01);
    $g = tp_tag_gemessen($st['2026-01-10'], [9, 17], [[150, 230]], [10, 14]);
    $f += (int)!($g['stunden_ab'][14] === 3 && $g['spitze'] === 16.0 && $g['abdeckung'] === 0.38);
    $g2 = tp_tag_gemessen($st['2026-01-10'], [9, 17], [[330, 30]], [10, 14]);
    $f += (int)!($g2['stunden_ab'][14] === 0 && $g2['spitze'] === 0.0);
    $z = tp_auswerten([
        '2026-01-10' => ['regel' => true, 'gemessen' => ['stunden_ab' => [14 => 3, 10 => 3], 'abdeckung' => 1.0]],
        '2026-01-11' => ['regel' => true, 'gemessen' => ['stunden_ab' => [14 => 0, 10 => 2], 'abdeckung' => 1.0]],
        '2026-01-12' => ['regel' => false, 'gemessen' => ['stunden_ab' => [14 => 2, 10 => 2], 'abdeckung' => 1.0]],
        '2026-01-13' => ['regel' => true, 'gemessen' => ['stunden_ab' => [14 => 5, 10 => 5], 'abdeckung' => 0.3]],
    ], 14, 10);
    $f += (int)!($z['tage'] === 3 && $z['regel_ja'] === 2 && $z['regel_ja_gem'] === 1 && $z['regel_ja_schwach'] === 2 && $z['gem_ja'] === 2 && $z['gem_ja_regel'] === 1);
    $f += (int)!(dwd_zeile('  3379 20230101 20251118            528     47.5935   11.3427 Kochel-Testname                            Bayern                                   Frei') === ['3379', 528, 47.5935, 11.3427, 'Kochel-Testname']);
    echo $f === 0 ? "Selbsttest ok\n" : "Selbsttest: $f Fehler\n";
    return $f;
}

// ===================================================================
// Netz
// ===================================================================

function holen(string $url, int $timeout = 120): ?string
{
    for ($v = 0; $v < 3; $v++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_ENCODING => '',
                                CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'windapp-umkreis/0.1 (Hobbyprojekt, Thermik-Pruefung)']);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body !== false && $code >= 200 && $code < 300) {
            return (string)$body;
        }
        fwrite(STDERR, "  $url: http=$code " . substr((string)$body, 0, 200) . "\n");
        if ($code === 429) {
            sleep(65);
            continue;
        }
        if ($code >= 400 && $code < 500) {
            return null;
        }
        sleep(10);
    }
    return null;
}

/** Open-Meteo Historical Forecast; bei HTTP 400 (Modell im Archiv unbekannt) mit Ersatzmodellen */
function hist_holen(array $punkt, string $vars, string $von, string $bis, string $einheit = 'kn'): array
{
    static $erster = true;
    foreach ([TP_MODELLE, TP_MODELLE_ERSATZ] as $modelle) {
        if (!$erster) {
            sleep(TP_PAUSE_S);
        }
        $erster = false;
        $url = TP_HIST . '?' . http_build_query(['latitude' => $punkt[0], 'longitude' => $punkt[1], 'hourly' => $vars, 'models' => $modelle,
               'wind_speed_unit' => $einheit, 'start_date' => $von, 'end_date' => $bis, 'timezone' => 'UTC']);
        $body = holen($url, 300);
        $d = $body === null ? null : json_decode($body, true);
        if (is_array($d) && isset($d['hourly']['time'])) {
            return [$d, $modelle];
        }
    }
    return [null, null];
}

/** ECMWF-Ensemble der letzten TP_ENS_TAGE Tage (aneinandergereihte Laeufe), wie der Sammler */
function ens_holen(array $punkt, string $vars): ?array
{
    sleep(TP_PAUSE_S);
    $url = TP_ENS . '?' . http_build_query(['latitude' => $punkt[0], 'longitude' => $punkt[1], 'hourly' => $vars, 'models' => 'ecmwf_ifs025',
           'wind_speed_unit' => 'kn', 'past_days' => TP_ENS_TAGE, 'forecast_days' => 1, 'timezone' => 'UTC']);
    $d = json_decode((string)holen($url, 300), true);
    return is_array($d) && isset($d['hourly']['time']) ? $d : null;
}

/** DWD-Stationsliste, eine Zeile: [id, hoehe, lat, lon, name] oder null */
function dwd_zeile(string $z): ?array
{
    if (!preg_match('/^\s*(\d+)\s+(\d{8})\s+(\d{8})\s+(-?\d+)\s+([\d.]+)\s+([\d.]+)\s+(.+?)\s{2,}/', $z, $m)) {
        return null;
    }
    return [$m[1], (int)$m[4], (float)$m[5], (float)$m[6], trim($m[7])];
}

/** Alle Windstationen der offenen Netze: ["netz:id" => [netz, id, name, hoehe, lat, lon, bis?]] */
function stationen_alle(): array
{
    $alle = [];
    // GeoSphere
    $meta = json_decode((string)holen(GEO_META), true);
    foreach ($meta['stations'] ?? [] as $s) {
        if (isset($s['lat'], $s['lon']) && !empty($s['is_active'])) {
            $alle['geosphere:' . $s['id']] = ['netz' => 'geosphere', 'id' => (string)$s['id'], 'name' => (string)$s['name'],
                                               'hoehe' => (int)round((float)($s['altitude'] ?? 0)), 'lat' => (float)$s['lat'], 'lon' => (float)$s['lon']];
        }
    }
    $par = array_column($meta['parameters'] ?? [], null, 'name');
    foreach (['FF', 'FFAM', 'DD'] as $p) {
        echo "  GeoSphere Parameter $p: " . json_encode($par[$p] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
    }
    // MeteoSchweiz
    $csv = holen(CH_BASIS . 'ogd-smn_meta_stations.csv');
    if ($csv !== null) {
        $zeilen = preg_split('/\r?\n/', trim(mb_convert_encoding($csv, 'UTF-8', 'Windows-1252')));
        $kopf = str_getcsv(array_shift($zeilen), ';', '"', '');
        foreach ($zeilen as $z) {
            $c = array_combine($kopf, array_pad(str_getcsv($z, ';', '"', ''), count($kopf), ''));
            $id = strtolower($c['station_abbr']);
            $alle["meteoswiss:$id"] = ['netz' => 'meteoswiss', 'id' => $id, 'name' => $c['station_name'], 'hoehe' => (int)round((float)$c['station_height_masl']),
                                       'lat' => (float)$c['station_coordinates_wgs84_lat'], 'lon' => (float)$c['station_coordinates_wgs84_lon']];
        }
    }
    $pcsv = holen(CH_BASIS . 'ogd-smn_meta_parameters.csv');
    foreach (preg_split('/\r?\n/', (string)mb_convert_encoding((string)$pcsv, 'UTF-8', 'Windows-1252')) as $z) {
        if (preg_match('/^(fkl010z0|dkl010z0|fu3010z0);/', $z)) {
            echo "  MeteoSchweiz Parameter: $z\n";
        }
    }
    // Suedtirol
    $bz = json_decode((string)holen(BZ_BASIS . 'stations?coord_sys=EPSG:4326'), true);
    foreach ($bz['features'] ?? [] as $f) {
        $p = $f['properties'] ?? [];
        $c = $f['geometry']['coordinates'] ?? [];
        $lat = $p['LAT'] ?? $c[1] ?? null;
        $lon = $p['LONG'] ?? $c[0] ?? null;
        if (is_numeric($lat) && is_numeric($lon)) {
            $alle['suedtirol:' . $p['SCODE']] = ['netz' => 'suedtirol', 'id' => (string)$p['SCODE'], 'name' => (string)($p['NAME_D'] ?? $p['SCODE']),
                                                  'hoehe' => (int)($p['ALT'] ?? 0), 'lat' => (float)$lat, 'lon' => (float)$lon];
        }
    }
    // DWD
    $txt = holen(DWD_BASIS . 'zehn_min_ff_Beschreibung_Stationen.txt');
    foreach (preg_split('/\r?\n/', mb_convert_encoding((string)$txt, 'UTF-8', 'ISO-8859-1')) as $z) {
        $d = dwd_zeile($z);
        if ($d !== null) {
            [$id, $h, $lat, $lon, $name] = $d;
            $alle["dwd:$id"] = ['netz' => 'dwd', 'id' => $id, 'name' => $name, 'hoehe' => $h, 'lat' => $lat, 'lon' => $lon];
        }
    }
    $n = array_count_values(array_column($alle, 'netz'));
    echo '  Stationen: ' . json_encode($n) . "\n";
    return $alle;
}

/** Messreihe einer Station: [UTC-Zeitstempel => [kn, grad]] */
function messreihe(array $st, string $von, string $bis): array
{
    $ms = 1.943844;   // m/s -> kn
    $reihe = [];
    $a = new DateTimeImmutable("$von 00:00", new DateTimeZone('UTC'));
    $e = (new DateTimeImmutable("$bis 23:59", new DateTimeZone('UTC')));
    if ($st['netz'] === 'geosphere') {
        for ($t = $a; $t < $e; $t = $t->modify('+2 months')) {
            $t2 = min($e, $t->modify('+2 months'));
            $body = holen(GEO_DATEN . '?' . http_build_query(['parameters' => 'FF,DD', 'station_ids' => $st['id'],
                          'start' => $t->format('Y-m-d\TH:i'), 'end' => $t2->format('Y-m-d\TH:i')]));
            $d = json_decode((string)$body, true);
            $zeiten = $d['timestamps'] ?? [];
            $p = $d['features'][0]['properties']['parameters'] ?? [];
            foreach ($zeiten as $i => $z) {
                $ff = $p['FF']['data'][$i] ?? null;
                $dd = $p['DD']['data'][$i] ?? null;
                $reihe[strtotime($z)] = [$ff === null ? null : $ff * $ms, $dd === null ? null : (float)$dd];
            }
            usleep(300000);
        }
    } elseif ($st['netz'] === 'meteoswiss') {
        foreach (['historical_2020-2029', 'recent'] as $teil) {
            $body = holen(CH_BASIS . "{$st['id']}/ogd-smn_{$st['id']}_t_$teil.csv", 300);
            if ($body === null) {
                continue;
            }
            $zeilen = preg_split('/\r?\n/', trim($body));
            $kopf = str_getcsv(array_shift($zeilen) ?? '', ';', '"', '');
            [$iz, $iff, $idd] = [array_search('reference_timestamp', $kopf, true), array_search('fkl010z0', $kopf, true), array_search('dkl010z0', $kopf, true)];
            if ($iz === false || $iff === false) {
                echo "  MeteoSchweiz {$st['id']} $teil: Spalten fehlen\n";
                continue;
            }
            foreach ($zeilen as $z) {
                $c = str_getcsv($z, ';', '"', '');
                $d = DateTimeImmutable::createFromFormat('d.m.Y H:i', (string)($c[$iz] ?? ''), new DateTimeZone('UTC'));
                if (!$d || $d < $a || $d > $e) {
                    continue;
                }
                $ff = trim((string)($c[$iff] ?? ''));
                $dd = $idd === false ? '' : trim((string)($c[$idd] ?? ''));
                $reihe[$d->getTimestamp()] = [$ff === '' ? null : (float)$ff * $ms, $dd === '' ? null : (float)$dd];
            }
        }
    } elseif ($st['netz'] === 'suedtirol') {
        $tz = new DateTimeZone('Europe/Rome');
        for ($t = $a; $t < $e; $t = $t->modify('+1 month')) {
            $t2 = min($e, $t->modify('+1 month'));
            $proSensor = [];
            foreach (['WG', 'WR'] as $sensor) {
                $body = holen(BZ_BASIS . 'timeseries?' . http_build_query(['station_code' => $st['id'], 'sensor_code' => $sensor,
                              'date_from' => $t->setTimezone($tz)->format('YmdHi'), 'date_to' => $t2->setTimezone($tz)->format('YmdHi')]));
                foreach (json_decode((string)$body, true) ?: [] as $x) {
                    $ts = is_string($x['DATE'] ?? null) ? strtotime($x['DATE']) : false;
                    if ($ts !== false && is_numeric($x['VALUE'] ?? null)) {
                        $proSensor[$sensor][$ts] = (float)$x['VALUE'];
                    }
                }
                usleep(200000);
            }
            foreach ($proSensor['WG'] ?? [] as $ts => $v) {
                $reihe[$ts] = [$v * $ms, $proSensor['WR'][$ts] ?? null];
            }
        }
    } elseif ($st['netz'] === 'dwd') {
        $id = str_pad($st['id'], 5, '0', STR_PAD_LEFT);
        $zip = holen(DWD_BASIS . "10minutenwerte_wind_{$id}_akt.zip", 300);
        if ($zip !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'dwd');
            file_put_contents($tmp, $zip);
            $za = new ZipArchive();
            if ($za->open($tmp) === true) {
                for ($i = 0; $i < $za->numFiles; $i++) {
                    if (!str_starts_with($za->getNameIndex($i), 'produkt_')) {
                        continue;
                    }
                    foreach (preg_split('/\r?\n/', (string)$za->getFromIndex($i)) as $z) {
                        $c = array_map('trim', explode(';', $z));
                        if (count($c) < 5 || !ctype_digit($c[1])) {
                            continue;
                        }
                        $d = DateTimeImmutable::createFromFormat('YmdHi', $c[1], new DateTimeZone('UTC'));
                        if (!$d || $d < $a || $d > $e) {
                            continue;
                        }
                        $ff = (float)$c[3];
                        $dd = (float)$c[4];
                        $reihe[$d->getTimestamp()] = [$ff <= -999 ? null : $ff * $ms, $dd <= -999 ? null : $dd];
                    }
                }
                $za->close();
            }
            unlink($tmp);
        }
    }
    ksort($reihe);
    return $reihe;
}

// ===================================================================
// Ablauf
// ===================================================================

if (($argv[1] ?? '') === '--selbsttest') {
    exit(tp_selbsttest() === 0 ? 0 : 1);
}
$katalogDatei = $argv[1] ?? null;
$ausDir = $argv[2] ?? null;
if (!$katalogDatei || !$ausDir) {
    fwrite(STDERR, "Aufruf: php tools/thermik_pruefen.php daten/regionen.json <ausgabeordner> [von] [bis] [region,region]\n");
    exit(2);
}
if (tp_selbsttest() !== 0) {
    exit(1);
}
$von = ($argv[3] ?? '') ?: '2025-03-01';
$bis = ($argv[4] ?? '') ?: gmdate('Y-m-d', time() - 86400);
$nur = array_filter(explode(',', (string)($argv[5] ?? '')));
$katalog = katalog_laden($katalogDatei);
$regionen = tp_regel_regionen($katalog['regionen']);
if ($nur) {
    $regionen = array_values(array_filter($regionen, fn($r) => in_array($r['id'], $nur, true)));
}
$sport = SPORTARTEN[TP_SPORT];
$schwellen = [TP_SCHWACH_KN, (int)$sport['min']];
@mkdir($ausDir, 0777, true);
echo "Zeitraum $von bis $bis, Regionen: " . implode(', ', array_column($regionen, 'id')) . "\n";

echo "Stationen holen\n";
$stationen = stationen_alle();

// Druckpunkte einmal fuer alle Regionen
$druckBedarf = [];
$hoehenBedarf = [];
foreach ($regionen as $r) {
    [$achsen, $hoehen] = region_bedarf($r);
    foreach ($achsen as [$p, $m]) {
        $druckBedarf[$p] = $druckBedarf[$m] = true;
    }
    if (array_diff($hoehen, ['10m'])) {
        $hoehenBedarf[$r['id']] = true;
    }
}
$druck = [];
foreach (array_keys($druckBedarf) as $p) {
    echo "Druck $p\n";
    [$druck[$p], $mod] = hist_holen($katalog['druckpunkte'][$p]['punkt'], 'pressure_msl', $von, $bis);
    echo '  Modelle: ' . ($mod ?? 'keine Daten') . "\n";
}

$druckEns = [];
foreach (array_keys($druckBedarf) as $p) {
    echo "Druck-Ensemble $p\n";
    $druckEns[$p] = ens_holen($katalog['druckpunkte'][$p]['punkt'], 'pressure_msl');
    echo '  ' . ($druckEns[$p] ? 'ok' : 'keine Daten') . "\n";
}
$ensVon = gmdate('Y-m-d', time() - TP_ENS_TAGE * 86400);

$bericht = [];
$json = [];
foreach ($regionen as $r) {
    $id = $r['id'];
    echo "\n## $id\n";
    $tz = new DateTimeZone($r['zeitzone']);
    [$vh, $mod] = hist_holen($r['punkt'], 'wind_speed_10m,wind_direction_10m,wind_gusts_10m,temperature_2m,weather_code,precipitation,cloud_cover', $von, $bis);
    $seeHoehe = isset($vh['elevation']) ? (int)round((float)$vh['elevation']) : null;
    echo '  Vorhersage: ' . ($mod ?? 'keine Daten') . ", Hoehe am Punkt $seeHoehe m\n";
    $hoehe = null;
    if (isset($hoehenBedarf[$id])) {
        [$hoehe] = hist_holen($r['punkt'], 'wind_speed_850hPa,wind_direction_850hPa,wind_speed_700hPa,wind_direction_700hPa', $von, $bis);
    }

    // Kandidaten
    $kand = [];
    foreach ($stationen as $key => $s) {
        $km = entfernung_km($r['punkt'], [$s['lat'], $s['lon']]);
        if ($km <= TP_SUCH_KM) {
            $kand[$key] = $s + ['km' => round($km, 1), 'dh' => $seeHoehe === null ? null : $s['hoehe'] - $seeHoehe];
        }
    }
    uasort($kand, fn($a, $b) => tp_kandidat_wert($a) <=> tp_kandidat_wert($b));
    foreach ($kand as $key => $k) {
        printf("  %5.1f km %5d m (%+d)  %s  %s\n", $k['km'], $k['hoehe'], $k['dh'] ?? 0, $key, $k['name']);
    }
    $wahl = [];
    if (isset(TP_FEST[$id], $kand[TP_FEST[$id]])) {
        $wahl[] = TP_FEST[$id];
    }
    foreach ($kand as $key => $k) {
        if (count($wahl) < 3 && $k['km'] <= TP_WAHL_KM && ($k['dh'] === null || abs($k['dh']) <= TP_WAHL_DH) && !in_array($key, $wahl, true)) {
            $wahl[] = $key;
        }
    }

    // Regel aus den Modellen von damals
    $regel = [];
    if ($vh !== null) {
        $daten = ['vorhersage' => $vh, 'ensemble' => null, 'druck' => $druck, 'hoehe' => $hoehe];
        $tage = [];
        for ($t = new DateTimeImmutable($von); $t->format('Y-m-d') <= $bis; $t = $t->modify('+1 day')) {
            $tage[] = $t->format('Y-m-d');
        }
        $jetzt = new DateTimeImmutable("$von 00:00 -2 days", $tz);
        foreach (region_bewerten($r, $daten, $sport, $tage, $jetzt) as $tag => $e) {
            $det = $e['det'];
            $sperre = !$det['geht'] && region_sperre($r, $sport, (int)substr($tag, 5, 2)) !== null;
            $regel[$tag] = ['geht' => $det['geht'] && !empty($det['regel']), 'modellwind' => $det['geht'] && empty($det['regel']),
                            'windlage' => $det['windlage'] ?? null, 'von' => $det['von'] ?? null, 'bis' => $det['bis'] ?? null,
                            'grund' => $det['grund'] ?? null, 'fenster' => $e['fenster'], 'sperre' => $sperre];
        }
    }

    // Ensemble-Chance wie in der App (letzte TP_ENS_TAGE Tage)
    $chance = [];
    $ens = ens_holen($r['punkt'], 'wind_speed_10m,wind_direction_10m,cloud_cover');
    $hoeheEns = isset($hoehenBedarf[$id]) ? ens_holen($r['punkt'], 'wind_speed_850hPa,wind_direction_850hPa,wind_speed_700hPa,wind_direction_700hPa') : null;
    if ($ens !== null && $vh !== null) {
        $tageE = array_values(array_filter(array_keys($regel), fn($t) => $t >= $ensVon));
        $daten = ['vorhersage' => $vh, 'ensemble' => $ens, 'druck' => $druck, 'druck_ensemble' => $druckEns, 'hoehe' => $hoehe, 'hoehe_ensemble' => $hoeheEns];
        foreach (region_bewerten($r, $daten, $sport, $tageE, new DateTimeImmutable("$ensVon 00:00 -2 days", $tz)) as $tag => $e) {
            if ($e['ens'] !== null) {
                $chance[$tag] = round($e['ens']['chance'], 2);
                $regel[$tag]['chance'] = $chance[$tag];
            }
        }
    }
    echo '  Ensemble: ' . count($chance) . ' Tage, im Mittel ' . ($chance ? round(100 * array_sum($chance) / count($chance)) : '-') . " %\n";

    // Messung: erste gewaehlte Station mit genug Werten
    $station = null;
    $gemessen = [];
    foreach ($wahl as $key) {
        $s = $kand[$key];
        echo "  Messreihe $key ({$s['name']})\n";
        $reihe = messreihe($s, $von, $bis);
        $st = tp_stunden($reihe, $tz);
        $sek = tp_sektoren($r);
        $g = [];
        foreach ($regel as $tag => $x) {
            if (isset($st[$tag])) {
                $g[$tag] = tp_tag_gemessen($st[$tag], $x['fenster'], $sek, $schwellen);
            }
        }
        $g = array_filter($g);
        $mitWert = array_keys(array_filter($reihe, fn($x) => $x[0] !== null));
        echo '    Werte von ' . ($mitWert ? gmdate('Y-m-d', min($mitWert)) . ' bis ' . gmdate('Y-m-d', max($mitWert)) : '-') . "\n";
        $knWerte = array_filter(array_column($reihe, 0), fn($v) => $v !== null);
        sort($knWerte);
        echo '    ' . count($reihe) . ' Werte, ' . count($g) . ' Tage, Median ' . ($knWerte ? round($knWerte[intdiv(count($knWerte), 2)], 1) : '-')
           . ' kn, 99 %: ' . ($knWerte ? round($knWerte[(int)(0.99 * (count($knWerte) - 1))], 1) : '-') . " kn\n";
        if (count($g) >= TP_MIN_TAGE) {
            $station = $s + ['key' => $key];
            $gemessen = $g;
            break;
        }
    }

    $tageZ = [];
    foreach ($regel as $tag => $x) {
        $tageZ[$tag] = ['regel' => $x['geht'] || $x['modellwind'], 'gemessen' => $gemessen[$tag] ?? null, 'sperre' => $x['sperre']];
    }
    $z = tp_auswerten($tageZ, (int)$sport['min'], TP_SCHWACH_KN);
    // Ensemble gegen Messung
    $ez = ['tage' => count($chance), 'summe' => array_sum($chance), 'ab50' => 0, 'ab50_gem' => 0, 'ab50_mit' => 0, 'gem_ja' => 0, 'mit' => 0];
    foreach ($chance as $tag => $c) {
        $g = $gemessen[$tag] ?? null;
        $mit = $g !== null && $g['abdeckung'] >= 0.6;
        $ja = $mit && $g['stunden_ab'][(int)$sport['min']] >= MIN_STUNDEN;
        $ez['mit'] += (int)$mit;
        $ez['gem_ja'] += (int)$ja;
        if ($c >= 0.5) {
            $ez['ab50']++;
            $ez['ab50_mit'] += (int)$mit;
            $ez['ab50_gem'] += (int)$ja;
        }
    }
    $bericht[$id] = ['region' => $r, 'station' => $station, 'kandidaten' => array_slice($kand, 0, 8, true), 'z' => $z, 'ez' => $ez, 'modelle' => $mod, 'see' => $seeHoehe];
    $json[$id] = ['station' => $station, 'tage' => array_map(fn($x, $t) => $x + ['gemessen' => $gemessen[$t] ?? null], $regel, array_keys($regel))];
    $json[$id]['tage'] = array_combine(array_keys($regel), $json[$id]['tage']);
}

// ===================================================================
// Bericht
// ===================================================================

$md = "# Regel-Windlagen gegen Messstationen\n\n";
$md .= "Erzeugt von `tools/thermik_pruefen.php` (Action „Thermik prüfen“) am " . gmdate('j.n.Y H:i') . " UTC. Zeitraum $von bis $bis.\n\n";
$md .= "- **Regel geht**: `region_bewerten()` der App mit den Modellen von damals (Open-Meteo Historical Forecast, kurze Vorlaufzeit, ohne Ensemble), Sportart " . $sport['name'] . " (ab {$sport['min']} kn). Zählt auch Tage, an denen eine normale Windlage mit Modellwind geht.\n";
$md .= "- **Gemessen geht**: mindestens " . MIN_STUNDEN . " Stunden am Stück im Tagesfenster ein Stundenmittel ab {$sport['min']} kn aus einem Sektor der Windlagen; „schwach“ = ab " . TP_SCHWACH_KN . " kn. Stationen an Land messen oft weniger als auf dem Wasser.\n";
$md .= "- Gezählt nur Tage mit Messwerten für mindestens 60 % des Fensters, ohne Sperrtage.\n\n";
$md .= "| Region | Station | Tage | Regel geht | davon gemessen ab {$sport['min']} kn | ab " . TP_SCHWACH_KN . " kn | gemessen ab {$sport['min']} kn | davon von der Regel erkannt |\n|---|---|---|---|---|---|---|---|\n";
foreach ($bericht as $id => $b) {
    $z = $b['z'];
    $s = $b['station'];
    $md .= "| {$b['region']['name']} | " . ($s ? "{$s['name']} ({$s['netz']}, {$s['km']} km, " . sprintf('%+d', $s['dh'] ?? 0) . " m)" : 'keine') . " | {$z['tage']} | {$z['regel_ja']} | "
         . tp_pct($z['regel_ja_gem'], $z['regel_ja']) . " | " . tp_pct($z['regel_ja_schwach'], $z['regel_ja']) . " | {$z['gem_ja']} | " . tp_pct($z['gem_ja_regel'], $z['gem_ja']) . " |\n";
}
$md .= "\n### Ensemble wie in der App (letzte " . TP_ENS_TAGE . " Tage)\n\nChance = Anteil der 51 ECMWF-Läufe, an denen der Tag geht (wie die Kacheln). Gemessen wie oben.\n\n";
$md .= "| Region | Tage | Chance im Mittel | Tage ab 50 % | davon mit Messung | davon gemessen ab {$sport['min']} kn | Tage gemessen ab {$sport['min']} kn |\n|---|---|---|---|---|---|---|\n";
foreach ($bericht as $id => $b) {
    $e = $b['ez'];
    $md .= "| {$b['region']['name']} | {$e['tage']} | " . ($e['tage'] ? round(100 * $e['summe'] / $e['tage']) . ' %' : '–') . " | {$e['ab50']} | {$e['ab50_mit']} | "
         . tp_pct($e['ab50_gem'], $e['ab50_mit']) . " | " . ($e['mit'] ? "{$e['gem_ja']} von {$e['mit']}" : '–') . " |\n";
}
$md .= "\n### Trefferquote je Regel-Windlage (Vorschlag für `treffer` im Katalog)\n\n";
$md .= "Treffer = gemessen ab " . TP_SCHWACH_KN . " kn (Landstationen messen weniger als auf dem Wasser). Vorschlag = (Treffer + " . TP_VORWISSEN . " × " . TP_VORWISSEN_TAGE . ") / (Tage + " . TP_VORWISSEN_TAGE . "): kleine Stichproben rücken zu " . TP_VORWISSEN . ".\n\n";
$md .= "| Region | Windlage | Regeltage mit Messung | ab " . TP_SCHWACH_KN . " kn | ab {$sport['min']} kn | Vorschlag |\n|---|---|---|---|---|---|\n";
foreach ($json as $id => $j) {
    $c = [];
    foreach ($j['tage'] as $t) {
        $g = $t['gemessen'];
        if (!$t['geht'] || $g === null || $g['abdeckung'] < 0.6 || $t['sperre']) {
            continue;
        }
        $c[$t['windlage']] ??= [0, 0, 0];
        $c[$t['windlage']][0]++;
        $c[$t['windlage']][1] += (int)($g['stunden_ab'][TP_SCHWACH_KN] >= MIN_STUNDEN);
        $c[$t['windlage']][2] += (int)($g['stunden_ab'][(int)$sport['min']] >= MIN_STUNDEN);
    }
    foreach ($c as $lage => [$n, $a, $b]) {
        $v = ($a + TP_VORWISSEN * TP_VORWISSEN_TAGE) / ($n + TP_VORWISSEN_TAGE);
        $md .= "| {$bericht[$id]['region']['name']} | $lage | $n | $a | $b | " . number_format($v, 2) . " |\n";
    }
}
foreach ($bericht as $id => $b) {
    $md .= "\n## {$b['region']['name']} (`$id`)\n\n";
    $md .= "Modelle: " . ($b['modelle'] ?? 'keine Daten') . "; Höhe am Vorhersagepunkt " . ($b['see'] ?? '?') . " m.\n\n";
    $md .= "Kandidaten (Umkreis " . TP_SUCH_KM . " km):\n\n";
    foreach ($b['kandidaten'] as $key => $k) {
        $md .= "- `$key` {$k['name']}, {$k['km']} km, {$k['hoehe']} m (" . sprintf('%+d', $k['dh'] ?? 0) . " m)\n";
    }
    if (!$b['kandidaten']) {
        $md .= "- keine in den offenen Netzen\n";
    }
    if ($b['z']['monate']) {
        $md .= "\n| Monat | Tage | Regel geht | davon gemessen | gemessen geht |\n|---|---|---|---|---|\n";
        foreach ($b['z']['monate'] as $m => $x) {
            $md .= "| $m | {$x['tage']} | {$x['regel_ja']} | " . tp_pct($x['treffer'], $x['regel_ja']) . " | {$x['gem_ja']} |\n";
        }
    }
    $tage = $json[$id]['tage'];
    $hoch = array_filter($tage, fn($t) => ($t['chance'] ?? 0) >= 0.5);
    if ($hoch) {
        $md .= "\nEnsemble ab 50 % (letzte " . TP_ENS_TAGE . " Tage): " . implode(', ', array_map(fn($t, $x) => substr($t, 5) . ' (' . round(100 * $x['chance']) . ' %'
             . ($x['gemessen'] !== null ? ', gemessen ' . $x['gemessen']['spitze'] . ' kn' : '') . ')', array_keys($hoch), $hoch)) . "\n";
    }
    $liste = array_filter($tage, fn($t) => ($t['geht'] || $t['modellwind']) && $t['gemessen'] !== null);
    $liste = array_slice($liste, -20, null, true);
    if ($liste) {
        $md .= "\nLetzte Tage, an denen die Regel „geht“ sagte:\n\n| Tag | Windlage | Fenster | Ensemble | gemessen (bester 2-h-Block) | Richtung |\n|---|---|---|---|---|---|\n";
        foreach ($liste as $tag => $t) {
            $md .= "| $tag | {$t['windlage']} | {$t['von']}–{$t['bis']} Uhr | " . (isset($t['chance']) ? round(100 * $t['chance']) . ' %' : '–') . " | {$t['gemessen']['spitze']} kn | " . ($t['gemessen']['richtung'] ?? '–') . "° |\n";
        }
    }
}
file_put_contents("$ausDir/pruefung.md", $md);
file_put_contents("$ausDir/pruefung.json", json_encode(['von' => $von, 'bis' => $bis, 'sport' => TP_SPORT, 'regionen' => $json], JSON_UNESCAPED_UNICODE));
echo "\n$md";
