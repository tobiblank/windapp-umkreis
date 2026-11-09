<?php
/**
 * Lawinen-Warnregionen (EAWS-Mikroregionen) zu den Schnee-Regionen finden (seit 9.11.2026).
 *
 *   php tools/eaws_zuordnen.php daten/schneeregionen.json [region,region]
 *
 * Holt die Grenzen der Mikroregionen aus dem Repository eaws/eaws-regions (gitlab.com,
 * CC BY 4.0) und sagt je Schnee-Region, in welcher Mikroregion der Vorhersagepunkt,
 * die Anfahrt und Punkte 5 km rundum liegen, mit dem deutschen Namen. Prueft ausserdem,
 * ob die EAWS-Sammlung Berichte der Warndienste fuehrt. Laeuft als GitHub Action
 * "EAWS-Regionen zuordnen".
 */

declare(strict_types=1);
date_default_timezone_set('UTC');
require __DIR__ . '/../server/web/lib/regionen.php';
require __DIR__ . '/../server/web/lib/bewertung.php';
require __DIR__ . '/../server/web/lib/schnee.php';

$katalog = $argv[1] ?? null;
$nur = array_filter(explode(',', (string)($argv[2] ?? '')));
if (!$katalog) {
    fwrite(STDERR, "Aufruf: php tools/eaws_zuordnen.php <schneeregionen.json> [region,region]\n");
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
        fwrite(STDERR, "$url: http=$code\n");
        return null;
    }
    return (string)$body;
}

const GL = 'https://gitlab.com/api/v4/projects/eaws%2Feaws-regions/repository/';
$baum = [];
foreach (['public', 'public/micro-regions', 'public/micro-regions_names'] as $pfad) {
    $t = json_decode((string)holen(GL . 'tree?' . http_build_query(['path' => $pfad, 'per_page' => 100, 'ref' => 'master'])), true);
    echo "$pfad: " . implode(' ', array_map(fn($x) => $x['name'], is_array($t) ? $t : [])) . "\n";
    foreach (is_array($t) ? $t : [] as $x) {
        $baum[] = $x['path'];
    }
}
$roh = fn(string $pfad) => holen(GL . 'files/' . rawurlencode($pfad) . '/raw?ref=master');

// Namen
$namen = [];
foreach ($baum as $p) {
    if (preg_match('#micro-regions_names/de\.json$#', $p)) {
        $namen = json_decode((string)$roh($p), true) ?: [];
    }
}
echo 'Namen: ' . count($namen) . "\n";

// Polygone der benoetigten Praefixe
$praefixe = ['AT-07', 'AT-08', 'DE-BY', 'IT-32-BZ', 'IT-25-SO-LI', 'IT-25', 'CH'];
$flaechen = [];
foreach ($baum as $p) {
    foreach ($praefixe as $pr) {
        if (preg_match('#micro-regions/' . preg_quote($pr, '#') . '_micro-regions\.geojson\.json$#', $p)) {
            $g = json_decode((string)$roh($p), true);
            foreach ($g['features'] ?? [] as $f) {
                $id = $f['properties']['id'] ?? null;
                if (!$id || !empty($f['properties']['end_date'])) {
                    continue;
                }
                $flaechen[$id] = $f['geometry'];
            }
            echo "$p: " . count($g['features'] ?? []) . " Flaechen\n";
        }
    }
}

function im_ring(array $ring, float $lon, float $lat): bool
{
    $in = false;
    for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
        [$xi, $yi] = $ring[$i];
        [$xj, $yj] = $ring[$j];
        if (($yi > $lat) !== ($yj > $lat) && $lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
            $in = !$in;
        }
    }
    return $in;
}
function in_flaeche(array $g, float $lat, float $lon): bool
{
    $polys = $g['type'] === 'Polygon' ? [$g['coordinates']] : ($g['type'] === 'MultiPolygon' ? $g['coordinates'] : []);
    foreach ($polys as $poly) {
        if (im_ring($poly[0], $lon, $lat)) {
            for ($h = 1; $h < count($poly); $h++) {
                if (im_ring($poly[$h], $lon, $lat)) {
                    continue 2;
                }
            }
            return true;
        }
    }
    return false;
}
$wo = function (float $lat, float $lon) use ($flaechen): ?string {
    foreach ($flaechen as $id => $g) {
        if (in_flaeche($g, $lat, $lon)) {
            return $id;
        }
    }
    return null;
};

foreach ($regionen as $r) {
    if ($nur && !in_array($r['id'], $nur, true)) {
        continue;
    }
    $treffer = [];
    $pkt = ['punkt' => $r['punkt'], 'anfahrt' => $r['anfahrt'] ?? $r['punkt']];
    foreach ([0, 72, 144, 216, 288] as $w) {
        $pkt["5km/$w"] = [$r['punkt'][0] + 5 / 111 * cos(deg2rad($w)), $r['punkt'][1] + 5 / (111 * cos(deg2rad($r['punkt'][0]))) * sin(deg2rad($w))];
    }
    foreach ($pkt as $was => $p) {
        $id = $wo($p[0], $p[1]);
        $treffer[] = "$was=" . ($id ? $id . ' ' . ($namen[$id] ?? '?') : '-');
    }
    $h = json_decode((string)holen('https://api.open-meteo.com/v1/elevation?' . http_build_query([
        'latitude' => $pkt['punkt'][0] . ',' . $pkt['anfahrt'][0], 'longitude' => $pkt['punkt'][1] . ',' . $pkt['anfahrt'][1]])), true)['elevation'] ?? [];
    printf("%-20s jetzt %s %s  Hoehe Punkt %s m, Anfahrt %s m (Katalog Tal %d, Tour %d)\n    %s\n", $r['id'], json_encode($r['eaws']), json_encode($r['eaws_namen'] ?? null),
           json_encode($h[0] ?? null), json_encode($h[1] ?? null), $r['hoehe_tal'], $r['hoehe_tour'], implode("\n    ", $treffer));
}

foreach ($flaechen as $id => $g) {
    if (str_starts_with($id, 'IT-25-SO')) {
        echo "Flaeche $id " . ($namen[$id] ?? '?') . "\n";
    }
}

// Berichte in der EAWS-Sammlung (letzter Winter)
foreach (['IT-25-SO-LI', 'IT-25'] as $pr) {
    foreach (['2026-02-15'] as $tag) {
        $b = holen("https://static.avalanche.report/eaws_bulletins/$tag/$tag-$pr.json");
        echo "Sammlung $tag $pr: " . ($b === null ? 'fehlt' : strlen($b) . ' Bytes, ' . substr(preg_replace('/\s+/', ' ', $b), 0, 300)) . "\n";
    }
}
