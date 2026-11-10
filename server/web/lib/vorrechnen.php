<?php
/**
 * Vorgerechnete Bewertung (seit 8.11.2026)
 *
 * Bis dahin rechnete jeder Aufruf der Rangliste alle Regionen im Umkreis neu:
 * Rohdaten entpacken (rund 100 MB) und region_bewerten() je Region, bis etwa 1 s,
 * bei 2 PHP-Prozessen im Tarif S geteilt mit ammerseewind.de.
 *
 * Die Bewertung einer Region haengt nicht vom Standort ab, nur von Sportart, Tag,
 * Rohdaten und Katalog-Eintrag; heute zusaetzlich von der Uhrzeit (Restfenster).
 * Deshalb liegt sie je Sportart und Tag in einer Datei, mit einem Eintrag je Region:
 *
 *   <cache_dir>/bewertung-<sport>-<tag>-<datenschluessel>.ser
 *     [region_id => ['h' => Hash des Katalog-Eintrags, 'stunde' => UTC-Stunde oder null,
 *                    'tag' => region_bewerten()[tag] oder null ohne Daten]]
 *   <cache_dir>/stand-<datenschluessel>.ser   ['_ts' => ..., '_fp' => ...] fuer alle Regionen
 *
 * Der Datenschluessel haengt an den Rohdaten-Dateien (Name, Groesse, Aenderungszeit)
 * und am Code der Bewertung; ein neuer Sammler-Lauf oder ein Deploy macht also neue
 * Dateien. Ein Eintrag gilt, wenn der Katalog-Eintrag der Region gleich ist und,
 * falls der Tag dort gerade heute ist, die UTC-Stunde stimmt (Restfenster: ab
 * jetzt + 30 min, auf die volle Stunde gerundet, wechselt zur vollen Stunde).
 *
 * Was fehlt, rechnet die Seite selbst, in Stapeln von BEWERTUNG_STAPEL Regionen
 * (Speicher), und schreibt es dazu. vorrechnen.php fuellt alles fuer eine Sportart
 * vorab; die Action "Sammeln" ruft es nach jedem Lauf fuer jede Sportart auf.
 *
 * serialize statt JSON: haelt float und int auseinander (JSON macht aus 1.0 eine 1).
 * Dateien aelter als BEWERTUNG_AUFBEWAHREN werden beim Schreiben geloescht.
 */

const BEWERTUNG_STAPEL = 12;              // Regionen je daten_laden() beim Nachrechnen
const BEWERTUNG_AUFBEWAHREN = 2 * 86400;  // aeltere Zwischenspeicher-Dateien loeschen
const BEWERTUNG_QUELLEN = ['vorhersage', 'ensemble', 'welle', 'wasser', 'seewasser', 'druck', 'druck_ensemble', 'hoehe', 'hoehe_ensemble'];

/**
 * Schluessel fuer den Stand der Rohdaten und des Codes: dieselben Dateien, die
 * daten_laden() liest (heute und gestern UTC), mit Groesse und Aenderungszeit,
 * dazu die Aenderungszeit von bewertung.php und dieser Datei (Deploy = neu rechnen).
 */
function daten_schluessel(string $dataDir): string
{
    $teile = [];
    foreach (BEWERTUNG_QUELLEN as $q) {
        foreach ([gmdate('Y-m-d'), gmdate('Y-m-d', time() - 86400)] as $d) {
            foreach (array_merge(glob("$dataDir/$q/$d.ndjson") ?: [], glob("$dataDir/$q/{$d}_*.ndjson.gz") ?: []) as $f) {
                $teile[] = "$q/" . basename($f) . ':' . @filesize($f) . ':' . @filemtime($f);
            }
        }
    }
    sort($teile);
    $teile[] = 'code:' . @filemtime(__DIR__ . '/bewertung.php') . ':' . @filemtime(__FILE__);
    return substr(md5(implode("\n", $teile)), 0, 16);
}

/** UTC-Stunde, fuer die ein Eintrag gilt: nur wenn $tag in der Zeitzone der Region heute ist */
function bewertung_stunde(array $region, string $tag, DateTimeImmutable $jetzt): ?string
{
    $lok = $jetzt->setTimezone(new DateTimeZone($region['zeitzone']));
    return $lok->format('Y-m-d') === $tag ? $jetzt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H') : null;
}

function cache_lesen(string $datei): ?array
{
    if (!is_file($datei)) {
        return null;
    }
    $v = @unserialize((string)@file_get_contents($datei), ['allowed_classes' => false]);
    return is_array($v) ? $v : null;
}

/** Atomar schreiben (erst .tmp, dann umbenennen), damit nie halbe Dateien gelesen werden */
function cache_schreiben(string $datei, array $v): void
{
    $tmp = "$datei." . getmypid() . '.tmp';
    if (@file_put_contents($tmp, serialize($v)) !== false) {
        @rename($tmp, $datei);
    }
}

function cache_bereit(?string $cacheDir): bool
{
    return $cacheDir !== null && (is_dir($cacheDir) || @mkdir($cacheDir, 0700, true)) && is_writable($cacheDir);
}

/** Alte Dateien loeschen (bewertung-*, stand-*) */
function cache_aufraeumen(string $cacheDir): void
{
    foreach (array_merge(glob("$cacheDir/bewertung-*.ser") ?: [], glob("$cacheDir/stand-*.ser") ?: [], glob("$cacheDir/*.tmp") ?: []) as $alt) {
        if (@filemtime($alt) < time() - BEWERTUNG_AUFBEWAHREN) {
            @unlink($alt);
        }
    }
}

/**
 * Rangliste wie rangliste(), aber aus dem Zwischenspeicher. Was fehlt, wird
 * gerechnet und dazugeschrieben. $cacheDir null oder nicht beschreibbar: wie bisher.
 * Rueckgabe ['liste' => wie rangliste(), 'stand' => ['_ts', '_fp'] fuer alle Regionen
 * (ohne Rohdaten), 'gerechnet' => Zahl der neu gerechneten Regionen,
 * 'aus_cache' => Zahl der Regionen aus dem Zwischenspeicher].
 */
function rangliste_mit_cache(array $regionen, string $dataDir, ?string $cacheDir, string $sportId, array $heim, float $radiusKm, array $tage, ?DateTimeImmutable $jetzt = null, int $stapel = BEWERTUNG_STAPEL): array
{
    $jetzt ??= new DateTimeImmutable('now');
    $sport = SPORTARTEN[$sportId];
    $kandidaten = [];
    foreach ($regionen as $r) {
        $km = entfernung_km($heim, $r['punkt']);
        if ($km <= $radiusKm) {
            $kandidaten[] = [$r, $km];
        }
    }

    if (!cache_bereit($cacheDir)) {
        // Wie bis 8.11.2026: alles im Umkreis auf einmal laden und rechnen
        $stand = daten_laden($dataDir, nur: array_map(fn($k) => $k[0]['id'], $kandidaten));
        $liste = rangliste(array_column($kandidaten, 0), $stand, $sport, $heim, INF, $tage, $jetzt);
        return ['liste' => $liste, 'stand' => ['_ts' => $stand['_ts'], '_fp' => $stand['_fp']],
                'gerechnet' => count($liste), 'aus_cache' => 0];
    }

    $schluessel = daten_schluessel($dataDir) . '-' . substr(md5(serialize($sport)), 0, 8);
    $standDatei = "$cacheDir/stand-" . explode('-', $schluessel)[0] . '.ser';
    $stand = cache_lesen($standDatei);

    // Je Tag: Datei lesen, fehlende oder veraltete Eintraege sammeln
    $dateien = [];
    $vorrat = [];
    $fehlt = [];   // region_id => [tage]
    $nachId = [];
    foreach ($kandidaten as [$r]) {
        $nachId[$r['id']] = $r;
    }
    foreach ($tage as $tag) {
        $dateien[$tag] = "$cacheDir/bewertung-$sportId-$tag-$schluessel.ser";
        $vorrat[$tag] = cache_lesen($dateien[$tag]) ?? [];
        foreach ($kandidaten as [$r]) {
            $e = $vorrat[$tag][$r['id']] ?? null;
            if ($e === null || $e['h'] !== md5(serialize($r)) || $e['stunde'] !== bewertung_stunde($r, $tag, $jetzt)) {
                $fehlt[$r['id']][] = $tag;
            }
        }
    }

    // Nachrechnen in Stapeln: daten_laden() je Stapel entpackt nur dessen Regionen
    $neu = array_fill_keys($tage, []);
    foreach (array_chunk(array_keys($fehlt), max(1, $stapel)) as $stapel) {
        $roh = daten_laden($dataDir, nur: $stapel, fingerabdruck: $stand === null);
        if ($stand === null) {
            $stand = ['_ts' => $roh['_ts'], '_fp' => $roh['_fp']];
        }
        foreach ($stapel as $id) {
            $r = $nachId[$id];
            $daten = region_daten($r, $roh);
            $proTag = $daten === null ? [] : region_bewerten($r, $daten, $sport, $fehlt[$id], $jetzt);
            foreach ($fehlt[$id] as $tag) {
                $neu[$tag][$id] = ['h' => md5(serialize($r)), 'stunde' => bewertung_stunde($r, $tag, $jetzt),
                                   'tag' => $daten === null ? null : $proTag[$tag]];
            }
            unset($daten, $proTag);
        }
        unset($roh);
    }
    if ($stand === null) {
        // Alles aus dem Zwischenspeicher, aber kein Stand (Datei geloescht): nur Kopfzeilen lesen
        $roh = daten_laden($dataDir, nur: []);
        $stand = ['_ts' => $roh['_ts'], '_fp' => $roh['_fp']];
        unset($roh);
    }
    if (!is_file($standDatei)) {
        cache_schreiben($standDatei, $stand);
    }

    // Neue Eintraege dazuschreiben. Unter Sperre neu lesen und mischen, damit zwei
    // gleichzeitige Aufrufe sich nicht gegenseitig Regionen ueberschreiben.
    $geschrieben = false;
    foreach ($tage as $tag) {
        if (!$neu[$tag]) {
            continue;
        }
        $sperre = @fopen("$cacheDir/bewertung.lock", 'c');
        if ($sperre) {
            flock($sperre, LOCK_EX);
        }
        $vorrat[$tag] = array_replace(cache_lesen($dateien[$tag]) ?? [], $neu[$tag]);
        cache_schreiben($dateien[$tag], $vorrat[$tag]);
        if ($sperre) {
            flock($sperre, LOCK_UN);
            fclose($sperre);
        }
        $geschrieben = true;
    }
    if ($geschrieben) {
        cache_aufraeumen($cacheDir);
    }

    // Rangliste zusammensetzen
    $liste = [];
    foreach ($kandidaten as [$r, $km]) {
        $proTag = [];
        foreach ($tage as $tag) {
            $proTag[$tag] = $vorrat[$tag][$r['id']]['tag'] ?? null;
        }
        if (in_array(null, $proTag, true)) {
            continue;   // keine Daten (wie rangliste(): weder Vorhersage noch Ensemble)
        }
        // Fremde Prognose erst hier, nicht im Zwischenspeicher (siehe fremd_einmischen)
        $liste[] = ranglisten_eintrag($r, $km, fremd_einmischen($r, $proTag, $sport, $jetzt));
    }
    return ['liste' => rangliste_sortieren($liste, 'summe'), 'stand' => $stand,
            'gerechnet' => count($fehlt), 'aus_cache' => count($kandidaten) - count($fehlt)];
}

/**
 * Alles fuer eine Sportart vorab rechnen: Bewertung aller Regionen (Auto und Flug)
 * fuer heute bis Tag 7, dazu der Verlauf fuer "Heute bis uebermorgen" und
 * "Tag 3 bis 7" (gilt nur bis zur naechsten vollen Stunde). Fuer vorrechnen.php.
 * Rueckgabe: Kennzahlen fuer das Protokoll der Action.
 */
function vorrechnen(array $regionen, string $dataDir, string $cacheDir, string $sportId, string $tzSeite, string $katalogDatei = '', ?DateTimeImmutable $jetzt = null): array
{
    $jetzt ??= new DateTimeImmutable('now');
    $t0 = microtime(true);
    $heute = new DateTimeImmutable('today', new DateTimeZone($tzSeite));
    $tage = [];
    for ($i = 0; $i <= 6; $i++) {
        $tage[] = $heute->modify("+$i day")->format('Y-m-d');
    }
    $rb = rangliste_mit_cache($regionen, $dataDir, $cacheDir, $sportId, [0.0, 0.0], INF, $tage, $jetzt);
    $t1 = microtime(true);
    foreach ([array_slice($tage, 0, 3), array_slice($tage, 3)] as $teil) {
        verlauf_mit_cache($regionen, $dataDir, $cacheDir, $rb['stand'], SPORTARTEN[$sportId], $teil, $jetzt, $katalogDatei);
    }
    return ['sport' => $sportId, 'regionen' => count($rb['liste']), 'gerechnet' => $rb['gerechnet'], 'aus_cache' => $rb['aus_cache'],
            'bewertung_s' => round($t1 - $t0, 2), 'verlauf_s' => round(microtime(true) - $t1, 2),
            'speicher_mb' => round(memory_get_peak_usage(true) / 1048576)];
}
