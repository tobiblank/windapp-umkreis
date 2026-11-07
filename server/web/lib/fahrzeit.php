<?php
/**
 * Fahrzeit und Abfahrt (seit 7.11.2026).
 *
 * Genau: aus <data_dir>/fahrzeiten.json (server/fahrzeiten.php, openrouteservice),
 * wenn der Standort der Startort dieser Datei ist (Standard Inning).
 * Sonst geschaetzt aus der Luftlinie: FAHRT_SOCKEL_MIN + Faktor x km, der Faktor
 * als Median aus den genauen Werten (ab FAHRT_MIN_KM Luftlinie), ohne Datei
 * FAHRT_FAKTOR_STANDARD. Regionen, die vom Startort keine Route haben (Inseln),
 * bleiben auch geschaetzt ohne Fahrzeit.
 * Ohne Verkehr, Pausen und Grenzwartezeiten.
 */

const FAHRT_SOCKEL_MIN = 10;          // Anfahrt bis zur Autobahn, Parken
const FAHRT_FAKTOR_STANDARD = 0.95;   // Minuten je km Luftlinie: Umweg ~1,3, im Mittel ~80 km/h
const FAHRT_MIN_KM = 50;              // kuerzere Strecken taugen nicht fuer den Faktor
const FAHRT_GLEICHER_START = 0.05;    // Grad: so nah gilt der Standort als Startort der Datei
const ABFAHRT_AUFBAU_MIN = 15;        // Aufbauen vor dem Fenster
const ABFAHRT_FRUEHESTENS = 5;        // frueher losfahren heisst: am Vortag anreisen

/** fahrzeiten.json oder null */
function fahrzeiten_laden(string $dataDir): ?array
{
    $j = json_decode((string)@file_get_contents("$dataDir/fahrzeiten.json"), true);
    return is_array($j) && isset($j['start']['punkt'], $j['ziele']) && is_array($j['ziele']) ? $j : null;
}

/** Minuten je km Luftlinie aus den genauen Werten (Median), sonst Standard */
function fahrt_faktor(?array $fz, array $regionen): float
{
    if (!$fz) {
        return FAHRT_FAKTOR_STANDARD;
    }
    $werte = [];
    foreach ($regionen as $r) {
        $z = $fz['ziele'][$r['id']] ?? null;
        if (!is_array($z)) {
            continue;
        }
        $km = entfernung_km($fz['start']['punkt'], $r['punkt']);
        if ($km >= FAHRT_MIN_KM) {
            $werte[] = ($z['min'] - FAHRT_SOCKEL_MIN) / $km;
        }
    }
    if (count($werte) < 3) {
        return FAHRT_FAKTOR_STANDARD;
    }
    sort($werte);
    $n = count($werte);
    return $n % 2 ? $werte[intdiv($n, 2)] : ($werte[$n / 2 - 1] + $werte[$n / 2]) / 2;
}

/**
 * Fahrzeit vom Standort zu einer Region.
 * Rueckgabe ['min' => int, 'km' => ?float (Strasse), 'geschaetzt' => bool] oder
 * ['min' => null, 'kein_landweg' => true], wenn es vom Startort keine Route gibt.
 */
function fahrzeit(array $region, array $heim, float $luftKm, ?array $fz, float $faktor): array
{
    $bekannt = $fz && array_key_exists($region['id'], $fz['ziele']);
    if ($bekannt && $fz['ziele'][$region['id']] === null) {
        return ['min' => null, 'km' => null, 'geschaetzt' => false, 'kein_landweg' => true];
    }
    $gleich = $fz && abs($heim[0] - $fz['start']['punkt'][0]) <= FAHRT_GLEICHER_START
                  && abs($heim[1] - $fz['start']['punkt'][1]) <= FAHRT_GLEICHER_START;
    if ($bekannt && $gleich) {
        $z = $fz['ziele'][$region['id']];
        return ['min' => (int)$z['min'], 'km' => (float)$z['km'], 'geschaetzt' => false, 'kein_landweg' => false];
    }
    return ['min' => (int)round(FAHRT_SOCKEL_MIN + $faktor * $luftKm), 'km' => null, 'geschaetzt' => true, 'kein_landweg' => false];
}

/** "1:45 h", geschaetzt auf 15 Minuten gerundet: "≈ 3:30 h"; unter einer Stunde "40 min" */
function fahrzeit_text(array $f): string
{
    if ($f['min'] === null) {
        return 'kein Landweg';
    }
    $m = $f['geschaetzt'] ? (int)(round($f['min'] / 15) * 15) : (int)(round($f['min'] / 5) * 5);
    $m = max($m, 5);
    $t = $m < 60 ? "$m min" : intdiv($m, 60) . ':' . sprintf('%02d', $m % 60) . ' h';
    return ($f['geschaetzt'] ? '≈ ' : '') . $t;
}

/**
 * Abfahrt fuer ein Fenster. $tag (Ortstag der Region), $ab (Ortsstunde, an der
 * das Fenster beginnt), $bis (Ende), Fahrzeit in Minuten.
 * Rueckgabe ['art' => 'bis'|'vortag'|'jetzt'|'zu_spaet', 'zeit' => DateTimeImmutable|null]
 *   bis      spaetestens um zeit losfahren (in $tzSeite)
 *   vortag   so frueh, dass man am Vortag anreisen sollte
 *   jetzt    Abfahrtszeit vorbei, bei Abfahrt jetzt ab zeit dort (noch mindestens MIN_STUNDEN)
 *   zu_spaet heute nicht mehr erreichbar
 */
function abfahrt(string $tag, int $ab, int $bis, DateTimeZone $tzRegion, int $minuten, DateTimeZone $tzSeite, DateTimeImmutable $jetzt): array
{
    $start = new DateTimeImmutable(sprintf('%s %02d:00', $tag, $ab), $tzRegion);
    $los = $start->modify('-' . ($minuten + ABFAHRT_AUFBAU_MIN) . ' minutes');
    $los = $los->setTimestamp($los->getTimestamp() - $los->getTimestamp() % 300)->setTimezone($tzSeite);   // auf 5 min abrunden
    if ($los >= $jetzt) {
        $tagSeite = (new DateTimeImmutable($tag, $tzSeite))->format('Y-m-d');
        $frueh = $los->format('Y-m-d') < $tagSeite || (int)$los->format('G') < ABFAHRT_FRUEHESTENS;
        return ['art' => $frueh ? 'vortag' : 'bis', 'zeit' => $los];
    }
    $ankunft = $jetzt->modify('+' . ($minuten + ABFAHRT_AUFBAU_MIN) . ' minutes')->setTimezone($tzRegion);
    $ende = new DateTimeImmutable(sprintf('%s %02d:00', $tag, $bis), $tzRegion);
    if ($ankunft->getTimestamp() + MIN_STUNDEN * 3600 <= $ende->getTimestamp()) {
        return ['art' => 'jetzt', 'zeit' => $ankunft];
    }
    return ['art' => 'zu_spaet', 'zeit' => null];
}
