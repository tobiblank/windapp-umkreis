<?php
/**
 * Tests ohne Netz:  php tests/test.php
 *
 * 1. Einzelfunktionen (Sektoren, Katalog-Pruefung, Tagesfenster)
 * 2. Sammler gegen einen lokalen Open-Meteo-Ersatz (fake_openmeteo.php),
 *    danach Rangliste aus den geschriebenen Rohdaten.
 */

date_default_timezone_set('UTC');
$ROOT = dirname(__DIR__);
require "$ROOT/server/web/lib/regionen.php";
require "$ROOT/server/web/lib/bewertung.php";

$fehler = 0;
function pruefe(bool $ok, string $was): void
{
    global $fehler;
    echo ($ok ? '  ok    ' : '  FEHLER '), $was, "\n";
    if (!$ok) {
        $fehler++;
    }
}

echo "Sektoren\n";
pruefe(winkel_in_sektor(250, [240, 275]), '250 in [240, 275)');
pruefe(!winkel_in_sektor(275, [240, 275]), '275 nicht in [240, 275) (bis exklusive)');
pruefe(winkel_in_sektor(350, [275, 20]) && winkel_in_sektor(10, [275, 20]), '[275, 20] geht über Norden');
pruefe(!winkel_in_sektor(30, [275, 20]), '30 nicht in [275, 20]');
pruefe(winkel_in_sektor(-10, [340, 20]), 'negative Grad werden umgerechnet');
pruefe(windlage_fuer(123.0, [])['grob'] === true, 'ohne Windlagen: jede Richtung, grob');
pruefe(windlage_fuer(40.0, [['name' => 'Bora', 'sektor' => [20, 80]]])['name'] === 'Bora', '40 Grad = Bora');
pruefe(abs(richtung_mittel([350, 10]) - 0) < 1e-6 || abs(richtung_mittel([350, 10]) - 360) < 1e-6, 'Mittel von 350 und 10 ist 0');

echo "Katalog\n";
$katalog = json_decode(file_get_contents("$ROOT/daten/regionen.json"), true);
pruefe(regionen_pruefen($katalog) === [], 'daten/regionen.json ist gültig');
$kaputt = ['regionen' => [
    ['id' => 'a', 'name' => 'A', 'typ' => 'fluss', 'punkt' => [99, 0], 'zeitzone' => 'Mars/Olymp', 'windlagen' => [['name' => 'X', 'sektor' => [10, 10]]]],
    ['id' => 'a', 'name' => 'A2', 'typ' => 'see', 'punkt' => [1, 1], 'zeitzone' => 'UTC', 'windlagen' => []],
]];
pruefe(count(regionen_pruefen($kaputt)) === 5, 'kaputter Katalog: 5 Fehler gefunden (typ, punkt, zeitzone, sektor, id doppelt)');

$mitLink = ['regionen' => [['id' => 'x', 'name' => 'X', 'typ' => 'see', 'punkt' => [1, 1], 'zeitzone' => 'UTC', 'windlagen' => [],
    'links' => [['name' => 'gut', 'url' => 'https://example.org/a'], ['name' => 'schlecht', 'url' => 'javascript:alert(1)']]]]];
pruefe(count(regionen_pruefen($mitLink)) === 1, 'links: nur https-Adressen erlaubt');
$mitLink['regionen'][0]['links'] = [];
$mitLink['regionen'][0]['thermik'] = 'ja';
pruefe(count(regionen_pruefen($mitLink)) === 1, 'thermik: nur true oder false');

echo "Tagesfenster\n";
[$ab, $bis] = tagesfenster('2026-11-07', new DateTimeZone('Europe/Berlin'), 48.0, 11.13);
pruefe($ab === 9 && $bis === 17, "Ammersee 7.11.: 9 bis 17 Uhr (ist $ab bis $bis)");
[$ab, $bis] = tagesfenster('2026-06-21', new DateTimeZone('Europe/Berlin'), 48.0, 11.13);
pruefe($ab === 9 && $bis === 21, "Ammersee 21.6.: 9 bis 21 Uhr (ist $ab bis $bis)");

// ---------------------------------------------------------------
echo "Sammler gegen Open-Meteo-Ersatz\n";
$tmp = sys_get_temp_dir() . '/umkreis-test-' . getmypid();
@mkdir("$tmp/web/lib", 0700, true);
@mkdir("$tmp/daten", 0700, true);
copy("$ROOT/server/web/lib/regionen.php", "$tmp/web/lib/regionen.php");
copy(__DIR__ . "/regionen_test.json", "$tmp/regionen.json");
$port = 18000 + getmypid() % 1000;
file_put_contents("$tmp/web/config.php", '<?php return ' . var_export([
    'data_dir' => "$tmp/daten",
    'regionen_datei' => "$tmp/regionen.json",
    'test_url_ersatz' => "http://127.0.0.1:$port",
], true) . ';');
// Sammler mit angepasstem Webordner
$sammler = str_replace("\$WEB_DIR = __DIR__ . '/web';", "\$WEB_DIR = " . var_export("$tmp/web", true) . ';',
                       file_get_contents("$ROOT/server/sammler.php"));
file_put_contents("$tmp/sammler.php", $sammler);

$server = proc_open(['php', '-S', "127.0.0.1:$port", __DIR__ . '/fake_openmeteo.php'],
                    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

exec('php ' . escapeshellarg("$tmp/sammler.php") . ' 2>&1', $aus1, $rc1);
pruefe($rc1 === 0 && $aus1 === [], 'erster Lauf still (keine Fehler)' . ($aus1 ? ': ' . implode(' | ', $aus1) : ''));
$heute = gmdate('Y-m-d');
foreach (['vorhersage', 'ensemble', 'welle'] as $q) {
    pruefe(is_file("$tmp/daten/$q/$heute.ndjson"), "$q geschrieben");
}
exec('php ' . escapeshellarg("$tmp/sammler.php") . ' 2>&1', $aus2, $rc2);
$zeilen = count(file("$tmp/daten/vorhersage/$heute.ndjson"));
pruefe($rc2 === 0 && $zeilen === 2, "zweiter Lauf in derselben Stunde ruft nicht erneut ab ($zeilen Zeilen: See und Meer)");
exec('php ' . escapeshellarg("$tmp/sammler.php") . ' --debug 2>&1', $aus3);
pruefe(in_array('vorhersage/0: unveraendert', $aus3, true) && in_array('vorhersage/1: unveraendert', $aus3, true), 'gleiche Werte werden nicht doppelt geschrieben');
$zaehl = json_decode(file_get_contents("$tmp/daten/.state/openmeteo.json"), true);
pruefe(($zaehl[$heute]['abrufe'] ?? 0) === 18, 'Open-Meteo-Zählung: 18 Abrufe (2 Läufe x 9: Vorhersage und Ensemble je See/Meer, Welle, Druck und Höhenwind je Modell/Ensemble)');

// Eine fehlerhafte Antwort darf nichts kaputt machen
$zz = array_map(fn($l) => json_decode($l, true), file($tmp . "/daten/vorhersage/$heute.ndjson"));
pruefe($zz[0]['regionen'] === ['ammersee', 'testsee-druck', 'testsee-hoehe'] && count($zz[0]['payload']) === 3 && $zz[1]['regionen'] === ['adria-nord'],
       'payload als Liste, Abrufe getrennt nach See und Meer');
pruefe($zz[0]['payload'][0]['test_cell_selection'] === null && $zz[1]['payload'][0]['test_cell_selection'] === 'sea',
       'Meer-Regionen mit cell_selection=sea, Seen ohne');
$ensZ = array_map(fn($l) => json_decode($l, true), file($tmp . "/daten/ensemble/$heute.ndjson"));
pruefe(($ensZ[1]['payload'][0]['test_cell_selection'] ?? null) === 'sea', 'auch das Ensemble fragt für das Meer eine Wasser-Zelle an');

proc_terminate($server);

// ---------------------------------------------------------------
echo "Rangliste\n";
$stand = daten_laden("$tmp/daten");
$regionen = regionen_laden("$tmp/regionen.json");
$morgen = gmdate('Y-m-d', time() + 86400);
$uebermorgen = gmdate('Y-m-d', time() + 2 * 86400);
$inning = [48.07, 11.15];

$kite = SPORTARTEN['kite_twintip'];
$rl = rangliste($regionen, $stand, $kite, $inning, 1000, [$morgen, $uebermorgen]);
pruefe(count($rl) === 2, '2 Regionen im Umkreis 1000 km');
pruefe($rl[0]['region']['id'] === 'adria-nord', 'Adria vorn (zwei Bora-Tage)');
$adria = $rl[0]['tage'][$morgen];
pruefe($adria['det']['geht'] && $adria['det']['windlage'] === 'Bora', 'Adria morgen: geht, Windlage Bora');
pruefe(abs($adria['ens']['chance'] - 1.0) < 1e-9, 'Adria morgen: alle 51 Läufe über 14 kn');
pruefe($adria['det']['von'] === 10 && $adria['det']['bis'] === 15, "Adria morgen: 10-15 Uhr Ortszeit (ist {$adria['det']['von']}-{$adria['det']['bis']})");
$am = $rl[1]['tage'][$morgen];
pruefe($am['det']['geht'] && $am['det']['windlage'] === 'West', 'Ammersee morgen: geht bei West');
pruefe(in_array($am['spot'], ['stegen', 'herrsching'], true), "Ammersee morgen: bester Spot Stegen oder Herrsching (ist {$am['spot']})");
$eching = array_values(array_filter($am['alle'], fn($s) => $s['spot'] === 'eching'))[0];
pruefe(!$eching['det']['geht'] && $eching['det']['grund'] === 'Richtung passt nicht', 'Eching morgen: Richtung passt nicht');
pruefe(abs($am['ens']['chance'] - 36 / 51) < 1e-9, sprintf('Ammersee morgen: 36 von 51 Läufen (ist %.3f)', $am['ens']['chance']));
pruefe(!$rl[1]['tage'][$uebermorgen]['det']['geht'], 'Ammersee übermorgen: geht nicht');

$nah = rangliste($regionen, $stand, $kite, $inning, 100, [$morgen]);
pruefe(count($nah) === 1 && $nah[0]['region']['id'] === 'ammersee', 'Umkreis 100 km: nur Ammersee');

$segeln = rangliste($regionen, $stand, SPORTARTEN['segeln'], $inning, 1000, [$morgen]);
$adriaS = array_values(array_filter($segeln, fn($e) => $e['region']['id'] === 'adria-nord'))[0]['tage'][$morgen];
pruefe($adriaS['det']['geht'] && $adriaS['det']['von'] === 15,
       "Segeln Adria morgen: 10-15 Uhr zu viel Wind (max 18 kn), danach geht es (ab {$adriaS['det']['von']} Uhr)");

$wave = ['name' => 'Wave', 'min' => 14, 'welle_min' => 1.0];
$rlw = rangliste($regionen, $stand, $wave, $inning, 1000, [$morgen]);
$adriaW = array_values(array_filter($rlw, fn($e) => $e['region']['id'] === 'adria-nord'))[0]['tage'][$morgen];
pruefe($adriaW['det']['grund'] === 'zu wenig Welle', 'Wave Adria morgen: zu wenig Welle (0,8 m)');
$amW = array_values(array_filter($rlw, fn($e) => $e['region']['id'] === 'ammersee'))[0]['tage'][$morgen];
pruefe($amW['det']['grund'] === 'keine Wellendaten', 'Wave Ammersee: keine Wellendaten (See)');

echo "Regel-Windlagen\n";
foreach (['druck', 'druck_ensemble', 'hoehe', 'hoehe_ensemble'] as $q) {
    pruefe(is_file("$tmp/daten/$q/$heute.ndjson"), "$q geschrieben");
}
$zd = json_decode(file("$tmp/daten/druck/$heute.ndjson")[0], true);
pruefe($zd['regionen'] === ['a', 'b'], 'Druck für die Druckpunkte A und B');
$zh = json_decode(file("$tmp/daten/hoehe/$heute.ndjson")[0], true);
pruefe($zh['regionen'] === ['testsee-hoehe'], 'Höhenwind nur für die Region mit Grundströmung in 700 hPa');
$rlR = rangliste($regionen, $stand, $kite, [46.6, 30.0], 100, [$morgen, $uebermorgen], new DateTimeImmutable('today 06:00', new DateTimeZone('Europe/Berlin')));
$byId = [];
foreach ($rlR as $e) {
    $byId[$e['region']['id']] = $e;
}
$td = $byId['testsee-druck']['tage'][$morgen] ?? null;
pruefe($td && $td['det']['geht'] && $td['det']['regel'] && $td['det']['von'] === 11 && $td['det']['bis'] === min(17, $td['fenster'][1]),
       'Druckregel morgen: A−B ≥ 0, sonnig → ab 11 Uhr (Zeit der Windlage) bis Ende der Windlage oder des Tagesfensters'
       . ($td && $td['det']['geht'] ? " (ist {$td['det']['von']}-{$td['det']['bis']}, Fenster " . implode('-', $td['fenster']) . ')' : ''));
pruefe($td && abs($td['det']['mittel_kn'] - 19) < 1e-9 && $td['det']['boe_max'] === null && $td['det']['staerke'] === [16, 22],
       'Druckregel: typische Stärke 19 kn (Mitte 16-22), keine Böen');
pruefe($td && abs($td['ens']['chance'] - 1.0) < 1e-9, 'Druckregel morgen: alle Ensemble-Läufe');
$td2 = $byId['testsee-druck']['tage'][$uebermorgen] ?? null;
pruefe($td2 && !$td2['det']['geht'] && $td2['det']['grund'] === 'Druckdifferenz passt nicht' && abs($td2['ens']['chance']) < 1e-9,
       'Druckregel übermorgen: A−B < 0 → Druckdifferenz passt nicht, 0 %');
$th = $byId['testsee-hoehe']['tage'][$morgen] ?? null;
pruefe($th && $th['det']['geht'] && $th['det']['windlage'] === 'Talwind' && abs($th['det']['mittel_kn'] - 17) < 1e-9,
       'Grundströmung morgen: 700 hPa SW ~8 kn → Talwind 17 kn');
pruefe($th && abs($th['ens']['chance'] - 47 / 51) < 1e-9, sprintf('Grundströmung: 47 von 51 Läufen bis 10 kn (10,0 gerundet zählt) (ist %.3f)', $th['ens']['chance'] ?? -1));
$th2 = $byId['testsee-hoehe']['tage'][$uebermorgen] ?? null;
pruefe($th2 && $th2['det']['grund'] === 'Grundströmung passt nicht', 'Grundströmung übermorgen: 20 kn aus N passt nicht');
$rlSeg = rangliste($regionen, $stand, SPORTARTEN['segeln'], [46.6, 30.0], 100, [$morgen], new DateTimeImmutable('today 06:00', new DateTimeZone('Europe/Berlin')));
$segD = array_values(array_filter($rlSeg, fn($e) => $e['region']['id'] === 'testsee-druck'))[0]['tage'][$morgen];
pruefe(!$segD['det']['geht'] && $segD['det']['grund'] === 'zu viel Wind', 'Segeln (bis 18 kn): Regel mit 19 kn ist zu viel Wind');
$r = ['name' => 'X', 'sektor' => [0, 90], 'druck' => [['plus' => 'a', 'minus' => 'b', 'min' => 0]], 'staerke' => [10, 20], 'saison' => [5, 9]];
pruefe(regel_grund($r, ['_h' => 12, '_monat' => 11, 'druck' => ['a-b' => 3]]) === 'außerhalb der Saison', 'Saison Mai-Sep: im November nicht');
pruefe(regel_grund($r, ['_h' => 12, '_monat' => 7, 'druck' => ['a-b' => 3]]) === null, 'Saison Mai-Sep: im Juli erfüllt');
pruefe(regel_grund($r + ['saison' => [11, 2]], ['_h' => 12, '_monat' => 1, 'druck' => ['a-b' => 3]]) === 'außerhalb der Saison', 'Saison wird nicht überschrieben (Grundwert)');
pruefe(regel_grund(['saison' => [11, 2]] + $r, ['_h' => 12, '_monat' => 1, 'druck' => ['a-b' => 3]]) === null, 'Saison Nov-Feb über den Jahreswechsel: Januar erfüllt');
pruefe(regel_grund($r, ['_h' => 12, '_monat' => 7]) === 'keine Druckdaten', 'ohne Druckdaten: nicht erfüllt');
$kat = json_decode(file_get_contents(__DIR__ . '/regionen_test.json'), true);
$kat['regionen'][2]['windlagen'][0]['druck'][0]['plus'] = 'gibtsnicht';
unset($kat['regionen'][3]['windlagen'][0]['staerke']);
$kat['regionen'][0]['spots'][1]['windlagen'][0]['zeit'] = [10, 12];
pruefe(count(regionen_pruefen($kat)) === 3, 'Prüfung: unbekannter Druckpunkt, Regel ohne Stärke, zeit ohne Regel');

echo "Sortierung\n";
$heuteT = gmdate('Y-m-d');
$rlS = rangliste($regionen, $stand, $kite, $inning, 1000, [$heuteT, $morgen, $uebermorgen]);
$ids = fn(array $l) => array_map(fn($e) => $e['region']['id'], $l);
pruefe($ids(rangliste_sortieren($rlS, 'summe')) === ['adria-nord', 'ammersee'], 'alle Tage: Adria (2 Bora-Tage) vor Ammersee');
pruefe($ids(rangliste_sortieren($rlS, 'km')) === ['ammersee', 'adria-nord'], 'Entfernung: Ammersee zuerst');
pruefe($ids(rangliste_sortieren($rlS, $heuteT)) === ['ammersee', 'adria-nord'], 'heute: beide ohne Wind, dann näher zuerst');
pruefe($ids(rangliste_sortieren($rlS, $morgen)) === ['adria-nord', 'ammersee'], 'morgen: Adria 100 % vor Ammersee 71 %');
pruefe($ids(rangliste_sortieren($rlS, '2000-01-01')) === ['adria-nord', 'ammersee'], 'unbekannter Tag: wie alle Tage');

echo "Restfenster heute\n";
$rom = new DateTimeZone('Europe/Rome');
$adriaR = array_values(array_filter($regionen, fn($r) => $r['id'] === 'adria-nord'))[0];
$datenAdria = ['vorhersage' => $stand['vorhersage']['adria-nord'], 'ensemble' => $stand['ensemble']['adria-nord'],
               'welle' => $stand['welle']['adria-nord'] ?? null];
// Testdaten Adria morgen: Bora 20 kn von 10 bis 15 Uhr Ortszeit, Fenster 9-17 Uhr
$um = fn(string $zeit) => new DateTimeImmutable("$morgen $zeit", $rom);
$r = region_bewerten($adriaR, $datenAdria, $kite, [$morgen, $uebermorgen], $um('12:00'))[$morgen];
pruefe($r['rest'] && !$r['vorbei'] && $r['fenster'] === [13, 17], 'um 12 Uhr: Rest 13-17 Uhr (12:30 aufgerundet)');
pruefe($r['det']['geht'] && $r['det']['von'] === 13 && $r['det']['bis'] === 15, "um 12 Uhr: geht noch 13-15 Uhr (ist {$r['det']['von']}-{$r['det']['bis']})");
pruefe(abs($r['ens']['chance'] - 1.0) < 1e-9, 'um 12 Uhr: Ensemble rechnet nur den Rest, weiter 100 %');
$r = region_bewerten($adriaR, $datenAdria, $kite, [$morgen], $um('14:10'))[$morgen];
pruefe($r['rest'] && !$r['vorbei'] && !$r['det']['geht'] && abs($r['ens']['chance']) < 1e-9, 'um 14:10 Uhr: Rest 15-17 Uhr ohne Wind, 0 %');
$r = region_bewerten($adriaR, $datenAdria, $kite, [$morgen], $um('15:40'))[$morgen];
pruefe($r['vorbei'] && $r['ens'] === null && $r['det']['grund'] === 'Tag vorbei', 'um 15:40 Uhr: nur noch 1 Stunde, vorbei');
$r = region_bewerten($adriaR, $datenAdria, $kite, [$uebermorgen], $um('15:40'))[$uebermorgen];
pruefe(!$r['rest'] && $r['fenster'][0] === 9 && $r['det']['geht'], 'andere Tage: ganzes Fenster');
[$f, $rest, $vorbei] = restfenster([9, 17], $morgen, $rom, $um('23:45'));
pruefe($rest && $vorbei, 'kurz vor Mitternacht: vorbei');
[$f, $rest, $vorbei] = restfenster([9, 17], $morgen, $rom, $um('06:00'));
pruefe($rest && !$vorbei && $f === [9, 17], 'früh am Morgen: Rest = ganzes Fenster');
$rl = rangliste($regionen, $stand, $kite, $inning, 1000, [$morgen, $uebermorgen], $um('15:40'));
$adriaRl = array_values(array_filter($rl, fn($e) => $e['region']['id'] === 'adria-nord'))[0];
pruefe(abs($adriaRl['erwartete_tage'] - 1.0) < 1e-9, 'Rangliste: ein Tag, der vorbei ist, zählt nicht zu den erwarteten Tagen');

// ---------------------------------------------------------------
echo "Sammler als GitHub Action\n";
$server = proc_open(['php', '-S', "127.0.0.1:$port", __DIR__ . '/fake_openmeteo.php'],
                    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$cmd = 'UMKREIS_TEST_URL=' . escapeshellarg("http://127.0.0.1:$port")
     . ' php ' . escapeshellarg("$ROOT/server/sammler.php")
     . ' --action ' . escapeshellarg(__DIR__ . "/regionen_test.json") . ' ' . escapeshellarg("$tmp/action") . ' 2>&1';
exec($cmd, $ausA, $rcA);
pruefe($rcA === 0 && $ausA === [], 'Action-Lauf ohne Fehler' . ($ausA ? ': ' . implode(' | ', $ausA) : ''));
foreach (['vorhersage', 'ensemble', 'welle'] as $q) {
    $gz = glob("$tmp/action/$q/" . gmdate('Y-m-d') . '_*.ndjson.gz');
    $roh = glob("$tmp/action/$q/*.ndjson");
    pruefe(count($gz) === 1 && !$roh, "$q: eine gezippte Datei für den Lauf");
}
pruefe(!is_dir("$tmp/action/.state"), 'kein Zustand im Ausgabeordner (wird nicht hochgeladen)');
$standA = daten_laden("$tmp/action");
$rlA = rangliste($regionen, $standA, $kite, $inning, 1000, [$morgen]);
pruefe(count($rlA) === 2 && abs($rlA[0]['tage'][$morgen]['ens']['chance'] - 1.0) < 1e-9, 'gezippte Läufe werden gelesen, gleiche Rangliste');
exec('php ' . escapeshellarg("$ROOT/server/sammler.php") . ' --action 2>&1', $ausF, $rcF);
pruefe($rcF === 1, 'Action ohne Pfade: Hinweis und Exit 1');
// Fehlgeschlagener Abruf: Open-Meteo nicht erreichbar
$cmd = 'UMKREIS_TEST_URL=http://127.0.0.1:1 php ' . escapeshellarg("$ROOT/server/sammler.php")
     . ' --action ' . escapeshellarg(__DIR__ . "/regionen_test.json") . ' ' . escapeshellarg("$tmp/kaputt") . ' 2>&1';
exec($cmd, $ausK, $rcK);
pruefe($rcK === 1 && str_contains(implode("\n", $ausK), 'vorhersage: Abruf fehlgeschlagen'), 'Action bei Fehler: Meldung und Exit 1');
proc_terminate($server);

// ---------------------------------------------------------------
echo "Seite\n";
$site = "$tmp/site";
exec('cp -r ' . escapeshellarg("$ROOT/server/web") . ' ' . escapeshellarg($site));
copy(__DIR__ . "/regionen_test.json", "$site/lib/regionen.json");
$sitePort = $port + 1;
$web = proc_open(['php', '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S', "127.0.0.1:$sitePort", '-t', $site],
                 [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $sitePort); $i++) {
    usleep(100000);
}
function holen(int $port, string $pfad): array
{
    $body = @file_get_contents("http://127.0.0.1:$port/$pfad", false, stream_context_create(['http' => ['ignore_errors' => true]]));
    preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), (string)$body];
}
function ohne_php_meldung(string $html): bool
{
    return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)/', $html);
}

[$code] = holen($sitePort, '');
pruefe($code === 503, 'ohne config.php: 503');

file_put_contents("$site/config.php", '<?php return ' . var_export(['data_dir' => "$tmp/action"], true) . ';');
foreach (['', '?sport=wingfoil&km=100', '?ansicht=reise', '?ort=45.6,13.1&km=400', '?sport=xx&km=abc&ort=foo&ansicht=x',
          '?sport=segeln&km=2500&ort=48.07,11.15', '?nach=km', '?nach=' . gmdate('Y-m-d', time() + 86400), '?nach=quatsch&ansicht=reise'] as $q) {
    [$code, $html] = holen($sitePort, "index.php$q");
    pruefe($code === 200 && ohne_php_meldung($html), "index.php$q: 200, keine PHP-Meldung");
}
[, $html] = holen($sitePort, 'index.php?km=1000');
pruefe(str_contains($html, 'Nördliche Adria') && str_contains($html, 'Ammersee') && str_contains($html, 'Bora'), 'Rangliste zeigt Adria (Bora) und Ammersee');
[, $html] = holen($sitePort, 'index.php?km=100');
pruefe(!str_contains($html, 'Nördliche Adria') && str_contains($html, 'Ammersee'), 'Umkreis 100 km: nur Ammersee');
[, $html] = holen($sitePort, 'index.php?ort=45.6789,13.1234');
pruefe(str_contains($html, 'value="45.7,13.1"'), 'Standort wird auf 0,1 Grad gerundet');
$morgen = (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
[$code, $html] = holen($sitePort, "index.php?region=ammersee&nach=$morgen&km=100");
pruefe($code === 200 && ohne_php_meldung($html) && str_contains($html, 'Geteilte Vorhersage') && str_contains($html, 'Ammersee')
       && !str_contains($html, 'Nördliche Adria') && !str_contains($html, 'id="wahl"') && str_contains($html, ' gewaehlt"'),
       'geteilte Vorhersage: nur die Region, ohne Auswahl, Tag markiert');
[, $html] = holen($sitePort, 'index.php?region=adria-nord&nach=' . (new DateTimeImmutable('+4 day', new DateTimeZone('Europe/Berlin')))->format('Y-m-d'));
pruefe(str_contains($html, 'Nördliche Adria') && !str_contains($html, '<span class="t">Heute') && str_contains($html, ' gewaehlt"') && ohne_php_meldung($html), 'geteilter Tag 4: Zeitraum Tag 3 bis 7, Tag markiert');
[, $html] = holen($sitePort, 'index.php?region=ammersee&nach=2020-01-01');
pruefe(str_contains($html, 'ist vorbei') && ohne_php_meldung($html), 'geteilter Tag in der Vergangenheit: Hinweis');
[, $html] = holen($sitePort, 'index.php?region=gibtsnicht&km=100');
pruefe(!str_contains($html, 'Geteilte Vorhersage') && str_contains($html, 'id="wahl"') && ohne_php_meldung($html), 'unbekannte Region: normale Rangliste');
[, $html] = holen($sitePort, 'index.php?km=1000');
preg_match_all('/<button type="button" class="teilen" hidden\s+data-titel="([^"]*)"\s+data-text="([^"]*)"\s+data-url="([^"]*)"/', $html, $tk, PREG_SET_ORDER);
pruefe($tk !== [] && str_starts_with(html_entity_decode($tk[0][3]), '?sport=') && str_contains(html_entity_decode($tk[0][3]), '&region=')
       && preg_match('/^.+, (So|Mo|Di|Mi|Do|Fr|Sa) \d{1,2}\.\d{1,2}\.: /u', html_entity_decode($tk[0][2])),
       'Teilen-Knopf an guten Tagen, Text mit Datum, Link mit Region und Tag');
[, $html] = holen($sitePort, 'index.php?sport=<script>');
pruefe(!str_contains($html, '<script>alert') && ohne_php_meldung($html), 'unbekannte Sportart: Standard, nichts ungefiltert ausgegeben');

// Eigene Kopie im Unterordner statt config.php auszutauschen: unabhaengig von Zwischenspeichern
exec('cp -r ' . escapeshellarg("$ROOT/server/web") . ' ' . escapeshellarg("$site/leer"));
copy(__DIR__ . "/regionen_test.json", "$site/leer/lib/regionen.json");
file_put_contents("$site/leer/config.php", '<?php return ' . var_export(['data_dir' => "$tmp/leer"], true) . ';');
[$code, $html] = holen($sitePort, 'leer/index.php');
$ok = $code === 200 && str_contains($html, 'Noch keine Vorhersagedaten') && ohne_php_meldung($html);
pruefe($ok, 'ohne Daten: Hinweis statt Fehler');
if (!$ok) {
    echo "        HTTP $code, Anfang der Seite: ", substr(preg_replace('/\s+/', ' ', strip_tags($html)), 0, 400), "\n";
}
foreach (['impressum.html', 'datenschutz.html', 'stil.css', 'sw.js', 'offline.html', 'icon-192.png', 'icon-512.png', 'apple-touch-icon.png', 'icon.svg', 'icon-maskable-512.png', 'favicon.svg', 'favicon.ico', 'schrift/barlow-condensed-600.woff', 'schrift/OFL.txt'] as $f) {
    [$code] = holen($sitePort, $f);
    pruefe($code === 200, "$f erreichbar");
}
[$code, $man] = holen($sitePort, 'manifest.php');
$m = json_decode($man, true);
pruefe($code === 200 && ($m['name'] ?? '') === 'Wohinfahrma' && ($m['short_name'] ?? '') === 'Fahrma' && ($m['start_url'] ?? '') === './',
       'Manifest: Wohinfahrma, Kurzname Fahrma, relative Start-Adresse');
foreach ($m['icons'] ?? [] as $ic) {
    $info = @getimagesize("$site/" . $ic['src']);
    pruefe($info && $info[0] . 'x' . $info[1] === $ic['sizes'], "Symbol {$ic['src']} hat {$ic['sizes']}");
}
proc_terminate($web);

// Aufraeumen
exec('rm -rf ' . escapeshellarg($tmp));

echo $fehler ? "\n$fehler FEHLER\n" : "\nAlles grün\n";
exit($fehler ? 1 : 0);
