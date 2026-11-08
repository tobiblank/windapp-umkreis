<?php
/**
 * Wohinfahrma - Schnee-Modus (seit 8.11.2026): Rangliste der Schnee-Regionen in den
 * Alpen fuer Freeride und Skitour, mit der Lawinenlage der Warndienste daneben.
 *
 * Parameter (alle optional):
 *   sport   freeride | skitour (SCHNEE_SPORTARTEN, lib/schnee.php)
 *   km      Umkreis in km Strasse
 *   ort     "Breite,Laenge", auf 0,1 Grad gerundet
 *   ansicht bald (heute bis uebermorgen), reise (Tag 3 bis 7), woche
 *   nach    summe | YYYY-MM-DD | km
 *   region + nach=YYYY-MM-DD: geteilte Vorhersage einer Region
 *
 * Die Lawinenwarnstufe geht nie in Bewertung, Sortierung oder Farbe ein.
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

const TZ_SEITE = 'Europe/Berlin';
const HEIM_STANDARD = [48.07, 11.15];      // Inning am Ammersee
const UMKREISE = [100, 200, 300, 400, 600, 800];
const WOCHENTAGE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
const HAFTUNG = 'Die Lawinenlage stammt vom Warndienst. Sie ersetzt keine Beurteilung vor Ort. Wohinfahrma bewertet nur Schnee und Wetter.';

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
       . '<title>Wohinfahrma – Schnee</title><link rel="icon" href="favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="stil.css"><main class="seite"><h1 class="marke">Wohin<span>fahrma</span></h1>'
       . '<p class="karte">' . h($text) . ' <a href="./">Zum Wind-Modus</a></p></main>';
    exit;
}

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
$sportId = isset($_GET['sport']) && isset(SCHNEE_SPORTARTEN[$_GET['sport']]) ? (string)$_GET['sport'] : 'freeride';
$sport = SCHNEE_SPORTARTEN[$sportId];
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

// Geteilte Vorhersage: ?sport=..&region=<id>&nach=<YYYY-MM-DD>
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
$imUmkreis = $geteilt ? [$geteilt['id']] : array_column(array_filter($regionen, fn($r) => entfernung_km($heim, $r['punkt']) <= $km), 'id');
$stand = daten_laden($dataDir, ['schnee_vorhersage', 'schnee_ensemble'], nur: $imUmkreis);
$liste = $geteilt
    ? schnee_rangliste([$geteilt], $stand, $sport, $heim, INF, $tage, $jetzt)
    : schnee_rangliste($regionen, $stand, $sport, $heim, (float)$km, $tage, $jetzt);
$lagen = lawine_laden($dataDir);

$nach = (string)($_GET['nach'] ?? 'summe');
if ($nach !== 'km' && !in_array($nach, $tage, true)) {
    $nach = 'summe';
}
$fahrzeiten = fahrzeiten_laden($dataDir);
$alleRegionen = array_merge($windRegionen, $regionen);
$fahrtModell = fahrt_modell($fahrzeiten, $alleRegionen);
$fahrtUmweg = fahrt_umweg($fahrzeiten, $alleRegionen);
foreach ($liste as $i => $e) {
    $liste[$i]['fahrt'] = fahrzeit($e['region'], $heim, (float)$e['km'], $fahrzeiten, $fahrtModell, $fahrtUmweg);
}
if (!$geteilt) {
    $liste = array_values(array_filter($liste, fn($e) => im_umkreis($e, (float)$km)));
}
$liste = schnee_sortieren($liste, $nach);

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

/** Stufe einer Chance in der Blau-Skala des Schnee-Modus */
function s_stufe(?float $chance): string
{
    if ($chance === null) {
        return 'leer';
    }
    return $chance >= 0.7 ? 's-go' : ($chance >= 0.4 ? 's-maybe' : ($chance >= 0.15 ? 's-no' : 'nix'));
}

function beste_stufe(array $e): string
{
    $best = null;
    $geht = false;
    foreach ($e['tage'] as $d) {
        if (!empty($d['vorbei'])) {
            continue;
        }
        if (($d['ens']['chance'] ?? null) !== null) {
            $best = max($best ?? 0.0, $d['ens']['chance']);
        }
        $geht = $geht || $d['det']['geht'];
    }
    return $best === null ? ($geht ? 's-go' : 'nix') : s_stufe($best);
}

function link_mit(array $aenderung): string
{
    global $sportId, $km, $ansicht, $heim, $eigenerOrt, $nach;
    $p = ['sport' => $sportId, 'km' => $km, 'ansicht' => $ansicht];
    if ($nach !== 'summe') {
        $p['nach'] = $nach;
    }
    if ($eigenerOrt) {
        $p['ort'] = $heim[0] . ',' . $heim[1];
    }
    return 'schnee.php?' . http_build_query(array_merge($p, $aenderung));
}

function stunden_link(array $region, ?string $tag = null): string
{
    global $sportId;
    return 'schnee_stunden.php?' . http_build_query(array_filter(['region' => $region['id'], 'sport' => $sportId, 'tag' => $tag]));
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

/** ☀ / ⛅ / ☁ nach der Bewoelkung im Fenster, null ohne Wert */
function himmel_symbol(?float $wolken): ?string
{
    return match (schnee_himmel($wolken)) { 'sonnig' => '☀', 'teils sonnig' => '⛅', 'bewölkt' => '☁', default => null };
}

/** Kennwerte eines Tages fuer die Detailzeile */
function schnee_text(array $d, array $sport): array
{
    $k = $d['det'];
    $t = [];
    $t[] = 'Neuschnee 72 h ' . cm($k['neu72']) . ($k['grenze'] !== null && ($k['neu72'] ?? 0) >= 1 ? ' (Schneefallgrenze bis ' . meter($k['grenze']) . ')' : '');
    if (($k['neu_tag'] ?? 0) >= 1) {
        $t[] = 'am Tag ' . cm($k['neu_tag']);
    }
    if ($k['wolken'] !== null) {
        $t[] = schnee_himmel($k['wolken']) . ' (' . zahl($k['wolken']) . ' % Wolken)';
    }
    if ($k['kamm'] !== null) {
        $t[] = 'Wind am Kamm ' . zahl($k['kamm']) . ' km/h';
    }
    if ($k['nullgrad'] !== null) {
        $t[] = 'Nullgrad ' . meter($k['nullgrad']);
    }
    if ($k['hs'] !== null) {
        $t[] = 'Schneehöhe ≈ ' . cm($k['hs']) . ' (Modell)';
    }
    // Schneehoehe zuerst: sie gilt jeden Tag, Neuschnee nur, wenn welcher faellt
    if ($k['hs'] !== null) {
        array_unshift($t, array_pop($t));
    }
    return $t;
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
    $c = $d['ens']['chance'] ?? null;
    return $d['det']['geht'] && empty($d['vorbei']) && ($c === null || $c >= 0.4);
}

function teilen_text(array $region, string $tag, array $d, array $sport, DateTimeZone $tz): string
{
    $k = $d['det'];
    $teile = ['Neuschnee ' . cm($k['neu72']), schnee_himmel($k['wolken'])];
    if (($d['ens']['chance'] ?? null) !== null) {
        $teile[] = zahl(100 * $d['ens']['chance']) . ' % Chance';
    }
    return $region['name'] . ', ' . tag_datum($tag, $tz) . ': ' . implode(' · ', array_filter($teile)) . ' (' . $sport['name'] . '). ' . HAFTUNG;
}

/** Ausgabezeit eines Berichts: "So 17:00" */
function ausgabe_text(string $iso, DateTimeZone $tz, DateTimeImmutable $heute): string
{
    $t = strtotime($iso);
    if ($t === false) {
        return '';
    }
    $d = (new DateTimeImmutable('@' . $t))->setTimezone($tz);
    return ($d->format('Y-m-d') === $heute->format('Y-m-d') ? 'heute' : WOCHENTAGE[(int)$d->format('w')]) . ' ' . $d->format('G:i');
}

/** Lawinen-Abzeichen fuer eine Kachel oder null */
function lawine_abzeichen(?array $lage): string
{
    if (!$lage) {
        return '';
    }
    $s = $lage['stufe'];
    return '<span class="lw lw' . $s . '" title="' . h('Lawinengefahr: ' . lawine_stufen_text($lage['teile'])) . '">' . ($s ?: '–') . '</span>';
}

/** Block mit der Lawinenlage fuer heute und morgen (soweit angezeigt) */
function lawine_html(array $region, array $lagenTage, array $dienste, array $tage): string
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
    $o = '<div class="lawine klein">';
    foreach ($kurze as $t) {
        $l = $lagenTage[$t] ?? null;
        if (!$l) {
            $o .= '<p><b>' . h(tag_kurz($t, $heute)) . ':</b> <span class="leise">kein Lagebericht (heißt nicht „keine Gefahr“)</span> · ' . $link . '</p>';
            continue;
        }
        $probleme = array_map(fn($p) => h(lawine_problem_text($p)) . ($p['aspekte'] && count($p['aspekte']) < 8 ? ' ' . lawine_rose($p['aspekte']) : ''), $l['probleme']);
        $o .= '<p><b>' . h(tag_kurz($t, $heute)) . ':</b> ' . lawine_abzeichen($l) . ' ' . h(lawine_stufen_text($l['teile']))
            . ($probleme ? ' · ' . implode(' · ', $probleme) : '')
            . ' · ' . $link . ' <span class="leise">ausgegeben ' . h(ausgabe_text($l['veroeffentlicht'], $tzSeite, $heute)) . '</span>'
            . ($l['ueber_namen'] ? ' <span class="leise" title="' . h('Mikroregionen über den Namen zugeordnet: ' . implode(', ', array_map(fn($r) => $r[1], $l['regionen']))) . '">(Zuordnung über den Namen)</span>' : '')
            . '</p>';
    }
    return $o . '<p class="leise">' . h(HAFTUNG) . '</p></div>';
}

// Lage je Region und Tag (nur fuer freigegebene Dienste; heute und morgen)
$lageVon = [];
foreach ($liste as $e) {
    foreach ($tage as $t) {
        $lageVon[$e['region']['id']][$t] = lawine_freigegeben($e['region'], $dienste) ? lawine_lage($e['region'], $lagen, $t) : null;
    }
}

$schwelle = isset($sport['neu_min']) ? 'ab ' . $sport['neu_min'] . ' cm Neuschnee in 72 h' : 'ab ' . $sport['hoehe_min'] . ' cm Schnee (Modell)';
$schwelle .= ', bis ' . $sport['wolken_max'] . ' % Wolken, Wind am Kamm bis ' . $sport['kamm_max'] . ' km/h';
$quellen = array_filter($dienste, fn($d) => $d['freigegeben']);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Wohinfahrma – Wo liegt Schnee? Fahr ma.</title>
<meta name="description" content="Wo gibt es in den nächsten Tagen frischen Schnee und gutes Wetter für Skitouren und Freeride in den Alpen? Rangliste nach Vorhersage und Chance, mit der Lawinenlage der Warndienste.">
<link rel="stylesheet" href="stil.css">
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
  Geteilte Vorhersage<?= $geteiltTag ? ' für ' . h(tag_datum($geteiltTag, $tzSeite)) : '' ?>, <?= h($sport['name']) ?>, aktueller Stand.
  <?php if ($geteiltVorbei): ?>Der geteilte Tag (<?= h(tag_datum($geteiltVorbei, $tzSeite)) ?>) ist vorbei.<?php endif ?>
  <a href="schnee.php">Zur Rangliste</a>
</p>
<?php else: ?>
<form class="wahl karte steuer" method="get" id="wahl" action="schnee.php">
  <label>Sportart
    <select name="sport">
      <?php foreach (SCHNEE_SPORTARTEN as $id => $s): ?>
        <option value="<?= h($id) ?>"<?= $id === $sportId ? ' selected' : '' ?>><?= h($s['name']) ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <label>Umkreis
    <select name="km">
      <?php foreach (UMKREISE as $u): ?>
        <option value="<?= $u ?>"<?= $u === $km ? ' selected' : '' ?>><?= zahl($u) ?> km</option>
      <?php endforeach ?>
    </select>
  </label>
  <?php if (!$woche): ?>
  <label>Sortierung
    <select name="nach">
      <option value="summe"<?= $nach === 'summe' ? ' selected' : '' ?>>alle Tage</option>
      <?php foreach ($tage as $t): ?>
        <option value="<?= h($t) ?>"<?= $nach === $t ? ' selected' : '' ?>><?= h(tag_kurz($t, $heute)) ?></option>
      <?php endforeach ?>
      <option value="km"<?= $nach === 'km' ? ' selected' : '' ?>>Fahrzeit</option>
    </select>
  </label>
  <?php endif ?>
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
  <a href="<?= h(link_mit(['ansicht' => 'bald', 'nach' => null])) ?>"<?= !$woche ? ' aria-current="page"' : '' ?>>Liste</a>
  <a href="<?= h(link_mit(['ansicht' => 'woche', 'nach' => null])) ?>"<?= $woche ? ' aria-current="page"' : '' ?>>Woche</a>
</nav>
<?php if (!$woche): ?>
<nav class="zeitraum klein" aria-label="Zeitraum">
  <a href="<?= h(link_mit(['ansicht' => 'bald', 'nach' => null])) ?>"<?= $ansicht === 'bald' ? ' aria-current="page"' : '' ?>>Heute – Übermorgen</a>
  <a href="<?= h(link_mit(['ansicht' => 'reise', 'nach' => null])) ?>"<?= $ansicht === 'reise' ? ' aria-current="page"' : '' ?>>Tag 3 – 7</a>
</nav>
<?php endif ?>
<?php endif ?>

<p class="karte klein haftung"><b>Lawinen:</b> <?= h(HAFTUNG) ?> Die Gefahrenstufe geht nicht in Prozent, Farbe oder Reihenfolge ein.</p>

<details class="leise klein erklaerung">
  <summary><b><?= h($sport['name']) ?></b> · <?= h($schwelle) ?> · <span class="unterstrichen">Wie wird gerechnet?</span></summary>
  <p>
  Neuschnee: Niederschlag der letzten 72 Stunden bis <?= SCHNEE_MORGEN ?> Uhr, der auf Tourhöhe als Schnee fällt (Schneefallgrenze unter der Tourhöhe; 1 mm Wasser = <?= zahl(SCHNEE_CM_JE_MM, 1) ?> cm Schnee).
  Wetter von <?= SCHNEE_TAG[0] ?> bis <?= SCHNEE_TAG[1] ?> Uhr: Wolken, Wind am Kamm (700 hPa, etwa 3000 m), Nullgradgrenze.
  Prozent = Anteil der 51 Läufe des ECMWF-Ensembles, an denen der Tag nach denselben Regeln passt (ohne Wind am Kamm).
  Schneehöhe ist ein Modellwert und im Gebirge grob. Schwellen sind Startwerte, nicht kalibriert.
  </p>
  <p>Blau statt Grün: Ein gutes Feld heißt „guter Schnee“, nie „sicher“. Die Lawinenstufe steht in den offiziellen Farben daneben, nur für Tage mit Lagebericht.</p>
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
    $namen = karte_namen(array_map(fn($p) => ['xy' => $p['xy'], 'name' => $p['e']['region']['name'], 'rang' => $p['wert']['rang']], $punkte), $rp, [$vx, $vy, $vw, $vh]);
    $u = $rp * 0.7;
?>
<details class="karte landkarte" id="landkarte" open>
  <summary><b>Karte</b> <span class="leise klein"><?= $kartenTag ? 'Chance ' . h(tag_kurz($kartenTag, $heute)) : 'beste Chance im Zeitraum' ?></span></summary>
  <svg class="landkarte-bild" viewBox="<?= "$vx $vy $vw $vh" ?>" role="img" aria-label="Karte der Schnee-Regionen im Umkreis">
    <rect class="meer" x="<?= $vx ?>" y="<?= $vy ?>" width="<?= $vw ?>" height="<?= $vh ?>"/>
    <use href="karte.svg#land" class="land"/>
    <circle class="umkreis" cx="<?= round($heimXY[0], 1) ?>" cy="<?= round($heimXY[1], 1) ?>" r="<?= round(karte_km((float)$km, $heim[0]), 1) ?>"/>
    <?php foreach ($punkte as $nr => $p):
        $r = $p['e']['region'];
        $c = $p['wert']['chance'];
        $klasse = $c === null ? ($p['wert']['geht'] ? 's-go' : 'leer') : s_stufe($c);
        $zahl = $c !== null && $c >= 0.15;
        $rk = $zahl ? $rp : $rp * 0.55;
        $titel = $r['name'] . ': ' . ($c === null ? ($p['wert']['geht'] ? 'passt nach Modellmittel' : 'keine Ensemble-Daten') : zahl(100 * $c) . ' %')
               . ($p['wert']['tag'] && !$kartenTag ? ' (' . tag_kurz($p['wert']['tag'], $heute) . ')' : '')
               . ($p['wert']['neu'] !== null ? ' · Neuschnee ' . cm($p['wert']['neu']) : '') . ' · ' . fahrzeit_text($p['e']['fahrt']) . ' Fahrt';
    ?>
    <a href="<?= h(stunden_link($r, $p['wert']['tag'])) ?>" class="punkt <?= $klasse ?>">
      <title><?= h($titel) ?></title>
      <circle cx="<?= round($p['xy'][0], 1) ?>" cy="<?= round($p['xy'][1], 1) ?>" r="<?= round($rk, 2) ?>"/>
      <?php if ($zahl): ?><text class="zahl" x="<?= round($p['xy'][0], 1) ?>" y="<?= round($p['xy'][1] + $rp * 0.36, 2) ?>" font-size="<?= round($rp * 1.05, 2) ?>"><?= zahl(100 * $c) ?></text><?php endif ?>
      <?php if (isset($namen[$nr])): $rechts = $namen[$nr] === 'rechts'; ?><text class="name<?= $rechts ? '' : ' links' ?>" x="<?= round($p['xy'][0] + ($rechts ? 1 : -1) * $rp * 1.3, 2) ?>" y="<?= round($p['xy'][1] + $rp * 0.35, 2) ?>" font-size="<?= round($rp, 2) ?>" stroke-width="<?= round($rp * 0.3, 2) ?>"><?= h($r['name']) ?></text><?php endif ?>
    </a>
    <?php endforeach ?>
    <g class="heim" transform="translate(<?= round($heimXY[0], 1) ?> <?= round($heimXY[1], 1) ?>) scale(<?= round($u, 3) ?>)">
      <title><?= $eigenerOrt ? 'Dein Standort' : 'Inning am Ammersee' ?></title>
      <rect class="arm" x="-0.15" y="0" width="0.3" height="1.4" rx="0.15" transform="translate(-0.62 -2.2) rotate(12)"/>
      <rect class="arm" x="-0.15" y="0" width="0.3" height="1.4" rx="0.15" transform="translate(0.62 -2.2) rotate(-12)"/>
      <path d="M-0.75,-2.35H0.75V-1.1H0.55V0H0.13V-1H-0.13V0H-0.55V-1.1H-0.75Z"/>
      <circle cx="0" cy="-3.25" r="0.4"/>
    </g>
  </svg>
  <p class="leise klein landkarte-fuss">Zahl: Chance in Prozent<?= $kartenTag ? '' : ' am besten Tag' ?>. Punkt antippen: Stunden der Region. Kreis: <?= zahl($km) ?> km Luftlinie; der Umkreis zählt die Straße. Karte: Natural Earth.</p>
</details>
<?php endif ?>

<?php if (!$zeiten): ?>
  <p class="karte">Noch keine Schneedaten. Der Sammler läuft zweimal am Tag, gegen 6:30 und 18:30 Uhr.</p>
<?php elseif (!$liste && $geteilt): ?>
  <p class="karte">Für <?= h($geteilt['name']) ?> gibt es gerade keine Vorhersagedaten. <a href="schnee.php">Zur Rangliste</a></p>
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
              $c = $d['ens']['chance'] ?? null;
              $vorbei = !empty($d['vorbei']);
              $offen = !$vorbei && !empty($d['offen']);
              $klasse = $vorbei || $offen ? 'leer' : ($c === null ? ($d['det']['geht'] ? 's-go' : 'leer') : s_stufe($c));
              $text = $vorbei ? '' : ($offen ? '?' : ($c === null ? ($d['det']['geht'] ? 'ja' : '') : ($c < 0.005 ? '' : zahl(100 * $c))));
              $titel = $r['name'] . ', ' . tag_kurz($t, $heute) . ': ' . ($vorbei ? 'vorbei' : ($offen ? 'noch keine Vorhersage'
                     : ($c === null ? '' : zahl(100 * $c) . ' %, ') . 'Neuschnee ' . cm($d['det']['neu72'])));
          ?>
            <td class="<?= $klasse ?>" title="<?= h($titel) ?>"><a href="<?= h(stunden_link($r, $t)) ?>"><?= h($text) ?></a></td>
          <?php endforeach ?>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
</div>
<?php endif ?>

<?php foreach ($woche ? [] : $liste as $e):
    $r = $e['region'];
    $guteTage = array_filter($e['tage'], fn($t) => $t['det']['geht'] || (($t['ens']['chance'] ?? 0) >= 0.15));
    // Seit 8.11.2026 abends: jeder Tag mit Vorhersage bekommt eine Zeile (Schneehoehe, Wind, Wetter), nicht nur gute
    $zeigeTage = array_filter($e['tage'], fn($t) => empty($t['vorbei']) && empty($t['offen']));
    $kartenZiel = stunden_link($r, in_array($nach, $tage, true) ? $nach : null);
?>
  <article class="karte region beste-<?= beste_stufe($e) ?><?= $guteTage ? '' : ' still' ?>" id="r-<?= h($r['id']) ?>" data-ziel="<?= h($kartenZiel) ?>">
    <div class="region-kopf">
      <h2><a class="region-link" href="<?= h($kartenZiel) ?>"><?= h($r['name']) ?></a></h2>
      <?php if (!$geteilt): ?><span class="leise klein fahrzeit"><?= h(fahrzeit_text($e['fahrt'])) ?> Fahrt<span class="km">Tour ab <?= zahl((float)$r['hoehe_tour']) ?> m</span></span><?php endif ?>
    </div>
    <div class="tage" style="--n: <?= count($tage) ?>">
      <?php foreach ($tage as $t):
          $d = $e['tage'][$t];
          $c = $d['ens']['chance'] ?? null;
          $lw = lawine_abzeichen($lageVon[$r['id']][$t] ?? null);
      ?>
        <?php if (!empty($d['vorbei'])): ?>
        <div class="tag leer" title="Für heute ist das Tourenfenster vorbei">
          <span class="t"><?= h(tag_kurz($t, $heute)) ?></span>
          <span class="w vorbei">vorbei</span><?= $lw ?>
        </div>
        <?php elseif (!empty($d['offen'])): ?>
        <a class="tag leer" href="<?= h(stunden_link($r, $t)) ?>" title="Noch keine Vorhersage für diesen Tag">
          <span class="t"><?= h(tag_kurz($t, $heute)) ?></span>
          <span class="w vorbei">?</span>
        </a>
        <?php else: ?>
        <a class="tag <?= $c === null ? ($d['det']['geht'] ? 's-go' : 'nix') : s_stufe($c) ?><?= $nach === $t ? ' gewaehlt' : '' ?>" href="<?= h(stunden_link($r, $t)) ?>"
           title="<?= h(($c === null ? 'keine Ensemble-Daten' : zahl(100 * $c) . ' % der Läufe') . ', Neuschnee 72 h ' . cm($d['det']['neu72'])) ?>">
          <span class="t"><?= h(tag_kurz($t, $heute)) ?></span>
          <span class="w"><?= $c === null ? ($d['det']['geht'] ? 'ja' : '–') : zahl(100 * $c) . '<small>%</small>' ?></span>
          <span class="n">❄ <?= h(cm($d['det']['neu72'])) ?><?= ($sy = himmel_symbol($d['det']['wolken'])) ? ' ' . $sy : '' ?></span><?= $lw ?>
        </a>
        <?php endif ?>
      <?php endforeach ?>
    </div>
    <?php if ($zeigeTage): ?>
      <ul class="details klein">
        <?php foreach ($zeigeTage as $t => $d): $gut = isset($guteTage[$t]); ?>
          <li<?= $gut ? '' : ' class="leise"' ?>>
            <b><?= h(tag_kurz($t, $heute)) ?>:</b>
            <?= h(implode(' · ', schnee_text($d, $sport))) ?>
            <?php if (!$gut): ?>· passt nicht: <?= h($d['det']['grund'] ?? '') ?>
            <?php elseif (!$d['det']['geht']): ?><span class="leise">· Modellmittel: <?= h($d['det']['grund']) ?>, nur ein Teil der Ensemble-Läufe passt</span><?php endif ?>
            <?php if (!$geteilt && ($ab = abfahrt_text($e, $t, $d))): ?><span class="abfahrt">· <?= h($ab) ?></span><?php endif ?>
            <a class="stunden-link" href="<?= h(stunden_link($r, $t)) ?>">· Stunden ›</a>
            <?php if (teilenswert($d)): ?><button type="button" class="teilen" hidden
              data-titel="<?= h('Wohinfahrma: ' . $r['name'] . ', ' . tag_datum($t, $tzSeite)) ?>"
              data-text="<?= h(teilen_text($r, $t, $d, $sport, $tzSeite)) ?>"
              data-url="<?= h('schnee.php?' . http_build_query(['sport' => $sportId, 'region' => $r['id'], 'nach' => $t])) ?>"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M12 3v12M7 8l5-5 5 5M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>Teilen</button><?php endif ?>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
    <?php $offene = array_keys(array_filter($e['tage'], fn($x) => !empty($x['offen']))); ?>
    <?php if (!$guteTage || $offene): ?>
      <p class="klein leise"><?= $guteTage ? '' : 'Kein passender Tag in diesem Zeitraum.' ?><?= $offene ? ' Für ' . h(implode(', ', array_map(fn($x) => tag_kurz($x, $heute), $offene))) . ' gibt es noch keine Vorhersage.' : '' ?></p>
    <?php endif ?>
    <?= lawine_html($r, $lageVon[$r['id']] ?? [], $dienste, $tage) ?>
    <?php if (!empty($r['links'])): ?>
      <p class="links klein">Genauer:
        <?php foreach ($r['links'] as $i => $l): ?><?= $i ? ' · ' : '' ?><a href="<?= h($l['url']) ?>" rel="noopener"><?= h($l['name']) ?> ↗</a><?php endforeach ?>
      </p>
    <?php endif ?>
  </article>
<?php endforeach ?>

<footer class="fuss klein leise">
  <p><b>Rohwerte, nicht kalibriert.</b> Schneefall und Schneefallgrenze auf Tourhöhe aus dem Mittel mehrerer Modelle; im Gebirge können sie deutlich danebenliegen. Schneehöhe ist ein Modellwert. Umkreis nach Straßen-km, Fahrzeit ohne Verkehr, Pässe und Wintersperren; „≈“ heißt geschätzt.</p>
  <p><b>Lawinen:</b> <?= h(HAFTUNG) ?> Lageberichte:
    <?php $i = 0; foreach ($quellen as $d): ?><?= $i++ ? ' · ' : '' ?><a href="<?= h($d['url_bericht']) ?>" rel="noopener"><?= h($d['name']) ?></a> (<?= h($d['lizenz']) ?>)<?php endforeach ?>.
    Ohne Freigabe nur ein Link: <?php $i = 0; foreach (array_diff_key($dienste, $quellen) as $d): ?><?= $i++ ? ', ' : '' ?><a href="<?= h($d['url_bericht']) ?>" rel="noopener"><?= h($d['kurz']) ?></a><?php endforeach ?><?= $i ? '' : '–' ?>.</p>
  <?php if ($fahrzeiten): ?><p>Fahrzeiten ab <?= h($fahrzeiten['start']['name'] ?? 'Startort') ?>: © <a href="https://openrouteservice.org/">openrouteservice.org</a> by HeiGIT, Kartendaten © <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-Mitwirkende.</p><?php endif ?>
  <p>Vorhersagen: <a href="https://open-meteo.com/">Weather data by Open-Meteo.com</a> (CC BY 4.0)</p>
  <?= design_schalter() ?>
  <p><a href="impressum.html">Impressum</a> · <a href="datenschutz.html">Datenschutz</a></p>
</footer>
</main>

<script>
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
