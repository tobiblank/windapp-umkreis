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
copy("$ROOT/daten/regionen.json", "$tmp/regionen.json");
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
pruefe($rc2 === 0 && $zeilen === 1, "zweiter Lauf in derselben Stunde ruft nicht erneut ab ($zeilen Zeile)");
exec('php ' . escapeshellarg("$tmp/sammler.php") . ' --debug 2>&1', $aus3);
pruefe(in_array('vorhersage/0: unveraendert', $aus3, true), 'gleiche Werte werden nicht doppelt geschrieben');
$zaehl = json_decode(file_get_contents("$tmp/daten/.state/openmeteo.json"), true);
pruefe(($zaehl[$heute]['abrufe'] ?? 0) === 6, 'Open-Meteo-Zählung: 6 Abrufe (2 Läufe x 3 Quellen)');

// Eine fehlerhafte Antwort darf nichts kaputt machen
$z = json_decode(file($tmp . "/daten/vorhersage/$heute.ndjson")[0], true);
pruefe($z['regionen'] === ['ammersee', 'adria-nord'] && count($z['payload']) === 2, 'payload als Liste in Reihenfolge der Regionen');

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

// ---------------------------------------------------------------
echo "Sammler als GitHub Action\n";
$server = proc_open(['php', '-S', "127.0.0.1:$port", __DIR__ . '/fake_openmeteo.php'],
                    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$cmd = 'UMKREIS_TEST_URL=' . escapeshellarg("http://127.0.0.1:$port")
     . ' php ' . escapeshellarg("$ROOT/server/sammler.php")
     . ' --action ' . escapeshellarg("$ROOT/daten/regionen.json") . ' ' . escapeshellarg("$tmp/action") . ' 2>&1';
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
     . ' --action ' . escapeshellarg("$ROOT/daten/regionen.json") . ' ' . escapeshellarg("$tmp/kaputt") . ' 2>&1';
exec($cmd, $ausK, $rcK);
pruefe($rcK === 1 && str_contains(implode("\n", $ausK), 'vorhersage: Abruf fehlgeschlagen'), 'Action bei Fehler: Meldung und Exit 1');
proc_terminate($server);

// ---------------------------------------------------------------
echo "Seite\n";
$site = "$tmp/site";
exec('cp -r ' . escapeshellarg("$ROOT/server/web") . ' ' . escapeshellarg($site));
copy("$ROOT/daten/regionen.json", "$site/lib/regionen.json");
$sitePort = $port + 1;
$web = proc_open(['php', '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-S', "127.0.0.1:$sitePort", '-t', $site],
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
          '?sport=segeln&km=2500&ort=48.07,11.15'] as $q) {
    [$code, $html] = holen($sitePort, "index.php$q");
    pruefe($code === 200 && ohne_php_meldung($html), "index.php$q: 200, keine PHP-Meldung");
}
[, $html] = holen($sitePort, 'index.php?km=1000');
pruefe(str_contains($html, 'Nördliche Adria') && str_contains($html, 'Ammersee') && str_contains($html, 'Bora'), 'Rangliste zeigt Adria (Bora) und Ammersee');
[, $html] = holen($sitePort, 'index.php?km=100');
pruefe(!str_contains($html, 'Nördliche Adria') && str_contains($html, 'Ammersee'), 'Umkreis 100 km: nur Ammersee');
[, $html] = holen($sitePort, 'index.php?ort=45.6789,13.1234');
pruefe(str_contains($html, 'value="45.7,13.1"'), 'Standort wird auf 0,1 Grad gerundet');
[, $html] = holen($sitePort, 'index.php?sport=<script>');
pruefe(!str_contains($html, '<script>alert') && ohne_php_meldung($html), 'unbekannte Sportart: Standard, nichts ungefiltert ausgegeben');

file_put_contents("$site/config.php", '<?php return ' . var_export(['data_dir' => "$tmp/leer"], true) . ';');
[$code, $html] = holen($sitePort, 'index.php');
pruefe($code === 200 && str_contains($html, 'Noch keine Vorhersagedaten') && ohne_php_meldung($html), 'ohne Daten: Hinweis statt Fehler');
foreach (['impressum.html', 'datenschutz.html', 'stil.css'] as $f) {
    [$code] = holen($sitePort, $f);
    pruefe($code === 200, "$f erreichbar");
}
proc_terminate($web);

// Aufraeumen
exec('rm -rf ' . escapeshellarg($tmp));

echo $fehler ? "\n$fehler FEHLER\n" : "\nAlles grün\n";
exit($fehler ? 1 : 0);
