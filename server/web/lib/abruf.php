<?php
/**
 * Abrufe ausserhalb von Open-Meteo-Rechenlast: HTTP mit Wiederholung, Lageberichte der
 * Lawinenwarndienste und Messstationen (seit 13.11.2026 eigene Datei, vorher in
 * server/sammler.php).
 *
 * Zwei Wege nutzen diese Funktionen:
 *  - der Sammler (GitHub Action "Sammeln", 3 Laeufe am Tag), schreibt je Lauf eine Datei
 *    lawine/<lauf>.ndjson.gz bzw. stationen/<lauf>.ndjson.gz;
 *  - lawine_holen.php auf dem Server (server_abruf()), angestossen von melden.php auf
 *    ammerseewind.de etwa alle 30 Minuten. Der GitHub-Zeitplan startete im November 2026
 *    oft Stunden zu spaet; fuer den Lagebericht ist das zu lang. Der Server schreibt je
 *    Quelle nur eine kleine Datei <quelle>/server.ndjson mit der neuesten Zeile je Dienst
 *    bzw. Messnetz (und dem letzten Fehlversuch), damit die Seite nicht bei jedem Aufruf
 *    Dutzende Abrufe einlesen muss. lawine_laden() und stationen_laden() nehmen je Dienst
 *    die neueste Zeile aus allen Dateien.
 *
 * Kein Open-Meteo: diese Abrufe zaehlen nicht zum Open-Meteo-Kontingent.
 */

/** Wartezeit in Sekunden; Tests setzen die Umgebungsvariable auf 0 */
function umkreis_wartezeit(string $variable, int $standard): int
{
    $wert = getenv($variable);
    return $wert !== false && is_numeric($wert) ? max(0, (int)$wert) : $standard;
}

function fetch(string $url, int $timeout): array
{
    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        // Seit 14.11.2026 bis 15 s statt 5 s: Von GitHub aus brach am 13.11. der TLS-Aufbau zu
        // api.open-meteo.com mehrfach nach 5 s ab ("SSL connection timeout"), danach klappte es.
        CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_ENCODING       => '',      // gzip annehmen, spart viel bei grossen Antworten
        CURLOPT_USERAGENT      => 'windapp-umkreis/0.1 (Hobbyprojekt)',
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $body = '';
    }
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'http'  => $http,
        'ms'    => (int)round((microtime(true) - $t0) * 1000),
        'bytes' => strlen($body),
        'err'   => $err,
        'body'  => $body,
    ];
}

/**
 * Abruf mit Wiederholung: bei 429 eine Minute warten; bei Zeitueberschreitung
 * (http 0) oder 5xx kurz. Am 8.11.2026 brach die Wiederholung nach 429 mit
 * "SSL connection timeout" ab, deshalb bis zu zwei Wiederholungen fuer beide Faelle.
 */
function abruf(string $url, int $timeout, string $wo, array &$bericht): array
{
    $r = fetch($url, $timeout);
    $max = (int)($GLOBALS['umkreis_abruf_wiederholungen'] ?? 2);   // lawine_holen.php: 1
    for ($versuch = 1; $versuch <= $max && ($r['http'] === 429 || $r['http'] === 0 || $r['http'] >= 500); $versuch++) {
        $warte = $r['http'] === 429
            ? umkreis_wartezeit('UMKREIS_WARTE_429', 65)
            : umkreis_wartezeit('UMKREIS_WARTE_FEHLER', (int)($GLOBALS['umkreis_abruf_warte_fehler'] ?? 15));
        $grund = $r['http'] === 0 ? 'keine Antwort' : "HTTP {$r['http']}";
        $bericht[] = "$wo: $grund, warte $warte s ($versuch. Wiederholung)";
        sleep($warte);
        $r = fetch($url, $timeout);
    }
    return $r;
}

/** Nur fuer die Tests: Open-Meteo und Alplakes durch einen lokalen Ersatz tauschen */
function test_url(string $url, ?string $ersatz): string
{
    return $ersatz ? preg_replace('#^https://(?:[a-z-]+\.open-meteo\.com|alplakes-api\.eawag\.ch|aws\.slf\.ch|static\.avalanche\.report|static\.lawinen-warnung\.eu|wiski\.tirol\.gv\.at|dataset\.api\.hub\.geosphere\.at|data\.geo\.admin\.ch|geoservices\.buergernetz\.bz\.it|measurement-api\.slf\.ch|kitepredictionlagodigarda\.onrender\.com)#', $ersatz, $url) : $url;
}

/**
 * Lagebericht eines Warndiensts fuer heute und morgen (Ortstag in Mitteleuropa).
 * HTTP 404 heisst "noch kein Bericht" (morgen vor 17 Uhr, ausserhalb der Saison),
 * kein Fehler. Fehler: keine Antwort, andere HTTP-Codes oder ein unlesbarer Bericht.
 * Gespeichert werden nur freigegebene Mikroregionen.
 * Rueckgabe [zeile fuer die Datei, fehler|null, ms]
 */
function lawine_abrufen(string $id, array $dienst, ?string $ersatz, int $timeout, array &$bericht): array
{
    $tz = new DateTimeZone('Europe/Berlin');
    $heute = new DateTimeImmutable('today', $tz);
    $urls = [];
    foreach ([$heute, $heute->modify('+1 day')] as $d) {
        $urls[lawine_url($dienst, $d->format('Y-m-d'))][] = $d->format('Y-m-d');
    }
    $tage = [];
    $fehler = [];
    $ms = 0;
    foreach ($urls as $url => $daten) {
        $r = abruf(test_url($url, $ersatz), $timeout, "lawine/$id", $bericht);
        $ms += $r['ms'];
        $b = null;
        if ($r['http'] === 404) {
            $bericht[] = "lawine/$id: " . implode(', ', $daten) . ' noch kein Bericht (404)';
        } elseif ($r['http'] >= 200 && $r['http'] < 300) {
            $b = lawine_lesen($r['body'], $dienst['format']);
            if ($b === null) {
                $fehler[] = implode(', ', $daten) . ': Antwort ist kein CAAML-v6-Bericht: ' . substr(preg_replace('/\s+/', ' ', $r['body']), 0, 150);
            } else {
                $b = lawine_filtern($b, $dienst['freigegeben']);
                $bericht[] = sprintf('lawine/%s: %s ok, %d Berichtsteile, %d KB, %d ms', $id, implode(', ', $daten), count($b), $r['bytes'] / 1024, $r['ms']);
            }
        } else {
            $fehler[] = implode(', ', $daten) . ": http={$r['http']} " . ($r['err'] !== '' ? $r['err'] : substr(preg_replace('/\s+/', ' ', $r['body']), 0, 150));
        }
        // Ohne {datum} gilt derselbe Bericht fuer alle Tage (SLF: gerade gueltiger Bericht)
        foreach ($daten as $d) {
            $tage[$d] = $b;
        }
    }
    $ok = !$fehler || array_filter($tage) !== [];
    $zeile = ['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'source' => 'lawine', 'ok' => $ok, 'http' => $ok ? 200 : 0, 'ms' => $ms,
              'dienst' => $id, 'regionen' => [$id], 'tage' => $tage];
    if ($fehler) {
        $zeile['err'] = implode(' | ', $fehler);
    }
    return [$zeile, $fehler ? implode(' | ', $fehler) : null];
}

/**
 * Stationen eines Messnetzes (seit 9.11.2026): ein Abruf, gespeichert werden nur die
 * Stationen, die der Katalog nennt. Fehlt eine davon in der Antwort, steht das im
 * Bericht, ist aber kein Fehler (Station kann ausfallen).
 * Rueckgabe [zeile fuer die Datei, fehler|null]
 */
function stationen_abrufen(string $netz, array $info, array $ids, ?string $ersatz, int $timeout, array &$bericht): array
{
    $art = $info['art'] ?? 'lwd-geojson';
    if (in_array($art, STATION_EINZELN, true)) {
        return stationen_einzeln($netz, $info, $ids, $ersatz, $timeout, $bericht);
    }
    $url = $info['abruf'];
    if ($art === 'snowgrid') {
        $url = snowgrid_url($info, $ids, time());
    } elseif ($art === 'slf-imis') {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'period_in_days=3';
    } elseif ($art === 'geosphere') {
        $url .= '?' . http_build_query(['parameters' => 'SCHNEE', 'station_ids' => implode(',', $ids),
                    'start' => gmdate('Y-m-d\TH:i', time() - STATION_STUNDEN_ZURUECK * 3600), 'end' => gmdate('Y-m-d\TH:i')]);
    }
    $r = abruf(test_url($url, $ersatz), $timeout, "stationen/$netz", $bericht);
    $fehler = null;
    $st = null;
    if ($r['http'] >= 200 && $r['http'] < 300 && $art === 'snowgrid') {
        $st = snowgrid_lesen($r['body'], $ids, $info['stationen'] ?? []);
        if ($st === null) {
            $fehler = 'Antwort ist keine SNOWGRID-Zeitreihe mit ' . count($ids) . ' Punkten: ' . substr(preg_replace('/\s+/', ' ', $r['body']), 0, 150);
        } else {
            $fehlen = array_diff($ids, array_keys($st));
            $bericht[] = sprintf('stationen/%s: ok, %d von %d Punkten mit Wert, %d KB, %d ms', $netz, count($st), count($ids), $r['bytes'] / 1024, $r['ms'])
                       . ($fehlen ? ' (ohne Wert: ' . implode(', ', $fehlen) . ')' : '');
        }
    } elseif ($r['http'] >= 200 && $r['http'] < 300 && $art === 'slf-imis') {
        $st = slf_imis_lesen($r['body'], $ids, $info['stationen'] ?? []);
        if ($st === null) {
            $fehler = 'Antwort ist keine IMIS-Liste (station_code, measure_date, HS, HN_1D): ' . substr(preg_replace('/\s+/', ' ', $r['body']), 0, 150);
        } else {
            $fehlen = array_diff($ids, array_keys($st));
            $bericht[] = sprintf('stationen/%s: ok, %d von %d Stationen mit Schneehöhe, %d KB, %d ms', $netz, count($st), count($ids), $r['bytes'] / 1024, $r['ms'])
                       . ($fehlen ? ' (ohne Wert: ' . implode(', ', $fehlen) . ')' : '');
        }
    } elseif ($r['http'] >= 200 && $r['http'] < 300 && $art === 'geosphere') {
        $st = geosphere_lesen($r['body'], $info['stationen'] ?? []);
        if ($st === null) {
            $fehler = 'Antwort ist keine Stationszeitreihe (timestamps, features): ' . substr(preg_replace('/\s+/', ' ', $r['body']), 0, 150);
        } else {
            $fehlen = array_diff($ids, array_keys($st));
            $bericht[] = sprintf('stationen/%s: ok, %d von %d Stationen mit Schneehöhe, %d KB, %d ms', $netz, count($st), count($ids), $r['bytes'] / 1024, $r['ms'])
                       . ($fehlen ? ' (ohne Wert: ' . implode(', ', $fehlen) . ')' : '');
        }
    } elseif ($r['http'] >= 200 && $r['http'] < 300) {
        $st = stationen_filtern($r['body'], $ids);
        if ($st === null) {
            $fehler = 'Antwort ist kein GeoJSON mit features: ' . substr(preg_replace('/\s+/', ' ', $r['body']), 0, 150);
        } else {
            $fehlen = array_diff($ids, array_keys($st));
            $bericht[] = sprintf('stationen/%s: ok, %d von %d Stationen, %d KB, %d ms', $netz, count($st), count($ids), $r['bytes'] / 1024, $r['ms'])
                       . ($fehlen ? ' (fehlen: ' . implode(', ', $fehlen) . ')' : '');
        }
    } else {
        $fehler = "http={$r['http']} " . ($r['err'] !== '' ? $r['err'] : substr(preg_replace('/\s+/', ' ', $r['body']), 0, 150));
    }
    $zeile = ['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'source' => 'stationen', 'ok' => $fehler === null, 'http' => $r['http'], 'ms' => $r['ms'],
              'netz' => $netz, 'regionen' => [$netz], 'stationen' => $st ?? []];
    if ($fehler !== null) {
        $zeile['err'] = $fehler;
    }
    return [$zeile, $fehler];
}

/**
 * MeteoSwiss (CSV) und Suedtirol (JSON): ein Abruf je Station; eine fehlende Datei
 * oder Spalte ist kein Fehler des Netzes
 */
function stationen_einzeln(string $netz, array $info, array $ids, ?string $ersatz, int $timeout, array &$bericht): array
{
    $st = [];
    $fehler = [];
    $leer = [];
    $ms = 0;
    foreach ($ids as $id) {
        $r = abruf(test_url(stationen_url_einzeln($info, $id, time()), $ersatz), $timeout, "stationen/$netz/$id", $bericht);
        $ms += $r['ms'];
        if ($r['http'] < 200 || $r['http'] >= 300) {
            $fehler[] = "$id: http={$r['http']} " . ($r['err'] !== '' ? $r['err'] : substr(preg_replace('/\s+/', ' ', $r['body']), 0, 100));
            continue;
        }
        $e = ($info['art'] ?? '') === 'suedtirol'
            ? suedtirol_lesen($r['body'], $id, $info['stationen'][$id] ?? [])
            : meteoswiss_lesen(function_exists('mb_convert_encoding') ? mb_convert_encoding($r['body'], 'UTF-8', 'Windows-1252') : $r['body'], $id, $info['stationen'][$id] ?? []);
        if ($e === null) {
            $fehler[] = "$id: Antwort hat nicht das erwartete Format";
        } elseif ($e === ['leer']) {
            $leer[] = $id;
        } else {
            $st[$id] = $e;
        }
    }
    $bericht[] = sprintf('stationen/%s: %d von %d Stationen mit Schneehöhe, %d ms', $netz, count($st), count($ids), $ms) . ($leer ? ' (ohne Wert: ' . implode(', ', $leer) . ')' : '');
    $ok = !$fehler || $st;
    $zeile = ['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'source' => 'stationen', 'ok' => $ok, 'http' => $ok ? 200 : 0, 'ms' => $ms,
              'netz' => $netz, 'regionen' => [$netz], 'stationen' => $st];
    if ($fehler) {
        $zeile['err'] = implode(' | ', $fehler);
    }
    return [$zeile, $fehler ? implode(' | ', $fehler) : null];
}

// ===================================================================
// ABRUF AUF DEM SERVER (seit 13.11.2026, lawine_holen.php)
// ===================================================================

const ABRUF_SERVER_DATEI = 'server.ndjson';
const ABRUF_LAWINE_MIN = 20;      // Minuten Mindestabstand Lagebericht (Anstoss alle 30 min)
const ABRUF_STATIONEN_MIN = 50;   // Minuten Mindestabstand Stationen (rund stuendlich)
const ABRUF_BUDGET_S = 100;       // danach keinen weiteren Dienst bzw. kein weiteres Netz anfangen
const ABRUF_TIMEOUT_S = 10;       // je Abruf; mit einer Wiederholung nach 5 s hoechstens rund 25 s

/**
 * Neueste Zeilen eines Server-Abrufs merken: <dataDir>/<quelle>/server.ndjson enthaelt je
 * Dienst/Netz ($feld) die letzte gute Zeile und, falls der letzte Versuch danach scheiterte,
 * auch diese Fehlerzeile (fuer den Hinweis "letzter Abruf fehlgeschlagen").
 * Atomar ersetzt, waechst nicht.
 */
function server_zeilen_merken(string $dataDir, string $quelle, string $feld, array $zeilen): bool
{
    $f = "$dataDir/$quelle/" . ABRUF_SERVER_DATEI;
    $stand = [];
    foreach (is_file($f) ? (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $z) {
        $j = json_decode($z, true);
        if (is_array($j) && is_string($j[$feld] ?? null)) {
            $stand[$j[$feld]][empty($j['ok']) ? 'versuch' : 'gut'] = $j;
        }
    }
    foreach ($zeilen as $z) {
        $k = (string)($z[$feld] ?? '');
        if ($k === '') {
            continue;
        }
        if (!empty($z['ok'])) {
            $stand[$k] = ['gut' => $z];
        } else {
            $stand[$k]['versuch'] = $z;
        }
    }
    ksort($stand);
    $txt = '';
    foreach ($stand as $s) {
        foreach (['gut', 'versuch'] as $art) {
            if (isset($s[$art])) {
                $txt .= json_encode($s[$art], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    }
    @mkdir(dirname($f), 0700, true);
    $tmp = "$f.tmp";
    return @file_put_contents($tmp, $txt, LOCK_EX) !== false && @rename($tmp, $f);
}

/**
 * Lageberichte (alle ABRUF_LAWINE_MIN Minuten) und Stationen (alle ABRUF_STATIONEN_MIN)
 * auf dem Server holen. Zu frueh angestossen: nichts tun. Ein Fehlversuch zaehlt als
 * Versuch, der naechste kommt beim naechsten Anstoss nach dem Mindestabstand.
 * Rueckgabe ['lawine' => 'ok'|'fehler'|'zu frueh'|..., 'stationen' => ..., 'bericht' => [...]]
 */
function server_abruf(string $dataDir, array $schnee, ?string $ersatz = null, ?int $jetzt = null, array $regionen = []): array
{
    $jetzt ??= time();
    $t0 = microtime(true);
    $GLOBALS['umkreis_abruf_wiederholungen'] = 1;   // im Seitenaufruf nicht minutenlang warten
    $GLOBALS['umkreis_abruf_warte_fehler'] = 5;
    $zeitUm = fn() => microtime(true) - $t0 > ABRUF_BUDGET_S;
    $stateF = "$dataDir/.state/server_abruf.json";
    $state = json_decode((string)@file_get_contents($stateF), true);
    $state = is_array($state) ? $state : [];
    $bericht = [];
    $out = [];

    $faellig = fn(string $q, int $min) => $jetzt - (int)($state[$q] ?? 0) >= $min * 60 - 30;

    $dienste = array_filter($schnee['lawinendienste'] ?? [], fn($d) => !empty($d['freigegeben']));
    if (!$dienste) {
        $out['lawine'] = 'keine freigegebenen Dienste';
    } elseif (!$faellig('lawine', ABRUF_LAWINE_MIN)) {
        $out['lawine'] = 'zu frueh';
    } else {
        $zeilen = [];
        $fehler = 0;
        foreach ($dienste as $id => $dienst) {
            if ($zeitUm()) {
                $bericht[] = "lawine/$id: uebersprungen (Zeit)";
                $fehler++;
                continue;
            }
            [$z, $f] = lawine_abrufen($id, $dienst, $ersatz, ABRUF_TIMEOUT_S, $bericht);
            $zeilen[] = $z;
            $fehler += $f !== null ? 1 : 0;
            if ($f !== null) {
                $bericht[] = "lawine/$id: FEHLER $f";
            }
        }
        $state['lawine'] = $jetzt;
        $out['lawine'] = !server_zeilen_merken($dataDir, 'lawine', 'dienst', $zeilen) ? 'Schreiben fehlgeschlagen'
                       : ($fehler ? "$fehler von " . count($dienste) . ' Diensten fehlgeschlagen' : 'ok');
    }

    $ids = stationen_benoetigt($schnee);
    $netze = array_intersect_key($schnee['messnetze'] ?? [], $ids);
    if (!$netze) {
        $out['stationen'] = 'keine Stationen';
    } elseif (!$faellig('stationen', ABRUF_STATIONEN_MIN)) {
        $out['stationen'] = 'zu frueh';
    } elseif ($zeitUm()) {
        $out['stationen'] = 'uebersprungen (Zeit)';
    } else {
        $zeilen = [];
        $fehler = 0;
        foreach ($netze as $netz => $info) {
            // Netze mit abruf_stunden_utc (SLF IMIS: ein- bis zweimal am Tag) nur im Zeitfenster
            $nz = $state['netze'][$netz] ?? [];
            if (!stationen_netz_faellig($info, $nz['gut'] ?? null, $nz['versuch'] ?? null, $jetzt)) {
                continue;
            }
            if ($zeitUm()) {
                $bericht[] = "stationen/$netz: uebersprungen (Zeit)";
                $fehler++;
                continue;
            }
            [$z, $f] = stationen_abrufen($netz, $info, $ids[$netz] ?? [], $ersatz, ABRUF_TIMEOUT_S, $bericht);
            $zeilen[] = $z;
            $fehler += $f !== null ? 1 : 0;
            if ($f !== null) {
                $bericht[] = "stationen/$netz: FEHLER $f";
            }
            if (!empty($info['abruf_stunden_utc'])) {
                $state['netze'][$netz]['versuch'] = $jetzt;
                if ($f === null) {
                    $state['netze'][$netz]['gut'] = $jetzt;
                }
            }
        }
        $state['stationen'] = $jetzt;
        $out['stationen'] = !server_zeilen_merken($dataDir, 'stationen', 'netz', $zeilen) ? 'Schreiben fehlgeschlagen'
                          : ($fehler ? "$fehler von " . count($netze) . ' Messnetzen fehlgeschlagen' : 'ok');
    }

    // Fremde Prognosen, die der Server selbst holt (seit 23.11.2026: Gardasee), nach dem
    // Zeitplan des Anbieters; ausserhalb des Zeitbudgets, weil der Dienst erst aufwachen muss
    foreach ($regionen as $r) {
        if (($r['fremdprognose']['format'] ?? '') === 'garda') {
            $out['fremd/' . $r['id']] = fremd_abrufen($dataDir, $r, $ersatz, $jetzt, $state, $bericht);
        }
    }

    @mkdir("$dataDir/.state", 0700, true);
    @file_put_contents($stateF, json_encode($state));
    $out['ms'] = (int)round((microtime(true) - $t0) * 1000);
    $out['bericht'] = $bericht;
    return $out;
}

// ===================================================================
// FREMDE PROGNOSE GARDASEE (seit 23.11.2026)
// ===================================================================

const GARDA_RECHNET = [6, 12, 22];   // Uhr (Europe/Berlin): so rechnet Marcos Gardasee-Vorhersage neu
const GARDA_VERSATZ_MIN = 10;        // kurz danach abholen
const GARDA_WIEDERHOLEN_MIN = 60;    // nach einem Fehlversuch fruehestens wieder
const GARDA_TIMEOUT_S = 60;          // der Dienst (onrender.com) schlaeft und braucht zum Aufwachen

/**
 * Faellig, wenn seit dem letzten Rechenlauf des Anbieters (plus GARDA_VERSATZ_MIN) kein
 * guter Abruf war und der letzte Versuch mindestens GARDA_WIEDERHOLEN_MIN her ist.
 */
function garda_faellig(?int $gut, ?int $versuch, int $jetzt): bool
{
    $tz = new DateTimeZone('Europe/Berlin');
    $lauf = null;
    $tag = (new DateTimeImmutable('@' . $jetzt))->setTimezone($tz)->setTime(0, 0);
    foreach ([$tag, $tag->modify('-1 day')] as $t) {
        foreach (GARDA_RECHNET as $h) {
            $z = $t->setTime($h, GARDA_VERSATZ_MIN)->getTimestamp();
            if ($z <= $jetzt && ($lauf === null || $z > $lauf)) {
                $lauf = $z;
            }
        }
    }
    return ($gut === null || $gut < $lauf) && ($versuch === null || $jetzt - $versuch >= GARDA_WIEDERHOLEN_MIN * 60);
}

/**
 * Gardasee-Vorhersage holen und als <dataDir>/fremd/<id>.json {ts, payload} ablegen, nur
 * wenn sie sich lesen laesst (fremdprognose_garda); sonst bleibt die alte Datei.
 * Rueckgabe 'ok', 'zu frueh' oder eine Fehlermeldung.
 */
function fremd_abrufen(string $dataDir, array $region, ?string $ersatz, int $jetzt, array &$state, array &$bericht): string
{
    $id = $region['id'];
    $st = $state['fremd'][$id] ?? [];
    if (!garda_faellig($st['gut'] ?? null, $st['versuch'] ?? null, $jetzt)) {
        return 'zu frueh';
    }
    $state['fremd'][$id]['versuch'] = $jetzt;
    $r = abruf(test_url($region['fremdprognose']['abruf'], $ersatz), GARDA_TIMEOUT_S, "fremd/$id", $bericht);
    if ($r['http'] < 200 || $r['http'] >= 300) {
        $f = "http={$r['http']} " . ($r['err'] !== '' ? $r['err'] : substr(preg_replace('/\s+/', ' ', $r['body']), 0, 150));
        $bericht[] = "fremd/$id: FEHLER $f";
        return $f;
    }
    $j = json_decode($r['body'], true);
    $p = is_array($j) ? fremdprognose_garda($j, $jetzt) : null;
    if ($p === null) {
        $f = 'Antwort ist keine frische Gardasee-Vorhersage: ' . substr(preg_replace('/\s+/', ' ', $r['body']), 0, 150);
        $bericht[] = "fremd/$id: FEHLER $f";
        return $f;
    }
    $datei = "$dataDir/fremd/$id.json";
    @mkdir(dirname($datei), 0700, true);
    if (@file_put_contents("$datei.tmp", json_encode(['ts' => gmdate('Y-m-d\TH:i:s\Z', $jetzt), 'payload' => $j], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false
        || !@rename("$datei.tmp", $datei)) {
        return 'Schreiben fehlgeschlagen';
    }
    $state['fremd'][$id]['gut'] = $jetzt;
    $bericht[] = sprintf('fremd/%s: ok, %d Tage, Stand %s, %d KB, %d ms', $id, count($p['tage']), gmdate('Y-m-d H:i', $p['stand']), $r['bytes'] / 1024, $r['ms']);
    return 'ok';
}
