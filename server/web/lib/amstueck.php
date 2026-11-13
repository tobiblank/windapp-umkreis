<?php
/**
 * Windtage am Stueck (seit 13.11.2026, Wunsch Tobias): Weite Fahrten lohnen sich erst, wenn
 * mehrere Tage hintereinander Wind ist. Gezaehlt wird immer ueber alle 7 Tage, auch wenn die
 * Kacheln nur einen Teil zeigen.
 *
 * Windtag: nicht vorbei, Chance ab AM_STUECK_WINDTAG (ohne Ensemble: Modellmittel sieht ein
 * Fenster), wie ein guter Tag im Roadtrip. Bruecke: ein einzelner Tag mit Chance ab
 * AM_STUECK_BRUECKE zwischen zwei Windtagen haelt die Strecke zusammen, zaehlt aber nicht
 * als Windtag (gut, schwach, gut = 2 Windtage am Stueck). Zwei schwache Tage hintereinander
 * trennen.
 *
 * Noetig (Auswahl "Am Stueck", Parameter stueck): auto = nach Fahrzeit (AM_STUECK_FAHRT;
 * Flugziele und Ziele ohne Landweg wie die weiteste Stufe), egal, 2 oder 3. Regionen, die es
 * nicht schaffen, bleiben sichtbar, stehen aber am Ende ("lohnt sich kaum").
 * Startwerte ohne Quelle.
 */

const AM_STUECK_WINDTAG = 0.5;
const AM_STUECK_BRUECKE = 0.3;
const AM_STUECK_FAHRT = [[120, 1], [300, 2], [PHP_INT_MAX, 3]];   // bis Minuten Fahrt => Windtage
// Seit 13.11.2026 vormittags deutlicher benannt (Feld "Windtage am Stück", vorher "Am Stück")
const AM_STUECK_WAHL = ['auto' => 'je nach Fahrzeit', 'egal' => 'egal', '2' => 'mindestens 2', '3' => 'mindestens 3'];

/** 'wind', 'bruecke' oder null fuer einen Tag aus region_bewerten() */
function am_stueck_art(?array $d): ?string
{
    if (!$d || !empty($d['vorbei']) || !empty($d['offen'])) {
        return null;
    }
    $c = $d['ens']['chance'] ?? null;
    if ($c === null) {
        return !empty($d['det']['geht']) ? 'wind' : null;
    }
    return $c >= AM_STUECK_WINDTAG - 1e-9 ? 'wind' : ($c >= AM_STUECK_BRUECKE - 1e-9 ? 'bruecke' : null);
}

/** Noetige Windtage am Stueck fuer eine Fahrt (Eintrag 'fahrt' der Seite) */
function am_stueck_noetig(string $wahl, ?array $fahrt): int
{
    if ($wahl === 'egal') {
        return 1;
    }
    if ($wahl === '2' || $wahl === '3') {
        return (int)$wahl;
    }
    $min = $fahrt['min'] ?? null;
    if ($min === null || !empty($fahrt['flug'])) {
        return AM_STUECK_FAHRT[count(AM_STUECK_FAHRT) - 1][1];
    }
    $min = round($min / 5) * 5;   // wie die angezeigte Fahrzeit: "5:00 h" zaehlt als bis 5 h
    foreach (AM_STUECK_FAHRT as [$bis, $tage]) {
        if ($min <= $bis) {
            return $tage;
        }
    }
    return AM_STUECK_FAHRT[count(AM_STUECK_FAHRT) - 1][1];
}

/**
 * Beste Strecke am Stueck: ['wind' => Windtage, 'summe' => Summe der Chancen der Windtage,
 * 'von' => erster, 'bis' => letzter Tag, 'tage' => alle Tage der Strecke (mit Bruecken)].
 * Beste = meiste Windtage, dann hoehere Summe, dann frueher. Ohne Windtag wind = 0.
 */
function am_stueck(array $proTag, array $tage): array
{
    $best = ['wind' => 0, 'summe' => 0.0, 'von' => null, 'bis' => null, 'tage' => []];
    $cur = null;
    $arten = [];
    foreach ($tage as $i => $t) {
        $d = $proTag[$t] ?? null;
        $arten[$i] = am_stueck_art($d);
        if ($arten[$i] !== 'wind') {
            continue;
        }
        $wert = (float)($d['ens']['chance'] ?? AM_STUECK_WINDTAG);
        $anschluss = $cur && ($cur['letzter'] === $i - 1 || ($cur['letzter'] === $i - 2 && $arten[$i - 1] === 'bruecke'));
        if ($anschluss) {
            $cur['wind']++;
            $cur['summe'] += $wert;
            for ($k = $cur['letzter'] + 1; $k <= $i; $k++) {
                $cur['tage'][] = $tage[$k];
            }
            $cur['letzter'] = $i;
        } else {
            $cur = ['wind' => 1, 'summe' => $wert, 'tage' => [$t], 'letzter' => $i];
        }
        if ([$cur['wind'], $cur['summe']] > [$best['wind'], $best['summe']]) {
            $best = ['wind' => $cur['wind'], 'summe' => $cur['summe'], 'von' => $cur['tage'][0], 'bis' => $t, 'tage' => $cur['tage']];
        }
    }
    return $best;
}

/** Bewertung fuer eine Region: am_stueck() plus 'noetig' und 'lohnt' */
function am_stueck_bewerten(array $proTag, array $tage, string $wahl, ?array $fahrt): array
{
    $s = am_stueck($proTag, $tage);
    $s['noetig'] = am_stueck_noetig($wahl, $fahrt);
    $s['lohnt'] = $s['wind'] >= $s['noetig'];
    return $s;
}

/**
 * Nicht lohnende ans Ende, Reihenfolge sonst wie vorher (usort ist stabil). Bei $nachStrecke
 * (Sortierung "alle Tage") innerhalb der lohnenden zuerst nach der Strecke (Windtage, Summe).
 */
function am_stueck_sortieren(array $liste, bool $nachStrecke): array
{
    usort($liste, function (array $a, array $b) use ($nachStrecke): int {
        $ka = [$a['stueck']['lohnt'] ? 1 : 0];
        $kb = [$b['stueck']['lohnt'] ? 1 : 0];
        if ($nachStrecke && $a['stueck']['lohnt'] && $b['stueck']['lohnt']) {
            $ka = [1, $a['stueck']['wind'], round($a['stueck']['summe'], 3)];
            $kb = [1, $b['stueck']['wind'], round($b['stueck']['summe'], 3)];
        }
        return $kb <=> $ka;
    });
    return $liste;
}
