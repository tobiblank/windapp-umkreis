<?php
/**
 * Windapp Umkreis - Sammler
 * =========================
 *
 * Holt fuer alle Regionen aus daten/regionen.json die Vorhersage bei
 * Open-Meteo und haengt sie als NDJSON an. Gleiches Prinzip wie der
 * Sammler von stegen-wind: abrufen, anhaengen, nichts verlieren.
 *
 * Pro Region EIN Vorhersagepunkt, nicht pro Spot. Spots werden erst bei
 * der Bewertung unterschieden (eigene Richtungssektoren, siehe bewertung.php).
 *
 * ---------------------------------------------------------------
 * EINRICHTUNG
 * ---------------------------------------------------------------
 * 1. $WEB_DIR auf den Webordner der App setzen (dort liegt config.php).
 * 2. Datei per FTP hochladen, AUSSERHALB des Web-Roots.
 * 3. Cronjob alle 10 Minuten:
 *       /usr/bin/php /usr/home/DEINUSER/sammler.php
 * 4. Test von der Kommandozeile:  php sammler.php --debug
 *
 * ALS GITHUB ACTION (seit 7.11.2026, .github/workflows/sammeln.yml):
 *       php server/sammler.php --action daten/regionen.json ausgabe/
 *    Ohne config.php, ohne Takt: ruft jede Quelle genau einmal ab und schreibt
 *    je Quelle eine gezippte Datei fuer diesen Lauf, z. B.
 *       ausgabe/vorhersage/2026-11-07_0525.ndjson.gz
 *    Die Action laedt sie per FTPS in den Datenordner. Exit 1 bei jedem Fehler,
 *    dann mailt GitHub; erfolgreiche Teile werden trotzdem hochgeladen.
 *
 * SCHNEE-MODUS (seit 8.11.2026): mit --schnee <schneeregionen.json> (Action) bzw.
 * 'schneeregionen_datei' in config.php (Cron) zusaetzlich die Quellen
 * schnee_vorhersage, schnee_ensemble und lawine. --nur lawine,schnee_vorhersage
 * ruft nur diese Quellen ab (Morgenlauf fuer den Lagebericht).
 *
 * VERHALTEN: bei Erfolg still, nur im Fehlerfall Ausgabe (Cron-Mail).
 *
 * AUSGABE: eine Datei pro Quelle und Tag, aeltere Tage gezippt:
 *   $data_dir/vorhersage/2026-11-07.ndjson
 *   $data_dir/ensemble/2026-11-07.ndjson
 *   $data_dir/welle/2026-11-07.ndjson
 * Jede Zeile ein Abruf fuer bis zu $TEIL_GROESSE Regionen desselben Typs (see/meer):
 *   {"ts":..., "source":..., "ok":..., "http":..., "ms":...,
 *    "regionen":["ammersee","adria-nord"], "payload":[{...}, {...}]}
 * payload ist immer eine Liste, in derselben Reihenfolge wie "regionen".
 * Unveraenderte Antworten (gleiche Stundenwerte wie beim letzten Abruf)
 * werden nicht noch einmal geschrieben, nur als "unveraendert" vermerkt.
 */

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// ===================================================================
// KONFIGURATION
// ===================================================================

// !!! ANPASSEN !!! Webordner der App (mit config.php und lib/).
$WEB_DIR = __DIR__ . '/web';

$TIMEOUT      = 20;   // Sekunden pro Request (viele Regionen = groessere Antwort)
$TEIL_GROESSE = 25;   // Regionen pro Open-Meteo-Abruf (URL-Laenge, Antwortgroesse)
$DEBUG        = in_array('--debug', $argv ?? [], true) || isset($_GET['debug']);

// Modus GitHub Action: --action <regionen.json> <ausgabe-ordner>
$ACTION = false;
$i = array_search('--action', $argv ?? [], true);
if ($i !== false) {
    if (!isset($argv[$i + 2])) {
        echo "Aufruf: php sammler.php --action <regionen.json> <ausgabe-ordner>\n";
        exit(1);
    }
    $ACTION = true;
    $WEB_DIR = __DIR__ . '/web';
    $CFG = ['regionen_datei' => $argv[$i + 1], 'data_dir' => $argv[$i + 2]];
}
$LAUF = $ACTION ? gmdate('Y-m-d_Hi') : null;
// Schnee-Katalog (seit 8.11.2026): --schnee <datei>; nur bestimmte Quellen: --nur a,b
$j = array_search('--schnee', $argv ?? [], true);
$SCHNEE_DATEI = $j !== false ? ($argv[$j + 1] ?? null) : null;
$j = array_search('--nur', $argv ?? [], true);
$NUR = $j !== false && isset($argv[$j + 1]) ? array_filter(explode(',', $argv[$j + 1])) : null;

require $WEB_DIR . '/lib/regionen.php';
require $WEB_DIR . '/lib/schnee.php';
require $WEB_DIR . '/lib/lawine.php';

if (!$ACTION) {
    $CFG = @include $WEB_DIR . '/config.php';
    if (!is_array($CFG)) {
        echo "config.php fehlt in $WEB_DIR (Vorlage: config.example.php)\n";
        exit(1);
    }
}
$DATA_DIR = rtrim($CFG['data_dir'], '/');
$MODELLE  = $CFG['modelle'] ?? 'icon_d2,icon_eu,ecmwf_ifs,gfs_seamless,meteofrance_arome_france_hd';
$OM_LIMIT = (int)($CFG['openmeteo_limit'] ?? 10000);
$OM_WARNUNG_AB = 0.7;

$SCHNEE_DATEI ??= $CFG['schneeregionen_datei'] ?? null;
$SCHNEE_MODELLE = $CFG['schnee_modelle'] ?? 'icon_d2,icon_eu,ecmwf_ifs,gfs_seamless';
try {
    $KATALOG = katalog_laden($CFG['regionen_datei'] ?? "$WEB_DIR/lib/regionen.json");
    $SCHNEE = $SCHNEE_DATEI ? schnee_katalog_laden($SCHNEE_DATEI) : null;
} catch (RuntimeException $e) {
    echo $e->getMessage(), "\n";
    exit(1);
}

// ===================================================================
// QUELLEN
// ===================================================================

/**
 * Jede Quelle: wie oft (Minuten), fuer welche Regionen, welche URL-Parameter.
 * Koordinaten werden kommagetrennt angehaengt (mehrere Orte in einem Abruf).
 *
 * vorhersage: stuendlich, 7 Tage. Die Modelle rechnen alle 3-6 h neu,
 *             stuendlich reicht, damit neue Laeufe bald da sind.
 * ensemble:   ECMWF IFS 0,25 Grad, 51 Laeufe, 8 Tage, alle 6 h
 *             (Modellname wie in stegen-wind, dort am 17.10.2026 geprueft).
 * welle:      nur Regionen mit typ "meer", alle 3 h.
 *             Variablennamen der Marine API hier NICHT live geprueft -
 *             beim ersten Lauf mit --debug kontrollieren.
 * wasser:     Meerestemperatur (sea_surface_temperature, Marine API), nur "meer",
 *             alle 6 h (seit 7.11.2026). Eigene Quelle, damit ein Fehler dort die
 *             Wellendaten nicht mitreisst; Variablenname ungeprueft.
 * seewasser:  Wassertemperatur der Seen mit Katalogfeld "alplakes" (seit 8.11.2026),
 *             nicht von Open-Meteo, sondern vom Seemodell Simstrat bei Alplakes
 *             (Eawag, CC BY 4.0): ein Abruf je See, 1 m Tiefe, Seemitte. Alplakes
 *             rechnet jeden Morgen, rund 4-5 Tage voraus, alle 3 Stunden; der Sammler
 *             fuellt die Stunden dazwischen linear (alplakes_eintrag()). Am 8.11.2026
 *             im Browser geprueft: ein Zeitraum ueber das Vorhersageende hinaus liefert,
 *             was da ist; ein unbekannter See gibt HTTP 400.
 */
function quellen(array $katalog, string $modelle, ?array $schnee = null, string $schneeModelle = ''): array
{
    return quellen_wind($katalog, $modelle) + ($schnee ? quellen_schnee($schnee, $schneeModelle) : []);
}

/**
 * Schnee-Modus (seit 8.11.2026). Vorhersage auf Tourhoehe (elevation je Ort, siehe
 * koordinaten()), 3 Tage zurueck fuer den Neuschnee der letzten 72 h. Wind in km/h.
 * UNGEPRUEFT: welche Modelle snowfall_height liefern (sonst null, das Mittel nimmt
 * die uebrigen), ob das Ensemble past_days, snow_depth und freezing_level_height kennt.
 * lawine: Lageberichte der freigegebenen Warndienste, heute und morgen (lawine.php).
 */
function quellen_schnee(array $schnee, string $modelle): array
{
    $vars = 'precipitation,snowfall,snowfall_height,freezing_level_height,temperature_2m,cloud_cover,'
          . 'snow_depth,wind_speed_700hPa,wind_gusts_10m,weather_code';
    $dienste = array_filter($schnee['lawinendienste'], fn($d) => !empty($d['freigegeben']));
    return [
        'schnee_vorhersage' => [
            'every'    => 60,
            'regionen' => $schnee['regionen'],
            'basis'    => 'https://api.open-meteo.com/v1/forecast',
            'query'    => "hourly=$vars&models=$modelle&wind_speed_unit=kmh&past_days=3&forecast_days=8&timezone=UTC",
        ],
        'schnee_ensemble' => [
            'every'    => 360,
            'regionen' => $schnee['regionen'],
            'basis'    => 'https://ensemble-api.open-meteo.com/v1/ensemble',
            'query'    => 'hourly=precipitation,snowfall,freezing_level_height,temperature_2m,cloud_cover,snow_depth'
                        . '&models=ecmwf_ifs025&past_days=3&forecast_days=8&timezone=UTC',
        ],
        'lawine' => [
            'every'    => 360,
            'regionen' => array_map(fn($id) => ['id' => $id, 'typ' => 'lawine'], array_keys($dienste)),
            'art'      => 'lawine',
            'dienste'  => $dienste,
        ],
    ];
}

function quellen_wind(array $katalog, string $modelle): array
{
    $alle = $katalog['regionen'];
    $meer = array_values(array_filter($alle, fn($r) => $r['typ'] === 'meer'));
    // Druckpunkte als eigene "Orte" (typ druck): nur Luftdruck
    $druck = [];
    foreach ($katalog['druckpunkte'] as $id => $p) {
        $druck[] = ['id' => $id, 'typ' => 'druck', 'punkt' => $p['punkt']];
    }
    // Hoehenwind nur fuer Regionen, deren Regel-Windlagen ihn brauchen
    $hoehe = array_values(array_filter($alle, function ($r) {
        $ws = $r['windlagen'] ?? [];
        foreach ($r['spots'] ?? [] as $sp) {
            $ws = array_merge($ws, $sp['windlagen'] ?? []);
        }
        foreach ($ws as $w) {
            if (isset($w['grundstroemung']['hoehe']) && $w['grundstroemung']['hoehe'] !== '10m') {
                return true;
            }
        }
        return false;
    }));
    $hoeheVars = 'wind_speed_850hPa,wind_direction_850hPa,wind_speed_700hPa,wind_direction_700hPa';

    return [
        'vorhersage' => [
            'every'    => 60,
            'regionen' => $alle,
            'basis'    => 'https://api.open-meteo.com/v1/forecast',
            'query'    => 'hourly=wind_speed_10m,wind_direction_10m,wind_gusts_10m,'
                        . 'temperature_2m,weather_code,precipitation,cloud_cover'
                        . "&models=$modelle&wind_speed_unit=kn&forecast_days=8&timezone=UTC",
        ],
        'ensemble' => [
            'every'    => 360,
            'regionen' => $alle,
            'basis'    => 'https://ensemble-api.open-meteo.com/v1/ensemble',
            'query'    => 'hourly=wind_speed_10m,wind_direction_10m,cloud_cover'
                        . '&models=ecmwf_ifs025&wind_speed_unit=kn&forecast_days=8&timezone=UTC',
        ],
        // Seit 7.11.2026, fuer Regel-Windlagen: eigene Abrufe, damit ein Fehler
        // (etwa ein Modell ohne Druckniveaus) die Vorhersage nicht mitreisst.
        'druck' => [
            'every'    => 60,
            'regionen' => $druck,
            'basis'    => 'https://api.open-meteo.com/v1/forecast',
            'query'    => "hourly=pressure_msl&models=$modelle&forecast_days=8&timezone=UTC",
        ],
        'druck_ensemble' => [
            'every'    => 360,
            'regionen' => $druck,
            'basis'    => 'https://ensemble-api.open-meteo.com/v1/ensemble',
            'query'    => 'hourly=pressure_msl&models=ecmwf_ifs025&forecast_days=8&timezone=UTC',
        ],
        'hoehe' => [
            'every'    => 60,
            'regionen' => $hoehe,
            'basis'    => 'https://api.open-meteo.com/v1/forecast',
            'query'    => "hourly=$hoeheVars&models=$modelle&wind_speed_unit=kn&forecast_days=8&timezone=UTC",
        ],
        'hoehe_ensemble' => [
            'every'    => 360,
            'regionen' => $hoehe,
            'basis'    => 'https://ensemble-api.open-meteo.com/v1/ensemble',
            'query'    => "hourly=$hoeheVars&models=ecmwf_ifs025&wind_speed_unit=kn&forecast_days=8&timezone=UTC",
        ],
        'welle' => [
            'every'    => 180,
            'regionen' => $meer,
            'basis'    => 'https://marine-api.open-meteo.com/v1/marine',
            'query'    => 'hourly=wave_height,wave_direction,wave_period,'
                        . 'swell_wave_height,swell_wave_direction,swell_wave_period'
                        . '&forecast_days=8&timezone=UTC',
        ],
        'wasser' => [
            'every'    => 360,
            'regionen' => $meer,
            'basis'    => 'https://marine-api.open-meteo.com/v1/marine',
            'query'    => 'hourly=sea_surface_temperature&forecast_days=8&timezone=UTC',
        ],
        'seewasser' => [
            'every'    => 360,
            'regionen' => array_values(array_filter($alle, fn($r) => $r['typ'] === 'see' && !empty($r['alplakes']))),
            'art'      => 'alplakes',
            'basis'    => ALPLAKES_BASIS,
        ],
    ];
}

// ===================================================================
// ALPLAKES (Wassertemperatur der Seen, seit 8.11.2026)
// ===================================================================

const ALPLAKES_BASIS = 'https://alplakes-api.eawag.ch/simulations/1d/point/simstrat';
const ALPLAKES_TIEFE = 1;   // m; die Simstrat-Modelle beginnen bei 1 m

/** URL fuer einen See: von gestern 0 Uhr bis in 8 Tagen (UTC), Alplakes liefert, was da ist */
function alplakes_url(string $basis, string $see, ?int $jetzt = null): string
{
    $jetzt ??= time();
    $von = gmdate('Ymd', $jetzt - 86400) . '0000';
    $bis = gmdate('Ymd', $jetzt + 8 * 86400) . '0000';
    return "$basis/" . rawurlencode($see) . "/$von/$bis/" . ALPLAKES_TIEFE;
}

/**
 * Antwort von Alplakes ({time: [ISO], variables: {T: {data: [...]}}}) als Eintrag
 * in der Form von Open-Meteo, damit daten_laden() und die Bewertung ihn wie die
 * Meerestemperatur lesen: hourly.time ("Y-m-d\TH:i", UTC) und
 * hourly.water_temperature. Die Zeitpunkte (alle 3 Stunden, ein paar Sekunden nach
 * der vollen Stunde) werden auf die volle Stunde gerundet, die Stunden dazwischen
 * linear gefuellt. Erstes Feld latitude, wie bei Open-Meteo (payload_teile()).
 * Rueckgabe null, wenn die Antwort nicht passt.
 */
function alplakes_eintrag(string $body, array $region): ?array
{
    $j = json_decode($body, true);
    $zeiten = $j['time'] ?? null;
    $werte = $j['variables']['T']['data'] ?? null;
    if (!is_array($zeiten) || !is_array($werte) || count($zeiten) !== count($werte) || !$zeiten) {
        return null;
    }
    $punkte = [];
    foreach ($zeiten as $i => $t) {
        $ts = is_string($t) ? strtotime($t) : false;
        if ($ts === false || !is_numeric($werte[$i])) {
            continue;
        }
        $punkte[(int)(round($ts / 3600) * 3600)] = (float)$werte[$i];
    }
    if (!$punkte) {
        return null;
    }
    ksort($punkte);
    $ts = array_keys($punkte);
    $time = $temp = [];
    for ($k = 0; $k < count($ts); $k++) {
        $a = $ts[$k];
        $b = $ts[$k + 1] ?? $a;
        for ($s = $a; $s < $b || $s === $a; $s += 3600) {
            $f = $b > $a ? ($s - $a) / ($b - $a) : 0.0;
            $time[] = gmdate('Y-m-d\TH:i', $s);
            $temp[] = round($punkte[$a] + $f * ($punkte[$b] - $punkte[$a]), 2);
            if ($b === $a) {
                break;
            }
        }
    }
    return [
        'latitude'  => (float)$region['punkt'][0],
        'longitude' => (float)$region['punkt'][1],
        'quelle'    => 'alplakes',
        'modell'    => 'simstrat',
        'see'       => $region['alplakes'],
        'tiefe_m'   => ALPLAKES_TIEFE,
        'hourly'    => ['time' => $time, 'water_temperature' => $temp],
    ];
}

/**
 * Abrufe einer Quelle: getrennt nach Typ, weil Meer-Regionen eine Wasser-Zelle
 * brauchen. Open-Meteo waehlt sonst die Gitterzelle bevorzugt an Land
 * (cell_selection, Vorgabe "land"). Gesehen am 7.11.2026: Fuer die Adria
 * (angefragt 45,5 N) lieferte das ECMWF-Ensemble 45,75 N mit 0 m Hoehe an der
 * friaulischen Kueste, rund 8 kn, die feineren Modelle rechneten 17 kn.
 * Seen bleiben vorerst beim Standard: wie Open-Meteo "sea" im Binnenland
 * behandelt, ist nicht geprueft.
 * Rueckgabe Liste von [regionen, zusatz fuer die URL], je hoechstens $groesse Regionen.
 */
function teile(array $regionen, int $groesse): array
{
    $out = [];
    foreach (['see' => '', 'meer' => '&cell_selection=sea', 'druck' => '', 'schnee' => ''] as $typ => $zusatz) {
        $gruppe = array_values(array_filter($regionen, fn($r) => $r['typ'] === $typ));
        foreach (array_chunk($gruppe, $groesse) as $teil) {
            $out[] = [$teil, $zusatz];
        }
    }
    return $out;
}

// ===================================================================
// ABRUF
// ===================================================================

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
        CURLOPT_CONNECTTIMEOUT => 5,
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
    for ($versuch = 1; $versuch <= 2 && ($r['http'] === 429 || $r['http'] === 0 || $r['http'] >= 500); $versuch++) {
        $warte = $r['http'] === 429
            ? umkreis_wartezeit('UMKREIS_WARTE_429', 65)
            : umkreis_wartezeit('UMKREIS_WARTE_FEHLER', 15);
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
    return $ersatz ? preg_replace('#^https://(?:[a-z-]+\.open-meteo\.com|alplakes-api\.eawag\.ch|aws\.slf\.ch|static\.avalanche\.report|static\.lawinen-warnung\.eu)#', $ersatz, $url) : $url;
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

/** Koordinaten mehrerer Regionen als Open-Meteo-Parameter */
function koordinaten(array $regionen): string
{
    $lat = [];
    $lon = [];
    foreach ($regionen as $r) {
        $lat[] = round((float)$r['punkt'][0], 4);
        $lon[] = round((float)$r['punkt'][1], 4);
    }
    $q = 'latitude=' . implode(',', $lat) . '&longitude=' . implode(',', $lon);
    // Schnee-Regionen (seit 8.11.2026): Vorhersage auf Tourhoehe statt Gitter- oder Talhoehe
    // (Open-Meteo: "elevation used for statistical downscaling"); eine Hoehe je Ort
    $hoehen = array_column($regionen, 'hoehe_tour');
    if ($hoehen && count($hoehen) === count($regionen)) {
        $q .= '&elevation=' . implode(',', array_map('intval', $hoehen));
    }
    return $q;
}

/**
 * Open-Meteo antwortet bei einem Ort mit einem Objekt, bei mehreren mit
 * einer Liste. Immer Liste zurueckgeben, oder null bei unpassender Antwort.
 */
function antwort_liste(string $body, int $erwartet): ?array
{
    $j = json_decode($body, true);
    if (!is_array($j)) {
        return null;
    }
    $liste = array_is_list($j) ? $j : [$j];
    if (count($liste) !== $erwartet) {
        return null;
    }
    foreach ($liste as $e) {
        if (!is_array($e) || !isset($e['hourly']['time'])) {
            return null;
        }
    }
    return $liste;
}

// ===================================================================
// OPEN-METEO-ZAEHLUNG
// ===================================================================

/**
 * Gewichtung wie in stegen-wind (Variablen x Modelle / 10, Tage / 14),
 * zusaetzlich mal Anzahl Orte. Dass jeder Ort einzeln zaehlt, ist eine
 * vorsichtige Annahme, nicht geprueft. Ensemble-Laeufe zaehlen hier nicht
 * extra - auch das ist ungeprueft; die Zahl ist eine Schaetzung.
 */
function om_gewicht(string $query, int $orte): float
{
    $q = [];
    parse_str($query, $q);
    $vars = 0;
    foreach (['hourly', 'daily', 'current', 'minutely_15'] as $k) {
        if (!empty($q[$k])) {
            $vars += count(explode(',', $q[$k]));
        }
    }
    $modelle = !empty($q['models']) ? count(explode(',', $q['models'])) : 1;
    $tage = isset($q['forecast_days']) ? (int)$q['forecast_days'] : 7;
    return max(1.0, $vars * $modelle / 10) * max(1.0, $tage / 14) * max(1, $orte);
}

function om_zaehlen(string $dataDir, float $gewicht): float
{
    $f = "$dataDir/.state/openmeteo.json";
    @mkdir("$dataDir/.state", 0700, true);
    $fp = @fopen($f, 'c+');
    if (!$fp) {
        return 0.0;
    }
    flock($fp, LOCK_EX);
    $z = json_decode((string)stream_get_contents($fp), true);
    $z = is_array($z) ? $z : [];
    $tag = gmdate('Y-m-d');
    $z[$tag] = $z[$tag] ?? ['last' => 0.0, 'abrufe' => 0];
    $z[$tag]['last'] = round($z[$tag]['last'] + $gewicht, 2);
    $z[$tag]['abrufe']++;
    ksort($z);
    $z = array_slice($z, -14, null, true);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($z));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $z[$tag]['last'];
}

// ===================================================================
// SCHREIBEN
// ===================================================================

/** $lauf: im Action-Modus eigene Datei je Lauf (Y-m-d_Hi), sonst eine je Tag */
function anhaengen(string $dataDir, string $quelle, array $zeile, ?string $lauf = null): bool
{
    $dir = "$dataDir/$quelle";
    @mkdir($dir, 0700, true);
    $json = json_encode($zeile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $name = $lauf ?? gmdate('Y-m-d');
    return $json !== false
        && @file_put_contents("$dir/$name.ndjson", $json . "\n", FILE_APPEND | LOCK_EX) !== false;
}

/** Action-Modus: Dateien dieses Laufs zippen (gelesen werden sie gezippt) */
function lauf_zippen(string $dataDir, string $quelle, string $lauf): void
{
    $f = "$dataDir/$quelle/$lauf.ndjson";
    if (!is_file($f)) {
        return;
    }
    $gz = gzencode((string)file_get_contents($f), 9);
    if ($gz !== false && file_put_contents("$f.gz", $gz) !== false) {
        unlink($f);
    }
}

/**
 * Fingerabdruck nur der Stundenwerte: generationtime_ms u. Ae. aendern
 * sich bei jedem Abruf, die Werte nur bei einem neuen Modelllauf.
 */
function fingerabdruck(array $liste): string
{
    return md5(json_encode(array_map(fn($e) => $e['hourly'], $liste)));
}

/** Tagesdateien aelter als gestern zippen (gelesen werden nur heute und gestern) */
function alte_zippen(string $dataDir, string $quelle): void
{
    $grenze = gmdate('Y-m-d', time() - 86400);
    foreach (glob("$dataDir/$quelle/*.ndjson") ?: [] as $f) {
        if (basename($f, '.ndjson') >= $grenze) {
            continue;
        }
        $gz = gzencode((string)file_get_contents($f), 9);
        if ($gz !== false && file_put_contents("$f.gz.tmp", $gz) !== false && rename("$f.gz.tmp", "$f.gz")) {
            unlink($f);
        }
    }
}

// ===================================================================
// HAUPTLAUF
// ===================================================================

$probleme = [];
$bericht  = [];

foreach (quellen($KATALOG, $MODELLE, $SCHNEE, $SCHNEE_MODELLE) as $name => $q) {
    if (!$q['regionen'] || ($NUR !== null && !in_array($name, $NUR, true))) {
        continue;
    }

    // Erst als erledigt markieren, wenn alle Teile geklappt haben.
    // Sonst versucht es der naechste Zehn-Minuten-Lauf noch einmal.
    $stempelDatei = "$DATA_DIR/.state/$name.block";
    $block  = max(1, intdiv($q['every'], 60));
    $diesen = gmdate('Y-m-d_') . sprintf('%02d', intdiv((int)gmdate('G'), $block) * $block);
    if (!$DEBUG && !$ACTION && @file_get_contents($stempelDatei) === $diesen) {
        continue;
    }

    $alleOk = true;
    $ersatz = $CFG['test_url_ersatz'] ?? (getenv('UMKREIS_TEST_URL') ?: null);

    if (($q['art'] ?? '') === 'alplakes') {
        // Ein Abruf je See; eine Zeile mit allen Seen, die geklappt haben, und eine
        // Fehlerzeile mit den uebrigen. Zaehlt nicht zum Open-Meteo-Kontingent.
        $gut = [];
        $schlecht = [];
        $kb = 0;
        $ms = 0;
        foreach ($q['regionen'] as $reg) {
            $r = abruf(test_url(alplakes_url($q['basis'], $reg['alplakes']), $ersatz), $TIMEOUT, "$name/{$reg['id']}", $bericht);
            $e = ($r['http'] >= 200 && $r['http'] < 300) ? alplakes_eintrag($r['body'], $reg) : null;
            $ms += $r['ms'];
            if ($e === null) {
                $schlecht[$reg['id']] = "{$reg['alplakes']}: http={$r['http']} "
                    . ($r['err'] !== '' ? $r['err'] : substr(preg_replace('/\s+/', ' ', $r['body']), 0, 200));
                $bericht[] = "$name/{$reg['id']}: FEHLER " . $schlecht[$reg['id']];
                continue;
            }
            $gut[$reg['id']] = $e;
            $kb += $r['bytes'] / 1024;
        }
        $ts = gmdate('Y-m-d\TH:i:s\Z');
        if ($gut) {
            $fp = fingerabdruck(array_values($gut));
            $fpDatei = "$DATA_DIR/.state/$name.0.fp";
            if (!$ACTION && @file_get_contents($fpDatei) === $fp . '|' . implode(',', array_keys($gut))) {
                $bericht[] = "$name: unveraendert";
            } elseif (anhaengen($DATA_DIR, $name, ['ts' => $ts, 'source' => $name, 'ok' => true, 'http' => 200, 'ms' => $ms,
                                                    'regionen' => array_keys($gut), 'payload' => array_values($gut)], $LAUF)) {
                @file_put_contents($fpDatei, $fp . '|' . implode(',', array_keys($gut)));
                $bericht[] = sprintf('%s: ok, %d Seen, %d KB, %d ms', $name, count($gut), $kb, $ms);
            } else {
                $probleme[] = "$name: Schreiben in $DATA_DIR fehlgeschlagen";
                $alleOk = false;
            }
        }
        if ($schlecht) {
            $alleOk = false;
            anhaengen($DATA_DIR, $name, ['ts' => $ts, 'source' => $name, 'ok' => false, 'http' => 0, 'ms' => 0,
                                         'regionen' => array_keys($schlecht), 'err' => implode(' | ', $schlecht)], $LAUF);
        }
    }

    if (($q['art'] ?? '') === 'lawine') {
        foreach ($q['dienste'] as $id => $dienst) {
            [$zeile, $f] = lawine_abrufen($id, $dienst, $ersatz, $TIMEOUT, $bericht);
            if (!anhaengen($DATA_DIR, $name, $zeile, $LAUF)) {
                $probleme[] = "$name: Schreiben in $DATA_DIR fehlgeschlagen";
                $alleOk = false;
            }
            if ($f !== null) {
                $bericht[] = "$name/$id: FEHLER $f";
                $alleOk = false;
            }
        }
    }

    foreach (in_array($q['art'] ?? '', ['alplakes', 'lawine'], true) ? [] : teile($q['regionen'], $TEIL_GROESSE) as $teilNr => [$teil, $zusatz]) {
        $ids = array_column($teil, 'id');
        $url = test_url($q['basis'] . '?' . koordinaten($teil) . '&' . $q['query'] . $zusatz, $ersatz);
        // Open-Meteo zaehlt je Minute (ein Ort und ein Ensemble-Abruf wiegen mehrfach).
        // Am 8.11.2026 (52 Regionen) gingen ensemble/0 und /1 durch, /2 bekam HTTP 429.
        // Deshalb vor jedem weiteren Ensemble-Teil eine Minute Pause.
        // Seit 8.11.2026 auch vor dem Schnee-Ensemble, das nach dem Wind-Ensemble im selben Lauf kommt
        if (($teilNr > 0 && str_ends_with($name, 'ensemble')) || ($teilNr === 0 && $name === 'schnee_ensemble' && $NUR === null)) {
            $pause = umkreis_wartezeit('UMKREIS_PAUSE_ENSEMBLE', 61);
            $bericht[] = "$name/$teilNr: Pause $pause s (Minutenlimit)";
            sleep($pause);
        }
        $r = abruf($url, $TIMEOUT, "$name/$teilNr", $bericht);

        // Im Action-Modus nicht zaehlen: kein bleibender Zustand, Abrufe von GitHub-IPs
        $omHeute = $ACTION ? 0.0 : om_zaehlen($DATA_DIR, om_gewicht($q['query'], count($teil)));
        if (!$ACTION && $omHeute >= $OM_LIMIT * $OM_WARNUNG_AB) {
            $warnStempel = "$DATA_DIR/.state/openmeteo.warnung";
            if (@file_get_contents($warnStempel) !== gmdate('Y-m-d')) {
                $probleme[] = sprintf('Open-Meteo: heute geschaetzt %d von %d freien Aufrufen (%d %%)',
                                      $omHeute, $OM_LIMIT, round(100 * $omHeute / $OM_LIMIT));
                @file_put_contents($warnStempel, gmdate('Y-m-d'));
            }
        }

        $liste = ($r['http'] >= 200 && $r['http'] < 300) ? antwort_liste($r['body'], count($teil)) : null;
        $ok = $liste !== null;

        $zeile = [
            'ts'       => gmdate('Y-m-d\TH:i:s\Z'),
            'source'   => $name,
            'ok'       => $ok,
            'http'     => $r['http'],
            'ms'       => $r['ms'],
            'regionen' => $ids,
        ];

        if ($ok) {
            $fp = fingerabdruck($liste);
            $fpDatei = "$DATA_DIR/.state/$name.$teilNr.fp";
            if (!$ACTION && @file_get_contents($fpDatei) === $fp . '|' . implode(',', $ids)) {
                $bericht[] = "$name/$teilNr: unveraendert";
                continue;
            }
            $zeile['payload'] = $liste;
            if (!anhaengen($DATA_DIR, $name, $zeile, $LAUF)) {
                $probleme[] = "$name: Schreiben in $DATA_DIR fehlgeschlagen";
                $alleOk = false;
                continue;
            }
            @file_put_contents($fpDatei, $fp . '|' . implode(',', $ids));
            $bericht[] = sprintf('%s/%d: ok, %d Regionen, %d KB, %d ms', $name, $teilNr, count($ids), $r['bytes'] / 1024, $r['ms']);
        } else {
            $alleOk = false;
            $zeile['err'] = $r['err'] !== '' ? $r['err'] : substr(preg_replace('/\s+/', ' ', $r['body']), 0, 300);
            anhaengen($DATA_DIR, $name, $zeile, $LAUF);
            $bericht[] = "$name/$teilNr: FEHLER http={$r['http']} {$zeile['err']}";
        }
    }

    if ($ACTION) {
        // Ein Lauf, kein zweiter Versuch: jeder Fehler wird gemeldet
        if (!$alleOk) {
            $probleme[] = "$name: Abruf fehlgeschlagen (Einzelheiten in der Datei dieses Laufs)";
        }
        lauf_zippen($DATA_DIR, $name, $LAUF);
        continue;
    }
    if ($alleOk) {
        @mkdir("$DATA_DIR/.state", 0700, true);
        @file_put_contents($stempelDatei, $diesen);
    } else {
        // Einzelne Aussetzer sind bei Open-Meteo normal (503). Melden erst,
        // wenn der ganze Block nicht klappt - also beim letzten Versuch im Block.
        $minuteImBlock = ((int)gmdate('G') % $block) * 60 + (int)gmdate('i');
        if ($minuteImBlock >= $q['every'] - 10) {
            $probleme[] = "$name: im ganzen Block kein vollstaendiger Abruf";
        }
    }
    alte_zippen($DATA_DIR, $name);
}

if ($DEBUG) {
    echo implode("\n", $bericht), "\n";
}
if ($probleme) {
    echo "Windapp Umkreis Sammler:\n- ", implode("\n- ", $probleme), "\n";
    if ($ACTION) {
        exit(1);
    }
}
