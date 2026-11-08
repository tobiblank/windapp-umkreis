<?php
/**
 * Wohinfahrma - Stunden einer Schnee-Region (seit 8.11.2026)
 *   schnee_stunden.php?region=<id>&sport=freeride|skitour&tag=YYYY-MM-DD
 *
 * Zeigt die 72 Stunden bis SCHNEE_MORGEN Uhr des Tages und den Tag selbst:
 * Neuschnee je Stunde (Balken), Schneefallgrenze und Nullgradgrenze gegen Tour- und
 * Talhoehe, dazu Wetter im Tagesfenster, Streuung des Ensembles und die Lawinenlage.
 */

declare(strict_types=1);
date_default_timezone_set('UTC');
require __DIR__ . '/lib/regionen.php';
require __DIR__ . '/lib/bewertung.php';
require __DIR__ . '/lib/design.php';
require __DIR__ . '/lib/schnee.php';
require __DIR__ . '/lib/lawine.php';
require __DIR__ . '/lib/stunden.php';   // perzentil()

const TZ_SEITE = 'Europe/Berlin';
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

function fehlerseite(string $text, int $code = 404): never
{
    http_response_code($code);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
       . '<title>Wohinfahrma – Schnee</title><link rel="stylesheet" href="stil.css"><main class="seite"><h1 class="marke">Wohin<span>fahrma</span></h1><p class="karte">'
       . h($text) . ' <a href="schnee.php">Zur Rangliste</a></p></main>';
    exit;
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

function cm(?float $c): string
{
    return $c === null ? '?' : zahl(round($c)) . ' cm';
}

function meter(?float $m): string
{
    return $m === null ? '?' : zahl(round($m / 50) * 50) . ' m';
}

$cfg = @include __DIR__ . '/config.php';
if (!is_array($cfg) || empty($cfg['data_dir'])) {
    fehlerseite('Die App ist noch nicht eingerichtet (config.php fehlt).', 503);
}
try {
    $schnee = schnee_katalog_laden($cfg['schneeregionen_datei'] ?? __DIR__ . '/lib/schneeregionen.json');
} catch (RuntimeException $e) {
    fehlerseite('Der Schnee-Katalog ist nicht lesbar.', 503);
}
$region = null;
foreach ($schnee['regionen'] as $r) {
    if ($r['id'] === ($_GET['region'] ?? null)) {
        $region = $r;
    }
}
if (!$region) {
    fehlerseite('Diese Region gibt es nicht.');
}
$sportId = isset($_GET['sport']) && isset(SCHNEE_SPORTARTEN[$_GET['sport']]) ? (string)$_GET['sport'] : 'freeride';
$sport = SCHNEE_SPORTARTEN[$sportId];
$tzSeite = new DateTimeZone(TZ_SEITE);
$heute = new DateTimeImmutable('today', $tzSeite);
$tage = [];
for ($i = 0; $i <= 6; $i++) {
    $tage[] = $heute->modify("+$i day")->format('Y-m-d');
}
$dataDir = rtrim($cfg['data_dir'], '/');
$stand = daten_laden($dataDir, ['schnee_vorhersage', 'schnee_ensemble'], nur: [$region['id']]);
$daten = ['vorhersage' => $stand['schnee_vorhersage'][$region['id']] ?? null, 'ensemble' => $stand['schnee_ensemble'][$region['id']] ?? null];
$bew = schnee_region_bewerten($region, $daten, $sport, $tage);

// Tag: gewaehlt, sonst der beste (hoechste Chance, sonst Modellmittel)
$tag = in_array($_GET['tag'] ?? '', $tage, true) ? $_GET['tag'] : null;
if ($tag === null) {
    $best = -2.0;
    foreach ($bew as $t => $d) {
        $w = $d['vorbei'] ? -1.5 : ($d['ens']['chance'] ?? ($d['det']['geht'] ? 0.5 : -1.0));
        if ($w > $best) {
            $best = $w;
            $tag = $t;
        }
    }
}
$b = $bew[$tag];
$tz = new DateTimeZone($region['zeitzone']);
$st = schnee_stunden($daten['vorhersage'], $tz, (int)$region['hoehe_tour']);
$laeufe = schnee_ensemble_laeufe($daten['ensemble'], $tz, (int)$region['hoehe_tour']);
$neuEns = [];
foreach ($laeufe as $lauf) {
    $k = schnee_kennwerte($lauf, $tag, $b['fenster']);
    if ($k['neu72'] !== null) {
        $neuEns[] = $k['neu72'];
    }
}
$dienste = $schnee['lawinendienste'];
$lage = lawine_freigegeben($region, $dienste) ? lawine_lage($region, lawine_laden($dataDir), $tag) : null;
$dienst = $dienste[$region['warndienst']];

// Stunden des Diagramms: (tag - 3) ab SCHNEE_MORGEN bis tag 18 Uhr
$reihe = [];
for ($k = 3; $k >= 0; $k--) {
    $d = schnee_tag_minus($tag, $k);
    for ($s = 0; $s < 24; $s++) {
        if (($k === 3 && $s < SCHNEE_MORGEN) || ($k === 0 && $s > 18)) {
            continue;
        }
        $reihe[] = ['tag' => $d, 'h' => $s, 'w' => $st[$d][$s] ?? null];
    }
}

function link_mit(array $aenderung): string
{
    global $region, $sportId, $tag;
    return 'schnee_stunden.php?' . http_build_query(array_merge(['region' => $region['id'], 'sport' => $sportId, 'tag' => $tag], $aenderung));
}

/** Diagramm Neuschnee je Stunde und Hoehen (Schneefallgrenze, Nullgrad, Tour, Tal) */
function schnee_svg(array $reihe, array $region, string $tag, array $fenster): string
{
    $W = 360;
    $L = 34;
    $R = 6;
    $n = count($reihe);
    $bw = ($W - $L - $R) / max(1, $n);
    $r = fn(float $v) => round($v, 1);
    $x = fn(int $i) => $L + $i * $bw;
    $maxNeu = max(1.0, ...array_map(fn($z) => (float)($z['w']['neu'] ?? 0), $reihe));
    $maxNeu = ceil($maxNeu);
    $oben = 20;
    $hoehe = 70;
    $y = fn(float $v) => $oben + $hoehe - $v / $maxNeu * $hoehe;
    // Hoehen-Teil darunter
    $o2 = $oben + $hoehe + 34;
    $h2 = 90;
    $werte = array_filter(array_merge(array_column(array_column($reihe, 'w'), 'grenze'), array_column(array_column($reihe, 'w'), 'nullgrad')), fn($v) => $v !== null);
    $mMin = max(0, (int)(floor(min(array_merge($werte, [$region['hoehe_tal']])) / 500) * 500) - 0);
    $mMax = (int)(ceil(max(array_merge($werte, [$region['hoehe_tour'] + 300])) / 500) * 500);
    $mMax = min(max($mMax, $mMin + 1000), 5000);
    $y2 = fn(float $m) => $o2 + $h2 - (min(max($m, $mMin), $mMax) - $mMin) / ($mMax - $mMin) * $h2;
    $o = ['<svg class="stunden-bild" viewBox="0 0 ' . $W . ' ' . ($o2 + $h2 + 22) . '" role="img" aria-label="Neuschnee und Schneefallgrenze je Stunde">'];
    // Tagesfenster
    foreach ($reihe as $i => $z) {
        if ($z['tag'] === $tag && $z['h'] === $fenster[0]) {
            $o[] = '<rect class="s-fenster" x="' . $r($x($i)) . '" y="' . $oben . '" width="' . $r(($fenster[1] - $fenster[0]) * $bw) . '" height="' . ($o2 + $h2 - $oben) . '"><title>Tourenfenster ' . $fenster[0] . '–' . $fenster[1] . ' Uhr</title></rect>';
        }
    }
    foreach ([0, $maxNeu / 2, $maxNeu] as $k) {
        $o[] = '<line class="s-raster" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $r($y($k)) . '" y2="' . $r($y($k)) . '"/>';
        $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . $r($y($k) + 3) . '" text-anchor="end">' . zahl($k, $k < 2 && $k != 0 ? 1 : 0) . '</text>';
    }
    $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . ($oben - 9) . '" text-anchor="end">cm/h</text>';
    foreach ($reihe as $i => $z) {
        $v = (float)($z['w']['neu'] ?? 0);
        if ($v > 0.05) {
            $o[] = '<rect class="s-schnee" x="' . $r($x($i) + 0.3) . '" y="' . $r($y($v)) . '" width="' . $r(max(0.6, $bw - 0.6)) . '" height="' . $r($oben + $hoehe - $y($v)) . '"><title>'
                 . h($z['tag'] . ' ' . $z['h'] . ' Uhr: ' . zahl($v, 1) . ' cm') . '</title></rect>';
        }
        if ($z['h'] === 0) {
            $d = new DateTimeImmutable($z['tag']);
            $o[] = '<line class="s-raster" x1="' . $r($x($i)) . '" x2="' . $r($x($i)) . '" y1="' . $oben . '" y2="' . ($o2 + $h2) . '"/>';
            $o[] = '<text class="s-achse" x="' . $r($x($i) + 2) . '" y="' . ($o2 + $h2 + 12) . '">' . WOCHENTAGE[(int)$d->format('w')] . ' ' . $d->format('j.') . '</text>';
        }
    }
    // Hoehen
    foreach ([$mMin, ($mMin + $mMax) / 2, $mMax] as $k) {
        $o[] = '<line class="s-raster" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $r($y2($k)) . '" y2="' . $r($y2($k)) . '"/>';
        $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . $r($y2($k) + 3) . '" text-anchor="end">' . zahl($k) . '</text>';
    }
    $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . ($o2 - 9) . '" text-anchor="end">m</text>';
    // Bergsilhouette (seit 8.11.2026): feste Kammlinie vom Talort bis etwas ueber die Tourhoehe,
    // nur zur Orientierung, kein echtes Gelaende. Darueber die Linien.
    $gipfel = min($mMax, (float)$region['hoehe_tour'] + 600);
    $tal = max($mMin, (float)$region['hoehe_tal']);
    $kamm = [[0, 0], [.08, .35], [.14, .18], [.22, .7], [.28, .45], [.36, .86], [.42, .58], [.5, 1.0], [.56, .7], [.63, .88],
             [.7, .38], [.77, .62], [.84, .28], [.91, .5], [1, .05]];
    $pb = [$r($L) . ',' . $r($o2 + $h2)];
    foreach ($kamm as [$fx, $fy]) {
        $pb[] = $r($L + $fx * ($W - $L - $R)) . ',' . $r($y2($tal + $fy * ($gipfel - $tal)));
    }
    $pb[] = $r($W - $R) . ',' . $r($o2 + $h2);
    $o[] = '<polygon class="s-berg" points="' . implode(' ', $pb) . '"><title>Berg nur zur Orientierung, kein echtes Gelände</title></polygon>';
    foreach (['hoehe_tour' => 'Tourhöhe', 'hoehe_tal' => 'Talort'] as $f => $name) {
        $o[] = '<line class="s-tour" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $r($y2((float)$region[$f])) . '" y2="' . $r($y2((float)$region[$f])) . '"><title>' . $name . ' ' . zahl((float)$region[$f]) . ' m</title></line>';
    }
    foreach (['grenze' => ['s-sfg', 'Schneefallgrenze'], 'nullgrad' => ['s-null', 'Nullgradgrenze']] as $k => [$klasse, $titel]) {
        $p = [];
        foreach ($reihe as $i => $z) {
            if (($z['w'][$k] ?? null) !== null) {
                $p[] = $r($x($i) + $bw / 2) . ',' . $r($y2((float)$z['w'][$k]));
            }
        }
        if (count($p) > 1) {
            $o[] = '<polyline class="' . $klasse . '" points="' . implode(' ', $p) . '"><title>' . $titel . '</title></polyline>';
        }
    }
    $o[] = '</svg>';
    return implode('', $o);
}

$k = $b['det'];
$c = $b['ens']['chance'] ?? null;
sort($neuEns);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($region['name']) ?> – Schnee – Wohinfahrma</title>
<link rel="stylesheet" href="stil.css">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<?= design_kopf() ?>
</head>
<body>
<main class="seite">
<header class="kopf">
  <a href="schnee.php?sport=<?= h($sportId) ?>" class="logozeile" style="text-decoration:none;color:inherit">
    <svg class="logo" viewBox="0 0 100 100" width="40" height="40" aria-hidden="true"><path class="logo-marke" d="M50 7C31.2 7 17 21.6 17 40.5C17 64 50 93 50 93S83 64 83 40.5C83 21.6 68.8 7 50 7Z"/><path class="logo-fahne" d="M31 36H57a7 7 0 1 0 -7 -7M31 48H65a7 7 0 1 1 -7 7"/></svg>
    <div>
      <h1 class="marke">Wohin<span>fahrma</span></h1>
      <p class="leise klein">← zur Schnee-Rangliste</p>
    </div>
  </a>
  <div class="rechts klein leise"><?= h($sport['name']) ?><br>Tour ab <?= zahl((float)$region['hoehe_tour']) ?> m</div>
</header>

<nav class="sortierung klein" aria-label="Tag">
  <?php foreach ($tage as $t): $d = $bew[$t]; $ct = $d['ens']['chance'] ?? null; ?>
    <a href="<?= h(link_mit(['tag' => $t])) ?>"<?= $t === $tag ? ' aria-current="true"' : '' ?>><?= h(tag_kurz($t, $heute)) ?><?= $d['vorbei'] ? '' : ($ct !== null ? ' ' . zahl(100 * $ct) . ' %' : ($d['det']['geht'] ? ' ja' : '')) ?></a>
  <?php endforeach ?>
</nav>

<section class="karte stunden-region">
  <div class="region-kopf"><h2><?= h($region['name']) ?></h2>
    <span class="leise klein fahrzeit"><?= $c === null ? '' : zahl(100 * $c) . ' % Chance' ?><span class="km"><?= h(tag_kurz($tag, $heute)) ?></span></span></div>
  <?php if (!$daten['vorhersage'] && !$daten['ensemble']): ?>
    <p class="leise">Für diese Region gibt es gerade keine Vorhersagedaten.</p>
  <?php else: ?>
  <p class="klein"><?= $b['vorbei'] ? 'Für heute ist das Tourenfenster vorbei.' : ($k['geht'] ? 'Passt für ' . h($sport['name']) . ' nach Modellmittel.' : '<span class="leise">Modellmittel: ' . h($k['grund']) . '.</span>') ?></p>
  <p class="klein">Neuschnee 72 h bis <?= SCHNEE_MORGEN ?> Uhr: <b><?= h(cm($k['neu72'])) ?></b>
    <?php if ($neuEns): ?><span class="leise">(Ensemble: Mitte <?= h(cm($neuEns[intdiv(count($neuEns), 2)])) ?>, 80 % der Läufe <?= h(cm(perzentil($neuEns, 0.1))) ?>–<?= h(cm(perzentil($neuEns, 0.9))) ?>)</span><?php endif ?>
    <?php if ($k['grenze'] !== null): ?> · Schneefallgrenze bis <?= h(meter($k['grenze'])) ?><?php endif ?></p>
  <p class="klein leise"><?= SCHNEE_TAG[0] ?>–<?= SCHNEE_TAG[1] ?> Uhr: <?= h(implode(' · ', array_filter([
      $k['wolken'] !== null ? schnee_himmel($k['wolken']) . ' (' . zahl($k['wolken']) . ' % Wolken)' : null,
      $k['kamm'] !== null ? 'Wind am Kamm ' . zahl($k['kamm']) . ' km/h' : null,
      $k['boe'] !== null ? 'Böen im Tal bis ' . zahl($k['boe']) . ' km/h' : null,
      $k['nullgrad'] !== null ? 'Nullgrad bis ' . meter($k['nullgrad']) : null,
      $k['hs'] !== null ? 'Schneehöhe ≈ ' . cm($k['hs']) . ' (Modell)' : null,
  ]))) ?></p>
  <p class="klein leise welle-titel">Oben: Neuschnee je Stunde auf Tourhöhe. Unten: Schneefallgrenze (durchgezogen), Nullgradgrenze (gestrichelt), Tourhöhe und Talort (gepunktet) vor einem Berg zur Orientierung. Grau: Tourenfenster.</p>
  <?= schnee_svg($reihe, $region, $tag, $b['fenster']) ?>
  <?php endif ?>
</section>

<section class="karte lawine-seite">
  <h2 class="klein">Lawinenlage <?= h(tag_kurz($tag, $heute)) ?></h2>
  <?php if (!lawine_freigegeben($region, $dienste)): ?>
    <p class="klein">Bitte im Lagebericht nachsehen: <a href="<?= h($dienst['url_bericht']) ?>" rel="noopener"><?= h($dienst['name']) ?> ↗</a> <span class="leise">(die Stufe zeigen wir hier, sobald die Nutzung geklärt ist)</span></p>
  <?php elseif (!$lage): ?>
    <p class="klein">Kein Lagebericht für diesen Tag. Das heißt nicht „keine Gefahr“. <a href="<?= h($dienst['url_bericht']) ?>" rel="noopener"><?= h($dienst['kurz']) ?> ↗</a></p>
  <?php else: ?>
    <p class="klein"><span class="lw lw<?= $lage['stufe'] ?>">Gefahrenstufe <?= $lage['stufe'] ?: '–' ?></span> <?= h(lawine_stufen_text($lage['teile'], true)) ?></p>
    <?php foreach ($lage['probleme'] as $p): ?>
      <p class="klein"><?= $p['aspekte'] && count($p['aspekte']) < 8 ? lawine_rose($p['aspekte'], 22) . ' ' : '' ?><?= h(lawine_problem_text($p)) ?></p>
    <?php endforeach ?>
    <p class="klein leise">Bericht: <a href="<?= h($dienst['url_bericht']) ?>" rel="noopener"><?= h($dienst['name']) ?> ↗</a> (<?= h($dienst['lizenz']) ?>), gültig für <?= h(implode(', ', array_unique(array_map(fn($r) => $r[1] ?: $r[0], $lage['regionen'])))) ?><?= $lage['ueber_namen'] ? ' (über den Namen zugeordnet)' : '' ?>.</p>
  <?php endif ?>
  <p class="klein leise"><?= h(HAFTUNG) ?></p>
</section>

<footer class="fuss klein leise">
  <p>Vorhersagen: <a href="https://open-meteo.com/">Weather data by Open-Meteo.com</a> (CC BY 4.0), Mittel mehrerer Modelle auf Tourhöhe, Chance aus dem ECMWF-Ensemble. Rohwerte, nicht kalibriert.</p>
  <?= design_schalter() ?>
  <p><a href="impressum.html">Impressum</a> · <a href="datenschutz.html">Datenschutz</a></p>
</footer>
</main>
</body>
</html>
