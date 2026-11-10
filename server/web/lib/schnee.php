<?php
/**
 * Schnee-Modus (seit 8.11.2026): Wo liegt in den naechsten Tagen frischer Schnee,
 * und wo wird es schoen? Fuer Freerider und Skitourengeher, nur Alpen. Seit 9.11.2026
 * ohne Unterscheidung der Sportarten: eine Regel (SCHNEE_REGEL) fuer alle.
 *
 * Braucht bewertung.php (Zeitreihen, Tagesfenster, Entfernung) und regionen.php.
 *
 * BEWERTUNG je Region und Tag (nicht je Stunde):
 *   Neuschnee  angezeigt je Tag: Summe der 24 h bis SCHNEE_MORGEN Uhr Ortszeit (wie die
 *              Messung am Morgen; die Tage lassen sich addieren). Die Regel (Pulvertag)
 *              nutzt die 72 h bis SCHNEE_MORGEN Uhr (Pulver vom Vortag zaehlt). Je Stunde:
 *              liegt die Schneefallgrenze unter der Tourhoehe, zaehlt der Niederschlag
 *              als Schnee (1 mm = SCHNEE_CM_JE_MM cm, wie Open-Meteo), sonst nichts.
 *              Schneefallgrenze = snowfall_height, sonst Nullgradgrenze minus
 *              SCHNEE_GRENZE_ABSTAND, sonst Temperatur auf Tourhoehe <= SCHNEE_TEMP_MAX.
 *              Ohne Niederschlag: snowfall von Open-Meteo.
 *   Wetter     im Tagesfenster SCHNEE_TAG: mittlere Bewoelkung, Wind am Kamm (700 hPa),
 *              hoechste Nullgradgrenze, Neuschnee am Tag.
 *   Schneehoehe Modellwert snow_depth um SCHNEE_MORGEN Uhr (im Gebirge grob).
 *   Spanne     Neuschnee (24 h) im ECMWF-Ensemble, 10. bis 90. Perzentil der Laeufe.
 *              Seit 9.11.2026 statt einer Chance in Prozent angezeigt: Die Prozent
 *              fassten mehrere Schwellen zusammen und sagten nicht, was fehlt.
 *   Pulvertag  schoener Tag (hoechstens teils sonnig, wie das Symbol ⛅) mit Neuschnee
 *              in 72 h ab SCHNEE_REGEL['neu_min']. Schneehoehe und Wind am Kamm schliessen
 *              nichts aus, sie stehen nur dabei (Wind ab SCHNEE_KAMM_HINWEIS als Hinweis).
 *   Rangliste  nach Prioritaet (seit 10.11.2026 waehlbar, SCHNEE_PRIOS):
 *              pulver  bester Pulvertag (Neuschnee 72 h an einem schoenen Tag), dann
 *                      Neuschnee im Zeitraum (wie bisher, Standard)
 *              neu     Neuschnee in Stufen (SCHNEE_NEU_STUFEN), innerhalb einer Stufe die
 *                      Sonne: sonst entschiede fast immer der erste Zentimeter
 *              km      Fahrzeit
 * Die Schwellen sind Startwerte ohne Quelle (SCHNEE_REGEL, SCHNEE_KAMM_HINWEIS).
 *
 * Die Lawinenlage geht NICHT in die Reihenfolge ein (siehe lawine.php). Seit 10.11.2026
 * steht sie ab Stufe 3 mit Warnzeichen bei der Region (lawine_warnung()), der Platz bleibt.
 *
 * UNGEPRUEFT gegen das echte Open-Meteo: wie der Parameter elevation (Tourhoehe) auf
 * Schneefall, Schneefallgrenze und Schneehoehe wirkt; welche Modelle snowfall_height
 * liefern; ob das Ensemble past_days, snow_depth und freezing_level_height liefert.
 */

require_once __DIR__ . '/stationen.php';

const SCHNEE_TAG = [8, 15];           // Ortsstunden [ab, bis): Wetter am Tourtag
const SCHNEE_MORGEN = 8;              // Neuschnee zaehlt bis zu dieser Ortsstunde
const SCHNEE_STUNDEN_NEU = 72;
const SCHNEE_CM_JE_MM = 0.7;          // Open-Meteo: snowfall in cm = Wasser in mm x 0,7
const SCHNEE_GRENZE_ABSTAND = 300;    // m: Schneefallgrenze rund 300 m unter der Nullgradgrenze (Faustwert)
const SCHNEE_TEMP_MAX = 1.0;          // °C auf Tourhoehe: darunter Schnee, wenn keine Grenze bekannt
const SCHNEE_MIN_STUNDEN = 60;        // so viele der 72 Stunden muessen Werte haben
const SCHNEE_MIN_STUNDEN_24 = 20;     // so viele der 24 Stunden muessen Werte haben
const SCHNEE_SONNIG = 25;             // % Bewoelkung: bis hier "sonnig"
const SCHNEE_WOLKIG = 60;             // % Bewoelkung: bis hier "teils sonnig"

/**
 * Pulvertag (seit 9.11.2026, vorher zwei Sportarten Freeride und Skitour mit eigenen
 * Schwellen): schoener Tag nach Neuschnee. Startwerte ohne Quelle, leicht zu aendern.
 * neu_min: Neuschnee in 72 h, dieselbe Grenze wie das mittlere Blau (schnee_klasse()).
 * wolken_max: Grenze "teils sonnig" (SCHNEE_WOLKIG), also wie das Wettersymbol.
 */
const SCHNEE_REGEL = ['name' => 'Pulvertag', 'neu_min' => 10, 'wolken_max' => SCHNEE_WOLKIG];
const SCHNEE_KAMM_HINWEIS = 50;       // km/h in 700 hPa: ab hier Hinweis "Wind am Kamm" (Startwert ohne Quelle)

const SCHNEE_FORMATE = ['json', 'xml'];

/** Prioritaeten der Rangliste (seit 10.11.2026), Schluessel = Parameter prio */
const SCHNEE_PRIOS = ['pulver' => 'Pulver + Sonne', 'neu' => 'Neuschnee', 'km' => 'Fahrzeit'];
/** cm Neuschnee: Stufengrenzen fuer die Prioritaet "neu" (Startwerte ohne Quelle) */
const SCHNEE_NEU_STUFEN = [5, 15, 30];

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
    $d['messnetze'] ??= [];
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
    return array_merge($f, stationen_pruefen($d));
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
 *   neu72, neu24 (cm bis SCHNEE_MORGEN Uhr, null bei zu wenigen Stunden), neu_tag (im Fenster), wolken, kamm, boe,
 *   nullgrad (hoechste im Fenster), grenze (tiefste Schneefallgrenze bei Niederschlag in 72 h),
 *   hs (Schneehoehe um SCHNEE_MORGEN), stunden (mit Werten im Fenster)
 */
function schnee_kennwerte(array $st, string $tag, array $fenster): array
{
    $summe = 0.0;
    $n = 0;
    $summe24 = 0.0;
    $n24 = 0;
    $grenze = null;
    for ($k = 3; $k >= 0; $k--) {
        $d = schnee_tag_minus($tag, $k);
        foreach ($st[$d] ?? [] as $h => $w) {
            if (($k === 3 && $h < SCHNEE_MORGEN) || ($k === 0 && $h >= SCHNEE_MORGEN)) {
                continue;
            }
            $summe += $w['neu'];
            $n++;
            if ($k <= 1 && !($k === 1 && $h < SCHNEE_MORGEN)) {
                $summe24 += $w['neu'];
                $n24++;
            }
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
        'neu24'    => $n24 >= SCHNEE_MIN_STUNDEN_24 ? round($summe24, 1) : null,
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

/** Ist der Tag ein Pulvertag? Rueckgabe [geht, grund|null] */
function schnee_tag_geht(array $k, array $regel = SCHNEE_REGEL): array
{
    if ($k['neu72'] === null) {
        return [false, 'Neuschnee unbekannt'];
    }
    if ($k['neu72'] < $regel['neu_min']) {
        return [false, 'zu wenig Neuschnee'];
    }
    if ($k['wolken'] !== null && $k['wolken'] > $regel['wolken_max']) {
        return [false, 'zu bewölkt'];
    }
    return [true, null];
}

/** Wind am Kamm so stark, dass er als Hinweis dabeisteht (schliesst nichts aus) */
function schnee_kamm_stark(array $k): bool
{
    return ($k['kamm'] ?? null) !== null && $k['kamm'] >= SCHNEE_KAMM_HINWEIS;
}

/**
 * Beide Regeln des Pulvertags einzeln, fuer die Stundenseite (seit 9.11.2026): damit man
 * sieht, was fehlt und wie viel, nicht nur den ersten Grund wie schnee_tag_geht().
 * Rueckgabe Liste von ['name', 'wert' (Text), 'soll' (Text), 'grund' (wenn nicht ok), 'ok' true|false|null (unbekannt)]
 */
function schnee_kriterien(array $k, array $regel = SCHNEE_REGEL): array
{
    $z = fn(?float $v) => $v === null ? '?' : number_format(round($v), 0, ',', '.');
    return [
        ['name' => 'Neuschnee 72 h', 'wert' => $z($k['neu72']) . ' cm', 'soll' => 'ab ' . $regel['neu_min'] . ' cm', 'grund' => 'zu wenig Neuschnee',
         'ok' => $k['neu72'] === null ? null : $k['neu72'] >= $regel['neu_min']],
        ['name' => 'Wolken ' . SCHNEE_TAG[0] . '–' . SCHNEE_TAG[1] . ' Uhr', 'wert' => $z($k['wolken']) . ' %', 'soll' => 'bis ' . $regel['wolken_max'] . ' % (⛅)', 'grund' => 'zu bewölkt',
         'ok' => $k['wolken'] === null ? null : $k['wolken'] <= $regel['wolken_max']],
    ];
}

/** Perzentil (0..1) einer Liste, linear zwischen den Werten; null bei leerer Liste */
function schnee_perzentil(array $w, float $p): ?float
{
    if (!$w) {
        return null;
    }
    sort($w);
    $i = $p * (count($w) - 1);
    $u = (int)floor($i);
    $o = min(count($w) - 1, $u + 1);
    return $w[$u] + ($w[$o] - $w[$u]) * ($i - $u);
}

/**
 * Farbe eines Tages in der Blau-Skala: s-go = Pulvertag (Modellmittel), sonst nach dem
 * Neuschnee (24 h): s-maybe ab 10 cm, s-no ab 3 cm, sonst nix
 */
function schnee_klasse(array $d): string
{
    if (!empty($d['vorbei']) || !empty($d['offen'])) {
        return 'leer';
    }
    if ($d['det']['geht']) {
        return 's-go';
    }
    $neu = $d['det']['neu24'] ?? null;
    return $neu === null ? 'nix' : ($neu >= 10 ? 's-maybe' : ($neu >= 3 ? 's-no' : 'nix'));
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
 * Rueckgabe [tag => ['fenster', 'rest', 'vorbei', 'offen', 'det' => kennwerte + geht/grund/pulver/kamm_stark,
 *            'ens' => ['chance' (nur noch intern), 'laeufe', 'neu24_p10', 'neu24_p50', 'neu24_p90'] | null]]
 */
function schnee_region_bewerten(array $region, array $daten, array $tage, ?DateTimeImmutable $jetzt = null): array
{
    $jetzt ??= new DateTimeImmutable('now');
    $tz = new DateTimeZone($region['zeitzone']);
    $st = schnee_stunden($daten['vorhersage'] ?? null, $tz, (int)$region['hoehe_tour']);
    $laeufe = schnee_ensemble_laeufe($daten['ensemble'] ?? null, $tz, (int)$region['hoehe_tour']);
    $out = [];
    foreach ($tage as $tag) {
        [$fenster, $rest, $vorbei] = restfenster(SCHNEE_TAG, $tag, $tz, $jetzt);
        $k = schnee_kennwerte($st, $tag, $fenster);
        [$geht, $grund] = schnee_tag_geht($k);
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
                $gut += schnee_tag_geht($kl)[0] ? 1 : 0;
                if ($kl['neu24'] !== null) {
                    $neu[] = $kl['neu24'];
                }
            }
            if ($n > 0) {
                $ens = ['chance' => $gut / $n, 'laeufe' => $n,
                        'neu24_p10' => schnee_perzentil($neu, 0.1), 'neu24_p50' => schnee_perzentil($neu, 0.5), 'neu24_p90' => schnee_perzentil($neu, 0.9)];
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
                               // Pulver: Neuschnee 72 h an einem Pulvertag, sonst 0 (fuer die Rangliste)
                               'pulver' => $geht && !$vorbei && !$offen ? (float)$k['neu72'] : 0.0,
                               'kamm_stark' => schnee_kamm_stark($k),
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
 * bewertet fuer $tage, sortiert nach dem besten Pulvertag, dann Neuschnee im Zeitraum.
 */
function schnee_rangliste(array $regionen, array $stand, array $heim, float $radiusKm, array $tage, ?DateTimeImmutable $jetzt = null): array
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
        $proTag = schnee_region_bewerten($r, $daten, $tage, $jetzt);
        $det = 0;
        $neu = 0.0;
        $pulver = 0.0;
        $schoen = 0;
        foreach ($proTag as $t) {
            $det += $t['det']['geht'] ? 1 : 0;
            $neu += (float)($t['det']['neu24'] ?? 0);
            $pulver = max($pulver, $t['det']['pulver']);
            if (empty($t['vorbei']) && empty($t['offen']) && $t['det']['wolken'] !== null && $t['det']['wolken'] <= SCHNEE_WOLKIG) {
                $schoen++;
            }
        }
        $liste[] = ['region' => $r, 'km' => round($km), 'tage' => $proTag, 'tage_modellmittel' => $det,
                    'pulver_max' => round($pulver, 1), 'neu_summe' => round($neu, 1), 'schoene_tage' => $schoen];
    }
    return schnee_sortieren($liste, 'summe');
}

/** Stufe des Neuschnees fuer die Prioritaet "neu": 0 unter 5 cm, 1 ab 5, 2 ab 15, 3 ab 30 */
function schnee_neu_stufe(?float $cm): int
{
    $s = 0;
    foreach (SCHNEE_NEU_STUFEN as $g) {
        $s += ($cm ?? 0) >= $g ? 1 : 0;
    }
    return $s;
}

/**
 * Rangliste sortieren. $nach: 'summe' (alle Tage) oder ein Tag (YYYY-MM-DD); 'km' bleibt
 * fuer alte Links gueltig und heisst dasselbe wie $prio 'km'.
 * $prio 'pulver': bester Pulvertag, dann Neuschnee im Zeitraum; an einem Tag Pulver, dann
 *   Neuschnee 72 h und 24 h. Pulver = Neuschnee 72 h an einem Pulvertag: 30 cm bei Sonne
 *   gehen vor 12 cm bei Sonne, beide vor 40 cm im Nebel.
 * $prio 'neu': Neuschnee in Stufen (schnee_neu_stufe()), in der Stufe zuerst die Sonne
 *   (alle Tage: Zahl der schoenen Tage; ein Tag: sonnig vor teils sonnig vor bewoelkt, wie
 *   das Symbol, nicht die einzelnen Prozent), dann die cm. 40 cm im
 *   Nebel gehen vor 30 cm bei Sonne, 18 cm bei Sonne vor 25 cm im Nebel.
 * $prio 'km': Fahrzeit.
 * Bei Gleichstand gewinnt die naehere Region. Die Lawinenlage geht nicht ein.
 */
function schnee_sortieren(array $liste, string $nach, string $prio = 'pulver'): array
{
    if ($nach === 'km' || $prio === 'km') {
        $k = fn($e) => isset($e['fahrt']) ? [$e['fahrt']['min'] === null ? 1 : 0, $e['fahrt']['min'] ?? 0, $e['km']] : [0, $e['km'], 0];
        usort($liste, fn($a, $b) => $k($a) <=> $k($b));
        return $liste;
    }
    $tag = $liste && isset($liste[0]['tage'][$nach]) ? $nach : null;
    $s = function (array $e) use ($tag, $prio): array {
        if ($prio === 'neu') {
            if ($tag === null) {
                return [schnee_neu_stufe($e['neu_summe']), $e['schoene_tage'] ?? 0, $e['neu_summe'], -$e['km']];
            }
            $d = $e['tage'][$tag]['det'];
            $sonne = $d['wolken'] === null ? 0 : ($d['wolken'] <= SCHNEE_SONNIG ? 2 : ($d['wolken'] <= SCHNEE_WOLKIG ? 1 : 0));
            return [schnee_neu_stufe($d['neu24'] ?? null), $sonne, $d['neu24'] ?? 0, $d['neu72'] ?? 0, -$e['km']];
        }
        if ($tag === null) {
            return [$e['pulver_max'], $e['neu_summe'], -$e['km']];
        }
        $t = $e['tage'][$tag];
        return [$t['det']['pulver'], $t['det']['neu72'] ?? 0, $t['det']['neu24'] ?? 0, -$e['km']];
    };
    usort($liste, fn($a, $b) => $s($b) <=> $s($a));
    return $liste;
}

/**
 * Wert fuer die Landkarte: Neuschnee (24 h) am Tag oder Summe im Zeitraum, ob ein Tag
 * ein Pulvertag ist, Klasse in der Blau-Skala und Rang fuer die Reihenfolge (gute oben)
 */
function schnee_karte_wert(array $e, ?string $tag): array
{
    $neu = null;
    $geht = false;
    $besterTag = null;
    $bestNeu = -1.0;
    foreach ($e['tage'] as $t => $d) {
        if (($tag !== null && $t !== $tag) || !empty($d['vorbei']) || !empty($d['offen'])) {
            continue;
        }
        if (($d['det']['neu24'] ?? null) !== null) {
            $neu = ($neu ?? 0.0) + $d['det']['neu24'];
        }
        // Ziel beim Antippen: bester Pulvertag, sonst der mit dem meisten Neuschnee
        $wert = $d['det']['geht'] ? 1000 + $d['det']['pulver'] : (float)($d['det']['neu24'] ?? 0);
        if ($wert > $bestNeu) {
            $besterTag = $t;
            $bestNeu = $wert;
        }
        $geht = $geht || $d['det']['geht'];
    }
    $klasse = $geht ? 's-go' : ($neu === null ? 'leer' : ($neu >= 10 ? 's-maybe' : ($neu >= 3 ? 's-no' : 'nix')));
    return ['rang' => $geht ? 1000 + $bestNeu : ($neu ?? -1), 'neu' => $neu === null ? null : round($neu, 1),
            'geht' => $geht, 'tag' => $besterTag, 'klasse' => $klasse];
}

// ===================================================================
// NEUSCHNEE-RASTER FUER DIE KARTE (seit 9.11.2026)
// ===================================================================
//
// Ein Gitter ueber die Alpen (SCHNEE_RASTER_SCHRITT Grad, rund 25 km), je Punkt eine
// Vorhersage von Open-Meteo (best_match, ohne Ensemble). Der Sammler rechnet daraus
// sofort den Neuschnee je Tag (24 h bis SCHNEE_MORGEN Uhr) auf SCHNEE_RASTER_HOEHE
// und speichert nur diese Zahlen, damit die Seite nichts entpacken muss.
// Feste Hoehe statt Gelaende: sonst zeigen Taeler Regen und Kaemme Schnee, und das Bild
// wird fleckig. Nur Punkte im groben Alpenumriss (SCHNEE_RASTER_UMRISS, von Hand nach
// dem Alpenbogen gezogen, nicht die amtliche Grenze der Alpenkonvention): ueber dem
// Flachland waere "Neuschnee auf 2.000 m" ohne Sinn.

const SCHNEE_RASTER_SCHRITT = 0.25;
const SCHNEE_RASTER_HOEHE = 2000;
const SCHNEE_RASTER_TZ = 'Europe/Berlin';    // alle Alpenlaender MEZ
const SCHNEE_RASTER_MAX_ALTER_H = 36;         // aelter: nicht mehr zeigen
const SCHNEE_RASTER_TEIL = 100;               // Punkte je Abruf
/** [Breite, Laenge], im Uhrzeigersinn ab Genf; grob, etwa 10-20 km genau */
const SCHNEE_RASTER_UMRISS = [
    [46.25, 6.10], [46.40, 6.75], [46.75, 7.55], [47.00, 8.25], [47.15, 8.90], [47.30, 9.50],
    [47.55, 10.20], [47.65, 11.10], [47.72, 11.70], [47.78, 12.20], [47.85, 13.00], [47.92, 13.80],
    [47.90, 14.50], [47.95, 15.40], [48.15, 16.05], [47.75, 16.30], [47.20, 15.70], [46.95, 15.20],
    [46.55, 15.40], [46.20, 14.40], [46.05, 13.40], [45.85, 12.30], [45.70, 11.30], [45.55, 10.60],
    [45.70, 9.60], [45.80, 9.00], [45.75, 8.40], [45.55, 7.85], [45.10, 7.30], [44.40, 7.45],
    [44.00, 7.65], [43.80, 7.25], [43.90, 6.40], [44.50, 5.75], [45.20, 5.60], [45.90, 5.95],
];

/** Liegt der Punkt im Polygon? (Strahlverfahren, Breite/Laenge als ebene Koordinaten) */
function schnee_im_umriss(float $lat, float $lon, array $poly = SCHNEE_RASTER_UMRISS): bool
{
    $innen = false;
    $n = count($poly);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$yi, $xi] = $poly[$i];
        [$yj, $xj] = $poly[$j];
        if (($yi > $lat) !== ($yj > $lat) && $lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
            $innen = !$innen;
        }
    }
    return $innen;
}

/** Gitterpunkte im Umriss als Pseudo-Regionen fuer den Sammler (typ "raster") */
function schnee_raster_punkte(): array
{
    $lats = array_column(SCHNEE_RASTER_UMRISS, 0);
    $lons = array_column(SCHNEE_RASTER_UMRISS, 1);
    $s = SCHNEE_RASTER_SCHRITT;
    $out = [];
    for ($lat = ceil(min($lats) / $s) * $s; $lat <= max($lats); $lat += $s) {
        for ($lon = ceil(min($lons) / $s) * $s; $lon <= max($lons); $lon += $s) {
            if (schnee_im_umriss($lat, $lon)) {
                $out[] = ['id' => sprintf('r%.2f_%.2f', $lat, $lon), 'typ' => 'raster', 'punkt' => [round($lat, 2), round($lon, 2)],
                          'hoehe_tour' => SCHNEE_RASTER_HOEHE];
            }
        }
    }
    return $out;
}

/**
 * Eintrag von Open-Meteo -> Neuschnee je Tag auf SCHNEE_RASTER_HOEHE.
 * Rueckgabe ['latitude', 'longitude' (angefragt), 'neu' => [YYYY-MM-DD => cm]] (Tage ohne genug Stunden fehlen)
 */
function schnee_raster_eintrag(array $e, array $punkt): array
{
    $st = schnee_stunden($e, new DateTimeZone(SCHNEE_RASTER_TZ), SCHNEE_RASTER_HOEHE);
    $neu = [];
    foreach (array_keys($st) as $tag) {
        $v = schnee_kennwerte($st, $tag, SCHNEE_TAG)['neu24'];
        if ($v !== null) {
            $neu[$tag] = round($v, 1);
        }
    }
    return ['latitude' => $punkt[0], 'longitude' => $punkt[1], 'neu' => $neu];
}

/**
 * Neuester Raster-Stand: ['ts' => neuester Zeitstempel, 'zellen' => [id => eintrag]] oder null.
 * Klein (wenige hundert Punkte mit je 9 Zahlen), deshalb ganze Zeilen entpacken.
 */
function schnee_raster_laden(string $dataDir, ?int $jetzt = null): ?array
{
    $jetzt ??= time();
    $dateien = [];
    foreach ([gmdate('Y-m-d', $jetzt), gmdate('Y-m-d', $jetzt - 86400)] as $d) {
        $dateien = array_merge($dateien, glob("$dataDir/schnee_raster/$d.ndjson") ?: [], glob("$dataDir/schnee_raster/{$d}_*.ndjson.gz") ?: []);
    }
    rsort($dateien);
    $zellen = [];
    $ts = null;
    foreach ($dateien as $f) {
        $txt = (string)@file_get_contents($f);
        if (str_ends_with($f, '.gz')) {
            $txt = (string)@gzdecode($txt);
        }
        $zeilen = array_reverse(array_values(array_filter(array_map('trim', explode("\n", $txt)))));
        foreach ($zeilen as $z) {
            $j = json_decode($z, true);
            if (!is_array($j) || empty($j['ok']) || !isset($j['regionen'], $j['payload']) || strtotime((string)$j['ts']) < $jetzt - 3600 * SCHNEE_RASTER_MAX_ALTER_H) {
                continue;
            }
            foreach ($j['regionen'] as $k => $id) {
                if (!isset($zellen[$id]) && isset($j['payload'][$k]['neu'])) {
                    $zellen[$id] = $j['payload'][$k];
                    $ts = max($ts ?? '', (string)$j['ts']);
                }
            }
        }
    }
    return $zellen ? ['ts' => $ts, 'zellen' => $zellen] : null;
}
