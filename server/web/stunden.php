<?php
/**
 * Wohinfahrma - Stundenansicht einer Region (seit 7.11.2026)
 *
 * Parameter:
 *   region     id aus dem Katalog (Pflicht)
 *   sport      Schluessel aus SPORTARTEN
 *   tag        YYYY-MM-DD, heute bis Tag 7; ohne: der beste Tag
 *   vergleich  id einer zweiten Region, im selben Massstab darunter
 *
 * Zeigt je Stunde Wind je Modell, Modellmittel, Boeen, Spanne des Ensembles,
 * Richtung, den Anteil der Ensemble-Laeufe, die die Stunde fahrbar sehen, und
 * die fahrbaren Stunden im Modellmittel. Diagramm als SVG ohne Fremdbibliothek.
 */

declare(strict_types=1);
date_default_timezone_set('UTC');
require __DIR__ . '/lib/regionen.php';
require __DIR__ . '/lib/bewertung.php';
require __DIR__ . '/lib/design.php';
require __DIR__ . '/lib/stunden.php';
require __DIR__ . '/lib/neopren.php';
require __DIR__ . '/lib/spotinfo.php';
require __DIR__ . '/lib/maps.php';

const TZ_SEITE = 'Europe/Berlin';
const WOCHENTAGE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function zahl(float $x): string
{
    return number_format($x, 0, ',', '.');
}

function fehlerseite(string $text, int $code = 404): never
{
    http_response_code($code);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Wohinfahrma</title><link rel="icon" href="favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="' . stil_url() . '">'
       . '<main class="seite"><h1 class="marke">Wohin<span>fahrma</span></h1><p class="karte">' . h($text)
       . ' <a href="./">Zur Rangliste</a></p></main>';
    exit;
}

$cfg = @include __DIR__ . '/config.php';
if (!is_array($cfg) || empty($cfg['data_dir'])) {
    fehlerseite('Die App ist noch nicht eingerichtet (config.php fehlt).', 500);
}
try {
    $regionen = regionen_laden($cfg['regionen_datei'] ?? __DIR__ . '/lib/regionen.json');
} catch (RuntimeException $e) {
    fehlerseite('Der Regionen-Katalog ist nicht lesbar.', 500);
}
fremdprognosen_laden($cfg, $regionen);
$nachId = [];
foreach ($regionen as $r) {
    $nachId[$r['id']] = $r;
}
$region = $nachId[(string)($_GET['region'] ?? '')] ?? null;
if (!$region) {
    fehlerseite('Diese Region gibt es nicht.');
}
$vergleich = $nachId[(string)($_GET['vergleich'] ?? '')] ?? null;
if ($vergleich && $vergleich['id'] === $region['id']) {
    $vergleich = null;
}
$sportId = isset($_GET['sport']) && isset(SPORTARTEN[$_GET['sport']]) ? (string)$_GET['sport'] : 'kite_twintip';
$sport = SPORTARTEN[$sportId];

$tzSeite = new DateTimeZone(TZ_SEITE);
$heute = new DateTimeImmutable('today', $tzSeite);
$jetzt = new DateTimeImmutable('now');
$tage = [];
for ($i = 0; $i <= 6; $i++) {
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

$dataDir = rtrim($cfg['data_dir'], '/');
$stand = daten_laden($dataDir, nur: array_values(array_filter([$region['id'], $vergleich['id'] ?? null])));

/** Daten einer Region aus dem Stand, wie rangliste() sie zusammenstellt */
function daten_fuer(array $r, array $stand): array
{
    $druck = [];
    foreach (region_bedarf($r)[0] as [$plus, $minus]) {
        foreach ([$plus, $minus] as $p) {
            $druck[$p] = $stand['druck'][$p] ?? null;
        }
    }
    $druckEns = [];
    foreach (array_keys($druck) as $p) {
        $druckEns[$p] = $stand['druck_ensemble'][$p] ?? null;
    }
    return ['vorhersage' => $stand['vorhersage'][$r['id']] ?? null, 'ensemble' => $stand['ensemble'][$r['id']] ?? null,
            'welle' => $stand['welle'][$r['id']] ?? null, 'wasser' => wasser_eintrag($stand, $r['id']), 'druck' => array_filter($druck), 'hoehe' => $stand['hoehe'][$r['id']] ?? null,
            'druck_ensemble' => array_filter($druckEns), 'hoehe_ensemble' => $stand['hoehe_ensemble'][$r['id']] ?? null];
}

$daten = daten_fuer($region, $stand);
$woche = $daten['vorhersage'] ? fremd_einmischen($region, region_bewerten($region, $daten, $sport, $tage, $jetzt), $sport, $jetzt) : [];

// Tag: gewaehlt, sonst der beste (hoechste Chance, dann Fenster im Modellmittel), sonst heute
$tag = in_array($_GET['tag'] ?? '', $tage, true) ? (string)$_GET['tag'] : null;
if ($tag === null) {
    $best = -1.0;
    foreach ($woche as $t => $d) {
        $w = !empty($d['vorbei']) || !empty($d['offen']) ? -1.0 : (($d['ens']['chance'] ?? ($d['det']['geht'] ? 1.0 : 0.0)) + ($d['det']['geht'] ? 0.01 : 0));
        if ($w > $best) {
            $best = $w;
            $tag = $t;
        }
    }
    $tag ??= $tage[0];
}

$st = $daten['vorhersage'] ? region_stunden($region, $daten, $sport, $tag, $jetzt) : null;
$stV = $vergleich ? region_stunden($vergleich, daten_fuer($vergleich, $stand), $sport, $tag, $jetzt) : null;

// Gemeinsamer Massstab: hoechster Wert beider Regionen, mindestens das Windfenster
$ymax = (float)(($sport['max'] ?? max($sport['min'], $sport['wind_max'] ?? 0)) + 6);
$mmax = max(1.0, (float)($sport['welle_min'] ?? 0) + 0.5);
foreach ([$st, $stV] as $x) {
    foreach ($x['stunden'] ?? [] as $z) {
        if ($z['welle']) {
            $mmax = max($mmax, $z['welle']['hoehe'] ?? 0, $z['welle']['swell'] ?? 0);
        }
    }
}
$mmax = ceil($mmax * 2) / 2;
foreach ([$st, $stV] as $x) {
    foreach ($x['stunden'] ?? [] as $z) {
        $ymax = max($ymax, $z['ens_p90'] ?? 0, $z['boe'] ?? 0, $z['kn'] ?? 0, ...array_values($z['modelle'] ?: [0]));
    }
}
$ymax = ceil($ymax / 5) * 5;

function link_mit(array $aenderung): string
{
    global $region, $sportId, $tag, $vergleich;
    $p = ['region' => $region['id'], 'sport' => $sportId, 'tag' => $tag];
    if ($vergleich) {
        $p['vergleich'] = $vergleich['id'];
    }
    return '?' . http_build_query(array_filter(array_merge($p, $aenderung), fn($v) => $v !== null));
}

function stufe(?float $c): string
{
    if ($c === null) {
        return 'leer';
    }
    return $c >= 0.7 ? 'go' : ($c >= 0.4 ? 'maybe' : ($c >= 0.15 ? 'no' : 'nix'));   // wie index.php
}

/**
 * Diagramm als SVG. Masse in Einheiten der viewBox (360 breit); Farben aus stil.css.
 */
function stunden_svg(array $st, array $sport, float $ymax): string
{
    $W = 360;
    $L = 28;
    $R = 6;
    $oben = 30;      // Platz fuer die Richtungspfeile
    $hoehe = 140;
    $unten = $oben + $hoehe;
    $n = $st['bis'] - $st['von'];
    $bw = ($W - $L - $R) / max(1, $n);
    $x = fn(float $s) => $L + ($s - $st['von']) * $bw;           // Kante der Stunde
    $xm = fn(int $s) => $L + ($s - $st['von'] + 0.5) * $bw;       // Mitte
    $y = fn(float $kn) => $unten - min($kn, $ymax) / $ymax * $hoehe;
    $r = fn(float $v) => (string)round($v, 1);
    $o = [];
    // Seit 13.11.2026 zwei Zeilen mehr unter dem Diagramm: Wettersymbol und Luft je Stunde
    $mitWetter = (bool)array_filter(array_column($st['stunden'], 'wetter'));
    $yUhr = $unten + ($mitWetter ? 74 : 42);
    $o[] = '<svg class="stunden-bild" viewBox="0 0 ' . $W . ' ' . ($yUhr + 10) . '" role="img" aria-label="Wind je Stunde">';
    // Tagesfenster
    [$fa, $fb] = $st['fenster'];
    $o[] = '<rect class="s-fenster" x="' . $r($x($fa)) . '" y="' . $oben . '" width="' . $r(($fb - $fa) * $bw) . '" height="' . $hoehe . '"/>';
    // Raster
    $schritt = $ymax > 30 ? 10 : 5;
    for ($k = 0; $k <= $ymax; $k += $schritt) {
        $o[] = '<line class="s-raster" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $r($y($k)) . '" y2="' . $r($y($k)) . '"/>';
        $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . $r($y($k) + 3) . '" text-anchor="end">' . $k . '</text>';
    }
    $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . ($oben - 18) . '" text-anchor="end">kn</text>';
    // Windfenster der Sportart
    foreach (array_filter([$sport['min'], $sport['max'] ?? null]) as $g) {
        $o[] = '<line class="s-grenze" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $r($y($g)) . '" y2="' . $r($y($g)) . '"><title>Windfenster ' . h($sport['name']) . ': ' . $g . ' kn</title></line>';
    }
    // Ensemble-Spanne
    $obenP = $untenP = [];
    foreach ($st['stunden'] as $s => $z) {
        if ($z['ens_p90'] !== null) {
            $obenP[] = $r($xm($s)) . ',' . $r($y($z['ens_p90']));
            $untenP[] = $r($xm($s)) . ',' . $r($y($z['ens_p10']));
        }
    }
    if ($obenP) {
        $o[] = '<polygon class="s-spanne" points="' . implode(' ', array_merge($obenP, array_reverse($untenP))) . '"><title>Ensemble: 80 % der Läufe liegen in diesem Band</title></polygon>';
    }
    // Linien je Modell, Boeen, Mittel
    $linie = function (callable $wert, string $klasse, string $titel) use ($st, $xm, $y, $r): string {
        $p = [];
        foreach ($st['stunden'] as $s => $z) {
            $v = $wert($z);
            if ($v !== null) {
                $p[] = $r($xm($s)) . ',' . $r($y($v));
            }
        }
        return count($p) > 1 ? '<polyline class="' . $klasse . '" points="' . implode(' ', $p) . '"><title>' . h($titel) . '</title></polyline>' : '';
    };
    foreach ($st['modelle'] as $m => $name) {
        $o[] = $linie(fn($z) => $z['modelle'][$m] ?? null, 's-modell', $name);
    }
    $o[] = $linie(fn($z) => $z['boe'], 's-boe', 'Böen (Modellmittel)');
    $o[] = $linie(fn($z) => $z['kn'], 's-mittel', 'Wind (Modellmittel)');
    // Richtungspfeile: zeigen, wohin der Wind weht. Der Pfad zeigt ungedreht nach
    // Sueden (wie Wind aus Norden), also um die Herkunft drehen, nicht um +180.
    // Bis 8.11.2026 stand hier +180: Die Pfeile zeigten, woher der Wind kommt,
    // entgegen der Landkarte.
    foreach ($st['stunden'] as $s => $z) {
        if ($z['richtung'] === null) {
            continue;
        }
        $cx = $r($xm($s));
        $grad = round(fmod($z['richtung'], 360));
        $o[] = '<g class="s-pfeil" transform="translate(' . $cx . ' ' . ($oben - 12) . ') rotate(' . $grad . ')"><title>aus ' . round($z['richtung']) . '° (' . himmelsrichtung($z['richtung']) . ')</title>'
             . '<path d="M0 -6 L0 6 M-3.5 2.5 L0 6 L3.5 2.5"/></g>';
    }
    // Fahrbar (Modellmittel) und Anteil der Ensemble-Laeufe
    foreach ($st['stunden'] as $s => $z) {
        $o[] = '<rect class="s-fahrbar' . ($z['fahrbar'] ? ' ja' : '') . '" x="' . $r($x($s) + 1) . '" y="' . ($unten + 4) . '" width="' . $r($bw - 2) . '" height="6" rx="2">'
             . '<title>' . h($s . ' Uhr: ' . ($z['fahrbar'] ? 'fahrbar' . ($z['regel'] ? ' (Regel)' : '') : ($z['grund'] ?? 'außerhalb des Fensters'))) . '</title></rect>';
        if ($z['ens_anteil'] !== null) {
            $o[] = '<text class="s-anteil ' . stufe($z['ens_anteil']) . '" x="' . $r($xm($s)) . '" y="' . ($unten + 24) . '" text-anchor="middle">' . zahl(100 * $z['ens_anteil']) . '</text>';
        }
        $wt = $z['wetter'] ?? null;
        if ($wt !== null && ($sym = wetter_symbol($wt)) !== null) {
            $o[] = '<text class="s-wetter" x="' . $r($xm($s)) . '" y="' . ($unten + 44) . '" text-anchor="middle"><title>' . h($s . ' Uhr: ' . wetter_wort($wt)
                 . ($wt['nied'] !== null && $wt['nied'] >= WETTER_NASS_MM ? ', ' . number_format($wt['nied'], 1, ',', '') . ' mm' : '')
                 . ($wt['wolken'] !== null ? ', ' . zahl($wt['wolken']) . ' % Wolken' : '')) . '</title>' . $sym . '</text>';
        }
        if ($wt !== null && ($g = grad_text($wt['temp'])) !== null) {
            $o[] = '<text class="s-temp" x="' . $r($xm($s)) . '" y="' . ($unten + 60) . '" text-anchor="middle">' . h($g) . '</text>';
        }
        if (($s - $st['von']) % 2 === 0) {
            $o[] = '<text class="s-achse" x="' . $r($xm($s)) . '" y="' . $yUhr . '" text-anchor="middle">' . $s . '</text>';
        }
    }
    $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . ($unten + 24) . '" text-anchor="end">%</text>';
    if ($mitWetter) {
        $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . ($unten + 60) . '" text-anchor="end">°C</text>';
    }
    $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . $yUhr . '" text-anchor="end">Uhr</text>';
    $o[] = '</svg>';
    return implode("\n", $o);
}

/**
 * Wellen als SVG (seit 7.11.2026, nur Meer): Balken = gesamte Welle, Linie =
 * Duenung, darueber Pfeile (wohin die Duenung laeuft), darunter die Periode.
 */
function welle_svg(array $st, array $sport, float $mmax): string
{
    $W = 360;
    $L = 28;
    $R = 6;
    $oben = 26;
    $hoehe = 80;
    $unten = $oben + $hoehe;
    $n = $st['bis'] - $st['von'];
    $bw = ($W - $L - $R) / max(1, $n);
    $x = fn(float $s) => $L + ($s - $st['von']) * $bw;
    $xm = fn(int $s) => $L + ($s - $st['von'] + 0.5) * $bw;
    $y = fn(float $m) => $unten - min($m, $mmax) / $mmax * $hoehe;
    $r = fn(float $v) => (string)round($v, 1);
    $o = ['<svg class="stunden-bild welle-bild" viewBox="0 0 ' . $W . ' ' . ($unten + 34) . '" role="img" aria-label="Wellen je Stunde">'];
    [$fa, $fb] = $st['fenster'];
    $o[] = '<rect class="s-fenster" x="' . $r($x($fa)) . '" y="' . $oben . '" width="' . $r(($fb - $fa) * $bw) . '" height="' . $hoehe . '"/>';
    $schritt = $mmax > 4 ? 1 : 0.5;
    for ($k = 0; $k <= $mmax + 1e-9; $k += $schritt) {
        $o[] = '<line class="s-raster" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $r($y($k)) . '" y2="' . $r($y($k)) . '"/>';
        $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . $r($y($k) + 3) . '" text-anchor="end">' . str_replace('.', ',', (string)$k) . '</text>';
    }
    $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . ($oben - 14) . '" text-anchor="end">m</text>';
    if (isset($sport['welle_min'])) {
        $o[] = '<line class="s-grenze" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $r($y($sport['welle_min'])) . '" y2="' . $r($y($sport['welle_min'])) . '"><title>Mindestwelle ' . h($sport['name']) . '</title></line>';
    }
    $p = [];
    foreach ($st['stunden'] as $s => $z) {
        $w = $z['welle'];
        if ($w === null) {
            continue;
        }
        if ($w['hoehe'] !== null) {
            $o[] = '<rect class="s-welle" x="' . $r($x($s) + 2) . '" y="' . $r($y($w['hoehe'])) . '" width="' . $r($bw - 4) . '" height="' . $r($unten - $y($w['hoehe'])) . '" rx="2">'
                 . '<title>' . h($s . ' Uhr: Welle ' . number_format($w['hoehe'], 1, ',', '') . ' m' . ($w['periode'] !== null ? ', ' . round($w['periode']) . ' s' : '')) . '</title></rect>';
        }
        [$dh, $dp, $dr] = duenung($w);
        if ($w['swell'] !== null) {
            $p[] = $r($xm($s)) . ',' . $r($y($w['swell']));
        }
        if ($dr !== null) {
            $o[] = '<g class="s-pfeil" transform="translate(' . $r($xm($s)) . ' ' . ($oben - 12) . ') rotate(' . round(fmod($dr, 360)) . ')"><title>Dünung aus ' . round($dr) . '° (' . himmelsrichtung($dr) . ')</title>'
                 . '<path d="M0 -6 L0 6 M-3.5 2.5 L0 6 L3.5 2.5"/></g>';
        }
        if ($dp !== null) {
            $o[] = '<text class="s-achse s-periode" x="' . $r($xm($s)) . '" y="' . ($unten + 13) . '" text-anchor="middle">' . round($dp) . '</text>';
        }
    }
    if (count($p) > 1) {
        $o[] = '<polyline class="s-swell" points="' . implode(' ', $p) . '"><title>Dünung (Swell)</title></polyline>';
    }
    $o[] = '<text class="s-achse" x="' . ($L - 4) . '" y="' . ($unten + 13) . '" text-anchor="end">s</text>';
    foreach ($st['stunden'] as $s => $_) {
        if (($s - $st['von']) % 2 === 0) {
            $o[] = '<text class="s-achse" x="' . $r($xm($s)) . '" y="' . ($unten + 28) . '" text-anchor="middle">' . $s . '</text>';
        }
    }
    $o[] = '</svg>';
    return implode("\n", $o);
}

$wind = !empty($sport['surf']) ? 'ab ' . str_replace('.', ',', (string)$sport['welle_min']) . ' m Dünung, ' . $sport['periode_min'] . ' s'
      : $sport['min'] . (isset($sport['max']) ? '–' . $sport['max'] : '+') . ' kn' . (isset($sport['welle_min']) ? ', Welle ab ' . str_replace('.', ',', (string)$sport['welle_min']) . ' m' : '');
$andere = $regionen;
usort($andere, fn($a, $b) => strcoll($a['name'], $b['name']));

/**
 * Tageswerte als Kennzahl-Kacheln (seit 13.11.2026, wie im Schnee-Modus; vorher eine
 * Textzeile): Wind, Wetter, Luft, Wasser, am Meer die Welle, Neopren ueber die ganze Breite.
 * Farbe nach Bedeutung (stil.css: kz-wind, kz-sonne, kz-temp, kz-wasser).
 */
function wind_kennzahlen(array $b, array $sport): string
{
    $det = $b['det'];
    $f = $det['geht'] ? [$det['von'], $det['bis']] : $b['fenster'];
    $fen = $f[0] . '–' . $f[1] . ' Uhr';
    $kz = fn(string $art, string $t, string $w, string $u, string $titel = '') => '<div class="kz kz-' . $art . '"' . ($titel !== '' ? ' title="' . h($titel) . '"' : '') . '>'
        . '<span class="t">' . h($t) . '</span><span class="w">' . $w . '</span><span class="u">' . h($u) . '</span></div>';
    $k = [];
    // Wind
    if ($det['geht'] && !empty($det['regel'])) {
        $k[] = $kz('wind', 'Wind', h(zahl((float)$det['staerke'][0]) . '–' . zahl((float)$det['staerke'][1])) . '<small> kn</small>', 'typisch (Regel)');
    } elseif ($det['geht'] && empty($sport['surf'])) {
        $k[] = $kz('wind', 'Wind Ø', h(zahl($det['mittel_kn'])) . '<small> kn</small>', $det['boe_max'] !== null ? 'Böen bis ' . zahl($det['boe_max']) . ' kn' : $fen);
    } elseif (($b['temp']['kn'] ?? null) !== null) {
        $k[] = $kz('wind', 'Wind Ø', h(zahl($b['temp']['kn'])) . '<small> kn</small>', $fen);
    }
    // Wetter
    $w = $b['wetter'] ?? null;
    if (($wort = wetter_wort($w)) !== null) {
        $k[] = $kz('sonne', 'Wetter', '<span class="symbol">' . wetter_symbol($w) . '</span>', $wort, $wort . ', ' . $fen);
    }
    // Luft und Wasser (Neopren-Faustregel)
    $n = !empty($b['temp']) ? neopren($b['temp']) : null;
    if ($n && $n['luft'] !== null) {
        $gef = $n['gefuehlt'] !== null && $n['gefuehlt'] <= $n['luft'] - 2;
        $spanne = $w && $w['temp_min'] !== null && round($w['temp_min']) !== round($w['temp_max']) ? grad_text($w['temp_min']) . ' bis ' . grad_text($w['temp_max']) : 'Mittel im Fenster';
        $k[] = $kz('temp', 'Luft', h(grad_text($n['luft'])), $gef ? 'gefühlt ' . grad_text($n['gefuehlt']) : $spanne,
                   $gef ? 'Gefühlt: Windchill bei Wind im Fenster' : '');
    }
    if ($n) {
        $see = ($b['temp']['wasser_quelle'] ?? null) === 'see';
        $k[] = $kz('wasser', 'Wasser', $n['wasser'] !== null ? h(grad_text($n['wasser'])) : '?', $n['wasser'] === null ? 'unbekannt' : ($see ? 'Seemitte, Modell' : 'Meer'),
                   $see ? 'Seemitte in 1 m Tiefe, Modell Alplakes (Eawag); am Ufer kann es abweichen' : '');
    }
    // Welle am Meer
    if (!empty($b['welle']) && $b['welle']['hoehe'] !== null) {
        [$dh, $dp] = duenung($b['welle']);
        $k[] = $kz('wasser', 'Welle', h(number_format($b['welle']['hoehe'], 1, ',', '')) . '<small> m</small>',
                   $dh !== null ? 'Dünung ' . number_format($dh, 1, ',', '') . ' m' : $fen,
                   $dh !== null ? 'Dünung ' . number_format($dh, 1, ',', '') . ' m' . ($dp !== null ? ', ' . zahl($dp) . ' s' : '') . ', Mittel im Fenster' : '');
    }
    $o = $k ? '<div class="kennzahlen">' . implode('', $k) . '</div>' : '';
    if ($n && $n['text'] !== null) {
        $o .= '<p class="neopren-zeile"><span class="leise">Neopren</span> <b>' . h($n['text']) . '</b>' . ($n['waermer'] ? ' <span class="leise">(eine Stufe wärmer wegen Wind)</span>' : '') . '</p>';
    }
    return $o;
}

/** Abschnitt einer Region: Kopf, Diagramm */
function abschnitt(array $r, ?array $st, array $sport, float $ymax, string $tag, DateTimeImmutable $heute, float $mmax = 2.0): string
{
    $o = '<section class="karte stunden-region"><div class="region-kopf"><h2>' . h($r['name']) . '</h2>';
    if (!$st) {
        return $o . '</div><p class="leise">Für diese Region gibt es gerade keine Vorhersagedaten.</p></section>';
    }
    $b = $st['bewertung'];
    $c = $b['ens']['chance'] ?? null;
    $o .= '<span class="leise klein fahrzeit">' . ($c === null ? '' : zahl(100 * $c) . ' % Chance') . '<span class="km">' . h($st['spot_name'] ?? 'ganze Region') . '</span></span></div>';
    $det = $b['det'];
    // Urteil des Tages gross (seit 13.11.2026, vorher eine Zeile im Text)
    $o .= '<p class="urteil wind ' . (!empty($b['vorbei']) ? 'vorbei' : ($det['geht'] ? 'passt' : 'nein')) . '">' . h(tag_kurz($tag, $heute)) . ': '
        . (!empty($b['vorbei']) ? '<b>vorbei</b>'
        : ($det['geht'] ? '<b>' . h($det['windlage'] ?? 'jede Richtung') . '</b> <span class="leise">· ' . $det['von'] . '–' . $det['bis'] . ' Uhr</span>' : '<b>' . h($det['grund']) . '</b>')) . '</p>';
    if (!empty($b['fremd'])) {
        // Seit 10.11.2026: Chance aus einer kalibrierten Spezialprognose, die Kurven bleiben Modellwind
        $fr = $b['fremd'];
        $o .= '<p class="klein">Chance aus der <a href="' . h($fr['url']) . '" rel="noopener">Vorhersage von ' . h($fr['name']) . ' ↗</a>, an Messwerten kalibriert: '
            . h(fremd_text($fr, $heute->getTimezone())) . '. <span class="leise">Die Kurven unten sind weiter Modellwind.</span></p>';
    }
    if (empty($b['vorbei']) && $det['grund'] !== 'noch keine Vorhersage') {
        $o .= wind_kennzahlen($b, $sport);
    }
    // Legende eingeklappt (seit 13.11.2026; vorher ein langer Absatz ueber dem Diagramm)
    $o .= '<details class="klein leise legende-auf"><summary>Was zeigt das Diagramm?</summary><p>Wind: dicke Linie = Modellmittel, dünne = einzelne Modelle, orange gestrichelt = Böen (Modellmittel), Band = 80 % der Ensemble-Läufe, grün gestrichelt = Windfenster, Pfeile = wohin der Wind weht. Grüne Balken = Stunde fahrbar, % = Anteil der Ensemble-Läufe, die sie fahrbar sehen. Darunter Wetter und Lufttemperatur je Stunde (Modellmittel)</p></details>';
    $o .= stunden_svg($st, $sport, $ymax);
    if (!empty($st['hat_welle'])) {
        $o .= '<p class="klein leise welle-titel">Wellen: Balken = gesamte Welle, Linie = Dünung, Pfeile = wohin die Dünung läuft, Zahlen = Periode in Sekunden</p>' . welle_svg($st, $sport, $mmax);
    }
    return $o . maps_html($r) . spotinfo_html($r) . '</section>';
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($region['name']) ?> – Wohinfahrma</title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= stil_url() ?>">
<?= design_kopf() ?>
</head>
<body>
<main class="seite">
<header class="kopf">
  <a href="./" class="logozeile" style="text-decoration:none;color:inherit">
    <svg class="logo" viewBox="0 0 100 100" width="40" height="40" aria-hidden="true"><path class="logo-marke" d="M50 7C31.2 7 17 21.6 17 40.5C17 64 50 93 50 93S83 64 83 40.5C83 21.6 68.8 7 50 7Z"/><path class="logo-fahne" d="M31 36H57a7 7 0 1 0 -7 -7M31 48H65a7 7 0 1 1 -7 7"/></svg>
    <div>
      <h1 class="marke">Wohin<span>fahrma</span></h1>
      <p class="leise klein">← zur Rangliste</p>
    </div>
  </a>
  <div class="rechts klein leise"><?= h($sport['name']) ?><br><?= h($wind) ?></div>
</header>

<nav class="sortierung klein" aria-label="Tag">
  <?php foreach ($tage as $t):
      $d = $woche[$t] ?? null;
      $c = $d && empty($d['vorbei']) ? ($d['ens']['chance'] ?? null) : null;
  ?>
    <a href="<?= h(link_mit(['tag' => $t])) ?>"<?= $t === $tag ? ' aria-current="true"' : '' ?><?= !empty($d['offen']) ? ' title="' . h(ucfirst($d['det']['grund'])) . '"' : '' ?>><?= h(tag_kurz($t, $heute)) ?><?= $c !== null ? ' <span class="leise">' . zahl(100 * $c) . ' %</span>' : (!empty($d['offen']) ? ' <span class="leise">?</span>' : '') ?></a>
  <?php endforeach ?>
</nav>

<?= abschnitt($region, $st, $sport, $ymax, $tag, $heute, $mmax) ?>
<?php if ($vergleich): ?>
<?= abschnitt($vergleich, $stV, $sport, $ymax, $tag, $heute, $mmax) ?>
<?php endif ?>

<form class="wahl karte" method="get">
  <input type="hidden" name="region" value="<?= h($region['id']) ?>">
  <input type="hidden" name="tag" value="<?= h($tag) ?>">
  <label>Sportart
    <select name="sport" onchange="this.form.submit()">
      <?php foreach (SPORTARTEN as $id => $s): ?>
        <option value="<?= h($id) ?>"<?= $id === $sportId ? ' selected' : '' ?>><?= h($s['name']) ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <label>Vergleichen mit
    <select name="vergleich" onchange="this.form.submit()">
      <option value="">–</option>
      <?php foreach ($andere as $a): if ($a['id'] === $region['id']) { continue; } ?>
        <option value="<?= h($a['id']) ?>"<?= $vergleich && $a['id'] === $vergleich['id'] ? ' selected' : '' ?>><?= h($a['name']) ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <noscript><button class="knopf">Anzeigen</button></noscript>
</form>

<p class="leise klein erklaerung">
  <b>Modelle:</b> <?= h(implode(', ', array_values($st['modelle'] ?? []))) ?>; Ensemble: 51 Läufe von ECMWF. <b>Windfenster</b> <?= h($sport['name']) ?>: <?= h($wind) ?>.
  <b>Fahrbar</b> heißt im Modellmittel: Wind im Fenster, Richtung passt, nicht ablandig, kein Gewitter, Welle passt. Hinterlegt: Tagesfenster. Zeiten in Ortszeit der Region.
  Bei Regel-Windlagen rechnet die Bewertung mit einer typischen Stärke; die Linien zeigen trotzdem den Modellwind.
</p>
<footer class="fuss klein leise">
  <p>Vorhersagen: <a href="https://open-meteo.com/">Weather data by Open-Meteo.com</a> (CC BY 4.0). Wassertemperatur der Seen: <a href="https://www.alplakes.eawag.ch/">Alplakes, Eawag</a> (CC BY 4.0). Rohwerte, nicht kalibriert.</p>
  <?= design_schalter() ?>
  <p><a href="impressum.html">Impressum</a> · <a href="datenschutz.html">Datenschutz</a></p>
</footer>
</main>
</body>
</html>
