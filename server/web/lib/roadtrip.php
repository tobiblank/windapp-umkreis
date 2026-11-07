<?php
/**
 * Roadtrip (seit 7.11.2026): Ketten guter Tage hintereinander, an denen man
 * jeweils an derselben oder einer nahen Region aufs Wasser kommt.
 *
 * Ein Tag ist gut, wenn er nicht vorbei ist und die Chance mindestens
 * ROADTRIP_MIN_CHANCE betraegt (ohne Ensemble: das Modellmittel sieht ein
 * Fenster, gewertet mit ROADTRIP_OHNE_ENSEMBLE). Nah heisst hoechstens
 * ROADTRIP_NAH_KM Luftlinie zwischen den Vorhersagepunkten.
 *
 * Je Starttag sucht eine dynamische Programmierung die beste Folge (Summe der
 * Chancen, ein Wechsel kostet ROADTRIP_WECHSEL_MALUS) fuer jede Laenge. Vorschlaege
 * sind die laengsten Ketten je Starttag und Region; gereiht nach Zahl der Tage,
 * dann mittlerer Chance. Doppelungen fallen weg: Teilstuecke eines gewaehlten
 * Vorschlags und Vorschlaege, die an mindestens der Haelfte der Tage am selben
 * Ort sind wie ein gewaehlter, und Vorschlaege an schon abgedeckten Tagen, deren
 * Regionen alle nahe an denen eines gewaehlten liegen.
 */

const ROADTRIP_MIN_CHANCE = 0.5;
const ROADTRIP_OHNE_ENSEMBLE = 0.5;
const ROADTRIP_NAH_KM = 150;
const ROADTRIP_MIN_TAGE = 2;
const ROADTRIP_ANZAHL = 5;
const ROADTRIP_WECHSEL_MALUS = 0.05;   // lieber bleiben, wenn es kaum etwas bringt

/** Wert eines Tages fuer den Roadtrip oder null (kein guter Tag) */
function roadtrip_wert(array $d): ?float
{
    if (!empty($d['vorbei'])) {
        return null;
    }
    $c = $d['ens']['chance'] ?? null;
    if ($c !== null) {
        return $c >= ROADTRIP_MIN_CHANCE - 1e-9 ? (float)$c : null;
    }
    return !empty($d['det']['geht']) ? ROADTRIP_OHNE_ENSEMBLE : null;
}

/**
 * Vorschlaege aus der Rangliste.
 * $liste: Eintraege mit 'region' und 'tage' (wie rangliste()), $tage: Tage in Reihenfolge.
 * Rueckgabe: Liste von ['tage' => [['tag', 'i' (Index in $liste), 'wert'], ...], 'mittel' => float]
 */
function roadtrip_vorschlaege(array $liste, array $tage, int $anzahl = ROADTRIP_ANZAHL): array
{
    $n = count($liste);
    $wert = [];   // [tagIndex][i] => float
    foreach ($tage as $ti => $t) {
        foreach ($liste as $i => $e) {
            if (isset($e['tage'][$t]) && ($w = roadtrip_wert($e['tage'][$t])) !== null) {
                $wert[$ti][$i] = $w;
            }
        }
    }
    $nah = [];
    for ($i = 0; $i < $n; $i++) {
        $nah[$i] = [$i];
        for ($j = 0; $j < $n; $j++) {
            if ($i !== $j && entfernung_km($liste[$i]['region']['punkt'], $liste[$j]['region']['punkt']) <= ROADTRIP_NAH_KM) {
                $nah[$i][] = $j;
            }
        }
    }

    $kandidaten = [];
    foreach ($tage as $s => $_) {
        // best[i] = [summe, vorgaenger-Kette als Liste von i]
        // Jede beste Kette jeder Laenge ist Kandidat; Teilstuecke laengerer Ketten
        // fallen bei der Auswahl unten weg
        $best = [];
        foreach ($wert[$s] ?? [] as $i => $w) {
            $best[$i] = [$w, [$i]];
        }
        for ($d = $s + 1; $best && $d < count($tage); $d++) {
            $neu = [];
            foreach ($wert[$d] ?? [] as $i => $w) {
                foreach ($nah[$i] as $j) {
                    if (!isset($best[$j])) {
                        continue;
                    }
                    $summe = $best[$j][0] + $w - ($i === $j ? 0 : ROADTRIP_WECHSEL_MALUS);
                    if (!isset($neu[$i]) || $summe > $neu[$i][0]) {
                        $neu[$i] = [$summe, array_merge($best[$j][1], [$i])];
                    }
                }
            }
            foreach ($neu as [$summe, $kette]) {
                $kandidaten[] = [$s, $kette, $summe];
            }
            $best = $neu;
        }
    }

    $kandidaten = array_filter($kandidaten, fn($k) => count($k[1]) >= ROADTRIP_MIN_TAGE);
    usort($kandidaten, fn($a, $b) => [count($b[1]), $b[2] / count($b[1])] <=> [count($a[1]), $a[2] / count($a[1])]);

    $gewaehlt = [];
    foreach ($kandidaten as [$s, $kette, $summe]) {
        $tageK = range($s, $s + count($kette) - 1);
        $regionenK = array_unique($kette);
        $paare = array_map(fn($k, $i) => ($s + $k) . ':' . $i, array_keys($kette), $kette);
        foreach ($gewaehlt as $g) {
            // Teil eines schon gewaehlten Vorschlags (Tage und Regionen darin enthalten)
            // oder zu aehnlich (mindestens die Haelfte der Tage am selben Ort): weglassen
            if (!array_diff($tageK, $g['ti']) && !array_diff($regionenK, $g['ri'])) {
                continue 2;
            }
            if (count(array_intersect($paare, $g['paare'])) * 2 >= count($paare)) {
                continue 2;
            }
            // an Tagen, die ein gewaehlter Vorschlag schon abdeckt, nur in dessen Naehe: nichts Neues
            if (!array_diff($tageK, $g['ti']) && !array_filter($regionenK, fn($i) => !array_intersect($nah[$i], $g['ri']))) {
                continue 2;
            }
        }
        $gewaehlt[] = ['ti' => $tageK, 'ri' => $regionenK, 'kette' => $kette, 's' => $s, 'paare' => $paare];
        if (count($gewaehlt) >= $anzahl) {
            break;
        }
    }

    $out = [];
    foreach ($gewaehlt as $g) {
        $tageOut = [];
        foreach ($g['kette'] as $k => $i) {
            $ti = $g['s'] + $k;
            $tageOut[] = ['tag' => $tage[$ti], 'i' => $i, 'wert' => $wert[$ti][$i]];
        }
        $out[] = ['tage' => $tageOut, 'mittel' => array_sum(array_column($tageOut, 'wert')) / count($tageOut)];
    }
    return $out;
}
