<?php
/**
 * Roadtrip (seit 7.11.2026, neu gedacht am selben Abend): eine Reise vom
 * Standort zu guten Tagen und wieder zurueck. Die App sucht die beste Laenge.
 *
 * Modell (in Stunden Ortszeit; Zeitzonen zwischen den Regionen werden ignoriert,
 * in Europa hoechstens eine Stunde):
 * - Hinfahrt am Morgen ab ROADTRIP_LOS_UHR (heute fruehestens jetzt), hoechstens
 *   ROADTRIP_FAHRT_PRO_TAG_MIN am Tag, bis ROADTRIP_ABENDS_BIS; laengere Strecken
 *   mit Uebernachtung unterwegs. Reicht es am Ankunftstag noch fuer
 *   MIN_STUNDEN im Fenster, ist der Tag ein Windtag, sonst ein Fahrtag.
 * - Jeden weiteren Tag: bleiben (Windtag, wenn der Tag gut ist, sonst Pause)
 *   oder abends nach dem Fenster weiterfahren, Etappe hoechstens
 *   ROADTRIP_ETAPPE_MAX_MIN, Ankunft bis ROADTRIP_ABENDS_BIS.
 * - Rueckfahrt nach dem letzten Windtag: abends, wenn man bis
 *   ROADTRIP_ABENDS_BIS zu Hause ist, sonst ab dem naechsten Morgen.
 * Guter Tag: nicht vorbei, Chance mindestens ROADTRIP_MIN_CHANCE (ohne
 * Ensemble: Modellmittel sieht ein Fenster, zaehlt als ROADTRIP_OHNE_ENSEMBLE).
 *
 * Bilanz = Summe der Chancen der Windtage - ROADTRIP_KOSTEN_STUNDE je Fahrstunde
 * - ROADTRIP_KOSTEN_TAG je Tag ohne Wind (Fahrtag, Pause, Rueckfahrtag).
 * Mindestens ROADTRIP_MIN_WINDTAGE Windtage. Eine dynamische Programmierung je
 * Abreisetag findet je (Tag, Region) die beste Bilanz; jedes Ende nach einem
 * Windtag ist ein Kandidat. Die besten, die sich nicht zu sehr aehneln, werden
 * vorgeschlagen; es sind Alternativen.
 *
 * Fahrzeit vom Standort: $e['fahrt']['min'] (genau oder geschaetzt), zwischen
 * Regionen aus der Luftlinie geschaetzt ($modell wie bei der Fahrzeit).
 */

const ROADTRIP_MIN_CHANCE = 0.5;
const ROADTRIP_OHNE_ENSEMBLE = 0.5;
const ROADTRIP_MIN_WINDTAGE = 2;
const ROADTRIP_ANZAHL = 5;
const ROADTRIP_ETAPPE_MAX_MIN = 300;      // 5 h zwischen zwei Regionen, abends
const ROADTRIP_FAHRT_PRO_TAG_MIN = 600;   // mehr am Tag nicht, sonst Uebernachtung unterwegs
const ROADTRIP_LOS_UHR = 6;
const ROADTRIP_ABENDS_BIS = 23;
const ROADTRIP_KOSTEN_STUNDE = 0.05;
const ROADTRIP_KOSTEN_TAG = 0.3;

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
 * Fahrt von $minuten ab Tag $tag, Uhrzeit $ab (Stunden), mit Uebernachtung.
 * Rueckgabe [Ankunftstag-Index, Ankunftsstunde].
 */
function roadtrip_fahrt(int $tag, float $ab, int $minuten): array
{
    $rest = $minuten;
    $h = $ab;
    while (true) {
        $heute = (int)min(ROADTRIP_FAHRT_PRO_TAG_MIN, max(0, (ROADTRIP_ABENDS_BIS - $h) * 60));
        if ($rest <= $heute) {
            return [$tag, (float)($h + $rest / 60)];
        }
        $rest -= $heute;
        $tag++;
        $h = (float)ROADTRIP_LOS_UHR;
    }
}

/**
 * Vorschlaege.
 * $liste: Eintraege wie rangliste() mit 'fahrt' (Fahrzeit vom Standort);
 * $tage: Tage in Reihenfolge, $tage[0] = heute; $jetztStunde: jetzt in Stunden.
 * Rueckgabe: Liste von Trips:
 *   ['start' => Abreisetag-Index, 'hin' => ['i', 'min', 'los', 'ankunft' => [tag, stunde]],
 *    'tage' => [tagIndex => ['i', 'wert' (null = kein Wind), 'art' => wind|pause|fahrt]],
 *    'etappen' => [tagIndex => ['von' => i, 'nach' => i, 'min']],   // abends vor diesem Tag
 *    'rueck' => ['i', 'min', 'tag', 'ankunft' (Stunde), 'abends' (bool)],
 *    'windtage', 'fahrt_min', 'bilanz']
 */
function roadtrip_vorschlaege(array $liste, array $tage, array $modell, float $jetztStunde, int $anzahl = ROADTRIP_ANZAHL): array
{
    $n = count($liste);
    $T = count($tage);
    $wert = [];
    $ende = [];
    foreach ($tage as $ti => $t) {
        foreach ($liste as $i => $e) {
            $d = $e['tage'][$t] ?? null;
            $wert[$ti][$i] = $d ? roadtrip_wert($d) : null;
            $ende[$ti][$i] = $d ? (float)(!empty($d['det']['geht']) ? $d['det']['bis'] : ($d['fenster'][1] ?? 18)) : 18.0;
        }
    }
    $heim = [];
    foreach ($liste as $i => $e) {
        $m = $e['fahrt']['min'] ?? null;
        $heim[$i] = $m !== null && !isset($e['fahrt']['flug']) ? (int)$m : null;
    }
    $etappe = [];
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            if ($i === $j) {
                continue;
            }
            $m = (int)round($modell[0] + $modell[1] * entfernung_km($liste[$i]['region']['punkt'], $liste[$j]['region']['punkt']));
            if ($m <= ROADTRIP_ETAPPE_MAX_MIN) {
                $etappe[$i][$j] = $m;
            }
        }
    }

    $kandidaten = [];
    for ($s = 0; $s < $T; $s++) {
        $los = $s === 0 ? max((float)ROADTRIP_LOS_UHR, ceil(($jetztStunde + 0.25) * 4) / 4) : (float)ROADTRIP_LOS_UHR;
        // Zustaende am Ende eines Tages: [i][windtage(0..MIN)] => Trip-Teil
        $stand = [];
        for ($i = 0; $i < $n; $i++) {
            if ($heim[$i] === null) {
                continue;
            }
            [$a, $hA] = roadtrip_fahrt($s, $los, $heim[$i]);
            if ($a >= $T) {
                continue;
            }
            $trip = ['start' => $s, 'hin' => ['i' => $i, 'min' => $heim[$i], 'los' => $los, 'ankunft' => [$a, $hA]],
                     'tage' => [], 'etappen' => [], 'windtage' => 0, 'fahrt_min' => $heim[$i],
                     'bilanz' => -ROADTRIP_KOSTEN_STUNDE * $heim[$i] / 60];
            for ($d = $s; $d < $a; $d++) {
                $trip['tage'][$d] = ['i' => $i, 'wert' => null, 'art' => 'fahrt'];
                $trip['bilanz'] -= ROADTRIP_KOSTEN_TAG;
            }
            $w = $wert[$a][$i];
            if ($w !== null && $hA + MIN_STUNDEN <= $ende[$a][$i]) {
                $trip['tage'][$a] = ['i' => $i, 'wert' => $w, 'art' => 'wind'];
                $trip['windtage'] = 1;
                $trip['bilanz'] += $w;
            } else {
                $trip['tage'][$a] = ['i' => $i, 'wert' => null, 'art' => 'fahrt'];
                $trip['bilanz'] -= ROADTRIP_KOSTEN_TAG;
            }
            $stand[$a][$i][min($trip['windtage'], ROADTRIP_MIN_WINDTAGE)] = $trip;
        }
        for ($d = $s; $d < $T; $d++) {
            foreach ($stand[$d] ?? [] as $i => $proK) {
                foreach ($proK as $trip) {
                    $letzter = $trip['tage'][$d];
                    // Ende hier, wenn heute Wind war
                    if ($letzter['art'] === 'wind' && $trip['windtage'] >= ROADTRIP_MIN_WINDTAGE) {
                        $kandidaten[] = roadtrip_abschluss($trip, $d, $i, $heim[$i], $ende[$d][$i]);
                    }
                    if ($d + 1 >= $T) {
                        continue;
                    }
                    // Bleiben oder abends weiter
                    $ziele = [$i => 0] + ($etappe[$i] ?? []);
                    foreach ($ziele as $j => $min) {
                        if ($heim[$j] === null) {
                            continue;   // Region ohne Landweg
                        }
                        if ($min > 0 && $ende[$d][$i] + $min / 60 > ROADTRIP_ABENDS_BIS) {
                            continue;
                        }
                        $neu = $trip;
                        if ($min > 0) {
                            $neu['etappen'][$d + 1] = ['von' => $i, 'nach' => $j, 'min' => $min];
                            $neu['fahrt_min'] += $min;
                            $neu['bilanz'] -= ROADTRIP_KOSTEN_STUNDE * $min / 60;
                        }
                        $w = $wert[$d + 1][$j];
                        if ($w !== null) {
                            $neu['tage'][$d + 1] = ['i' => $j, 'wert' => $w, 'art' => 'wind'];
                            $neu['windtage']++;
                            $neu['bilanz'] += $w;
                        } else {
                            $neu['tage'][$d + 1] = ['i' => $j, 'wert' => null, 'art' => 'pause'];
                            $neu['bilanz'] -= ROADTRIP_KOSTEN_TAG;
                        }
                        $k = min($neu['windtage'], ROADTRIP_MIN_WINDTAGE);
                        if (!isset($stand[$d + 1][$j][$k]) || $neu['bilanz'] > $stand[$d + 1][$j][$k]['bilanz']) {
                            $stand[$d + 1][$j][$k] = $neu;
                        }
                    }
                }
            }
        }
    }

    $kandidaten = array_filter($kandidaten, fn($t) => $t['bilanz'] > 0);
    usort($kandidaten, fn($a, $b) => $b['bilanz'] <=> $a['bilanz']);

    $gewaehlt = [];
    foreach ($kandidaten as $t) {
        $paare = [];
        foreach ($t['tage'] as $ti => $x) {
            if ($x['art'] === 'wind') {
                $paare[] = "$ti:" . $x['i'];
            }
        }
        foreach ($gewaehlt as $g) {
            // zu aehnlich: mindestens die Haelfte der Windtage am selben Ort wie ein gewaehlter
            if (count(array_intersect($paare, $g['_paare'])) * 2 >= count($paare)) {
                continue 2;
            }
        }
        $t['_paare'] = $paare;
        $gewaehlt[] = $t;
        if (count($gewaehlt) >= $anzahl) {
            break;
        }
    }
    return array_map(function ($t) {
        unset($t['_paare']);
        return $t;
    }, $gewaehlt);
}

/** Rueckfahrt nach dem Windtag $d an Region $i anhaengen */
function roadtrip_abschluss(array $trip, int $d, int $i, int $heimMin, float $fensterEnde): array
{
    if ($fensterEnde + $heimMin / 60 <= ROADTRIP_ABENDS_BIS) {
        $trip['rueck'] = ['i' => $i, 'min' => $heimMin, 'tag' => $d, 'ankunft' => $fensterEnde + $heimMin / 60, 'abends' => true, 'heim_tag' => $d];
    } else {
        [$a, $hA] = roadtrip_fahrt($d + 1, ROADTRIP_LOS_UHR, $heimMin);
        $trip['rueck'] = ['i' => $i, 'min' => $heimMin, 'tag' => $d + 1, 'ankunft' => $hA, 'abends' => false, 'heim_tag' => $a];
        $trip['bilanz'] -= ROADTRIP_KOSTEN_TAG * ($a - $d);
    }
    $trip['fahrt_min'] += $heimMin;
    $trip['bilanz'] -= ROADTRIP_KOSTEN_STUNDE * $heimMin / 60;
    // Pausen am Ende gibt es nicht: der letzte Tag ist ein Windtag
    $trip['tage'] = array_filter($trip['tage'], fn($ti) => $ti <= $d, ARRAY_FILTER_USE_KEY);
    return $trip;
}
