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
// Sammler im Test ohne Wartezeiten (Pause vor Ensemble-Teilen, Wiederholungen)
putenv('UMKREIS_WARTE_429=0');
putenv('UMKREIS_WARTE_FEHLER=0');
putenv('UMKREIS_PAUSE_ENSEMBLE=0');
putenv('UMKREIS_PAUSE_NACHHOLEN=0');
require "$ROOT/server/web/lib/regionen.php";
require "$ROOT/server/web/lib/bewertung.php";
require "$ROOT/server/web/lib/vorrechnen.php";
require "$ROOT/server/web/lib/karte.php";
require "$ROOT/server/web/lib/fahrzeit.php";
require "$ROOT/server/web/lib/roadtrip.php";
require "$ROOT/server/web/lib/stunden.php";
require "$ROOT/server/web/lib/neopren.php";
require "$ROOT/server/web/lib/amstueck.php";
require "$ROOT/server/web/lib/maps.php";

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

echo "Maps-Knöpfe\n";
$mz = maps_ziele(['name' => 'Ammersee', 'land' => 'DE', 'punkt' => [48.0, 11.135], 'spots' => [
    ['name' => 'Stegen', 'punkt' => [48.0489, 11.1614]], ['name' => 'Herrsching']]]);
pruefe($mz[0] === ['Stegen', 'https://www.google.com/maps/search/?api=1&query=48.0489%2C11.1614', true], 'Spot mit punkt: Koordinaten');
pruefe($mz[1] === ['Herrsching', 'https://www.google.com/maps/search/?api=1&query=Herrsching%2C%20Ammersee%2C%20Germany', false], 'Spot ohne punkt: Suche Spot, Region, Land');
$mz = maps_ziele(['name' => 'Zandvoort', 'land' => 'NL', 'punkt' => [52.38, 4.48], 'anfahrt' => [52.375, 4.525], 'spots' => [['name' => 'Zandvoort']]]);
pruefe($mz === [['Zandvoort', 'https://www.google.com/maps/search/?api=1&query=52.375%2C4.525', true]], 'einziger Spot ohne punkt: anfahrt der Region');
$mz = maps_ziele(['name' => 'Walchensee', 'land' => 'DE', 'punkt' => [47.59, 11.34], 'spots' => []]);
pruefe($mz === [['Walchensee', 'https://www.google.com/maps/search/?api=1&query=47.59%2C11.34', true]], 'Region ohne Spots: punkt');
$mh = maps_html(['name' => 'Walchensee', 'land' => 'DE', 'punkt' => [47.59, 11.34], 'spots' => []]);
pruefe(str_contains($mh, '<a class="maps-knopf" href="https://www.google.com/maps/search/?api=1&amp;query=47.59%2C11.34"') && str_contains($mh, '</svg>Maps</a>'), 'Region ohne Spots: ein Knopf „Maps“');

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
$mitLink['regionen'][0]['thermik'] = true;
$mitLink['regionen'][0]['anreise'] = ['flug', 'schiff'];
$mitLink['regionen'][0]['flughafen'] = ['name' => 'X (XXX)', 'transfer_min' => '30'];
pruefe(count(regionen_pruefen($mitLink)) === 2, 'anreise nur auto/flug, transfer_min ganze Minuten');
$mitLink['regionen'][0]['anreise'] = ['flug'];
$mitLink['regionen'][0]['flughafen'] = ['name' => 'X (XXX)', 'transfer_min' => 30];
pruefe(regionen_pruefen($mitLink) === [] && region_anreise(['id' => 'y']) === ['auto'], 'gültiges Flugziel; ohne anreise: Auto');
$mitLink['regionen'][0]['infos'] = [['art' => 'warnung', 'text' => 'x'], ['art' => 'sperre', 'text' => 'y', 'monate' => [13, 2]]];
pruefe(count(regionen_pruefen($mitLink)) === 2, 'infos: unbekannte Art und falsche Monate werden gemeldet');
$mitLink['regionen'][0]['infos'] = [['art' => 'sperre', 'text' => 'Kiten verboten', 'monate' => [11, 12], 'sportarten' => ['kite'], 'quelle' => 'q']];
pruefe(regionen_pruefen($mitLink) === [], 'infos: gültige Sperre');
$sperrR = $mitLink['regionen'][0];
pruefe(region_sperre($sperrR, SPORTARTEN['kite_twintip'], 11) === 'Sperre: Kiten verboten' && region_sperre($sperrR, SPORTARTEN['windsurf'], 11) === null
       && region_sperre($sperrR, SPORTARTEN['kitefoil'], 5) === null, 'Sperre trifft Kite im November, nicht Windsurf, nicht im Mai');

echo "Tagesfenster\n";
[$ab, $bis] = tagesfenster('2026-11-07', new DateTimeZone('Europe/Berlin'), 48.0, 11.13);
pruefe($ab === 9 && $bis === 17, "Ammersee 7.11.: 9 bis 17 Uhr (ist $ab bis $bis)");
[$ab, $bis] = tagesfenster('2026-06-21', new DateTimeZone('Europe/Berlin'), 48.0, 11.13);
pruefe($ab === 9 && $bis === 21, "Ammersee 21.6.: 9 bis 21 Uhr (ist $ab bis $bis)");
pruefe(tagesfenster('2026-06-21', new DateTimeZone('Europe/Berlin'), 47.645, 11.345, 6)[0] === 6, 'fenster_ab 6: im Juni ab 6 Uhr');
pruefe(tagesfenster('2026-12-10', new DateTimeZone('Europe/Berlin'), 47.645, 11.345, 6)[0] === 8, 'fenster_ab 6: im Dezember erst ab Sonnenaufgang (8 Uhr)');
pruefe(region_fenster_ab(['id' => 'x']) === FENSTER_AB && region_fenster_ab(['fenster_ab' => 6]) === 6, 'Beginn je Region aus dem Katalog');

echo "Landkarte\n";
$svg = (string)file_get_contents("$ROOT/server/web/karte.svg");
preg_match('/viewBox="0 0 ([\d.]+) ([\d.]+)" data-lon0="([-\d.]+)" data-lat-max="([-\d.]+)" data-k="([\d.]+)"/', $svg, $kv);
pruefe($kv && (float)$kv[3] === KARTE_LON0 && (float)$kv[4] === KARTE_LAT_MAX && (float)$kv[5] === KARTE_K
       && abs((float)$kv[1] - KARTE_BREITE) < 0.05 && abs((float)$kv[2] - KARTE_HOEHE) < 0.05,
       'karte.svg und lib/karte.php nutzen dieselbe Projektion');
pruefe(str_contains($svg, 'id="land"') && str_contains($svg, 'Natural Earth') && strlen($svg) < 200000, 'karte.svg: Pfad land, Quelle genannt, unter 200 KB');
[$x, $y] = karte_punkt(KARTE_LAT_MAX, KARTE_LON0);
pruefe(abs($x) < 1e-9 && abs($y) < 1e-9, 'Ecke oben links ist (0, 0)');
[$x, $y] = karte_punkt(22.0, 36.0);
pruefe(abs($x - KARTE_BREITE) < 0.05 && abs($y - KARTE_HOEHE) < 0.05, 'Ecke unten rechts passt zur Grundlage');
$alle = [];
foreach ($katalog['regionen'] as $r) {
    [$x, $y] = karte_punkt((float)$r['punkt'][0], (float)$r['punkt'][1]);
    if ($x < 0 || $x > KARTE_BREITE || $y < 0 || $y > KARTE_HOEHE) {
        $alle[] = $r['id'];
    }
}
pruefe($alle === [], 'alle Regionen des Katalogs liegen auf der Karte' . ($alle ? ': außerhalb ' . implode(', ', $alle) : ''));
$a = karte_ausschnitt([karte_punkt(48.07, 11.15)]);
pruefe(abs($a[3] / $a[2] - KARTE_SEITE) < 0.01 && $a[2] >= KARTE_MIN_GRAD * KARTE_K, 'ein Punkt: Mindestausschnitt im Seitenverhältnis');
$a = karte_ausschnitt([karte_punkt(63, -19), karte_punkt(23, 35)]);
pruefe($a[0] >= 0 && $a[1] >= 0 && $a[0] + $a[2] <= KARTE_BREITE + 0.1 && $a[1] + $a[3] <= KARTE_HOEHE + 0.1, 'großer Ausschnitt bleibt innerhalb der Grundlage');
$np = ['a' => ['xy' => [100, 100], 'name' => 'Erster', 'rang' => 1.0], 'b' => ['xy' => [101, 100.5], 'name' => 'Zweiter', 'rang' => 0.5],
       'c' => ['xy' => [140, 100], 'name' => 'Weit weg', 'rang' => 0.0]];
$nm = karte_namen($np, 1.0, [80, 80, 80, 88]);
pruefe(($nm['a'] ?? null) !== null && ($nm['c'] ?? null) === 'rechts' && count($nm) <= 3, 'Namen: der beste bekommt einen, freie Punkte rechts');
pruefe(!isset($nm['b']) || $nm['b'] !== $nm['a'], 'Namen: zwei nahe Punkte nicht auf derselben Seite');
// Reschensee/Silvaplana (9.11.2026): Name rechts von B laege direkt unter A und liest sich als Name von A
$nm = karte_namen(['a' => ['xy' => [100, 100], 'name' => 'Reschensee', 'rang' => 1.0], 'b' => ['xy' => [97.5, 102.0], 'name' => 'Silvaplana', 'rang' => 0.0]], 1.0, [60, 60, 80, 88]);
pruefe(($nm['a'] ?? null) === 'rechts' && ($nm['b'] ?? null) !== 'rechts', 'Namen: kein Name direkt neben einem fremden Punkt');
// Orte zur Orientierung (seit 10.11.2026)
$aus = karte_ausschnitt([karte_punkt(48.07, 11.15), karte_punkt(46.5, 11.4)]);
$or = karte_orte($aus, $aus[2] / 42, [], []);
$on = array_column($or, 'name');
pruefe(in_array('Innsbruck', $on, true) && in_array('München', $on, true) && !in_array('Paris', $on, true), 'Orte im Alpen-Ausschnitt: München, Innsbruck, kein Paris');
$ib = karte_punkt(47.269, 11.404);
$or2 = karte_orte($aus, $aus[2] / 42, [$ib], []);
pruefe(!in_array('Innsbruck', array_column($or2, 'name'), true), 'Ort unter einem Regionspunkt entfaellt');
$gross = karte_orte(karte_ausschnitt([karte_punkt(63, -19), karte_punkt(23, 35)]), 1.0, [], []);
pruefe(!array_filter($gross, fn($o) => $o['stufe'] === 2), 'Europa-Ausschnitt: nur grosse Staedte');
pruefe(str_contains(karte_orte_svg($or, 1.0), '<g class="orte"') && karte_orte_svg([], 1.0) === '', 'Orte als SVG');
$fest = karte_orte($aus, $aus[2] / 42, [$ib], [], KARTE_ORTE_SCHNEE, $fb);
pruefe(in_array('Innsbruck', array_column($fest, 'name'), true) && count($fb ?? []) === count($fest), 'feste Orte (Schnee) auch unter einem Regionspunkt');
$nmv = karte_namen(['a' => ['xy' => [100, 100], 'name' => 'Arlberg', 'rang' => 1.0]], 1.0, [60, 60, 80, 88], $nb, [[101, 98, 140, 102], [60, 98, 99, 102]]);
pruefe(!isset($nmv['a']), 'Regionsname weicht festen Orten aus');
$e = ['tage' => ['t1' => ['det' => ['geht' => true], 'ens' => ['chance' => 0.3]], 't2' => ['det' => ['geht' => false], 'ens' => ['chance' => 0.8]],
                 't3' => ['det' => ['geht' => false], 'ens' => null, 'vorbei' => true]]];
pruefe(karte_wert($e, null)['tag'] === 't2' && abs(karte_wert($e, 't1')['chance'] - 0.3) < 1e-9 && karte_wert($e, 't3')['tag'] === null,
       'Punktwert: ohne Tag die beste Chance, mit Tag dieser Tag, vorbei zählt nicht');

echo "Roadtrip\n";
$rtT = ['d0', 'd1', 'd2', 'd3', 'd4'];
$rtTag = fn($c) => ['det' => ['geht' => $c !== null && $c >= 0.5, 'von' => 10, 'bis' => 17], 'ens' => $c === null ? null : ['chance' => $c], 'fenster' => [9, 17]];
$rtE = fn($id, $p, $min, $cs) => ['region' => ['id' => $id, 'name' => $id, 'punkt' => $p], 'fahrt' => ['min' => $min], 'km' => 50,
                                  'tage' => array_combine($rtT, array_map($rtTag, $cs))];
$rtM = [15.0, 0.75];
$rtL = [$rtE('A', [47.6, 11.2], 60, [0.0, 0.9, 0.9, 0.0, 0.0]),     // nah, zwei Tage
        $rtE('B', [47.6, 12.0], 70, [0.0, 0.0, 0.0, 0.9, 0.0]),     // 60 km neben A, danach
        $rtE('F', [46.2, -1.4], 720, [0.0, 0.9, 0.9, 0.9, 0.9]),    // 12 h entfernt, vier Tage
        $rtE('C', [50.5, 7.0], 330, [0.9, 0.0, 0.0, 0.0, 0.0])];    // nur ein Tag, weit weg von A und B
$rtV = roadtrip_vorschlaege($rtL, $rtT, $rtM, 7.0);
$rtK = array_map(fn($v) => implode(' ', array_map(fn($ti, $x) => $rtT[$ti] . $rtL[$x['i']]['region']['id'] . ($x['art'] === 'wind' ? '' : '-' . $x['art']),
                                                  array_keys($v['tage']), $v['tage'])), $rtV);
pruefe(($rtK[0] ?? '') === 'd1A d2A d3B', 'bester Trip: zwei Tage A, abends weiter ins nahe B (ist ' . ($rtK[0] ?? '-') . ')');
pruefe(isset($rtV[0]['etappen'][3]) && $rtV[0]['rueck']['abends'] && $rtV[0]['start'] === 1, 'Etappe vor dem dritten Tag, Rückfahrt am Abend, Abreise am Morgen des ersten Windtags');
pruefe(!array_filter($rtK, fn($k) => $k === 'd1A d2A'), 'A allein fällt weg (Teil des besseren Trips)');
$rtF = array_values(array_filter($rtV, fn($v) => $rtL[$v['hin']['i']]['region']['id'] === 'F'))[0] ?? null;
pruefe($rtF && $rtF['tage'][0]['art'] === 'fahrt' && $rtF['hin']['ankunft'][0] === 1 && $rtF['windtage'] === 4 && !$rtF['rueck']['abends']
       && $rtF['rueck']['heim_tag'] === 6, '12 h entfernt: Fahrtag mit Übernachtung, vier Windtage, Rückfahrt über zwei Tage');
pruefe($rtF && $rtF['bilanz'] < $rtV[0]['bilanz'], 'weite Fahrt kostet: vier Tage weit weg zählen weniger als drei Tage in der Nähe');
pruefe(!array_filter($rtK, fn($k) => str_contains($k, 'C')), 'ein einzelner Windtag ist kein Roadtrip');
$rtP = [$rtE('A', [47.6, 11.2], 60, [0.0, 0.9, 0.0, 0.9, 0.0])];
$rtPV = roadtrip_vorschlaege($rtP, $rtT, $rtM, 7.0);
pruefe(($rtPV[0]['tage'][2]['art'] ?? null) === 'pause' && count($rtPV) === 1, 'flauer Tag dazwischen: Pause, der Trip geht weiter');
pruefe(roadtrip_fahrt(0, 6.0, 720) === [1, 8.0] && roadtrip_fahrt(2, 6.0, 90) === [2, 7.5], 'Fahrt: 12 h mit Übernachtung (10 h am Tag), 1:30 h am selben Morgen');
pruefe(roadtrip_fahrt(0, 6.0, 605) === [0, 6.0 + 605 / 60], 'Fahrt: 10:05 h ohne Übernachtung (bis eine Stunde über dem Tageslimit)');
$rtW = [$rtE('A', [47.6, 11.2], 60, [0.0, 0.9, 0.0, 0.9, 0.0]), $rtE('B', [47.6, 11.6], 65, [0.0, 0.0, 0.9, 0.0, 0.0])];
$rtWV = roadtrip_vorschlaege($rtW, $rtT, $rtM, 7.0);
$rtWK = array_map(fn($v) => implode(' ', array_map(fn($x) => $rtW[$x['i']]['region']['id'], $v['tage'])), $rtWV);
pruefe($rtWV && !array_filter($rtWK, fn($k) => preg_match('/A.*B.*A|B.*A.*B/', $k)), 'keine Region zweimal: kein A → B → A (' . implode(' | ', $rtWK) . ')');
$rtAB = entfernung_km([47.6, 11.2], [47.6, 12.0]) * ROADTRIP_UMWEG;
pruefe(abs($rtV[0]['km'] - (50 * ROADTRIP_UMWEG + $rtAB + 50 * ROADTRIP_UMWEG)) < 0.01, 'Kilometer: Hin, Etappe, Rück (geschätzt Luftlinie x 1,25)');
pruefe(roadtrip_wert(['vorbei' => true, 'det' => ['geht' => true], 'ens' => ['chance' => 1.0]]) === null
       && roadtrip_wert(['det' => ['geht' => true], 'ens' => null]) === ROADTRIP_OHNE_ENSEMBLE
       && roadtrip_wert(['det' => ['geht' => false], 'ens' => ['chance' => 0.49]]) === null, 'guter Tag: nicht vorbei, ab 50 %, ohne Ensemble mit Fenster');

// ---------------------------------------------------------------
echo "Sammler gegen Open-Meteo-Ersatz\n";
$tmp = sys_get_temp_dir() . '/umkreis-test-' . getmypid();
@mkdir("$tmp/web/lib", 0700, true);
@mkdir("$tmp/daten", 0700, true);
copy("$ROOT/server/web/lib/regionen.php", "$tmp/web/lib/regionen.php");
foreach (['schnee.php', 'lawine.php', 'stationen.php', 'bewertung.php', 'abruf.php'] as $lib) {
    copy("$ROOT/server/web/lib/$lib", "$tmp/web/lib/$lib");
}
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
pruefe(($zaehl[$heute]['abrufe'] ?? 0) === 20, 'Open-Meteo-Zählung: 20 Abrufe (2 Läufe x 10: Vorhersage und Ensemble je See/Meer, Welle, Wasser, Druck und Höhenwind je Modell/Ensemble)');

// Eine fehlerhafte Antwort darf nichts kaputt machen
$zz = array_map(fn($l) => json_decode($l, true), file($tmp . "/daten/vorhersage/$heute.ndjson"));
pruefe($zz[0]['regionen'] === ['ammersee', 'testsee-druck', 'testsee-hoehe'] && count($zz[0]['payload']) === 3 && $zz[1]['regionen'] === ['adria-nord'],
       'payload als Liste, Abrufe getrennt nach See und Meer');
pruefe($zz[0]['payload'][0]['test_cell_selection'] === null && $zz[1]['payload'][0]['test_cell_selection'] === 'sea',
       'Meer-Regionen mit cell_selection=sea, Seen ohne');
$ensZ = array_map(fn($l) => json_decode($l, true), file($tmp . "/daten/ensemble/$heute.ndjson"));
pruefe(($ensZ[1]['payload'][0]['test_cell_selection'] ?? null) === 'sea', 'auch das Ensemble fragt für das Meer eine Wasser-Zelle an');

echo "Seewasser (Alplakes)\n";
$sw = array_map(fn($l) => json_decode($l, true), file("$tmp/daten/seewasser/$heute.ndjson") ?: []);
pruefe(count($sw) === 1 && $sw[0]['ok'] && $sw[0]['regionen'] === ['ammersee'], 'seewasser: eine Zeile, nur der See mit alplakes-Schlüssel');
$swH = $sw[0]['payload'][0]['hourly'] ?? [];
pruefe(str_starts_with(json_encode($sw[0]['payload'][0]), '{"latitude":') && ($sw[0]['payload'][0]['see'] ?? null) === 'ammersee',
       'Eintrag beginnt mit latitude (payload_teile) und nennt den See');
$i1 = array_search(gmdate('Y-m-d') . 'T01:00', $swH['time'] ?? [], true);
$swW = fn(int $k) => (float)$swH['water_temperature'][$i1 + $k];
pruefe($i1 !== false && abs($swW(0) - 10) < 1e-9 && abs($swW(1) - 11) < 1e-9 && abs($swW(2) - 12) < 1e-9 && abs($swW(3) - 13) < 1e-9,
       'Stunden zwischen den 3-Stunden-Werten linear gefüllt (10, 11, 12, 13), auf volle Stunden gerundet');
$swT = $swH['time'] ?? [];
pruefe(count($swT) === count(array_unique($swT)) && count($swT) === 3 * 39 + 1, 'Stundenreihe lückenlos ohne doppelte Zeiten (' . count($swT) . ')');
pruefe(in_array('seewasser: unveraendert', $aus3, true), 'seewasser: gleiche Werte werden nicht doppelt geschrieben');
$kat = json_decode(file_get_contents(__DIR__ . '/regionen_test.json'), true);
$kat['regionen'][1]['alplakes'] = 'adria';
$kat['regionen'][2]['alplakes'] = 'Gibts Nicht';
pruefe(count(regionen_pruefen($kat)) === 2, 'Prüfung: alplakes nur bei Seen und nur a-z, 0-9');

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
$amT = $am['temp'] ?? [];
pruefe(isset($amT['wasser']) && $amT['wasser'] >= 10 && $amT['wasser'] <= 13 && $amT['wasser_quelle'] === 'see',
       'Ammersee morgen: Wassertemperatur aus Alplakes (10–13 °C), Quelle See' . (isset($amT['wasser']) ? sprintf(' (ist %.2f)', $amT['wasser']) : ''));
pruefe(neopren($amT)['text'] !== null, 'Ammersee: Neopren-Empfehlung wie am Meer');
$adT = $rl[0]['tage'][$morgen]['temp'] ?? [];
pruefe(($adT['wasser'] ?? null) == 14.0 && $adT['wasser_quelle'] === 'meer', 'Adria morgen: Wasser weiter aus der Marine API (14 °C)');

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
// Fruehes Fenster (seit 10.11.2026, Foehn am Kochelsee): fenster_ab und Regel bis 10 Uhr
$frueh = $byId['testsee-druck']['region'];
$frueh['fenster_ab'] = 6;
$frueh['windlagen'] = [['name' => 'Föhn', 'sektor' => [150, 230], 'druck' => $frueh['windlagen'][0]['druck'], 'zeit' => [6, 10], 'staerke' => [16, 28]]];
$fr = region_bewerten($frueh, region_daten($frueh, $stand), $kite, [$morgen], new DateTimeImmutable('today 06:00', new DateTimeZone('Europe/Berlin')))[$morgen];
$sonnenAb = tagesfenster($morgen, new DateTimeZone('Europe/Berlin'), (float)$frueh['punkt'][0], (float)$frueh['punkt'][1], 6)[0];
pruefe($fr['det']['geht'] && $fr['det']['von'] === $sonnenAb && $fr['det']['bis'] === 10 && $fr['fenster'][0] === $sonnenAb && $sonnenAb < FENSTER_AB,
       "fenster_ab 6: Morgenregel geht ab Sonnenaufgang ($sonnenAb Uhr) bis 10 Uhr" . ($fr['det']['geht'] ? " (ist {$fr['det']['von']}-{$fr['det']['bis']})" : ' (geht nicht: ' . $fr['det']['grund'] . ')'));
unset($frueh['fenster_ab']);
$fr9 = region_bewerten($frueh, region_daten($frueh, $stand), $kite, [$morgen], new DateTimeImmutable('today 06:00', new DateTimeZone('Europe/Berlin')))[$morgen];
pruefe(!$fr9['det']['geht'], 'ohne fenster_ab: dieselbe Morgenregel bleibt vor dem Fenster ab 9 Uhr (1 Stunde reicht nicht)');
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
$swA = glob("$tmp/action/seewasser/" . gmdate('Y-m-d') . '_*.ndjson.gz');
pruefe(count($swA) === 1 && isset($standA['seewasser']['ammersee']['hourly']['water_temperature']), 'seewasser: gezippt je Lauf und von daten_laden gelesen');
// Ein See, den Alplakes nicht kennt (HTTP 400): Fehler melden, der andere See bleibt
$katX = json_decode(file_get_contents(__DIR__ . '/regionen_test.json'), true);
$katX['regionen'][2]['alplakes'] = 'gibtsnicht';
file_put_contents("$tmp/regionen_x.json", json_encode($katX));
exec('UMKREIS_TEST_URL=' . escapeshellarg("http://127.0.0.1:$port") . ' php ' . escapeshellarg("$ROOT/server/sammler.php")
     . ' --action ' . escapeshellarg("$tmp/regionen_x.json") . ' ' . escapeshellarg("$tmp/action_x") . ' 2>&1', $ausX, $rcX);
$standX = daten_laden("$tmp/action_x", ['seewasser']);
pruefe($rcX === 1 && str_contains(implode("\n", $ausX), 'seewasser: Abruf fehlgeschlagen') && $ausX === ['Windapp Umkreis Sammler:', '- seewasser: Abruf fehlgeschlagen (Einzelheiten in der Datei dieses Laufs)'],
       'unbekannter See: nur seewasser meldet einen Fehler' . ($rcX !== 1 ? '' : ' (' . implode(' | ', $ausX) . ')'));
pruefe(isset($standX['seewasser']['ammersee']) && !isset($standX['seewasser']['testsee-druck']), 'unbekannter See: Ammersee trotzdem gespeichert');
exec('php ' . escapeshellarg("$ROOT/server/sammler.php") . ' --action 2>&1', $ausF, $rcF);
pruefe($rcF === 1, 'Action ohne Pfade: Hinweis und Exit 1');
// Fehlgeschlagener Abruf: Open-Meteo nicht erreichbar
$cmd = 'UMKREIS_TEST_URL=http://127.0.0.1:1 php ' . escapeshellarg("$ROOT/server/sammler.php")
     . ' --action ' . escapeshellarg(__DIR__ . "/regionen_test.json") . ' ' . escapeshellarg("$tmp/kaputt") . ' 2>&1';
exec($cmd, $ausK, $rcK);
pruefe($rcK === 1 && str_contains(implode("\n", $ausK), 'vorhersage: Abruf fehlgeschlagen'), 'Action bei Fehler: Meldung und Exit 1');
exec($cmd . ' --debug', $ausW);
pruefe(str_contains(implode("\n", $ausW), 'keine Antwort, warte 0 s (2. Wiederholung)'), 'Action bei Fehler: zwei Wiederholungen je Teil');
pruefe(substr_count(implode("\n", $ausW), '(1. Wiederholung)') === 3 && preg_match('/Nachholen: \d+ Teile nach \d+ s, uebersprungen/', implode("\n", $ausW)) === 1,
       'Ausfall: nach drei Teilen ohne Antwort keine Wiederholungen mehr, kein Nachholen');
// Nachholen (seit 14.11.2026): die ersten drei Vorhersage-Anfragen scheitern (503), der Teil
// wird am Ende des Laufs noch einmal geholt
$ausfallF = "$tmp/ausfall";
file_put_contents($ausfallF, '3');
$portN = $port + 11;
$serverN = proc_open(['php', '-S', "127.0.0.1:$portN", __DIR__ . '/fake_openmeteo.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipesN, null,
                     array_merge(getenv(), ['FAKE_AUSFALL' => $ausfallF]));
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $portN); $i++) {
    usleep(100000);
}
exec('UMKREIS_TEST_URL=' . escapeshellarg("http://127.0.0.1:$portN") . ' php ' . escapeshellarg("$ROOT/server/sammler.php")
     . ' --action ' . escapeshellarg(__DIR__ . "/regionen_test.json") . ' ' . escapeshellarg("$tmp/action_n") . ' --debug 2>&1', $ausN, $rcN);
proc_terminate($serverN);
$standN = daten_laden("$tmp/action_n", ['vorhersage']);
pruefe($rcN === 0 && str_contains(implode("\n", $ausN), 'vorhersage/0: nachgeholt') && count($standN['vorhersage']) === count($standA['vorhersage'])
       && count(glob("$tmp/action_n/vorhersage/*.ndjson.gz")) === 1 && !glob("$tmp/action_n/vorhersage/*.ndjson"),
       'Action: fehlgeschlagener Teil am Ende nachgeholt, kein Fehler, Datei gezippt' . ($rcN === 0 ? '' : ': ' . implode(' | ', array_slice($ausN, -4))));
proc_terminate($server);

// ---------------------------------------------------------------
echo "Stundenwerte\n";
$adriaR = array_values(array_filter($regionen, fn($r) => $r['id'] === 'adria-nord'))[0];
$datA = ['vorhersage' => $standA['vorhersage']['adria-nord'], 'ensemble' => $standA['ensemble']['adria-nord'], 'welle' => $standA['welle']['adria-nord'] ?? null];
$stA = region_stunden($adriaR, $datA, $kite, $morgen);
$fahrbar = array_keys(array_filter($stA['stunden'], fn($z) => $z['fahrbar']));
$detA = $stA['bewertung']['det'];
pruefe($fahrbar && min($fahrbar) === $detA['von'] && max($fahrbar) + 1 === $detA['bis'], 'fahrbare Stunden = Fenster der Bewertung (' . implode(',', $fahrbar) . ')');
pruefe(count($stA['modelle']) >= 2 && abs($stA['stunden'][$detA['von']]['ens_anteil'] - 1.0) < 1e-9
       && $stA['stunden'][$detA['von']]['ens_p10'] <= $stA['stunden'][$detA['von']]['ens_p90'], 'je Stunde Modelle, Anteil der Läufe, Spanne p10 bis p90');
pruefe($stA['von'] === $stA['fenster'][0] - STUNDEN_RAND && perzentil([1, 2, 3, 4], 0.5) === 2.5, 'gezeigt: Fenster mit Rand; Perzentil interpoliert');
pruefe(region_stunden($adriaR, ['vorhersage' => null], $kite, $morgen) === null, 'ohne Vorhersage: keine Stundenwerte');
// Wetter (seit 13.11.2026): Symbol und Luft je Stunde und Tag
$wz = $stA['stunden'][$detA['von']]['wetter'] ?? null;
pruefe($wz && $wz['temp'] == 12.0 && $wz['wolken'] == 40 && $wz['nied'] == 0.0 && wetter_art($wz) === 'wolkig', 'Stunde: Luft 12 °C, 40 % Wolken, trocken = teils sonnig');
$wtA = $stA['bewertung']['wetter'] ?? null;
pruefe($wtA && $wtA['temp'] == 12.0 && $wtA['nass'] == 0.0 && !$wtA['gewitter'] && wetter_symbol($wtA) === '⛅', 'Tag: Wetter im Fenster');
$wSt = [];
for ($s = 9; $s < 17; $s++) {
    $wSt[$s] = ['wolken' => 90.0, 'nied' => $s < 11 ? 1.0 : 0.0, 'gewitter' => false, 'temp' => 8.0 + $s / 4];
}
$wTag = wetter_tag($wSt, [9, 17]);
pruefe(abs($wTag['nass'] - 0.25) < 1e-9 && wetter_art($wTag) === 'schauer' && $wTag['temp_min'] == 10.25 && $wTag['temp_max'] == 12.0,
       'Tag: 2 von 8 Stunden nass = Schauer, Temperatur min/max');
pruefe(wetter_art(['nass' => 0.6, 'temp' => 0.5, 'wolken' => 100.0]) === 'schnee' && wetter_art(['nass' => 0.6, 'temp' => 8.0]) === 'regen'
       && wetter_art(['nass' => 0.0, 'gewitter' => true]) === 'gewitter' && wetter_art(['nass' => 0.0, 'wolken' => 10.0]) === 'sonnig'
       && wetter_art(['nass' => 0.0, 'wolken' => 80.0]) === 'bedeckt' && wetter_art(['nied' => 0.5, 'temp' => 15.0]) === 'regen'
       && wetter_art(null) === null && wetter_tag([], [9, 17]) === null,
       'Wetterart: Gewitter, Schnee, Regen, sonnig, bewölkt; Stunde nach Niederschlag');
pruefe(grad_text(-0.4) === '0°' && grad_text(-2.6) === "\u{2212}3°" && grad_text(null) === null, 'Grad: kein „−0“, echtes Minus');

// ---------------------------------------------------------------
echo "Wasser und Neopren\n";
$tA = region_bewerten($adriaR, $datA + ['wasser' => $standA['wasser']['adria-nord'] ?? null], $kite, [$morgen])[$morgen]['temp'];
pruefe(abs(($tA['wasser'] ?? 0) - 14.0) < 1e-9 && abs(($tA['luft'] ?? 0) - 12.0) < 1e-9 && $tA['kn'] > 14, 'Adria morgen: Wasser 14 °C, Luft 12 °C, Wind im Fenster');
pruefe(neopren(['wasser' => 14.0, 'luft' => 12.0, 'kn' => 20.0])['text'] === '5/4 mm, Schuhe', 'Wasser 14 °C, Luft 12 °C: 5/4 mm, Schuhe');
pruefe(neopren(['wasser' => 19.0, 'luft' => 25.0, 'kn' => 18.0])['text'] === '3/2 mm', 'Wasser 19 °C, warme Luft: 3/2 mm');
$nk = neopren(['wasser' => 16.0, 'luft' => 5.0, 'kn' => 22.0]);
pruefe($nk['waermer'] && $nk['text'] === '5/4 mm, Schuhe' && $nk['gefuehlt'] < 0, 'kalte Luft bei Wind (gefühlt unter 0 °C): eine Stufe wärmer');
pruefe(neopren(['wasser' => null, 'luft' => 10.0, 'kn' => 15.0])['text'] === null, 'ohne Wassertemperatur (See): keine Empfehlung');
pruefe(abs(gefuehlt(0.0, 20.0) - (-7.5)) < 0.6 && gefuehlt(15.0, 20.0) === 15.0, 'Windchill: 0 °C bei 20 kn etwa -7 °C; über 10 °C die Luft');

// ---------------------------------------------------------------
echo "Wellen\n";
$surf = SPORTARTEN['wellenreiten'];
$spotW = ['id' => 'w', 'ablandig' => [[45, 135]]];   // Land im Osten
$wi = fn($h, $p, $r) => ['hoehe' => $h, 'periode' => $p, 'richtung' => $r, 'swell' => $h, 'swell_periode' => $p, 'swell_richtung' => $r];
$stdW = fn($kn, $dir, $info) => ['kn' => $kn, 'richtung' => $dir, 'welle' => $info['hoehe'] ?? null, 'welle_info' => $info, 'gewitter' => false];
pruefe(stunde_surfbar($stdW(6, 270, $wi(1.4, 11, 280)), $surf, $spotW)[0] === true, 'Wellenreiten: 1,4 m, 11 s aus W, schwacher Wind: geht');
pruefe(stunde_surfbar($stdW(20, 90, $wi(1.4, 11, 280)), $surf, $spotW)[0] === true
       && stunde_surfbar($stdW(20, 90, $wi(1.4, 11, 280)), $surf, $spotW)[2]['charakter'] === 'Wind ablandig', 'starker ablandiger Wind: geht (ablandig gut)');
pruefe(stunde_surfbar($stdW(20, 270, $wi(1.4, 11, 280)), $surf, $spotW)[1] === 'zu viel Wind', 'starker auflandiger Wind: zu viel Wind');
pruefe(stunde_surfbar($stdW(6, 270, $wi(0.5, 11, 280)), $surf, $spotW)[1] === 'zu wenig Welle'
       && stunde_surfbar($stdW(6, 270, $wi(1.4, 6, 280)), $surf, $spotW)[1] === 'Periode zu kurz'
       && stunde_surfbar($stdW(6, 270, $wi(1.4, 11, 90)), $surf, $spotW)[1] === 'Dünung kommt vom Land', 'zu wenig Welle, Periode zu kurz, Dünung vom Land');
pruefe(stunde_fahrbar(['kn' => 6, 'richtung' => 270, 'welle_info' => null, 'gewitter' => false], $surf, [], $spotW)[1] === 'keine Wellendaten'
       && stunde_fahrbar(['kn' => 6, 'richtung' => 270], $surf, [], $spotW)[0] === false, 'ohne Wellendaten (See) und im Ensemble: nie');
$bewS = region_bewerten($adriaR, $datA, $surf, [$morgen])[$morgen];
pruefe($bewS['ens'] === null && !$bewS['det']['geht'], 'Wellenreiten an der Adria mit 0,3 m Dünung: geht nicht, keine Ensemble-Chance');
$bewW = region_bewerten($adriaR, $datA, $kite, [$morgen])[$morgen]['welle'];
pruefe(abs($bewW['hoehe'] - 0.8) < 1e-9 && abs($bewW['swell'] - 0.3) < 1e-9 && abs($bewW['periode'] - 5.0) < 1e-9, 'Welle je Tag: Höhe, Dünung, Periode gemittelt');
$kw = region_bewerten($adriaR, $datA, SPORTARTEN['kite_wave'], [$morgen])[$morgen];
pruefe(!$kw['det']['geht'] && isset($kw['ens']['chance']) && $kw['ens']['chance'] == 0, 'Kite Wave an der Adria (0,8 m Welle, mind. 1 m): auch die Ensemble-Chance ist 0');
$kw2 = region_bewerten($adriaR, $datA, SPORTARTEN['kite_twintip'], [$morgen])[$morgen];
pruefe(($kw2['ens']['chance'] ?? 0) > 0.9, 'Kite Twintip am selben Tag: Welle egal, Chance unverändert');
// Fehlende Daten (seit 8.11.2026): offen statt 0 %
$ohneW = region_bewerten($adriaR, ['welle' => null] + $datA, SPORTARTEN['kite_wave'], [$morgen])[$morgen];
pruefe($ohneW['offen'] && $ohneW['ens'] === null && $ohneW['det']['grund'] === 'noch keine Wellenvorhersage',
       'Kite Wave am Meer ohne Wellendaten: Tag offen, keine Chance statt 0 %');
$ohneV = region_bewerten($adriaR, ['vorhersage' => null, 'welle' => null] + $datA, SPORTARTEN['kite_wave'], [$morgen])[$morgen];
pruefe($ohneV['offen'] && $ohneV['det']['grund'] === 'noch keine Vorhersage', 'ohne Modellmittel und Welle: offen, "noch keine Vorhersage"');
$ohneVK = region_bewerten($adriaR, ['vorhersage' => null] + $datA, SPORTARTEN['kite_twintip'], [$morgen])[$morgen];
pruefe(!$ohneVK['offen'] && ($ohneVK['ens']['chance'] ?? 0) > 0.9 && $ohneVK['det']['grund'] === 'noch keine Vorhersage',
       'Kite Twintip ohne Modellmittel: Ensemble-Chance bleibt, Tag nicht offen');
pruefe(!$kw['offen'] && !$kw2['offen'], 'mit allen Daten: nicht offen');
$ensSee = ensemble_welle_einmischen([0 => ['2026-01-01' => [12 => ['kn' => 20]]]], [], SPORTARTEN['kite_wave'], false);
pruefe(!isset($ensSee[0]['2026-01-01'][12]['welle_fehlt']) && $ensSee[0]['2026-01-01'][12]['welle'] === null, 'See: Welle null, nicht "fehlt" (geht dort nie)');
$stOhneW = region_stunden($adriaR, ['welle' => null] + $datA, SPORTARTEN['kite_wave'], $morgen);
$zw = $stOhneW['stunden'][$stOhneW['fenster'][0]];
pruefe($zw['ens_anteil'] === null && $zw['ens_p50'] !== null, 'Stundenseite ohne Welle: Band bleibt, Anteil der Läufe offen');
$wA = region_bewerten($adriaR, $datA, $kite, [$morgen])[$morgen]['wind'];
pruefe($wA['fenster'] && abs($wA['richtung'] - 40) < 1 && abs($wA['kn'] - $kw2['det']['mittel_kn']) < 1e-9, 'Wind je Tag: im Fenster Mittel und Richtung (Bora 40 Grad)');
pruefe(tag_wind([10 => ['kn' => 10.0, 'richtung' => 350.0], 11 => ['kn' => 20.0, 'richtung' => 10.0]], ['geht' => false], [9, 17]) == ['kn' => 15.0, 'richtung' => 0.0, 'fenster' => false]
       && tag_wind([], ['geht' => false], [9, 17]) === null, 'Wind ohne Fenster: Tagesmittel, Richtung als Vektormittel; ohne Daten null');
pruefe(windfarbe(0.0) === 'rgb(96, 120, 205)' && windfarbe(12.0) === 'rgb(83, 198, 100)' && windfarbe(99.0) === 'rgb(120, 70, 185)', 'Windfarbe: Stützstellen, fließend, gekappt');
pruefe(duenung($wi(1.0, 9, 200)) === [1.0, 9, 200] && duenung(['hoehe' => 0.8, 'periode' => 5, 'richtung' => 60, 'swell' => null, 'swell_periode' => null, 'swell_richtung' => null]) === [0.8, 5, 60],
       'Dünung: Swell, ohne Swell die gesamte Welle');

$urner = $adriaR + ['infos' => [['art' => 'sperre', 'text' => 'Kiten verboten', 'monate' => [11, 12], 'sportarten' => ['kite']]]];
$bU = region_bewerten($urner, $datA, $kite, ['2030-11-20'])['2030-11-20'];
pruefe(!$bU['det']['geht'] && str_starts_with($bU['det']['grund'], 'Sperre') && (!isset($bU['ens']) || $bU['ens'] === null || $bU['ens']['chance'] == 0),
       'Bewertung: gesperrter Tag geht nicht, Ensemble-Chance 0');

// ---------------------------------------------------------------
echo "Verlauf über die Abrufe\n";
pruefe(verlauf_trend([], 0.5) === null && verlauf_trend([['ts' => 'x', 'chance' => 0.5]], null) === null, 'ohne frühere Werte oder ohne Chance: kein Verlauf');
$art = fn(array $w) => verlauf_trend(array_map(fn($c) => ['ts' => 'x', 'chance' => $c], array_slice($w, 0, -1)), end($w))['art'];
pruefe($art([0.7, 0.8, 0.75, 0.8]) === 'stabil', '70 → 80 → 75 → 80: stabil');
pruefe($art([0.8, 0.4]) === 'faellt' && $art([0.2, 0.5, 0.6]) === 'steigt', '80 → 40: fällt, 20 → 50 → 60: steigt');
pruefe($art([0.4, 0.9, 0.5]) === 'schwankt' && $art([0.6, 0.7]) === 'gleich', '40 → 90 → 50: schwankt, 60 → 70: kaum verändert');
pruefe($art([0.5, 0.65]) === 'steigt' && $art([0.5, 0.6, 0.65]) === 'stabil', 'genau 15 Punkte: bei 2 Werten steigt, bei 3 stabil');

// Frühere Läufe aus dem Action-Lauf bauen: vor 36 h alles flau, vor 24 h Kontrolllauf
// und Member 1-25 flau (mit Druckdaten), vor 12 h derselbe Modelllauf wie jetzt
$v = "$tmp/verlauf";
exec('cp -r ' . escapeshellarg("$tmp/action") . ' ' . escapeshellarg($v));
$jetztDatei = glob("$v/ensemble/*.ndjson.gz")[0];
$jetztTs = json_decode(ndjson_zeilen($jetztDatei)[0], true)['ts'];
$frueherSchreiben = function (string $q, string $quelle, int $stundenZurueck, callable $aendern) use ($v, $jetztTs) {
    $t = strtotime($jetztTs) - $stundenZurueck * 3600;
    $zeilen = [];
    foreach (ndjson_zeilen($quelle) as $z) {
        $z = json_decode($z, true);
        $z['ts'] = gmdate('Y-m-d\TH:i:s\Z', $t);
        foreach ($z['payload'] as $k => $p) {
            $z['payload'][$k]['hourly'] = $aendern($p['hourly']);
        }
        $zeilen[] = json_encode($z);
    }
    file_put_contents("$v/$q/" . gmdate('Y-m-d_Hi', $t) . '.ndjson.gz', gzencode(implode("\n", $zeilen) . "\n"));
};
$flau = function (array $h, int $bis) {
    foreach ($h as $k => $reihe) {
        if (preg_match('/^wind_speed_10m(_member(\d+))?$/', $k, $m) && (int)($m[2] ?? 0) <= $bis) {
            $h[$k] = array_map(fn($x) => $x === null ? null : 0.0, $reihe);
        }
    }
    return $h;
};
$frueherSchreiben('ensemble', $jetztDatei, 36, fn($h) => $flau($h, 50));
$frueherSchreiben('ensemble', $jetztDatei, 24, function ($h) use ($flau) {
    $h = $flau($h, 25);
    $h['wind_speed_10m'] = array_map(fn($x) => $x === null ? null : $x + 0.1, $h['wind_speed_10m']);   // Kontrolllauf leicht anders
    return $h;
});
$frueherSchreiben('ensemble', $jetztDatei, 12, fn($h) => $h);
$frueherSchreiben('druck_ensemble', glob("$v/druck_ensemble/*.ndjson.gz")[0], 24, fn($h) => $h);
$standV = daten_laden($v);
$tageV = [$morgen, $uebermorgen];
$jetztV = new DateTimeImmutable('today 06:00', new DateTimeZone('Europe/Berlin'));
$vl = verlauf_rechnen($regionen, $v, $standV, $kite, $tageV, $jetztV);
$va = array_column($vl['adria-nord'][$morgen] ?? [], 'chance');
pruefe(count($va) === 2 && abs($va[0]) < 1e-9 && abs($va[1] - 25 / 51) < 1e-9,
       'Adria morgen: zwei frühere Abrufe (0 %, 25 von 51), der gleiche Modelllauf vor 12 h zählt nicht' . ' (ist ' . implode(', ', array_map(fn($c) => round($c, 3), $va)) . ')');
$trA = verlauf_trend($vl['adria-nord'][$morgen], 1.0);
pruefe($trA['art'] === 'steigt' && count($trA['werte']) === 3, 'Adria morgen: steigt (0 → 49 → 100 %)');
$vd = $vl['testsee-druck'][$morgen] ?? [];
pruefe(count($vd) === 1 && abs($vd[0]['chance'] - 1.0) < 1e-9, 'Druckregel: nur der frühere Abruf mit Druckdaten zählt, Stärke hängt nicht am Modellwind');
pruefe(($vl['testsee-hoehe'][$morgen] ?? []) === [], 'Grundströmung ohne frühere Höhenwinddaten: kein Verlauf');
pruefe(verlauf_rechnen($regionen, "$tmp/action", $standA, $kite, $tageV, $jetztV)['adria-nord'] === [], 'nur ein Abruf: kein Verlauf');
$vc1 = verlauf_mit_cache($regionen, $v, "$tmp/cache", $standV, $kite, $tageV, $jetztV);
$cacheDateien = glob("$tmp/cache/verlauf-*.json");
$vc2 = verlauf_mit_cache($regionen, $v, "$tmp/cache", $standV, $kite, $tageV, $jetztV);
pruefe($vc1 == $vl && $vc2 == $vl && count($cacheDateien) === 1, 'Zwischenspeicher: eine Datei, gleiches Ergebnis beim zweiten Aufruf');
verlauf_mit_cache($regionen, $v, "$tmp/cache", $standV, SPORTARTEN['wingfoil'], $tageV, $jetztV);
pruefe(count(glob("$tmp/cache/verlauf-*.json")) === 2, 'Zwischenspeicher: andere Sportart, eigene Datei');
pruefe(verlauf_mit_cache($regionen, $v, null, $standV, $kite, $tageV, $jetztV) == $vl, 'ohne Zwischenspeicher: gleiches Ergebnis');

// ---------------------------------------------------------------
echo "Speicher: Zeilen einzeln entpacken\n";
$zeileT = json_encode(['ts' => '2026-11-07T12:00:00Z', 'source' => 'ensemble', 'ok' => true, 'http' => 200, 'ms' => 1,
    'regionen' => ['a', 'b', 'c'],
    'payload' => [['latitude' => 1.5, 'hourly' => ['x' => [1, 2]]], ['latitude' => 2.5, 'hourly' => ['x' => [3]]],
                  ['latitude' => 3.5, 'hourly' => ['x' => []], 'units' => ['x' => '{"latitude":']]]],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$teileT = payload_teile($zeileT, 3);
pruefe($teileT !== null && array_map(fn($t) => json_decode($t, true), $teileT) === json_decode($zeileT, true)['payload'],
       'payload wird in dieselben Einträge geteilt');
$zT = zeile_eintraege($zeileT, fn($id) => $id === 'b');
pruefe(array_keys($zT['eintraege']) === ['b'] && $zT['eintraege']['b']['latitude'] === 2.5, 'nur der gebrauchte Eintrag wird entpackt');
pruefe(payload_teile($zeileT, 2) === null && count(zeile_eintraege($zeileT, fn($id) => true)['eintraege']) === 3,
       'passt die Teilung nicht, wird die ganze Zeile entpackt');
$standNur = daten_laden("$tmp/action", nur: ['adria-nord']);
pruefe(isset($standNur['ensemble']['adria-nord']) && count($standNur['ensemble']) === 1
       && count($standNur['_ts']['ensemble']) === count($standA['_ts']['ensemble'])
       && count($standNur['_fp']['ensemble']) === count($standA['_ts']['ensemble']),
       'daten_laden mit Umkreis: Einträge nur dort, Stand und Fingerabdruck für alle');
$standFrei = $standA;
foreach (array_keys($standFrei) as $q) {
    if ($q[0] !== '_') {
        $standFrei[$q] = [];
    }
}
pruefe(verlauf_rechnen($regionen, "$tmp/action", $standFrei, $kite, $tageV, $jetztV)
       === verlauf_rechnen($regionen, "$tmp/action", $standA, $kite, $tageV, $jetztV),
       'Verlauf braucht die Rohdaten nicht mehr');
$standOhneFp = daten_laden("$tmp/action", nur: ['adria-nord'], fingerabdruck: false);
pruefe(array_keys($standOhneFp['_fp']['ensemble']) === ['adria-nord'] && $standOhneFp['_ts'] === $standNur['_ts']
       && $standOhneFp['ensemble'] === $standNur['ensemble'],
       'daten_laden ohne Fingerabdruck: _fp nur im Umkreis, sonst gleich');

// ---------------------------------------------------------------
echo "Vorgerechnete Bewertung\n";
$vc = "$tmp/vorrechnen-cache";
$ber = new DateTimeZone('Europe/Berlin');
$heuteB = (new DateTimeImmutable('today', $ber))->format('Y-m-d');
$tage7 = [];
for ($i = 0; $i <= 6; $i++) {
    $tage7[] = (new DateTimeImmutable("today +$i day", $ber))->format('Y-m-d');
}
$tage3 = array_slice($tage7, 0, 3);
$j12 = new DateTimeImmutable("$heuteB 12:10", $ber);
$direkt = rangliste($regionen, $standA, $kite, $inning, 1000, $tage3, $j12);
$c1 = rangliste_mit_cache($regionen, "$tmp/action", $vc, 'kite_twintip', $inning, 1000, $tage3, $j12, 1);
pruefe($c1['liste'] === $direkt && $c1['gerechnet'] === count($direkt) && $c1['aus_cache'] === 0,
       'erster Aufruf: rechnet in Stapeln, Ergebnis gleich wie rangliste()');
pruefe($c1['stand']['_ts'] === $standA['_ts'] && $c1['stand']['_fp'] === $standA['_fp'], 'Stand und Fingerabdruck wie daten_laden()');
pruefe(count(glob("$vc/bewertung-kite_twintip-*.ser")) === 3 && count(glob("$vc/stand-*.ser")) === 1, 'je Tag eine Datei, dazu der Stand');
$c2 = rangliste_mit_cache($regionen, "$tmp/action", $vc, 'kite_twintip', $inning, 1000, $tage3, $j12->modify('+40 minutes'));
pruefe($c2['liste'] === $direkt && $c2['gerechnet'] === 0 && $c2['aus_cache'] === count($direkt), 'zweiter Aufruf in derselben Stunde: alles aus dem Zwischenspeicher');
$nahD = rangliste($regionen, $standA, $kite, $inning, 100, $tage3, $j12);
$nahC = rangliste_mit_cache($regionen, "$tmp/action", $vc, 'kite_twintip', $inning, 100, $tage3, $j12);
$weitD = rangliste($regionen, $standA, $kite, [46.6, 30.0], 2500, $tage3, $j12);
$weitC = rangliste_mit_cache($regionen, "$tmp/action", $vc, 'kite_twintip', [46.6, 30.0], 2500, $tage3, $j12);
pruefe($nahC['liste'] === $nahD && $nahC['gerechnet'] === 0 && $weitC['liste'] === $weitD && $weitC['gerechnet'] === 2 && $weitC['aus_cache'] === 2,
       'anderer Umkreis und Standort: vorhandene Einträge wiederverwendet, nur die zwei neuen Regionen gerechnet');
$j13 = $j12->modify('+1 hour');
$c3 = rangliste_mit_cache($regionen, "$tmp/action", $vc, 'kite_twintip', $inning, 1000, $tage3, $j13);
pruefe($c3['liste'] === rangliste($regionen, $standA, $kite, $inning, 1000, $tage3, $j13) && $c3['gerechnet'] === count($direkt),
       'nächste Stunde: heute wird neu gerechnet (Restfenster), gleich wie rangliste()');
$tage46 = array_slice($tage7, 3);
$c4 = rangliste_mit_cache($regionen, "$tmp/action", $vc, 'kite_twintip', $inning, 1000, $tage46, $j13);
$c4b = rangliste_mit_cache($regionen, "$tmp/action", $vc, 'kite_twintip', $inning, 1000, $tage46, $j13->modify('+5 hours'));
pruefe($c4['liste'] === rangliste($regionen, $standA, $kite, $inning, 1000, $tage46, $j13) && $c4b['gerechnet'] === 0,
       'Tag 3 bis 7: hängt nicht an der Uhrzeit, bleibt im Zwischenspeicher');
$regionenG = $regionen;
$regionenG[0]['spots'][] = ['id' => 'neu-im-test', 'name' => 'Neuer Spot'];
$c5 = rangliste_mit_cache($regionenG, "$tmp/action", $vc, 'kite_twintip', $inning, 1000, $tage3, $j13);
pruefe($c5['gerechnet'] === 1 && $c5['liste'] === rangliste($regionenG, $standA, $kite, $inning, 1000, $tage3, $j13),
       'geänderter Katalog-Eintrag: nur diese Region neu');
$c6 = rangliste_mit_cache($regionen, "$tmp/action", $vc, 'wingfoil', $inning, 1000, $tage3, $j13);
pruefe($c6['gerechnet'] === count($direkt) && $c6['liste'] === rangliste($regionen, $standA, SPORTARTEN['wingfoil'], $inning, 1000, $tage3, $j13),
       'andere Sportart: eigene Dateien');
pruefe(rangliste_mit_cache($regionen, "$tmp/action", null, 'kite_twintip', $inning, 1000, $tage3, $j13)['liste']
       === rangliste($regionen, $standA, $kite, $inning, 1000, $tage3, $j13), 'ohne Zwischenspeicher: gleiches Ergebnis');
// Neuer Lauf des Sammlers: neue Datei, neuer Schluessel
$schl = daten_schluessel("$tmp/action");
$ensDatei = glob("$tmp/action/ensemble/*.ndjson.gz")[0];
$kopie = dirname($ensDatei) . '/' . gmdate('Y-m-d') . '_2359.ndjson.gz';
copy($ensDatei, $kopie);
pruefe(daten_schluessel("$tmp/action") !== $schl, 'neuer Lauf: neuer Datenschlüssel');
$c7 = rangliste_mit_cache($regionen, "$tmp/action", $vc, 'kite_twintip', $inning, 1000, $tage3, $j13);
pruefe($c7['gerechnet'] === count($direkt), 'neuer Lauf: alles neu gerechnet');
unlink($kopie);
// Vorrechnen fuer eine Sportart: danach kommt jede Ansicht aus dem Zwischenspeicher
$vc2 = "$tmp/vorrechnen-cache2";
mkdir($vc2);
$vr = vorrechnen($regionen, "$tmp/action", $vc2, 'kite_twintip', 'Europe/Berlin', '', $j12);
pruefe($vr['gerechnet'] > 0 && $vr['aus_cache'] === 0 && count(glob("$vc2/bewertung-*.ser")) === 7 && count(glob("$vc2/verlauf-*.json")) === 2,
       'vorrechnen: 7 Tage und der Verlauf für beide Zeiträume');
$c8 = rangliste_mit_cache($regionen, "$tmp/action", $vc2, 'kite_twintip', [46.6, 30.0], 100, $tage46, $j12);
$c9 = rangliste_mit_cache($regionen, "$tmp/action", $vc2, 'kite_twintip', $inning, 1000, $tage7, $j12);
pruefe($c8['gerechnet'] === 0 && $c9['gerechnet'] === 0 && $c9['liste'] === rangliste($regionen, $standA, $kite, $inning, 1000, $tage7, $j12),
       'nach vorrechnen: Tag 3 bis 7 und Woche ohne Nachrechnen, gleich wie rangliste()');
touch("$vc2/bewertung-alt.ser", time() - 3 * 86400);
cache_aufraeumen($vc2);
pruefe(!is_file("$vc2/bewertung-alt.ser") && count(glob("$vc2/bewertung-kite_twintip-*.ser")) === 7, 'Aufräumen: alte Dateien weg, aktuelle bleiben');

// ---------------------------------------------------------------
echo "Fahrzeiten\n";
$orsPort = $port + 2;
$ors = proc_open(['php', '-S', "127.0.0.1:$orsPort", __DIR__ . '/fake_ors.php'],
                 [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $orsPort); $i++) {
    usleep(100000);
}
$fzCmd = fn(string $key, string $ziel) => ($key !== '' ? 'ORS_API_KEY=' . escapeshellarg($key) . ' ' : 'ORS_API_KEY= ')
    . 'UMKREIS_ORS_URL=' . escapeshellarg("http://127.0.0.1:$orsPort")
    . ' php ' . escapeshellarg("$ROOT/server/fahrzeiten.php") . ' ' . escapeshellarg(__DIR__ . '/regionen_test.json') . ' ' . escapeshellarg($ziel) . ' 2>&1';
exec($fzCmd('testschluessel', "$tmp/action/fahrzeiten.json"), $ausZ, $rcZ);
$fzJ = json_decode((string)@file_get_contents("$tmp/action/fahrzeiten.json"), true);
pruefe($rcZ === 0 && $ausZ === [] && is_array($fzJ), 'Abruf ohne Fehler, fahrzeiten.json geschrieben' . ($ausZ ? ': ' . implode(' | ', $ausZ) : ''));
pruefe(($fzJ['ziele']['ammersee']['ziel'] ?? null) === [48.0489, 11.1619] && ($fzJ['ziele']['ammersee']['snap_m'] ?? null) == 50,
       'Ziel Ammersee: Punkt des ersten Spots, auf die Straße eingerastet');
pruefe(($fzJ['ziele']['adria-nord']['snap_m'] ?? null) == 9500 && ($fzJ['ziele']['adria-nord']['min'] ?? 0) > 200,
       'Adria auf dem Wasser: rastet erst mit größerem Radius ein');
pruefe(array_key_exists('testsee-druck', $fzJ['ziele'] ?? []) && $fzJ['ziele']['testsee-druck'] === null, 'ohne Route: null');
exec('ORS_API_KEY=testschluessel UMKREIS_ORS_URL=' . escapeshellarg("http://127.0.0.1:$orsPort") . ' php ' . escapeshellarg("$ROOT/server/fahrzeiten.php")
     . ' ' . escapeshellarg("$ROOT/daten/regionen.json") . ' ' . escapeshellarg("$tmp/fz-echt.json") . ' 2>&1', $ausZ4, $rcZ4);
$fzE = json_decode((string)@file_get_contents("$tmp/fz-echt.json"), true);
pruefe($rcZ4 === 0 && !array_key_exists('tarifa', $fzE['ziele'] ?? ['tarifa' => 1]) && str_contains(implode(' ', $ausZ4), 'Snapping mit 30000 m abgelehnt'),
       'ganzer Katalog: ohne Straße in der Nähe fehlt die Region (wird geschätzt), zu großer Radius abgelehnt, Lauf geht weiter');
exec($fzCmd('falsch', "$tmp/fz-falsch.json"), $ausZ2, $rcZ2);
pruefe($rcZ2 === 1 && !is_file("$tmp/fz-falsch.json") && str_contains(implode(' ', $ausZ2), 'HTTP 403'), 'falscher Schlüssel: Exit 1, nichts geschrieben');
exec($fzCmd('', "$tmp/fz-leer.json"), $ausZ3, $rcZ3);
pruefe($rcZ3 === 2 && str_contains(implode(' ', $ausZ3), 'ORS_API_KEY fehlt'), 'ohne Schlüssel: Exit 2 mit Hinweis');
proc_terminate($ors);

$fzL = fahrzeiten_laden("$tmp/action");
$adriaReg = array_values(array_filter($regionen, fn($r) => $r['id'] === 'adria-nord'))[0];
$f = fahrzeit($adriaReg, [48.07, 11.15], 380.0, $fzL);
pruefe(!$f['geschaetzt'] && $f['min'] === $fzJ['ziele']['adria-nord']['min'], 'ab Inning: genaue Fahrzeit aus der Datei');
$f = fahrzeit($adriaReg, [47.5, 9.7], 300.0, $fzL, [10.0, 0.9]);
pruefe($f['geschaetzt'] && $f['min'] === 280, 'anderer Standort: geschätzt, 10 min + 0,9 x 300 km');
$tsee = array_values(array_filter($regionen, fn($r) => $r['id'] === 'testsee-druck'))[0];
pruefe(fahrzeit($tsee, [47.5, 9.7], 100.0, $fzL)['min'] === null, 'ohne Route ab dem Startort: auch geschätzt kein Landweg');
pruefe(fahrzeit($adriaReg, [47.5, 9.7], 300.0, null)['min'] === 240, 'ohne Datei: geschätzt mit 15 min + 0,75 x km');
pruefe(fahrzeit_text(['min' => 104, 'geschaetzt' => false]) === '1:45 h' && fahrzeit_text(['min' => 209, 'geschaetzt' => true]) === '≈ 3:30 h'
       && fahrzeit_text(['min' => 2, 'geschaetzt' => false]) === '5 min' && fahrzeit_text(['min' => null]) === 'kein Landweg',
       'Fahrzeit als Text: 1:45 h, ≈ 3:30 h, 5 min, kein Landweg');
// Ausgleichsgerade: genaue Werte auf 20 min + 0,7 min/km
$modellReg = [];
$modellZiele = [];
foreach ([30, 100, 200, 400, 800] as $i => $km) {
    $modellReg[] = ['id' => "m$i", 'punkt' => [48.07 + $km / 111.195, 11.15]];
    $modellZiele["m$i"] = ['min' => 20 + 0.7 * $km];
}
$fzM = ['start' => ['punkt' => [48.07, 11.15]], 'ziele' => $modellZiele];
[$ma, $mb] = fahrt_modell($fzM, $modellReg);
pruefe(abs($ma - 20) < 0.5 && abs($mb - 0.7) < 0.005, 'Schätzmodell: Sockel und Faktor aus den genauen Werten (20 min + 0,7 min/km)');
pruefe(fahrt_modell(['start' => $fzM['start'], 'ziele' => array_slice($modellZiele, 0, 4)], $modellReg) === FAHRT_STANDARD, 'Schätzmodell: zu wenige Werte, Standard');
$fzSchief = ['start' => $fzM['start'], 'ziele' => array_map(fn($z) => ['min' => $z['min'] * 3], $modellZiele)];
pruefe(fahrt_modell($fzSchief, $modellReg)[1] === FAHRT_GRENZEN[1][1], 'Schätzmodell: unplausibler Faktor wird begrenzt');
$ber = new DateTimeZone('Europe/Berlin');
$um = fn(string $z) => new DateTimeImmutable($z, $ber);
$a = abfahrt('2026-11-08', 11, 16, $ber, 90, $ber, $um('2026-11-07 20:00'));
pruefe($a['art'] === 'bis' && $a['zeit']->format('G:i') === '9:15', 'Fenster ab 11 Uhr, 90 min Fahrt: Abfahrt bis 9:15 (15 min Aufbau)');
$a = abfahrt('2026-11-08', 10, 15, $ber, 400, $ber, $um('2026-11-07 20:00'));
pruefe($a['art'] === 'vortag', 'vor 5 Uhr losfahren: Anreise am Vortag');
$a = abfahrt('2026-11-08', 11, 17, $ber, 60, $ber, $um('2026-11-08 12:00'));
pruefe($a['art'] === 'jetzt' && $a['zeit']->format('G:i') === '13:15', 'Abfahrt verpasst, jetzt los: ab 13:15 dort');
$a = abfahrt('2026-11-08', 11, 16, $ber, 180, $ber, $um('2026-11-08 12:00'));
pruefe($a['art'] === 'zu_spaet', 'zu spät für mindestens 2 Stunden: nicht mehr erreichbar');
$a = abfahrt('2026-11-08', 10, 16, new DateTimeZone('Atlantic/Canary'), 60, $ber, $um('2026-11-07 20:00'));
pruefe($a['zeit']->format('G:i') === '9:45', 'andere Zeitzone: Fenster 10 Uhr Kanaren = 11 Uhr Berlin, Abfahrt 9:45 Berlin');
$sk = rangliste_sortieren([['km' => 100, 'fahrt' => ['min' => 200]], ['km' => 300, 'fahrt' => ['min' => 150]], ['km' => 50, 'fahrt' => ['min' => null]]], 'km');
pruefe(array_column($sk, 'km') === [300, 100, 50], 'Sortierung Fahrzeit: kürzeste Fahrt zuerst, ohne Landweg zuletzt');
// Umkreis nach Strasse (seit 7.11.2026)
pruefe(im_umkreis(['km' => 319.0, 'fahrt' => ['km' => 401.2, 'min' => 301]], 400.0) === false
       && im_umkreis(['km' => 319.0, 'fahrt' => ['km' => 401.2, 'min' => 301]], 700.0)
       && im_umkreis(['km' => 90.0, 'fahrt' => ['km' => null, 'min' => null, 'kein_landweg' => true]], 100.0)
       && !im_umkreis(['km' => 120.0, 'fahrt' => ['km' => null, 'min' => null, 'kein_landweg' => true]], 100.0)
       && im_umkreis(['km' => 5000.0, 'fahrt' => ['km' => null, 'min' => 20, 'flug' => 'X']], 100.0) === false
       && im_umkreis(['km' => 50.0, 'fahrt' => ['km' => null, 'min' => 20, 'flug' => 'X']], 100.0),
       'Umkreis: Straßen-km zählen, ohne Landweg die Luftlinie');
$fzU = ['start' => $fzM['start'], 'ziele' => array_map(fn($z, $k) => ['min' => $z['min'], 'km' => $k * 1.3],
        $modellZiele, [30, 100, 200, 400, 800])];
$fzU['ziele'] = array_combine(array_keys($modellZiele), $fzU['ziele']);
pruefe(abs(fahrt_umweg($fzU, $modellReg) - 1.3) < 0.01 && fahrt_umweg(null, $modellReg) === FAHRT_UMWEG
       && fahrt_umweg(['start' => $fzM['start'], 'ziele' => array_slice($fzU['ziele'], 0, 4)], $modellReg) === FAHRT_UMWEG,
       'Umwegfaktor: Median Straße/Luftlinie, sonst 1,25');
$fG = fahrzeit($adriaReg, [47.5, 9.7], 300.0, $fzL, [10.0, 0.9], 1.3);
pruefe($fG['geschaetzt'] && $fG['km'] == 390, 'anderer Standort: Straßen-km geschätzt, 300 km x 1,3');
pruefe(fahrzeit($adriaReg, [48.07, 11.15], 319.0, $fzL)['km'] === 401.2, 'ab Inning: Straßen-km aus der Datei');

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
[, $html] = holen($sitePort, 'index.php');
pruefe(str_contains($html, 'id="installieren" hidden') && str_contains($html, 'beforeinstallprompt'), 'Hinweis zum Installieren vorhanden, zunächst verborgen');
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

// Suchmaschinen: ohne 'indexieren' ueberall noindex, mit nur die Startseite
[, $html] = holen($sitePort, 'index.php');
pruefe(str_contains($html, '<meta name="robots" content="noindex">') && !str_contains($html, 'rel="canonical"')
       && !str_contains($html, 'google-site-verification') && str_contains($html, '<meta name="description"'),
       'ohne indexieren: Startseite noindex, ohne canonical, mit Beschreibung');
exec('cp -r ' . escapeshellarg("$ROOT/server/web") . ' ' . escapeshellarg("$site/suche"));
copy(__DIR__ . "/regionen_test.json", "$site/suche/lib/regionen.json");
file_put_contents("$site/suche/config.php", '<?php return ' . var_export(['data_dir' => "$tmp/action", 'indexieren' => true,
                  'google_verifizierung' => 'Ab12-cd_34Ef56gh78'], true) . ';');
[$code, $html] = holen($sitePort, 'suche/index.php');
pruefe($code === 200 && ohne_php_meldung($html) && !str_contains($html, 'name="robots"')
       && str_contains($html, '<link rel="canonical" href="https://wohinfahrma.de/">')
       && str_contains($html, '<meta name="google-site-verification" content="Ab12-cd_34Ef56gh78">'),
       'mit indexieren: Startseite ohne noindex, mit canonical und Google-Code');
[, $html] = holen($sitePort, 'suche/index.php?km=100');
pruefe(str_contains($html, '<meta name="robots" content="noindex">') && !str_contains($html, 'rel="canonical"')
       && !str_contains($html, 'google-site-verification'), 'mit indexieren: Ansicht mit Parametern bleibt noindex');
[, $html] = holen($sitePort, "suche/index.php?region=ammersee&nach=$morgen");
pruefe(str_contains($html, '<meta name="robots" content="noindex">'), 'mit indexieren: geteilte Vorhersage bleibt noindex');
file_put_contents("$site/suche/config.php", '<?php return ' . var_export(['data_dir' => "$tmp/action", 'indexieren' => 'ja',
                  'google_verifizierung' => '"><script>'], true) . ';');
[, $html] = holen($sitePort, 'suche/index.php');
pruefe(str_contains($html, '<meta name="robots" content="noindex">') && !str_contains($html, 'google-site-verification'),
       'indexieren nur mit true, ungültiger Google-Code wird ignoriert');
[, $html] = holen($sitePort, 'stunden.php?region=ammersee');
pruefe(str_contains($html, '<meta name="robots" content="noindex">'), 'Stundenseite bleibt noindex');
$robots = (string)file_get_contents("$ROOT/server/web/robots.txt");
pruefe(str_contains($robots, "Disallow: /*?\n") && str_contains($robots, "Disallow: /stunden.php\n")
       && str_contains($robots, 'Sitemap: https://wohinfahrma.de/sitemap.xml') && !preg_match('/^Disallow: \/\s*$/m', $robots),
       'robots.txt: Parameter und Stundenseite gesperrt, Startseite frei, Sitemap genannt');
$sm = @simplexml_load_file("$ROOT/server/web/sitemap.xml");
pruefe($sm !== false && count($sm->url) === 1 && (string)$sm->url[0]->loc === 'https://wohinfahrma.de/', 'sitemap.xml: gültig, nur die Startseite');

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
exec('cp -r ' . escapeshellarg("$ROOT/server/web") . ' ' . escapeshellarg("$site/verlauf"));
copy(__DIR__ . "/regionen_test.json", "$site/verlauf/lib/regionen.json");
file_put_contents("$site/verlauf/config.php", '<?php return ' . var_export(['data_dir' => "$tmp/verlauf"], true) . ';');
[$code, $html] = holen($sitePort, 'verlauf/index.php?km=1000');
pruefe($code === 200 && ohne_php_meldung($html) && str_contains($html, '<span class="v steigt">↑ von 0</span>')
       && str_contains($html, 'steigt (0 → 49 → 100 %)') && str_contains($html, 'Frühere Abrufe: '),
       'Seite mit früheren Abrufen: Pfeil in der Kachel, Verlauf in der Detailzeile');
// Fremde Prognose auf der Seite (seit 10.11.2026): Ammersee im Test mit Prognose aus einem Ordner
exec('cp -r ' . escapeshellarg("$ROOT/server/web") . ' ' . escapeshellarg("$site/fremd"));
$fKat = json_decode(file_get_contents(__DIR__ . '/regionen_test.json'), true);
foreach ($fKat['regionen'] as $k => $fr0) {
    if ($fr0['id'] === 'ammersee') {
        $fKat['regionen'][$k]['fremdprognose'] = ['name' => 'Marco Schanda', 'url' => 'https://walchenseewindpredictor.onrender.com/'];
    }
}
file_put_contents("$site/fremd/lib/regionen.json", json_encode($fKat));
$fOrdner = "$tmp/fremd/seite";
@mkdir($fOrdner, 0777, true);
$fBer = new DateTimeZone('Europe/Berlin');
$fTage = [];
for ($i = 0; $i < 3; $i++) {
    $fTage[] = ['date' => (new DateTimeImmutable("today +$i day", $fBer))->format('Y-m-d'), 'data_available' => true, 'chance' => 0.93, 'status' => 'go', 'peak_kt' => 18.0];
}
file_put_contents("$fOrdner/" . gmdate('Y-m-d') . '.ndjson', json_encode(['ok' => true, 'payload' => [
    'status' => 'success', 'stale' => false, 'generated_at' => (new DateTimeImmutable('now', $fBer))->format('Y-m-d H:i:s'), 'days' => $fTage]]) . "\n");
file_put_contents("$site/fremd/config.php", '<?php return ' . var_export(['data_dir' => "$tmp/action", 'cache_dir' => "$tmp/fremd/cache", 'fremdprognosen' => ['ammersee' => $fOrdner]], true) . ';');
[$code, $html] = holen($sitePort, 'fremd/index.php?km=100&sport=kite_twintip');
pruefe($code === 200 && ohne_php_meldung($html) && str_contains($html, 'Vorhersage von Marco Schanda ↗') && str_contains($html, '93<small>%</small>')
       && str_contains($html, 'GO · Spitze 18 kn'), 'Seite: Kite-Chance aus der fremden Prognose, mit Quelle');
[$code, $html] = holen($sitePort, 'fremd/index.php?km=100&sport=kite_twintip');
pruefe($code === 200 && str_contains($html, '93<small>%</small>') && count(glob("$tmp/fremd/cache/bewertung-kite_twintip-*.ser")) > 0, 'aus dem Zwischenspeicher: fremde Chance trotzdem drin');
[$code, $html] = holen($sitePort, 'fremd/index.php?km=100&sport=wingfoil');
pruefe($code === 200 && ohne_php_meldung($html) && str_contains($html, 'Vorhersage von Marco Schanda ↗'), 'Seite: Wingfoil mit fremder Prognose');
[$code, $html] = holen($sitePort, 'fremd/index.php?km=100&sport=segeln');
pruefe($code === 200 && ohne_php_meldung($html) && !str_contains($html, 'Vorhersage von Marco Schanda ↗'), 'Seite: Segeln ohne fremde Prognose');
[$code, $html] = holen($sitePort, 'fremd/stunden.php?region=ammersee&sport=kite_twintip&tag=' . $fTage[1]['date']);
pruefe($code === 200 && ohne_php_meldung($html) && str_contains($html, 'Urteil und Chance aus der <a href="https://walchenseewindpredictor.onrender.com/"'), 'Stundenseite nennt die fremde Prognose');
pruefe(preg_match('#<p class="urteil wind passt">[^<]+: <b>GO</b> <span class="leise">· Spitze 18 kn</span></p>#', $html) === 1
       && preg_match('#das Modell allein sagt: </span><b>[^<]+</b>#', $html) === 1,
       'Stundenseite: Urteil aus der fremden Prognose (wie die Chance), Modell-Urteil nur als Hinweis');
pruefe(str_contains($html, '>Modell, nicht kalibriert: Stunde fahrbar, % der Läufe</text>') && str_contains($html, '<text class="s-anteil modell"')
       && !preg_match('#class="s-anteil (go|maybe|no)"#', $html),
       'Stundenseite mit fremder Prognose: Stunden-% als Modell gekennzeichnet, ohne Ampelfarben');
[$code, $html] = holen($sitePort, 'fremd/index.php?km=100&ansicht=woche');
pruefe($code === 200 && ohne_php_meldung($html), 'Woche mit fremder Prognose: keine PHP-Meldung');
// ammerseewind.de mit kalibrierten Stunden und bester Zeit (seit 14.11.2026)
exec('cp -r ' . escapeshellarg("$ROOT/server/web") . ' ' . escapeshellarg("$site/kal"));
foreach ($fKat['regionen'] as $k => $fr0) {
    if ($fr0['id'] === 'ammersee') {
        $fKat['regionen'][$k]['fremdprognose'] = ['name' => 'ammerseewind.de (kalibriert für Stegen)', 'url' => 'https://ammerseewind.de/', 'format' => 'ammerseewind'];
    }
}
file_put_contents("$site/kal/lib/regionen.json", json_encode($fKat));
$kTag = (new DateTimeImmutable('today +2 day', $fBer))->format('Y-m-d');
$kStunden = ['9' => 10.0, '10' => 12.0, '11' => 14.5, '12' => 15.5, '13' => 16.5, '14' => 15.0, '15' => 13.0, '16' => 11.0];
file_put_contents("$tmp/kal-export.json", json_encode(['version' => 1, 'erzeugt' => gmdate('Y-m-d\TH:i:s\Z'), 'tage' => [
    $kTag => ['von' => 9, 'bis' => 17, 'chance' => ['directional' => 0.92, 'twintip' => 0.9], 'stunden' => $kStunden,
              'staerkste' => 13, 'spitze' => 16.5, 'beste' => ['directional' => [11, 15], 'twintip' => [11, 15]]]]]));
file_put_contents("$site/kal/config.php", '<?php return ' . var_export(['data_dir' => "$tmp/action", 'cache_dir' => "$tmp/kal/cache", 'fremdprognosen' => ['ammersee' => "$tmp/kal-export.json"]], true) . ';');
[$code, $html] = holen($sitePort, "kal/stunden.php?region=ammersee&sport=kite_directional&tag=$kTag");
pruefe($code === 200 && ohne_php_meldung($html)
       && preg_match('#<p class="urteil wind passt">[^<]+: <b>lohnt sich</b> <span class="leise">· 11–15 Uhr</span> <span class="leise">· Spitze 17 kn</span></p>#', $html) === 1
       && str_contains($html, 'Urteil, Zeiten, Wind-Kachel und grüne Linie aus der')
       && preg_match('#<div class="kz kz-wind" title="Kalibriert \(Messwerte\), Mittel 11–15 Uhr[^"]*"><span class="t">Wind Ø</span><span class="w">15<small> kn</small>#', $html) === 1
       && str_contains($html, '<polyline class="s-kal"') && str_contains($html, '>Grüne Linie und Balken kalibriert · % Modell-Läufe, nicht kalibriert</text>')
       && str_contains($html, '<title>13 Uhr: 17 kn kalibriert, fahrbar</title>') && str_contains($html, '<title>10 Uhr: 12 kn kalibriert, nicht fahrbar</title>'),
       'Stundenseite mit ammerseewind.de: beste Zeit im Urteil, Wind-Kachel, grüne Linie und Balken kalibriert');
[$code, $html] = holen($sitePort, 'kal/index.php?km=100&sport=kite_directional');
pruefe($code === 200 && ohne_php_meldung($html) && str_contains($html, 'lohnt sich · 11–15 Uhr · Spitze 17 kn · Stand '), 'Rangliste: beste Zeit aus ammerseewind.de in der Detailzeile');

// Vorrechnen (seit 8.11.2026): nur mit Schluessel aus config.php, nur per POST
function senden(int $port, string $pfad, array $felder): array
{
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 60,
        'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => http_build_query($felder)]]);
    $body = @file_get_contents("http://127.0.0.1:$port/$pfad", false, $ctx);
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), (string)$body];
}
$vSchl = str_repeat('a1', 16);
exec('cp -r ' . escapeshellarg("$ROOT/server/web") . ' ' . escapeshellarg("$site/vorr"));
copy(__DIR__ . "/regionen_test.json", "$site/vorr/lib/regionen.json");
file_put_contents("$site/vorr/config.php", '<?php return ' . var_export(['data_dir' => "$tmp/action", 'cache_dir' => "$tmp/vorr-cache",
                  'vorrechnen_schluessel' => $vSchl], true) . ';');
[$code] = senden($sitePort, 'vorrechnen.php', ['sport' => 'kite_twintip']);
[$code2] = senden($sitePort, 'vorrechnen.php', ['schluessel' => 'falsch', 'sport' => 'kite_twintip']);
[$code3] = senden($sitePort, 'vorr/vorrechnen.php', ['schluessel' => 'falsch', 'sport' => 'kite_twintip']);
[$code4] = holen($sitePort, 'vorr/vorrechnen.php');
pruefe($code === 404 && $code2 === 404 && $code3 === 404 && $code4 === 404, 'vorrechnen.php: ohne Schlüssel in config.php oder mit falschem 404');
[$code, $antw] = senden($sitePort, 'vorr/vorrechnen.php', ['schluessel' => $vSchl, 'sport' => 'gibtsnicht']);
pruefe($code === 400, 'vorrechnen.php: unbekannte Sportart 400');
[$code, $antw] = senden($sitePort, 'vorr/vorrechnen.php', ['schluessel' => $vSchl, 'sport' => 'kite_twintip']);
$vrA = json_decode($antw, true);
pruefe($code === 200 && ($vrA['sport'] ?? '') === 'kite_twintip' && ($vrA['gerechnet'] ?? 0) > 0 && count(glob("$tmp/vorr-cache/bewertung-kite_twintip-*.ser")) === 7,
       'vorrechnen.php: rechnet die Sportart vor' . ($code === 200 ? '' : " (HTTP $code: " . substr($antw, 0, 200) . ')'));
[, $antw] = senden($sitePort, 'vorr/vorrechnen.php', ['schluessel' => $vSchl, 'sport' => 'kite_twintip']);
pruefe((json_decode($antw, true)['gerechnet'] ?? -1) === 0, 'vorrechnen.php: zweiter Aufruf aus dem Zwischenspeicher');
[$code, $html] = holen($sitePort, 'vorr/index.php?km=1000');
pruefe($code === 200 && ohne_php_meldung($html) && str_contains($html, 'id="r-adria-nord"'), 'Seite nach dem Vorrechnen');
[, $html] = holen($sitePort, 'index.php?km=1000');
pruefe(str_contains($html, 'id="landkarte"') && substr_count($html, 'class="punkt ') === 2
       && preg_match('/<a href="stunden\.php\?region=adria-nord&amp;sport=[a-z_]+&amp;tag=\d{4}-\d\d-\d\d" class="punkt /', $html)
       && str_contains($html, 'id="r-adria-nord"') && str_contains($html, 'karte.svg#land'),
       'Landkarte: ein Punkt je Region, verlinkt auf die Stundenseite am besten Tag');
pruefe(preg_match('/id="r-adria-nord" data-ziel="stunden\.php\?region=adria-nord&amp;sport=[a-z_]+"/', $html)
       && preg_match('/<h2><a class="region-link" href="stunden\.php\?region=adria-nord/', $html)
       && preg_match('/<a class="tag [a-z]+[^"]*" href="stunden\.php\?region=adria-nord&amp;sport=[a-z_]+&amp;tag=' . $morgen . '"/', $html)
       && !str_contains($html, '<div class="tag go') && str_contains($html, ".region[data-ziel]"),
       'Regionskarte: Karte, Name und Tageskacheln führen zur Stundenseite');
[, $html] = holen($sitePort, 'index.php?km=1000&nach=' . $morgen);
pruefe(str_contains($html, 'Chance Morgen') && preg_match('/class="punkt go".*?<text class="zahl"[^>]*>100</s', $html), 'Landkarte mit gewähltem Tag: Adria 100 %');
pruefe(preg_match('/<path class="windpfeil" data-weht="[\d.]+,[\d.]+,40,[\d.]+" d="[^"]+" transform="translate\([\d. ]+\) rotate\(40\)"/', $html)
       && preg_match('/<g class="windfeld"[^>]*>\s*<circle [^>]*style="fill: rgb\(/', $html) && str_contains($html, 'class="windskala"')
       && str_contains($html, 'aus NO, Ø '),
       'Landkarte: Windpfeil (Bora aus 40 Grad, gute Region mit Daten für die Windstriche), Farbfeld nach Windstärke, Legende');
pruefe(preg_match('/<g class="heim" id="heim" transform="translate\\([\\d. ]+\\) scale\\([\\d.]+\\)"[^>]* data-wohin="[\\d.]+,[\\d.]+">\\s*<title>Inning am Ammersee/', $html)
       && strrpos($html, 'class="heim"') > strrpos($html, 'class="punkt ') && str_contains($html, 'id="heimBus"')
       && str_contains($html, 'id="heimZeig"') && str_contains($html, "getElementById('heim')")
       && str_contains($html, 'id="heimFahrer"') && str_contains($html, 'data-art="palme"')
       && str_contains($html, '<div class="design" id="ton" role="group" aria-label="Ton" hidden>') && str_contains($html, 'function tonStart()')
       && str_contains($html, 'id="heimJuchhu"') && str_contains($html, 'function pfiff('),
       'Landkarte: Standort als Männchen über den Punkten, mit Ziel (Adria), Bus mit Fahrer und Palme (Adria südlich von 46°), Juchhu, Ton-Schalter');
pruefe(str_contains($html, 'stunden.php?region=adria-nord&amp;sport=') && preg_match('/data-ziel="stunden\.php\?region=adria-nord&amp;sport=[a-z_]+&amp;tag=' . $morgen . '"/', $html),
       'mit gewähltem Tag: Karte und Punkt öffnen diesen Tag');
[, $html] = holen($sitePort, "index.php?region=ammersee&nach=$morgen");
pruefe(!str_contains($html, 'id="landkarte"'), 'geteilte Vorhersage: keine Landkarte');
[, $html] = holen($sitePort, 'index.php?km=1000');
pruefe(preg_match('/fahrzeit"[^>]*>5 min Fahrt<span class="km">3 km Straße</', $html) && str_contains($html, 'openrouteservice.org')
       && (str_contains($html, 'Anreise am Vortag') || str_contains($html, 'Abfahrt bis')), 'Fahrzeiten ab Inning: genau, ohne Landweg markiert, Abfahrt, Quelle genannt');
[, $html] = holen($sitePort, 'index.php?km=100&ort=46.6,30.0');
pruefe(str_contains($html, '>kein Landweg<') && ohne_php_meldung($html), 'Region ohne Route ab dem Startort: kein Landweg');
[, $html] = holen($sitePort, 'index.php?km=1000&ort=47.5,9.7');
pruefe(str_contains($html, '>≈ ') && preg_match('/<span class="km">≈ [0-9.]+0 km Straße<\/span>/u', $html) && ohne_php_meldung($html), 'anderer Standort: Fahrzeit und Straßen-km geschätzt (≈)');
[, $html] = holen($sitePort, 'index.php?km=400');
[, $html7] = holen($sitePort, 'index.php?km=700');
pruefe(!str_contains($html, 'id="r-adria-nord"') && str_contains($html, 'id="r-ammersee"') && str_contains($html7, 'id="r-adria-nord"')
       && str_contains($html, 'der Umkreis zählt die Straße'),
       'Umkreis 400 km: Adria (319 km Luftlinie, 401 km Straße) fehlt, bei 700 km dabei');
// Hell/Dunkel (seit 7.11.2026)
$css = (string)file_get_contents(__DIR__ . '/../server/web/stil.css');
preg_match('/@media \(prefers-color-scheme: dark\) \{\s*:root:not\(\[data-theme="light"\]\) \{(.*?)\}\s*\}/s', $css, $mA);
preg_match('/:root\[data-theme="dark"\] \{(.*?)\}/s', $css, $mB);
$norm = fn($t) => preg_replace('/\s+/', ' ', trim($t ?? ''));
pruefe(isset($mA[1], $mB[1]) && $norm($mA[1]) === $norm($mB[1]) && str_contains($mA[1], '--grund: #0f1e24'),
       'Dunkel: Block für das Gerät und für die Wahl gleich');
pruefe(str_contains($html, 'id="design"') && str_contains($html, 'data-design="dark"') && str_contains($html, "localStorage.getItem(\"wohinfahrma_design\")")
       && substr_count($html, 'name="theme-color"') === 2, 'Design-Schalter im Fuß, Wahl vor dem ersten Zeichnen gesetzt');
[, $html] = holen($sitePort, "index.php?region=adria-nord&nach=$morgen");
pruefe(!str_contains($html, 'class="leise klein fahrzeit"') && !str_contains($html, 'class="abfahrt"'), 'geteilte Vorhersage: ohne Fahrzeit und Abfahrt');
[$code, $html] = holen($sitePort, 'index.php?anreise=flug');
pruefe($code === 200 && ohne_php_meldung($html) && substr_count($html, 'class="karte region') === 1 && str_contains($html, 'id="r-testsee-druck"')
       && str_contains($html, 'Flug: Testflughafen (TST)') && str_contains($html, '≈ 20 min Transfer · ≈ ') && preg_match('/≈ \d+,\d t CO₂/', $html)
       && str_contains($html, 'km allein im Auto') && str_contains($html, 'CO₂ der Flüge:'),
       'Flugziele: nur Regionen mit Anreise Flug, ohne Umkreis, mit Flughafen, Transfer und CO₂ hin und zurück');
pruefe(abs(flug_co2_t(1000) - 0.276) < 0.001 && abs(flug_als_auto_km(0.2296) - 1000) < 1, 'CO₂ eines Flugs: 1.000 km Luftlinie ≈ 0,28 t hin und zurück');
pruefe(!str_contains($html, 'name="km"><') && !str_contains($html, 'class="umkreis"') && !str_contains($html, 'class="abfahrt"')
       && !str_contains($html, 'id="standort"') && str_contains($html, 'ansicht=woche&amp;anreise=flug') && str_contains($html, '>Transfer</option>'),
       'Flugziele: kein Umkreis, kein Standort, keine Abfahrt, Links behalten die Anreise');
pruefe(str_contains($html, 'id="heim"') && (!str_contains($html, 'id="heimBus"') || str_contains($html, 'data-art="flugzeug"') && preg_match('/id="heimZieltext"[^>]*>≈ \d+,\d t CO₂ hin und zurück</', $html)) && !str_contains($html, '<rect class="dach"'),
       'Flugziele: Männchen am Standort, mit Ziel ein Flugzeug statt des Busses');
if (getenv('UMKREIS_SEITE_OFFEN')) {   // zum Ansehen im Browser: Seite eine Weile offen lassen
    file_put_contents(getenv('UMKREIS_SEITE_OFFEN'), (string)$sitePort);
    sleep(90);
}
[, $html] = holen($sitePort, 'index.php?km=100&ort=46.6,30.0');
pruefe(str_contains($html, 'id="r-testsee-druck"') && !str_contains($html, 'Testflughafen'), 'Anreise Auto: Region mit Auto und Flug bleibt mit Fahrzeit');
[$code, $html] = holen($sitePort, 'index.php?km=1000&ansicht=roadtrip');
pruefe($code === 200 && ohne_php_meldung($html) && str_contains($html, 'class="karte trip beste-go"') && str_contains($html, '>Nördliche Adria</a>')
       && !str_contains($html, 'id="landkarte"') && !str_contains($html, 'aria-label="Sortierung"') && str_contains($html, '>Roadtrip</a>'),
       'Roadtrip: Vorschlag Adria morgen und übermorgen, ohne Karte und Sortierung');
pruefe(str_contains($html, 'Hinfahrt ab Inning') && str_contains($html, 'Rückfahrt'), 'Roadtrip mit Hin- und Rückfahrt');
[, $html] = holen($sitePort, 'index.php?anreise=flug&ansicht=roadtrip');
pruefe(str_contains($html, 'Roadtrips gibt es nur mit Anreise Auto') && ohne_php_meldung($html), 'Roadtrip mit Flug: Hinweis');
[, $html] = holen($sitePort, 'index.php?km=100&ansicht=roadtrip');
pruefe(str_contains($html, 'Kein Roadtrip lohnt sich gerade'), 'Roadtrip ohne passende Tage: Hinweis');
// Windtage am Stueck (seit 13.11.2026)
$tS = ['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05', '2026-01-06', '2026-01-07'];
$tagS = fn(?float $c) => ['ens' => $c === null ? null : ['chance' => $c], 'det' => ['geht' => false]];
$folge = fn(array $cs) => array_combine($tS, array_map($tagS, $cs));
$sA = am_stueck($folge([0.8, 0.4, 0.9, 0.1, 0.6, 0.7, 0.2]), $tS);
$sB = am_stueck($folge([0.8, 0.4, 0.35, 0.9, 0.0, 0.0, 0.0]), $tS);
pruefe($sA['wind'] === 2 && $sA['von'] === '2026-01-01' && $sA['bis'] === '2026-01-03' && $sA['tage'] === ['2026-01-01', '2026-01-02', '2026-01-03']
       && $sB['wind'] === 1 && am_stueck($folge([0.1, 0.6, 0.7, 0.8, 0.0, 0.0, 0.0]), $tS)['wind'] === 3,
       'Am Stück: ein schwacher Tag überbrückt und zählt nicht, zwei trennen; bei Gleichstand der frühere');
pruefe(am_stueck_noetig('auto', ['min' => 90]) === 1 && am_stueck_noetig('auto', ['min' => 301]) === 2 && am_stueck_noetig('auto', ['min' => 310]) === 3
       && am_stueck_noetig('auto', ['min' => null]) === 3 && am_stueck_noetig('auto', ['min' => 60, 'flug' => 'X']) === 3 && am_stueck_noetig('2', ['min' => 10]) === 2,
       'Am Stück: nötige Windtage nach Fahrzeit, Flug und ohne Landweg 3, feste Wahl');
[$code, $html] = holen($sitePort, 'index.php?km=1000&stueck=3');
pruefe($code === 200 && ohne_php_meldung($html) && str_contains($html, '<select name="stueck">') && str_contains($html, '<option value="3" selected>mindestens 3</option>') && str_contains($html, '>Windtage am Stück')
       && preg_match('#class="karte region[^"]* lohnt-nicht" id="r-adria-nord".*?Lohnt sich kaum: höchstens 2 Windtage am Stück \(Morgen bis .*?\), nötig 3#s', $html) === 1
       && !preg_match('/class="heim" id="heim"[^>]* data-wohin=/', $html) && str_contains($html, 'stueck=3'),
       'Am Stück 3: Adria mit 2 Windtagen lohnt sich kaum, Männchen fährt nicht hin, Links behalten die Wahl');
[, $html] = holen($sitePort, 'index.php?km=1000');
pruefe(str_contains($html, '✓ 2 Windtage am Stück: Morgen bis') && !str_contains($html, 'lohnt-nicht'), 'Am Stück nach Fahrzeit: Adria (5 h) mit 2 Windtagen lohnt');
[, $html] = holen($sitePort, 'index.php?km=1000&stueck=egal');
pruefe(!str_contains($html, 'stueck-zeile') && str_contains($html, '<option value="egal" selected>'), 'Am Stück egal: wie bisher');
[, $html] = holen($sitePort, 'index.php?km=1000&ansicht=woche&stueck=3');
pruefe(str_contains($html, '<tr class="lohnt-nicht"') && str_contains($html, ' stueck"'), 'Tabelle: lohnt sich kaum blass, Strecke markiert');
[, $html] = holen($sitePort, 'index.php?km=1000&ansicht=roadtrip');
pruefe(!str_contains($html, '<select name="stueck">'), 'Roadtrip: kein Feld Am Stück');

// Deine Regionen im Wind-Modus (seit 13.11.2026)
[, $html] = holen($sitePort, 'index.php?km=1000');
pruefe(str_contains($html, 'class="fav" data-id="ammersee" aria-pressed="false"') && str_contains($html, 'id="favZeile" hidden') && str_contains($html, '"wohinfahrma_fav_wind"'),
       'Wind: Sterne an den Regionen, Zeile für deine Regionen');
[$code, $html] = holen($sitePort, 'index.php?km=100&fav=adria-nord,<x>');
pruefe($code === 200 && ohne_php_meldung($html) && str_contains($html, 'id="r-adria-nord"') && !str_contains($html, 'id="r-ammersee"')
       && str_contains($html, '★ Nur deine Regionen (1), ohne Umkreis') && !str_contains($html, 'class="umkreis"') && str_contains($html, 'name="fav" value="adria-nord"')
       && str_contains($html, 'fav=adria-nord') && str_contains($html, 'class="fav" data-id="adria-nord" aria-pressed="true"'),
       'Wind: nur deine Regionen, ohne Umkreis, unbekannte ids weg');
[$code, $html] = holen($sitePort, 'index.php?anreise=flug&fav=ammersee');
pruefe($code === 200 && ohne_php_meldung($html) && str_contains($html, 'Keine deiner Regionen passt zur Anreise „Flug“'), 'Wind: deine Regionen ohne Flugziel: Hinweis');
[$code, $html] = holen($sitePort, 'index.php?km=1000&ansicht=woche');
pruefe($code === 200 && ohne_php_meldung($html) && str_contains($html, '<table class="matrix">') && substr_count($html, '<th scope="col"') === 8
       && substr_count($html, '<th scope="row"') === 2 && !str_contains($html, 'class="karte region'),
       'Woche: Matrix mit 7 Tagen, eine Zeile je Region, keine Regionskarten');
pruefe(preg_match('/id="r-adria-nord"|#r-adria-nord">Nördliche Adria/', $html) && preg_match('/<td class="go( stueck)?"[^>]*>.*?>100</s', $html)
       && str_contains($html, '>Tabelle 7 Tage</a>'), 'Woche: Adria morgen 100 %, verlinkt');
[, $html] = holen($sitePort, "index.php?km=1000&ansicht=woche&nach=$morgen");
pruefe(str_contains($html, 'class="m-aktiv"') && ohne_php_meldung($html), 'Woche nach einem Tag sortiert: Spalte markiert');
[$code, $html] = holen($sitePort, "stunden.php?region=adria-nord&tag=$morgen&vergleich=ammersee");
pruefe($code === 200 && ohne_php_meldung($html) && substr_count($html, 'class="stunden-bild"') === 2 && substr_count($html, 'class="s-mittel"') === 2
       && substr_count($html, 'class="s-fahrbar ja"') === 10 && str_contains($html, 'Nördliche Adria') && str_contains($html, 'Ammersee'),
       'Stundenseite: zwei Diagramme im Vergleich, Adria und Ammersee morgen je 5 fahrbare Stunden');
pruefe(substr_count($html, 'Wind: dicke Linie = Modellmittel') === 2
       && preg_match('/<summary>Was zeigt das Diagramm\?<\/summary><p>Wind: dicke Linie[^<]*<\/p><\/details><svg class="stunden-bild"/', $html),
       'Stundenseite: Legende Wind eingeklappt direkt über jedem Winddiagramm');
// Pfeil zeigt, wohin der Wind weht: Pfad zeigt ungedreht nach Sueden, gedreht wird um die Herkunft
pruefe(preg_match_all('/<g class="s-pfeil" transform="translate\([\d. ]+\) rotate\((\d+)\)"><title>aus (\d+)°/', $html, $pf) > 0
       && $pf[1] === array_map(fn($g) => (string)((int)$g % 360), $pf[2]),
       'Stundenseite: Windpfeile zeigen, wohin der Wind weht (wie die Landkarte)');
[$code, $html] = holen($sitePort, 'stunden.php?region=adria-nord');
pruefe($code === 200 && preg_match('/aria-current="true">(Morgen|Mo|Di|Mi|Do|Fr|Sa|So)/', $html) && ohne_php_meldung($html), 'Stundenseite ohne Tag: bester Tag gewählt');
[$code] = holen($sitePort, 'stunden.php?region=gibtsnicht');
pruefe($code === 404, 'Stundenseite: unbekannte Region 404');
[, $html] = holen($sitePort, 'index.php?km=1000');
pruefe(str_contains($html, 'class="stunden-link" href="stunden.php?region=adria-nord'), 'Rangliste verlinkt die Stundenseite');
[, $html] = holen($sitePort, 'index.php?km=1000');
pruefe(str_contains($html, 'Wasser 14 °C, 5/4 mm, Schuhe') && str_contains($html, 'Luft 12 °C'), 'Detailzeile: Meer mit Wasser und Neopren, See mit Luft');
pruefe(str_contains($html, 'Seemodell von Alplakes (Eawag), Seemitte in 1 m Tiefe') && str_contains($html, '>Alplakes, Eawag</a> (CC BY 4.0)'),
       'Ammersee: Wasser aus Alplakes im Tooltip, Quellenangabe im Fuß');
[, $html] = holen($sitePort, 'index.php?km=1000');
pruefe(str_contains($html, 'Welle 0,8 m (Dünung 0,3 m, 5 s aus ONO)'), 'Detailzeile am Meer: Welle mit Dünung');
[$code, $html] = holen($sitePort, "stunden.php?region=adria-nord&tag=$morgen");
pruefe($code === 200 && str_contains($html, 'welle-bild') && substr_count($html, 'class="s-welle"') > 5, 'Stundenseite am Meer: Wellendiagramm');
pruefe(substr_count($html, 'class="s-wetter"') > 5 && str_contains($html, '>⛅</text>') && str_contains($html, '<text class="s-temp"') && str_contains($html, '>12°</text>')
       && preg_match('#<div class="kz kz-sonne" title="teils sonnig, [^"]+"><span class="t">Wetter</span><span class="w"><span class="symbol">⛅</span></span><span class="u">teils sonnig</span>#', $html) === 1,
       'Stundenseite: Wetter und Luft je Stunde, Wetter als Kennzahl');
pruefe(preg_match('#<p class="urteil wind passt">Morgen: <b>[^<]+</b>#', $html) === 1
       && preg_match('#<div class="kennzahlen"><div class="kz kz-wind"><span class="t">Wind Ø</span><span class="w">\d+<small> kn</small></span><span class="u">Böen bis \d+ kn</span>#', $html) === 1
       && str_contains($html, '<div class="kz kz-temp"><span class="t">Luft</span><span class="w">12°</span>')
       && str_contains($html, '<div class="kz kz-wasser"><span class="t">Wasser</span><span class="w">14°</span><span class="u">Meer</span>')
       && str_contains($html, '<span class="t">Welle</span><span class="w">0,8<small> m</small></span><span class="u">Dünung 0,3 m</span>') && str_contains($html, 'title="Dünung 0,3 m, 5 s, Mittel im Fenster"')
       && str_contains($html, '<p class="neopren-zeile"><span class="leise">Neopren</span> <b>5/4 mm, Schuhe</b>'),
       'Stundenseite: Urteil groß, Tageswerte als Kennzahlen (Wind, Wetter, Luft, Wasser, Welle), Neopren eigene Zeile');
$tag3 = (new DateTimeImmutable('+3 days', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
[, $html] = holen($sitePort, "stunden.php?region=ammersee&tag=$tag3");
pruefe(!str_contains($html, 'nicht kalibriert: Stunde fahrbar'), 'Stundenseite ohne fremde Prognose: kein Modell-Hinweis im Diagramm');
pruefe(str_contains($html, '<div class="kz kz-wind" title="Mittel und höchste Böe 9–17 Uhr"><span class="t">Wind Ø</span><span class="w">6<small> kn</small></span><span class="u">Böen bis 10 kn</span>'),
       'Stundenseite: Wind-Kachel zeigt die Böen auch an Tagen ohne Fenster (zu wenig Wind)');
[, $hk] = holen($sitePort, 'index.php?km=1000');
pruefe(preg_match('#id="r-adria-nord".*?<span class="wetter">⛅<b>12°</b></span>#s', $hk) === 1 && str_contains($hk, 'Teils sonnig, Luft 12 °C von '),
       'Kachel: Wettersymbol und Luft, Tooltip mit Wetter');
[$code, $html] = holen($sitePort, "index.php?km=1000&sport=wellenreiten");
pruefe($code === 200 && ohne_php_meldung($html) && str_contains($html, 'ab 0,8 m Dünung'), 'Rangliste Wellenreiten ohne Fehler');
[, $html] = holen($sitePort, 'index.php?km=1000');
pruefe(str_contains($html, '<details class="spotinfo klein"><summary>Spot-Infos (1)') && str_contains($html, 'Testtipp für den Ammersee'), 'Regionskarte: Spot-Infos aufklappbar');
pruefe(substr_count($html, 'class="maps-knopf"') >= 1 && str_contains($html, 'https://www.google.com/maps/search/?api=1&amp;query='), 'Regionskarte: Maps-Knöpfe');
[, $html] = holen($sitePort, "index.php?km=1000&nach=$morgen");
pruefe(str_contains($html, '<select name="nach">') && str_contains($html, '<option value="' . $morgen . '" selected>') && substr_count($html, '<nav class="reiter" aria-label="Ansicht">') === 1
       && str_contains($html, '>Kacheln</a>') && str_contains($html, 'class="zeitraum klein"') && str_contains($html, '<details class="leise klein erklaerung">'),
       'Bedienung: Sortierung im Formular, drei Ansichten, Zeitraum nur in der Liste, Erklärung eingeklappt');
[, $html] = holen($sitePort, 'index.php?km=1000&ansicht=woche');
pruefe(!str_contains($html, '<select name="nach">') && !str_contains($html, 'class="zeitraum klein"'), 'Woche: ohne Sortierfeld und Zeitraum');
[, $html] = holen($sitePort, 'index.php?km=1000');
pruefe(str_contains($html, 'class="karte region beste-go" id="r-adria-nord"') && str_contains($html, 'class="wahl karte steuer"'),
       'Farbe: Balken nach dem besten Tag, Einstellungen eigens getönt');
foreach (['impressum.html', 'datenschutz.html', 'karte.svg', 'stil.css', 'sw.js', 'offline.html', 'icon-192.png', 'icon-512.png', 'apple-touch-icon.png', 'icon.svg', 'icon-maskable-512.png', 'favicon.svg', 'favicon.ico', 'schrift/barlow-condensed-600.woff', 'schrift/OFL.txt'] as $f) {
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

// Fremde Prognose (seit 10.11.2026): Walchensee-Predictor aus dem Ordner von ammerseewind.de
echo "\nFremde Prognose\n";
$fJetzt = strtotime('2030-07-10 08:00:00 UTC');   // 10 Uhr Ortszeit
$fDir = "$tmp/fremd/walchensee";
@mkdir($fDir, 0777, true);
$fPayload = fn(array $extra = []) => $extra + [
    'status' => 'success', 'stale' => false, 'generated_at' => '2030-07-10 06:10:00',
    'days' => [
        ['date' => '2030-07-10', 'data_available' => true, 'chance' => 0.72, 'status' => 'go', 'peak_kt' => 16.4],
        ['date' => '2030-07-11', 'data_available' => true, 'chance' => 0.35, 'status' => 'maybe', 'peak_kt' => 11.0],
        ['date' => '2030-07-12', 'data_available' => false, 'chance' => 0.0],
    ],
];
$fSchreib = fn(array $p) => file_put_contents("$fDir/2030-07-10.ndjson",
    json_encode(['ts' => '2030-07-10T05:15:00Z', 'ok' => true, 'payload' => $fPayload()]) . "\n"
  . json_encode(['ts' => '2030-07-10T06:15:00Z', 'ok' => false, 'error' => 'timeout']) . "\n"
  . json_encode(['ts' => '2030-07-10T07:15:00Z', 'ok' => true, 'payload' => $p]) . "\n");
$fSchreib($fPayload());
$fp = fremdprognose_lesen($fDir, $fJetzt);
pruefe($fp !== null && array_keys($fp['tage']) === ['2030-07-10', '2030-07-11'], 'liest den neuesten erfolgreichen Abruf, nur Tage mit Daten');
pruefe(abs($fp['tage']['2030-07-10']['chance'] - 0.72) < 1e-9 && $fp['tage']['2030-07-10']['stufe'] === 'go' && $fp['tage']['2030-07-10']['peak_kt'] === 16.4, 'Chance, Stufe und Spitze übernommen');
pruefe($fp['stand'] === strtotime('2030-07-10 04:10:00 UTC'), 'generated_at als Ortszeit Europe/Berlin');
$fSchreib($fPayload(['stale' => true]));
pruefe(fremdprognose_lesen($fDir, $fJetzt) === null, 'stale: keine Prognose (auch nicht die ältere Zeile)');
$fSchreib($fPayload(['generated_at' => '2030-07-09 10:00:00']));
pruefe(fremdprognose_lesen($fDir, $fJetzt) === null, 'älter als 18 h: keine Prognose');
pruefe(fremdprognose_lesen("$tmp/fremd/gibtsnicht", $fJetzt) === null, 'ohne Ordner: keine Prognose');
$fSchreib($fPayload());

$fRegion = ['id' => 'walchensee', 'name' => 'Walchensee', 'zeitzone' => 'Europe/Berlin',
            'fremdprognose' => ['name' => 'Marco Schanda', 'url' => 'https://walchenseewindpredictor.onrender.com/']];
pruefe(regionen_pruefen(['regionen' => [$fRegion + ['land' => 'DE', 'typ' => 'see', 'punkt' => [47.59, 11.34], 'windlagen' => [], 'spots' => []]]]) === [], 'Katalogfeld fremdprognose gültig');
$fFalsch = regionen_pruefen(['regionen' => [['fremdprognose' => ['name' => 'X', 'url' => 'http://a']] + $fRegion + ['land' => 'DE', 'typ' => 'see', 'punkt' => [47.59, 11.34], 'windlagen' => [], 'spots' => []]]]);
pruefe((bool)array_filter($fFalsch, fn($x) => str_contains($x, 'fremdprognose')), 'fremdprognose ohne https wird abgelehnt');

fremdprognosen_laden(['fremdprognosen' => ['walchensee' => $fDir]], [$fRegion, ['id' => 'adria-nord', 'zeitzone' => 'Europe/Rome']], $fJetzt);
pruefe(array_keys(fremdprognosen()) === ['walchensee'], 'nur Regionen mit Katalogfeld und Ordner');
$fTag = fn(float $c) => ['spot' => null, 'det' => ['geht' => false, 'stunden' => 0, 'grund' => 'zu wenig Wind'], 'ens' => ['chance' => $c, 'laeufe' => 51], 'vorbei' => false, 'offen' => false];
$fProTag = ['2030-07-10' => $fTag(0.05), '2030-07-11' => $fTag(0.1), '2030-07-12' => $fTag(0.2)];
$fUm = fn(string $hhmm) => new DateTimeImmutable("2030-07-10 $hhmm", new DateTimeZone('Europe/Berlin'));
$fx = fremd_einmischen($fRegion, $fProTag, SPORTARTEN['kite_twintip'], $fUm('10:00'));
pruefe(abs($fx['2030-07-10']['ens']['chance'] - 0.72) < 1e-9 && abs($fx['2030-07-11']['ens']['chance'] - 0.35) < 1e-9, 'Kite: Chance aus der fremden Prognose');
pruefe(($fx['2030-07-10']['fremd']['name'] ?? '') === 'Marco Schanda' && !isset($fx['2030-07-12']['fremd']) && abs($fx['2030-07-12']['ens']['chance'] - 0.2) < 1e-9, 'Tag ohne seine Daten bleibt beim eigenen Wert');
pruefe(isset(fremd_einmischen($fRegion, $fProTag, SPORTARTEN['wingfoil'], $fUm('10:00'))['2030-07-10']['fremd'])
       && isset(fremd_einmischen($fRegion, $fProTag, SPORTARTEN['windsurf_foil'], $fUm('10:00'))['2030-07-10']['fremd']), 'Wing und Windsurf: auch fremde Chance');
pruefe(!isset(fremd_einmischen($fRegion, $fProTag, SPORTARTEN['segeln'], $fUm('10:00'))['2030-07-10']['fremd'])
       && !isset(fremd_einmischen($fRegion, $fProTag, SPORTARTEN['wellenreiten'], $fUm('10:00'))['2030-07-10']['fremd']), 'Segeln und Wellenreiten: eigener Wert');
pruefe(!isset(fremd_einmischen($fRegion, $fProTag, SPORTARTEN['kite_wave'], $fUm('10:00'))['2030-07-10']['fremd']), 'Kite Wave: eigener Wert');
$fy = fremd_einmischen($fRegion, $fProTag, SPORTARTEN['kitefoil'], $fUm('15:30'));
pruefe(!isset($fy['2030-07-10']['fremd']) && isset($fy['2030-07-11']['fremd']), 'heute ab 15 Uhr nicht mehr, morgen schon');
$fz = fremd_einmischen($fRegion, ['2030-07-11' => ['det' => ['geht' => false, 'stunden' => 0, 'grund' => 'Sperre: Test']] + $fTag(0.0)], SPORTARTEN['kite_twintip'], $fUm('10:00'));
pruefe(!isset($fz['2030-07-11']['fremd']), 'Sperre bleibt Sperre');
pruefe(fremd_text($fx['2030-07-10']['fremd'], new DateTimeZone('Europe/Berlin')) === 'GO · Spitze 16 kn · Stand 6:10', 'Text für die Seite');
$fE = ranglisten_eintrag($fRegion, 50, $fx);
pruefe(abs($fE['erwartete_tage'] - (0.72 + 0.35 + 0.2)) < 1e-9, 'Rangliste zählt die fremde Chance');
fremdprognosen([]);

// ammerseewind.de (seit 11.11.2026): Exportdatei mit einer Chance je Sportart
echo "\nammerseewind.de\n";
$aDir = "$tmp/fremd/winddaten";
@mkdir("$aDir/export", 0777, true);
$aExport = fn(array $extra = []) => $extra + [
    'version' => 1, 'erzeugt' => '2030-07-10T07:40:00Z', 'lauf' => '2030-07-10T07:00:00Z', 'alter_h' => 0.7,
    'quelle' => 'ammerseewind.de', 'ort' => 'Stegen',
    'tage' => [
        '2030-07-10' => ['von' => 9, 'bis' => 20, 'rest' => false, 'vorbei' => false, 'sektor' => 'sued',
                         'chance' => ['directional' => 0.64, 'twintip' => 0.62, 'wing' => 0.9, 'segeln' => 0.3]],
        '2030-07-11' => ['von' => 9, 'bis' => 20, 'rest' => false, 'vorbei' => false, 'sektor' => 'west',
                         'chance' => ['twintip' => 0.05, 'segeln' => 0.8]],
        '2030-07-09' => ['vorbei' => true, 'chance' => []],
    ],
];
file_put_contents("$aDir/export/wohinfahrma.json", json_encode($aExport()));
$aRegion = ['id' => 'ammersee', 'name' => 'Ammersee', 'zeitzone' => 'Europe/Berlin',
            'fremdprognose' => ['name' => 'ammerseewind.de (kalibriert für Stegen)', 'url' => 'https://ammerseewind.de/',
                                'format' => 'ammerseewind', 'pfad' => 'export/wohinfahrma.json']];
pruefe(regionen_pruefen(['regionen' => [$aRegion + ['land' => 'DE', 'typ' => 'see', 'punkt' => [48.0, 11.1], 'windlagen' => [], 'spots' => []]]]) === [], 'Katalogfeld mit format und pfad gültig');
$aFalsch = $aRegion;
$aFalsch['fremdprognose']['format'] = 'xyz';
pruefe((bool)array_filter(regionen_pruefen(['regionen' => [$aFalsch + ['land' => 'DE', 'typ' => 'see', 'punkt' => [48.0, 11.1], 'windlagen' => [], 'spots' => []]]]), fn($x) => str_contains($x, 'format')), 'unbekanntes format wird abgelehnt');
pruefe(fremdprognose_quelle(['fremdprognosen' => ['walchensee' => "$aDir/walchensee"]], $aRegion) === "$aDir/export/wohinfahrma.json", 'Datei aus dem Elternordner der Walchensee-Prognose');
pruefe(fremdprognose_quelle(['winddaten' => '/x/winddaten/'], $aRegion) === '/x/winddaten/export/wohinfahrma.json', 'Datei aus winddaten in config.php');
pruefe(fremdprognose_quelle(['fremdprognosen' => ['ammersee' => '/y/datei.json']], $aRegion) === '/y/datei.json', 'eigener Eintrag geht vor');
pruefe(fremdprognose_quelle([], $aRegion) === null, 'ohne Ordner: keine Quelle');
fremdprognosen_laden(['fremdprognosen' => ['walchensee' => "$aDir/walchensee"]], [$aRegion], $fJetzt);
$ap = fremdprognosen()['ammersee'] ?? null;
pruefe($ap !== null && array_keys($ap['tage']) === ['2030-07-10', '2030-07-11'] && $ap['stand'] === strtotime('2030-07-10T07:40:00Z'), 'Export gelesen, vorbei-Tage weggelassen');
$ax = fremd_einmischen($aRegion, $fProTag, SPORTARTEN['kite_twintip'], $fUm('10:00'));
pruefe(abs($ax['2030-07-10']['ens']['chance'] - 0.62) < 1e-9 && abs($ax['2030-07-11']['ens']['chance'] - 0.05) < 1e-9, 'Twintip: Zahl der Sportart');
pruefe(($ax['2030-07-10']['fremd']['stufe'] ?? '') === 'lohnt' && ($ax['2030-07-11']['fremd']['stufe'] ?? '') === 'no', 'Stufen wie in den Mails von ammerseewind.de');
pruefe(fremd_text($ax['2030-07-10']['fremd'], new DateTimeZone('Europe/Berlin')) === 'lohnt sich · Stand 9:40', 'Text ohne Spitze');
pruefe(($ax['2030-07-10']['fremd']['stunden'] ?? null) === [] && array_key_exists('beste', $ax['2030-07-10']['fremd']) && $ax['2030-07-10']['fremd']['beste'] === null, 'alter Export ohne Stunden: leer, keine beste Zeit');
$aNeu = $aExport();
$aNeu['tage']['2030-07-10'] += ['stunden' => ['9' => 11.2, '13' => 16.8, '25' => 3, 'x' => 4], 'staerkste' => 13, 'spitze' => 16.8,
                                'beste' => ['twintip' => [12, 15], 'wing' => [15, 12], 'segeln' => 'x']];
file_put_contents("$aDir/export/wohinfahrma.json", json_encode($aNeu));
fremdprognosen_laden(['fremdprognosen' => ['walchensee' => "$aDir/walchensee"]], [$aRegion], $fJetzt);
$aK = fremd_einmischen($aRegion, $fProTag, SPORTARTEN['kite_twintip'], $fUm('10:00'))['2030-07-10']['fremd'] ?? [];
pruefe(($aK['stunden'] ?? null) === [9 => 11.2, 13 => 16.8] && ($aK['beste'] ?? null) === [12, 15] && ($aK['staerkste'] ?? null) === 13 && ($aK['peak_kt'] ?? null) === 16.8,
       'Export mit Stunden: kalibrierter Wind je Stunde, beste Zeit der Sportart, Spitze; ungültige Einträge weggelassen');
pruefe(fremd_text($aK, new DateTimeZone('Europe/Berlin')) === 'lohnt sich · 12–15 Uhr · Spitze 17 kn · Stand 9:40', 'Text mit bester Zeit und Spitze');
$aW = fremd_einmischen($aRegion, $fProTag, SPORTARTEN['wingfoil'], $fUm('10:00'))['2030-07-10']['fremd'] ?? [];
pruefe(array_key_exists('beste', $aW) && $aW['beste'] === null && $aW['stunden'] === [9 => 11.2, 13 => 16.8], 'ungültige beste Zeit (von > bis): keine, Stunden trotzdem da');
file_put_contents("$aDir/export/wohinfahrma.json", json_encode($aExport()));
fremdprognosen_laden(['fremdprognosen' => ['walchensee' => "$aDir/walchensee"]], [$aRegion], $fJetzt);
$ay = fremd_einmischen($aRegion, $fProTag, SPORTARTEN['segeln'], $fUm('10:00'));
pruefe(abs($ay['2030-07-10']['ens']['chance'] - 0.3) < 1e-9, 'Segeln: auch von ammerseewind.de');
$az = fremd_einmischen($aRegion, $fProTag, SPORTARTEN['kitefoil'], $fUm('10:00'));
pruefe(!isset($az['2030-07-10']['fremd']) && abs($az['2030-07-10']['ens']['chance'] - 0.05) < 1e-9, 'Sportart ohne Zahl im Export: eigener Wert');
pruefe(!isset(fremd_einmischen($aRegion, $fProTag, SPORTARTEN['kite_wave'], $fUm('10:00'))['2030-07-10']['fremd']), 'Wave: eigener Wert');
$aSpaet = fremd_einmischen($aRegion, $fProTag, SPORTARTEN['kite_twintip'], $fUm('12:00'));
pruefe(!isset($aSpaet['2030-07-10']['fremd']) && isset($aSpaet['2030-07-11']['fremd']), 'heute nur, solange der Export höchstens 2 h alt ist');
pruefe(isset(fremd_einmischen($aRegion, $fProTag, SPORTARTEN['kite_twintip'], $fUm('16:00'))['2030-07-11']['fremd']), 'nach 15 Uhr: morgen weiter (keine Walchensee-Grenze)');
file_put_contents("$aDir/export/wohinfahrma.json", json_encode($aExport(['erzeugt' => '2030-07-09T08:00:00Z'])));
fremdprognosen_laden(['winddaten' => $aDir], [$aRegion], $fJetzt);
pruefe(!isset(fremdprognosen()['ammersee']), 'älter als 18 h: keine Prognose');
file_put_contents("$aDir/export/wohinfahrma.json", json_encode($aExport(['version' => 2])));
fremdprognosen_laden(['winddaten' => $aDir], [$aRegion], $fJetzt);
pruefe(!isset(fremdprognosen()['ammersee']), 'andere Version: keine Prognose');
file_put_contents("$aDir/export/wohinfahrma.json", '{kaputt');
fremdprognosen_laden(['winddaten' => $aDir], [$aRegion], $fJetzt);
pruefe(!isset(fremdprognosen()['ammersee']), 'kaputte Datei: keine Prognose, kein Fehler');
fremdprognosen([]);

// Schnee-Modus (seit 8.11.2026)
require __DIR__ . '/test_schnee.php';

// Aufraeumen
exec('rm -rf ' . escapeshellarg($tmp));

echo $fehler ? "\n$fehler FEHLER\n" : "\nAlles grün\n";
exit($fehler ? 1 : 0);
