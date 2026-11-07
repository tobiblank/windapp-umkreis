<?php
/**
 * Ersatz fuer Open-Meteo im Test (php -S 127.0.0.1:PORT fake_openmeteo.php).
 * Liefert dieselbe Struktur wie die echte API, mit festen Testwerten:
 *   Ort 0: Westwind 250 Grad, 16 kn, morgen 10-15 UTC, sonst 6 kn
 *   Ort 1: Nordost 40 Grad (Bora), 20 kn, morgen und uebermorgen 9-14 UTC
 *   weitere Orte: Suedwind 180 Grad, 8 kn
 * Mehrere Orte -> Liste, ein Ort -> Objekt (wie Open-Meteo).
 */

$pfad = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$lats = explode(',', $q['latitude'] ?? '');
$tage = (int)($q['forecast_days'] ?? 7);
$vars = explode(',', $q['hourly'] ?? '');
$modelle = isset($q['models']) ? explode(',', $q['models']) : [];

$start = strtotime(gmdate('Y-m-d') . ' 00:00 UTC');
$zeiten = [];
for ($i = 0; $i < 24 * $tage; $i++) {
    $zeiten[] = gmdate('Y-m-d\TH:i', $start + 3600 * $i);
}

function wert(int $ort, string $var, int $i, float $faktor): ?float
{
    $tag = intdiv($i, 24);
    $h = $i % 24;
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
    return match ($var) {
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
        default              => null,
    };
}

$liste = [];
foreach ($lats as $ort => $lat) {
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
    $liste[] = ['latitude' => (float)$lat, 'generationtime_ms' => mt_rand(1, 99) / 10, 'hourly' => $hourly];
}

header('Content-Type: application/json');
echo json_encode(count($liste) === 1 ? $liste[0] : $liste);
