<?php
/**
 * Schnee-Modus (seit 8.11.2026): Wo liegt in den naechsten Tagen frischer Schnee,
 * und wo wird es schoen? Fuer Freerider und Skitourengeher, nur Alpen.
 *
 * Braucht bewertung.php (Zeitreihen, Tagesfenster, Entfernung) und regionen.php.
 *
 * BEWERTUNG je Region und Tag (nicht je Stunde):
 *   Neuschnee  Summe der letzten 72 h bis SCHNEE_MORGEN Uhr Ortszeit. Je Stunde:
 *              liegt die Schneefallgrenze unter der Tourhoehe, zaehlt der Niederschlag
 *              als Schnee (1 mm = SCHNEE_CM_JE_MM cm, wie Open-Meteo), sonst nichts.
 *              Schneefallgrenze = snowfall_height, sonst Nullgradgrenze minus
 *              SCHNEE_GRENZE_ABSTAND, sonst Temperatur auf Tourhoehe <= SCHNEE_TEMP_MAX.
 *              Ohne Niederschlag: snowfall von Open-Meteo.
 *   Wetter     im Tagesfenster SCHNEE_TAG: mittlere Bewoelkung, Wind am Kamm (700 hPa),
 *              hoechste Nullgradgrenze, Neuschnee am Tag.
 *   Schneehoehe Modellwert snow_depth um SCHNEE_MORGEN Uhr (im Gebirge grob).
 *   Chance     Anteil der Laeufe des ECMWF-Ensembles, an denen der Tag nach denselben
 *              Regeln geht, ohne Wind am Kamm (ungeprueft, ob das Ensemble ihn liefert).
 * Schwellen der Sportarten sind Startwerte ohne Quelle (SCHNEE_SPORTARTEN).
 *
 * Die Lawinenlage geht NICHT ein (siehe lawine.php).
 *
 * UNGEPRUEFT gegen das echte Open-Meteo: wie der Parameter elevation (Tourhoehe) auf
 * Schneefall, Schneefallgrenze und Schneehoehe wirkt; welche Modelle snowfall_height
 * liefern; ob das Ensemble past_days, snow_depth und freezing_level_height liefert.
 */

const SCHNEE_TAG = [8, 15];           // Ortsstunden [ab, bis): Wetter am Tourtag
const SCHNEE_MORGEN = 8;              // Neuschnee zaehlt bis zu dieser Ortsstunde
const SCHNEE_STUNDEN_NEU = 72;
const SCHNEE_CM_JE_MM = 0.7;          // Open-Meteo: snowfall in cm = Wasser in mm x 0,7
const SCHNEE_GRENZE_ABSTAND = 300;    // m: Schneefallgrenze rund 300 m unter der Nullgradgrenze (Faustwert)
const SCHNEE_TEMP_MAX = 1.0;          // °C auf Tourhoehe: darunter Schnee, wenn keine Grenze bekannt
const SCHNEE_MIN_STUNDEN = 60;        // so viele der 72 Stunden muessen Werte haben
const SCHNEE_SONNIG = 25;             // % Bewoelkung: bis hier "sonnig"
const SCHNEE_WOLKIG = 60;             // % Bewoelkung: bis hier "teils sonnig"

/** Startwerte ohne Quelle (Konzept 8.11.2026); leicht zu aendern */
const SCHNEE_SPORTARTEN = [
    'freeride' => ['name' => 'Freeride', 'gruppe' => 'schnee', 'neu_min' => 15, 'wolken_max' => 40, 'kamm_max' => 30],
    'skitour'  => ['name' => 'Skitour', 'gruppe' => 'schnee', 'hoehe_min' => 30, 'wolken_max' => 50, 'kamm_max' => 40],
];

const SCHNEE_FORMATE = ['json', 'xml'];

// ===================================================================
// KATALOG
// ===================================================================

/** Schnee-Katalog laden und pruefen; jede Region bekommt typ "schnee" (fuer den Sammler) */
function schnee_katalog_laden(string $datei): array
{
    $txt = @file_get_contents($datei);
    if ($txt === false) {
        throw new RuntimeException("Schnee-Katalog fehlt: $datei");
    }
    $d = json_decode($txt, true);
    if (!is_array($d) || !isset($d['regionen']) || !is_array($d['regionen'])) {
        throw new RuntimeException("Schnee-Katalog ist kein gueltiges JSON mit 'regionen': $datei");
    }
    $fehler = schneeregionen_pruefen($d);
    if ($fehler) {
        throw new RuntimeException("Schnee-Katalog fehlerhaft:\n- " . implode("\n- ", $fehler));
    }
    foreach ($d['regionen'] as $i => $r) {
        $d['regionen'][$i]['typ'] = 'schnee';
    }
    $d['lawinendienste'] ??= [];
    return $d;
}

/** Liste von Fehlermeldungen, leer = in Ordnung */
function schneeregionen_pruefen(array $d): array
{
    $f = [];
    $dienste = $d['lawinendienste'] ?? [];
    if (!is_array($dienste)) {
        $f[] = 'lawinendienste muss ein Objekt sein';
        $dienste = [];
    }
    foreach ($dienste as $id => $l) {
        $w = "Lawinendienst $id";
        if (!preg_match('/^[a-z0-9-]+$/', (string)$id)) {
            $f[] = "$w: id nur a-z, 0-9, -";
        }
        foreach (['name', 'kurz', 'lizenz'] as $k) {
            if (!is_string($l[$k] ?? null) || $l[$k] === '') {
                $f[] = "$w: $k fehlt";
            }
        }
        foreach (['url_bericht', 'abruf'] as $k) {
            if (!is_string($l[$k] ?? null) || !str_starts_with($l[$k], 'https://')) {
                $f[] = "$w: $k muss mit https:// beginnen";
            }
        }
        if (!in_array($l['format'] ?? null, SCHNEE_FORMATE, true)) {
            $f[] = "$w: format json oder xml";
        }
        foreach (['praefixe', 'freigegeben'] as $k) {
            if (!is_array($l[$k] ?? null) || !array_is_list($l[$k]) || array_filter($l[$k], fn($p) => !is_string($p) || $p === '')) {
                $f[] = "$w: $k muss eine Liste von Texten sein";
            }
        }
        foreach ((array)($l['freigegeben'] ?? []) as $p) {
            if (is_string($p) && !lawine_praefix_von($p, (array)($l['praefixe'] ?? []))) {
                $f[] = "$w: freigegeben $p gehört zu keinem Präfix";
            }
        }
    }
    $ids = [];
    foreach ($d['regionen'] ?? [] as $i => $r) {
        $wo = 'Schneeregion ' . ($r['id'] ?? "#$i");
        if (!is_string($r['id'] ?? null) || !preg_match('/^[a-z0-9-]+$/', $r['id'])) {
            $f[] = "$wo: id fehlt oder enthaelt andere Zeichen als a-z, 0-9, -";
        } elseif (isset($ids[$r['id']])) {
            $f[] = "$wo: id doppelt";
        } else {
            $ids[$r['id']] = true;
        }
        if (!is_string($r['name'] ?? null) || $r['name'] === '') {
            $f[] = "$wo: name fehlt";
        }
        if (!punkt_ok($r['punkt'] ?? null)) {
            $f[] = "$wo: punkt muss [Breite, Laenge] sein";
        }
        if (isset($r['anfahrt']) && !punkt_ok($r['anfahrt'])) {
            $f[] = "$wo: anfahrt muss [Breite, Laenge] sein";
        }
        $ht = $r['hoehe_tour'] ?? null;
        $hv = $r['hoehe_tal'] ?? null;
        if (!is_int($ht) || $ht < 500 || $ht > 4500) {
            $f[] = "$wo: hoehe_tour in m (500–4500)";
        }
        if (!is_int($hv) || $hv < 0 || (is_int($ht) && $hv >= $ht)) {
            $f[] = "$wo: hoehe_tal in m, unter hoehe_tour";
        }
        try {
            new DateTimeZone((string)($r['zeitzone'] ?? ''));
        } catch (Throwable $e) {
            $f[] = "$wo: zeitzone ungueltig";
        }
        if (!isset($dienste[$r['warndienst'] ?? ''])) {
            $f[] = "$wo: warndienst fehlt oder steht nicht in lawinendienste";
        }
        foreach (['eaws', 'eaws_namen'] as $k) {
            if (isset($r[$k]) && (!is_array($r[$k]) || !array_is_list($r[$k]) || array_filter($r[$k], fn($x) => !is_string($x) || $x === ''))) {
                $f[] = "$wo: $k muss eine Liste von Texten sein";
            }
        }
        $dienst = $dienste[$r['warndienst'] ?? ''] ?? null;
        foreach ($r['eaws'] ?? [] as $e) {
            if ($dienst && is_string($e) && !lawine_praefix_von($e, (array)($dienst['praefixe'] ?? []))) {
                $f[] = "$wo: eaws $e gehört nicht zum Warndienst {$r['warndienst']}";
            }
        }
        if (empty($r['eaws']) && empty($r['eaws_namen'])) {
            $f[] = "$wo: eaws oder eaws_namen angeben";
        }
        foreach ($r['links'] ?? [] as $l) {
            if (!is_string($l['name'] ?? null) || !is_string($l['url'] ?? null) || !str_starts_with($l['url'], 'https://')) {
                $f[] = "$wo: links brauchen name und https-url";
            }
        }
    }
    return $f;
}

/** Beginnt $id mit einem der Praefixe? (wie lawine_praefix, ohne lawine.php zu brauchen) */
function lawine_praefix_von(string $id, array $praefixe): bool
{
    foreach ($praefixe as $p) {
        if (is_string($p) && $p !== '' && str_starts_with($id, $p)) {
            return true;
        }
    }
    return false;
}

// ===================================================================
// STUNDEN
// ===================================================================

/** Schneefallgrenze einer Stunde: snowfall_height, sonst Nullgradgrenze minus Abstand, sonst null */
function schnee_grenze(?float $schneefallhoehe, ?float $nullgrad): ?float
{
    if ($schneefallhoehe !== null) {
        return $schneefallhoehe;
    }
    return $nullgrad !== null ? $nullgrad - SCHNEE_GRENZE_ABSTAND : null;
}

/** Neuschnee einer Stunde auf Tourhoehe in cm */
function schnee_neu_stunde(?float $niederschlag, ?float $schneefall, ?float $grenze, ?float $temp, int $hoehe): float
{
    if ($niederschlag !== null && $niederschlag > 0) {
        $schnee = $grenze !== null ? $grenze < $hoehe : ($temp !== null ? $temp <= SCHNEE_TEMP_MAX : null);
        if ($schnee !== null) {
            return $schnee ? $niederschlag * SCHNEE_CM_JE_MM : 0.0;
        }
    }
    return max(0.0, (float)($schneefall ?? 0.0));
}

/**
 * Stundenwerte (Modellmittel) einer Schnee-Region aus schnee_vorhersage.
 * Rueckgabe [tag => [stunde => ['neu' cm, 'nied' mm, 'grenze' m, 'nullgrad' m, 'temp', 'wolken' %,
 *            'kamm' km/h, 'boe' km/h, 'hs' cm, 'wetter' WMO]]]
 */
function schnee_stunden(?array $eintrag, DateTimeZone $tz, int $hoehe): array
{
    if (!$eintrag) {
        return [];
    }
    $m = fn(string $v) => mittel_stunden($eintrag, $v, $tz);
    $nied = $m('precipitation');
    // modell_reihen() nimmt alles, was mit "snowfall_" beginnt, also auch snowfall_height_<modell>
    $ohneHoehe = ['hourly' => array_filter($eintrag['hourly'], fn($k) => !str_starts_with($k, 'snowfall_height'), ARRAY_FILTER_USE_KEY)];
    $sf = mittel_stunden($ohneHoehe, 'snowfall', $tz);
    $sfh = $m('snowfall_height');
    $ng = $m('freezing_level_height');
    $tmp = $m('temperature_2m');
    $wol = $m('cloud_cover');
    $kamm = $m('wind_speed_700hPa');
    $boe = $m('wind_gusts_10m');
    $hs = $m('snow_depth');
    $wc = $m('weather_code');
    $out = [];
    $tage = array_unique(array_merge(array_keys($nied), array_keys($sf), array_keys($wol)));
    sort($tage);
    foreach ($tage as $tag) {
        foreach (array_unique(array_merge(array_keys($nied[$tag] ?? []), array_keys($sf[$tag] ?? []), array_keys($wol[$tag] ?? []))) as $h) {
            $g = schnee_grenze($sfh[$tag][$h] ?? null, $ng[$tag][$h] ?? null);
            $out[$tag][$h] = [
                'neu'      => schnee_neu_stunde($nied[$tag][$h] ?? null, $sf[$tag][$h] ?? null, $g, $tmp[$tag][$h] ?? null, $hoehe),
                'nied'     => $nied[$tag][$h] ?? null,
                'grenze'   => $g,
                'nullgrad' => $ng[$tag][$h] ?? null,
                'temp'     => $tmp[$tag][$h] ?? null,
                'wolken'   => $wol[$tag][$h] ?? null,
                'kamm'     => $kamm[$tag][$h] ?? null,
                'boe'      => $boe[$tag][$h] ?? null,
                'hs'       => isset($hs[$tag][$h]) ? $hs[$tag][$h] * 100 : null,   // m -> cm
                'wetter'   => isset($wc[$tag][$h]) ? (int)round($wc[$tag][$h]) : null,
            ];
        }
        ksort($out[$tag]);
    }
    return $out;
}

/** Ensemble: je Lauf dieselben Stunden (neu, wolken, hs) */
function schnee_ensemble_laeufe(?array $eintrag, DateTimeZone $tz, int $hoehe): array
{
    if (!$eintrag) {
        return [];
    }
    $r = fn(string $v) => ensemble_reihe($eintrag, $v, $tz);
    $nied = $r('precipitation');
    $sf = $r('snowfall');
    $ng = $r('freezing_level_height');
    $tmp = $r('temperature_2m');
    $wol = $r('cloud_cover');
    $hs = $r('snow_depth');
    $out = [];
    foreach (array_unique(array_merge(array_keys($nied), array_keys($sf))) as $lauf) {
        foreach (($nied[$lauf] ?? []) + ($sf[$lauf] ?? []) as $tag => $_) {
            $stunden = ($nied[$lauf][$tag] ?? []) + ($sf[$lauf][$tag] ?? []);
            foreach ($stunden as $h => $__) {
                $g = schnee_grenze(null, $ng[$lauf][$tag][$h] ?? null);
                $out[$lauf][$tag][$h] = [
                    'neu'    => schnee_neu_stunde($nied[$lauf][$tag][$h] ?? null, $sf[$lauf][$tag][$h] ?? null, $g, $tmp[$lauf][$tag][$h] ?? null, $hoehe),
                    'wolken' => $wol[$lauf][$tag][$h] ?? null,
                    'hs'     => isset($hs[$lauf][$tag][$h]) ? $hs[$lauf][$tag][$h] * 100 : null,
                    'kamm'   => null,
                ];
            }
        }
    }
    return $out;
}

// ===================================================================
// TAG
// ===================================================================

/** Ortstag minus $n Tage */
function schnee_tag_minus(string $tag, int $n): string
{
    return (new DateTimeImmutable($tag))->modify("-$n day")->format('Y-m-d');
}

/**
 * Kennwerte eines Tages aus Stundenwerten ([tag => [stunde => [...]]]):
 *   neu72 (cm, null bei zu wenigen Stunden), neu_tag (im Fenster), wolken, kamm, boe,
 *   nullgrad (hoechste im Fenster), grenze (tiefste Schneefallgrenze bei Niederschlag in 72 h),
 *   hs (Schneehoehe um SCHNEE_MORGEN), stunden (mit Werten im Fenster)
 */
function schnee_kennwerte(array $st, string $tag, array $fenster): array
{
    $summe = 0.0;
    $n = 0;
    $grenze = null;
    for ($k = 3; $k >= 0; $k--) {
        $d = schnee_tag_minus($tag, $k);
        foreach ($st[$d] ?? [] as $h => $w) {
            if (($k === 3 && $h < SCHNEE_MORGEN) || ($k === 0 && $h >= SCHNEE_MORGEN)) {
                continue;
            }
            $summe += $w['neu'];
            $n++;
            if (($w['nied'] ?? 0) > 0.2 && ($w['grenze'] ?? null) !== null) {
                $grenze = $grenze === null ? $w['grenze'] : min($grenze, $w['grenze']);
            }
        }
    }
    [$ab, $bis] = $fenster;
    $im = array_filter($st[$tag] ?? [], fn($h) => $h >= $ab && $h < $bis, ARRAY_FILTER_USE_KEY);
    $mittel = function (string $k) use ($im): ?float {
        $v = array_filter(array_column($im, $k), fn($x) => $x !== null);
        return $v ? array_sum($v) / count($v) : null;
    };
    $maxi = function (string $k) use ($im): ?float {
        $v = array_filter(array_column($im, $k), fn($x) => $x !== null);
        return $v ? max($v) : null;
    };
    $hs = $st[$tag][SCHNEE_MORGEN]['hs'] ?? null;
    if ($hs === null) {
        foreach ($im as $w) {
            if (($w['hs'] ?? null) !== null) {
                $hs = $w['hs'];
                break;
            }
        }
    }
    return [
        'neu72'    => $n >= SCHNEE_MIN_STUNDEN ? round($summe, 1) : null,
        'neu_tag'  => $im ? round(array_sum(array_column($im, 'neu')), 1) : null,
        'wolken'   => $mittel('wolken'),
        'kamm'     => $mittel('kamm'),
        'boe'      => $maxi('boe'),
        'nullgrad' => $maxi('nullgrad'),
        'grenze'   => $grenze,
        'hs'       => $hs,
        'stunden'  => count($im),
    ];
}

/** Geht der Tag fuer die Sportart? Rueckgabe [geht, grund|null] */
function schnee_tag_geht(array $k, array $sport): array
{
    if (isset($sport['neu_min'])) {
        if ($k['neu72'] === null) {
            return [false, 'Neuschnee unbekannt'];
        }
        if ($k['neu72'] < $sport['neu_min']) {
            return [false, 'zu wenig Neuschnee'];
        }
    }
    if (isset($sport['hoehe_min'])) {
        if ($k['hs'] === null) {
            return [false, 'Schneehöhe unbekannt'];
        }
        if ($k['hs'] < $sport['hoehe_min']) {
            return [false, 'zu wenig Schnee'];
        }
    }
    if (isset($sport['wolken_max']) && $k['wolken'] !== null && $k['wolken'] > $sport['wolken_max']) {
        return [false, 'zu bewölkt'];
    }
    if (isset($sport['kamm_max']) && $k['kamm'] !== null && $k['kamm'] > $sport['kamm_max']) {
        return [false, 'zu viel Wind am Kamm'];
    }
    return [true, null];
}

/** "sonnig", "teils sonnig", "bewölkt" oder null */
function schnee_himmel(?float $wolken): ?string
{
    if ($wolken === null) {
        return null;
    }
    return $wolken <= SCHNEE_SONNIG ? 'sonnig' : ($wolken <= SCHNEE_WOLKIG ? 'teils sonnig' : 'bewölkt');
}

/**
 * Bewertung einer Schnee-Region fuer $tage.
 * $daten: ['vorhersage' => eintrag|null, 'ensemble' => eintrag|null]
 * Rueckgabe [tag => ['fenster', 'rest', 'vorbei', 'offen', 'det' => kennwerte + geht/grund,
 *            'ens' => ['chance', 'laeufe', 'neu72_median'] | null]]
 */
function schnee_region_bewerten(array $region, array $daten, array $sport, array $tage, ?DateTimeImmutable $jetzt = null): array
{
    $jetzt ??= new DateTimeImmutable('now');
    $tz = new DateTimeZone($region['zeitzone']);
    $st = schnee_stunden($daten['vorhersage'] ?? null, $tz, (int)$region['hoehe_tour']);
    $laeufe = schnee_ensemble_laeufe($daten['ensemble'] ?? null, $tz, (int)$region['hoehe_tour']);
    $out = [];
    foreach ($tage as $tag) {
        [$fenster, $rest, $vorbei] = restfenster(SCHNEE_TAG, $tag, $tz, $jetzt);
        $k = schnee_kennwerte($st, $tag, $fenster);
        [$geht, $grund] = schnee_tag_geht($k, $sport);
        $offen = $k['stunden'] === 0 && !$vorbei;
        $ens = null;
        if ($laeufe && !$vorbei) {
            $gut = 0;
            $n = 0;
            $neu = [];
            foreach ($laeufe as $lauf) {
                $kl = schnee_kennwerte($lauf, $tag, $fenster);
                if ($kl['stunden'] === 0) {
                    continue;
                }
                $n++;
                $gut += schnee_tag_geht($kl, $sport)[0] ? 1 : 0;
                if ($kl['neu72'] !== null) {
                    $neu[] = $kl['neu72'];
                }
            }
            if ($n > 0) {
                sort($neu);
                $ens = ['chance' => $gut / $n, 'laeufe' => $n, 'neu72_median' => $neu ? $neu[intdiv(count($neu), 2)] : null];
            }
        }
        if ($offen) {
            $grund = 'noch keine Vorhersage';
        }
        $out[$tag] = [
            'fenster' => $fenster,
            'rest'    => $rest,
            'vorbei'  => $vorbei,
            'offen'   => $offen && $ens === null,
            'det'     => $k + ['geht' => $geht && !$vorbei && !$offen, 'grund' => $vorbei ? 'vorbei' : $grund,
                               'von' => $fenster[0], 'bis' => $fenster[1]],
            'ens'     => $ens,
        ];
    }
    return $out;
}

// ===================================================================
// RANGLISTE
// ===================================================================

/**
 * Schnee-Regionen im Umkreis (Luftlinie; die Seite filtert danach nach Strasse),
 * bewertet fuer $tage, sortiert nach erwarteten guten Tagen.
 */
function schnee_rangliste(array $regionen, array $stand, array $sport, array $heim, float $radiusKm, array $tage, ?DateTimeImmutable $jetzt = null): array
{
    $liste = [];
    foreach ($regionen as $r) {
        $km = entfernung_km($heim, $r['punkt']);
        if ($km > $radiusKm) {
            continue;
        }
        $daten = ['vorhersage' => $stand['schnee_vorhersage'][$r['id']] ?? null, 'ensemble' => $stand['schnee_ensemble'][$r['id']] ?? null];
        if (!$daten['vorhersage'] && !$daten['ensemble']) {
            continue;
        }
        $proTag = schnee_region_bewerten($r, $daten, $sport, $tage, $jetzt);
        $erwartet = null;
        $det = 0;
        $neu = 0.0;
        foreach ($proTag as $t) {
            if ($t['ens'] !== null) {
                $erwartet = ($erwartet ?? 0) + $t['ens']['chance'];
            }
            $det += $t['det']['geht'] ? 1 : 0;
            $neu = max($neu, (float)($t['det']['neu72'] ?? 0));
        }
        $liste[] = ['region' => $r, 'km' => round($km), 'tage' => $proTag, 'erwartete_tage' => $erwartet,
                    'tage_modellmittel' => $det, 'neu_max' => $neu];
    }
    return schnee_sortieren($liste, 'summe');
}

/** 'summe' (erwartete gute Tage, dann Neuschnee), ein Tag (Chance, dann Neuschnee) oder 'km' (Fahrzeit) */
function schnee_sortieren(array $liste, string $nach): array
{
    if ($nach === 'km') {
        $k = fn($e) => isset($e['fahrt']) ? [$e['fahrt']['min'] === null ? 1 : 0, $e['fahrt']['min'] ?? 0, $e['km']] : [0, $e['km'], 0];
        usort($liste, fn($a, $b) => $k($a) <=> $k($b));
        return $liste;
    }
    $tag = $liste && isset($liste[0]['tage'][$nach]) ? $nach : null;
    $s = function (array $e) use ($tag): array {
        if ($tag === null) {
            return [$e['erwartete_tage'] ?? -1, $e['tage_modellmittel'], $e['neu_max'], -$e['km']];
        }
        $t = $e['tage'][$tag];
        return [$t['ens']['chance'] ?? -1, $t['det']['geht'] ? 1 : 0, $t['det']['neu72'] ?? 0, -$e['km']];
    };
    usort($liste, fn($a, $b) => $s($b) <=> $s($a));
    return $liste;
}

/** Wert fuer die Landkarte: Chance am Tag oder beste Chance im Zeitraum, mit Neuschnee */
function schnee_karte_wert(array $e, ?string $tag): array
{
    $best = null;
    foreach ($e['tage'] as $t => $d) {
        if ($tag !== null && $t !== $tag) {
            continue;
        }
        if (!empty($d['vorbei']) || !empty($d['offen'])) {
            continue;
        }
        $c = $d['ens']['chance'] ?? null;
        $rang = $c ?? ($d['det']['geht'] ? 0.5 : -1.0);
        if ($best === null || $rang > $best['rang']) {
            $best = ['rang' => $rang, 'chance' => $c, 'geht' => $d['det']['geht'], 'tag' => $t, 'neu' => $d['det']['neu72']];
        }
    }
    return $best ?? ['rang' => -1.0, 'chance' => null, 'geht' => false, 'tag' => null, 'neu' => null];
}
