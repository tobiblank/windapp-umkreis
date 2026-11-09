<?php
/**
 * Messstationen fuer den Schnee-Modus (seit 9.11.2026): gemessene Schneehoehe neben
 * dem Modellwert.
 *
 * Katalog (daten/schneeregionen.json):
 *   messnetze  {id: {name, kurz, abruf, url, lizenz}}  abruf = GeoJSON aller Stationen
 *   regionen[].stationen  {netz-id: [plot-id, ...]}    in dieser Reihenfolge angezeigt
 *
 * Erstes Netz: Wetterstationen Tirol (LWD Tirol und Hydrographischer Dienst Tirol,
 * Land Tirol, data.gv.at, CC BY 4.0, stuendlich). Felder je Station (properties),
 * gesehen in der Datei vom 9.11.2026 11:00 Uhr (221 Stationen, 120 mit Schneehoehe):
 *   plot (Kennung), name, LWD-Region (EAWS-Mikroregion), date (ISO mit Zeitzone),
 *   HS (Schneehoehe cm), HSD24/HSD48/HSD72 (Aenderung der Schneehoehe in cm, mit
 *   Setzung, also kein Neuschnee), LT (Lufttemperatur), operator; Hoehe als dritte
 *   Koordinate. Rohdaten: einzelne Werte sind offensichtlich falsch (HS -1,3 cm,
 *   HSD24 -23 cm bei 0 cm Schnee), deshalb stationen_wert() mit Plausibilitaet.
 */

const STATION_HS_MIN = -0.5;     // cm: darunter Messfehler (leicht negativ = 0)
const STATION_HS_MAX = 1500;     // cm
const STATION_HSD_MAX = 150;     // cm: groessere Aenderung in 24 h ist ein Messfehler
const STATION_ALT_STUNDEN = 12;  // aelter: Wert mit Zeit anzeigen
const STATION_ZU_ALT_STUNDEN = 72; // aelter: nicht mehr anzeigen
const STATIONEN_SUCHTAGE = 3;    // so viele UTC-Tage zurueck nach Dateien suchen

/** Netz-Ids und Plot-Ids, die der Katalog braucht: [netz => [plot, ...]] */
function stationen_benoetigt(array $schnee): array
{
    $out = [];
    foreach ($schnee['regionen'] as $r) {
        foreach ($r['stationen'] ?? [] as $netz => $ids) {
            foreach ($ids as $id) {
                $out[$netz][$id] = true;
            }
        }
    }
    return array_map('array_keys', $out);
}

/**
 * Aus dem GeoJSON eines Netzes die gewuenschten Stationen, kompakt.
 * Rueckgabe [plot => [name, lat, lon, hoehe, hs, hsd24, hsd72, lt, zeit, region, betreiber]] oder null,
 * wenn die Antwort kein GeoJSON mit features ist.
 */
function stationen_filtern(string $body, array $ids): ?array
{
    $d = json_decode($body, true);
    if (!is_array($d) || !is_array($d['features'] ?? null)) {
        return null;
    }
    $will = array_flip($ids);
    $out = [];
    foreach ($d['features'] as $f) {
        $p = $f['properties'] ?? [];
        $id = $p['plot'] ?? null;
        if (!is_string($id) || !isset($will[$id])) {
            continue;
        }
        $c = $f['geometry']['coordinates'] ?? [];
        $zahl = fn($k) => is_numeric($p[$k] ?? null) ? (float)$p[$k] : null;
        $out[$id] = [
            'name'      => (string)($p['name'] ?? $id),
            'lat'       => is_numeric($c[1] ?? null) ? (float)$c[1] : null,
            'lon'       => is_numeric($c[0] ?? null) ? (float)$c[0] : null,
            'hoehe'     => is_numeric($c[2] ?? null) ? (int)round((float)$c[2]) : null,
            'hs'        => $zahl('HS'),
            'hsd24'     => $zahl('HSD24'),
            'hsd72'     => $zahl('HSD72'),
            'lt'        => $zahl('LT'),
            'zeit'      => is_string($p['date'] ?? null) ? $p['date'] : null,
            'region'    => is_string($p['LWD-Region'] ?? null) ? $p['LWD-Region'] : null,
            'betreiber' => is_string($p['operator'] ?? null) ? $p['operator'] : null,
        ];
    }
    return $out;
}

/** Neueste gelungene Zeile je Netz: [netz => ['ts' => ..., 'stationen' => [...]]] */
function stationen_laden(string $dataDir, ?int $jetzt = null): array
{
    $jetzt ??= time();
    $dateien = [];
    for ($t = 0; $t < STATIONEN_SUCHTAGE; $t++) {
        $d = gmdate('Y-m-d', $jetzt - $t * 86400);
        $dateien = array_merge($dateien, glob("$dataDir/stationen/$d.ndjson") ?: [], glob("$dataDir/stationen/{$d}_*.ndjson.gz") ?: []);
    }
    rsort($dateien);
    $out = [];
    foreach ($dateien as $f) {
        $txt = (string)@file_get_contents($f);
        if (str_ends_with($f, '.gz')) {
            $txt = (string)@gzdecode($txt);
        }
        foreach (array_reverse(array_values(array_filter(array_map('trim', explode("\n", $txt))))) as $z) {
            $j = json_decode($z, true);
            $n = $j['netz'] ?? null;
            if (!is_array($j) || empty($j['ok']) || !is_string($n) || isset($out[$n]) || !is_array($j['stationen'] ?? null)) {
                continue;
            }
            $out[$n] = ['ts' => (string)($j['ts'] ?? ''), 'stationen' => $j['stationen']];
        }
    }
    return $out;
}

/**
 * Anzeigewert einer Station nach Plausibilitaet, null wenn nichts Brauchbares da ist:
 * [name, hoehe, hs, hsd24, zeit (DateTimeImmutable), alt (bool)]
 */
function stationen_wert(array $s, int $jetzt): ?array
{
    $zeit = null;
    if ($s['zeit'] ?? null) {
        try {
            $zeit = new DateTimeImmutable($s['zeit']);
        } catch (Exception $e) {
            $zeit = null;
        }
    }
    if ($zeit === null || $jetzt - $zeit->getTimestamp() > STATION_ZU_ALT_STUNDEN * 3600) {
        return null;
    }
    $hs = $s['hs'] ?? null;
    if ($hs === null || $hs < STATION_HS_MIN || $hs > STATION_HS_MAX) {
        return null;
    }
    $hsd = $s['hsd24'] ?? null;
    if ($hsd !== null && abs($hsd) > STATION_HSD_MAX) {
        $hsd = null;
    }
    return ['name' => $s['name'], 'hoehe' => $s['hoehe'], 'hs' => max(0.0, $hs), 'hsd24' => $hsd,
            'zeit' => $zeit, 'alt' => $jetzt - $zeit->getTimestamp() > STATION_ALT_STUNDEN * 3600];
}

/** Stationen einer Region mit Anzeigewert: [['netz' => id, 'wert' => [...]], ...] in Katalog-Reihenfolge */
function stationen_region(array $region, array $geladen, ?int $jetzt = null): array
{
    $jetzt ??= time();
    $out = [];
    foreach ($region['stationen'] ?? [] as $netz => $ids) {
        foreach ($ids as $id) {
            $s = $geladen[$netz]['stationen'][$id] ?? null;
            $w = $s ? stationen_wert($s, $jetzt) : null;
            if ($w) {
                $out[] = ['netz' => $netz, 'wert' => $w];
            }
        }
    }
    return $out;
}

/** Fehler im Katalogteil fuer Stationen (fuer schneeregionen_pruefen) */
function stationen_pruefen(array $d): array
{
    $f = [];
    $netze = $d['messnetze'] ?? [];
    if (!is_array($netze)) {
        return ['messnetze muss ein Objekt sein'];
    }
    foreach ($netze as $id => $n) {
        $w = "Messnetz $id";
        if (!preg_match('/^[a-z0-9-]+$/', (string)$id)) {
            $f[] = "$w: id nur a-z, 0-9, -";
        }
        foreach (['name', 'kurz', 'lizenz'] as $k) {
            if (!is_string($n[$k] ?? null) || $n[$k] === '') {
                $f[] = "$w: $k fehlt";
            }
        }
        foreach (['abruf', 'url'] as $k) {
            if (!is_string($n[$k] ?? null) || !str_starts_with($n[$k], 'https://')) {
                $f[] = "$w: $k muss mit https:// beginnen";
            }
        }
    }
    foreach ($d['regionen'] ?? [] as $i => $r) {
        if (!isset($r['stationen'])) {
            continue;
        }
        $wo = 'Schneeregion ' . ($r['id'] ?? "#$i");
        if (!is_array($r['stationen']) || array_is_list($r['stationen']) && $r['stationen'] !== []) {
            $f[] = "$wo: stationen muss ein Objekt {netz: [plot, ...]} sein";
            continue;
        }
        foreach ($r['stationen'] as $netz => $ids) {
            if (!isset($netze[$netz])) {
                $f[] = "$wo: Messnetz $netz steht nicht in messnetze";
            }
            if (!is_array($ids) || !array_is_list($ids) || !$ids || array_filter($ids, fn($x) => !is_string($x) || $x === '')) {
                $f[] = "$wo: stationen.$netz muss eine nicht leere Liste von Kennungen sein";
            }
        }
    }
    return $f;
}

/**
 * Zeile "Gemessen …" fuer Regionskarte und Stundenseite (HTML), leer ohne Stationen.
 * Aenderung in 24 h mit Vorzeichen; sie enthaelt die Setzung und ist kein Neuschnee.
 */
function stationen_html(array $liste, array $netze, DateTimeZone $tz, DateTimeImmutable $heute): string
{
    if (!$liste) {
        return '';
    }
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $zahl = fn(float $v) => number_format($v, 0, ',', '.');
    $wann = function (DateTimeImmutable $z) use ($tz, $heute): string {
        $z = $z->setTimezone($tz);
        $tage = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        return ($z->format('Y-m-d') === $heute->format('Y-m-d') ? 'heute' : $tage[(int)$z->format('w')] . ' ' . $z->format('j.n.')) . ' ' . $z->format('G:i');
    };
    $neueste = max(array_map(fn($s) => $s['wert']['zeit']->getTimestamp(), $liste));
    $teile = [];
    foreach ($liste as $s) {
        $w = $s['wert'];
        $d = $w['hsd24'];
        $aend = $d === null ? '' : ' <span class="leise" title="Änderung der Schneehöhe in 24 Stunden, mit Setzung; kein Neuschnee">(24 h ' . (round($d) > 0 ? '+' : (round($d) < 0 ? '−' : '±')) . $zahl(abs(round($d))) . ')</span>';
        $teile[] = '<span title="' . $e($w['name'] . ', ' . ($w['hoehe'] !== null ? $zahl($w['hoehe']) . ' m, ' : '') . 'gemessen ' . $wann($w['zeit'])) . '">'
                 . $e($w['name']) . ($w['hoehe'] !== null ? ' <span class="leise">' . $zahl($w['hoehe']) . ' m</span>' : '')
                 . ' <b>' . $zahl(round($w['hs'])) . ' cm</b>' . $aend
                 . ($w['alt'] ? ' <span class="leise">(' . $e($wann($w['zeit'])) . ')</span>' : '') . '</span>';
    }
    $quellen = array_unique(array_map(fn($s) => $netze[$s['netz']]['kurz'] ?? $s['netz'], $liste));
    $link = array_values(array_filter(array_map(fn($s) => $netze[$s['netz']]['url'] ?? null, $liste)))[0] ?? null;
    return '<p class="klein messung"><span class="leise">Schneehöhe gemessen ' . $e($wann((new DateTimeImmutable('@' . $neueste)))) . ' ('
         . ($link ? '<a href="' . $e($link) . '" rel="noopener">' . $e(implode(', ', $quellen)) . ' ↗</a>' : $e(implode(', ', $quellen)))
         . '):</span> ' . implode(' · ', $teile) . '</p>';
}
