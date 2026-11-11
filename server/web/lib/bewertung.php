<?php
/**
 * Bewertung: welche Region im Umkreis passt an welchem Tag zur Sportart?
 *
 * Grundsatz: Die Bedingungen der Person (Sportart, Windfenster) und die des
 * Spots (Windrichtungen, ablandig) sind getrennt. Die Vorhersage kommt einmal
 * pro Region, bewertet wird pro Spot; die Region bekommt den Wert ihres
 * besten Spots.
 *
 * Eine Stunde ist fahrbar, wenn
 *   - sie im Tagesfenster liegt (ab 9 Uhr Ortszeit bis Sonnenuntergang),
 *   - der Wind im Fenster der Sportart liegt (min, optional max),
 *   - die Boeen nicht ueber boe_max liegen (falls gesetzt),
 *   - die Richtung in einer Windlage des Spots liegt (leere Liste = kein
 *     Richtungsfilter, Ergebnis als "grob" markiert),
 *   - der Wind nicht ablandig ist,
 *   - kein Gewitter gerechnet wird (mindestens die Haelfte der Modelle WMO 95-99),
 *   - die Welle passt (nur wenn die Sportart welle_min/welle_max hat).
 *
 * WELLENREITEN (Sportart mit 'surf', seit 7.11.2026) prueft statt des Windes
 * die Duenung: Hoehe ab welle_min, Periode ab periode_min, Richtung nicht aus
 * einem ablandigen Sektor des Spots (vom Land kommt keine Welle), Wind hoechstens
 * wind_max oder ablandig (dann gut), kein Gewitter. Duenung = Swell-Werte der
 * Marine API, ohne Swell die gesamte Welle. Keine Ensemble-Chance (das Ensemble
 * hat keine Welle).
 * Ein Tag geht, wenn mindestens MIN_STUNDEN fahrbare Stunden am Stueck kommen
 * (wie das beste 2-h-Fenster in stegen-wind).
 *
 * REGEL-WINDLAGEN (seit 7.11.2026, fuer Thermik- und Foehnlagen): Eine Windlage
 * mit druck und/oder grundstroemung ersetzt den Modellwind am See. Sind alle
 * ihre Bedingungen erfuellt (Druckdifferenzen, Grundstroemung, wetter, zeit,
 * saison), gilt die Stunde mit der typischen Staerke der Windlage (Mitte von
 * staerke) und der Mitte ihres Sektors; danach dieselben Pruefungen wie sonst
 * (Windfenster der Sportart, ablandig, Gewitter). Boeen gibt es dafuer keine.
 * Im Ensemble wird jeder Lauf mit seinen eigenen Druck- und Hoehenwerten geprueft.
 *
 * Die Chance ist der Anteil der Laeufe des ECMWF-Ensembles, an denen der Tag
 * nach denselben Regeln geht - ohne Boeen und Gewitter, weil das Ensemble nur
 * Wind und Richtung liefert; die Welle (Wave-Sportarten) kommt fuer alle Laeufe
 * aus der Marine-Vorhersage. ROHWERTE, NICHT KALIBRIERT.
 */

const MIN_STUNDEN = 2;
const FENSTER_AB = 9;          // Ortszeit, wie stegen-wind seit 22.10.2026
const REST_VORLAUF_MIN = 30;   // heute: erst ab jetzt + 30 min (Anfahrt, Aufbau), wie stegen-wind

/** Voreinstellungen wie in stegen-wind (sport.php): nur 14 kn Directional ist dort kalibriert */
const SPORTARTEN = [
    'kite_directional' => ['name' => 'Kite Directional', 'gruppe' => 'kite', 'min' => 14, 'ammerseewind' => 'directional'],
    'kite_twintip'     => ['name' => 'Kite Twintip', 'gruppe' => 'kite', 'min' => 14, 'ammerseewind' => 'twintip'],
    'kitefoil'         => ['name' => 'Kitefoil', 'gruppe' => 'kite', 'min' => 11, 'ammerseewind' => 'kitefoil'],
    'wingfoil'         => ['name' => 'Wingfoil', 'gruppe' => 'wing', 'min' => 16, 'ammerseewind' => 'wing'],
    'windsurf'         => ['name' => 'Windsurf', 'gruppe' => 'windsurf', 'min' => 17, 'ammerseewind' => 'windsurf'],
    'windsurf_foil'    => ['name' => 'Windsurf Foil', 'gruppe' => 'windsurf', 'min' => 12, 'ammerseewind' => 'windsurffoil'],
    // Seit 7.11.2026: Wave-Varianten mit Mindestwelle, Wellenreiten ohne Mindestwind (Faustwerte, nicht kalibriert)
    'kite_wave'        => ['name' => 'Kite Wave', 'gruppe' => 'kite', 'min' => 14, 'welle_min' => 1.0],
    'windsurf_wave'    => ['name' => 'Windsurf Wave', 'gruppe' => 'windsurf', 'min' => 17, 'welle_min' => 1.0],
    'wellenreiten'     => ['name' => 'Wellenreiten', 'gruppe' => 'surf', 'min' => 0, 'surf' => true, 'welle_min' => 0.8, 'periode_min' => 8, 'wind_max' => 12],
    'segeln'           => ['name' => 'Segeln', 'gruppe' => 'segeln', 'min' => 5, 'max' => 18, 'ammerseewind' => 'segeln'],
];

// ===================================================================
// RICHTUNG
// ===================================================================

/** Liegt die Richtung im Sektor [von, bis)? Im Uhrzeigersinn, [330, 30] ueber Norden */
function winkel_in_sektor(float $g, array $s): bool
{
    $g = fmod(fmod($g, 360) + 360, 360);
    [$von, $bis] = [fmod((float)$s[0], 360), fmod((float)$s[1], 360)];
    return $von < $bis ? ($g >= $von && $g < $bis) : ($g >= $von || $g < $bis);
}

/** Windlagen eines Spots: eigene, wenn angegeben, sonst die der Region */
function spot_windlagen(array $region, ?array $spot): array
{
    if ($spot !== null && array_key_exists('windlagen', $spot)) {
        return $spot['windlagen'];
    }
    return $region['windlagen'] ?? [];
}

/**
 * Passende Windlage oder null. Ohne Windlagen gilt jede Richtung,
 * Rueckgabe dann ['name' => null, 'grob' => true].
 */
function windlage_fuer(?float $richtung, array $windlagen): ?array
{
    if (!$windlagen) {
        return ['name' => null, 'grob' => true];
    }
    if ($richtung === null) {
        return null;
    }
    foreach ($windlagen as $w) {
        if (winkel_in_sektor($richtung, $w['sektor'])) {
            return $w;
        }
    }
    return null;
}

function ist_ablandig(?float $richtung, ?array $spot): bool
{
    if ($richtung === null || $spot === null) {
        return false;
    }
    foreach ($spot['ablandig'] ?? [] as $s) {
        if (winkel_in_sektor($richtung, $s)) {
            return true;
        }
    }
    return false;
}

function himmelsrichtung(?float $g): string
{
    if ($g === null) {
        return '–';
    }
    $n = ['N', 'NNO', 'NO', 'ONO', 'O', 'OSO', 'SO', 'SSO', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
    return $n[(int)round(fmod(fmod($g, 360) + 360, 360) / 22.5) % 16];
}

// ===================================================================
// STUNDENWERTE AUS DEN ROHDATEN
// ===================================================================

/**
 * Werte einer Variable je Modell. Open-Meteo haengt bei mehreren Modellen den
 * Modellnamen an (wind_speed_10m_icon_eu), bei einem nicht.
 * Rueckgabe [modell => [werte...]]
 */
function modell_reihen(array $hourly, string $var): array
{
    $out = [];
    foreach ($hourly as $k => $v) {
        if ($k === $var) {
            $out['_'] = $v;
        } elseif (str_starts_with($k, $var . '_') && !str_contains(substr($k, strlen($var) + 1), 'member')) {
            $out[substr($k, strlen($var) + 1)] = $v;
        }
    }
    return $out;
}

/**
 * Ortstag und -stunde je Zeitindex einer Open-Meteo-Zeitreihe (UTC).
 * Einmal je Abruf statt je Ensemble-Lauf: sonst entstehen 51 x 192 Datumsobjekte
 * je Region, das war der groesste Teil der Rechenzeit (seit 7.11.2026).
 * Rueckgabe [i => ['YYYY-MM-DD', stunde]]
 */
function ortszeiten(array $zeiten, DateTimeZone $tz): array
{
    static $merk = [];
    $key = $tz->getName() . '|' . ($zeiten[0] ?? '') . '|' . count($zeiten);
    if (isset($merk[$key])) {
        return $merk[$key];
    }
    if (count($merk) > 50) {
        $merk = [];
    }
    $out = [];
    foreach ($zeiten as $i => $t) {
        $lok = (new DateTimeImmutable($t . ':00Z'))->setTimezone($tz);
        $out[$i] = [$lok->format('Y-m-d'), (int)$lok->format('G')];
    }
    return $merk[$key] = $out;
}

/** Mittlere Richtung (Vektormittel der Einheitsvektoren) */
function richtung_mittel(array $grad): ?float
{
    if (!$grad) {
        return null;
    }
    $x = $y = 0.0;
    foreach ($grad as $g) {
        $x += sin(deg2rad($g));
        $y += cos(deg2rad($g));
    }
    if (abs($x) < 1e-9 && abs($y) < 1e-9) {
        return null;
    }
    return fmod(rad2deg(atan2($x, $y)) + 360, 360);
}

/**
 * Stundenwerte einer Region aus einem Vorhersage-Eintrag (Open-Meteo, UTC),
 * gemittelt ueber alle Modelle mit Wert.
 * Rueckgabe [ 'YYYY-MM-DD' (Ortszeit) => [stunde => [...]] ]
 */
function vorhersage_stunden(array $eintrag, DateTimeZone $tz): array
{
    $h = $eintrag['hourly'];
    $kn  = modell_reihen($h, 'wind_speed_10m');
    $dir = modell_reihen($h, 'wind_direction_10m');
    $boe = modell_reihen($h, 'wind_gusts_10m');
    $wc  = modell_reihen($h, 'weather_code');
    $tmp = modell_reihen($h, 'temperature_2m');
    $wol = modell_reihen($h, 'cloud_cover');
    $orte = ortszeiten($h['time'], $tz);
    $out = [];
    foreach ($h['time'] as $i => $_) {
        [$tag, $stunde] = $orte[$i];
        $v = $d = $b = $te = $wo = [];
        $gew = $codes = 0;
        foreach ($kn as $m => $reihe) {
            if (!isset($reihe[$i])) {
                continue;
            }
            $v[] = (float)$reihe[$i];
            if (isset($dir[$m][$i])) {
                $d[] = (float)$dir[$m][$i];
            }
            if (isset($boe[$m][$i])) {
                $b[] = (float)$boe[$m][$i];
            }
            if (isset($tmp[$m][$i])) {
                $te[] = (float)$tmp[$m][$i];
            }
            if (isset($wol[$m][$i])) {
                $wo[] = (float)$wol[$m][$i];
            }
            if (isset($wc[$m][$i])) {
                $codes++;
                if ((int)$wc[$m][$i] >= 95) {
                    $gew++;
                }
            }
        }
        if (!$v) {
            continue;
        }
        $out[$tag][$stunde] = [
            'kn'       => array_sum($v) / count($v),
            'richtung' => richtung_mittel($d),
            'boe'      => $b ? array_sum($b) / count($b) : null,
            'boe_max'  => $b ? max($b) : null,
            'temp'     => $te ? array_sum($te) / count($te) : null,
            'gewitter' => $codes > 0 && $gew * 2 >= $codes,
            'wolken'   => $wo ? array_sum($wo) / count($wo) : null,
            'modelle'  => count($v),
        ];
    }
    return $out;
}

/**
 * Wellen je Ortstag und Stunde mit allen Werten (seit 7.11.2026):
 * ['hoehe', 'periode', 'richtung', 'swell', 'swell_periode', 'swell_richtung'] (null, wo fehlt)
 */
function welle_details(?array $eintrag, DateTimeZone $tz): array
{
    if (!$eintrag) {
        return [];
    }
    $h = $eintrag['hourly'];
    $orte = ortszeiten($h['time'], $tz);
    $f = fn(string $k, int $i) => isset($h[$k][$i]) ? (float)$h[$k][$i] : null;
    $out = [];
    foreach ($h['time'] as $i => $_) {
        if (!isset($h['wave_height'][$i]) && !isset($h['swell_wave_height'][$i])) {
            continue;
        }
        [$tag, $stunde] = $orte[$i];
        $out[$tag][$stunde] = ['hoehe' => $f('wave_height', $i), 'periode' => $f('wave_period', $i), 'richtung' => $f('wave_direction', $i),
                               'swell' => $f('swell_wave_height', $i), 'swell_periode' => $f('swell_wave_period', $i),
                               'swell_richtung' => $f('swell_wave_direction', $i)];
    }
    return $out;
}

/** Duenung einer Stunde: Swell, ohne Swell die gesamte Welle. [hoehe, periode, richtung] */
function duenung(array $wi): array
{
    if ($wi['swell'] !== null) {
        return [$wi['swell'], $wi['swell_periode'] ?? $wi['periode'], $wi['swell_richtung'] ?? $wi['richtung']];
    }
    return [$wi['hoehe'], $wi['periode'], $wi['richtung']];
}

/** Mittel der Wellen ueber ein Fenster [ab, bis) eines Tages; null ohne Daten */
function tag_welle(array $proStunde, array $fenster): ?array
{
    $w = [];
    for ($s = $fenster[0]; $s < $fenster[1]; $s++) {
        if (isset($proStunde[$s])) {
            $w[] = $proStunde[$s];
        }
    }
    if (!$w) {
        return null;
    }
    $m = function (string $k) use ($w): ?float {
        $v = array_values(array_filter(array_column($w, $k), fn($x) => $x !== null));
        return $v ? array_sum($v) / count($v) : null;
    };
    $r = function (string $k) use ($w): ?float {
        $v = array_values(array_filter(array_column($w, $k), fn($x) => $x !== null));
        return $v ? richtung_mittel($v) : null;
    };
    return ['hoehe' => $m('hoehe'), 'periode' => $m('periode'), 'richtung' => $r('richtung'),
            'swell' => $m('swell'), 'swell_periode' => $m('swell_periode'), 'swell_richtung' => $r('swell_richtung')];
}

/**
 * Sperre aus den Spot-Infos (seit 7.11.2026): eine info mit art "sperre",
 * monate [von, bis] und sportarten (Gruppen wie "kite"), die den Monat und die
 * Gruppe der Sportart trifft. Rueckgabe der Text der Sperre oder null.
 */
function region_sperre(array $region, array $sport, int $monat): ?string
{
    foreach ($region['infos'] ?? [] as $info) {
        if (($info['art'] ?? '') !== 'sperre' || empty($info['monate'])) {
            continue;
        }
        if (isset($info['sportarten']) && !in_array($sport['gruppe'] ?? '', $info['sportarten'], true)) {
            continue;
        }
        if (in_monaten($monat, $info['monate'])) {
            return 'Sperre: ' . $info['text'];
        }
    }
    return null;
}

/**
 * Wave-Sportarten (welle_min/welle_max): Das Ensemble hat keine Welle. Damit die
 * Chance nicht Tage mit zu kleiner Welle zaehlt, bekommt jeder Lauf die
 * Wellenhoehe der Marine-Vorhersage (fuer alle Laeufe gleich, seit 7.11.2026).
 * Am Meer ohne Wellenwert fuer die Stunde (Marine-Vorhersage reicht nicht so
 * weit oder fehlt) ist die Stunde unbekannt ('welle_fehlt', seit 8.11.2026):
 * ensemble_chance zaehlt solche Laeufe nicht, statt sie als "keine Welle" zu
 * werten. An Seen bleibt welle = null, die Stunde geht dort nie.
 * Andere Sportarten: unveraendert (ohne Schluessel 'welle' prueft rest_grund nicht).
 */
function ensemble_welle_einmischen(array $ens, array $ws, array $sport, bool $meer = false): array
{
    if (!isset($sport['welle_min']) && !isset($sport['welle_max'])) {
        return $ens;
    }
    foreach ($ens as $lid => $proTag) {
        foreach ($proTag as $t => $proStunde) {
            foreach ($proStunde as $s => $_) {
                $wert = $ws[$t][$s] ?? null;
                $ens[$lid][$t][$s]['welle'] = $wert;
                if ($meer && $wert === null) {
                    $ens[$lid][$t][$s]['welle_fehlt'] = true;
                }
            }
        }
    }
    return $ens;
}

/** Braucht die Sportart Wellendaten? (Wave-Sportarten, Wellenreiten) */
function braucht_welle(array $sport): bool
{
    return isset($sport['welle_min']) || isset($sport['welle_max']) || !empty($sport['surf']);
}

/** Gibt es fuer mindestens eine Stunde im Fenster [ab, bis) einen Wert? */
function fenster_hat_werte(array $proStunde, array $fenster): bool
{
    for ($s = $fenster[0]; $s < $fenster[1]; $s++) {
        if (isset($proStunde[$s])) {
            return true;
        }
    }
    return false;
}

/**
 * Stunde fuers Wellenreiten (Sportart mit 'surf'). Rueckgabe wie stunde_fahrbar:
 * [ok, grund, lage ['name' => 'Dünung aus W', 'charakter'], Hoehe der Duenung].
 */
function stunde_surfbar(array $w, array $sport, ?array $spot): array
{
    if (!array_key_exists('welle_info', $w)) {
        return [false, 'ohne Wellendaten', null, null];   // Ensemble
    }
    if ($w['welle_info'] === null) {
        return [false, 'keine Wellendaten', null, null];
    }
    [$hoehe, $periode, $richtung] = duenung($w['welle_info']);
    if ($hoehe === null || $hoehe < $sport['welle_min']) {
        return [false, 'zu wenig Welle', null, null];
    }
    if (isset($sport['welle_max']) && $hoehe > $sport['welle_max']) {
        return [false, 'zu viel Welle', null, null];
    }
    if ($periode !== null && isset($sport['periode_min']) && $periode < $sport['periode_min']) {
        return [false, 'Periode zu kurz', null, null];
    }
    if ($richtung !== null && ist_ablandig($richtung, $spot)) {
        return [false, 'Dünung kommt vom Land', null, null];
    }
    if (!empty($w['gewitter'])) {
        return [false, 'Gewitter', null, null];
    }
    $ablandig = ist_ablandig($w['richtung'] ?? null, $spot);
    if (!$ablandig && isset($sport['wind_max']) && $w['kn'] > $sport['wind_max']) {
        return [false, 'zu viel Wind', null, null];
    }
    $name = 'Dünung' . ($richtung !== null ? ' aus ' . himmelsrichtung($richtung) : '');
    return [true, null, ['name' => $name, 'charakter' => $ablandig ? 'Wind ablandig' : null, 'grob' => !$spot || empty($spot['ablandig'])], $hoehe];
}

/** Wellenhoehe je Ortstag und Stunde aus einem Marine-Eintrag */
function welle_stunden(?array $eintrag, DateTimeZone $tz): array
{
    if (!$eintrag) {
        return [];
    }
    $h = $eintrag['hourly'];
    $orte = ortszeiten($h['time'], $tz);
    $out = [];
    foreach ($h['time'] as $i => $_) {
        if (!isset($h['wave_height'][$i])) {
            continue;
        }
        [$tag, $stunde] = $orte[$i];
        $out[$tag][$stunde] = (float)$h['wave_height'][$i];
    }
    return $out;
}

/**
 * Wassertemperatur je Ortstag und Stunde: Meer aus der Marine API
 * (sea_surface_temperature, seit 7.11.2026), Seen aus dem Seemodell von Alplakes
 * (water_temperature, 1 m Tiefe, vom Sammler auf Stunden gefuellt, seit 8.11.2026).
 */
function wasser_stunden(?array $eintrag, DateTimeZone $tz): array
{
    $var = null;
    foreach (['sea_surface_temperature', 'water_temperature'] as $v) {
        if (!empty($eintrag['hourly'][$v])) {
            $var = $v;
            break;
        }
    }
    if ($var === null) {
        return [];
    }
    $h = $eintrag['hourly'];
    $orte = ortszeiten($h['time'], $tz);
    $out = [];
    foreach ($h['time'] as $i => $_) {
        if (isset($h[$var][$i])) {
            [$tag, $stunde] = $orte[$i];
            $out[$tag][$stunde] = (float)$h[$var][$i];
        }
    }
    return $out;
}

/** Wasser-Eintrag einer Region: Meer aus "wasser", Seen aus "seewasser" (Alplakes) */
function wasser_eintrag(array $stand, string $id): ?array
{
    return $stand['wasser'][$id] ?? $stand['seewasser'][$id] ?? null;
}

/**
 * Luft, Wasser und Wind im Fenster eines Tages (seit 7.11.2026, fuer die
 * Neopren-Faustregel): Mittel ueber das Fenster des Tages (das gefundene
 * Fenster, wenn es eins gibt, sonst das Tagesfenster).
 * Rueckgabe ['luft' => ?float, 'wasser' => ?float, 'kn' => ?float]
 */
function tag_temperaturen(array $stunden, array $wasser, array $fenster): array
{
    [$ab, $bis] = $fenster;
    $l = $w = $k = [];
    for ($s = $ab; $s < $bis; $s++) {
        if (isset($stunden[$s]['temp'])) {
            $l[] = $stunden[$s]['temp'];
        }
        if (isset($stunden[$s]['kn'])) {
            $k[] = $stunden[$s]['kn'];
        }
        if (isset($wasser[$s])) {
            $w[] = $wasser[$s];
        }
    }
    $m = fn(array $a) => $a ? array_sum($a) / count($a) : null;
    return ['luft' => $m($l), 'wasser' => $m($w), 'kn' => $m($k)];
}

/**
 * Wind eines Tages fuer die Windpfeile der Landkarte (seit 8.11.2026): im Fenster
 * des Modellmittels dessen Mittel und Richtung (bei Regel-Windlagen die typische
 * Staerke und Sektormitte), sonst Mittel ueber das Tagesfenster. null ohne Daten.
 * Rueckgabe ['kn' => float, 'richtung' => float|null, 'fenster' => bool]
 */
function tag_wind(array $stunden, array $det, array $fenster): ?array
{
    if (!empty($det['geht'])) {
        $kn = !empty($det['regel']) ? ($det['staerke'][0] + $det['staerke'][1]) / 2 : $det['mittel_kn'];
        return ['kn' => (float)$kn, 'richtung' => $det['richtung'] ?? null, 'fenster' => true];
    }
    $k = $r = [];
    for ($s = $fenster[0]; $s < $fenster[1]; $s++) {
        if (isset($stunden[$s]['kn'])) {
            $k[] = $stunden[$s]['kn'];
            if (isset($stunden[$s]['richtung'])) {
                $r[] = $stunden[$s]['richtung'];
            }
        }
    }
    return $k ? ['kn' => array_sum($k) / count($k), 'richtung' => richtung_mittel($r), 'fenster' => false] : null;
}

/**
 * Ensemble: je Lauf (Kontrolllauf + Member) Wind, Richtung und Bewoelkung je Ortstag/Stunde.
 * Schluessel des Laufs ist das Suffix der Open-Meteo-Variablen: '' (Kontrolllauf)
 * oder '_member01' usw. - damit lassen sich Laeufe verschiedener Abrufe (Druck,
 * Hoehenwind) demselben Lauf zuordnen.
 * Rueckgabe [lauf => [tag => [stunde => ['kn' =>, 'richtung' =>, 'wolken' =>]]]]
 */
function ensemble_stunden(?array $eintrag, DateTimeZone $tz): array
{
    if (!$eintrag) {
        return [];
    }
    $h = $eintrag['hourly'];
    $orte = ortszeiten($h['time'], $tz);
    $laeufe = [];
    foreach ($h as $k => $reihe) {
        if (!preg_match('/^wind_speed_10m(_member\d+)?$/', $k, $m)) {
            continue;
        }
        $suffix = $m[1] ?? '';
        $richtung = $h['wind_direction_10m' . $suffix] ?? [];
        $wolken = $h['cloud_cover' . $suffix] ?? [];
        foreach ($h['time'] as $i => $_) {
            if (!isset($reihe[$i])) {
                continue;
            }
            [$tag, $stunde] = $orte[$i];
            $laeufe[$suffix][$tag][$stunde] = [
                'kn'       => (float)$reihe[$i],
                'richtung' => isset($richtung[$i]) ? (float)$richtung[$i] : null,
                'wolken'   => isset($wolken[$i]) ? (float)$wolken[$i] : null,
            ];
        }
    }
    return $laeufe;
}

/**
 * Modellmittel einer Variable je Ortstag und Stunde (alle Modelle mit Wert).
 * $richtung: als Vektormittel (fuer Windrichtungen).
 */
function mittel_stunden(?array $eintrag, string $var, DateTimeZone $tz, bool $richtung = false): array
{
    if (!$eintrag) {
        return [];
    }
    $h = $eintrag['hourly'];
    $reihen = modell_reihen($h, $var);
    $orte = ortszeiten($h['time'], $tz);
    $out = [];
    foreach ($h['time'] as $i => $_) {
        $v = [];
        foreach ($reihen as $reihe) {
            if (isset($reihe[$i])) {
                $v[] = (float)$reihe[$i];
            }
        }
        if (!$v) {
            continue;
        }
        [$tag, $stunde] = $orte[$i];
        $out[$tag][$stunde] = $richtung ? richtung_mittel($v) : array_sum($v) / count($v);
    }
    return $out;
}

/** Ensemble: eine Variable je Lauf (Suffix wie in ensemble_stunden), Ortstag und Stunde */
function ensemble_reihe(?array $eintrag, string $var, DateTimeZone $tz): array
{
    if (!$eintrag) {
        return [];
    }
    $h = $eintrag['hourly'];
    $orte = ortszeiten($h['time'], $tz);
    $out = [];
    foreach ($h as $k => $reihe) {
        if (!preg_match('/^' . preg_quote($var, '/') . '(_member\d+)?$/', $k, $m)) {
            continue;
        }
        foreach ($h['time'] as $i => $_) {
            if (!isset($reihe[$i])) {
                continue;
            }
            [$tag, $stunde] = $orte[$i];
            $out[$m[1] ?? ''][$tag][$stunde] = (float)$reihe[$i];
        }
    }
    return $out;
}

/** Ist das eine Regel-Windlage (Druck oder Grundstroemung statt Modellwind)? */
function ist_regel(array $w): bool
{
    return isset($w['druck']) || isset($w['grundstroemung']);
}

/** Druckachsen und Hoehen, die die Windlagen einer Region brauchen */
function region_bedarf(array $region): array
{
    $achsen = $hoehen = [];
    $alle = $region['windlagen'] ?? [];
    foreach ($region['spots'] ?? [] as $sp) {
        $alle = array_merge($alle, $sp['windlagen'] ?? []);
    }
    foreach ($alle as $w) {
        foreach ($w['druck'] ?? [] as $d) {
            $achsen[$d['plus'] . '-' . $d['minus']] = [$d['plus'], $d['minus']];
        }
        if (isset($w['grundstroemung']['hoehe'])) {
            $hoehen[$w['grundstroemung']['hoehe']] = true;
        }
    }
    return [$achsen, array_keys($hoehen)];
}

function in_monaten(int $monat, array $saison): bool
{
    [$a, $b] = $saison;
    return $a <= $b ? ($monat >= $a && $monat <= $b) : ($monat >= $a || $monat <= $b);
}

/**
 * Bedingungen einer Regel-Windlage fuer eine Stunde. null = erfuellt, sonst der Grund.
 * $w braucht '_h' (Ortsstunde), '_monat', je nach Regel 'druck' [achse => hPa],
 * 'hoehe' [hoehe => ['kn', 'richtung']], 'wolken'.
 */
function regel_grund(array $r, array $w): ?string
{
    if (isset($r['saison']) && !in_monaten((int)$w['_monat'], $r['saison'])) {
        return 'außerhalb der Saison';
    }
    if (isset($r['zeit']) && ($w['_h'] < $r['zeit'][0] || $w['_h'] >= $r['zeit'][1])) {
        return 'außerhalb der Tageszeit der Windlage';
    }
    foreach ($r['druck'] ?? [] as $d) {
        $v = $w['druck'][$d['plus'] . '-' . $d['minus']] ?? null;
        if ($v === null) {
            return 'keine Druckdaten';
        }
        if ((isset($d['min']) && $v < $d['min']) || (isset($d['max']) && $v > $d['max'])) {
            return 'Druckdifferenz passt nicht';
        }
    }
    if (isset($r['grundstroemung'])) {
        $g = $r['grundstroemung'];
        $h = $w['hoehe'][$g['hoehe']] ?? null;
        if ($h === null || $h['kn'] === null || $h['richtung'] === null) {
            return 'keine Höhenwinddaten';
        }
        if ((isset($g['kn_min']) && $h['kn'] < $g['kn_min']) || (isset($g['kn_max']) && $h['kn'] > $g['kn_max'])
            || (isset($g['sektor']) && !winkel_in_sektor($h['richtung'], $g['sektor']))) {
            return 'Grundströmung passt nicht';
        }
    }
    if (($r['wetter'] ?? null) === 'sonnig') {
        if (!isset($w['wolken'])) {
            return 'keine Wolkendaten';
        }
        if ($w['wolken'] > 50) {
            return 'zu bewölkt';
        }
    }
    return null;
}

/** Mitte eines Sektors (im Uhrzeigersinn von 'von' nach 'bis') */
function sektor_mitte(array $s): float
{
    $breite = fmod($s[1] - $s[0] + 360, 360);
    return fmod($s[0] + $breite / 2, 360);
}

// ===================================================================
// TAGESFENSTER
// ===================================================================

/** [ab, bis) in Ortsstunden: 9 Uhr (oder Sonnenaufgang, wenn spaeter) bis Sonnenuntergang, gerundet */
function tagesfenster(string $tag, DateTimeZone $tz, float $lat, float $lon, int $ab = FENSTER_AB): array
{
    // $ab: frueherer Beginn fuer Regionen mit Morgenwind (Katalogfeld fenster_ab, seit 10.11.2026),
    // nie vor Sonnenaufgang
    $mittag = new DateTimeImmutable("$tag 12:00", $tz);
    $sonne = date_sun_info($mittag->getTimestamp(), $lat, $lon);
    $bis = 18;
    if (is_int($sonne['sunrise'] ?? null)) {
        $auf = (new DateTimeImmutable('@' . $sonne['sunrise']))->setTimezone($tz);
        $ab = max($ab, (int)$auf->format('G') + ((int)$auf->format('i') > 0 ? 1 : 0));
    }
    if (is_int($sonne['sunset'] ?? null)) {
        $unter = (new DateTimeImmutable('@' . $sonne['sunset']))->setTimezone($tz);
        $bis = (int)$unter->format('G') + ((int)$unter->format('i') >= 30 ? 1 : 0);
    }
    return [$ab, max($ab, $bis)];
}

/**
 * Restfenster fuer heute (seit 7.11.2026, wie stegen-wind seit 26.10.2026):
 * ab jetzt + REST_VORLAUF_MIN, auf die naechste volle Modellstunde gerundet,
 * bis zum Ende des Tagesfensters. Fuer andere Tage unveraendert.
 * Rueckgabe [fenster, rest (bool), vorbei (bool)]. Vorbei, wenn weniger als
 * MIN_STUNDEN bleiben - dann kann der Tag nicht mehr gehen.
 * Grenze: die Ensemble-Chance ist fuer ganze Tage gedacht; ein Rest von wenigen
 * Stunden ist eine Naeherung mit derselben Regel.
 */
function restfenster(array $fenster, string $tag, DateTimeZone $tz, DateTimeImmutable $jetzt): array
{
    $lok = $jetzt->setTimezone($tz);
    if ($lok->format('Y-m-d') !== $tag) {
        return [$fenster, false, false];
    }
    $start = $lok->modify('+' . REST_VORLAUF_MIN . ' minutes');
    $ab = $start->format('Y-m-d') === $tag
        ? (int)$start->format('G') + ((int)$start->format('i') >= 30 ? 1 : 0)
        : 24;
    [$fAb, $fBis] = $fenster;
    $ab = max($fAb, $ab);
    return [[$ab, max($ab, $fBis)], true, $fBis - $ab < MIN_STUNDEN];
}

// ===================================================================
// STUNDE UND TAG
// ===================================================================

/**
 * Ist eine Stunde fahrbar? Rueckgabe [ok, grund, windlage, kn]
 * kn ist der Wind, mit dem gerechnet wurde: Modellwind oder bei einer
 * Regel-Windlage deren typische Staerke.
 * $w: ['kn', 'richtung', optional 'boe', 'gewitter', 'welle', 'wolken', 'druck',
 *      'hoehe', '_h', '_monat']
 */
function stunde_fahrbar(array $w, array $sport, array $windlagen, ?array $spot): array
{
    if (!empty($sport['surf'])) {
        return stunde_surfbar($w, $sport, $spot);
    }
    $normal = array_values(array_filter($windlagen, fn($l) => !ist_regel($l)));
    $regeln = array_values(array_filter($windlagen, 'ist_regel'));

    // Regel-Windlagen zuerst: sie ersetzen den Modellwind
    $regelGrund = null;
    foreach ($regeln as $r) {
        $g = regel_grund($r, $w);
        if ($g === null) {
            $w2 = $w;
            $w2['kn'] = ($r['staerke'][0] + $r['staerke'][1]) / 2;
            $w2['richtung'] = sektor_mitte($r['sektor']);
            $w2['boe'] = null;
            $g = wind_grund($w2, $sport) ?? rest_grund($w2, $sport, $spot);
            if ($g === null) {
                return [true, null, $r + ['regel' => true], $w2['kn']];
            }
        }
        $regelGrund ??= $g;
    }
    if (!$normal && $regeln) {
        return [false, $regelGrund ?? 'Lage passt nicht', null, null];
    }

    $g = wind_grund($w, $sport);
    if ($g !== null) {
        return [false, $regelGrund ?? $g, null, null];
    }
    $lage = windlage_fuer($w['richtung'], $normal);
    if ($lage === null) {
        return [false, $regelGrund ?? 'Richtung passt nicht', null, null];
    }
    $g = rest_grund($w, $sport, $spot);
    if ($g !== null) {
        return [false, $g, null, null];
    }
    return [true, null, $lage, $w['kn']];
}

/** Windstaerke und Boeen gegen das Fenster der Sportart. null = passt */
function wind_grund(array $w, array $sport): ?string
{
    if ($w['kn'] < $sport['min']) {
        return 'zu wenig Wind';
    }
    if (isset($sport['max']) && $w['kn'] > $sport['max']) {
        return 'zu viel Wind';
    }
    if (isset($sport['boe_max'], $w['boe']) && $w['boe'] > $sport['boe_max']) {
        return 'Böen zu stark';
    }
    return null;
}

/** Ablandig, Gewitter, Welle. null = passt */
function rest_grund(array $w, array $sport, ?array $spot): ?string
{
    if (ist_ablandig($w['richtung'], $spot)) {
        return 'ablandig';
    }
    if (!empty($w['gewitter'])) {
        return 'Gewitter';
    }
    if (isset($sport['welle_min']) || isset($sport['welle_max'])) {
        if (!array_key_exists('welle', $w)) {
            // Ensemble hat keine Welle: dort nicht pruefen
        } elseif ($w['welle'] === null) {
            if (isset($sport['welle_min'])) {
                return 'keine Wellendaten';
            }
        } elseif (isset($sport['welle_min']) && $w['welle'] < $sport['welle_min']) {
            return 'zu wenig Welle';
        } elseif (isset($sport['welle_max']) && $w['welle'] > $sport['welle_max']) {
            return 'zu viel Welle';
        }
    }
    return null;
}

/**
 * Laengstes Stueck fahrbarer Stunden im Fenster.
 * $stunden: [stunde => werte], $fenster: [ab, bis)
 * Rueckgabe ['geht', 'von', 'bis', 'stunden', 'mittel_kn', 'boe_max', 'windlage', 'grob', 'grund']
 */
function tag_bewerten(array $stunden, array $fenster, array $sport, array $windlagen, ?array $spot, int $monat = 0): array
{
    [$ab, $bis] = $fenster;
    $best = null;
    $lauf = [];
    $gruende = [];
    for ($s = $ab; $s <= $bis; $s++) {
        $ok = false;
        if ($s < $bis && isset($stunden[$s])) {
            [$ok, $grund, $lage, $knEff] = stunde_fahrbar($stunden[$s] + ['_h' => $s, '_monat' => $monat], $sport, $windlagen, $spot);
            if (!$ok) {
                $gruende[$grund] = ($gruende[$grund] ?? 0) + 1;
            }
        }
        if ($ok) {
            // Richtung: bei Regel-Windlagen die Mitte des Sektors (wie die Staerke), sonst der Modellwind
            $lauf[$s] = ['lage' => $lage, 'kn' => $knEff,
                         'richtung' => !empty($lage['regel']) ? sektor_mitte($lage['sektor']) : ($stunden[$s]['richtung'] ?? null)];
            continue;
        }
        if ($lauf && ($best === null || count($lauf) > count($best)
                      || (count($lauf) === count($best) && lauf_mittel($lauf) > lauf_mittel($best)))) {
            $best = $lauf;
        }
        $lauf = [];
    }

    if ($best === null || count($best) < MIN_STUNDEN) {
        arsort($gruende);
        return ['geht' => false, 'stunden' => $best ? count($best) : 0,
                'grund' => $gruende ? array_key_first($gruende) : 'keine Daten'];
    }

    // Haeufigste Windlage im Fenster
    $namen = array_count_values(array_map(fn($l) => (string)($l['lage']['name'] ?? ''), $best));
    arsort($namen);
    $lageName = array_key_first($namen);
    $lage = null;
    foreach ($best as $l) {
        if ((string)($l['lage']['name'] ?? '') === (string)$lageName) {
            $lage = $l['lage'];
            break;
        }
    }
    // Boeen nur aus Stunden mit Modellwind; Regel-Stunden haben keine
    $boen = [];
    foreach ($best as $st => $l) {
        if (empty($l['lage']['regel']) && isset($stunden[$st]['boe_max'])) {
            $boen[] = $stunden[$st]['boe_max'];
        }
    }
    return [
        'geht'      => true,
        'von'       => array_key_first($best),
        'bis'       => array_key_last($best) + 1,
        'stunden'   => count($best),
        'mittel_kn' => round(lauf_mittel($best), 1),
        'richtung'  => richtung_mittel(array_values(array_filter(array_column($best, 'richtung'), fn($r) => $r !== null))),
        'boe_max'   => $boen ? round(max($boen), 1) : null,
        'windlage'  => $lage['name'] ?? null,
        'charakter' => $lage['charakter'] ?? null,
        'grob'      => !empty($lage['grob']),
        'regel'     => !empty($lage['regel']),
        'staerke'   => !empty($lage['regel']) ? $lage['staerke'] : null,
        'grund'     => null,
    ];
}

function lauf_mittel(array $lauf): float
{
    $v = array_column($lauf, 'kn');
    return $v ? array_sum($v) / count($v) : 0.0;
}

/**
 * Chance aus dem Ensemble: Anteil der Laeufe, an denen der Tag geht.
 * Nur Laeufe mit Werten fuer alle Fensterstunden zaehlen (bei Wave-Sportarten
 * am Meer auch mit Welle). null = keine Daten.
 */
function ensemble_chance(array $laeufe, string $tag, array $fenster, array $sport, array $windlagen, ?array $spot): ?array
{
    [$ab, $bis] = $fenster;
    $n = $ja = 0;
    foreach ($laeufe as $proTag) {
        $st = $proTag[$tag] ?? [];
        $voll = true;
        for ($s = $ab; $s < $bis; $s++) {
            if (!isset($st[$s]) || !empty($st[$s]['welle_fehlt'])) {
                $voll = false;
                break;
            }
        }
        if (!$voll || $bis <= $ab) {
            continue;
        }
        $n++;
        if (tag_bewerten($st, $fenster, $sport, $windlagen, $spot, (int)substr($tag, 5, 2))['geht']) {
            $ja++;
        }
    }
    return $n ? ['chance' => $ja / $n, 'laeufe' => $n] : null;
}

// ===================================================================
// REGION
// ===================================================================

/** Beginn des Tagesfensters einer Region: fenster_ab aus dem Katalog (Morgenwind wie Foehn am Kochelsee), sonst FENSTER_AB */
function region_fenster_ab(array $region): int
{
    return (int)($region['fenster_ab'] ?? FENSTER_AB);
}

/**
 * Bewertung einer Region fuer die angegebenen Tage.
 * $daten: ['vorhersage' => eintrag|null, 'ensemble' => eintrag|null, 'welle' => eintrag|null]
 * Rueckgabe [tag => ['spot' => id|null, 'spot_name', 'det' => tag_bewerten, 'ens' => chance|null, 'alle' => [...]]]
 */
function region_bewerten(array $region, array $daten, array $sport, array $tage, ?DateTimeImmutable $jetzt = null): array
{
    $jetzt ??= new DateTimeImmutable('now');
    $tz = new DateTimeZone($region['zeitzone']);
    [$lat, $lon] = $region['punkt'];
    $vh = $daten['vorhersage'] ? vorhersage_stunden($daten['vorhersage'], $tz) : [];
    $ws = welle_stunden($daten['welle'] ?? null, $tz);
    $wd = welle_details($daten['welle'] ?? null, $tz);
    $wt = wasser_stunden($daten['wasser'] ?? null, $tz);
    $ens = ensemble_stunden($daten['ensemble'] ?? null, $tz);
    [$vh, $ens] = regel_daten_einmischen($region, $daten, $tz, $vh, $ens);
    $meer = ($region['typ'] ?? '') === 'meer';
    $ens = ensemble_welle_einmischen($ens, $ws, $sport, $meer);
    $spots = $region['spots'] ?? [];
    if (!$spots) {
        $spots = [null];   // Region selbst, mit ihren Windlagen
    }

    $out = [];
    foreach ($tage as $tag) {
        [$fenster, $rest, $vorbei] = restfenster(tagesfenster($tag, $tz, (float)$lat, (float)$lon, region_fenster_ab($region)), $tag, $tz, $jetzt);
        $sperre = region_sperre($region, $sport, (int)substr($tag, 5, 2));
        if ($vorbei) {
            $leer = ['geht' => false, 'stunden' => 0, 'grund' => 'Tag vorbei'];
            $out[$tag] = ['spot' => null, 'spot_name' => null, 'det' => $leer, 'ens' => null,
                          'fenster' => $fenster, 'rest' => true, 'vorbei' => true, 'offen' => false, 'alle' => []];
            continue;
        }
        $stunden = $vh[$tag] ?? [];
        // Welle auch bei Seen setzen (dann null): eine Wave-Sportart soll dort
        // "keine Wellendaten" bekommen, statt ungeprueft durchzugehen
        foreach ($stunden as $s => $w) {
            $stunden[$s]['welle'] = $ws[$tag][$s] ?? null;
            $stunden[$s]['welle_info'] = $wd[$tag][$s] ?? null;
        }
        $alle = [];
        foreach ($spots as $spot) {
            $wl = spot_windlagen($region, $spot);
            $alle[] = [
                'spot'      => $spot['id'] ?? null,
                'spot_name' => $spot['name'] ?? null,
                'det'       => tag_bewerten($stunden, $fenster, $sport, $wl, $spot, (int)substr($tag, 5, 2)),
                'ens'       => empty($sport['surf']) ? ensemble_chance($ens, $tag, $fenster, $sport, $wl, $spot) : null,
            ];
        }
        // Fehlende Daten (seit 8.11.2026): Reicht die Vorhersage nicht bis zu diesem
        // Tag, oder fehlt am Meer die Welle fuer eine Wave-Sportart, ist der Tag
        // offen, nicht schlecht. Ohne Ensemble-Chance zeigt die Seite dann "?" statt 0 %.
        $fehlt = !fenster_hat_werte($vh[$tag] ?? [], $fenster) ? 'noch keine Vorhersage'
               : ($meer && braucht_welle($sport) && !fenster_hat_werte($ws[$tag] ?? [], $fenster) ? 'noch keine Wellenvorhersage' : null);
        if ($fehlt !== null) {
            foreach ($alle as $k => $x) {
                if (!$x['det']['geht']) {
                    $alle[$k]['det']['grund'] = $fehlt;
                }
            }
        }
        if ($sperre !== null) {
            // Sperre aus den Spot-Infos: Tag geht nicht, auch nicht im Ensemble
            foreach ($alle as $k => $x) {
                $alle[$k]['det'] = ['geht' => false, 'stunden' => 0, 'grund' => $sperre];
                $alle[$k]['ens'] = $x['ens'] !== null ? ['chance' => 0.0, 'laeufe' => $x['ens']['laeufe']] : null;
            }
        }
        usort($alle, 'spot_vergleich');
        $det = $alle[0]['det'];
        $temp = tag_temperaturen($vh[$tag] ?? [], $wt[$tag] ?? [], $det['geht'] ? [$det['von'], $det['bis']] : $fenster);
        // Herkunft der Wassertemperatur fuer den Tooltip (Seen: Modell der Seemitte)
        $temp['wasser_quelle'] = $temp['wasser'] === null ? null : ($meer ? 'meer' : 'see');
        $offen = $fehlt !== null && $sperre === null && !$det['geht'] && $alle[0]['ens'] === null;
        $out[$tag] = $alle[0] + ['fenster' => $fenster, 'rest' => $rest, 'vorbei' => false, 'offen' => $offen, 'alle' => $alle, 'temp' => $temp,
                                 // Wellenreiten: Fenster nach Duenung, dort ist mittel_kn die Hoehe; also Tagesmittel
                                 'wind' => tag_wind($vh[$tag] ?? [], empty($sport['surf']) ? $det : [], $fenster),
                                 'welle' => tag_welle($wd[$tag] ?? [], $det['geht'] ? [$det['von'], $det['bis']] : $fenster)];
    }
    return $out;
}

/**
 * Druckdifferenzen, Hoehenwind (und 10-m-Wind als Grundstroemung) in die
 * Stundenwerte einmischen, die die Regel-Windlagen der Region brauchen.
 * $daten['druck'] / ['druck_ensemble']: [druckpunkt => eintrag]
 * $daten['hoehe'] / ['hoehe_ensemble']: eintrag der Region
 */
function regel_daten_einmischen(array $region, array $daten, DateTimeZone $tz, array $vh, array $ens): array
{
    [$achsen, $hoehen] = region_bedarf($region);
    if (!$achsen && !$hoehen) {
        return [$vh, $ens];
    }
    // Modellmittel
    foreach ($achsen as $key => [$plus, $minus]) {
        $a = mittel_stunden($daten['druck'][$plus] ?? null, 'pressure_msl', $tz);
        $b = mittel_stunden($daten['druck'][$minus] ?? null, 'pressure_msl', $tz);
        foreach ($vh as $tag => $proStunde) {
            foreach ($proStunde as $st => $_) {
                if (isset($a[$tag][$st], $b[$tag][$st])) {
                    $vh[$tag][$st]['druck'][$key] = $a[$tag][$st] - $b[$tag][$st];
                }
            }
        }
    }
    foreach ($hoehen as $hh) {
        if ($hh === '10m') {
            foreach ($vh as $tag => $proStunde) {
                foreach ($proStunde as $st => $w) {
                    $vh[$tag][$st]['hoehe']['10m'] = ['kn' => $w['kn'], 'richtung' => $w['richtung']];
                }
            }
            continue;
        }
        $kn = mittel_stunden($daten['hoehe'] ?? null, "wind_speed_$hh", $tz);
        $ri = mittel_stunden($daten['hoehe'] ?? null, "wind_direction_$hh", $tz, true);
        foreach ($vh as $tag => $proStunde) {
            foreach ($proStunde as $st => $_) {
                if (isset($kn[$tag][$st])) {
                    $vh[$tag][$st]['hoehe'][$hh] = ['kn' => $kn[$tag][$st], 'richtung' => $ri[$tag][$st] ?? null];
                }
            }
        }
    }
    // Ensemble: Lauf fuer Lauf
    foreach ($achsen as $key => [$plus, $minus]) {
        $a = ensemble_reihe($daten['druck_ensemble'][$plus] ?? null, 'pressure_msl', $tz);
        $b = ensemble_reihe($daten['druck_ensemble'][$minus] ?? null, 'pressure_msl', $tz);
        foreach ($ens as $lauf => $proTag) {
            foreach ($proTag as $tag => $proStunde) {
                foreach ($proStunde as $st => $_) {
                    if (isset($a[$lauf][$tag][$st], $b[$lauf][$tag][$st])) {
                        $ens[$lauf][$tag][$st]['druck'][$key] = $a[$lauf][$tag][$st] - $b[$lauf][$tag][$st];
                    }
                }
            }
        }
    }
    foreach ($hoehen as $hh) {
        $kn = $hh === '10m' ? null : ensemble_reihe($daten['hoehe_ensemble'] ?? null, "wind_speed_$hh", $tz);
        $ri = $hh === '10m' ? null : ensemble_reihe($daten['hoehe_ensemble'] ?? null, "wind_direction_$hh", $tz);
        foreach ($ens as $lauf => $proTag) {
            foreach ($proTag as $tag => $proStunde) {
                foreach ($proStunde as $st => $w) {
                    if ($hh === '10m') {
                        $ens[$lauf][$tag][$st]['hoehe']['10m'] = ['kn' => $w['kn'], 'richtung' => $w['richtung']];
                    } elseif (isset($kn[$lauf][$tag][$st])) {
                        $ens[$lauf][$tag][$st]['hoehe'][$hh] = ['kn' => $kn[$lauf][$tag][$st], 'richtung' => $ri[$lauf][$tag][$st] ?? null];
                    }
                }
            }
        }
    }
    return [$vh, $ens];
}

/** Besser zuerst: hoehere Chance, dann geht/laenger/staerker nach Modellmittel */
function spot_vergleich(array $a, array $b): int
{
    $ca = $a['ens']['chance'] ?? -1;
    $cb = $b['ens']['chance'] ?? -1;
    if (abs($ca - $cb) > 1e-9) {
        return $cb <=> $ca;
    }
    return [$b['det']['geht'], $b['det']['stunden'], $b['det']['mittel_kn'] ?? 0]
       <=> [$a['det']['geht'], $a['det']['stunden'], $a['det']['mittel_kn'] ?? 0];
}

// ===================================================================
// FREMDE PROGNOSEN (seit 10.11.2026)
// ===================================================================
//
// Fuer einzelne Regionen gibt es eine an Messwerten kalibrierte Spezialprognose,
// die besser ist als der Modellwind hier. Erste: Walchensee Wind Predictor von
// Marco Schanda (mit seiner Zustimmung, Mail vom 10.11.2026). Wohinfahrma ruft
// seinen Dienst nicht selbst ab: Der Sammler von ammerseewind.de holt /forecast
// ohnehin stuendlich (6-21 Uhr) in seinen Datenordner, eine NDJSON-Datei je UTC-Tag
// mit Zeilen {ok, payload}. config.php nennt diesen Ordner ('fremdprognosen').
//
// Gilt fuer Kite, Wing und Windsurf ohne Welle (seine Chance meint kitebaren Wind; laut
// Tobias, 10.11.2026, unterscheiden sich die Schwellen dort nicht so stark, dass der Rohwert
// besser waere), nicht fuer Segeln und Wellenreiten. Ersetzt nur die Chance des Tages;
// Modellmittel, Temperatur usw. bleiben.
// Bewusst nach dem Zwischenspeicher eingemischt (fremd_einmischen), damit sein
// stuendlicher Stand nicht die vorgerechnete Bewertung aller Regionen ungueltig macht.

const FREMD_FRISCH_S = 18 * 3600;   // wie prognose.php in stegen-wind: hoechstens 18 h alt
const FREMD_HEUTE_BIS = 15;          // heute nur bis 15 Uhr Ortszeit, danach ist die Thermik meist vorbei
const FREMD_STUFEN = ['go' => 'GO', 'lohnt' => 'lohnt sich', 'maybe' => 'vielleicht', 'no' => 'eher nicht'];

/** Gemerkte Prognosen je Region (fremdprognosen_laden); mit Argument ersetzen */
function fremdprognosen(?array $neu = null): array
{
    static $p = [];
    if ($neu !== null) {
        $p = $neu;
    }
    return $p;
}

/**
 * Payload des Walchensee-Predictors pruefen: status success, nicht stale,
 * generated_at (Ortszeit Europe/Berlin) hoechstens FREMD_FRISCH_S alt.
 * Rueckgabe ['stand' => Unix-Zeit, 'tage' => [YYYY-MM-DD => ['chance' 0..1, 'stufe', 'peak_kt' oder null]]] oder null
 */
function fremdprognose_auswerten(array $p, ?int $jetzt = null): ?array
{
    $jetzt ??= time();
    if (($p['status'] ?? '') !== 'success' || !empty($p['stale']) || !is_string($p['generated_at'] ?? null)) {
        return null;
    }
    try {
        $stand = (new DateTimeImmutable($p['generated_at'], new DateTimeZone('Europe/Berlin')))->getTimestamp();
    } catch (Exception) {
        return null;
    }
    if ($jetzt - $stand > FREMD_FRISCH_S || $stand - $jetzt > 3600) {
        return null;
    }
    $tage = [];
    foreach ($p['days'] ?? [] as $d) {
        if (!is_array($d) || empty($d['data_available']) || !is_string($d['date'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['date']) || !is_numeric($d['chance'] ?? null)) {
            continue;
        }
        $tage[$d['date']] = [
            'chance'  => max(0.0, min(1.0, (float)$d['chance'])),
            'stufe'   => isset(FREMD_STUFEN[$d['status'] ?? '']) ? $d['status'] : 'no',
            'peak_kt' => is_numeric($d['peak_kt'] ?? null) ? (float)$d['peak_kt'] : null,
        ];
    }
    return $tage ? ['stand' => $stand, 'tage' => $tage] : null;
}

/**
 * Neuester erfolgreicher Abruf im Ordner (heute oder gestern UTC), wie
 * letzter_lauf_quelle() in stegen-wind: ist der neueste nicht frisch, gilt keiner.
 */
function fremdprognose_lesen(string $dir, ?int $jetzt = null): ?array
{
    $jetzt ??= time();
    foreach ([gmdate('Y-m-d', $jetzt), gmdate('Y-m-d', $jetzt - 86400)] as $d) {
        $f = "$dir/$d.ndjson";
        if (!is_file($f)) {
            continue;
        }
        $zeilen = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        for ($i = count($zeilen) - 1; $i >= 0; $i--) {
            $z = json_decode($zeilen[$i], true);
            if (is_array($z) && !empty($z['ok']) && is_array($z['payload'] ?? null)) {
                return fremdprognose_auswerten($z['payload'], $jetzt);
            }
        }
    }
    return null;
}

// ammerseewind.de (seit 11.11.2026): kalibrierte Chance fuer Stegen, die melden.php dort
// alle 20 Minuten als winddaten/export/wohinfahrma.json schreibt (export_wohinfahrma.php
// in stegen-wind). Je Sportart eine Zahl (Schluessel 'ammerseewind' in SPORTARTEN); heute
// schon ueber das Restfenster gerechnet, deshalb ohne FREMD_HEUTE_BIS, aber nur, solange
// die Datei hoechstens FREMD_HEUTE_FRISCH_S alt ist (sonst stimmt das Restfenster nicht mehr).
const FREMD_HEUTE_FRISCH_S = 2 * 3600;

/**
 * Exportdatei von ammerseewind.de pruefen: version 1, erzeugt hoechstens FREMD_FRISCH_S alt.
 * Rueckgabe wie fremdprognose_auswerten(), je Tag mit 'chancen' [art => 0..1] statt 'chance'.
 */
function fremdprognose_ammerseewind(array $j, ?int $jetzt = null): ?array
{
    $jetzt ??= time();
    $stand = is_string($j['erzeugt'] ?? null) ? strtotime($j['erzeugt']) : false;
    if (($j['version'] ?? null) !== 1 || $stand === false || $jetzt - $stand > FREMD_FRISCH_S || $stand - $jetzt > 3600) {
        return null;
    }
    $tage = [];
    foreach ($j['tage'] ?? [] as $tag => $d) {
        if (!is_string($tag) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tag) || !is_array($d) || !empty($d['vorbei'])) {
            continue;
        }
        $ch = [];
        foreach ((array)($d['chance'] ?? []) as $art => $c) {
            if (is_string($art) && is_numeric($c)) {
                $ch[$art] = max(0.0, min(1.0, (float)$c));
            }
        }
        if ($ch) {
            $tage[$tag] = ['chancen' => $ch, 'peak_kt' => null];
        }
    }
    return $tage ? ['stand' => $stand, 'tage' => $tage, 'format' => 'ammerseewind'] : null;
}

/** Ordner oder Datei einer Region: config.php 'fremdprognosen', sonst Katalogfeld pfad im Ordner 'winddaten' */
function fremdprognose_quelle(array $cfg, array $region): ?string
{
    $q = $cfg['fremdprognosen'][$region['id']] ?? null;
    if (is_string($q) && $q !== '') {
        return rtrim($q, '/');
    }
    $pfad = $region['fremdprognose']['pfad'] ?? null;
    if (!is_string($pfad) || $pfad === '') {
        return null;
    }
    // Ordner winddaten von ammerseewind.de: eigener Eintrag, sonst der Elternordner eines
    // anderen Eintrags dort (die Walchensee-Prognose liegt in winddaten/walchensee)
    $basis = $cfg['winddaten'] ?? null;
    if (!is_string($basis) || $basis === '') {
        foreach ((array)($cfg['fremdprognosen'] ?? []) as $o) {
            if (is_string($o) && $o !== '') {
                $basis = dirname(rtrim($o, '/'));
                break;
            }
        }
    }
    return is_string($basis) && $basis !== '' ? rtrim($basis, '/') . '/' . ltrim($pfad, '/') : null;
}

/** Prognosen aller Regionen mit Katalogfeld fremdprognose und Ordner in config.php lesen und merken */
function fremdprognosen_laden(array $cfg, array $regionen, ?int $jetzt = null): array
{
    $p = [];
    foreach ($regionen as $r) {
        if (empty($r['fremdprognose'])) {
            continue;
        }
        $quelle = fremdprognose_quelle($cfg, $r);
        if ($quelle === null) {
            continue;
        }
        if (($r['fremdprognose']['format'] ?? '') === 'ammerseewind') {
            $j = is_file($quelle) ? json_decode((string)@file_get_contents($quelle), true) : null;
            $x = is_array($j) ? fremdprognose_ammerseewind($j, $jetzt) : null;
        } else {
            $x = fremdprognose_lesen($quelle, $jetzt);
        }
        if ($x !== null) {
            $p[$r['id']] = $x;
        }
    }
    return fremdprognosen($p);
}

/** Gilt eine fremde Prognose fuer diese Sportart? Kite, Wing, Windsurf, jeweils ohne Welle */
function fremd_gilt(array $sport): bool
{
    return in_array($sport['gruppe'] ?? '', ['kite', 'wing', 'windsurf'], true) && empty($sport['welle_min']);
}

/**
 * Chance der fremden Prognose in die Bewertung je Tag (region_bewerten) einsetzen.
 * Nicht an vergangenen Tagen, bei Sperre und heute ab FREMD_HEUTE_BIS Uhr Ortszeit;
 * dort bleibt der eigene Wert. Der Tag bekommt 'fremd' => [name, url, stufe, peak_kt, stand].
 */
function fremd_einmischen(array $region, array $proTag, array $sport, ?DateTimeImmutable $jetzt = null): array
{
    $q = $region['fremdprognose'] ?? null;
    $p = fremdprognosen()[$region['id']] ?? null;
    $aw = ($p['format'] ?? '') === 'ammerseewind';
    // ammerseewind.de hat eine Zahl je Sportart (auch Segeln), der Walchensee-Predictor eine fuer Kite, Wing, Windsurf
    if (!$q || !$p || ($aw ? empty($sport['ammerseewind']) : !fremd_gilt($sport))) {
        return $proTag;
    }
    $now = $jetzt ?? new DateTimeImmutable('now');
    $lok = $now->setTimezone(new DateTimeZone($region['zeitzone']));
    foreach ($proTag as $tag => $d) {
        $f = $p['tage'][$tag] ?? null;
        $heute = $tag === $lok->format('Y-m-d');
        if ($d === null || $f === null || !empty($d['vorbei']) || str_starts_with((string)($d['det']['grund'] ?? ''), 'Sperre')
            || ($heute && !$aw && (int)$lok->format('G') >= FREMD_HEUTE_BIS)
            || ($heute && $aw && $now->getTimestamp() - $p['stand'] > FREMD_HEUTE_FRISCH_S)) {
            continue;
        }
        $chance = $aw ? ($f['chancen'][$sport['ammerseewind']] ?? null) : $f['chance'];
        if ($chance === null) {
            continue;
        }
        // Stufe wie in den Mails von ammerseewind.de: ab 60 % "lohnt sich", ab 10 % "vielleicht"
        $stufe = $aw ? ($chance >= 0.6 ? 'lohnt' : ($chance >= 0.1 ? 'maybe' : 'no')) : $f['stufe'];
        $proTag[$tag]['ens'] = ['chance' => $chance, 'laeufe' => 0];
        $proTag[$tag]['offen'] = false;
        $proTag[$tag]['fremd'] = ['name' => $q['name'], 'url' => $q['url'], 'stufe' => $stufe,
                                  'peak_kt' => $f['peak_kt'], 'stand' => $p['stand']];
    }
    return $proTag;
}

/** "GO · Spitze 17 kn · Stand 6:10" fuer die Seite */
function fremd_text(array $fr, DateTimeZone $tz): string
{
    $teile = [FREMD_STUFEN[$fr['stufe']] ?? $fr['stufe']];
    if ($fr['peak_kt'] !== null) {
        $teile[] = 'Spitze ' . (string)round($fr['peak_kt']) . ' kn';
    }
    $teile[] = 'Stand ' . (new DateTimeImmutable('@' . $fr['stand']))->setTimezone($tz)->format('G:i');
    return implode(' · ', $teile);
}

// ===================================================================
// RANGLISTE
// ===================================================================

function entfernung_km(array $a, array $b): float
{
    [$la1, $lo1] = array_map('deg2rad', $a);
    [$la2, $lo2] = array_map('deg2rad', $b);
    $h = sin(($la2 - $la1) / 2) ** 2 + cos($la1) * cos($la2) * sin(($lo2 - $lo1) / 2) ** 2;
    return 2 * 6371.0 * asin(min(1.0, sqrt($h)));
}

/**
 * Regionen im Umkreis (Luftlinie zum Vorhersagepunkt), bewertet fuer $tage.
 * $stand: [quelle => [region_id => eintrag]] aus daten_laden().
 * Sortiert nach der Summe der Chancen ueber die Tage (= erwartete gute Tage),
 * ohne Ensemble nach Zahl der Tage, die nach dem Modellmittel gehen.
 */
function rangliste(array $regionen, array $stand, array $sport, array $heim, float $radiusKm, array $tage, ?DateTimeImmutable $jetzt = null): array
{
    $liste = [];
    foreach ($regionen as $r) {
        $km = entfernung_km($heim, $r['punkt']);
        if ($km > $radiusKm) {
            continue;
        }
        $daten = region_daten($r, $stand);
        if ($daten === null) {
            continue;
        }
        $liste[] = ranglisten_eintrag($r, $km, fremd_einmischen($r, region_bewerten($r, $daten, $sport, $tage, $jetzt), $sport, $jetzt));
    }
    return rangliste_sortieren($liste, 'summe');
}

/** Rohdaten einer Region aus daten_laden(); null ohne Vorhersage und Ensemble */
function region_daten(array $r, array $stand): ?array
{
    $daten = [
        'vorhersage' => $stand['vorhersage'][$r['id']] ?? null,
        'ensemble'   => $stand['ensemble'][$r['id']] ?? null,
        'welle'      => $stand['welle'][$r['id']] ?? null,
        'wasser'     => wasser_eintrag($stand, $r['id']),
        'druck'          => $stand['druck'] ?? [],
        'druck_ensemble' => $stand['druck_ensemble'] ?? [],
        'hoehe'          => $stand['hoehe'][$r['id']] ?? null,
        'hoehe_ensemble' => $stand['hoehe_ensemble'][$r['id']] ?? null,
    ];
    return !$daten['vorhersage'] && !$daten['ensemble'] ? null : $daten;
}

/** Eintrag der Rangliste aus der Bewertung je Tag (region_bewerten) */
function ranglisten_eintrag(array $r, float $km, array $proTag): array
{
    $erwartet = null;
    $det = 0;
    foreach ($proTag as $t) {
        if ($t['ens'] !== null) {
            $erwartet = ($erwartet ?? 0) + $t['ens']['chance'];
        }
        $det += $t['det']['geht'] ? 1 : 0;
    }
    return ['region' => $r, 'km' => round($km), 'tage' => $proTag,
            'erwartete_tage' => $erwartet, 'tage_modellmittel' => $det];
}

/**
 * Reihenfolge der Rangliste (seit 7.11.2026 waehlbar):
 *   'summe'      erwartete gute Tage ueber alle angezeigten Tage (Standard),
 *                dann Tage nach Modellmittel, dann naeher zuerst
 *   'YYYY-MM-DD' nur dieser Tag: Chance, dann geht/laengeres Fenster/staerker
 *                nach Modellmittel, dann naeher zuerst; ohne Daten oder vorbei zuletzt
 *   'km'         naeher zuerst: nach $e['fahrt']['min'], wenn gesetzt (Fahrzeit), sonst Luftlinie
 * Ein Tag, der nicht in der Liste vorkommt, wird wie 'summe' behandelt.
 */
function rangliste_sortieren(array $liste, string $nach): array
{
    if ($nach === 'km') {
        // Seit 7.11.2026 nach Fahrzeit, wenn die Seite sie gesetzt hat; ohne Landweg zuletzt
        $k = fn($e) => isset($e['fahrt']) ? [$e['fahrt']['min'] === null ? 1 : 0, $e['fahrt']['min'] ?? 0, $e['km']] : [0, $e['km'], 0];
        usort($liste, fn($a, $b) => $k($a) <=> $k($b));
        return $liste;
    }
    $tagDa = $liste && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nach) && isset($liste[0]['tage'][$nach]);
    if (!$tagDa) {
        usort($liste, fn($a, $b) => [$b['erwartete_tage'] ?? -1, $b['tage_modellmittel'], -$b['km']]
                                <=> [$a['erwartete_tage'] ?? -1, $a['tage_modellmittel'], -$a['km']]);
        return $liste;
    }
    $schluessel = function (array $e) use ($nach): array {
        $t = $e['tage'][$nach] ?? null;
        $det = $t['det'] ?? ['geht' => false, 'stunden' => 0];
        return [$t['ens']['chance'] ?? -1, $det['geht'] ? 1 : 0, $det['stunden'] ?? 0, $det['mittel_kn'] ?? 0, -$e['km']];
    };
    usort($liste, fn($a, $b) => $schluessel($b) <=> $schluessel($a));
    return $liste;
}

// ===================================================================
// DATEN LADEN
// ===================================================================

/**
 * Neuester erfolgreicher Eintrag je Region und Quelle (heute oder gestern, UTC).
 * Liest beide Formen des Sammlers: eine Datei je Tag (Cron, 2026-11-07.ndjson)
 * und eine gezippte je Lauf (GitHub Action, 2026-11-07_0525.ndjson.gz).
 * Rueckgabe [quelle => [region_id => eintrag]], dazu ['_ts'][quelle][region_id].
 */
function daten_laden(string $dataDir, array $quellen = ['vorhersage', 'ensemble', 'welle', 'wasser', 'seewasser', 'druck', 'druck_ensemble', 'hoehe', 'hoehe_ensemble'], ?array $nur = null, bool $fingerabdruck = true): array
{
    // $nur: Regionen, deren Eintraege behalten werden (Umkreis); null = alle.
    // Stand (_ts) und Fingerabdruck des Ensembles (_fp) gibt es fuer alle Regionen,
    // der Verlauf braucht sie. Druckquellen sind nach Druckpunkt geschluesselt
    // und werden nie gefiltert. $fingerabdruck false: _fp nur fuer $nur (spart das
    // Entpacken aller Ensemble-Eintraege, wenn _fp schon bekannt ist; seit 8.11.2026).
    $behalten = $nur === null ? null : array_fill_keys($nur, true);
    $out = ['_ts' => [], '_fp' => []];
    foreach ($quellen as $q) {
        $out[$q] = [];
        $out['_ts'][$q] = [];
        $filter = $behalten !== null && !in_array($q, ['druck', 'druck_ensemble'], true);
        $dateien = [];
        foreach ([gmdate('Y-m-d'), gmdate('Y-m-d', time() - 86400)] as $d) {
            $dateien = array_merge($dateien, glob("$dataDir/$q/$d.ndjson") ?: [], glob("$dataDir/$q/{$d}_*.ndjson.gz") ?: []);
        }
        rsort($dateien);   // neueste zuerst
        foreach ($dateien as $f) {
            // Nicht gzfile(): das teilt lange Zeilen (ein Abruf = eine Zeile, oft > 100 KB)
            $txt = (string)@file_get_contents($f);
            if (str_ends_with($f, '.gz')) {
                $txt = (string)@gzdecode($txt);
            }
            $zeilen = array_values(array_filter(array_map('trim', explode("\n", $txt))));
            unset($txt);
            $fehlt = fn($id) => !isset($out['_ts'][$q][$id]);
            for ($i = count($zeilen) - 1; $i >= 0; $i--) {
                // Aeltere Zeilen nur entpacken, wenn sie noch fehlende Regionen haben
                $kopf = zeile_kopf($zeilen[$i]);
                if ($kopf !== null && (!$kopf['ok'] || !array_filter($kopf['regionen'], $fehlt))) {
                    continue;
                }
                // Einzeln entpacken, nur was fehlt (Speicher, siehe zeile_eintraege). Ausserhalb
                // des Umkreises nur fuer den Fingerabdruck des Ensembles, danach verworfen.
                $z = zeile_eintraege($zeilen[$i], fn($id) => $fehlt($id) && (!$filter || isset($behalten[$id]) || ($q === 'ensemble' && $fingerabdruck)));
                if ($z === null) {
                    continue;
                }
                foreach ($z['regionen'] as $id) {
                    if (!$fehlt($id)) {
                        continue;
                    }
                    $e = $z['eintraege'][$id] ?? null;
                    if ($e !== null && !isset($e['hourly'])) {
                        continue;
                    }
                    if ($e === null && !($filter && !isset($behalten[$id]))) {
                        continue;   // nicht entpackt, obwohl gebraucht: unbrauchbar
                    }
                    $out['_ts'][$q][$id] = $z['ts'];
                    if ($q === 'ensemble' && $e !== null) {
                        $out['_fp']['ensemble'][$id] = ensemble_fingerabdruck($e);
                    }
                    if ($e !== null && (!$filter || isset($behalten[$id]))) {
                        $out[$q][$id] = $e;
                    }
                }
                unset($z, $e);
            }
            unset($zeilen);
        }
        if (!$out['_ts'][$q]) {
            unset($out['_ts'][$q]);
        }
    }
    return $out;
}

// ===================================================================
// VERLAUF UEBER DIE LAEUFE (seit 7.11.2026)
// ===================================================================
//
// Dieselbe Chance fuer denselben Tag aus frueheren Abrufen des Ensembles:
// "stabil seit 4 Laeufen" oder "faellt (80 -> 40 %)". Gerechnet wird mit
// demselben Fenster wie jetzt (auch dem Restfenster von heute), damit sich nur
// die Vorhersage aendert, nicht die Frage.
//
// Speicher: Ein Ensemble-Eintrag ist entpackt gut 1 MB je Region. Fruehere
// Laeufe werden deshalb Zeile fuer Zeile gelesen, sofort bewertet und wieder
// verworfen; behalten wird nur die Chance je Tag.

const VERLAUF_FRUEHER = 3;        // fruehere Laeufe je Region (mit dem aktuellen also bis zu 4 Werte)
const VERLAUF_SUCHTAGE = 3;       // so viele UTC-Tage zurueck suchen
const VERLAUF_SCHWELLE = 0.15;    // ab 15 Prozentpunkten gilt es als Aenderung
const VERLAUF_BEGLEIT_MIN = 90;   // Druck/Hoehenwind gehoert zum Ensemble-Abruf, wenn hoechstens so weit entfernt

/** Dateien einer Quelle der letzten $tage UTC-Tage, neueste zuerst (beide Formen des Sammlers, auch alte gezippte) */
function ndjson_dateien(string $dataDir, string $q, int $tage, ?int $jetzt = null): array
{
    $jetzt ??= time();
    $dateien = [];
    for ($i = 0; $i < $tage; $i++) {
        $d = gmdate('Y-m-d', $jetzt - $i * 86400);
        $dateien = array_merge($dateien, glob("$dataDir/$q/$d.ndjson") ?: [], glob("$dataDir/$q/$d.ndjson.gz") ?: [],
                               glob("$dataDir/$q/{$d}_*.ndjson.gz") ?: []);
    }
    $dateien = array_values(array_unique($dateien));
    rsort($dateien);
    return $dateien;
}

/** Zeilen einer Datei, aelteste zuerst */
function ndjson_zeilen(string $f): array
{
    $txt = (string)@file_get_contents($f);
    if (str_ends_with($f, '.gz')) {
        $txt = (string)@gzdecode($txt);
    }
    return array_values(array_filter(array_map('trim', explode("\n", $txt))));
}

/**
 * Kopf einer Zeile (ts, ok, regionen), ohne die ganze Zeile zu entpacken.
 * Der Sammler schreibt ts, source, ok, http, ms, regionen vor payload.
 * Faellt sonst auf json_decode zurueck. null = unbrauchbar.
 */
function zeile_kopf(string $zeile): ?array
{
    $anfang = substr($zeile, 0, 4000);
    if (preg_match('/^\{"ts":"([^"]+)".*?"ok":(true|false).*?"regionen":(\[[^\]]*\])/s', $anfang, $m)) {
        $reg = json_decode($m[3], true);
        if (is_array($reg)) {
            return ['ts' => $m[1], 'ok' => $m[2] === 'true', 'regionen' => $reg];
        }
    }
    $z = json_decode($zeile, true);
    if (!is_array($z) || !isset($z['ts'], $z['regionen'])) {
        return null;
    }
    return ['ts' => $z['ts'], 'ok' => !empty($z['ok']), 'regionen' => $z['regionen']];
}

/**
 * Die Eintraege einer Zeile einzeln entpacken, nur fuer Regionen, die $brauche
 * will. Rueckgabe ['ts', 'regionen', 'eintraege' => [id => eintrag]] oder null.
 *
 * Speicher (7.11.2026): Eine Ensemble-Zeile mit 25 Regionen ist entpackt rund
 * 30 MB. Mit dem aktuellen Stand und dem Verlauf zugleich lag die Seite bei
 * 146 MB und damit ueber 128 MB (HTTP 500). Deshalb wird die payload-Liste als
 * Text in ihre Eintraege geteilt und nur das Noetige entpackt. Der Sammler
 * schreibt payload als letztes Feld, jeder Eintrag von Open-Meteo beginnt mit
 * {"latitude": . Passt das nicht (Anzahl, JSON), wird die ganze Zeile entpackt.
 */
function zeile_eintraege(string $zeile, callable $brauche): ?array
{
    $kopf = zeile_kopf($zeile);
    if ($kopf === null || !$kopf['ok']) {
        return null;
    }
    $ids = array_values($kopf['regionen']);
    $teile = payload_teile($zeile, count($ids));
    $out = ['ts' => $kopf['ts'], 'regionen' => $ids, 'eintraege' => []];
    if ($teile !== null) {
        foreach ($ids as $k => $id) {
            if ($brauche($id)) {
                $e = json_decode($teile[$k], true);
                if (!is_array($e)) {
                    $teile = null;   // Teilung stimmt nicht: unten alles entpacken
                    $out['eintraege'] = [];
                    break;
                }
                $out['eintraege'][$id] = $e;
            }
        }
    }
    if ($teile === null) {
        $z = json_decode($zeile, true);
        if (!is_array($z) || empty($z['ok']) || !isset($z['payload'], $z['regionen'])) {
            return null;
        }
        foreach ($z['regionen'] as $k => $id) {
            if ($brauche($id) && isset($z['payload'][$k])) {
                $out['eintraege'][$id] = $z['payload'][$k];
            }
        }
    }
    return $out;
}

/** payload einer Zeile als Liste von JSON-Texten je Eintrag; null, wenn die Form nicht passt */
function payload_teile(string $zeile, int $anzahl): ?array
{
    $start = strpos($zeile, '"payload":[{');
    if ($start === false || $anzahl < 1 || !str_ends_with($zeile, '}]}')) {
        return null;
    }
    $innen = substr($zeile, $start + 11, -3);   // ab dem ersten "{" bis vor "]}"; letzter Eintrag ohne "}"
    $teile = explode('},{"latitude":', $innen);
    if (count($teile) !== $anzahl || !str_starts_with($teile[0], '{"latitude":')) {
        return null;
    }
    foreach ($teile as $i => $t) {
        $teile[$i] = ($i === 0 ? '' : '{"latitude":') . $t . '}';
    }
    return $teile;
}

/** Fingerabdruck eines Ensemble-Eintrags: der Kontrolllauf reicht, um gleiche Modelllaeufe zu erkennen */
function ensemble_fingerabdruck(array $e): string
{
    $h = $e['hourly'] ?? [];
    return md5(json_encode([$h['time'][0] ?? null, $h['wind_speed_10m'] ?? null, $h['wind_direction_10m'] ?? null]));
}

/**
 * Begleitdaten (druck_ensemble, hoehe_ensemble) zu einem frueheren Ensemble-Abruf:
 * je Druckpunkt bzw. Region der Eintrag mit dem naechsten ts, hoechstens
 * VERLAUF_BEGLEIT_MIN entfernt. Rueckgabe [id => eintrag].
 * $merk haelt den Kopf-Index und die zuletzt entpackte Zeile je Quelle.
 */
function begleitdaten(string $dataDir, string $q, string $ts, array &$merk, int $jetzt): array
{
    if (!isset($merk[$q]['index'])) {
        $merk[$q]['index'] = [];
        foreach (ndjson_dateien($dataDir, $q, VERLAUF_SUCHTAGE, $jetzt) as $f) {
            foreach (ndjson_zeilen($f) as $nr => $z) {
                $k = zeile_kopf($z);
                if ($k && $k['ok']) {
                    $merk[$q]['index'][] = ['ts' => $k['ts'], 'datei' => $f, 'nr' => $nr, 'regionen' => $k['regionen']];
                }
            }
        }
    }
    $t = strtotime($ts);
    $wahl = [];
    $abstand = [];
    foreach ($merk[$q]['index'] as $i => $k) {
        $d = abs(strtotime($k['ts']) - $t);
        if ($d > VERLAUF_BEGLEIT_MIN * 60) {
            continue;
        }
        foreach ($k['regionen'] as $id) {
            if (!isset($abstand[$id]) || $d < $abstand[$id]) {
                $abstand[$id] = $d;
                $wahl[$id] = $i;
            }
        }
    }
    $ergebnis = [];
    foreach ($wahl as $id => $i) {
        $k = $merk[$q]['index'][$i];
        $schluessel = $k['datei'] . '#' . $k['nr'];
        if (($merk[$q]['zeile'] ?? null) !== $schluessel) {
            $merk[$q]['daten'] = null;   // erst freigeben, dann die naechste Zeile entpacken
            $zeilen = ndjson_zeilen($k['datei']);
            $merk[$q]['zeile'] = $schluessel;
            $merk[$q]['daten'] = json_decode($zeilen[$k['nr']] ?? 'null', true);
        }
        $pos = array_search($id, $k['regionen'], true);
        if ($pos !== false && isset($merk[$q]['daten']['payload'][$pos]['hourly'])) {
            $ergebnis[$id] = $merk[$q]['daten']['payload'][$pos];
        }
    }
    return $ergebnis;
}

/**
 * Chancen aus frueheren Ensemble-Abrufen fuer die angezeigten Tage.
 * Nur Abrufe aelter als der aktuelle Stand der Region, gleiche Modelllaeufe
 * einmal (Fingerabdruck). Fehlen einer Region mit Regel-Windlagen die Druck-
 * oder Hoehendaten des frueheren Abrufs, zaehlt der Abruf fuer sie nicht.
 * Rueckgabe [region_id => [tag => [['ts' =>, 'chance' =>], ...]]], aelteste zuerst.
 */
function verlauf_rechnen(array $regionen, string $dataDir, array $stand, array $sport, array $tage, ?DateTimeImmutable $jetzt = null): array
{
    $jetzt ??= new DateTimeImmutable('now');
    $offen = [];   // id => region, solange noch fruehere Laeufe fehlen
    $gesehen = [];
    $zahl = [];
    $out = [];
    foreach ($regionen as $r) {
        // Fingerabdruck aus daten_laden; die Seite gibt die Ensemble-Daten danach frei
        $fpAkt = $stand['_fp']['ensemble'][$r['id']]
            ?? (isset($stand['ensemble'][$r['id']]) ? ensemble_fingerabdruck($stand['ensemble'][$r['id']]) : null);
        if ($fpAkt === null) {
            continue;
        }
        $offen[$r['id']] = $r;
        $gesehen[$r['id']] = [$fpAkt => true];
        $zahl[$r['id']] = 0;
        $out[$r['id']] = [];
    }
    $merk = [];
    foreach (ndjson_dateien($dataDir, 'ensemble', VERLAUF_SUCHTAGE, $jetzt->getTimestamp()) as $f) {
        if (!$offen) {
            break;
        }
        $zeilen = ndjson_zeilen($f);
        for ($i = count($zeilen) - 1; $i >= 0 && $offen; $i--) {
            $kopf = zeile_kopf($zeilen[$i]);
            if (!$kopf || !$kopf['ok']) {
                continue;
            }
            $brauchen = array_filter($kopf['regionen'], fn($id) => isset($offen[$id])
                && $kopf['ts'] < ($stand['_ts']['ensemble'][$id] ?? ''));
            if (!$brauchen) {
                continue;
            }
            $z = zeile_eintraege($zeilen[$i], fn($id) => in_array($id, $brauchen, true));
            foreach ($brauchen as $id) {
                $e = $z['eintraege'][$id] ?? null;
                if (!isset($e['hourly']) || !isset($offen[$id])) {
                    continue;
                }
                $fp = ensemble_fingerabdruck($e);
                if (isset($gesehen[$id][$fp])) {
                    continue;
                }
                $gesehen[$id][$fp] = true;
                $region = $offen[$id];
                [$achsen, $hoehen] = region_bedarf($region);
                $daten = ['vorhersage' => null, 'ensemble' => $e, 'welle' => null, 'druck' => [], 'hoehe' => null,
                          'druck_ensemble' => [], 'hoehe_ensemble' => null];
                $fehlt = false;
                if ($achsen) {
                    $daten['druck_ensemble'] = begleitdaten($dataDir, 'druck_ensemble', $kopf['ts'], $merk, $jetzt->getTimestamp());
                    foreach ($achsen as [$plus, $minus]) {
                        $fehlt = $fehlt || !isset($daten['druck_ensemble'][$plus], $daten['druck_ensemble'][$minus]);
                    }
                }
                if (array_diff($hoehen, ['10m'])) {
                    $daten['hoehe_ensemble'] = begleitdaten($dataDir, 'hoehe_ensemble', $kopf['ts'], $merk, $jetzt->getTimestamp())[$id] ?? null;
                    $fehlt = $fehlt || $daten['hoehe_ensemble'] === null;
                }
                if ($fehlt) {
                    continue;
                }
                foreach (region_bewerten($region, $daten, $sport, $tage, $jetzt) as $tag => $t) {
                    if ($t['ens'] !== null) {
                        $out[$id][$tag] ??= [];
                        array_unshift($out[$id][$tag], ['ts' => $kopf['ts'], 'chance' => $t['ens']['chance']]);
                    }
                }
                if (++$zahl[$id] >= VERLAUF_FRUEHER) {
                    unset($offen[$id]);
                }
            }
            unset($z);
        }
    }
    return $out;
}

/**
 * verlauf_rechnen mit Zwischenspeicher. Fruehere Abrufe aendern sich nicht;
 * der Schluessel haengt an Sportart, Tagen, Stunde (Restfenster von heute),
 * Katalog und den vorhandenen Dateien. Gerechnet wird fuer alle Regionen, damit
 * Umkreis und Standort den Speicher nicht teilen. $cacheDir null oder nicht
 * beschreibbar: ohne Zwischenspeicher. Dateien aelter als ein Tag werden geloescht.
 */
function verlauf_mit_cache(array $regionen, string $dataDir, ?string $cacheDir, array $stand, array $sport, array $tage, ?DateTimeImmutable $jetzt = null, string $katalogDatei = ''): array
{
    $jetzt ??= new DateTimeImmutable('now');
    $dateien = [];
    foreach (['ensemble', 'druck_ensemble', 'hoehe_ensemble'] as $q) {
        foreach (ndjson_dateien($dataDir, $q, VERLAUF_SUCHTAGE, $jetzt->getTimestamp()) as $f) {
            $dateien[] = basename($f) . ':' . @filesize($f);
        }
    }
    $key = md5(json_encode([$sport, $tage, $jetzt->format('Y-m-d H'), $dateien, $stand['_ts']['ensemble'] ?? [],
                            $katalogDatei !== '' ? @filemtime($katalogDatei) : null, VERLAUF_FRUEHER]));
    $datei = $cacheDir !== null ? "$cacheDir/verlauf-$key.json" : null;
    if ($datei !== null && is_file($datei)) {
        $v = json_decode((string)@file_get_contents($datei), true);
        if (is_array($v)) {
            return $v;
        }
    }
    $v = verlauf_rechnen($regionen, $dataDir, $stand, $sport, $tage, $jetzt);
    if ($datei !== null && (is_dir($cacheDir) || @mkdir($cacheDir, 0700, true)) && is_writable($cacheDir)) {
        $tmp = "$datei." . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($v)) !== false) {
            @rename($tmp, $datei);
        }
        foreach (glob("$cacheDir/verlauf-*.json") ?: [] as $alt) {
            if (@filemtime($alt) < time() - 86400) {
                @unlink($alt);
            }
        }
    }
    return $v;
}

/**
 * Einordnung des Verlaufs. $frueher aelteste zuerst, $jetzt die aktuelle Chance.
 *   stabil    mindestens 3 Werte, Spanne hoechstens VERLAUF_SCHWELLE
 *   steigt    jetzt mindestens VERLAUF_SCHWELLE ueber dem aeltesten Wert
 *   faellt    jetzt mindestens VERLAUF_SCHWELLE darunter
 *   schwankt  groessere Spanne, aber kein klarer Weg
 *   gleich    nur 2 Werte, kaum Unterschied
 * Rueckgabe ['art', 'werte' (aelteste zuerst, inkl. aktuell), 'ts' (der frueheren)] oder null.
 */
function verlauf_trend(array $frueher, ?float $jetzt): ?array
{
    if ($jetzt === null || !$frueher) {
        return null;
    }
    $werte = array_merge(array_column($frueher, 'chance'), [$jetzt]);
    $spanne = max($werte) - min($werte);
    $weg = $jetzt - $werte[0];
    $eps = 1e-9;
    $art = match (true) {
        count($werte) >= 3 && $spanne <= VERLAUF_SCHWELLE + $eps => 'stabil',
        $weg >= VERLAUF_SCHWELLE - $eps => 'steigt',
        $weg <= -VERLAUF_SCHWELLE + $eps => 'faellt',
        $spanne > VERLAUF_SCHWELLE + $eps => 'schwankt',
        default => 'gleich',
    };
    return ['art' => $art, 'werte' => $werte, 'ts' => array_column($frueher, 'ts')];
}
