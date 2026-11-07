<?php
/**
 * Karte der Rangliste (seit 7.11.2026): Regionen als Punkte, eingefaerbt nach
 * der Chance am gewaehlten Tag, auf einer eigenen SVG-Grundlage (karte.svg aus
 * Natural Earth, tools/karte_bauen.py). Keine fremden Kachelserver.
 *
 * Projektion wie in tools/karte_bauen.py: Mercator, KARTE_K Einheiten je
 * Laengengrad, Ursprung (KARTE_LON0, KARTE_LAT_MAX) oben links.
 */

const KARTE_LON0 = -20.0;
const KARTE_LAT_MAX = 64.0;
const KARTE_K = 10.0;
const KARTE_BREITE = 560.0;      // (36 - -20) * K
const KARTE_HOEHE = 614.3;       // bis 22 Grad Nord
const KARTE_SEITE = 1.1;         // Hoehe : Breite des Ausschnitts (etwas hoeher als breit, fuers Handy)
const KARTE_MIN_GRAD = 2.5;      // kleinster Ausschnitt, in Laengengraden

function karte_merc(float $lat): float
{
    return rad2deg(log(tan(M_PI / 4 + deg2rad($lat) / 2)));
}

/** [x, y] in Karteneinheiten */
function karte_punkt(float $lat, float $lon): array
{
    return [($lon - KARTE_LON0) * KARTE_K, (karte_merc(KARTE_LAT_MAX) - karte_merc($lat)) * KARTE_K];
}

/** Radius eines Kreises von $km um $lat in Karteneinheiten (Mercator ist oertlich winkeltreu) */
function karte_km(float $km, float $lat): float
{
    return $km / (111.32 * cos(deg2rad($lat))) * KARTE_K;
}

/**
 * Ausschnitt [x, y, breite, hoehe] um alle Punkte, mit Rand, im Seitenverhaeltnis
 * KARTE_SEITE und nicht groesser als die Grundlage.
 * $punkte: Liste von [x, y]
 */
function karte_ausschnitt(array $punkte): array
{
    $xs = array_column($punkte, 0);
    $ys = array_column($punkte, 1);
    [$x0, $x1, $y0, $y1] = [min($xs), max($xs), min($ys), max($ys)];
    $w = max($x1 - $x0, KARTE_MIN_GRAD * KARTE_K);
    $h = max($y1 - $y0, KARTE_MIN_GRAD * KARTE_K);
    $w *= 1.2;   // Rand fuer die Punkte am Rand
    $h *= 1.2;
    if ($h / $w < KARTE_SEITE) {
        $h = $w * KARTE_SEITE;
    } else {
        $w = $h / KARTE_SEITE;
    }
    if ($w > KARTE_BREITE) {
        $w = KARTE_BREITE;
        $h = $w * KARTE_SEITE;
    }
    if ($h > KARTE_HOEHE) {
        $h = KARTE_HOEHE;
        $w = $h / KARTE_SEITE;
    }
    $cx = ($x0 + $x1) / 2;
    $cy = ($y0 + $y1) / 2;
    $x = min(max($cx - $w / 2, 0), KARTE_BREITE - $w);
    $y = min(max($cy - $h / 2, 0), KARTE_HOEHE - $h);
    return [round($x, 1), round($y, 1), round($w, 1), round($h, 1)];
}

/**
 * Namen an die Punkte setzen, ohne dass sie sich ueberlappen: die besten zuerst,
 * rechts vom Punkt, sonst links, sonst ohne Namen. Breite grob geschaetzt
 * (0,55 x Schriftgroesse je Zeichen).
 * $punkte: [schluessel => ['xy' => [x, y], 'name' => string, 'rang' => float]]
 * Rueckgabe [schluessel => 'rechts' | 'links'], fehlend = kein Name.
 */
function karte_namen(array $punkte, float $rp, array $ausschnitt): array
{
    [$vx, $vy, $vw, $vh] = $ausschnitt;
    $schrift = $rp;
    uasort($punkte, fn($a, $b) => $b['rang'] <=> $a['rang']);
    // Belegte Flaechen: zuerst alle Punkte, dann die gesetzten Namen
    $belegt = [];
    foreach ($punkte as $p) {
        $belegt[] = [$p['xy'][0] - $rp, $p['xy'][1] - $rp, $p['xy'][0] + $rp, $p['xy'][1] + $rp];
    }
    $out = [];
    foreach ($punkte as $k => $p) {
        $b = mb_strlen($p['name']) * 0.55 * $schrift;
        [$x, $y] = $p['xy'];
        $kandidaten = [
            'rechts' => [$x + $rp * 1.3, $y - $schrift * 0.6, $x + $rp * 1.3 + $b, $y + $schrift * 0.6],
            'links'  => [$x - $rp * 1.3 - $b, $y - $schrift * 0.6, $x - $rp * 1.3, $y + $schrift * 0.6],
        ];
        foreach ($kandidaten as $seite => $box) {
            if ($box[0] < $vx || $box[2] > $vx + $vw || $box[1] < $vy || $box[3] > $vy + $vh) {
                continue;
            }
            $frei = true;
            foreach ($belegt as $o) {
                if ($box[0] < $o[2] && $box[2] > $o[0] && $box[1] < $o[3] && $box[3] > $o[1]) {
                    $frei = false;
                    break;
                }
            }
            if ($frei) {
                $out[$k] = $seite;
                $belegt[] = $box;
                break;
            }
        }
    }
    return $out;
}

/**
 * Wert eines Punkts: Chance am Tag $tag, oder ohne Tag die beste Chance der
 * angezeigten Tage (ohne Ensemble: 1 wenn das Modellmittel ein Fenster sieht).
 * Rueckgabe ['chance' => ?float, 'tag' => ?string, 'geht' => bool]
 */
function karte_wert(array $eintrag, ?string $tag): array
{
    $tage = $tag !== null ? [$tag => $eintrag['tage'][$tag] ?? null] : $eintrag['tage'];
    $best = ['chance' => null, 'tag' => null, 'geht' => false];
    foreach ($tage as $t => $d) {
        if ($d === null || !empty($d['vorbei'])) {
            continue;
        }
        $c = $d['ens']['chance'] ?? null;
        $geht = (bool)$d['det']['geht'];
        $wert = $c ?? ($geht ? 1.0 : 0.0);
        $bisher = $best['chance'] ?? ($best['geht'] ? 1.0 : -1.0);
        if ($best['tag'] === null || $wert > $bisher) {
            $best = ['chance' => $c, 'tag' => $t, 'geht' => $geht];
        }
    }
    return $best;
}
