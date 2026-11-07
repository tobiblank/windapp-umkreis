<?php
/**
 * Ersatz fuer openrouteservice im Test (php -S 127.0.0.1:PORT fake_ors.php).
 * Antwortet wie in der ORS-Dokumentation beschrieben, mit festen Regeln:
 *   Schluessel muss "testschluessel" sein, sonst 403.
 *   Snapping: Orte suedlich von 45,8 Grad (Adria im Testkatalog) liegen "auf dem
 *     Wasser" und rasten erst ab 10000 m ein (0,1 Grad noerdlich); Orte suedlich
 *     von 40 Grad nie; alle anderen sofort, 50 m daneben.
 *   Radius ueber 20000 m: 400 (wie ein Server mit kleinerer Grenze).
 *   Matrix: Dauer = Luftlinie x 1,3 / 80 km/h, Strecke = Luftlinie x 1,3;
 *     Ziele oestlich von 29 Grad (Testsee) ohne Route: null.
 */

$pfad = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
header('Content-Type: application/json');

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'testschluessel') {
    http_response_code(403);
    echo json_encode(['error' => 'Access to this API has been disallowed']);
    exit;
}

function luft_km(array $a, array $b): float
{
    [$lo1, $la1] = array_map('deg2rad', $a);
    [$lo2, $la2] = array_map('deg2rad', $b);
    $h = sin(($la2 - $la1) / 2) ** 2 + cos($la1) * cos($la2) * sin(($lo2 - $lo1) / 2) ** 2;
    return 2 * 6371.0 * asin(min(1.0, sqrt($h)));
}

if ($pfad === '/v2/snap/driving-car') {
    $r = (int)($body['radius'] ?? 0);
    if ($r > 20000) {
        http_response_code(400);
        echo json_encode(['error' => ['code' => 2003, 'message' => 'radius too large']]);
        exit;
    }
    $out = [];
    foreach ($body['locations'] ?? [] as [$lon, $lat]) {
        if ($lat < 40) {
            $out[] = null;
        } elseif ($lat < 45.8) {
            $out[] = $r >= 10000 ? ['location' => [$lon, $lat + 0.1], 'name' => 'Strada', 'snapped_distance' => 9500.0] : null;
        } else {
            $out[] = ['location' => [$lon + 0.0005, $lat], 'name' => 'Weg', 'snapped_distance' => 50.0];
        }
    }
    echo json_encode(['locations' => $out, 'metadata' => ['service' => 'snap']]);
    exit;
}

if ($pfad === '/v2/matrix/driving-car') {
    $orte = $body['locations'] ?? [];
    $dauer = $strecke = [];
    foreach ($body['sources'] ?? [0] as $s) {
        $zd = $zs = [];
        foreach ($body['destinations'] ?? [] as $d) {
            if ($orte[$d][0] > 29) {
                $zd[] = null;
                $zs[] = null;
                continue;
            }
            $km = luft_km($orte[$s], $orte[$d]) * 1.3;
            $zd[] = round($km / 80 * 3600, 1);
            $zs[] = round($km, 2);
        }
        $dauer[] = $zd;
        $strecke[] = $zs;
    }
    echo json_encode(['durations' => $dauer, 'distances' => $strecke, 'metadata' => ['service' => 'matrix']]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'unbekannter Pfad ' . $pfad]);
