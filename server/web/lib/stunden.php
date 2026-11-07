<?php
/**
 * Stundenwerte einer Region fuer einen Tag (seit 7.11.2026), fuer stunden.php.
 *
 * Je Stunde: Wind je Modell, Modellmittel, Boeen, Richtung, Spanne des
 * ECMWF-Ensembles (10. bis 90. Perzentil), Anteil der Laeufe, die die Stunde
 * fahrbar sehen, und ob die Stunde im Modellmittel fahrbar ist. Gerechnet fuer
 * den besten Spot des Tages (wie in der Rangliste); Regel-Windlagen wie dort.
 */

const STUNDEN_MODELLNAMEN = [
    'icon_d2' => 'ICON-D2', 'icon_eu' => 'ICON-EU', 'ecmwf_ifs' => 'ECMWF', 'gfs_seamless' => 'GFS',
    'meteofrance_arome_france_hd' => 'AROME', '_' => 'Modell',
];
const STUNDEN_RAND = 2;   // so viele Stunden vor und nach dem Tagesfenster zeigen

/** Perzentil (0..1) einer Liste, linear interpoliert */
function perzentil(array $w, float $p): ?float
{
    if (!$w) {
        return null;
    }
    sort($w);
    $x = $p * (count($w) - 1);
    $u = (int)floor($x);
    $o = (int)ceil($x);
    return $w[$u] + ($w[$o] - $w[$u]) * ($x - $u);
}

/**
 * Rueckgabe ['tag', 'fenster' [von, bis], 'von', 'bis' (gezeigte Stunden [von, bis)),
 *            'spot' => id|null, 'spot_name', 'bewertung' (wie region_bewerten fuer den Tag),
 *            'modelle' => [id => name], 'stunden' => [h => [...]]]
 * oder null ohne Vorhersage.
 */
function region_stunden(array $region, array $daten, array $sport, string $tag, ?DateTimeImmutable $jetzt = null): ?array
{
    if (empty($daten['vorhersage']['hourly'])) {
        return null;
    }
    $tz = new DateTimeZone($region['zeitzone']);
    $bew = region_bewerten($region, $daten, $sport, [$tag], $jetzt)[$tag];
    $fenster = tagesfenster($tag, $tz, (float)$region['punkt'][0], (float)$region['punkt'][1]);

    $vh = vorhersage_stunden($daten['vorhersage'], $tz);
    $ws = welle_stunden($daten['welle'] ?? null, $tz);
    $wd = welle_details($daten['welle'] ?? null, $tz);
    $ens = ensemble_stunden($daten['ensemble'] ?? null, $tz);
    [$vh, $ens] = regel_daten_einmischen($region, $daten, $tz, $vh, $ens);
    $ens = ensemble_welle_einmischen($ens, $ws, $sport);

    // Wind je Modell
    $h = $daten['vorhersage']['hourly'];
    $orte = ortszeiten($h['time'], $tz);
    $proModell = [];
    foreach (modell_reihen($h, 'wind_speed_10m') as $m => $reihe) {
        foreach ($orte as $i => [$t, $s]) {
            if ($t === $tag && isset($reihe[$i])) {
                $proModell[$s][$m] = (float)$reihe[$i];
            }
        }
    }
    $modelle = [];
    foreach ($proModell as $werte) {
        foreach ($werte as $m => $_) {
            $modelle[$m] = STUNDEN_MODELLNAMEN[$m] ?? $m;
        }
    }

    $spot = null;
    foreach ($region['spots'] ?? [] as $sp) {
        if (($sp['id'] ?? null) === $bew['spot']) {
            $spot = $sp;
        }
    }
    $wl = spot_windlagen($region, $spot);
    $monat = (int)substr($tag, 5, 2);
    $von = max(0, $fenster[0] - STUNDEN_RAND);
    $bis = min(24, $fenster[1] + STUNDEN_RAND);

    $stunden = [];
    for ($s = $von; $s < $bis; $s++) {
        $w = $vh[$tag][$s] ?? null;
        $zeile = ['kn' => null, 'boe' => null, 'richtung' => null, 'modelle' => $proModell[$s] ?? [],
                  'ens_p10' => null, 'ens_p50' => null, 'ens_p90' => null, 'ens_anteil' => null,
                  'fahrbar' => false, 'grund' => null, 'regel' => false, 'welle' => $wd[$tag][$s] ?? null];
        if ($w !== null) {
            $w['welle'] = $ws[$tag][$s] ?? null;
            $w['welle_info'] = $wd[$tag][$s] ?? null;
            [$ok, $grund, $lage] = stunde_fahrbar($w + ['_h' => $s, '_monat' => $monat], $sport, $wl, $spot);
            $zeile = array_merge($zeile, ['kn' => $w['kn'], 'boe' => $w['boe'], 'richtung' => $w['richtung'],
                                          'fahrbar' => $ok && $s >= $fenster[0] && $s < $fenster[1], 'grund' => $ok ? null : $grund,
                                          'regel' => $ok && !empty($lage['regel'])]);
        }
        $kn = [];
        $ja = 0;
        foreach (empty($sport['surf']) ? $ens : [] as $lauf) {   // Wellenreiten: Ensemble ohne Welle
            $e = $lauf[$tag][$s] ?? null;
            if ($e === null) {
                continue;
            }
            $kn[] = $e['kn'];
            if (stunde_fahrbar($e + ['_h' => $s, '_monat' => $monat], $sport, $wl, $spot)[0]) {
                $ja++;
            }
        }
        if ($kn) {
            $zeile['ens_p10'] = perzentil($kn, 0.1);
            $zeile['ens_p50'] = perzentil($kn, 0.5);
            $zeile['ens_p90'] = perzentil($kn, 0.9);
            $zeile['ens_anteil'] = $ja / count($kn);
        }
        $stunden[$s] = $zeile;
    }
    return ['tag' => $tag, 'fenster' => $fenster, 'hat_welle' => (bool)array_filter(array_column($stunden, 'welle')), 'von' => $von, 'bis' => $bis, 'spot' => $bew['spot'],
            'spot_name' => $bew['spot_name'], 'bewertung' => $bew, 'modelle' => $modelle, 'stunden' => $stunden];
}
