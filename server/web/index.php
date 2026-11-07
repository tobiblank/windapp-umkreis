<?php
/**
 * Wohinfahrma - Rangliste der Regionen im Umkreis
 *
 * Parameter (alle optional, die Seite merkt sich die Wahl im Browser):
 *   sport   Schluessel aus SPORTARTEN (bewertung.php)
 *   km      Umkreis in km Luftlinie
 *   ort     "Breite,Laenge", auf 0,1 Grad gerundet (ca. 10 km) - genauer wird
 *           nichts angenommen, damit kein genauer Standort im Zugriffsprotokoll steht
 *   ansicht bald (heute bis uebermorgen) oder reise (Tag 3 bis 7)
 */

declare(strict_types=1);
date_default_timezone_set('UTC');
require __DIR__ . '/lib/regionen.php';
require __DIR__ . '/lib/bewertung.php';
require __DIR__ . '/lib/karte.php';
require __DIR__ . '/lib/fahrzeit.php';
require __DIR__ . '/lib/roadtrip.php';

const TZ_SEITE = 'Europe/Berlin';
const HEIM_STANDARD = [48.07, 11.15];      // Inning am Ammersee
const UMKREISE = [100, 200, 400, 700, 1000, 1500, 2500];
const WOCHENTAGE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function zahl(float $v, int $stellen = 0): string
{
    return number_format($v, $stellen, ',', '.');
}

function fehlerseite(string $text): never
{
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Wohinfahrma</title><link rel="icon" href="favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="stil.css"><main class="seite"><h1 class="marke">Wohin<span>fahrma</span></h1>'
       . '<p class="karte">' . h($text) . '</p></main>';
    exit;
}

// Abbruch (z. B. zu wenig Speicher) als lesbare Seite statt leerem HTTP 500;
// die Meldung steht weiter im Fehlerprotokoll des Servers.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true) || headers_sent()) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    $speicher = str_contains($e['message'], 'Allowed memory size');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Wohinfahrma</title><link rel="stylesheet" href="stil.css"><main class="seite"><h1 class="marke">Wohin<span>fahrma</span></h1>'
       . '<p class="karte">' . ($speicher ? 'Die Seite braucht gerade mehr Speicher, als der Server erlaubt (memory_limit ' . h((string)ini_get('memory_limit')) . ').'
                                          : 'Beim Rechnen der Rangliste ist ein Fehler aufgetreten.')
       . ' Bitte später noch einmal versuchen.</p></main>';
});

// Mehr Speicher erbitten, falls der Server weniger einstellt (wirkt nur, wo erlaubt)
$grenze = ini_get('memory_limit');
if ($grenze !== false && $grenze !== '-1' && (int)$grenze > 0 && stripos($grenze, 'M') !== false && (int)$grenze < 256) {
    @ini_set('memory_limit', '256M');
}

$cfg = @include __DIR__ . '/config.php';
if (!is_array($cfg) || empty($cfg['data_dir'])) {
    fehlerseite('Die App ist noch nicht eingerichtet (config.php fehlt).');
}
try {
    $regionen = regionen_laden($cfg['regionen_datei'] ?? __DIR__ . '/lib/regionen.json');
} catch (RuntimeException $e) {
    fehlerseite('Der Regionen-Katalog ist nicht lesbar.');
}

// --- Auswahl ----------------------------------------------------------
$sportId = isset($_GET['sport']) && isset(SPORTARTEN[$_GET['sport']]) ? (string)$_GET['sport'] : 'kite_twintip';
$sport = SPORTARTEN[$sportId];
$km = isset($_GET['km']) && in_array((int)$_GET['km'], UMKREISE, true) ? (int)$_GET['km'] : 1000;
$ansicht = in_array($_GET['ansicht'] ?? '', ['reise', 'woche', 'roadtrip'], true) ? $_GET['ansicht'] : 'bald';
$roadtrip = $ansicht === 'roadtrip';   // seit 7.11.2026: Reisevorschlaege ueber alle 7 Tage
$woche = $ansicht === 'woche';         // seit 7.11.2026: Matrix Regionen x 7 Tage
// Anreise (seit 7.11.2026): Auto = Umkreis um den Standort, Flug = alle Flugziele ohne Umkreis
$anreise = ($_GET['anreise'] ?? '') === 'flug' ? 'flug' : 'auto';
$flug = $anreise === 'flug';

$heim = HEIM_STANDARD;
$eigenerOrt = false;
if (isset($_GET['ort']) && preg_match('/^(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)$/', (string)$_GET['ort'], $m)) {
    $lat = round((float)$m[1], 1);
    $lon = round((float)$m[2], 1);
    if (abs($lat) <= 90 && abs($lon) <= 180) {
        $heim = [$lat, $lon];
        $eigenerOrt = true;
    }
}

$tzSeite = new DateTimeZone(TZ_SEITE);
$heute = new DateTimeImmutable('today', $tzSeite);

// Geteilte Vorhersage (Knopf "Teilen"): ?sport=..&region=<id>&nach=<YYYY-MM-DD>
// zeigt nur diese Region, ohne Umkreis und Standort; der Zeitraum folgt aus dem Tag.
$geteilt = null;
$geteiltTag = null;      // geteilter Tag, noch im Vorhersagezeitraum
$geteiltVorbei = null;   // geteilter Tag, der schon vorbei ist
if (is_string($_GET['region'] ?? null)) {
    foreach ($regionen as $r) {
        if ($r['id'] === $_GET['region']) {
            $geteilt = $r;
            break;
        }
    }
}
if ($geteilt && is_string($_GET['nach'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['nach'])) {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $_GET['nach'], $tzSeite);
    if ($d && $d->format('Y-m-d') === $_GET['nach']) {
        $diff = (int)$heute->diff($d)->format('%r%a');
        if ($diff >= 0 && $diff <= 6) {
            $ansicht = $diff <= 2 ? 'bald' : 'reise';
            $geteiltTag = $_GET['nach'];
        } elseif ($diff < 0) {
            $geteiltVorbei = $_GET['nach'];
        }
    }
}

[$von, $bis] = match ($ansicht) { 'reise' => [3, 6], 'roadtrip', 'woche' => [0, 6], default => [0, 2] };
$tage = [];
for ($i = $von; $i <= $bis; $i++) {
    $tage[] = $heute->modify("+$i day")->format('Y-m-d');
}

function tag_kurz(string $tag, DateTimeImmutable $heute): string
{
    $d = new DateTimeImmutable($tag, $heute->getTimezone());
    $diff = (int)$heute->diff($d)->format('%r%a');
    return match ($diff) {
        0 => 'Heute',
        1 => 'Morgen',
        default => WOCHENTAGE[(int)$d->format('w')] . ' ' . $d->format('j.'),
    };
}

/** Tag mit Datum, fuer geteilte Texte (dort waere "Morgen" beim Empfaenger falsch) */
function tag_datum(string $tag, DateTimeZone $tz): string
{
    $d = new DateTimeImmutable($tag, $tz);
    return WOCHENTAGE[(int)$d->format('w')] . ' ' . $d->format('j.n.');
}

// --- Rechnen ----------------------------------------------------------
$jetzt = new DateTimeImmutable('now');
$dataDir = rtrim($cfg['data_dir'], '/');
// Nur die Regionen im Umkreis entpacken (Speicher)
$zurAnreise = array_values(array_filter($regionen, fn($r) => in_array($anreise, region_anreise($r), true)));
$imUmkreis = $geteilt
    ? [$geteilt['id']]
    : array_column(array_filter($zurAnreise, fn($r) => $flug || entfernung_km($heim, $r['punkt']) <= $km), 'id');
$stand = daten_laden($dataDir, nur: $imUmkreis);
$liste = $geteilt
    ? rangliste([$geteilt], $stand, $sport, $heim, INF, $tage, $jetzt)
    : rangliste($zurAnreise, $stand, $sport, $heim, $flug ? INF : (float)$km, $tage, $jetzt);
// Speicher: die Rohdaten braucht ab hier nichts mehr (der Verlauf liest die Dateien
// selbst, Stand und Fingerabdruecke stehen in _ts und _fp)
foreach (array_keys($stand) as $q) {
    if ($q[0] !== '_') {
        $stand[$q] = [];
    }
}
// Verlauf: dieselbe Chance aus frueheren Abrufen (mit Zwischenspeicher, Standard <data_dir>/cache)
$cacheDir = array_key_exists('cache_dir', $cfg) ? ($cfg['cache_dir'] === null ? null : rtrim((string)$cfg['cache_dir'], '/')) : "$dataDir/cache";
$verlauf = $roadtrip || $woche ? [] : verlauf_mit_cache($geteilt ? [$geteilt] : $regionen, $dataDir, $cacheDir, $stand, $sport, $tage, $jetzt,
                                              $cfg['regionen_datei'] ?? __DIR__ . '/lib/regionen.json');
// Sortierung: alle Tage (summe), ein angezeigter Tag oder Entfernung
$nach = (string)($_GET['nach'] ?? 'summe');
if ($nach !== 'km' && !in_array($nach, $tage, true)) {
    $nach = 'summe';
}
// Fahrzeit (seit 7.11.2026): genau fuer den Startort von fahrzeiten.json, sonst aus der Luftlinie geschaetzt
$fahrzeiten = fahrzeiten_laden($dataDir);
$fahrtModell = fahrt_modell($fahrzeiten, $regionen);
foreach ($liste as $i => $e) {
    $fh = $e['region']['flughafen'] ?? null;
    $liste[$i]['fahrt'] = $flug || !in_array('auto', region_anreise($e['region']), true)
        // Flugziel: "Fahrzeit" ist der Transfer vom Flughafen, sortiert wird danach
        ? ['min' => $fh['transfer_min'] ?? null, 'km' => null, 'geschaetzt' => false, 'kein_landweg' => false, 'flug' => $fh['name'] ?? 'Flugziel']
        : fahrzeit($e['region'], $heim, (float)$e['km'], $fahrzeiten, $fahrtModell);
}
$liste = rangliste_sortieren($liste, $nach);
$jetztSeite = $jetzt->setTimezone($tzSeite);
$trips = $roadtrip && !$geteilt && !$flug
    ? roadtrip_vorschlaege($liste, $tage, $fahrtModell, (int)$jetztSeite->format('G') + (int)$jetztSeite->format('i') / 60)
    : [];

/**
 * Hinfahrt: Fahrt am selben Tag zum Wind: "los bis 7:40" (Fensterbeginn minus
 * Fahrt und 15 min Aufbau, nicht vor der fruehesten Abfahrt); sonst Abfahrt und
 * Ankunft, bei langen Strecken mit Uebernachtung.
 */
function trip_hin_text(array $trip, array $liste, array $tage, callable $tagName): string
{
    [$aT, $aH] = $trip['hin']['ankunft'];
    $erster = $trip['tage'][$aT] ?? null;
    if ($aT === $trip['start'] && $erster && $erster['art'] === 'wind') {
        $d = $liste[$erster['i']]['tage'][$tage[$aT]];
        $von = !empty($d['det']['geht']) ? (float)$d['det']['von'] : (float)($d['fenster'][0] ?? FENSTER_AB);
        $spaet = $von - $trip['hin']['min'] / 60 - ABFAHRT_AUFBAU_MIN / 60;
        return $spaet > $trip['hin']['los'] ? 'los bis ' . uhr(floor($spaet * 12) / 12) : 'los ' . uhr($trip['hin']['los']) . ', ab ca. ' . uhr($aH) . ' dort';
    }
    return 'los ' . uhr($trip['hin']['los']) . ($aT > $trip['start'] ? ', mit Übernachtung, Ankunft ' . $tagName($aT) . ' ca. ' : ', Ankunft ca. ') . uhr($aH);
}

/** Spaltenkopf der Matrix als HTML: Wochentag ueber dem Datum, heute markiert */
function matrix_kopf(string $tag, DateTimeImmutable $heute): string
{
    $d = new DateTimeImmutable($tag, $heute->getTimezone());
    $wt = $d->format('Y-m-d') === $heute->format('Y-m-d') ? 'heute' : WOCHENTAGE[(int)$d->format('w')];
    return '<span>' . h($wt) . '</span><span>' . $d->format('j.') . '</span>';
}

/** Stunden als Uhrzeit, auf 5 Minuten: 11.25 -> "11:15", ueber Mitternacht weiter gezaehlt */
function uhr(float $stunden): string
{
    $m = (int)(round($stunden * 12) * 5);
    return intdiv($m, 60) % 24 . ':' . sprintf('%02d', $m % 60);
}

$zeiten = [];
foreach ($stand['_ts'] as $proRegion) {
    foreach ($proRegion as $ts) {
        $zeiten[] = $ts;
    }
}
$standText = null;
if ($zeiten) {
    $neu = (new DateTimeImmutable(max($zeiten)))->setTimezone($tzSeite);
    $standText = ($neu->format('Y-m-d') === $heute->format('Y-m-d') ? 'heute' : WOCHENTAGE[(int)$neu->format('w')] . ' ' . $neu->format('j.n.'))
               . ' ' . $neu->format('H:i') . ' Uhr';
}

function stufe(?float $chance): string
{
    if ($chance === null) {
        return 'leer';
    }
    return $chance >= 0.7 ? 'go' : ($chance >= 0.4 ? 'maybe' : ($chance >= 0.15 ? 'no' : 'nix'));
}

function link_mit(array $aenderung): string
{
    global $sportId, $km, $ansicht, $heim, $eigenerOrt, $nach, $anreise;
    $p = ['sport' => $sportId, 'km' => $km, 'ansicht' => $ansicht];
    if ($anreise === 'flug') {
        $p['anreise'] = 'flug';
    }
    if ($nach !== 'summe') {
        $p['nach'] = $nach;
    }
    if ($eigenerOrt) {
        $p['ort'] = $heim[0] . ',' . $heim[1];
    }
    return '?' . http_build_query(array_merge($p, $aenderung));
}

$wind = $sport['min'] . (isset($sport['max']) ? '–' . $sport['max'] : '+') . ' kn';

/** Teilen nur, wenn die Vorhersage gut ist: Fenster im Modellmittel und Chance ab 40 % (oder kein Ensemble) */
function teilenswert(array $d): bool
{
    $c = $d['ens']['chance'] ?? null;
    return $d['det']['geht'] && empty($d['vorbei']) && ($c === null || $c >= 0.4);
}

function teilen_link(array $region, string $tag): string
{
    global $sportId;
    return '?' . http_build_query(['sport' => $sportId, 'region' => $region['id'], 'nach' => $tag]);
}

/** Stand zum Zeitpunkt des Teilens; der Link zeigt beim Empfaenger die dann aktuelle Vorhersage */
function teilen_text(array $region, string $tag, array $d, array $sport, DateTimeZone $tz): string
{
    $det = $d['det'];
    $teile = [$d['spot_name'] ?? null, $det['windlage'] ?? null, $det['von'] . '–' . $det['bis'] . ' Uhr'];
    if (!empty($det['regel'])) {
        $teile[] = 'typisch ' . zahl((float)$det['staerke'][0]) . '–' . zahl((float)$det['staerke'][1]) . ' kn';
    } else {
        $teile[] = 'Ø ' . zahl($det['mittel_kn']) . ' kn' . ($det['boe_max'] !== null ? ', Böen bis ' . zahl($det['boe_max']) . ' kn' : '');
    }
    $c = $d['ens']['chance'] ?? null;
    if ($c !== null) {
        $teile[] = zahl(100 * $c) . ' % Chance';
    }
    return $region['name'] . ', ' . tag_datum($tag, $tz) . ': ' . implode(' · ', array_filter($teile)) . ' (' . $sport['name'] . ')';
}

/** Verlauf eines Tages oder null (seit 7.11.2026) */
function trend_fuer(array $e, string $tag): ?array
{
    global $verlauf;
    $d = $e['tage'][$tag];
    if (!empty($d['vorbei'])) {
        return null;
    }
    return verlauf_trend($verlauf[$e['region']['id']][$tag] ?? [], $d['ens']['chance'] ?? null);
}

function prozente(array $werte): string
{
    return implode(' → ', array_map(fn($w) => zahl(100 * $w), $werte)) . ' %';
}

/** Kurz fuer die Detailzeile: "stabil seit 4 Abrufen", "fällt (80 → 40 %)" */
function trend_text(array $tr): string
{
    $n = count($tr['werte']);
    return match ($tr['art']) {
        'stabil'   => "stabil seit $n Abrufen",
        'steigt'   => 'steigt (' . prozente($tr['werte']) . ')',
        'faellt'   => 'fällt (' . prozente($tr['werte']) . ')',
        'schwankt' => 'schwankt (' . prozente($tr['werte']) . ')',
        default    => 'kaum verändert (' . prozente($tr['werte']) . ')',
    };
}

/** "≈ 5:15 h Fahrt", "kein Landweg"; Flugziel: "Flug: Lanzarote (ACE)" */
function fahrzeit_mit_wort(array $f): string
{
    if (isset($f['flug'])) {
        return 'Flug: ' . $f['flug'];
    }
    return fahrzeit_text($f) . ($f['min'] !== null ? ' Fahrt' : '');
}

/** "412 km Straße" (genau), "380 km Luftlinie"; Flugziel: "≈ 25 min Transfer" */
function km_text(array $e): string
{
    $f = $e['fahrt'];
    if (isset($f['flug'])) {
        return $f['min'] !== null ? '≈ ' . fahrzeit_text($f) . ' Transfer' : '';   // Transfer ist immer geschaetzt
    }
    return $f['km'] !== null ? zahl((float)$f['km']) . ' km Straße' : zahl((float)$e['km']) . ' km Luftlinie';
}

function fahrzeit_titel(array $e): string
{
    global $fahrzeiten;
    $f = $e['fahrt'];
    $luft = 'Luftlinie ' . zahl((float)$e['km']) . ' km';
    if (isset($f['flug'])) {
        return 'Flugziel. Flughafen und Transfer zum Spot geschätzt; Flugzeiten und Preise sind nicht berücksichtigt.';
    }
    if ($f['min'] === null) {
        return 'Vom Startort aus keine Route auf der Straße (Insel?). ' . $luft;
    }
    if ($f['geschaetzt']) {
        return 'Fahrzeit geschätzt aus der ' . $luft . ($fahrzeiten ? ' (genau nur ab ' . ($fahrzeiten['start']['name'] ?? 'Startort') . ')' : '');
    }
    return 'Fahrzeit mit dem Auto laut openrouteservice, ' . zahl((float)$f['km']) . ' km Straße, ohne Verkehr. ' . $luft;
}

/** "Abfahrt bis 7:15", "Anreise am Vortag", "jetzt los: ab 15:10 dort" oder null */
function abfahrt_text(array $e, string $tag, array $d): ?string
{
    global $tzSeite, $jetzt;
    $det = $d['det'];
    if (!$det['geht'] || $e['fahrt']['min'] === null || isset($e['fahrt']['flug'])) {
        return null;
    }
    $a = abfahrt($tag, (int)$det['von'], (int)$det['bis'], new DateTimeZone($e['region']['zeitzone']), $e['fahrt']['min'], $tzSeite, $jetzt);
    $ca = $e['fahrt']['geschaetzt'] ? 'ca. ' : '';
    return match ($a['art']) {
        'bis'      => 'Abfahrt bis ' . $ca . $a['zeit']->format('G:i'),
        'vortag'   => 'Anreise am Vortag',
        'jetzt'    => 'jetzt los: ab ' . $ca . $a['zeit']->format('G:i') . ' dort',
        default    => 'heute nicht mehr erreichbar',
    };
}

/** Fuer den Tooltip: frueherer Abruf mit Uhrzeit, dann jetzt */
function trend_titel(array $tr, DateTimeZone $tz, DateTimeImmutable $heute): string
{
    $teile = [];
    foreach ($tr['ts'] as $i => $ts) {
        $t = (new DateTimeImmutable($ts))->setTimezone($tz);
        $tagText = $t->format('Y-m-d') === $heute->format('Y-m-d') ? 'heute' : WOCHENTAGE[(int)$t->format('w')];
        $teile[] = $tagText . ' ' . $t->format('G:i') . ': ' . zahl(100 * $tr['werte'][$i]) . ' %';
    }
    return 'Frühere Abrufe: ' . implode(', ', $teile);
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Wohinfahrma</title>
<link rel="stylesheet" href="stil.css">
<link rel="manifest" href="manifest.php">
<link rel="icon" href="favicon.ico" sizes="32x32">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="apple-mobile-web-app-title" content="Fahrma">
<meta name="theme-color" content="#eef3f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0f1e24" media="(prefers-color-scheme: dark)">
</head>
<body>
<main class="seite">

<header class="kopf">
  <div class="logozeile">
    <svg class="logo" viewBox="0 0 100 100" width="40" height="40" aria-hidden="true"><path class="logo-marke" d="M50 7C31.2 7 17 21.6 17 40.5C17 64 50 93 50 93S83 64 83 40.5C83 21.6 68.8 7 50 7Z"/><path class="logo-fahne" d="M31 36H57a7 7 0 1 0 -7 -7M31 48H65a7 7 0 1 1 -7 7"/></svg>
    <div>
      <h1 class="marke">Wohin<span>fahrma</span></h1>
      <p class="leise klein">Wo ist Wind? Fahr ma.</p>
    </div>
  </div>
  <?php if ($standText): ?><p class="leise klein rechts">Stand<br><?= h($standText) ?></p><?php endif ?>
</header>

<div class="karte installieren" id="installieren" hidden>
  <p><b>Als App installieren?</b> <span class="leise">Dann startet Wohinfahrma vom Startbildschirm, ohne Browserleiste.</span></p>
  <p class="leise klein" id="installieren-ios" hidden>Auf dem iPhone: unten auf das Teilen-Symbol tippen, dann „Zum Home-Bildschirm“.</p>
  <div class="installieren-knoepfe">
    <button type="button" class="knopf-klein" id="installieren-ja" hidden>Installieren</button>
    <button type="button" class="textknopf" id="installieren-nein">Nicht jetzt</button>
  </div>
</div>

<?php if ($geteilt): ?>
<p class="karte geteilt">
  Geteilte Vorhersage<?= $geteiltTag ? ' für ' . h(tag_datum($geteiltTag, $tzSeite)) : '' ?>, <?= h($sport['name']) ?>, aktueller Stand.
  <?php if ($geteiltVorbei): ?>Der geteilte Tag (<?= h(tag_datum($geteiltVorbei, $tzSeite)) ?>) ist vorbei.<?php endif ?>
  <a href="./">Zur Rangliste</a>
</p>
<?php else: ?>
<form class="wahl karte" method="get" id="wahl">
  <label>Sportart
    <select name="sport">
      <?php foreach (SPORTARTEN as $id => $s): ?>
        <option value="<?= h($id) ?>"<?= $id === $sportId ? ' selected' : '' ?>><?= h($s['name']) ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <label>Anreise
    <select name="anreise">
      <option value="auto"<?= $flug ? '' : ' selected' ?>>Auto</option>
      <option value="flug"<?= $flug ? ' selected' : '' ?>>Flug</option>
    </select>
  </label>
  <?php if ($flug): ?><input type="hidden" name="km" value="<?= $km ?>"><?php else: ?>
  <label>Umkreis
    <select name="km">
      <?php foreach (UMKREISE as $u): ?>
        <option value="<?= $u ?>"<?= $u === $km ? ' selected' : '' ?>><?= zahl($u) ?> km</option>
      <?php endforeach ?>
    </select>
  </label>
  <?php endif ?>
  <input type="hidden" name="ansicht" value="<?= h($ansicht) ?>">
  <?php if ($eigenerOrt): ?><input type="hidden" name="ort" value="<?= h($heim[0] . ',' . $heim[1]) ?>"><?php endif ?>
  <?php if ($flug): ?>
  <p class="ort klein leise">Alle Flugziele, ohne Umkreis. Flughafen und Transfer geschätzt, Flugzeiten und Preise nicht berücksichtigt.</p>
  <?php else: ?>
  <p class="ort klein leise">
    Von <?= $eigenerOrt ? 'deinem Standort (auf etwa 10 km gerundet)' : 'Inning am Ammersee' ?> ·
    <button type="button" class="textknopf" id="standort">Meinen Standort nehmen</button>
    <?php if ($eigenerOrt): ?> · <a href="<?= h(link_mit(['ort' => null])) ?>">Inning</a><?php endif ?>
  </p>
  <?php endif ?>
  <noscript><button class="knopf">Anzeigen</button></noscript>
</form>

<nav class="reiter" aria-label="Zeitraum">
  <a href="<?= h(link_mit(['ansicht' => 'bald', 'nach' => null])) ?>"<?= $ansicht === 'bald' ? ' aria-current="page"' : '' ?>>Heute bis übermorgen</a>
  <a href="<?= h(link_mit(['ansicht' => 'reise', 'nach' => null])) ?>"<?= $ansicht === 'reise' ? ' aria-current="page"' : '' ?>>Tag 3 bis 7</a>
  <a href="<?= h(link_mit(['ansicht' => 'woche', 'nach' => null])) ?>"<?= $woche ? ' aria-current="page"' : '' ?>>Woche</a>
  <a href="<?= h(link_mit(['ansicht' => 'roadtrip', 'nach' => null])) ?>"<?= $roadtrip ? ' aria-current="page"' : '' ?>>Roadtrip</a>
</nav>
<?php if (!$roadtrip): ?>

<nav class="sortierung klein" aria-label="Sortierung">
  <span class="leise">Sortiert nach</span>
  <a href="<?= h(link_mit(['nach' => null])) ?>"<?= $nach === 'summe' ? ' aria-current="true"' : '' ?>>alle Tage</a>
  <?php foreach ($woche ? [] : $tage as $t): ?>
    <a href="<?= h(link_mit(['nach' => $t])) ?>"<?= $nach === $t ? ' aria-current="true"' : '' ?>><?= h(tag_kurz($t, $heute)) ?></a>
  <?php endforeach ?>
  <?php if ($woche): ?><span class="leise">Tag: Spaltenkopf antippen</span><?php endif ?>
  <a href="<?= h(link_mit(['nach' => 'km'])) ?>"<?= $nach === 'km' ? ' aria-current="true"' : '' ?>><?= $flug ? 'Transfer' : 'Fahrzeit' ?></a>
</nav>
<?php endif ?>
<?php endif ?>

<p class="leise klein erklaerung">
  <?= h($sport['name']) ?>, <?= h($wind) ?>, mindestens <?= MIN_STUNDEN ?> Stunden am Stück zwischen 9 Uhr und Sonnenuntergang<?= $ansicht !== 'reise' ? ', heute erst ab einer halben Stunde nach jetzt' : '' ?>.
  Prozent = Anteil der 51 Läufe des ECMWF-Ensembles, an denen das klappt.
  <?php if ($roadtrip): ?>
  Roadtrip: vom Standort hin und zurück, mindestens <?= ROADTRIP_MIN_WINDTAGE ?> Windtage mit mindestens <?= zahl(100 * ROADTRIP_MIN_CHANCE) ?> % Chance; Etappen bis <?= intdiv(ROADTRIP_ETAPPE_MAX_MIN, 60) ?> h abends nach dem Fenster, Anreise ab <?= ROADTRIP_LOS_UHR ?> Uhr, höchstens <?= intdiv(ROADTRIP_FAHRT_PRO_TAG_MIN, 60) ?> h am Tag. Die App sucht die Länge, bei der Wind und Fahrt sich am meisten lohnen.
  <?php elseif ($woche): ?>
  Woche: leer = 0 %, „ja“ = Fenster nach Modellmittel ohne Ensemble.
  <?php else: ?>
  „↑ von 40“ = vor bis zu drei Abrufen (zweimal am Tag) stand dort 40 %.
  <?php endif ?>
</p>

<?php if ($liste && !$geteilt && !$roadtrip):
    // Landkarte (seit 7.11.2026): Punkte nach der Chance am gewaehlten Tag,
    // ohne gewaehlten Tag die beste Chance im Zeitraum. Tippen springt zur Region.
    $kartenTag = in_array($nach, $tage, true) ? $nach : null;
    $heimXY = karte_punkt($heim[0], $heim[1]);
    $punkte = [];
    foreach ($liste as $e) {
        $punkte[] = ['e' => $e, 'xy' => karte_punkt((float)$e['region']['punkt'][0], (float)$e['region']['punkt'][1]),
                     'wert' => karte_wert($e, $kartenTag)];
    }
    // Schlechte zuerst zeichnen, gute liegen oben
    usort($punkte, fn($a, $b) => ($a['wert']['chance'] ?? ($a['wert']['geht'] ? 1 : -1)) <=> ($b['wert']['chance'] ?? ($b['wert']['geht'] ? 1 : -1)));
    [$vx, $vy, $vw, $vh] = karte_ausschnitt(array_merge($flug ? [] : [$heimXY], array_column($punkte, 'xy')));
    $rp = $vw / 42;   // Punktgroesse folgt dem Ausschnitt, auf dem Handy rund 9 px
    $namen = karte_namen(array_map(fn($p) => ['xy' => $p['xy'], 'name' => $p['e']['region']['name'],
                                              'rang' => $p['wert']['chance'] ?? ($p['wert']['geht'] ? 1 : -1)], $punkte),
                         $rp, [$vx, $vy, $vw, $vh]);
?>
<details class="karte landkarte" id="landkarte" open>
  <summary><b>Karte</b> <span class="leise klein"><?= $kartenTag ? 'Chance ' . h(tag_kurz($kartenTag, $heute)) : 'beste Chance im Zeitraum' ?> · Tag über „Sortiert nach“ wählen</span></summary>
  <svg class="landkarte-bild" viewBox="<?= "$vx $vy $vw $vh" ?>" role="img" aria-label="Karte der Regionen im Umkreis">
    <rect class="meer" x="<?= $vx ?>" y="<?= $vy ?>" width="<?= $vw ?>" height="<?= $vh ?>"/>
    <use href="karte.svg#land" class="land"/>
    <?php if (!$flug): ?>
    <circle class="umkreis" cx="<?= round($heimXY[0], 1) ?>" cy="<?= round($heimXY[1], 1) ?>" r="<?= round(karte_km((float)$km, $heim[0]), 1) ?>"/>
    <circle class="heim" cx="<?= round($heimXY[0], 1) ?>" cy="<?= round($heimXY[1], 1) ?>" r="<?= round($rp * 0.45, 2) ?>"><title><?= $eigenerOrt ? 'Dein Standort' : 'Inning am Ammersee' ?></title></circle>
    <?php endif ?>
    <?php foreach ($punkte as $nr => $p):
        $r = $p['e']['region'];
        $c = $p['wert']['chance'];
        $klasse = $c === null ? ($p['wert']['geht'] ? 'go' : 'leer') : stufe($c);
        $zahl = $c !== null && $c >= 0.15;
        $titel = $r['name'] . ': ' . ($c === null ? ($p['wert']['geht'] ? 'Fenster nach Modellmittel' : 'keine Ensemble-Daten')
               : zahl(100 * $c) . ' %') . ($p['wert']['tag'] && !$kartenTag ? ' (' . tag_kurz($p['wert']['tag'], $heute) . ')' : '')
               . ' · ' . fahrzeit_mit_wort($p['e']['fahrt']);
    ?>
    <a href="#r-<?= h($r['id']) ?>" class="punkt <?= $klasse ?>">
      <title><?= h($titel) ?></title>
      <circle cx="<?= round($p['xy'][0], 1) ?>" cy="<?= round($p['xy'][1], 1) ?>" r="<?= round($zahl ? $rp : $rp * 0.55, 2) ?>"/>
      <?php if ($zahl): ?><text class="zahl" x="<?= round($p['xy'][0], 1) ?>" y="<?= round($p['xy'][1] + $rp * 0.36, 2) ?>" font-size="<?= round($rp * 1.05, 2) ?>"><?= zahl(100 * $c) ?></text><?php endif ?>
      <?php if (isset($namen[$nr])): $rechts = $namen[$nr] === 'rechts'; ?><text class="name<?= $rechts ? '' : ' links' ?>" x="<?= round($p['xy'][0] + ($rechts ? 1 : -1) * $rp * 1.3, 2) ?>" y="<?= round($p['xy'][1] + $rp * 0.35, 2) ?>" font-size="<?= round($rp, 2) ?>" stroke-width="<?= round($rp * 0.3, 2) ?>"><?= h($r['name']) ?></text><?php endif ?>
    </a>
    <?php endforeach ?>
  </svg>
  <p class="leise klein landkarte-fuss">Punkt antippen: zur Region.<?= $flug ? '' : ' Kreis: Umkreis von ' . zahl($km) . ' km Luftlinie, ungefähr.' ?> Karte: Natural Earth.</p>
</details>
<?php endif ?>

<?php if (!$zeiten): ?>
  <p class="karte">Noch keine Vorhersagedaten. Der Sammler läuft zweimal am Tag, gegen 6:30 und 18:30 Uhr.</p>
<?php elseif (!$liste && $geteilt): ?>
  <p class="karte">Für <?= h($geteilt['name']) ?> gibt es gerade keine Vorhersagedaten. <a href="./">Zur Rangliste</a></p>
<?php elseif (!$liste): ?>
  <?php if ($flug): ?>
  <p class="karte">Im Katalog ist noch kein Flugziel eingetragen.</p>
  <?php else: ?>
  <p class="karte">Keine Region im Umkreis von <?= zahl($km) ?> km. <a href="<?= h(link_mit(['km' => UMKREISE[count(UMKREISE) - 1]])) ?>">Umkreis vergrößern</a></p>
  <?php endif ?>
<?php endif ?>

<?php if ($roadtrip && $flug): ?>
  <p class="karte">Roadtrips gibt es nur mit Anreise Auto. <a href="<?= h(link_mit(['anreise' => null])) ?>">Mit dem Auto ansehen</a></p>
<?php elseif ($roadtrip && $liste && !$trips): ?>
  <p class="karte">Kein Roadtrip lohnt sich gerade: nirgends <?= ROADTRIP_MIN_WINDTAGE ?> Windtage mit mindestens <?= zahl(100 * ROADTRIP_MIN_CHANCE) ?> % Chance, die die Fahrt aufwiegen. <a href="<?= h(link_mit(['km' => UMKREISE[count(UMKREISE) - 1]])) ?>">Umkreis vergrößern</a></p>
<?php endif ?>
<?php foreach ($trips as $trip):
    $orte = [];
    foreach ($trip['tage'] as $x) {
        $orte[$x['i']] = $liste[$x['i']]['region']['name'];
    }
    $heimTag = $trip['rueck']['heim_tag'];
    $tageGesamt = $heimTag - $trip['start'] + 1;
    $tagName = fn(int $ti) => tag_kurz($heute->modify("+$ti day")->format('Y-m-d'), $heute);
?>
  <article class="karte trip">
    <div class="region-kopf">
      <h2><?= h(implode(' → ', array_values(array_unique($orte)))) ?></h2>
      <span class="leise klein fahrzeit"><?= $trip['windtage'] ?> Windtage<span class="km"><?= h(fahrzeit_text(['min' => $trip['fahrt_min'], 'geschaetzt' => true])) ?> Fahrt · <?= zahl(round($trip['km'] / 10) * 10) ?> km</span></span>
    </div>
    <p class="leise klein trip-zeitraum"><?= $tageGesamt ?> <?= $tageGesamt === 1 ? 'Tag' : 'Tage' ?> · <?= h($tagName($trip['start'])) ?> – <?= h($tagName($heimTag)) ?></p>
    <ol class="trip-tage klein">
      <li class="wechsel leise">↳ <?= h($tagName($trip['start'])) ?> Hinfahrt<?= $eigenerOrt ? '' : ' ab Inning' ?>, <?= h(fahrzeit_text($liste[$trip['hin']['i']]['fahrt'])) ?>, <?= h(trip_hin_text($trip, $liste, $tage, $tagName)) ?></li>
      <?php foreach ($trip['tage'] as $ti => $x):
          $te = $liste[$x['i']];
          $t = $tage[$ti];
          $d = $te['tage'][$t];
          $det = $d['det'];
          $c = $d['ens']['chance'] ?? null;
      ?>
        <?php if (isset($trip['etappen'][$ti])): $et = $trip['etappen'][$ti]; ?>
          <li class="wechsel leise">↳ abends weiter nach <?= h($te['region']['name']) ?>, <?= h(fahrzeit_text(['min' => $et['min'], 'geschaetzt' => true])) ?></li>
        <?php endif ?>
        <?php if ($x['art'] === 'wind'): ?>
        <li>
          <span class="trip-wert <?= $c === null ? 'go' : stufe($c) ?>"><?= $c === null ? 'ja' : zahl(100 * $c) ?></span>
          <span><b><?= h(tag_kurz($t, $heute)) ?></b> <a href="<?= h(link_mit(['ansicht' => $ti <= 2 ? 'bald' : 'reise', 'nach' => $t])) ?>#r-<?= h($te['region']['id']) ?>" class="trip-region"><?= h($te['region']['name']) ?></a><?php if ($det['geht']): ?> ·
            <?= h($det['windlage'] ?? 'jede Richtung') ?> · <?= $det['von'] ?>–<?= $det['bis'] ?> Uhr ·
            <?= !empty($det['regel']) ? 'typisch ' . zahl((float)$det['staerke'][0]) . '–' . zahl((float)$det['staerke'][1]) . ' kn' : 'Ø ' . zahl($det['mittel_kn']) . ' kn' ?>
            <?php else: ?> · <span class="leise">nur ein Teil der Ensemble-Läufe sieht ein Fenster</span><?php endif ?></span>
        </li>
        <?php else: ?>
        <li class="leise">
          <span class="trip-wert"><?= $x['art'] === 'fahrt' ? '–' : '·' ?></span>
          <span><b><?= h(tag_kurz($t, $heute)) ?></b> <?= $x['art'] === 'fahrt' ? 'Fahrtag' : 'Pause in ' . h($te['region']['name']) . ', kein Wind' ?></span>
        </li>
        <?php endif ?>
      <?php endforeach ?>
      <li class="wechsel leise">↳ <?= h($trip['rueck']['abends'] ? 'abends' : $tagName($trip['rueck']['tag'])) ?> Rückfahrt, <?= h(fahrzeit_text($liste[$trip['rueck']['i']]['fahrt'])) ?><?= h($heimTag > $trip['rueck']['tag'] ? ', mit Übernachtung' : '') ?>, zu Hause <?= h(($heimTag > array_key_last($trip['tage']) ? $tagName($heimTag) . ' ' : '') . 'ca. ' . uhr($trip['rueck']['ankunft'])) ?></li>
    </ol>
  </article>
<?php endforeach ?>

<?php if ($woche && $liste && !$geteilt): ?>
<div class="karte matrix-box">
  <table class="matrix">
    <thead>
      <tr>
        <th scope="col" class="m-name">Region</th>
        <?php foreach ($tage as $t): ?>
          <th scope="col"<?= $nach === $t ? ' class="m-aktiv"' : '' ?>><a href="<?= h(link_mit(['nach' => $nach === $t ? null : $t])) ?>"><?= matrix_kopf($t, $heute) ?></a></th>
        <?php endforeach ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($liste as $e): $r = $e['region']; ?>
        <tr>
          <th scope="row" class="m-name"><a href="<?= h(link_mit(['ansicht' => 'bald', 'nach' => null])) ?>#r-<?= h($r['id']) ?>"><?= h($r['name']) ?></a><span class="leise"><?= h(fahrzeit_mit_wort($e['fahrt'])) ?></span></th>
          <?php foreach ($tage as $ti => $t):
              $d = $e['tage'][$t] ?? null;
              $c = $d['ens']['chance'] ?? null;
              $geht = !empty($d['det']['geht']);
              $vorbei = !$d || !empty($d['vorbei']);
              $klasse = $vorbei ? 'leer' : ($c === null ? ($geht ? 'go' : 'leer') : stufe($c));
              // 0 % bleibt leer, damit die guten Tage herausstechen
              $text = $vorbei ? '' : ($c === null ? ($geht ? 'ja' : '') : ($c < 0.005 ? '' : zahl(100 * $c)));
              $titel = $r['name'] . ', ' . tag_kurz($t, $heute) . ': ' . ($vorbei ? 'vorbei'
                     : ($c === null ? ($geht ? 'Fenster nach Modellmittel' : 'kein Fenster') : zahl(100 * $c) . ' %')
                     . ($geht ? ', ' . $d['det']['von'] . '–' . $d['det']['bis'] . ' Uhr' . (!empty($d['det']['regel']) ? ', Regel' : ', Ø ' . zahl($d['det']['mittel_kn']) . ' kn') : ''));
          ?>
            <td class="<?= $klasse ?>" title="<?= h($titel) ?>"><a href="<?= h(link_mit(['ansicht' => $ti <= 2 ? 'bald' : 'reise', 'nach' => $t])) ?>#r-<?= h($r['id']) ?>"><?= h($text) ?></a></td>
          <?php endforeach ?>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
</div>
<?php endif ?>

<?php foreach ($roadtrip || $woche ? [] : $liste as $e):
    $r = $e['region'];
    $guteTage = array_filter($e['tage'], fn($t) => $t['det']['geht'] || (($t['ens']['chance'] ?? 0) >= 0.15));
?>
  <article class="karte region<?= $guteTage ? '' : ' still' ?>" id="r-<?= h($r['id']) ?>">
    <div class="region-kopf">
      <h2><?= h($r['name']) ?><?php if (!empty($r['thermik'])): ?> <span class="marker thermik" title="Thermik bilden die Modelle schlecht ab: die Zahl ist hier vermutlich zu niedrig">Thermik</span><?php endif ?></h2>
      <?php if (!$geteilt): ?><span class="leise klein fahrzeit" title="<?= h(fahrzeit_titel($e)) ?>"><?= h(fahrzeit_mit_wort($e['fahrt'])) ?><span class="km"><?= h(km_text($e)) ?></span></span><?php endif ?>
    </div>
    <div class="tage" style="--n: <?= count($tage) ?>">
      <?php foreach ($tage as $t):
          $d = $e['tage'][$t];
          $c = $d['ens']['chance'] ?? null;
          $tr = trend_fuer($e, $t);
          $pfeil = $tr && in_array($tr['art'], ['steigt', 'faellt'], true);
      ?>
        <?php if (!empty($d['vorbei'])): ?>
        <div class="tag leer" title="Für heute bleibt nicht mehr genug Zeit bis Sonnenuntergang">
          <span class="t"><?= h(tag_kurz($t, $heute)) ?></span>
          <span class="w vorbei">vorbei</span>
        </div>
        <?php else: ?>
        <div class="tag <?= stufe($c) ?><?= $nach === $t ? ' gewaehlt' : '' ?>" title="<?= h(($c === null ? 'keine Ensemble-Daten' : zahl(100 * $c) . ' % der Läufe')
                                                     . (!empty($d['rest']) ? ', nur noch ' . $d['fenster'][0] . '–' . $d['fenster'][1] . ' Uhr' : '')
                                                     . ($tr ? '. ' . trend_titel($tr, $tzSeite, $heute) : '')) ?>">
          <span class="t"><?= h(tag_kurz($t, $heute)) ?><?= !empty($d['rest']) && $d['fenster'][0] > FENSTER_AB ? ' ab ' . $d['fenster'][0] : '' ?></span>
          <span class="w"><?= $c === null ? ($d['det']['geht'] ? 'ja' : '–') : zahl(100 * $c) . '<small>%</small>' ?></span>
          <?php if ($pfeil): ?><span class="v <?= $tr['art'] ?>"><?= $tr['art'] === 'steigt' ? '↑' : '↓' ?> von <?= zahl(100 * $tr['werte'][0]) ?></span><?php endif ?>
        </div>
        <?php endif ?>
      <?php endforeach ?>
    </div>
    <?php if ($guteTage): ?>
      <ul class="details klein">
        <?php foreach ($guteTage as $t => $d):
            $det = $d['det'];
            $tr = trend_fuer($e, $t);
        ?>
          <li>
            <b><?= h(tag_kurz($t, $heute)) ?>:</b>
            <?php if ($det['geht']): ?>
              <?= h($d['spot_name'] ?? 'ganze Region') ?> ·
              <?= h($det['windlage'] ?? 'jede Richtung') ?> ·
              <?= $det['von'] ?>–<?= $det['bis'] ?> Uhr ·
              <?php if (!empty($det['regel'])): ?>
                typisch <?= zahl((float)$det['staerke'][0]) ?>–<?= zahl((float)$det['staerke'][1]) ?> kn
              <?php else: ?>
                Ø <?= zahl($det['mittel_kn']) ?> kn<?php if ($det['boe_max'] !== null): ?>, Böen bis <?= zahl($det['boe_max']) ?> kn<?php endif ?>
              <?php endif ?>
              <?php if ($det['charakter']): ?><span class="leise">(<?= h($det['charakter']) ?>)</span><?php endif ?>
              <?php if ($det['grob']): ?><span class="marker">grob</span><?php endif ?>
              <?php if (!empty($det['regel'])): ?><span class="marker regel" title="Aus Druckdifferenz und/oder Grundströmung abgeleitet, nicht aus dem Modellwind am See. Die Stärke ist ein geschätzter typischer Wert.">Regel</span><?php endif ?>
              <?php if ($tr): ?><span class="verlauf <?= $tr['art'] ?>" title="<?= h(trend_titel($tr, $tzSeite, $heute)) ?>">· <?= h(trend_text($tr)) ?></span><?php endif ?>
              <?php if (!$geteilt && ($ab = abfahrt_text($e, $t, $d))): ?><span class="abfahrt">· <?= h($ab) ?></span><?php endif ?>
              <?php if (teilenswert($d)): ?><button type="button" class="teilen" hidden
                data-titel="<?= h('Wohinfahrma: ' . $r['name'] . ', ' . tag_datum($t, $tzSeite)) ?>"
                data-text="<?= h(teilen_text($r, $t, $d, $sport, $tzSeite)) ?>"
                data-url="<?= h(teilen_link($r, $t)) ?>"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M12 3v12M7 8l5-5 5 5M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>Teilen</button><?php endif ?>
            <?php else: ?>
              <span class="leise">Modellmittel: <?= h($det['grund']) ?>, nur ein Teil der Ensemble-Läufe sieht ein Fenster</span>
              <?php if ($tr): ?><span class="verlauf <?= $tr['art'] ?>" title="<?= h(trend_titel($tr, $tzSeite, $heute)) ?>">· <?= h(trend_text($tr)) ?></span><?php endif ?>
            <?php endif ?>
          </li>
        <?php endforeach ?>
      </ul>
    <?php else: ?>
      <p class="klein leise">Kein passender Tag in diesem Zeitraum.</p>
    <?php endif ?>
    <?php if (!empty($r['links'])): ?>
      <p class="links klein">Genauer:
        <?php foreach ($r['links'] as $i => $l): ?><?= $i ? ' · ' : '' ?><a href="<?= h($l['url']) ?>" rel="noopener"><?= h($l['name']) ?> ↗</a><?php endforeach ?>
      </p>
    <?php endif ?>
  </article>
<?php endforeach ?>

<footer class="fuss klein leise">
  <p><b>Regel</b> heißt: Die Windlage ist aus Druckdifferenzen und Grundströmung abgeleitet (nach lokalen Vorhersageseiten wie kitewetter.at), die Stärke ist ein geschätzter typischer Wert.</p>
  <p><b>Rohwerte, nicht kalibriert.</b> Modelle unterschätzen lokale Thermik oft (Seen mit „Thermik“ markiert, dort lieber die verlinkte lokale Vorhersage ansehen); Böen und Gewitter prüft nur das Modellmittel. Umkreis als Luftlinie zum Vorhersagepunkt der Region; Fahrzeit mit dem Auto ohne Verkehr und Pausen, „≈“ heißt aus der Luftlinie geschätzt. Flugziele (Anreise „Flug“): ohne Umkreis, mit geschätztem Transfer vom Flughafen.</p>
  <?php if ($fahrzeiten): ?><p>Fahrzeiten ab <?= h($fahrzeiten['start']['name'] ?? 'Startort') ?>: © <a href="https://openrouteservice.org/">openrouteservice.org</a> by HeiGIT, Kartendaten © <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-Mitwirkende.</p><?php endif ?>
  <p>Vorhersagen: <a href="https://open-meteo.com/">Weather data by Open-Meteo.com</a> (CC BY 4.0) · Für Stegen genauer: <a href="https://ammerseewind.de">ammerseewind.de</a></p>
  <p><a href="impressum.html">Impressum</a> · <a href="datenschutz.html">Datenschutz</a></p>
</footer>
</main>

<script>
// Installierbare Web-App: Service Worker nur fuer die Offline-Seite (sw.js)
if ('serviceWorker' in navigator) {
  addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () {}); });
}
// Hinweis "Als App installieren" (seit 7.11.2026): Chrome/Edge/Android melden
// mit beforeinstallprompt, dass die Seite installierbar ist; dann eigener Knopf
// statt der Browser-Leiste. iPhone/iPad kennen das nicht: dort ein Text-Hinweis.
// Nicht in der installierten App, und nach "Nicht jetzt" 30 Tage Ruhe.
(function () {
  var box = document.getElementById('installieren');
  if (!box) { return; }
  var SCHLUESSEL = 'wohinfahrma_install';
  var installiert = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  function lesen() { try { return JSON.parse(localStorage.getItem(SCHLUESSEL) || 'null'); } catch (e) { return null; } }
  function merken(v) { try { localStorage.setItem(SCHLUESSEL, JSON.stringify(v)); } catch (e) {} }
  var stand = lesen();
  var ruhe = stand && (stand.installiert || (stand.nein && Date.now() - stand.nein < 30 * 864e5));
  if (installiert || ruhe) { return; }
  var ja = document.getElementById('installieren-ja');
  var angebot = null;
  addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    angebot = e;
    ja.hidden = false;
    box.hidden = false;
  });
  ja.addEventListener('click', function () {
    if (!angebot) { return; }
    angebot.prompt();
    angebot.userChoice.then(function (w) {
      if (w && w.outcome === 'accepted') { merken({ installiert: true }); }
      box.hidden = true;
      angebot = null;
    }, function () {});
  });
  addEventListener('appinstalled', function () { merken({ installiert: true }); box.hidden = true; });
  document.getElementById('installieren-nein').addEventListener('click', function () {
    merken({ nein: Date.now() });
    box.hidden = true;
  });
  // iPhone/iPad (auch iPad mit Desktop-Kennung): kein beforeinstallprompt
  var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  if (ios) {
    document.getElementById('installieren-ios').hidden = false;
    box.hidden = false;
  }
})();
// Teilen: Web Share (Handy) oder in die Zwischenablage; ohne beides bleibt der Knopf verborgen
(function () {
  var kannKopieren = !!(navigator.clipboard && window.isSecureContext);
  if (!navigator.share && !kannKopieren) { return; }
  document.querySelectorAll('.teilen').forEach(function (k) {
    k.hidden = false;
    k.addEventListener('click', function () {
      var url = new URL(k.dataset.url, location.href).href;
      function kopieren() {
        navigator.clipboard.writeText(k.dataset.text + '\n' + url).then(function () {
          var alt = k.lastChild.textContent;
          k.lastChild.textContent = 'Kopiert';
          setTimeout(function () { k.lastChild.textContent = alt; }, 2000);
        }, function () {});
      }
      if (navigator.share) {
        navigator.share({ title: k.dataset.titel, text: k.dataset.text, url: url }).catch(function (e) {
          if (e && e.name !== 'AbortError' && kannKopieren) { kopieren(); }
        });
      } else {
        kopieren();
      }
    });
  });
})();
// Landkarte auf- oder zugeklappt merken
(function () {
  var k = document.getElementById('landkarte');
  if (!k) { return; }
  var SCHLUESSEL = 'wohinfahrma_karte';
  try { if (localStorage.getItem(SCHLUESSEL) === 'zu') { k.open = false; } } catch (e) {}
  k.addEventListener('toggle', function () {
    try { localStorage.setItem(SCHLUESSEL, k.open ? 'auf' : 'zu'); } catch (e) {}
  });
})();
(function () {
  var form = document.getElementById('wahl');
  if (!form) { return; }   // geteilte Vorhersage: keine Auswahl, nichts merken
  var SCHLUESSEL = 'wohinfahrma_wahl';
  function lesen() { try { return JSON.parse(localStorage.getItem(SCHLUESSEL) || 'null'); } catch (e) { return null; } }
  function merken(p) { try { localStorage.setItem(SCHLUESSEL, JSON.stringify(p)); } catch (e) {} }

  // Ohne Parameter: letzte Wahl wiederherstellen
  if (!location.search) {
    var alt = lesen();
    if (alt && alt.sport) {
      location.replace('?' + new URLSearchParams(alt).toString());
      return;
    }
  }
  var jetzt = Object.fromEntries(new URLSearchParams(location.search));
  if (jetzt.sport) { merken(jetzt); }

  form.querySelectorAll('select').forEach(function (s) {
    s.addEventListener('change', function () { form.submit(); });
  });

  var knopf = document.getElementById('standort');
  if (!knopf) { return; }   // Flugziele: kein Standort
  if (!('geolocation' in navigator)) { knopf.hidden = true; return; }
  knopf.addEventListener('click', function () {
    knopf.textContent = 'Standort wird bestimmt …';
    navigator.geolocation.getCurrentPosition(function (pos) {
      // Auf 0,1 Grad runden, bevor irgendetwas den Browser verlässt
      var ort = form.querySelector('input[name=ort]') || form.appendChild(Object.assign(document.createElement('input'), { type: 'hidden', name: 'ort' }));
      ort.value = pos.coords.latitude.toFixed(1) + ',' + pos.coords.longitude.toFixed(1);
      form.submit();
    }, function () {
      knopf.textContent = 'Standort nicht verfügbar';
    }, { maximumAge: 3600000, timeout: 10000 });
  });
})();
</script>
</body>
</html>
