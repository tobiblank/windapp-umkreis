<?php
/**
 * Wohinfahrma - Schnee-Modus (seit 8.11.2026): Rangliste der Schnee-Regionen in den
 * Alpen fuer Freeride und Skitour, mit der Lawinenlage der Warndienste daneben.
 *
 * Parameter (alle optional):
 *   sport   seit 9.11.2026 ohne Wirkung (alte Links bleiben gueltig): eine Regel fuer alle,
 *           der Pulvertag (SCHNEE_REGEL, lib/schnee.php)
 *   km      Umkreis in km Strasse
 *   ort     "Breite,Laenge", auf 0,1 Grad gerundet
 *   ansicht bald (heute bis uebermorgen), reise (Tag 3 bis 7), woche
 *   nach    summe | YYYY-MM-DD (Tag); km aus alten Links heisst prio=km
 *   prio    pulver | neu | km (seit 10.11.2026, SCHNEE_PRIOS in lib/schnee.php)
 *   region + nach=YYYY-MM-DD: geteilte Vorhersage einer Region
 *   fav     "id,id,…" (seit 13.11.2026): nur diese Regionen, ohne Umkreis (lib/favoriten.php)
 *
 * Die Lawinenwarnstufe geht nie in Bewertung, Sortierung oder Farbe ein. Seit 10.11.2026
 * steht ab Stufe 3 ein Warndreieck bei der Region, ab Stufe 4 rot umrandet (lawine_warnung()).
 */

declare(strict_types=1);
date_default_timezone_set('UTC');
require __DIR__ . '/lib/regionen.php';
require __DIR__ . '/lib/bewertung.php';
require __DIR__ . '/lib/karte.php';
require __DIR__ . '/lib/fahrzeit.php';
require __DIR__ . '/lib/design.php';
require __DIR__ . '/lib/schnee.php';
require __DIR__ . '/lib/lawine.php';
require __DIR__ . '/lib/maps.php';
require __DIR__ . '/lib/favoriten.php';

const TZ_SEITE = 'Europe/Berlin';
const HEIM_STANDARD = [48.07, 11.15];      // Inning am Ammersee
const UMKREISE = [100, 200, 300, 400, 600, 800];
const WOCHENTAGE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
const HAFTUNG = LAWINE_HAFTUNG;
const FAV_SCHLUESSEL = 'wohinfahrma_fav_schnee';

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
       . '<title>Wohinfahrma – Schnee</title><link rel="icon" href="favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="' . stil_url() . '"><main class="seite"><h1 class="marke">Wohin<span>fahrma</span></h1>'
       . '<p class="karte">' . h($text) . ' <a href="./">Zum Wind-Modus</a></p></main>';
    exit;
}

// Abbruch (z. B. zu wenig Speicher) als lesbare Seite statt leerem HTTP 500 (seit 11.11.2026,
// wie index.php); die Meldung steht weiter im Fehlerprotokoll des Servers.
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
       . '<title>Wohinfahrma – Schnee</title><link rel="stylesheet" href="' . stil_url() . '"><main class="seite"><h1 class="marke">Wohin<span>fahrma</span></h1>'
       . '<p class="karte">' . ($speicher ? 'Die Seite braucht gerade mehr Speicher, als der Server erlaubt (memory_limit ' . h((string)ini_get('memory_limit')) . ').'
                                          : 'Beim Rechnen der Schnee-Rangliste ist ein Fehler aufgetreten.')
       . ' Bitte später noch einmal versuchen. <a href="./">Zum Wind-Modus</a></p></main>';
});

$cfg = @include __DIR__ . '/config.php';
if (!is_array($cfg) || empty($cfg['data_dir'])) {
    fehlerseite('Die App ist noch nicht eingerichtet (config.php fehlt).');
}
try {
    $schnee = schnee_katalog_laden($cfg['schneeregionen_datei'] ?? __DIR__ . '/lib/schneeregionen.json');
    $windRegionen = is_file($cfg['regionen_datei'] ?? __DIR__ . '/lib/regionen.json') ? regionen_laden($cfg['regionen_datei'] ?? __DIR__ . '/lib/regionen.json') : [];
} catch (RuntimeException $e) {
    fehlerseite('Der Schnee-Katalog ist nicht lesbar.');
}
$regionen = $schnee['regionen'];
$dienste = $schnee['lawinendienste'];

// --- Auswahl ----------------------------------------------------------
$km = isset($_GET['km']) && in_array((int)$_GET['km'], UMKREISE, true) ? (int)$_GET['km'] : 400;
$ansicht = in_array($_GET['ansicht'] ?? '', ['reise', 'woche'], true) ? $_GET['ansicht'] : 'bald';
$woche = $ansicht === 'woche';

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

// Geteilte Vorhersage: ?region=<id>&nach=<YYYY-MM-DD>
$geteilt = null;
$geteiltTag = null;
$geteiltVorbei = null;
foreach ($regionen as $r) {
    if ($r['id'] === ($_GET['region'] ?? null)) {
        $geteilt = $r;
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

// Deine Regionen (seit 13.11.2026): nur bekannte ids, Reihenfolge wie im Katalog
$fav = $geteilt ? [] : fav_aus_get($regionen, $_GET['fav'] ?? null);

[$von, $bis] = match ($ansicht) { 'reise' => [3, 6], 'woche' => [0, 6], default => [0, 2] };
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

function tag_datum(string $tag, DateTimeZone $tz): string
{
    $d = new DateTimeImmutable($tag, $tz);
    return WOCHENTAGE[(int)$d->format('w')] . ' ' . $d->format('j.n.');
}

// --- Rechnen ----------------------------------------------------------
$jetzt = new DateTimeImmutable('now');
$dataDir = rtrim($cfg['data_dir'], '/');
$imUmkreis = $geteilt ? [$geteilt['id']] : ($fav ?: array_column(array_filter($regionen, fn($r) => entfernung_km($heim, $r['punkt']) <= $km), 'id'));
$stand = daten_laden($dataDir, ['schnee_vorhersage', 'schnee_ensemble'], nur: $imUmkreis);
$liste = $geteilt
    ? schnee_rangliste([$geteilt], $stand, $heim, INF, $tage, $jetzt)
    : ($fav ? schnee_rangliste(array_values(array_filter($regionen, fn($r) => in_array($r['id'], $fav, true))), $stand, $heim, INF, $tage, $jetzt)
            : schnee_rangliste($regionen, $stand, $heim, (float)$km, $tage, $jetzt));
$lagen = lawine_laden($dataDir);
$messungen = stationen_laden($dataDir);
$messnetze = $schnee['messnetze'];

$nach = (string)($_GET['nach'] ?? 'summe');
$prio = (string)($_GET['prio'] ?? 'pulver');
if ($nach === 'km') {          // alte Links: Fahrzeit war eine Sortierung
    $prio = 'km';
}
if (!in_array($nach, $tage, true)) {
    $nach = 'summe';
}
if (!isset(SCHNEE_PRIOS[$prio])) {
    $prio = 'pulver';
}
$fahrzeiten = fahrzeiten_laden($dataDir);
$alleRegionen = array_merge($windRegionen, $regionen);
$fahrtModell = fahrt_modell($fahrzeiten, $alleRegionen);
$fahrtUmweg = fahrt_umweg($fahrzeiten, $alleRegionen);
foreach ($liste as $i => $e) {
    $liste[$i]['fahrt'] = fahrzeit($e['region'], $heim, (float)$e['km'], $fahrzeiten, $fahrtModell, $fahrtUmweg);
}
if (!$geteilt && !$fav) {
    $liste = array_values(array_filter($liste, fn($e) => im_umkreis($e, (float)$km)));
}
$liste = schnee_sortieren($liste, $nach, $prio);

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

/** Farbe der Region (Balken links): bester Tag in der Blau-Skala */
function beste_stufe(array $e): string
{
    $rang = ['leer' => 0, 'nix' => 1, 's-no' => 2, 's-maybe' => 3, 's-go' => 4];
    $best = 'nix';
    foreach ($e['tage'] as $d) {
        $k = schnee_klasse($d);
        if ($rang[$k] > $rang[$best]) {
            $best = $k;
        }
    }
    return $best;
}

/** "5–14 cm" aus der Ensemble-Spanne, null ohne Ensemble */
function spanne_text(array $d): ?string
{
    $e = $d['ens'] ?? null;
    if (!$e || $e['neu24_p10'] === null) {
        return null;
    }
    $u = (int)round($e['neu24_p10']);
    $o = (int)round($e['neu24_p90']);
    if ($o === 0) {
        return null;    // kein Neuschnee in fast allen Laeufen: nichts anzeigen statt "0 cm"
    }
    return $u === $o ? zahl($u) . ' cm' : zahl($u) . '–' . zahl($o) . ' cm';
}

function link_mit(array $aenderung): string
{
    global $km, $ansicht, $heim, $eigenerOrt, $nach, $prio, $fav;
    $p = ['km' => $km, 'ansicht' => $ansicht];
    if ($fav) {
        $p['fav'] = implode(',', $fav);
    }
    if ($nach !== 'summe') {
        $p['nach'] = $nach;
    }
    if ($prio !== 'pulver') {
        $p['prio'] = $prio;
    }
    if ($eigenerOrt) {
        $p['ort'] = $heim[0] . ',' . $heim[1];
    }
    return 'schnee.php?' . http_build_query(array_merge($p, $aenderung));
}

function stunden_link(array $region, ?string $tag = null): string
{
    return 'schnee_stunden.php?' . http_build_query(array_filter(['region' => $region['id'], 'tag' => $tag]));
}

/** "1.200 m" */
function meter(?float $m): string
{
    return $m === null ? '?' : zahl(round($m / 50) * 50) . ' m';
}

/** "26 cm" (unter 1 cm: "0 cm"); null: "?" */
function cm(?float $c): string
{
    return $c === null ? '?' : zahl(round($c)) . ' cm';
}

/**
 * ☀ / ⛅ / ☁ nach der Bewoelkung im Fenster, null ohne Wert. ☀, ☁ und ❄ sind von Haus
 * aus Textzeichen (schwarz in der Schriftfarbe); erst das Variantenzeichen U+FE0F macht
 * sie zum farbigen Emoji wie ⛅.
 */
function himmel_symbol(?float $wolken, bool $schneit = false): ?string
{
    if ($schneit) {
        return "❄\u{FE0F}";
    }
    return match (schnee_himmel($wolken)) { 'sonnig' => "☀\u{FE0F}", 'teils sonnig' => '⛅', 'bewölkt' => "☁\u{FE0F}", default => null };
}

/** Tooltip der Kachel: alles, was dort keinen Platz hat */
function kachel_titel(array $d): string
{
    $k = $d['det'];
    $sp = spanne_text($d);
    return implode(' · ', array_filter([
        'Neuschnee 24 h bis ' . SCHNEE_MORGEN . ' Uhr ' . cm($k['neu24']) . ($sp ? ' (Ensemble ' . $sp . ')' : ''),
        'in 72 h ' . cm($k['neu72']), $k['hs'] !== null ? 'Schneehöhe ≈ ' . cm($k['hs']) . ' (Modell)' : null,
        $k['wolken'] !== null ? schnee_himmel($k['wolken']) . ' (' . zahl($k['wolken']) . ' % Wolken)' : null,
        ($tt = schnee_temp_text($k)) !== null ? 'Temperatur Nacht / Tag ' . $tt . 'C' : null,
        $k['kamm'] !== null ? 'Wind am Kamm ' . zahl($k['kamm']) . ' km/h' . ($k['kamm_stark'] ? ' (stark)' : '') : null,
        $k['geht'] ? 'Pulvertag' : 'kein Pulvertag: ' . $k['grund'],
        !empty($k['firn']) ? $k['firn']['text'] : null,
    ]));
}

function abfahrt_text(array $e, string $tag, array $d): ?string
{
    global $tzSeite, $jetzt;
    if (!$d['det']['geht'] || $e['fahrt']['min'] === null) {
        return null;
    }
    $a = abfahrt($tag, (int)$d['fenster'][0], (int)$d['fenster'][1], new DateTimeZone($e['region']['zeitzone']), $e['fahrt']['min'], $tzSeite, $jetzt);
    $ca = $e['fahrt']['geschaetzt'] ? 'ca. ' : '';
    return match ($a['art']) {
        'bis'    => 'Abfahrt bis ' . $ca . $a['zeit']->format('G:i'),
        'vortag' => 'Anreise am Vortag',
        'jetzt'  => 'jetzt los: ab ' . $ca . $a['zeit']->format('G:i') . ' dort',
        default  => 'heute nicht mehr erreichbar',
    };
}

function teilenswert(array $d): bool
{
    return $d['det']['geht'] && empty($d['vorbei']);
}

function teilen_text(array $region, string $tag, array $d, DateTimeZone $tz, ?array $lage = null): string
{
    $k = $d['det'];
    $teile = ['Neuschnee ' . cm($k['neu24']) . ' über Nacht, ' . cm($k['neu72']) . ' in 72 h', schnee_himmel($k['wolken']),
              $k['kamm_stark'] ? 'viel Wind am Kamm' : null];
    $w = lawine_warnung($lage);
    return $region['name'] . ', ' . tag_datum($tag, $tz) . ': ' . implode(' · ', array_filter($teile)) . '. '
         . ($w ? '⚠ ' . $w['text'] . ' ' : '') . HAFTUNG;
}

/** Lawinen-Abzeichen fuer eine Kachel oder null */
function lawine_abzeichen(?array $lage, string $wort = 'LWS'): string
{
    if (!$lage) {
        return '';
    }
    $s = $lage['stufe'];
    $w = lawine_warnung($lage);
    return '<span class="lw lw' . $s . ($w ? ' ' . $w['klasse'] : '') . '" title="' . h('Lawinenwarnstufe (Gefahrenstufe des Warndiensts): ' . lawine_stufen_text($lage['teile']) . ($w ? '. ' . $w['text'] : '')) . '">'
         . ($w ? '<span class="warn" aria-label="Warnung">⚠&#xFE0E;</span> ' : '') . h($wort) . ' ' . ($s ?: '–') . '</span>';
}

/**
 * Block mit der Lawinenlage fuer heute und morgen (soweit angezeigt). Seit 10.11.2026 nach den
 * Hinweisen des LWD Bayern: Gueltigkeit, Stoerung des Abrufs, Auszug gekennzeichnet, eigene
 * Hinweise (Warnzeichen) nach den Angaben des Warndiensts und als solche benannt.
 */
function lawine_html(array $region, array $lagenTage, array $dienste, array $tage, array $lagen = []): string
{
    global $tzSeite, $heute;
    $d = $dienste[$region['warndienst']] ?? null;
    if (!$d) {
        return '';
    }
    $link = '<a href="' . h($d['url_bericht']) . '" rel="noopener">' . h($d['kurz']) . ' ↗</a>';
    $kurze = array_slice($tage, 0, 2);
    $kurze = array_values(array_filter($kurze, fn($t) => (int)$heute->diff(new DateTimeImmutable($t, $tzSeite))->format('%r%a') <= 1));
    if (!$kurze) {
        return '<div class="lawine klein"><p class="leise">Lawinenlage nur für heute und morgen: ' . $link . '</p></div>';
    }
    if (!lawine_freigegeben($region, $dienste)) {
        return '<div class="lawine klein"><p>Lawinenlage: ' . $link . ' <span class="leise">(die Stufe zeigen wir hier, sobald die Nutzung mit dem Warndienst geklärt ist)</span></p></div>';
    }
    $stoerung = lawine_stoerung($lagen[$region['warndienst']] ?? null);
    $zus = [];
    foreach ($kurze as $t) {
        $l = $lagenTage[$t] ?? null;
        $zus[] = h(tag_kurz($t, $heute)) . ' ' . ($l ? (lawine_warnung($l) ? '⚠&#xFE0E; ' : '') . 'LWS ' . ($l['stufe'] ?: '–') : 'kein Bericht');
    }
    $o = '<details class="lawine klein"' . ($stoerung ? ' open' : '') . '><summary>Lawinenlage: ' . implode(', ', $zus) . ($stoerung ? ' · <span class="lawine-stoerung">Abruf gestört</span>' : '') . '</summary>';
    if ($stoerung) {
        $o .= stoerung_html($stoerung, $d);
    }
    $warnungen = '';
    $auszug = null;
    // Seit 13.11.2026 auch ohne Bericht: wann zuletzt beim Dienst nachgesehen wurde
    $ab = lawine_zeit_text((string)($lagen[$region['warndienst']]['ts'] ?? ''), $tzSeite);
    foreach ($kurze as $t) {
        $l = $lagenTage[$t] ?? null;
        if (!$l) {
            $o .= '<p><b>' . h(tag_kurz($t, $heute)) . ':</b> <span class="leise">kein gültiger Lagebericht (heißt nicht „keine Gefahr“)</span> · ' . $link
                . ($ab !== '' ? ' <span class="leise">abgerufen ' . h($ab) . '</span>' : '') . '</p>';
            continue;
        }
        $probleme = array_map(fn($p) => h($p['text']) . ($p['aspekte'] && count($p['aspekte']) < 8 ? ' ' . lawine_rose($p['aspekte']) : ''), lawine_probleme_liste($l['probleme']));
        $o .= '<p><b>' . h(tag_kurz($t, $heute)) . ':</b> ' . lawine_abzeichen($l, 'Gefahrenstufe') . ' ' . h(lawine_stufen_text($l['teile'], true))
            . ($probleme ? ' · ' . implode(' · ', $probleme) : '')
            . ' · ' . $link . ' <span class="leise">' . h(lawine_gueltig_text($l, $tzSeite)) . '</span></p>';
        if ($w = lawine_warnung($l)) {
            $warnungen .= '<p class="lawine-warnung ' . $w['klasse'] . '">⚠&#xFE0E; ' . h(tag_kurz($t, $heute)) . ': ' . h($w['text']) . '</p>';
        }
        $auszug ??= $l;
    }
    if ($auszug) {
        $o .= '<p class="leise">' . h(lawine_auszug_text($auszug, $d)) . ' Vollständiger Bericht: ' . $link . '</p>';
    }
    return $o . $warnungen . '<p class="leise">' . h(HAFTUNG) . '</p></details>';
}

/** Hinweis bei gestoertem Abruf eines Warndiensts (lawine_stoerung()) */
function stoerung_html(array $stoerung, array $dienst): string
{
    global $tzSeite;
    $stand = $stoerung['stand'] !== '' ? ' Stand der Daten: ' . lawine_zeit_text($stoerung['stand'], $tzSeite) . '.' : '';
    return '<p class="lawine-stoerung">⚠&#xFE0E; ' . h($stoerung['text'] . $stand) . ' Bitte direkt im Bericht nachsehen: <a href="' . h($dienst['url_bericht']) . '" rel="noopener">' . h($dienst['name']) . ' ↗</a></p>';
}

// Lage je Region und Tag (nur fuer freigegebene Dienste; heute und morgen)
$lageVon = [];
foreach ($liste as $e) {
    foreach ($tage as $t) {
        $lageVon[$e['region']['id']][$t] = lawine_freigegeben($e['region'], $dienste) ? lawine_lage($e['region'], $lagen, $t) : null;
    }
}

$schwelle = 'ab ' . SCHNEE_REGEL['neu_min'] . ' cm Neuschnee in 72 h und höchstens teils sonnig';
$quellen = array_filter($dienste, fn($d) => $d['freigegeben']);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Wohinfahrma – Wo liegt Schnee? Fahr ma.</title>
<meta name="description" content="Wo gibt es in den nächsten Tagen frischen Schnee und gutes Wetter für Skitouren und Freeride in den Alpen? Rangliste nach Neuschnee und Wetter, mit der Lawinenlage der Warndienste.">
<link rel="stylesheet" href="<?= stil_url() ?>">
<link rel="manifest" href="manifest.php">
<link rel="icon" href="favicon.ico" sizes="32x32">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<?= design_kopf() ?>
</head>
<body>
<main class="seite">

<header class="kopf">
  <div class="logozeile">
    <svg class="logo" viewBox="0 0 100 100" width="40" height="40" aria-hidden="true"><path class="logo-marke" d="M50 7C31.2 7 17 21.6 17 40.5C17 64 50 93 50 93S83 64 83 40.5C83 21.6 68.8 7 50 7Z"/><path class="logo-fahne" d="M31 36H57a7 7 0 1 0 -7 -7M31 48H65a7 7 0 1 1 -7 7"/></svg>
    <div>
      <h1 class="marke">Wohin<span>fahrma</span></h1>
      <p class="leise klein">Wo liegt Schnee? Fahr ma.</p>
      <nav class="modus" aria-label="Modus"><a href="./">Wind</a><a href="schnee.php" aria-current="page">Schnee</a></nav>
    </div>
  </div>
  <?php if ($standText): ?><p class="leise klein rechts">Stand<br><?= h($standText) ?></p><?php endif ?>
</header>

<?php if ($geteilt): ?>
<p class="karte geteilt">
  Geteilte Vorhersage<?= $geteiltTag ? ' für ' . h(tag_datum($geteiltTag, $tzSeite)) : '' ?>, aktueller Stand.
  <?php if ($geteiltVorbei): ?>Der geteilte Tag (<?= h(tag_datum($geteiltVorbei, $tzSeite)) ?>) ist vorbei.<?php endif ?>
  <a href="schnee.php">Zur Rangliste</a>
</p>
<?php else: ?>
<form class="wahl karte steuer" method="get" id="wahl" action="schnee.php">
  <?php if ($fav): ?>
  <input type="hidden" name="km" value="<?= $km ?>"><input type="hidden" name="fav" value="<?= h(implode(',', $fav)) ?>">
  <?php else: ?>
  <label>Umkreis
    <select name="km">
      <?php foreach (UMKREISE as $u): ?>
        <option value="<?= $u ?>"<?= $u === $km ? ' selected' : '' ?>><?= zahl($u) ?> km</option>
      <?php endforeach ?>
    </select>
  </label>
  <?php endif ?>
  <?php if (!$woche): ?>
  <label>Tag
    <select name="nach">
      <option value="summe"<?= $nach === 'summe' ? ' selected' : '' ?>>alle Tage</option>
      <?php foreach ($tage as $t): ?>
        <option value="<?= h($t) ?>"<?= $nach === $t ? ' selected' : '' ?>><?= h(tag_kurz($t, $heute)) ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <?php endif ?>
  <label>Priorität
    <select name="prio">
      <?php foreach (SCHNEE_PRIOS as $wert => $name): ?>
        <option value="<?= h($wert) ?>"<?= $prio === $wert ? ' selected' : '' ?>><?= h($name) ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <input type="hidden" name="ansicht" value="<?= h($ansicht) ?>">
  <?php if ($eigenerOrt): ?><input type="hidden" name="ort" value="<?= h($heim[0] . ',' . $heim[1]) ?>"><?php endif ?>
  <p class="ort klein leise">
    Von <?= $eigenerOrt ? 'deinem Standort (auf etwa 10 km gerundet)' : 'Inning am Ammersee' ?> ·
    <button type="button" class="textknopf" id="standort">Meinen Standort nehmen</button>
    <?php if ($eigenerOrt): ?> · <a href="<?= h(link_mit(['ort' => null])) ?>">Inning</a><?php endif ?>
  </p>
  <noscript><button class="knopf">Anzeigen</button></noscript>
</form>

<nav class="reiter" aria-label="Ansicht">
  <a href="<?= h(link_mit(['ansicht' => 'bald', 'nach' => null])) ?>"<?= !$woche ? ' aria-current="page"' : '' ?>>Kacheln</a>
  <a href="<?= h(link_mit(['ansicht' => 'woche', 'nach' => null])) ?>"<?= $woche ? ' aria-current="page"' : '' ?>>Tabelle 7 Tage</a>
</nav>
<?php // Deine Regionen (seit 13.11.2026): mit fav= vom Server, sonst zeigt das Skript den Link ?>
<?= fav_zeile_html($fav, link_mit(['fav' => null])) ?>
<?php if (!$woche): ?>
<?php $zn = zeitraum_namen($heute, WOCHENTAGE); // Wochentage statt "Heute – Übermorgen" / "Tag 3 – 7" (seit 13.11.2026) ?>
<nav class="zeitraum klein" aria-label="Zeitraum">
  <a href="<?= h(link_mit(['ansicht' => 'bald', 'nach' => null])) ?>" title="<?= h($zn['bald'][1]) ?>"<?= $ansicht === 'bald' ? ' aria-current="page"' : '' ?>><?= h($zn['bald'][0]) ?></a>
  <a href="<?= h(link_mit(['ansicht' => 'reise', 'nach' => null])) ?>" title="<?= h($zn['reise'][1]) ?>"<?= $ansicht === 'reise' ? ' aria-current="page"' : '' ?>><?= h($zn['reise'][0]) ?></a>
</nav>
<?php endif ?>
<?php endif ?>

<p class="karte klein haftung"><b>Lawinen:</b> <?= h(HAFTUNG) ?> Die Gefahrenstufe ändert Farbe und Reihenfolge nicht; ab Stufe 3 steht ein Warndreieck ⚠&#xFE0E; bei der Region, ab Stufe 4 ist der Tag rot umrandet.</p>
<?php foreach ($quellen as $id => $d): if ($st = lawine_stoerung($lagen[$id] ?? null)): ?><?= str_replace('<p class="lawine-stoerung">', '<p class="karte klein lawine-stoerung">', stoerung_html($st, $d)) ?>
<?php endif; endforeach ?>

<details class="leise klein erklaerung">
  <summary><b>Pulvertag</b> · <?= h($schwelle) ?> · <span class="unterstrichen">Wie wird gerechnet?</span></summary>
  <p>
  „Neu“ je Tag: Niederschlag der 24 Stunden bis <?= SCHNEE_MORGEN ?> Uhr, der auf Tourhöhe als Schnee fällt (Schneefallgrenze unter der Tourhöhe; 1 mm Wasser = <?= zahl(SCHNEE_CM_JE_MM, 1) ?> cm Schnee). So lassen sich die Tage addieren. Darunter die Spanne aus dem ECMWF-Ensemble (80 % der 51 Läufe liegen darin).
  „Pulvertag“ (kräftiges Blau): mindestens <?= SCHNEE_REGEL['neu_min'] ?> cm Neuschnee in den 72 Stunden davor, damit auch der schöne Tag nach dem Schneefall zählt, und von <?= SCHNEE_TAG[0] ?> bis <?= SCHNEE_TAG[1] ?> Uhr höchstens teils sonnig (⛅, bis <?= SCHNEE_REGEL['wolken_max'] ?> % Wolken). Sonst hellt das Blau mit dem Neuschnee ab (ab 10 cm, ab 3 cm).
  Reihenfolge nach der gewählten Priorität: „Pulver + Sonne“ = der beste Pulvertag, also am meisten Neuschnee an einem schönen Tag, dann Neuschnee im Zeitraum. „Neuschnee“ = Neuschnee in Stufen (ab <?= implode(', ab ', SCHNEE_NEU_STUFEN) ?> cm), innerhalb einer Stufe zuerst die Sonne. „Fahrzeit“ = die nächste zuerst.
  „Temp“: Temperatur auf Tourhöhe, tiefste in der Nacht davor (<?= SCHNEE_NACHT_AB ?>–<?= SCHNEE_MORGEN ?> Uhr) / höchste am Tag (<?= SCHNEE_TAG[0] ?>–<?= SCHNEE_TAG[1] ?> Uhr). Im Frühjahr: Friert es nachts, trägt der Firn am Morgen; je wärmer am Tag, desto früher weicht er auf. Am Talort ist es meist wärmer, bei Inversion auch kälter. Von März bis Juni steht „Firn“ dabei, wenn es nachts auf höchstens <?= schnee_grad(SCHNEE_FIRN_NACHT) ?> abkühlt, am Tag mindestens <?= schnee_grad(SCHNEE_FIRN_TAG) ?> erreicht, der Tag höchstens teils sonnig ist und kein frischer Pulver liegt; „kein Frost“, wenn die Nacht über 0° bleibt.
  Schneehöhe und Wind am Kamm (700 hPa, etwa 3000 m) schließen nichts aus, sie stehen nur dabei; 💨 ab <?= SCHNEE_KAMM_HINWEIS ?> km/h am Kamm. Ob genug Schnee für eine Tour liegt, siehst du an der Schneehöhe (Modell, im Gebirge grob) und an den Messstationen. Die Schwellen sind Startwerte, nicht kalibriert.
  </p>
  <p>Blau statt Grün: Ein gutes Feld heißt „guter Schnee“, nie „sicher“. Die Lawinenstufe steht in den offiziellen Farben daneben, nur für Tage mit Lagebericht: „LWS 3“ = Lawinenwarnstufe, im Bericht „Gefahrenstufe 3“. ⚠&#xFE0E; ab Stufe 3, weil bei Stufe 3 die meisten tödlichen Unfälle passieren (Bayerische Alpen seit 1993/94: 48 % laut Lawinenwarndienst Bayern); ab Stufe 4 zusätzlich rot umrandet. Am Platz in der Rangliste ändert das nichts.</p>
</details>

<?php if ($liste && !$geteilt):
    $kartenTag = in_array($nach, $tage, true) ? $nach : null;
    $heimXY = karte_punkt($heim[0], $heim[1]);
    $punkte = [];
    foreach ($liste as $e) {
        $punkte[] = ['e' => $e, 'xy' => karte_punkt((float)$e['region']['punkt'][0], (float)$e['region']['punkt'][1]), 'wert' => schnee_karte_wert($e, $kartenTag)];
    }
    usort($punkte, fn($a, $b) => $a['wert']['rang'] <=> $b['wert']['rang']);
    [$vx, $vy, $vw, $vh] = karte_ausschnitt(array_merge([$heimXY], array_column($punkte, 'xy')));
    $rp = $vw / 42;
    // Feste Orte zur Orientierung (seit 10.11.2026, Auswahl Tobias) vor den Regionsnamen,
    // frei vom Maennchen; die Regionsnamen weichen ihnen aus
    $orte = karte_orte([$vx, $vy, $vw, $vh], $rp, array_merge(array_column($punkte, 'xy'), [$heimXY, [$heimXY[0], $heimXY[1] - $rp * 1.5]]), [], KARTE_ORTE_SCHNEE, $ortBoxen);
    $namen = karte_namen(array_map(fn($p) => ['xy' => $p['xy'], 'name' => $p['e']['region']['name'], 'rang' => $p['wert']['rang']], $punkte), $rp, [$vx, $vy, $vw, $vh], $namenBoxen, $ortBoxen ?? []);
    // Weitere Orte (seit 10.11.2026) nur in den Luecken: frei von Punkten, Namen, festen Orten, Maennchen
    $festXY = array_column($orte, 'xy');
    $orte = array_merge($orte, karte_orte([$vx, $vy, $vw, $vh], $rp, array_merge(array_column($punkte, 'xy'), $festXY, [$heimXY, [$heimXY[0], $heimXY[1] - $rp * 1.5]]), array_merge($namenBoxen ?? [], $ortBoxen ?? []), KARTE_ORTE_SCHNEE_LUECKEN));
    // Neuschnee-Raster (seit 9.11.2026): Zellen mit eigenem Modellwert, Tage wie bei den Punkten
    // (am gewaehlten Tag oder Summe im Zeitraum; heute nicht mehr, wenn das Tourenfenster vorbei ist)
    $raster = schnee_raster_laden($dataDir);
    $rasterTage = $kartenTag ? [$kartenTag] : array_values(array_filter($tage, fn($t) => !array_filter($liste, fn($e) => !empty($e['tage'][$t]['vorbei']))));
    // Schneehoehe (seit 18.11.2026): Modellwert um SCHNEE_MORGEN Uhr am gewaehlten Tag, sonst am
    // ersten Tag des Zeitraums; keine Summe ueber Tage
    $hsTag = $kartenTag ?? ($rasterTage[0] ?? null);
    $zellen = [];
    $hsZellen = 0;
    foreach ($raster['zellen'] ?? [] as $z) {
        $w = array_intersect_key($z['neu'], array_flip($rasterTage));
        $hsWert = $hsTag !== null ? ($z['hs'][$hsTag] ?? null) : null;
        if (!$w && $hsWert === null) {
            continue;
        }
        $h = SCHNEE_RASTER_SCHRITT / 2;
        $a = karte_punkt($z['latitude'] + $h, $z['longitude'] - $h);
        $b = karte_punkt($z['latitude'] - $h, $z['longitude'] + $h);
        if ($b[0] < $vx || $a[0] > $vx + $vw || $b[1] < $vy || $a[1] > $vy + $vh) {
            continue;
        }
        $hsZellen += $hsWert !== null ? 1 : 0;
        $zellen[] = ['a' => $a, 'b' => $b, 'neu' => $w ? array_sum($w) : 0.0, 'hs' => $hsWert, 'z' => $z];
    }
    $u = $rp * 0.7;
    // Hoechste Lawinenwarnstufe eines Punkts: am gewaehlten Tag, sonst ueber alle Tage mit Bericht
    // (meist heute und morgen); dieselbe Stufe wie das Warndreieck auf der Karte
    $stufeVon = function (array $p) use ($kartenTag, $tage, $lageVon): int {
        $s = 0;
        foreach ($kartenTag ? [$kartenTag] : $tage as $t) {
            $s = max($s, (int)($lageVon[$p['e']['region']['id']][$t]['stufe'] ?? 0));
        }
        return $s;
    };
?>
<details class="karte landkarte" id="landkarte" open>
  <summary><b>Karte</b> <span class="leise klein">Neuschnee <?= $kartenTag ? h(tag_kurz($kartenTag, $heute)) : 'im Zeitraum' ?></span></summary>
  <svg class="landkarte-bild" viewBox="<?= "$vx $vy $vw $vh" ?>" role="img" aria-label="Karte der Schnee-Regionen im Umkreis">
    <rect class="meer" x="<?= $vx ?>" y="<?= $vy ?>" width="<?= $vw ?>" height="<?= $vh ?>"/>
    <use href="karte.svg#land" class="land"/>
    <?= relief_svg([$vx, $vy, $vw, $vh]) // Hangschatten der Alpen (seit 18.11.2026) ?>
    <?php // Farbfeld (seit 9.11.2026): weicher Fleck je Region in der Farbe ihres Neuschnees,
          // wie das Windfeld im Wind-Modus; bewusst kein Feld zwischen den Regionen. ?>
    <?php if ($zellen): // Raster: jede Zelle mit ihrem eigenen Wert, leicht weichgezeichnet ?>
    <defs><filter id="weich" x="-10%" y="-10%" width="120%" height="120%"><feGaussianBlur stdDeviation="<?= round(($zellen[0]['b'][0] - $zellen[0]['a'][0]) * 0.35, 2) ?>"/></filter></defs>
    <g class="windfeld schneefeld schneeraster feld-neu" filter="url(#weich)">
      <?php foreach ($zellen as $c): if ($c['neu'] < 0.5) { continue; } ?>
      <rect x="<?= round($c['a'][0], 2) ?>" y="<?= round($c['a'][1], 2) ?>" width="<?= round($c['b'][0] - $c['a'][0] + 0.05, 2) ?>" height="<?= round($c['b'][1] - $c['a'][1] + 0.05, 2) ?>" style="fill: <?= schneefarbe((float)$c['neu']) ?>"/>
      <?php endforeach ?>
    </g>
    <?php if ($hsZellen): // Schneehoehe (seit 18.11.2026), nur sichtbar, wenn im Schalter gewaehlt ?>
    <g class="windfeld schneefeld schneeraster feld-hs" filter="url(#weich)">
      <?php foreach ($zellen as $c): if ($c['hs'] === null || $c['hs'] < SCHNEEHOEHE_AB) { continue; } ?>
      <rect x="<?= round($c['a'][0], 2) ?>" y="<?= round($c['a'][1], 2) ?>" width="<?= round($c['b'][0] - $c['a'][0] + 0.05, 2) ?>" height="<?= round($c['b'][1] - $c['a'][1] + 0.05, 2) ?>" style="fill: <?= schneehoehe_farbe((float)$c['hs']) ?>"><title>Schneehöhe im Modell: <?= zahl($c['hs']) ?> cm</title></rect>
      <?php endforeach ?>
    </g>
    <?php endif ?>
    <?php else: // ohne Raster: weicher Fleck je Region in der Farbe ihres Neuschnees ?>
    <defs><filter id="weich" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="<?= round($rp * 0.9, 2) ?>"/></filter></defs>
    <g class="windfeld schneefeld" filter="url(#weich)">
      <?php foreach ($punkte as $p): if (($p['wert']['neu'] ?? 0) < 0.5) { continue; } ?>
      <circle cx="<?= round($p['xy'][0], 1) ?>" cy="<?= round($p['xy'][1], 1) ?>" r="<?= round($rp * 2.4, 2) ?>" style="fill: <?= schneefarbe((float)$p['wert']['neu']) ?>"/>
      <?php endforeach ?>
    </g>
    <?php endif ?>
    <?php if (!$fav): ?><circle class="umkreis" cx="<?= round($heimXY[0], 1) ?>" cy="<?= round($heimXY[1], 1) ?>" r="<?= round(karte_km((float)$km, $heim[0]), 1) ?>"/><?php endif ?>
    <?php foreach ($punkte as $nr => $p):
        $r = $p['e']['region'];
        $n = $p['wert']['neu'];
        $klasse = $p['wert']['klasse'];
        $dunkel = $n !== null && $n >= 0.5 && schneefarbe_hell((float)$n) ? ' dunkel' : '';
        $zahl = $n !== null && $n >= 0.5;
        $rk = $zahl ? $rp : $rp * 0.55;
        $fuellung = $n === null ? '' : ' style="fill: ' . schneefarbe((float)$n) . '"';
        $stufeMax = $stufeVon($p);
        $warn = lawine_warnung($stufeMax ? ['stufe' => $stufeMax] : null);
        $titel = ($warn ? '⚠ ' : '') . $r['name'] . ': Neuschnee ' . cm($n) . ($kartenTag ? '' : ' im Zeitraum')
               . ($p['wert']['geht'] ? ' · Pulvertag' . ($kartenTag ? '' : ' ' . tag_kurz($p['wert']['tag'], $heute)) : '')
               . ' · ' . fahrzeit_text($p['e']['fahrt']) . ' Fahrt'
               . ($warn ? ' · Lawinenwarnstufe ' . $stufeMax . ($kartenTag ? '' : ' (höchste heute/morgen)') : '');
    ?>
    <a href="<?= h(stunden_link($r, $p['wert']['tag'])) ?>" class="punkt <?= $klasse ?>">
      <title><?= h($titel) ?></title>
      <circle cx="<?= round($p['xy'][0], 1) ?>" cy="<?= round($p['xy'][1], 1) ?>" r="<?= round($rk, 2) ?>"<?= $fuellung ?><?php if ($p['wert']['geht']): ?> data-schneit="<?= round($p['xy'][0], 1) ?>,<?= round($p['xy'][1], 1) ?>,<?= round((float)$n) ?>"<?php endif ?>/>
      <?php if ($warn): ?><text class="warn-punkt <?= $warn['klasse'] ?>" x="<?= round($p['xy'][0] + $rk * 0.75, 2) ?>" y="<?= round($p['xy'][1] - $rk * 0.55, 2) ?>" font-size="<?= round($rp * ($warn['stufe'] >= 4 ? 1.25 : 1.0), 2) ?>" stroke-width="<?= round($rp * 0.25, 2) ?>">⚠&#xFE0E;</text><?php endif ?>
      <?php if ($zahl): ?><text class="zahl<?= $dunkel ?>" x="<?= round($p['xy'][0], 1) ?>" y="<?= round($p['xy'][1] + $rp * 0.36, 2) ?>" font-size="<?= round($rp * 1.05, 2) ?>"><?= zahl(round($n)) ?></text><?php endif ?>
      <?php if (isset($namen[$nr])): $rechts = $namen[$nr] === 'rechts'; ?><text class="name<?= $rechts ? '' : ' links' ?>" x="<?= round($p['xy'][0] + ($rechts ? 1 : -1) * $rp * 1.3, 2) ?>" y="<?= round($p['xy'][1] + $rp * 0.35, 2) ?>" font-size="<?= round($rp, 2) ?>" stroke-width="<?= round($rp * 0.3, 2) ?>"><?= h($r['name']) ?></text><?php endif ?>
    </a>
    <?php endforeach ?>
    <?= karte_orte_svg($orte, $rp) // feste Orte ueber den Regionspunkten, damit kein Name verdeckt wird ?>
    <?php
        // Maennchen (Animation seit 9.11.2026 wie im Wind-Modus, lib/maennchen.js): zeigt auf die
        // beste Region mit Pulvertag (bei "alle Tage" die mit dem meisten Neuschnee am besten
        // Pulvertag, sonst am gewaehlten Tag), steigt in den Bus mit Ski auf dem Dach und faehrt
        // hin; am Ziel stecken Ski im Schnee. Ohne Pulvertag: umsehen und Schultern zucken.
        // Seit 13.11.2026 (Wunsch Tobias): Es faehrt nur zu Regionen unter MAENNCHEN_NEIN_AB
        // (Lawinenwarnstufe 4). Liegt die beste Region darueber, zeigt es erst hin, schuettelt den
        // Kopf ("Naa, Stufe 4!") und faehrt zur besten Region mit hoechstens Stufe 3 (oder zuckt
        // mit den Schultern). Die Rangliste aendert sich dadurch nicht.
        $ziel = null;
        $nein = null;
        foreach ($punkte as $p) {
            if (!$p['wert']['geht']) {
                continue;
            }
            $s = $stufeVon($p);
            if ($s >= MAENNCHEN_NEIN_AB) {
                if (!$nein || $p['wert']['rang'] > $nein['wert']['rang']) {
                    $nein = $p + ['stufe' => $s];
                }
            } elseif (!$ziel || $p['wert']['rang'] > $ziel['wert']['rang']) {
                $ziel = $p;
            }
        }
        if ($nein && $ziel && $ziel['wert']['rang'] >= $nein['wert']['rang']) {
            $nein = null;   // das Ziel ist ohnehin besser: kein Kopfschuetteln
        }
    ?>
    <?= maennchen_svg($heimXY, $rp, $ziel['xy'] ?? null, 'ski', $eigenerOrt ? 'Dein Standort' : 'Inning am Ammersee', false, null,
                      $nein ? ['xy' => $nein['xy'], 'text' => 'Naa, Stufe ' . $nein['stufe'] . '!'] : null) ?>
  </svg>
  <?= schneeskala_legende() ?>
  <?php if ($zellen): $rts = new DateTimeImmutable($raster['ts']); $stand = h(tag_kurz($rts->setTimezone($tzSeite)->format('Y-m-d'), $heute)) . ' ' . $rts->setTimezone($tzSeite)->format('G:i') . ' Uhr'; ?>
  <?php if ($hsZellen): ?><div class="hs-legende"><p class="leise klein landkarte-fuss">Fläche Schneehöhe:</p><?= schneehoehe_legende() ?></div><?php endif ?>
  <p class="feldwahl" id="feldWahl" hidden><span class="leise klein">Fläche</span>
    <button type="button" class="feldknopf" data-feld="neu" aria-pressed="true">Neuschnee</button><?php if ($hsZellen): ?>
    <button type="button" class="feldknopf" data-feld="hs" aria-pressed="false">Schneehöhe</button><?php endif ?>
    <button type="button" class="feldknopf" data-feld="aus" aria-pressed="false">aus</button></p>
  <p class="leise klein landkarte-fuss fuss-neu">Fläche: Neuschnee auf <?= zahl(SCHNEE_RASTER_HOEHE) ?> m<?= $kartenTag ? '' : ' im Zeitraum' ?>, Raster rund 25 km, ein Modell, Stand <?= $stand ?>. Grob: Täler und einzelne Kämme verschwimmen.</p>
  <?php if ($hsZellen): ?><p class="leise klein landkarte-fuss fuss-hs">Fläche: Schneehöhe im Modell <?= h(tag_kurz($hsTag, $heute)) ?> <?= SCHNEE_MORGEN ?> Uhr, Raster rund 25 km, ein Modell, Stand <?= $stand ?>. Kein Messwert: Das Modell rechnet je Zelle mit einer mittleren Geländehöhe und liegt im Gebirge oft deutlich unter den Messstationen. Für „wo liegt mehr, wo weniger“, nicht für Zentimeter.</p><?php endif ?>
  <?php endif ?>
  <p class="leise klein landkarte-fuss">Punkte, Farbe und Zahl: Neuschnee in cm auf Tourhöhe der Region<?= $kartenTag ? ' (24 h bis ' . SCHNEE_MORGEN . ' Uhr)' : ', alle Tage im Zeitraum zusammen' ?>. Ring: <?= $kartenTag ? 'Pulvertag' : 'mindestens ein Pulvertag' ?>. ⚠&#xFE0E; Lawinenwarnstufe ab 3 (orange) bzw. ab 4 (rot)<?= $kartenTag ? '' : ', heute oder morgen' ?>. Männchen: <?= $eigenerOrt ? 'dein Standort' : 'Inning' ?>, antippen: wohin fahr ma? Punkt antippen: Stunden der Region.<?= $fav ? '' : ' Kreis: ' . zahl($km) . ' km Luftlinie; der Umkreis zählt die Straße.' ?> Karte: Natural Earth; Relief: Mapzen Terrain Tiles (EU-DEM, Copernicus; SRTM und GMTED2010, USGS; ETOPO1, NOAA).</p>
</details>
<?php endif ?>

<?php if (!$zeiten): ?>
  <p class="karte">Noch keine Schneedaten. Der Sammler läuft zweimal am Tag, gegen 6:30 und 18:30 Uhr.</p>
<?php elseif (!$liste && $geteilt): ?>
  <p class="karte">Für <?= h($geteilt['name']) ?> gibt es gerade keine Vorhersagedaten. <a href="schnee.php">Zur Rangliste</a></p>
<?php elseif (!$liste && $fav): ?>
  <p class="karte">Für deine Regionen gibt es gerade keine Vorhersagedaten. <a href="<?= h(link_mit(['fav' => null])) ?>">Alle Regionen</a></p>
<?php elseif (!$liste): ?>
  <p class="karte">Keine Schnee-Region im Umkreis von <?= zahl($km) ?> km. <a href="<?= h(link_mit(['km' => UMKREISE[count(UMKREISE) - 1]])) ?>">Umkreis vergrößern</a></p>
<?php endif ?>

<?php if ($woche && $liste && !$geteilt): ?>
<div class="karte matrix-box">
  <table class="matrix">
    <thead>
      <tr>
        <th scope="col" class="m-name">Region</th>
        <?php foreach ($tage as $t): $dt = new DateTimeImmutable($t, $tzSeite); ?>
          <th scope="col"<?= $nach === $t ? ' class="m-aktiv"' : '' ?>><a href="<?= h(link_mit(['nach' => $nach === $t ? null : $t])) ?>"><span><?= h($t === $heute->format('Y-m-d') ? 'heute' : WOCHENTAGE[(int)$dt->format('w')]) ?></span><span><?= $dt->format('j.') ?></span></a></th>
        <?php endforeach ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($liste as $e): $r = $e['region']; ?>
        <tr>
          <th scope="row" class="m-name"><a href="<?= h(link_mit(['ansicht' => 'bald', 'nach' => null])) ?>#r-<?= h($r['id']) ?>"><?= h($r['name']) ?></a><span class="leise"><?= h(fahrzeit_text($e['fahrt'])) ?> Fahrt</span></th>
          <?php foreach ($tage as $t):
              $d = $e['tage'][$t];
              $vorbei = !empty($d['vorbei']);
              $offen = !$vorbei && !empty($d['offen']);
              $klasse = schnee_klasse($d);
              $n = $d['det']['neu24'] ?? null;
              $text = $vorbei ? '' : ($offen ? '?' : ($n === null ? '' : ($n < 0.5 ? ($d['det']['geht'] ? '0' : '') : zahl(round($n)))));
              $titel = $r['name'] . ', ' . tag_kurz($t, $heute) . ': ' . ($vorbei ? 'vorbei' : ($offen ? 'noch keine Vorhersage' : kachel_titel($d)));
          ?>
            <?php $warn = $vorbei ? null : lawine_warnung($lageVon[$r['id']][$t] ?? null); ?>
            <td class="<?= $klasse ?><?= $warn ? ' ' . $warn['klasse'] : '' ?>" title="<?= h(($warn ? '⚠ ' . $warn['text'] . ' · ' : '') . $titel) ?>"><a href="<?= h(stunden_link($r, $t)) ?>"><?= h($text) ?><?= $warn ? '<span class="warn">⚠&#xFE0E;</span>' : '' ?></a></td>
          <?php endforeach ?>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
</div>
<?php endif ?>

<?php foreach ($woche ? [] : $liste as $e):
    $r = $e['region'];
    $guteTage = array_filter($e['tage'], fn($t) => $t['det']['geht']);
    $kartenZiel = stunden_link($r, in_array($nach, $tage, true) ? $nach : null);
?>
  <article class="karte region beste-<?= beste_stufe($e) ?><?= $guteTage ? '' : ' still' ?>" id="r-<?= h($r['id']) ?>" data-ziel="<?= h($kartenZiel) ?>">
    <div class="region-kopf">
      <h2><a class="region-link" href="<?= h($kartenZiel) ?>"><?= h($r['name']) ?></a><?= $geteilt ? '' : fav_knopf_html($r, $fav) ?></h2>
      <?php if (!$geteilt): ?><span class="leise klein fahrzeit"><?= h(fahrzeit_text($e['fahrt'])) ?> Fahrt<span class="km">Tour ab <?= zahl((float)$r['hoehe_tour']) ?> m</span></span><?php endif ?>
    </div>
    <div class="tage" style="--n: <?= count($tage) ?>">
      <?php foreach ($tage as $t):
          $d = $e['tage'][$t];
          $lw = lawine_abzeichen($lageVon[$r['id']][$t] ?? null);
          // LWS-Zeile immer gleich hoch: ohne Bericht ein leises "LWS –"
          $lwZeile = $lw !== '' ? $lw : '<span class="lw lw-leer" title="' . h(lawine_freigegeben($r, $dienste) ? 'Kein Lagebericht für diesen Tag (heißt nicht „keine Gefahr“)' : 'Lawinenwarnstufe im Bericht des Warndiensts nachsehen') . '">LWS –</span>';
      ?>
        <?php if (!empty($d['vorbei'])): ?>
        <div class="tag leer" title="Für heute ist das Tourenfenster vorbei">
          <span class="t"><?= h(tag_kurz($t, $heute)) ?></span>
          <span class="w vorbei">vorbei</span><?= $lwZeile ?>
        </div>
        <?php elseif (!empty($d['offen'])): ?>
        <a class="tag leer" href="<?= h(stunden_link($r, $t)) ?>" title="Noch keine Vorhersage für diesen Tag">
          <span class="t"><?= h(tag_kurz($t, $heute)) ?></span>
          <span class="w vorbei">?</span>
        </a>
        <?php else: ?>
        <?php $warn = lawine_warnung($lageVon[$r['id']][$t] ?? null); ?>
        <a class="tag <?= schnee_klasse($d) ?><?= $nach === $t ? ' gewaehlt' : '' ?><?= $warn ? ' ' . $warn['klasse'] : '' ?>" href="<?= h(stunden_link($r, $t)) ?>"
           title="<?= h(kachel_titel($d)) ?>">
          <span class="t"><?= h(tag_kurz($t, $heute)) ?></span>
          <span class="himmel"><?= himmel_symbol($d['det']['wolken'], ($d['det']['neu_tag'] ?? 0) >= 2) ?? '–' ?><?= $d['det']['kamm_stark'] ? '<span class="kamm" title="Viel Wind am Kamm">💨</span>' : '' ?></span>
          <span class="zeile"><span class="k">Neu</span> <?= h(cm($d['det']['neu24'])) ?></span>
          <span class="zeile"><span class="k">Höhe</span> <?= h(cm($d['det']['hs'])) ?></span>
          <span class="zeile" title="<?= h('Auf ' . zahl((float)$r['hoehe_tour']) . ' m: tiefste in der Nacht (' . SCHNEE_NACHT_AB . '–' . SCHNEE_MORGEN . ' Uhr) / höchste am Tag (' . $d['det']['von'] . '–' . $d['det']['bis'] . ' Uhr)') ?>"><span class="k">Temp</span> <?= h(schnee_temp_text($d['det']) ?? '?') ?></span>
          <?php if ($fi = $d['det']['firn'] ?? null): ?><span class="firn<?= $fi['art'] === 'weich' ? ' weich' : '' ?>" title="<?= h($fi['text']) ?>"><?= h($fi['kurz']) ?></span><?php endif ?>
          <?= $lwZeile ?>
          <span class="chance"><?= h(spanne_text($d) ?? ($d['det']['geht'] ? 'Pulver' : '')) ?></span>
        </a>
        <?php endif ?>
      <?php endforeach ?>
    </div>
    <?php if ($guteTage): ?>
      <ul class="details klein">
        <?php foreach ($guteTage as $t => $d): ?>
          <li>
            <b><?= h(tag_kurz($t, $heute)) ?>:</b>
            <span>Pulvertag: <?= h(implode(', ', array_filter([cm($d['det']['neu72']) . ' in 72 h', schnee_himmel($d['det']['wolken']), $d['det']['kamm_stark'] ? 'viel Wind am Kamm' : null]))) ?> ·</span>
            <?php if ($warn = lawine_warnung($lageVon[$r['id']][$t] ?? null)): ?><span class="lawine-warnung <?= $warn['klasse'] ?>">⚠&#xFE0E; <?= h($warn['text']) ?></span><?php endif ?>
            <?php if (!$geteilt && ($ab = abfahrt_text($e, $t, $d))): ?><span class="abfahrt"><?= h($ab) ?> ·</span><?php endif ?>
            <a class="stunden-link" href="<?= h(stunden_link($r, $t)) ?>">Details ›</a>
            <?php if (teilenswert($d)): ?><button type="button" class="teilen" hidden
              data-titel="<?= h('Wohinfahrma: ' . $r['name'] . ', ' . tag_datum($t, $tzSeite)) ?>"
              data-text="<?= h(teilen_text($r, $t, $d, $tzSeite, $lageVon[$r['id']][$t] ?? null)) ?>"
              data-url="<?= h('schnee.php?' . http_build_query(['region' => $r['id'], 'nach' => $t])) ?>"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M12 3v12M7 8l5-5 5 5M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>Teilen</button><?php endif ?>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
    <?php $offene = array_keys(array_filter($e['tage'], fn($x) => !empty($x['offen']))); ?>
    <?php if (!$guteTage || $offene): ?>
      <p class="klein leise"><?= $guteTage ? '' : 'Kein passender Tag in diesem Zeitraum.' ?><?= $offene ? ' Für ' . h(implode(', ', array_map(fn($x) => tag_kurz($x, $heute), $offene))) . ' gibt es noch keine Vorhersage.' : '' ?></p>
    <?php endif ?>
    <?= $ansicht === 'bald' ? stationen_html(stationen_region($r, $messungen), $messnetze, $tzSeite, $heute) : '' ?>
    <?= $ansicht === 'bald' ? webcams_html($r, $schnee['webcams'], $messnetze, $messungen) : '' ?>
    <?= lawine_html($r, $lageVon[$r['id']] ?? [], $dienste, $tage, $lagen) ?>
    <?= maps_html($r) ?>
    <?php if (!empty($r['links'])): ?>
      <p class="links klein">Genauer:
        <?php foreach ($r['links'] as $i => $l): ?><?= $i ? ' · ' : '' ?><a href="<?= h($l['url']) ?>" rel="noopener"><?= h($l['name']) ?> ↗</a><?php endforeach ?>
      </p>
    <?php endif ?>
  </article>
<?php endforeach ?>

<footer class="fuss klein leise">
  <p><b>Rohwerte, nicht kalibriert.</b> Schneefall und Schneefallgrenze auf Tourhöhe aus dem Mittel mehrerer Modelle; im Gebirge können sie deutlich danebenliegen. Schneehöhe ist ein Modellwert. Umkreis nach Straßen-km, Fahrzeit ohne Verkehr, Pässe und Wintersperren; „≈“ heißt geschätzt.</p>
  <p><b>Lawinen:</b> <?= h(HAFTUNG) ?> Gezeigt wird ein gekürzter, aufbereiteter Auszug (Gefahrenstufe, Lawinenprobleme, Ausgabe- und Gültigkeitszeit); Warndreiecke und ihre Texte sind Hinweise von Wohinfahrma, nicht des Warndiensts. Lageberichte:
    <?php $i = 0; foreach ($quellen as $d): ?><?= $i++ ? ' · ' : '' ?><a href="<?= h($d['url_bericht']) ?>" rel="noopener"><?= h($d['name']) ?></a> (<?= h($d['lizenz']) ?>)<?php endforeach ?>.
    Ohne Freigabe nur ein Link: <?php $i = 0; foreach (array_diff_key($dienste, $quellen) as $d): ?><?= $i++ ? ', ' : '' ?><a href="<?= h($d['url_bericht']) ?>" rel="noopener"><?= h($d['kurz']) ?></a><?php endforeach ?><?= $i ? '' : '–' ?>.</p>
  <?php if ($fahrzeiten): ?><p>Fahrzeiten ab <?= h($fahrzeiten['start']['name'] ?? 'Startort') ?>: © <a href="https://openrouteservice.org/">openrouteservice.org</a> by HeiGIT, Kartendaten © <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-Mitwirkende.</p><?php endif ?>
  <p>Vorhersagen: <a href="https://open-meteo.com/">Weather data by Open-Meteo.com</a> (CC BY 4.0)</p>
  <?php foreach (array_intersect_key($messnetze, $messungen) as $n): ?><p><?= ($n['art'] ?? '') === 'snowgrid' ? 'Modellwerte' : 'Messwerte' ?>: <a href="<?= h($n['url']) ?>" rel="noopener"><?= h($n['name']) ?></a> (<?= h($n['lizenz']) ?>), <?= ($n['art'] ?? '') === 'snowgrid' ? 'Tageswert vom Vortag, kein Messwert' : 'Rohdaten, ungeprüft' ?>.</p><?php endforeach ?>
  <?php if (!empty($schnee['webcams']['stationen'])): ?><p>Webcams: verlinkt, nicht eingebunden; die meisten von <a href="https://www.foto-webcam.eu/" rel="noopener">foto-webcam.eu</a>, Entfernung Luftlinie zur Messstation.</p><?php endif ?>
  <?= design_schalter() ?>
  <?= maennchen_ton_schalter() ?>
  <p><a href="impressum.html">Impressum</a> · <a href="datenschutz.html">Datenschutz</a></p>
</footer>
</main>

<script>
<?php readfile(__DIR__ . '/lib/maennchen.js') ?>
// Flaeche der Karte (seit 9.11.2026 Neuschnee an/aus, seit 18.11.2026 Neuschnee, Schneehoehe
// oder aus), Standard Neuschnee, gemerkt; alter Wert "an" heisst Neuschnee
(function () {
  var k = document.getElementById('landkarte'), wahl = document.getElementById('feldWahl');
  if (!k || !wahl) { return; }
  var SCHLUESSEL = 'wohinfahrma_schneefeld';
  var knoepfe = wahl.querySelectorAll('button[data-feld]');
  function setzen(feld) {
    if (!wahl.querySelector('button[data-feld="' + feld + '"]')) { feld = 'neu'; }
    k.classList.toggle('ohne-feld', feld === 'aus');
    k.classList.toggle('feld-hs-an', feld === 'hs');
    knoepfe.forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.feld === feld ? 'true' : 'false'); });
    return feld;
  }
  var feld = 'neu';
  try { var g = localStorage.getItem(SCHLUESSEL); feld = g === 'aus' ? 'aus' : (g === 'hs' ? 'hs' : 'neu'); } catch (e) {}
  feld = setzen(feld);
  wahl.hidden = false;
  knoepfe.forEach(function (b) {
    b.addEventListener('click', function () {
      feld = setzen(b.dataset.feld);
      try { localStorage.setItem(SCHLUESSEL, feld === 'neu' ? 'an' : feld); } catch (e) {}
    });
  });
})();
// Teilen: Web Share (Handy) oder in die Zwischenablage; ohne beides bleibt der Knopf verborgen (wie index.php)
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
// Deine Regionen (seit 13.11.2026, lib/favoriten.php)
<?= fav_skript(FAV_SCHLUESSEL, 'schnee.php') ?>

// Regionskarte antippen: Stundenseite; Links, Knoepfe und Textauswahl behalten ihre Wirkung
document.addEventListener('click', function (e) {
  var karte = e.target.closest && e.target.closest('.region[data-ziel]');
  if (!karte || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) { return; }
  if (e.target.closest('a, button, summary, details, input, select, label')) { return; }
  var sel = window.getSelection && getSelection();
  if (sel && String(sel).length > 0) { return; }
  location.href = karte.dataset.ziel;
});
// Auswahl sofort anwenden; Standort auf 0,1 Grad gerundet (wie index.php)
(function () {
  var form = document.getElementById('wahl');
  if (!form) { return; }
  form.querySelectorAll('select').forEach(function (s) { s.addEventListener('change', function () { form.submit(); }); });
  var knopf = document.getElementById('standort');
  if (!knopf || !navigator.geolocation) { if (knopf) { knopf.hidden = true; } return; }
  knopf.addEventListener('click', function () {
    navigator.geolocation.getCurrentPosition(function (p) {
      var ort = p.coords.latitude.toFixed(1) + ',' + p.coords.longitude.toFixed(1);
      var f = form.querySelector('input[name=ort]');
      if (!f) { f = document.createElement('input'); f.type = 'hidden'; f.name = 'ort'; form.appendChild(f); }
      f.value = ort;
      form.submit();
    }, function () {}, { maximumAge: 600000, timeout: 10000 });
  });
})();
</script>
</body>
</html>
