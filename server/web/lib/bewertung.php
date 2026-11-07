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
 * nach denselben Regeln geht - ohne Boeen, Gewitter und Welle, weil das
 * Ensemble nur Wind und Richtung liefert. ROHWERTE, NICHT KALIBRIERT.
 */

const MIN_STUNDEN = 2;
const FENSTER_AB = 9;          // Ortszeit, wie stegen-wind seit 22.10.2026
const REST_VORLAUF_MIN = 30;   // heute: erst ab jetzt + 30 min (Anfahrt, Aufbau), wie stegen-wind

/** Voreinstellungen wie in stegen-wind (sport.php): nur 14 kn Directional ist dort kalibriert */
const SPORTARTEN = [
    'kite_directional' => ['name' => 'Kite Directional', 'min' => 14],
    'kite_twintip'     => ['name' => 'Kite Twintip', 'min' => 14],
    'kitefoil'         => ['name' => 'Kitefoil', 'min' => 11],
    'wingfoil'         => ['name' => 'Wingfoil', 'min' => 16],
    'windsurf'         => ['name' => 'Windsurf', 'min' => 17],
    'windsurf_foil'    => ['name' => 'Windsurf Foil', 'min' => 12],
    'segeln'           => ['name' => 'Segeln', 'min' => 5, 'max' => 18],
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
    $out = [];
    foreach ($h['time'] as $i => $t) {
        $lok = (new DateTimeImmutable($t . ':00Z'))->setTimezone($tz);
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
        $out[$lok->format('Y-m-d')][(int)$lok->format('G')] = [
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

/** Wellenhoehe je Ortstag und Stunde aus einem Marine-Eintrag */
function welle_stunden(?array $eintrag, DateTimeZone $tz): array
{
    if (!$eintrag) {
        return [];
    }
    $h = $eintrag['hourly'];
    $out = [];
    foreach ($h['time'] as $i => $t) {
        if (!isset($h['wave_height'][$i])) {
            continue;
        }
        $lok = (new DateTimeImmutable($t . ':00Z'))->setTimezone($tz);
        $out[$lok->format('Y-m-d')][(int)$lok->format('G')] = (float)$h['wave_height'][$i];
    }
    return $out;
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
    $laeufe = [];
    foreach ($h as $k => $reihe) {
        if (!preg_match('/^wind_speed_10m(_member\d+)?$/', $k, $m)) {
            continue;
        }
        $suffix = $m[1] ?? '';
        $richtung = $h['wind_direction_10m' . $suffix] ?? [];
        $wolken = $h['cloud_cover' . $suffix] ?? [];
        foreach ($h['time'] as $i => $t) {
            if (!isset($reihe[$i])) {
                continue;
            }
            $lok = (new DateTimeImmutable($t . ':00Z'))->setTimezone($tz);
            $laeufe[$suffix][$lok->format('Y-m-d')][(int)$lok->format('G')] = [
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
    $out = [];
    foreach ($h['time'] as $i => $t) {
        $v = [];
        foreach ($reihen as $reihe) {
            if (isset($reihe[$i])) {
                $v[] = (float)$reihe[$i];
            }
        }
        if (!$v) {
            continue;
        }
        $lok = (new DateTimeImmutable($t . ':00Z'))->setTimezone($tz);
        $out[$lok->format('Y-m-d')][(int)$lok->format('G')] = $richtung ? richtung_mittel($v) : array_sum($v) / count($v);
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
    $out = [];
    foreach ($h as $k => $reihe) {
        if (!preg_match('/^' . preg_quote($var, '/') . '(_member\d+)?$/', $k, $m)) {
            continue;
        }
        foreach ($h['time'] as $i => $t) {
            if (!isset($reihe[$i])) {
                continue;
            }
            $lok = (new DateTimeImmutable($t . ':00Z'))->setTimezone($tz);
            $out[$m[1] ?? ''][$lok->format('Y-m-d')][(int)$lok->format('G')] = (float)$reihe[$i];
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
function tagesfenster(string $tag, DateTimeZone $tz, float $lat, float $lon): array
{
    $mittag = new DateTimeImmutable("$tag 12:00", $tz);
    $sonne = date_sun_info($mittag->getTimestamp(), $lat, $lon);
    $ab = FENSTER_AB;
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
            $lauf[$s] = ['lage' => $lage, 'kn' => $knEff];
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
 * Nur Laeufe mit Werten fuer alle Fensterstunden zaehlen. null = keine Daten.
 */
function ensemble_chance(array $laeufe, string $tag, array $fenster, array $sport, array $windlagen, ?array $spot): ?array
{
    [$ab, $bis] = $fenster;
    $n = $ja = 0;
    foreach ($laeufe as $proTag) {
        $st = $proTag[$tag] ?? [];
        $voll = true;
        for ($s = $ab; $s < $bis; $s++) {
            if (!isset($st[$s])) {
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
    $ens = ensemble_stunden($daten['ensemble'] ?? null, $tz);
    [$vh, $ens] = regel_daten_einmischen($region, $daten, $tz, $vh, $ens);
    $spots = $region['spots'] ?? [];
    if (!$spots) {
        $spots = [null];   // Region selbst, mit ihren Windlagen
    }

    $out = [];
    foreach ($tage as $tag) {
        [$fenster, $rest, $vorbei] = restfenster(tagesfenster($tag, $tz, (float)$lat, (float)$lon), $tag, $tz, $jetzt);
        if ($vorbei) {
            $leer = ['geht' => false, 'stunden' => 0, 'grund' => 'Tag vorbei'];
            $out[$tag] = ['spot' => null, 'spot_name' => null, 'det' => $leer, 'ens' => null,
                          'fenster' => $fenster, 'rest' => true, 'vorbei' => true, 'alle' => []];
            continue;
        }
        $stunden = $vh[$tag] ?? [];
        // Welle auch bei Seen setzen (dann null): eine Wave-Sportart soll dort
        // "keine Wellendaten" bekommen, statt ungeprueft durchzugehen
        foreach ($stunden as $s => $w) {
            $stunden[$s]['welle'] = $ws[$tag][$s] ?? null;
        }
        $alle = [];
        foreach ($spots as $spot) {
            $wl = spot_windlagen($region, $spot);
            $alle[] = [
                'spot'      => $spot['id'] ?? null,
                'spot_name' => $spot['name'] ?? null,
                'det'       => tag_bewerten($stunden, $fenster, $sport, $wl, $spot, (int)substr($tag, 5, 2)),
                'ens'       => ensemble_chance($ens, $tag, $fenster, $sport, $wl, $spot),
            ];
        }
        usort($alle, 'spot_vergleich');
        $out[$tag] = $alle[0] + ['fenster' => $fenster, 'rest' => $rest, 'vorbei' => false, 'alle' => $alle];
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
        $daten = [
            'vorhersage' => $stand['vorhersage'][$r['id']] ?? null,
            'ensemble'   => $stand['ensemble'][$r['id']] ?? null,
            'welle'      => $stand['welle'][$r['id']] ?? null,
            'druck'          => $stand['druck'] ?? [],
            'druck_ensemble' => $stand['druck_ensemble'] ?? [],
            'hoehe'          => $stand['hoehe'][$r['id']] ?? null,
            'hoehe_ensemble' => $stand['hoehe_ensemble'][$r['id']] ?? null,
        ];
        if (!$daten['vorhersage'] && !$daten['ensemble']) {
            continue;
        }
        $proTag = region_bewerten($r, $daten, $sport, $tage, $jetzt);
        $erwartet = null;
        $det = 0;
        foreach ($proTag as $t) {
            if ($t['ens'] !== null) {
                $erwartet = ($erwartet ?? 0) + $t['ens']['chance'];
            }
            $det += $t['det']['geht'] ? 1 : 0;
        }
        $liste[] = ['region' => $r, 'km' => round($km), 'tage' => $proTag,
                    'erwartete_tage' => $erwartet, 'tage_modellmittel' => $det];
    }
    return rangliste_sortieren($liste, 'summe');
}

/**
 * Reihenfolge der Rangliste (seit 7.11.2026 waehlbar):
 *   'summe'      erwartete gute Tage ueber alle angezeigten Tage (Standard),
 *                dann Tage nach Modellmittel, dann naeher zuerst
 *   'YYYY-MM-DD' nur dieser Tag: Chance, dann geht/laengeres Fenster/staerker
 *                nach Modellmittel, dann naeher zuerst; ohne Daten oder vorbei zuletzt
 *   'km'         naeher zuerst
 * Ein Tag, der nicht in der Liste vorkommt, wird wie 'summe' behandelt.
 */
function rangliste_sortieren(array $liste, string $nach): array
{
    if ($nach === 'km') {
        usort($liste, fn($a, $b) => $a['km'] <=> $b['km']);
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
function daten_laden(string $dataDir, array $quellen = ['vorhersage', 'ensemble', 'welle', 'druck', 'druck_ensemble', 'hoehe', 'hoehe_ensemble']): array
{
    $out = ['_ts' => []];
    foreach ($quellen as $q) {
        $out[$q] = [];
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
            for ($i = count($zeilen) - 1; $i >= 0; $i--) {
                $z = json_decode($zeilen[$i], true);
                if (!is_array($z) || empty($z['ok']) || !isset($z['payload'], $z['regionen'])) {
                    continue;
                }
                foreach ($z['regionen'] as $k => $id) {
                    if (!isset($out[$q][$id]) && isset($z['payload'][$k]['hourly'])) {
                        $out[$q][$id] = $z['payload'][$k];
                        $out['_ts'][$q][$id] = $z['ts'];
                    }
                }
            }
        }
    }
    return $out;
}
