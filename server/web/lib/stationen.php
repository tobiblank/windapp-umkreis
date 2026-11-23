<?php
/**
 * Messstationen fuer den Schnee-Modus (seit 9.11.2026): gemessene Schneehoehe neben
 * dem Modellwert.
 *
 * Katalog (daten/schneeregionen.json):
 *   messnetze  {id: {art, name, kurz, abruf, url, lizenz, stationen?}}
 *     art  lwd-geojson (Standard): abruf = GeoJSON aller Stationen mit Namen und Werten
 *          geosphere: GeoSphere Austria Data Hub, abruf = Endpunkt der Zeitreihe,
 *                     ein Abruf fuer alle Stationen, Parameter SCHNEE (cm)
 *          meteoswiss: SwissMetNet, abruf mit {id} = Kuerzel klein, eine CSV je Station
 *                     (10-Minuten-Werte seit gestern 12 UTC), Spalte htoauts0 (cm)
 *          suedtirol: Landeswetterdienst Suedtirol (CC0), abruf mit {id} = SCODE, eine
 *                     Zeitreihe je Station (10 Minuten, date_from/date_to in Ortszeit
 *                     YmdHi), Sensor HS (cm); Antwort [{DATE: "2026-11-09T12:30:00CET", VALUE}]
 *          snowgrid: SNOWGRID-CL von GeoSphere Austria (CC BY 4.0), Schneehoehe aus einem
 *                     Schneedeckenmodell im 1-km-Raster, taeglich, Stand gestern; nur
 *                     Oesterreich. Ein Abruf mit mehreren lat_lon; die "Stationen" sind
 *                     Punkte {id: {name, hoehe, lat, lon}} (Hoehe aus dem Gelaendemodell,
 *                     gewaehlt mit tools/snowgrid_punkte.php). Kein Messwert: die Anzeige
 *                     trennt ihn als Modell ab.
 *          slf-imis: Messnetz IMIS des SLF (seit 23.11.2026, Freigabe per Mail im Vorgang
 *                     DATA-2406), abruf = /public/api/imis/daily-snow, ein Abruf fuer alle
 *                     Stationen, Tageswerte [{station_code, measure_date (UTC ohne Zone),
 *                     HS (gemessen, cm), HN_1D (Neuschnee 24 h, vom Schneedeckenmodell
 *                     SNOWPACK gerechnet, cm)}]. Auflagen des SLF: HN_1D als Modellwert
 *                     kennzeichnen, Quelle "WSL-Institut fuer Schnee- und Lawinenforschung
 *                     SLF" mit Link auf www.slf.ch, Abruf ein- bis zweimal am Tag ueber den
 *                     eigenen Server (abruf_stunden_utc, siehe stationen_netz_faellig()).
 *     stationen  ausser bei lwd-geojson: {id: {name, hoehe}} aus den Stationslisten,
 *                weil die Antwort keine Namen enthaelt
 *     abruf_stunden_utc  optional, z. B. [6, 16]: das Netz wird nur auf dem Server und nur
 *                einmal je Zeitfenster ab dieser UTC-Stunde abgerufen (nicht von der Action)
 *     quelle     optional: Name in der Zeile "gemessen" statt kurz
 *   regionen[].stationen  {netz-id: [station-id, ...]}  in dieser Reihenfolge angezeigt
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
const MODELL_ZU_ALT_STUNDEN = 100; // SNOWGRID (Tageswert, Stand gestern 0 UTC): aelter nicht anzeigen
const SUEDTIROL_HS_NULL = -5.0;  // cm: Suedtiroler Pegel zeigen auf aperem Boden oft -1 bis -3 cm
const TAGESWERT_ALT_STUNDEN = 36; // Tageswert (IMIS, Stand 0 UTC): aelter mit Datum anzeigen
const NETZ_WIEDERHOLEN_MIN = 120; // abruf_stunden_utc: nach einem Fehlversuch fruehestens wieder

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

const STATION_ARTEN = ['lwd-geojson', 'geosphere', 'meteoswiss', 'suedtirol', 'snowgrid', 'slf-imis'];
const STATION_EINZELN = ['meteoswiss', 'suedtirol']; // ein Abruf je Station, abruf mit {id}
const STATION_STUNDEN_ZURUECK = 26; // Zeitreihe: so weit zurueck, fuer die Aenderung in 24 h

/**
 * Aus einer Zeitreihe (UTC-Zeitstempel => Wert oder null) der letzte Wert und die
 * Aenderung gegenueber dem Wert rund 24 h davor (+-40 min), sonst null.
 * Rueckgabe [hs, hsd24, zeit ISO] oder null ohne Wert.
 */
function stationen_aus_reihe(array $reihe): ?array
{
    $werte = array_filter($reihe, fn($v) => $v !== null && is_numeric($v));
    if (!$werte) {
        return null;
    }
    ksort($werte);
    $letzt = array_key_last($werte);
    $hs = (float)$werte[$letzt];
    $ziel = $letzt - 86400;
    $vorher = null;
    $abstand = 2400;
    foreach ($werte as $ts => $v) {
        if (abs($ts - $ziel) <= $abstand) {
            $abstand = abs($ts - $ziel);
            $vorher = (float)$v;
        }
    }
    return [$hs, $vorher === null ? null : round($hs - $vorher, 1), gmdate('Y-m-d\TH:i:s\Z', $letzt)];
}

/** Eintrag im Format von stationen_filtern() aus Name/Hoehe des Katalogs und einer Zeitreihe */
function stationen_eintrag(string $id, array $meta, ?array $wert, string $betreiber): ?array
{
    if ($wert === null) {
        return null;
    }
    [$hs, $hsd, $zeit] = $wert;
    return ['name' => (string)($meta['name'] ?? $id), 'lat' => null, 'lon' => null, 'hoehe' => isset($meta['hoehe']) ? (int)$meta['hoehe'] : null,
            'hs' => $hs, 'hsd24' => $hsd, 'hsd72' => null, 'lt' => null, 'zeit' => $zeit, 'region' => null, 'betreiber' => $betreiber];
}

/**
 * GeoSphere Data Hub, Stationszeitreihe als JSON: {timestamps: [ISO], features: [
 *   {properties: {station: "11344", parameters: {SCHNEE: {data: [...]}}}}]}.
 * Rueckgabe [id => eintrag] (Stationen ohne Wert fehlen) oder null, wenn das Format nicht passt.
 */
function geosphere_lesen(string $body, array $meta): ?array
{
    $d = json_decode($body, true);
    if (!is_array($d) || !is_array($d['timestamps'] ?? null) || !is_array($d['features'] ?? null)) {
        return null;
    }
    $zeiten = array_map(fn($z) => strtotime((string)$z), $d['timestamps']);
    $out = [];
    foreach ($d['features'] as $f) {
        $id = (string)($f['properties']['station'] ?? '');
        $daten = $f['properties']['parameters']['SCHNEE']['data'] ?? null;
        if ($id === '' || !is_array($daten)) {
            continue;
        }
        $reihe = [];
        foreach ($daten as $i => $v) {
            if (($zeiten[$i] ?? false) !== false) {
                $reihe[$zeiten[$i]] = $v;
            }
        }
        $e = stationen_eintrag($id, $meta[$id] ?? [], stationen_aus_reihe($reihe), 'GeoSphere Austria');
        if ($e) {
            $out[$id] = $e;
        }
    }
    return $out;
}

/**
 * MeteoSwiss SwissMetNet, CSV einer Station (Semikolon, Windows-1252, Zeit
 * "dd.mm.yyyy HH:MM" in UTC am Ende des Intervalls), Spalte htoauts0.
 * Rueckgabe eintrag, ['leer'] ohne Schneehoehe oder null, wenn das Format nicht passt.
 */
function meteoswiss_lesen(string $body, string $id, array $meta): ?array
{
    $zeilen = preg_split('/\r?\n/', trim($body));
    $kopf = str_getcsv(array_shift($zeilen) ?? '', ';', '"', '');
    $iz = array_search('reference_timestamp', $kopf, true);
    $iw = array_search('htoauts0', $kopf, true);
    if ($iz === false) {
        return null;
    }
    if ($iw === false) {
        return ['leer'];
    }
    $reihe = [];
    foreach ($zeilen as $z) {
        $c = str_getcsv($z, ';', '"', '');
        $d = DateTimeImmutable::createFromFormat('d.m.Y H:i', (string)($c[$iz] ?? ''), new DateTimeZone('UTC'));
        if ($d) {
            $v = trim((string)($c[$iw] ?? ''));
            $reihe[$d->getTimestamp()] = $v === '' ? null : (float)$v;
        }
    }
    return stationen_eintrag($id, $meta, stationen_aus_reihe($reihe), 'MeteoSchweiz') ?? ['leer'];
}

/**
 * Landeswetterdienst Suedtirol, Zeitreihe einer Station als JSON-Liste
 * [{DATE: "2026-11-09T12:30:00CET", VALUE: 8.4}, ...] (neueste zuerst, Ortszeit mit
 * Kuerzel). Leicht negative Werte (bis SUEDTIROL_HS_NULL) gelten als 0 cm.
 * Rueckgabe eintrag, ['leer'] ohne Wert oder null, wenn das Format nicht passt.
 */
function suedtirol_lesen(string $body, string $id, array $meta): ?array
{
    $d = json_decode($body, true);
    if (!is_array($d) || !array_is_list($d)) {
        return null;
    }
    $reihe = [];
    foreach ($d as $x) {
        $ts = is_string($x['DATE'] ?? null) ? strtotime($x['DATE']) : false;
        $v = $x['VALUE'] ?? null;
        if ($ts === false || !is_numeric($v)) {
            continue;
        }
        $v = (float)$v;
        $reihe[$ts] = $v < SUEDTIROL_HS_NULL ? null : max(0.0, $v);
    }
    return stationen_eintrag($id, $meta, stationen_aus_reihe($reihe), 'Landeswetterdienst Südtirol') ?? ['leer'];
}

/** Abruf-URL einer Station bei Netzen mit einem Abruf je Station */
function stationen_url_einzeln(array $info, string $id, int $jetzt): string
{
    $art = $info['art'] ?? '';
    if ($art === 'meteoswiss') {
        return str_replace('{id}', strtolower($id), $info['abruf']);
    }
    $tz = new DateTimeZone('Europe/Rome');
    $bis = (new DateTimeImmutable('@' . $jetzt))->setTimezone($tz);
    return str_replace('{id}', rawurlencode($id), $info['abruf']) . (str_contains($info['abruf'], '?') ? '&' : '?')
         . http_build_query(['date_from' => $bis->modify('-' . STATION_STUNDEN_ZURUECK . ' hours')->format('YmdHi'), 'date_to' => $bis->format('YmdHi')]);
}

/** SNOWGRID: Abruf-URL fuer alle Punkte (Tageswerte der letzten 4 Tage) */
function snowgrid_url(array $info, array $ids, int $jetzt): string
{
    $q = http_build_query(['parameters' => 'snow_depth', 'start' => gmdate('Y-m-d', $jetzt - 4 * 86400), 'end' => gmdate('Y-m-d', $jetzt)]);
    foreach ($ids as $id) {
        $p = $info['stationen'][$id] ?? [];
        $q .= '&lat_lon=' . (float)($p['lat'] ?? 0) . ',' . (float)($p['lon'] ?? 0);
    }
    return $info['abruf'] . '?' . $q;
}

/**
 * SNOWGRID-Antwort {timestamps: [ISO], features: [{properties: {parameters: {snow_depth:
 * {unit: "m", data: [...]}}}}]}, Features in der Reihenfolge der lat_lon. Meter in cm.
 * Rueckgabe [id => eintrag mit modell=true] (Punkte ohne Wert fehlen, ausserhalb
 * Oesterreichs kommen nulls) oder null, wenn das Format nicht passt.
 */
function snowgrid_lesen(string $body, array $ids, array $meta): ?array
{
    $d = json_decode($body, true);
    if (!is_array($d) || !is_array($d['timestamps'] ?? null) || !is_array($d['features'] ?? null) || count($d['features']) !== count($ids)) {
        return null;
    }
    $zeiten = array_map(fn($z) => strtotime((string)$z), $d['timestamps']);
    $out = [];
    foreach (array_values($ids) as $i => $id) {
        $p = $d['features'][$i]['properties']['parameters']['snow_depth'] ?? [];
        $faktor = ($p['unit'] ?? 'm') === 'm' ? 100.0 : 1.0;
        $reihe = [];
        foreach (is_array($p['data'] ?? null) ? $p['data'] : [] as $j => $v) {
            if (($zeiten[$j] ?? false) !== false) {
                $reihe[$zeiten[$j]] = is_numeric($v) ? round((float)$v * $faktor, 1) : null;
            }
        }
        $e = stationen_eintrag($id, $meta[$id] ?? [], stationen_aus_reihe($reihe), 'GeoSphere Austria (SNOWGRID)');
        if ($e) {
            $out[$id] = $e + ['modell' => true];
        }
    }
    return $out;
}

/**
 * SLF IMIS, Tageswerte aller Stationen als JSON-Liste [{station_code, measure_date
 * "2026-11-23T00:00:00" (UTC), HS, HN_1D}], period_in_days=3 also mehrere Tage je Station.
 * Rueckgabe [id => eintrag mit tageswert=true und hn24 (HN_1D des neuesten Tags, Modell)]
 * fuer die gewuenschten Stationen mit Schneehoehe, oder null, wenn das Format nicht passt.
 */
function slf_imis_lesen(string $body, array $ids, array $meta): ?array
{
    $d = json_decode($body, true);
    if (!is_array($d) || !array_is_list($d) || ($d && !array_key_exists('station_code', $d[0] ?? []))) {
        return null;
    }
    $will = array_flip($ids);
    $reihen = $hn = [];
    $utc = new DateTimeZone('UTC');
    foreach ($d as $x) {
        $id = $x['station_code'] ?? null;
        if (!is_string($id) || !isset($will[$id]) || !is_string($x['measure_date'] ?? null)) {
            continue;
        }
        try {
            $ts = (new DateTimeImmutable($x['measure_date'], $utc))->getTimestamp();
        } catch (Exception $e) {
            continue;
        }
        $reihen[$id][$ts] = is_numeric($x['HS'] ?? null) ? (float)$x['HS'] : null;
        $hn[$id][$ts] = is_numeric($x['HN_1D'] ?? null) ? (float)$x['HN_1D'] : null;
    }
    $out = [];
    foreach ($ids as $id) {
        $e = stationen_eintrag($id, $meta[$id] ?? [], stationen_aus_reihe($reihen[$id] ?? []), 'SLF IMIS');
        if ($e) {
            $ts = strtotime($e['zeit']);
            $out[$id] = $e + ['tageswert' => true, 'hn24' => $hn[$id][$ts] ?? null];
        }
    }
    return $out;
}

/**
 * Netz mit abruf_stunden_utc: faellig, wenn seit dem letzten Fenster (z. B. heute 6 UTC
 * oder gestern 16 UTC) kein guter Abruf war und der letzte Versuch mindestens
 * NETZ_WIEDERHOLEN_MIN her ist. So hoechstens ein guter Abruf je Fenster.
 */
function stationen_netz_faellig(array $info, ?int $gut, ?int $versuch, int $jetzt): bool
{
    $stunden = $info['abruf_stunden_utc'] ?? null;
    if (!$stunden) {
        return true;
    }
    $fenster = null;
    $heute = intdiv($jetzt, 86400) * 86400;
    foreach ([$heute, $heute - 86400] as $tag) {
        foreach ($stunden as $h) {
            $t = $tag + (int)$h * 3600;
            if ($t <= $jetzt && ($fenster === null || $t > $fenster)) {
                $fenster = $t;
            }
        }
    }
    return ($gut === null || $gut < $fenster) && ($versuch === null || $jetzt - $versuch >= NETZ_WIEDERHOLEN_MIN * 60);
}

/**
 * Neueste gelungene Zeile je Netz: [netz => ['ts' => ..., 'stationen' => [...]]].
 * Seit 13.11.2026 auch aus stationen/server.ndjson (Abruf auf dem Server, lib/abruf.php);
 * je Netz zaehlt die Zeile mit dem neuesten ts.
 */
function stationen_laden(string $dataDir, ?int $jetzt = null): array
{
    $jetzt ??= time();
    $dateien = [];
    for ($t = 0; $t < STATIONEN_SUCHTAGE; $t++) {
        $d = gmdate('Y-m-d', $jetzt - $t * 86400);
        $dateien = array_merge($dateien, glob("$dataDir/stationen/$d.ndjson") ?: [], glob("$dataDir/stationen/{$d}_*.ndjson.gz") ?: []);
    }
    rsort($dateien);
    if (is_file("$dataDir/stationen/server.ndjson")) {
        $dateien[] = "$dataDir/stationen/server.ndjson";
    }
    $out = [];
    foreach ($dateien as $f) {
        $txt = (string)@file_get_contents($f);
        if (str_ends_with($f, '.gz')) {
            $txt = (string)@gzdecode($txt);
        }
        foreach (array_reverse(array_values(array_filter(array_map('trim', explode("\n", $txt))))) as $z) {
            $j = json_decode($z, true);
            $n = $j['netz'] ?? null;
            if (!is_array($j) || empty($j['ok']) || !is_string($n) || !is_array($j['stationen'] ?? null)
                || (isset($out[$n]) && $out[$n]['ts'] >= (string)($j['ts'] ?? ''))) {
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
            $zeit = new DateTimeImmutable($s['zeit'], new DateTimeZone('UTC'));
        } catch (Exception $e) {
            $zeit = null;
        }
    }
    $modell = !empty($s['modell']);
    if ($zeit === null || $jetzt - $zeit->getTimestamp() > ($modell ? MODELL_ZU_ALT_STUNDEN : STATION_ZU_ALT_STUNDEN) * 3600) {
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
    $tageswert = !empty($s['tageswert']);
    $hn = $s['hn24'] ?? null;
    return ['name' => $s['name'], 'hoehe' => $s['hoehe'], 'hs' => max(0.0, $hs), 'hsd24' => $hsd,
            'zeit' => $zeit, 'alt' => !$modell && $jetzt - $zeit->getTimestamp() > ($tageswert ? TAGESWERT_ALT_STUNDEN : STATION_ALT_STUNDEN) * 3600,
            'modell' => $modell, 'tageswert' => $tageswert, 'hn24' => is_numeric($hn) && $hn >= 0 && $hn <= STATION_HSD_MAX ? (float)$hn : null];
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
        $art = $n['art'] ?? 'lwd-geojson';
        if (!in_array($art, STATION_ARTEN, true)) {
            $f[] = "$w: art muss " . implode(', ', STATION_ARTEN) . ' sein';
        }
        if (in_array($art, STATION_EINZELN, true) && !str_contains((string)($n['abruf'] ?? ''), '{id}')) {
            $f[] = "$w: abruf braucht {id}";
        }
        if ($art !== 'lwd-geojson' && !is_array($n['stationen'] ?? null)) {
            $f[] = "$w: stationen {id: {name, hoehe}} fehlt";
        }
        if (isset($n['abruf_stunden_utc']) && (!is_array($n['abruf_stunden_utc']) || !array_is_list($n['abruf_stunden_utc']) || !$n['abruf_stunden_utc']
                || array_filter($n['abruf_stunden_utc'], fn($h) => !is_int($h) || $h < 0 || $h > 23))) {
            $f[] = "$w: abruf_stunden_utc muss eine Liste von Stunden 0-23 sein";
        }
        if ($art === 'snowgrid') {
            foreach (is_array($n['stationen'] ?? null) ? $n['stationen'] : [] as $pid => $p) {
                if (!is_numeric($p['lat'] ?? null) || !is_numeric($p['lon'] ?? null) || !is_numeric($p['hoehe'] ?? null)) {
                    $f[] = "$w: Punkt $pid braucht lat, lon und hoehe";
                }
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
            } elseif (isset($netze[$netz]['stationen']) && is_array($netze[$netz]['stationen'])) {
                foreach (array_diff($ids, array_keys($netze[$netz]['stationen'])) as $x) {
                    $f[] = "$wo: Station $x fehlt in messnetze.$netz.stationen";
                }
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
    $modell = array_values(array_filter($liste, fn($s) => !empty($s['wert']['modell'])));
    $liste = array_values(array_filter($liste, fn($s) => empty($s['wert']['modell'])));
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $zahl = fn(float $v) => number_format($v, 0, ',', '.');
    $tage = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    $wann = function (DateTimeImmutable $z) use ($tz, $heute, $tage): string {
        $z = $z->setTimezone($tz);
        return ($z->format('Y-m-d') === $heute->format('Y-m-d') ? 'heute' : $tage[(int)$z->format('w')] . ' ' . $z->format('j.n.')) . ' ' . $z->format('G:i');
    };
    $tagText = fn(int $ts) => $tage[(int)gmdate('w', $ts)] . ' ' . gmdate('j.n.', $ts);
    $aenderung = function (?float $d, string $was) use ($zahl): string {
        return $d === null ? '' : ' <span class="leise" title="Änderung der Schneehöhe ' . $was . ', mit Setzung; kein Neuschnee">('
             . ($was === 'in 24 Stunden' ? '24 h ' : 'Vortag ') . (round($d) > 0 ? '+' : (round($d) < 0 ? '−' : '±')) . $zahl(abs(round($d))) . ')</span>';
    };
    $modellZeile = '';
    if ($modell) {
        // Tageswert mit Zeitstempel 0 UTC: das Datum des Tages, nicht die Uhrzeit
        $tag = max(array_map(fn($s) => $s['wert']['zeit']->getTimestamp(), $modell));
        $tagStand = $tagText($tag);
        $teile = [];
        foreach ($modell as $s) {
            $w = $s['wert'];
            $teile[] = '<span title="' . $e($w['name'] . ', Modellpunkt ' . ($w['hoehe'] !== null ? $zahl($w['hoehe']) . ' m' : '')) . '">'
                     . ($w['hoehe'] !== null ? $zahl($w['hoehe']) . ' m' : $e($w['name'])) . ' <b>' . $zahl(round($w['hs'])) . ' cm</b>'
                     . $aenderung($w['hsd24'], 'zum Vortag') . '</span>';
        }
        $n = $netze[$modell[0]['netz']] ?? [];
        $q = $n['kurz'] ?? $modell[0]['netz'];
        $modellZeile = '<p class="klein messung"><span class="leise">Schneehöhe im Modell, Stand ' . $e($tagStand) . ' ('
                     . (isset($n['url']) ? '<a href="' . $e($n['url']) . '" rel="noopener">' . $e($q) . ' ↗</a>' : $e($q))
                     . '):</span> ' . implode(' · ', $teile) . '</p>';
    }
    if (!$liste) {
        return $modellZeile;
    }
    // Kopf: Zeit der neuesten laufenden Messung; nur Tageswerte (IMIS): deren Datum
    $laufend = array_filter($liste, fn($s) => empty($s['wert']['tageswert']));
    $kopf = $laufend ? $wann(new DateTimeImmutable('@' . max(array_map(fn($s) => $s['wert']['zeit']->getTimestamp(), $laufend))))
                     : 'Tageswert ' . $tagText(max(array_map(fn($s) => $s['wert']['zeit']->getTimestamp(), $liste)));
    $teile = [];
    foreach ($liste as $s) {
        $w = $s['wert'];
        $tw = !empty($w['tageswert']);
        $aend = $aenderung($w['hsd24'], $tw ? 'zum Vortag' : 'in 24 Stunden');
        $hn = $w['hn24'] ?? null;
        $teile[] = '<span title="' . $e($w['name'] . ', ' . ($w['hoehe'] !== null ? $zahl($w['hoehe']) . ' m, ' : '') . ($tw ? 'Tageswert vom ' . $tagText($w['zeit']->getTimestamp()) : 'gemessen ' . $wann($w['zeit']))) . '">'
                 . $e($w['name']) . ($w['hoehe'] !== null ? ' <span class="leise">' . $zahl($w['hoehe']) . ' m</span>' : '')
                 . ' <b>' . $zahl(round($w['hs'])) . ' cm</b>' . $aend
                 . ($hn !== null ? ' <span class="leise" title="Neuschnee der letzten 24 Stunden, vom SLF mit dem Schneedeckenmodell SNOWPACK gerechnet, kein Messwert">· Neuschnee '
                                 . $zahl(round($hn)) . ' cm (Modell SNOWPACK)</span>' : '')
                 . ($w['alt'] ? ' <span class="leise">(' . $e($tw ? $tagText($w['zeit']->getTimestamp()) : $wann($w['zeit'])) . ')</span>' : '') . '</span>';
    }
    // Quellen je Messnetz mit eigenem Link (SLF verlangt den Link auf slf.ch)
    $quellen = [];
    foreach ($liste as $s) {
        $n = $netze[$s['netz']] ?? [];
        $name = $n['quelle'] ?? $n['kurz'] ?? $s['netz'];
        $quellen[$s['netz']] = isset($n['url']) ? '<a href="' . $e($n['url']) . '" rel="noopener">' . $e($name) . ' ↗</a>' : $e($name);
    }
    return '<p class="klein messung"><span class="leise">Schneehöhe gemessen ' . $e($kopf) . ' ('
         . implode(', ', $quellen) . '):</span> ' . implode(' · ', $teile) . '</p>' . $modellZeile;
}

/*
 * Webcams (seit 12.11.2026): je Messstation die naechste Webcam, nur verlinkt (kein Bild).
 * Katalog daten/schneeregionen.json, Teil webcams:
 *   stationen  {"netz:id": {name, url, titel?, km?, hoehe?}}  naechste Webcam zur Station
 *   regionen   {region_id: {...}}  fuer Regionen ohne Messstation, naechste zum Vorhersagepunkt
 * Gewaehlt mit tools/webcams_suchen.php (Action "Webcams suchen").
 */
const WEBCAM_AN_DER_STATION_KM = 0.5;  // naeher: "an der Station"

/** Fehler im Katalogteil webcams */
function webcams_pruefen(array $d): array
{
    $w = $d['webcams'] ?? [];
    if (!is_array($w)) {
        return ['webcams muss ein Objekt sein'];
    }
    $f = [];
    $regionIds = array_column($d['regionen'] ?? [], 'id');
    foreach (['stationen', 'regionen'] as $teil) {
        foreach ($w[$teil] ?? [] as $k => $c) {
            $wo = "webcams.$teil.$k";
            if (!is_string($c['name'] ?? null) || $c['name'] === '') {
                $f[] = "$wo: name fehlt";
            }
            if (!is_string($c['url'] ?? null) || !str_starts_with($c['url'], 'https://')) {
                $f[] = "$wo: url muss mit https:// beginnen";
            }
            if (isset($c['km']) && (!is_numeric($c['km']) || $c['km'] < 0)) {
                $f[] = "$wo: km muss eine Zahl ab 0 sein";
            }
            if ($teil === 'stationen' && !preg_match('/^[a-z0-9-]+:.+$/', (string)$k)) {
                $f[] = "$wo: Schluessel muss netz:id sein";
            }
            if ($teil === 'regionen' && !in_array($k, $regionIds, true)) {
                $f[] = "$wo: Region $k gibt es nicht";
            }
        }
    }
    return $f;
}

/** Name einer Station: aus den geladenen Messwerten, sonst aus dem Katalog, sonst die Kennung */
function station_name(string $netz, string $id, array $netze, array $geladen): string
{
    $n = $geladen[$netz]['stationen'][$id]['name'] ?? $netze[$netz]['stationen'][$id]['name'] ?? $id;
    // GeoSphere meldet Namen in Grossbuchstaben
    return $n === mb_strtoupper($n) ? mb_convert_case(mb_strtolower($n), MB_CASE_TITLE) : $n;
}

/**
 * Zeile "Webcams …" fuer Regionskarte und Stundenseite (HTML), leer ohne Webcam.
 * Je Messstation der Region (ohne SNOWGRID-Punkte) die naechste Webcam mit Entfernung;
 * teilen sich Stationen eine Webcam, steht sie einmal (bei der naechsten Station).
 */
function webcams_html(array $region, array $webcams, array $netze, array $geladen): string
{
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $km = fn(float $v) => $v < 10 ? number_format($v, 1, ',', '.') : number_format(round($v), 0, ',', '.');
    $treffer = [];   // url => [cam, wo]
    foreach ($region['stationen'] ?? [] as $netz => $ids) {
        if (($netze[$netz]['art'] ?? '') === 'snowgrid') {
            continue;
        }
        foreach ($ids as $id) {
            $c = $webcams['stationen']["$netz:$id"] ?? null;
            if (!$c) {
                continue;
            }
            $name = station_name($netz, $id, $netze, $geladen);
            $d = isset($c['km']) ? (float)$c['km'] : null;
            $wo = $d === null ? 'bei ' . $name : ($d < WEBCAM_AN_DER_STATION_KM ? 'an der Station ' . $name : $km($d) . ' km von ' . $name);
            if (!isset($treffer[$c['url']]) || ($d ?? INF) < ($treffer[$c['url']][0]['km'] ?? INF)) {
                $treffer[$c['url']] = [$c, $wo];
            }
        }
    }
    if (!$treffer && ($c = $webcams['regionen'][$region['id']] ?? null)) {
        $treffer[$c['url']] = [$c, isset($c['km']) ? 'rund ' . $km((float)$c['km']) . ' km vom Tourengebiet' : 'im Gebiet'];
    }
    if (!$treffer) {
        return '';
    }
    $teile = [];
    foreach ($treffer as [$c, $wo]) {
        $title = ($c['titel'] ?? $c['name']) . (isset($c['hoehe']) ? ', ' . number_format((int)$c['hoehe'], 0, ',', '.') . ' m' : '');
        $teile[] = '<a href="' . $e($c['url']) . '" rel="noopener" title="' . $e($title) . '">' . $e($c['name']) . ' ↗</a> <span class="leise">' . $e($wo) . '</span>';
    }
    return '<p class="klein messung webcams"><span class="leise">📷&#xFE0E; Webcams:</span> ' . implode(' · ', $teile) . '</p>';
}
