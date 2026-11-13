<?php
/**
 * Maps-Knoepfe (seit 13.11.2026): je Spot ein Link auf Google Maps, ohne Spots einer fuer
 * die Region. Ziel in dieser Reihenfolge:
 *   1. punkt des Spots (Koordinaten aus dem Katalog)
 *   2. anfahrt der Region, wenn sie genau einen Spot hat (Parkplatz am Spot)
 *   3. Suche nach dem Namen: "Spot, Region ohne Klammer, Land" (Google raet; kann danebenliegen)
 * Region ohne Spots: anfahrt, sonst punkt (bei Seen die Seemitte). Schnee-Regionen haben
 * keine Spots, dort zeigt der Knopf auf anfahrt (Ausgangspunkt, seit 13.11.2026).
 * Besser treffen: im Katalog punkt am Spot eintragen.
 */

const MAPS_LAENDER = ['DE' => 'Germany', 'AT' => 'Austria', 'CH' => 'Switzerland', 'IT' => 'Italy',
                      'FR' => 'France', 'ES' => 'Spain', 'PT' => 'Portugal', 'GR' => 'Greece',
                      'NL' => 'Netherlands', 'DK' => 'Denmark', 'PL' => 'Poland', 'GB' => 'United Kingdom',
                      'IE' => 'Ireland', 'HR' => 'Croatia', 'SI' => 'Slovenia', 'BE' => 'Belgium'];

/** Google-Maps-Adresse fuer Koordinaten oder Suchtext */
function maps_url(array|string $ziel): string
{
    $q = is_array($ziel) ? round((float)$ziel[0], 5) . ',' . round((float)$ziel[1], 5) : $ziel;
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q);
}

/**
 * Ziele einer Region: [[name, url, genau], ...]. genau = Koordinaten aus dem Katalog,
 * sonst Namenssuche.
 */
function maps_ziele(array $region): array
{
    $spots = $region['spots'] ?? [];
    if (!$spots) {
        return [[$region['name'], maps_url($region['anfahrt'] ?? $region['punkt']), true]];
    }
    $land = MAPS_LAENDER[$region['land'] ?? ''] ?? null;
    $ziele = [];
    foreach ($spots as $s) {
        $p = $s['punkt'] ?? (count($spots) === 1 ? ($region['anfahrt'] ?? null) : null);
        if ($p) {
            $ziele[] = [$s['name'], maps_url($p), true];
            continue;
        }
        // Region ohne Klammerzusatz ("Prasonisi (Rhodos)" -> "Prasonisi"), nicht doppelt
        $rname = trim(preg_replace('/\s*\([^)]*\)/u', '', $region['name']));
        $teile = [$s['name']];
        if ($rname !== '' && !str_contains(mb_strtolower($s['name']), mb_strtolower($rname))) {
            $teile[] = $rname;
        }
        if ($land) {
            $teile[] = $land;
        }
        $ziele[] = [$s['name'], maps_url(implode(', ', $teile)), false];
    }
    return $ziele;
}

/** Zeile mit den Maps-Knoepfen fuer eine Regionskarte */
function maps_html(array $region): string
{
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $pin = '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>';
    $ohneSpots = empty($region['spots']);
    $o = '<p class="maps klein">' . ($ohneSpots ? '' : '<span class="leise">Maps</span>');
    foreach (maps_ziele($region) as [$name, $url, $genau]) {
        $titel = $name . ' auf Google Maps' . ($genau ? '' : ' (Suche nach dem Namen, kann danebenliegen)');
        $o .= '<a class="maps-knopf" href="' . $e($url) . '" rel="noopener" title="' . $e($titel) . '">'
            . $pin . ($ohneSpots ? 'Maps' : $e($name)) . '</a>';
    }
    return $o . '</p>';
}
