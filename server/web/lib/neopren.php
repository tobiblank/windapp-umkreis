<?php
/**
 * Neopren-Faustregel (seit 7.11.2026).
 *
 * Nach der Wassertemperatur, wie sie Kite- und Surfschulen als Faustregel
 * angeben (die Grenzen schwanken je nach Quelle um 1-2 Grad; hier geschaetzt).
 * Eine Stufe waermer, wenn die gefuehlte Temperatur bei Wind deutlich unter dem
 * Wasser liegt (NEOPREN_KAELTER_ALS_WASSER). Gefuehlt: Windchill-Formel
 * (Environment Canada / US NWS), gilt fuer Luft bis 10 Grad und Wind ab 4,8 km/h;
 * darueber die Lufttemperatur.
 *
 * Ohne Wassertemperatur (Seen) keine Empfehlung: die Luft sagt zu wenig, im
 * Fruehjahr ist das Wasser viel kaelter als die Luft, im Herbst waermer.
 */

const NEOPREN_STUFEN = [
    // ab Wassertemperatur (Grad C) => Empfehlung
    24 => 'Lycra oder Shorty',
    21 => 'Shorty 2 mm',
    18 => '3/2 mm',
    15 => '4/3 mm',
    12 => '5/4 mm, Schuhe',
    9  => '5/4 oder 6/5 mm, Haube, Schuhe, Handschuhe',
    -5 => '6/5 mm, Haube, Schuhe, Handschuhe',
];
const NEOPREN_KAELTER_ALS_WASSER = 5;   // gefuehlt so viel unter dem Wasser: eine Stufe waermer

/** Gefuehlte Temperatur bei Wind (Grad C), Wind in Knoten */
function gefuehlt(float $luft, float $kn): float
{
    $kmh = $kn * 1.852;
    if ($luft > 10 || $kmh < 4.8) {
        return $luft;
    }
    $v = $kmh ** 0.16;
    return 13.12 + 0.6215 * $luft - 11.37 * $v + 0.3965 * $luft * $v;
}

/**
 * Empfehlung fuer einen Tag. $t: ['luft', 'wasser', 'kn'] (tag_temperaturen()).
 * Rueckgabe ['text' => ?string (null ohne Wasser), 'wasser', 'luft', 'gefuehlt', 'waermer' (bool)]
 */
function neopren(array $t): array
{
    $luft = $t['luft'] ?? null;
    $gef = $luft !== null && isset($t['kn']) ? gefuehlt((float)$luft, (float)$t['kn']) : $luft;
    $w = $t['wasser'] ?? null;
    $out = ['text' => null, 'wasser' => $w, 'luft' => $luft, 'gefuehlt' => $gef, 'waermer' => false];
    if ($w === null) {
        return $out;
    }
    $stufen = array_values(NEOPREN_STUFEN);
    $grenzen = array_keys(NEOPREN_STUFEN);
    $i = count($stufen) - 1;
    foreach ($grenzen as $k => $g) {
        if ($w >= $g) {
            $i = $k;
            break;
        }
    }
    if ($gef !== null && $gef < $w - NEOPREN_KAELTER_ALS_WASSER && $i < count($stufen) - 1) {
        $i++;
        $out['waermer'] = true;
    }
    $out['text'] = $stufen[$i];
    return $out;
}
