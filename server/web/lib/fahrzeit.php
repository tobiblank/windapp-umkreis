<?php
/**
 * Fahrzeit und Abfahrt (seit 7.11.2026).
 *
 * Genau: aus <data_dir>/fahrzeiten.json (server/fahrzeiten.php, openrouteservice),
 * wenn der Standort der Startort dieser Datei ist (Standard Inning).
 * Sonst geschaetzt aus der Luftlinie: Sockel + Faktor x km. Beides als
 * robuste Gerade (Theil-Sen) aus den genauen Werten bis FAHRT_MODELL_MAX_KM Luftlinie
 * (mindestens FAHRT_MIN_PUNKTE, Ergebnis
 * auf plausible Grenzen begrenzt), ohne Datei FAHRT_STANDARD. Ein einzelner Faktor
 * (bis 7.11.2026, 0,95 min/km) schaetzte lange Autobahnstrecken um 2-3 h zu lang.
 * Regionen, die vom Startort keine Route haben (Inseln), bleiben auch geschaetzt
 * ohne Fahrzeit.
 * Ohne Verkehr, Pausen und Grenzwartezeiten.
 *
 * Strassen-km (seit 7.11.2026, fuer den Umkreis): genau aus der Datei, sonst
 * Luftlinie x Umwegfaktor (Median Strasse/Luftlinie der genauen Werte bis
 * FAHRT_MODELL_MAX_KM, ohne Datei FAHRT_UMWEG).
 */

const FAHRT_STANDARD = [15.0, 0.75];  // [Sockel min, min je km Luftlinie]: Umweg ~1,25, Autobahn ~100 km/h; geschaetzt
const FAHRT_GRENZEN = [[0.0, 60.0], [0.5, 1.2]];   // erlaubte Spanne fuer Sockel und Faktor aus der Anpassung
const FAHRT_MIN_PUNKTE = 5;            // so viele genaue Werte braucht die Anpassung
const FAHRT_MODELL_MAX_KM = 1500;      // weitere Ziele (Faehren, Kanaren) nicht in die Anpassung
const FAHRT_UMWEG = 1.25;             // Strasse / Luftlinie ohne Datei; geschaetzt
const FAHRT_UMWEG_GRENZEN = [1.05, 1.8];
const FAHRT_GLEICHER_START = 0.05;    // Grad: so nah gilt der Standort als Startort der Datei
const ABFAHRT_AUFBAU_MIN = 15;        // Aufbauen vor dem Fenster
const ABFAHRT_FRUEHESTENS = 5;        // frueher losfahren heisst: am Vortag anreisen

/** fahrzeiten.json oder null */
function fahrzeiten_laden(string $dataDir): ?array
{
    $j = json_decode((string)@file_get_contents("$dataDir/fahrzeiten.json"), true);
    return is_array($j) && isset($j['start']['punkt'], $j['ziele']) && is_array($j['ziele']) ? $j : null;
}

/**
 * Schaetzmodell [Sockel, Faktor] aus den genauen Werten: robuste Gerade
 * (Theil-Sen) Minuten ueber km Luftlinie vom Startort, auf FAHRT_GRENZEN begrenzt;
 * zu wenige Werte oder keine Datei: FAHRT_STANDARD.
 */
function fahrt_modell(?array $fz, array $regionen): array
{
    if (!$fz) {
        return FAHRT_STANDARD;
    }
    $xs = $ys = [];
    foreach ($regionen as $r) {
        $z = $fz['ziele'][$r['id']] ?? null;
        $km = entfernung_km($fz['start']['punkt'], $r['punkt']);
        if (is_array($z) && isset($z['min']) && $km <= FAHRT_MODELL_MAX_KM) {
            $xs[] = $km;
            $ys[] = (float)$z['min'];
        }
    }
    $n = count($xs);
    if ($n < FAHRT_MIN_PUNKTE) {
        return FAHRT_STANDARD;
    }
    // Theil-Sen: Median der Steigungen aller Paare, Sockel als Median der Reste.
    // Ein einzelner Faehr-Ausreisser verschiebt das kaum (anders als kleinste Quadrate).
    $steigungen = [];
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            if (abs($xs[$j] - $xs[$i]) > 1) {
                $steigungen[] = ($ys[$j] - $ys[$i]) / ($xs[$j] - $xs[$i]);
            }
        }
    }
    if (!$steigungen) {
        return FAHRT_STANDARD;
    }
    $median = function (array $w): float {
        sort($w);
        $m = count($w);
        return $m % 2 ? $w[intdiv($m, 2)] : ($w[$m / 2 - 1] + $w[$m / 2]) / 2;
    };
    $b = $median($steigungen);
    $a = $median(array_map(fn($x, $y) => $y - $b * $x, $xs, $ys));
    [[$a0, $a1], [$b0, $b1]] = FAHRT_GRENZEN;
    $b = min(max($b, $b0), $b1);
    $a = min(max($a, $a0), $a1);
    return [$a, $b];
}

/**
 * Umwegfaktor Strasse / Luftlinie: Median der genauen Werte (bis
 * FAHRT_MODELL_MAX_KM, mindestens FAHRT_MIN_PUNKTE), auf FAHRT_UMWEG_GRENZEN
 * begrenzt; sonst FAHRT_UMWEG.
 */
function fahrt_umweg(?array $fz, array $regionen): float
{
    if (!$fz) {
        return FAHRT_UMWEG;
    }
    $q = [];
    foreach ($regionen as $r) {
        $z = $fz['ziele'][$r['id']] ?? null;
        $luft = entfernung_km($fz['start']['punkt'], $r['punkt']);
        if (is_array($z) && isset($z['km']) && $luft > 20 && $luft <= FAHRT_MODELL_MAX_KM) {
            $q[] = (float)$z['km'] / $luft;
        }
    }
    $n = count($q);
    if ($n < FAHRT_MIN_PUNKTE) {
        return FAHRT_UMWEG;
    }
    sort($q);
    $m = $n % 2 ? $q[intdiv($n, 2)] : ($q[$n / 2 - 1] + $q[$n / 2]) / 2;
    return min(max($m, FAHRT_UMWEG_GRENZEN[0]), FAHRT_UMWEG_GRENZEN[1]);
}

/**
 * Fahrzeit vom Standort zu einer Region.
 * Rueckgabe ['min' => int, 'km' => float (Strasse), 'geschaetzt' => bool] oder
 * ['min' => null, 'km' => null, 'kein_landweg' => true], wenn es vom Startort keine
 * Route gibt. Geschaetzt: km = Luftlinie x $umweg.
 */
function fahrzeit(array $region, array $heim, float $luftKm, ?array $fz, array $modell = FAHRT_STANDARD, float $umweg = FAHRT_UMWEG): array
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
    return ['min' => (int)round($modell[0] + $modell[1] * $luftKm), 'km' => round($luftKm * $umweg), 'geschaetzt' => true, 'kein_landweg' => false];
}

/**
 * Liegt die Region im Umkreis? Gezaehlt wird die Strasse (genau oder geschaetzt);
 * ohne Landweg und fuer Flugziele die Luftlinie.
 */
function im_umkreis(array $e, float $km): bool
{
    $f = $e['fahrt'] ?? null;
    $strasse = is_array($f) && !isset($f['flug']) && isset($f['km']) ? (float)$f['km'] : null;
    return ($strasse ?? (float)$e['km']) <= $km;
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

// ===================================================================
// CO2 EINES FLUGS (seit 9.11.2026)
// ===================================================================
//
// Nuechterne Zahl an jedem Flugziel: Hin- und Rueckflug pro Person, Economy, als
// Treibhausgase mit Nicht-CO2-Effekten (Kondensstreifen, Stickoxide). Grob: Luftlinie
// vom Standort zum Vorhersagepunkt (der Flughafen liegt nah dabei) mal zwei, plus Umweg.
// Faktor: UK DESNZ/DEFRA GHG Conversion Factors 2025, Flug Kurzstrecke (bis 3.700 km,
// alle Flugziele des Katalogs), Durchschnitt aller Klassen, mit Strahlungsantrieb (rund
// x 1,7), 0,128 kg CO2e je Personenkilometer; DEFRA rechnet 8 % Umweg auf die
// Grosskreisdistanz. Zum Vergleich der Pkw nach UBA (TREMOD 6.71B, 2024): 164 g CO2e
// je Personenkilometer bei 1,4 Personen, also rund 230 g je Kilometer allein im Auto.
// Das UBA rechnet fuer Inlandsfluege mit 290 g/Pkm (Nicht-CO2-Faktor 1,5); Kurzstrecken
// sind je km teurer, darum fuer die laengeren Flugziele der DEFRA-Wert.
const FLUG_CO2_KG_PKM = 0.128;
const FLUG_UMWEG = 1.08;
const PKW_ALLEIN_CO2_KG_KM = 0.164 * 1.4;

/** Hin und zurueck pro Person in Tonnen CO2e, aus der Luftlinie in km */
function flug_co2_t(float $luftKm): float
{
    return 2 * $luftKm * FLUG_UMWEG * FLUG_CO2_KG_PKM / 1000;
}

/** So viele km allein im Auto entsprechen dieser Menge */
function flug_als_auto_km(float $tonnen): float
{
    return $tonnen * 1000 / PKW_ALLEIN_CO2_KG_KM;
}
