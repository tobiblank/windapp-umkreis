<?php
/**
 * Wohinfahrma - Stunden einer Schnee-Region (seit 8.11.2026)
 *   schnee_stunden.php?region=<id>&tag=YYYY-MM-DD   (sport= seit 9.11.2026 ohne Wirkung)
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
require __DIR__ . '/lib/maps.php';
require __DIR__ . '/lib/stunden.php';   // perzentil()

const TZ_SEITE = 'Europe/Berlin';
const WOCHENTAGE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
const HAFTUNG = LAWINE_HAFTUNG;

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
       . '<title>Wohinfahrma – Schnee</title><link rel="stylesheet" href="' . stil_url() . '"><main class="seite"><h1 class="marke">Wohin<span>fahrma</span></h1><p class="karte">'
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
$tzSeite = new DateTimeZone(TZ_SEITE);
$heute = new DateTimeImmutable('today', $tzSeite);
$tage = [];
for ($i = 0; $i <= 6; $i++) {
    $tage[] = $heute->modify("+$i day")->format('Y-m-d');
}
$dataDir = rtrim($cfg['data_dir'], '/');
$stand = daten_laden($dataDir, ['schnee_vorhersage', 'schnee_ensemble'], nur: [$region['id']]);
$daten = ['vorhersage' => $stand['schnee_vorhersage'][$region['id']] ?? null, 'ensemble' => $stand['schnee_ensemble'][$region['id']] ?? null];
$bew = schnee_region_bewerten($region, $daten, $tage);

// Tag: gewaehlt, sonst der beste Pulvertag, sonst der mit dem meisten Neuschnee (24 h)
$tag = in_array($_GET['tag'] ?? '', $tage, true) ? $_GET['tag'] : null;
if ($tag === null) {
    $best = -2.0;
    foreach ($bew as $t => $d) {
        $w = $d['vorbei'] ? -1.5 : ($d['det']['geht'] ? 1000.0 + $d['det']['pulver'] : (float)($d['det']['neu24'] ?? -1.0));
        if ($w > $best) {
            $best = $w;
            $tag = $t;
        }
    }
}
$b = $bew[$tag];
$tz = new DateTimeZone($region['zeitzone']);
$st = schnee_stunden($daten['vorhersage'], $tz, (int)$region['hoehe_tour']);
$dienste = $schnee['lawinendienste'];
$lagenAlle = lawine_freigegeben($region, $dienste) ? lawine_laden($dataDir) : [];
$lage = $lagenAlle ? lawine_lage($region, $lagenAlle, $tag) : null;
$stoerung = lawine_freigegeben($region, $dienste) ? lawine_stoerung($lagenAlle[$region['warndienst']] ?? null) : null;
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
    global $region, $tag;
    return 'schnee_stunden.php?' . http_build_query(array_merge(['region' => $region['id'], 'tag' => $tag], $aenderung));
}

/**
 * Diagramm (neu am 9.11.2026, vorher mit Bergsilhouette und vier Hoehenlinien):
 * oben Niederschlag je Stunde auf Tourhoehe, blau als Schnee, grau als Regen, in
 * Abschnitten von SCHNEE_MORGEN bis SCHNEE_MORGEN Uhr mit der Summe darueber (wie die
 * Tagesknoepfe); darunter nur die Schneefallgrenze gegen Tourhoehe und Talort.
 * Eine Zeitachse: Marken um SCHNEE_MORGEN Uhr.
 */
function schnee_svg(array $reihe, array $region, string $tag, array $fenster, ?string $jetzt): string
{
    $W = 360;
    $L = 38;
    $R = 8;
    $n = count($reihe);
    $bw = ($W - $L - $R) / max(1, $n);
    $r = fn(float $v) => round($v, 1);
    $x = fn(int $i) => $L + $i * $bw;
    $regen = fn(array $w) => ($w['neu'] ?? 0) < 0.05 && ($w['nied'] ?? 0) > 0.05 ? $w['nied'] * SCHNEE_CM_JE_MM : 0.0;
    $maxNeu = 1.0;
    foreach ($reihe as $z) {
        $maxNeu = max($maxNeu, (float)($z['w']['neu'] ?? 0), $regen($z['w'] ?? []));
    }
    $maxNeu = $maxNeu <= 2 ? ceil($maxNeu * 2) / 2 : ceil($maxNeu);
    $oben = 34;
    $hoehe = 74;
    $y = fn(float $v) => $oben + $hoehe - $v / $maxNeu * $hoehe;
    $o2 = $oben + $hoehe + 30;
    $h2 = 64;
    $tour = (float)$region['hoehe_tour'];
    $tal = (float)$region['hoehe_tal'];
    $gr = array_values(array_filter(array_map(fn($z) => $z['w']['grenze'] ?? null, $reihe), fn($v) => $v !== null));
    $mMin = (int)(floor((min(array_merge($gr, [$tal])) - 100) / 500) * 500);
    $mMax = (int)(ceil((max(array_merge($gr, [$tour])) + 200) / 500) * 500);
    $y2 = fn(float $m) => $o2 + $h2 - ($m - $mMin) / max(1, $mMax - $mMin) * $h2;
    $unten = $o2 + $h2;
    $o = ['<svg class="stunden-bild schnee-bild" viewBox="0 0 ' . $W . ' ' . ($unten + 18) . '" role="img" aria-label="Neuschnee und Regen je Stunde, Schneefallgrenze">'];
    // Tourenfenster
    foreach ($reihe as $i => $z) {
        if ($z['tag'] === $tag && $z['h'] === $fenster[0]) {
            $o[] = '<rect class="s-fenster" x="' . $r($x($i)) . '" y="' . ($oben - 4) . '" width="' . $r(($fenster[1] - $fenster[0]) * $bw) . '" height="' . ($unten - $oben + 4) . '"><title>Tourenfenster ' . $fenster[0] . '–' . $fenster[1] . ' Uhr</title></rect>';
        }
    }
    // Raster und Achse oben
    foreach ([0, $maxNeu] as $k) {
        $o[] = '<line class="s-raster" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $r($y($k)) . '" y2="' . $r($y($k)) . '"/>';
        $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . $r($y($k) + 3) . '" text-anchor="end">' . zahl($k, fmod($k, 1.0) != 0 ? 1 : 0) . '</text>';
    }
    $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . $r($y($maxNeu / 2) + 3) . '" text-anchor="end">cm/h</text>';
    // Abschnitte bis SCHNEE_MORGEN Uhr mit Summe darueber
    $grenzen = [];
    foreach ($reihe as $i => $z) {
        if ($z['h'] === SCHNEE_MORGEN) {
            $grenzen[] = $i;
        }
    }
    $grenzen[] = $n;
    for ($j = 0; $j < count($grenzen) - 1; $j++) {
        [$a, $b] = [$grenzen[$j], $grenzen[$j + 1]];
        $mitte = $r(($x($a) + $x($b)) / 2);
        $bisTag = $reihe[$b]['tag'] ?? null;
        $teil = array_slice($reihe, $a, $b - $a);
        $mitWert = array_filter($teil, fn($z) => $z['w'] !== null);
        $summe = $bisTag !== null && count($mitWert) < SCHNEE_MIN_STUNDEN_24 ? null : array_sum(array_map(fn($z) => (float)($z['w']['neu'] ?? 0), $teil));
        $o[] = '<text class="s-summe" x="' . $mitte . '" y="13" text-anchor="middle">' . ($summe === null ? '?' : zahl(round($summe))) . ' cm</text>';
        $o[] = '<text class="s-achse" x="' . $mitte . '" y="24" text-anchor="middle">' . ($bisTag === null ? 'am Tourtag' : 'bis ' . h(WOCHENTAGE[(int)(new DateTimeImmutable($bisTag))->format('w')]) . ' ' . SCHNEE_MORGEN . ' Uhr') . '</text>';
    }
    foreach ($grenzen as $i) {
        if ($i < $n) {
            $o[] = '<line class="s-trenn" x1="' . $r($x($i)) . '" x2="' . $r($x($i)) . '" y1="4" y2="' . $unten . '"/>';
            $d = new DateTimeImmutable($reihe[$i]['tag']);
            $o[] = '<text class="s-achse" x="' . $r($x($i)) . '" y="' . ($unten + 12) . '" text-anchor="' . ($i === 0 ? 'start' : 'middle') . '">' . WOCHENTAGE[(int)$d->format('w')] . ' ' . $d->format('j.') . '</text>';
        }
    }
    // Balken
    foreach ($reihe as $i => $z) {
        $v = (float)($z['w']['neu'] ?? 0);
        $rg = $regen($z['w'] ?? []);
        $zeit = WOCHENTAGE[(int)(new DateTimeImmutable($z['tag']))->format('w')] . ' ' . $z['h'] . ' Uhr: ';
        if ($v > 0.05) {
            $o[] = '<rect class="s-schnee" x="' . $r($x($i) + 0.3) . '" y="' . $r($y($v)) . '" width="' . $r(max(0.6, $bw - 0.6)) . '" height="' . $r($oben + $hoehe - $y($v)) . '"><title>'
                 . h($zeit . zahl($v, 1) . ' cm Schnee') . '</title></rect>';
        } elseif ($rg > 0.05) {
            $o[] = '<rect class="s-regen" x="' . $r($x($i) + 0.3) . '" y="' . $r($y($rg)) . '" width="' . $r(max(0.6, $bw - 0.6)) . '" height="' . $r($oben + $hoehe - $y($rg)) . '"><title>'
                 . h($zeit . zahl((float)$z['w']['nied'], 1) . ' mm Regen auf Tourhöhe') . '</title></rect>';
        }
        if ($jetzt !== null && $z['tag'] . ' ' . $z['h'] === $jetzt) {
            $o[] = '<line class="s-jetzt" x1="' . $r($x($i)) . '" x2="' . $r($x($i)) . '" y1="' . ($oben - 4) . '" y2="' . $unten . '"/>';
            $o[] = '<text class="s-achse s-jetzttext" x="' . $r($x($i) + 2) . '" y="' . ($oben + 6) . '">jetzt</text>';
        }
    }
    // Schneefallgrenze gegen Tourhoehe und Talort
    $o[] = '<text class="s-achse s-sfgtext" x="' . ($W - $R) . '" y="' . ($o2 - 6) . '" text-anchor="end">Schneefallgrenze</text>';
    foreach ([$mMin, $mMax] as $k) {
        $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . $r($y2($k) + 3) . '" text-anchor="end">' . zahl($k) . '</text>';
    }
    $o[] = '<line class="s-raster" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $r($unten) . '" y2="' . $r($unten) . '"/>';
    foreach ([[$tour, 'Tour'], [$tal, 'Tal']] as [$m, $name]) {
        $o[] = '<line class="s-tour" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $r($y2($m)) . '" y2="' . $r($y2($m)) . '"><title>' . $name . ' ' . zahl($m) . ' m</title></line>';
        $o[] = '<text class="s-achse s-hoehe" x="' . ($L + 3) . '" y="' . $r($y2($m) - 3) . '">' . $name . ' ' . zahl($m) . ' m</text>';
    }
    $p = [];
    foreach ($reihe as $i => $z) {
        if (($z['w']['grenze'] ?? null) !== null) {
            $p[] = $r($x($i) + $bw / 2) . ',' . $r($y2((float)$z['w']['grenze']));
        }
    }
    if (count($p) > 1) {
        $o[] = '<polyline class="s-sfg" points="' . implode(' ', $p) . '"><title>Schneefallgrenze</title></polyline>';
    }
    $o[] = '</svg>';
    return implode('', $o);
}

$k = $b['det'];
$e = $b['ens'];
$jetztOrt = new DateTimeImmutable('now', $tz);
$jetztStunde = $jetztOrt->format('Y-m-d') . ' ' . (int)$jetztOrt->format('G');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($region['name']) ?> – Schnee – Wohinfahrma</title>
<link rel="stylesheet" href="<?= stil_url() ?>">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<?= design_kopf() ?>
</head>
<body>
<main class="seite">
<header class="kopf">
  <a href="schnee.php" class="logozeile" style="text-decoration:none;color:inherit">
    <svg class="logo" viewBox="0 0 100 100" width="40" height="40" aria-hidden="true"><path class="logo-marke" d="M50 7C31.2 7 17 21.6 17 40.5C17 64 50 93 50 93S83 64 83 40.5C83 21.6 68.8 7 50 7Z"/><path class="logo-fahne" d="M31 36H57a7 7 0 1 0 -7 -7M31 48H65a7 7 0 1 1 -7 7"/></svg>
    <div>
      <h1 class="marke">Wohin<span>fahrma</span></h1>
      <p class="leise klein">← zur Schnee-Rangliste</p>
    </div>
  </a>
  <div class="rechts klein leise" title="Alle Schneewerte und Temperaturen gelten für diese Höhe (oberer Teil typischer Touren), nicht für den Start.">Werte auf <?= zahl((float)$region['hoehe_tour']) ?> m</div>
</header>

<nav class="sortierung klein" aria-label="Tag">
  <?php foreach ($tage as $t): $d = $bew[$t]; ?>
    <a href="<?= h(link_mit(['tag' => $t])) ?>"<?= $t === $tag ? ' aria-current="true"' : '' ?>><?= h(tag_kurz($t, $heute)) ?><?= $d['vorbei'] || $d['offen'] || $d['det']['neu24'] === null ? '' : ' ' . h(cm($d['det']['neu24'])) ?><?= !$d['vorbei'] && $d['det']['geht'] ? ' ✓' : '' ?></a>
  <?php endforeach ?>
</nav>

<section class="karte stunden-region">
  <div class="region-kopf"><h2><?= h($region['name']) ?></h2>
    <span class="leise klein fahrzeit"><span class="km"><?= h(tag_kurz($tag, $heute)) ?></span></span></div>
  <?php if (!$daten['vorhersage'] && !$daten['ensemble']): ?>
    <p class="leise">Für diese Region gibt es gerade keine Vorhersagedaten.</p>
  <?php else:
      $kriterien = schnee_kriterien($k);
      $fehlt = array_column(array_filter($kriterien, fn($c) => $c['ok'] === false), 'grund');
  ?>
  <p class="urteil <?= $b['vorbei'] ? 'vorbei' : ($k['geht'] ? 'passt' : 'nein') ?>">
    <?php if ($b['vorbei']): ?><b>Heute vorbei</b> <span class="leise">– das Tourenfenster bis <?= SCHNEE_TAG[1] ?> Uhr ist zu kurz.</span>
    <?php elseif ($k['geht']): ?><b>✓ Pulvertag</b>
    <?php else: ?><b>Kein Pulvertag</b><?= $fehlt ? ': ' . h(implode(', ', $fehlt)) : ($k['grund'] ? ': ' . h($k['grund']) : '') ?>
    <?php endif ?></p>
  <ul class="kriterien klein">
    <?php foreach ($kriterien as $c): ?>
    <li class="<?= $c['ok'] === null ? 'unbekannt' : ($c['ok'] ? 'ja' : 'nein') ?>"><span class="t"><span class="zeichen" aria-hidden="true"><?= $c['ok'] === null ? '?' : ($c['ok'] ? '✓' : '✗') ?></span> <?= h($c['name']) ?></span><b><?= h($c['wert']) ?></b><span class="u">Regel: <?= h($c['soll']) ?></span></li>
    <?php endforeach ?>
  </ul>
  <?php if (!$b['vorbei'] && ($fi = $k['firn'] ?? null)): ?><p class="klein firn-hinweis<?= $fi['art'] === 'weich' ? ' weich' : '' ?>"><?= h($fi['text']) ?></p><?php endif ?>
  <div class="kennzahlen">
    <div class="kz kz-schnee"><span class="t">Neuschnee 24 h</span><span class="w"><?= h(cm($k['neu24'])) ?></span><span class="u"<?= $e && $e['neu24_p10'] !== null ? ' title="80 % der 51 Ensemble-Läufe, Mitte ' . h(cm($e['neu24_p50'])) . '"' : '' ?>><?= $e && $e['neu24_p10'] !== null ? (round($e['neu24_p10']) == round($e['neu24_p90']) ? 'Ensemble einig' : 'Spanne ' . h(zahl(round($e['neu24_p10']))) . '–' . h(cm($e['neu24_p90']))) : 'bis ' . SCHNEE_MORGEN . ' Uhr' ?></span></div>
    <div class="kz kz-schnee"><span class="t">Schneehöhe</span><span class="w"><?= h(cm($k['hs'])) ?></span><span class="u">Modell, grob</span></div>
    <div class="kz kz-schnee"><span class="t">Schneefallgrenze</span><span class="w"><?= h(meter($k['grenze'])) ?></span><span class="u"><?= $k['grenze'] === null ? 'kein Niederschlag' : 'tiefste in 72 h' ?></span></div>
    <div class="kz kz-temp" title="Auf Tourhöhe: tiefste <?= SCHNEE_NACHT_AB ?>–<?= SCHNEE_MORGEN ?> Uhr, höchste <?= $k['von'] ?>–<?= $k['bis'] ?> Uhr"><span class="t">Temperatur</span><span class="w"><?= h(schnee_temp_text($k) ?? '?') ?></span><span class="u">Nacht / Tag</span></div>
    <div class="kz kz-temp"><span class="t">Nullgrad</span><span class="w"><?= h(meter($k['nullgrad'])) ?></span><span class="u">höchste am Tag</span></div>
    <div class="kz kz-sonne"><span class="t">Himmel</span><span class="w"><?= h(schnee_himmel($k['wolken']) ?? '?') ?></span><span class="u"><?= SCHNEE_TAG[0] ?>–<?= SCHNEE_TAG[1] ?> Uhr</span></div>
    <div class="kz kz-wind"><span class="t">Wind am Kamm<?= $k['kamm_stark'] ? ' 💨' : '' ?></span><span class="w"><?= $k['kamm'] === null ? '?' : zahl($k['kamm']) ?></span><span class="u">km/h, 700 hPa</span></div>
    <div class="kz kz-wind"><span class="t">Böen im Tal</span><span class="w"><?= $k['boe'] === null ? '?' : zahl($k['boe']) ?></span><span class="u">km/h, höchste</span></div>
  </div>
  <?php $gemessen = $tag === $heute->format('Y-m-d') ? stationen_laden($dataDir) : []; ?>
  <?= $tag === $heute->format('Y-m-d') ? stationen_html(stationen_region($region, $gemessen), $schnee['messnetze'], $tzSeite, $heute) : '' ?>
  <?= $tag === $heute->format('Y-m-d') ? webcams_html($region, $schnee['webcams'], $schnee['messnetze'], $gemessen) : '' ?>
  <h3 class="klein diagramm-titel">Die drei Tage davor und der Tag</h3>
  <p class="legende klein"><span><i class="l-schnee"></i>Schnee</span><span><i class="l-regen"></i>Regen auf Tourhöhe</span><span><i class="l-sfg"></i>Schneefallgrenze</span><span><i class="l-fenster"></i>Tourenfenster</span></p>
  <?= schnee_svg($reihe, $region, $tag, $b['fenster'], $jetztStunde) ?>
  <p class="klein leise">Oben die Summe je 24 h bis <?= SCHNEE_MORGEN ?> Uhr, wie in den Tagesknöpfen; die drei Abschnitte vor dem Tourtag zusammen sind der Neuschnee 72 h<?= $k['neu72'] !== null ? ' (' . h(cm($k['neu72'])) . ')' : '' ?>. Liegt die Schneefallgrenze unter der Tourhöhe, fällt dort Schnee.</p>
  <?php endif ?>
  <?= maps_html($region) ?>
</section>

<section class="karte lawine-seite">
  <h2 class="klein">Lawinenlage <?= h(tag_kurz($tag, $heute)) ?></h2>
  <?php if (!lawine_freigegeben($region, $dienste)): ?>
    <p class="klein">Bitte im Lagebericht nachsehen: <a href="<?= h($dienst['url_bericht']) ?>" rel="noopener"><?= h($dienst['name']) ?> ↗</a> <span class="leise">(die Stufe zeigen wir hier, sobald die Nutzung geklärt ist)</span></p>
  <?php else: ?>
    <?php if ($stoerung): ?><p class="klein lawine-stoerung">⚠&#xFE0E; <?= h($stoerung['text'] . ($stoerung['stand'] !== '' ? ' Stand der Daten: ' . lawine_zeit_text($stoerung['stand'], $tzSeite) . '.' : '')) ?> Bitte direkt im Bericht nachsehen: <a href="<?= h($dienst['url_bericht']) ?>" rel="noopener"><?= h($dienst['name']) ?> ↗</a></p><?php endif ?>
    <?php if (!$lage): ?>
    <?php $ab = lawine_zeit_text((string)($lagenAlle[$region['warndienst']]['ts'] ?? ''), $tzSeite); ?>
    <p class="klein">Kein gültiger Lagebericht für diesen Tag. Das heißt nicht „keine Gefahr“. <a href="<?= h($dienst['url_bericht']) ?>" rel="noopener"><?= h($dienst['kurz']) ?> ↗</a><?= $ab !== '' ? ' <span class="leise">abgerufen ' . h($ab) . '</span>' : '' ?></p>
    <?php else: ?>
    <p class="klein"><span class="lw lw<?= $lage['stufe'] ?>">Gefahrenstufe <?= $lage['stufe'] ?: '–' ?></span> <?= h(lawine_stufen_text($lage['teile'], true)) ?></p>
    <?php foreach (lawine_probleme_liste($lage['probleme']) as $p): ?>
      <p class="klein"><?= $p['aspekte'] && count($p['aspekte']) < 8 ? lawine_rose($p['aspekte'], 22) . ' ' : '' ?><?= h($p['text']) ?></p>
    <?php endforeach ?>
    <p class="klein">Quelle: <a href="<?= h($dienst['url_bericht']) ?>" rel="noopener"><?= h($dienst['name']) ?> ↗</a> (<?= h($dienst['lizenz']) ?>), <?= h(lawine_gueltig_text($lage, $tzSeite)) ?>.</p>
    <p class="klein leise"><?= h(lawine_auszug_text($lage, $dienst)) ?></p>
    <?php if ($warn = lawine_warnung($lage)): ?><p class="klein lawine-warnung <?= $warn['klasse'] ?>">⚠&#xFE0E; <?= h($warn['text']) ?></p><?php endif ?>
    <?php endif ?>
  <?php endif ?>
  <p class="klein leise"><?= h(HAFTUNG) ?></p>
</section>

<footer class="fuss klein leise">
  <p>Vorhersagen: <a href="https://open-meteo.com/">Weather data by Open-Meteo.com</a> (CC BY 4.0), Mittel mehrerer Modelle auf Tourhöhe, Spanne aus dem ECMWF-Ensemble. Rohwerte, nicht kalibriert.</p>
  <?php if (!empty($region['stationen'])): foreach (array_intersect_key($schnee['messnetze'], $region['stationen']) as $n): ?><p><?= ($n['art'] ?? '') === 'snowgrid' ? 'Modellwerte' : 'Messwerte' ?>: <a href="<?= h($n['url']) ?>" rel="noopener"><?= h($n['name']) ?></a> (<?= h($n['lizenz']) ?>), <?= ($n['art'] ?? '') === 'snowgrid' ? 'Tageswert vom Vortag, kein Messwert' : 'Rohdaten, ungeprüft' ?>.</p><?php endforeach; endif ?>
  <?= design_schalter() ?>
  <p><a href="impressum.html">Impressum</a> · <a href="datenschutz.html">Datenschutz</a></p>
</footer>
</main>
</body>
</html>
