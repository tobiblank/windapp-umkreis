<?php
/**
 * Ersatz fuer Open-Meteo im Test (php -S 127.0.0.1:PORT fake_openmeteo.php).
 * Liefert dieselbe Struktur wie die echte API, mit festen Testwerten:
 *   Breite 48,0 (Ammersee): Westwind 250 Grad, 16 kn, morgen 10-15 UTC, sonst 6 kn
 *   Breite 45,5 (Adria): Nordost 40 Grad (Bora), 20 kn, morgen und uebermorgen 9-14 UTC
 *   andere Orte: Suedwind 180 Grad, 8 kn
 * Fuer Regel-Windlagen (Testkatalog):
 *   Druckpunkt A (Breite 47,0): morgen 3 hPa ueber B, sonst 5 hPa darunter;
 *     im Ensemble skaliert die Abweichung mit dem Lauf (Vorzeichen bleibt)
 *   Druckpunkt B (Breite 46,0): 1015 hPa
 *   700/850 hPa: morgen 8 kn aus SW (225 Grad), sonst 20 kn aus N; im Ensemble
 *     skaliert die Staerke mit dem Lauf
 * Schnee-Modus (seit 8.11.2026), Tage relativ zu heute (past_days wie Open-Meteo):
 *   Breite 47,2 (Schneeregion A, Tour 2400 m): gestern 1,5 mm/h, Schneefallgrenze 1200 m
 *     (= 25 cm), sonst trocken; gestern 95 % Wolken, sonst 10 %; 80 cm Schnee; Kamm 20 km/h
 *   Breite 46,3 (Schneeregion B, Tour 2000 m): gestern 1 mm/h Regen (Grenze 2800 m),
 *     20 % Wolken, 10 cm Schnee, Kamm 50 km/h
 * Lageberichte (CAAML v6): SLF (JSON), Euregio (JSON, morgen 404), Bayern (XML),
 * Pfad mit _XX_ -> HTTP 500 (darf nie abgerufen werden, Dienst nicht freigegeben).
 * Mehrere Orte -> Liste, ein Ort -> Objekt (wie Open-Meteo).
 */

$pfad = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Ersatz fuer Alplakes (seit 8.11.2026): /simulations/1d/point/simstrat/{see}/{von}/{bis}/{tiefe}
// Wie die echte API alle 3 Stunden, ein paar Sekunden nach der vollen Stunde, von gestern
// 1 Uhr UTC bis in 4 Tagen. Ammersee: abwechselnd 10 und 13 Grad (prueft das Fuellen der
// Stunden dazwischen). Andere Seen: HTTP 400 wie bei Alplakes.
if (preg_match('#^/simulations/1d/point/simstrat/([a-z0-9]+)/\d{12}/\d{12}/[\d.]+$#', $pfad, $m)) {
    header('Content-Type: application/json');
    if ($m[1] !== 'ammersee') {
        http_response_code(400);
        echo json_encode(['detail' => "simstrat simulation results are not available for {$m[1]}"]);
        exit;
    }
    $start = strtotime(gmdate('Y-m-d', time() - 86400) . ' 01:00 UTC');
    $zeiten = $werte = [];
    for ($i = 0; $i < 8 * 5; $i++) {
        $zeiten[] = gmdate('Y-m-d\TH:i:', $start + 3 * 3600 * $i) . '02.880000+00:00';
        $werte[] = $i % 2 ? 13.0 : 10.0;
    }
    echo json_encode(['time' => $zeiten, 'depth' => ['data' => 1.0, 'unit' => 'm'], 'resample' => null,
                      'variables' => ['T' => ['data' => $werte, 'unit' => 'degC', 'description' => 'Temperature']]]);
    exit;
}
// Wetterstationen Tirol (seit 9.11.2026), Aufbau wie die echte Datei vom 9.11.2026:
// sta-gut plausibel, sta-fehler mit negativer Schneehoehe (Messfehler), sta-sprung mit
// unplausibler Aenderung in 24 h, sta-andere nicht im Katalog; sta-fehlt gibt es nicht.
if ($pfad === '/lawine/produkte/ogd.geojson') {
    header('Content-Type: application/json');
    $zeit = (new DateTimeImmutable('@' . (intdiv(time(), 3600) * 3600)))->setTimezone(new DateTimeZone('Europe/Vienna'))->format('c');
    $st = fn($plot, $name, $lon, $lat, $h, $p) => ['id' => $plot, 'type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [$lon, $lat, $h]],
        'properties' => $p + ['plot' => $plot, 'name' => $name, 'date' => $zeit, 'operator' => 'LWD Tirol', 'LWD-Region' => 'AT-07-22 Stubaier Alpen Mitte']];
    echo json_encode(['type' => 'FeatureCollection', 'features' => [
        $st('sta-gut', 'Gutalm', 11.0, 47.2, 2450.0, ['HS' => 42.3, 'HSD24' => 5.2, 'HSD72' => 12.0, 'LT' => -3.1]),
        $st('sta-fehler', 'Fehlerkopf', 11.01, 47.21, 2500.0, ['HS' => -1.3, 'HSD24' => -5.8]),
        $st('sta-sprung', 'Sprungjoch', 11.02, 47.22, 2300.0, ['HS' => 10.0, 'HSD24' => -23.0 * 10]),
        $st('sta-andere', 'Anderswo', 12.0, 47.0, 1800.0, ['HS' => 5.0]),
    ]]);
    exit;
}
// Lageberichte (seit 8.11.2026)
$jetztTs = time();
$gueltig = ['startTime' => gmdate('Y-m-d\TH:i:s\Z', $jetztTs - 86400), 'endTime' => gmdate('Y-m-d\TH:i:s\Z', $jetztTs + 2 * 86400)];
if ($pfad === '/api/bulletin/caaml/de/json') {
    header('Content-Type: application/json');
    echo json_encode(['bulletins' => [[
        'bulletinID' => 'slf-1', 'lang' => 'de', 'publicationTime' => gmdate('Y-m-d\TH:i:s\Z', $jetztTs - 3600), 'validTime' => $gueltig,
        'regions' => [['regionID' => 'CH-7111', 'name' => 'Oberengadin'], ['regionID' => 'CH-7112', 'name' => 'Bernina']],
        'dangerRatings' => [['mainValue' => 'considerable', 'elevation' => ['lowerBound' => '2200'], 'validTimePeriod' => 'all_day'],
                            ['mainValue' => 'moderate', 'elevation' => ['upperBound' => '2200'], 'validTimePeriod' => 'all_day']],
        'avalancheProblems' => [['problemType' => 'wind_slab', 'elevation' => ['lowerBound' => '2000'], 'aspects' => ['N', 'NE', 'E'], 'validTimePeriod' => 'all_day'],
                                ['problemType' => 'persistent_weak_layers', 'elevation' => ['lowerBound' => 'treeline'], 'aspects' => ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW']]],
    ]]]);
    exit;
}
if (preg_match('#^/bulletins/(\d{4}-\d{2}-\d{2})/\1_(EUREGIO|DE-BY|XX)_de_CAAMLv6\.(json|xml)$#', $pfad, $m)) {
    $heuteB = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    if ($m[2] === 'XX') {
        http_response_code(500);
        exit;
    }
    if ($m[2] === 'EUREGIO') {
        if ($m[1] !== $heuteB) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode(['bulletins' => [
            ['bulletinID' => 'eu-1', 'publicationTime' => gmdate('Y-m-d\TH:i:s\Z', $jetztTs - 7200), 'validTime' => $gueltig,
             'regions' => [['regionID' => 'AT-07-14-01', 'name' => 'Tirol (nicht freigegeben)'], ['regionID' => 'IT-32-BZ-08-01', 'name' => 'Ahrntal Ost']],
             'dangerRatings' => [['mainValue' => 'high', 'validTimePeriod' => 'all_day']], 'avalancheProblems' => [['problemType' => 'new_snow']]],
            ['bulletinID' => 'eu-2', 'publicationTime' => gmdate('Y-m-d\TH:i:s\Z', $jetztTs - 7200), 'validTime' => $gueltig,
             'regions' => [['regionID' => 'AT-07-02', 'name' => 'Nur Tirol']], 'dangerRatings' => [['mainValue' => 'low']]],
        ]]);
        exit;
    }
    // Bayern: XML wie 2025-12-20_DE-BY_de_CAAMLv6.xml, dazu Lawinenproblem und Hoehengrenze
    header('Content-Type: application/xml');
    $pub = gmdate('Y-m-d\TH:i:s\Z', $jetztTs - 1800);
    echo '<?xml version="1.0" encoding="UTF-8"?><bulletins xmlns="http://caaml.org/Schemas/V6.0/Profiles/BulletinEAWS">'
       . '<bulletin bulletinID="by-1" lang="de"><publicationTime>' . $pub . '</publicationTime><validTime><startTime>' . $gueltig['startTime']
       . '</startTime><endTime>' . $gueltig['endTime'] . '</endTime></validTime><unscheduled>false</unscheduled>'
       . '<source><person><name>Lawinenwarndienst Bayern</name></person></source>'
       . '<region regionID="DE-BY-30"><name>Werdenfelser Alpen</name></region><region regionID="DE-BY-12"><name>Allgäuer Alpen Mitte</name></region>'
       . '<dangerRating><mainValue>moderate</mainValue><elevation><lowerBound>1800</lowerBound></elevation><validTimePeriod>all_day</validTimePeriod></dangerRating>'
       . '<dangerRating><mainValue>low</mainValue><elevation><upperBound>1800</upperBound></elevation><validTimePeriod>all_day</validTimePeriod></dangerRating>'
       . '<avalancheProblem><problemType>wind_slab</problemType><elevation><lowerBound>1800</lowerBound></elevation><aspects><aspect>N</aspect><aspect>NW</aspect></aspects><validTimePeriod>all_day</validTimePeriod></avalancheProblem>'
       . '<customData><ALBINA><mainDate>' . $m[1] . '</mainDate></ALBINA></customData></bulletin></bulletins>';
    exit;
}

parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$lats = explode(',', $q['latitude'] ?? '');
$tage = (int)($q['forecast_days'] ?? 7);
$vars = explode(',', $q['hourly'] ?? '');
$modelle = isset($q['models']) ? explode(',', $q['models']) : [];

$vorher = (int)($q['past_days'] ?? 0);
$start = strtotime(gmdate('Y-m-d') . ' 00:00 UTC') - 86400 * $vorher;
$zeiten = [];
for ($i = 0; $i < 24 * ($tage + $vorher); $i++) {
    $zeiten[] = gmdate('Y-m-d\TH:i', $start + 3600 * $i);
}

function wert(int $ort, string $var, int $i, float $faktor): ?float
{
    global $vorher;
    $tag = intdiv($i, 24) - $vorher;
    $h = $i % 24;
    if ($ort === 5 || $ort === 6) {
        $a = $ort === 5;
        $nass = $tag === -1;
        return match ($var) {
            'precipitation'         => $nass ? round(($a ? 1.5 : 1.0) * $faktor, 2) : 0.0,
            'snowfall'              => $nass && $a ? round(1.5 * 0.7 * $faktor, 2) : 0.0,
            'snowfall_height'       => $a ? 1200.0 : 2800.0,
            'freezing_level_height' => $a ? 1500.0 : 3100.0,
            'temperature_2m'        => $a ? -2.0 : 3.0,
            'cloud_cover'           => $a ? ($nass ? 95 : 10) : 20,
            'snow_depth'            => $a ? 0.8 : 0.1,
            'wind_speed_700hPa'     => $a ? 20.0 : 50.0,
            'wind_gusts_10m'        => 30.0,
            'weather_code'          => $nass ? 73 : 0,
            default                 => null,
        };
    }
    if ($ort === 0) {
        $an = $tag === 1 && $h >= 10 && $h < 15;
        $kn = $an ? 16 : 6;
        $dir = 250;
    } elseif ($ort === 1) {
        $an = ($tag === 1 || $tag === 2) && $h >= 9 && $h < 14;
        $kn = $an ? 20 : 5;
        $dir = 40;
    } else {
        $kn = 8;
        $dir = 180;
    }
    $hoehe = $tag === 1 ? [8, 225] : [20, 0];
    $druck = 1015.0 + ($ort === 3 ? ($tag === 1 ? 3 : -5) * $faktor : 0);
    return match ($var) {
        'pressure_msl'       => round($druck, 2),
        'wind_speed_850hPa', 'wind_speed_700hPa'         => round($hoehe[0] * $faktor, 1),
        'wind_direction_850hPa', 'wind_direction_700hPa' => $hoehe[1],
        'wind_speed_10m'     => round($kn * $faktor, 1),
        'wind_direction_10m' => $dir,
        'wind_gusts_10m'     => round($kn * $faktor * 1.4, 1),
        'temperature_2m'     => 12.0,
        'weather_code'       => 2,
        'precipitation'      => 0.0,
        'cloud_cover'        => 40,
        'wave_height'        => $ort === 1 ? 0.8 : 0.2,
        'wave_direction', 'swell_wave_direction' => 60,
        'wave_period', 'swell_wave_period'       => 5.0,
        'swell_wave_height'  => 0.3,
        'sea_surface_temperature' => 14.0,
        default              => null,
    };
}

$liste = [];
foreach ($lats as $lat) {
    // Testorte nach Breite erkennen (die Abrufe sind nach Typ getrennt)
    $ort = match (true) {
        abs((float)$lat - 48.0) < 0.01 => 0,
        abs((float)$lat - 45.5) < 0.01 => 1,
        abs((float)$lat - 47.0) < 0.01 => 3,
        abs((float)$lat - 46.0) < 0.01 => 4,
        abs((float)$lat - 47.2) < 0.01 => 5,
        abs((float)$lat - 46.3) < 0.01 => 6,
        default => 2,
    };
    $hourly = ['time' => $zeiten];
    foreach ($vars as $v) {
        if (str_ends_with($pfad, '/ensemble')) {
            for ($m = 0; $m <= 50; $m++) {
                $key = $m === 0 ? $v : sprintf('%s_member%02d', $v, $m);
                // Laeufe streuen von 70 % bis 130 % der Grundwerte
                $f = 0.7 + 0.6 * $m / 50;
                $hourly[$key] = array_map(fn($i) => wert($ort, $v, $i, $f), array_keys($zeiten));
            }
        } elseif ($modelle) {
            foreach ($modelle as $k => $mod) {
                $f = 0.95 + 0.05 * $k;
                // icon_d2 nur fuer Ort 0 (ausserhalb seines Gebiets: null, wie bei Open-Meteo)
                $werte = array_map(fn($i) => ($mod === 'icon_d2' && $ort !== 0) ? null : wert($ort, $v, $i, $f), array_keys($zeiten));
                $hourly[count($modelle) > 1 ? "{$v}_$mod" : $v] = $werte;
            }
        } else {
            $hourly[$v] = array_map(fn($i) => wert($ort, $v, $i, 1.0), array_keys($zeiten));
        }
    }
    // Echo der Zellwahl, damit der Test pruefen kann, was angefragt wurde
    $liste[] = ['latitude' => (float)$lat, 'generationtime_ms' => mt_rand(1, 99) / 10,
                'test_cell_selection' => $q['cell_selection'] ?? null, 'test_elevation' => $q['elevation'] ?? null, 'hourly' => $hourly];
}

header('Content-Type: application/json');
echo json_encode(count($liste) === 1 ? $liste[0] : $liste);
