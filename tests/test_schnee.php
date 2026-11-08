<?php
/**
 * Tests Schnee-Modus (seit 8.11.2026), eingebunden am Ende von tests/test.php.
 * Nutzt pruefe(), holen(), ohne_php_meldung(), $ROOT, $tmp und $port von dort.
 */

require_once "$ROOT/server/web/lib/schnee.php";
require_once "$ROOT/server/web/lib/lawine.php";

echo "Schnee: Katalog\n";
$sk = schnee_katalog_laden("$ROOT/daten/schneeregionen.json");
pruefe(count($sk['regionen']) >= 8, 'echter Schnee-Katalog gültig, mindestens 8 Regionen');
$windIds = array_column(regionen_laden("$ROOT/daten/regionen.json"), 'id');
pruefe(!array_intersect($windIds, array_column($sk['regionen'], 'id')), 'ids von Wind- und Schnee-Katalog verschieden (gemeinsame fahrzeiten.json)');
pruefe(($sk['lawinendienste']['bayern']['freigegeben'] ?? null) === [] && !in_array('AT-07', $sk['lawinendienste']['euregio']['freigegeben'], true),
       'Bayern und Tirol nicht freigegeben, solange die Lizenz offen ist');
pruefe(schnee_katalog_laden(__DIR__ . '/schneeregionen_test.json')['regionen'][0]['typ'] === 'schnee', 'Testkatalog gültig, typ schnee gesetzt');
$kaputt = json_decode(file_get_contents(__DIR__ . '/schneeregionen_test.json'), true);
$kaputt['lawinendienste']['slf']['freigegeben'] = ['AT-'];
$kaputt['regionen'][0]['hoehe_tal'] = 3000;
$kaputt['regionen'][1]['warndienst'] = 'gibtsnicht';
$kaputt['regionen'][2]['eaws_namen'] = [];
$kaputt['regionen'][3]['eaws'] = ['CH-1'];
$f = implode(' | ', schneeregionen_pruefen($kaputt));
pruefe(str_contains($f, 'freigegeben AT-') && str_contains($f, 'hoehe_tal') && str_contains($f, 'warndienst fehlt')
       && str_contains($f, 'eaws oder eaws_namen') && str_contains($f, 'eaws CH-1 gehört nicht'), 'fehlerhafter Katalog: alle Fehler gemeldet');

echo "Schnee: Neuschnee je Stunde\n";
pruefe(abs(schnee_neu_stunde(2.0, 0.0, 1200.0, null, 2400) - 1.4) < 1e-9, 'Grenze unter Tourhöhe: 2 mm = 1,4 cm');
pruefe(schnee_neu_stunde(2.0, 1.4, 2800.0, null, 2400) === 0.0, 'Grenze über Tourhöhe: Regen, kein Schnee (auch wenn snowfall etwas meldet)');
pruefe(abs(schnee_neu_stunde(1.0, null, null, -3.0, 2400) - 0.7) < 1e-9 && schnee_neu_stunde(1.0, null, null, 4.0, 2400) === 0.0, 'ohne Grenze: nach Temperatur auf Tourhöhe');
pruefe(schnee_neu_stunde(0.0, 0.5, null, null, 2400) === 0.5 && schnee_grenze(null, 1500.0) === 1200.0, 'ohne Niederschlag: snowfall; Grenze aus Nullgrad minus 300 m');

echo "Schnee: Lagebericht lesen\n";
$slf = '{"bulletins":[{"bulletinID":"x","publicationTime":"2026-01-10T16:00:00Z","validTime":{"startTime":"2026-01-10T16:00:00Z","endTime":"2026-01-11T16:00:00Z"},'
     . '"regions":[{"regionID":"CH-7111","name":"Oberengadin"},{"regionID":"AT-07-01","name":"Fremd"}],'
     . '"dangerRatings":[{"mainValue":"considerable","elevation":{"lowerBound":"2200"},"validTimePeriod":"all_day"},{"mainValue":"moderate","elevation":{"upperBound":"2200"}}],'
     . '"avalancheProblems":[{"problemType":"wind_slab","aspects":["N","NE","X"],"elevation":{"lowerBound":"treeline"}}]}]}';
$lj = lawine_lesen($slf, 'json');
pruefe($lj && $lj[0]['stufen'][0] === ['stufe' => 3, 'ab' => '2200', 'bis' => null, 'zeit' => 'all_day'] && $lj[0]['probleme'][0]['aspekte'] === ['N', 'NE'],
       'JSON: Stufe mit Höhengrenze, Problem mit Expositionen (Unbekanntes verworfen)');
pruefe(lawine_stufen_text($lj[0]['stufen']) === '3 erheblich über 2.200 m, 2 mäßig unter 2.200 m' && lawine_problem_text($lj[0]['probleme'][0]) === 'Triebschnee (N, NO über Waldgrenze)',
       'Texte: Stufen und Problem auf Deutsch');
pruefe(lawine_filtern($lj, ['CH-'])[0]['regionen'] === [['CH-7111', 'Oberengadin']] && lawine_filtern($lj, ['IT-']) === [], 'nur freigegebene Mikroregionen bleiben');
$xml = '<?xml version="1.0"?><bulletins xmlns="http://caaml.org/Schemas/V6.0/Profiles/BulletinEAWS"><bulletin bulletinID="b"><publicationTime>2026-01-10T16:00:00Z</publicationTime>'
     . '<validTime><startTime>2026-01-10T16:00:00Z</startTime><endTime>2026-01-11T16:00:00Z</endTime></validTime><region regionID="DE-BY-30"><name>Werdenfelser Alpen</name></region>'
     . '<dangerRating><mainValue>low</mainValue><validTimePeriod>all_day</validTimePeriod></dangerRating>'
     . '<avalancheProblem><problemType>gliding_snow</problemType><aspect>S</aspect><aspect>SW</aspect></avalancheProblem></bulletin></bulletins>';
$lx = lawine_lesen($xml, 'xml');
pruefe($lx && $lx[0]['regionen'] === [['DE-BY-30', 'Werdenfelser Alpen']] && $lx[0]['stufen'][0]['stufe'] === 1 && $lx[0]['probleme'][0]['aspekte'] === ['S', 'SW'] && $lx[0]['von'] === '2026-01-10T16:00:00Z',
       'XML (Namensraum, Attribute, Expositionen direkt als aspect)');
pruefe(lawine_lesen('<ObsCollection xmlns="http://caaml.org/Schemas/V5.0/Profiles/BulletinEAWS"/>', 'xml') === null && lawine_lesen('kaputt', 'json') === null && lawine_lesen('', 'xml') === null,
       'CAAML v5 und Unsinn werden nicht gelesen');
pruefe(str_contains(lawine_rose(['N']), 'class="an"') && substr_count(lawine_rose(['N']), '<path') === 8, 'Expositionsrose: 8 Sektoren, betroffene markiert');

// ---------------------------------------------------------------
echo "Schnee: Sammler\n";
$sPort = $port + 5;
$sServer = proc_open(['php', '-S', "127.0.0.1:$sPort", __DIR__ . '/fake_openmeteo.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $sPort); $i++) {
    usleep(100000);
}
$sCmd = fn(string $ziel, string $extra = '') => 'UMKREIS_TEST_URL=' . escapeshellarg("http://127.0.0.1:$sPort")
    . ' php ' . escapeshellarg("$ROOT/server/sammler.php") . ' --action ' . escapeshellarg(__DIR__ . '/regionen_test.json') . ' ' . escapeshellarg($ziel)
    . ' --schnee ' . escapeshellarg(__DIR__ . '/schneeregionen_test.json') . " $extra --debug 2>&1";
exec($sCmd("$tmp/schnee"), $ausS, $rcS);
$ausText = implode("\n", $ausS);
pruefe($rcS === 0 && !str_contains($ausText, 'FEHLER'), 'Action-Lauf mit --schnee ohne Fehler' . ($rcS ? ': ' . substr($ausText, -400) : ''));
foreach (['schnee_vorhersage', 'schnee_ensemble', 'lawine', 'vorhersage'] as $q) {
    pruefe(count(glob("$tmp/schnee/$q/" . gmdate('Y-m-d') . '_*.ndjson.gz')) === 1, "$q: eine gezippte Datei für den Lauf");
}
pruefe(str_contains($ausText, 'lawine/euregio: ') && str_contains($ausText, 'noch kein Bericht (404)') && !str_contains($ausText, 'lawine/gesperrt'),
       'Lagebericht: 404 für morgen ist kein Fehler; nicht freigegebener Dienst wird nie abgerufen');
$sStand = daten_laden("$tmp/schnee", ['schnee_vorhersage', 'schnee_ensemble']);
pruefe(($sStand['schnee_vorhersage']['schnee-a']['test_elevation'] ?? null) === '2400,2000,2200,2400', 'Vorhersage auf Tourhöhe angefragt (elevation je Ort)');
pruefe(count($sStand['schnee_vorhersage']['schnee-a']['hourly']['time']) === 24 * 11, '3 Tage zurück (past_days) und 8 voraus');
$lagen = lawine_laden("$tmp/schnee");
$euIds = array_merge(...array_map(fn($b) => array_column($b['regionen'], 0), $lagen['euregio']['bulletins'] ?? []));
pruefe($euIds === ['IT-32-BZ-08-01'], 'Euregio: nur Südtirol gespeichert, Tirol nicht');
exec($sCmd("$tmp/schnee_nur", '--nur lawine'), $ausN, $rcN);
pruefe($rcN === 0 && is_dir("$tmp/schnee_nur/lawine") && !is_dir("$tmp/schnee_nur/vorhersage") && !is_dir("$tmp/schnee_nur/schnee_ensemble"), '--nur lawine: nur der Lagebericht');

echo "Schnee: Bewertung\n";
$sKat = schnee_katalog_laden(__DIR__ . '/schneeregionen_test.json');
$sTage = [];
for ($i = 0; $i < 4; $i++) {
    $sTage[] = (new DateTimeImmutable("+$i day", new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
}
$fruehmorgens = new DateTimeImmutable('today 05:00', new DateTimeZone('Europe/Berlin'));
$sRl = schnee_rangliste($sKat['regionen'], $sStand, SCHNEE_SPORTARTEN['freeride'], [48.07, 11.15], 1000, $sTage, $fruehmorgens);
$sBy = [];
foreach ($sRl as $e) {
    $sBy[$e['region']['id']] = $e;
}
[$t0, $t1, $t2, $t3] = $sTage;
$a1 = $sBy['schnee-a']['tage'][$t1] ?? null;
pruefe($a1 && $a1['det']['neu72'] > 22 && $a1['det']['neu72'] < 32 && $a1['det']['geht'] && $a1['ens']['chance'] == 1,
       'Region A morgen: rund 25 cm Neuschnee aus gestern, sonnig, alle Läufe passen (' . ($a1['det']['neu72'] ?? '?') . ' cm)');
$a2 = $sBy['schnee-a']['tage'][$t2]['ens']['chance'] ?? null;
pruefe($a2 !== null && $a2 > 0 && $a2 < 1, 'Region A übermorgen: nur ein Teil des Schnees noch in 72 h, Chance zwischen 0 und 100 % (' . $a2 . ')');
pruefe(($sBy['schnee-a']['tage'][$t3]['det']['grund'] ?? '') === 'zu wenig Neuschnee', 'Region A in drei Tagen: zu wenig Neuschnee');
pruefe(($sBy['schnee-b']['tage'][$t1]['det']['neu72'] ?? null) == 0.0 && $sBy['schnee-b']['tage'][$t1]['det']['grenze'] == 2800, 'Region B: Regen über der Tourhöhe zählt nicht');
pruefe(array_key_first($sBy) === 'schnee-a' || array_key_first($sBy) === 'schnee-d', 'Rangliste: Regionen mit Neuschnee vorn');
pruefe(($sBy['schnee-a']['tage'][$t0]['vorbei'] ?? true) === false && $sBy['schnee-a']['tage'][$t0]['fenster'] === SCHNEE_TAG, 'heute früh: Tag nicht vorbei, volles Tourenfenster');
$spaet = schnee_region_bewerten($sKat['regionen'][0], ['vorhersage' => $sStand['schnee_vorhersage']['schnee-a'], 'ensemble' => null], SCHNEE_SPORTARTEN['freeride'], [$t0],
                                new DateTimeImmutable('today 14:00', new DateTimeZone('Europe/Vienna')))[$t0];
pruefe($spaet['vorbei'] && !$spaet['det']['geht'], 'heute nach 13:30: vorbei');
$tour = schnee_rangliste($sKat['regionen'], $sStand, SCHNEE_SPORTARTEN['skitour'], [48.07, 11.15], 1000, [$t1], $fruehmorgens);
$tourB = array_values(array_filter($tour, fn($e) => $e['region']['id'] === 'schnee-b'))[0]['tage'][$t1]['det'] ?? [];
$tourA = array_values(array_filter($tour, fn($e) => $e['region']['id'] === 'schnee-a'))[0]['tage'][$t1]['det'] ?? [];
pruefe(($tourA['geht'] ?? false) && ($tourB['grund'] ?? '') === 'zu wenig Schnee', 'Skitour: A mit 80 cm geht, B mit 10 cm zu wenig Schnee');
pruefe(schnee_tag_geht(['neu72' => 30, 'hs' => 50, 'wolken' => 10, 'kamm' => 45], SCHNEE_SPORTARTEN['freeride'])[1] === 'zu viel Wind am Kamm', 'zu viel Wind am Kamm');

echo "Schnee: Lawinenlage je Region\n";
$sR = [];
foreach ($sKat['regionen'] as $r) {
    $sR[$r['id']] = $r;
}
$la = lawine_lage($sR['schnee-a'], $lagen, $t1);
pruefe($la && $la['stufe'] === 3 && $la['ueber_namen'] && $la['regionen'] === [['CH-7111', 'Oberengadin']], 'A: SLF über den Namen „Oberengadin“, Stufe 3 morgen');
$lb = lawine_lage($sR['schnee-b'], $lagen, $t0);
pruefe($lb && $lb['stufe'] === 2 && ($lb['probleme'][0]['aspekte'] ?? []) === ['N', 'NW'] && !$lb['ueber_namen'], 'B: Bayern (XML) über DE-BY-30, Stufe 2, Triebschnee N und NW');
pruefe(lawine_lage($sR['schnee-c'], $lagen, $t0)['stufe'] === 4, 'C: Südtirol über den Namen „Ahrntal“');
pruefe(lawine_lage($sR['schnee-a'], $lagen, $t3) === null, 'ohne gültigen Bericht (in drei Tagen): keine Lage, keine Hochrechnung');
pruefe(!lawine_freigegeben($sR['schnee-d'], $sKat['lawinendienste']) && lawine_freigegeben($sR['schnee-b'], $sKat['lawinendienste']), 'Freigabe je Warndienst');
pruefe(!lawine_freigegeben($sk['regionen'][0], $sk['lawinendienste']) && lawine_freigegeben(array_values(array_filter($sk['regionen'], fn($r) => $r['id'] === 'oberengadin'))[0], $sk['lawinendienste']),
       'echter Katalog: Werdenfels (Bayern) nur Link, Oberengadin (SLF) mit Stufe');

echo "Schnee: Fahrzeiten mit zwei Katalogen\n";
$oPort = $port + 6;
$oServer = proc_open(['php', '-S', "127.0.0.1:$oPort", __DIR__ . '/fake_ors.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $oPort); $i++) {
    usleep(100000);
}
exec('ORS_API_KEY=testschluessel UMKREIS_ORS_URL=' . escapeshellarg("http://127.0.0.1:$oPort") . ' php ' . escapeshellarg("$ROOT/server/fahrzeiten.php")
     . ' ' . escapeshellarg(__DIR__ . '/regionen_test.json') . ' ' . escapeshellarg(__DIR__ . '/schneeregionen_test.json') . ' ' . escapeshellarg("$tmp/schnee/fahrzeiten.json") . ' 2>&1', $ausF, $rcF);
$fzS = json_decode((string)@file_get_contents("$tmp/schnee/fahrzeiten.json"), true);
pruefe($rcF === 0 && isset($fzS['ziele']['ammersee']['min'], $fzS['ziele']['schnee-a']['min']) && $fzS['ziele']['schnee-a']['ziel'][0] > 47.2,
       'Wind- und Schnee-Regionen in einer Datei, Ziel = anfahrt');
proc_terminate($oServer);
proc_terminate($sServer);

// ---------------------------------------------------------------
echo "Schnee: Seite\n";
$sSite = "$tmp/schnee_site";
exec('cp -r ' . escapeshellarg("$ROOT/server/web") . ' ' . escapeshellarg($sSite));
copy(__DIR__ . '/regionen_test.json', "$sSite/lib/regionen.json");
copy(__DIR__ . '/schneeregionen_test.json', "$sSite/lib/schneeregionen.json");
file_put_contents("$sSite/config.php", '<?php return ' . var_export(['data_dir' => "$tmp/schnee"], true) . ';');
$wPort = $port + 7;
$sWeb = proc_open(['php', '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'opcache.enable=0', '-S', "127.0.0.1:$wPort", '-t', $sSite],
                  [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $wPort); $i++) {
    usleep(100000);
}
foreach (['schnee.php', 'schnee.php?km=800', 'schnee.php?km=800&ansicht=reise', 'schnee.php?km=800&ansicht=woche', 'schnee.php?sport=skitour&km=800&nach=km',
          "schnee.php?km=800&nach=$t1", 'schnee.php?sport=<script>&km=12', 'schnee_stunden.php?region=schnee-a', "schnee_stunden.php?region=schnee-b&sport=skitour&tag=$t0",
          "schnee.php?region=schnee-a&nach=$t1", 'schnee.php?region=schnee-a&nach=2020-01-01'] as $u) {
    [$code, $html] = holen($wPort, $u);
    pruefe($code === 200 && ohne_php_meldung($html), "$u: 200 ohne PHP-Meldung");
}
[, $html] = holen($wPort, 'schnee.php?km=800');
pruefe(str_contains($html, 'id="r-schnee-a"') && str_contains($html, 'class="tag s-go') && !preg_match('/class="tag (go|maybe|no)\b/', $html),
       'Kacheln in der Blau-Skala, nie Grün/Gelb/Rot');
pruefe(str_contains($html, 'class="lw lw3"') && str_contains($html, 'class="lw lw2"') && str_contains($html, 'Zuordnung über den Namen'), 'Lawinen-Abzeichen in EAWS-Farben, Hinweis auf Namenszuordnung');
pruefe(substr_count($html, 'ersetzt keine Beurteilung vor Ort') >= 3 && str_contains($html, '<p class="karte klein haftung">'), 'Haftungshinweis oben, je Region und im Fuß');
pruefe(preg_match('#id="r-schnee-d".*?Lawinenlage: <a href="https://example.org/bericht"#s', $html) === 1 && !preg_match('#id="r-schnee-d".*?class="lw lw#s', explode('id="r-schnee-a"', $html)[0] ?? ''),
       'nicht freigegebener Dienst: nur Link, keine Stufe');
pruefe(str_contains($html, '<nav class="modus" aria-label="Modus"><a href="./">Wind</a><a href="schnee.php" aria-current="page">Schnee</a>'), 'Schalter Wind · Schnee');
pruefe(str_contains($html, '<details class="karte landkarte" id="landkarte" open>') && str_contains($html, 'class="punkt s-go"'), 'Karte mit blauen Punkten');
pruefe(str_contains($html, 'class="teilen" hidden') && str_contains($html, 'Abfahrt bis'), 'gute Tage: Teilen und Abfahrt');
[, $html] = holen($wPort, "schnee.php?region=schnee-a&nach=$t1");
pruefe(str_contains($html, 'Geteilte Vorhersage') && !str_contains($html, 'id="r-schnee-b"') && !str_contains($html, 'id="wahl"'), 'geteilte Vorhersage: nur diese Region, ohne Auswahl');
[, $html] = holen($wPort, 'schnee_stunden.php?region=schnee-a&tag=' . $t1);
pruefe(str_contains($html, 'class="s-schnee"') && str_contains($html, 'class="s-sfg"') && str_contains($html, 'class="lw lw3"') && str_contains($html, 'Ensemble: Mitte'),
       'Stundenseite: Schneebalken, Schneefallgrenze, Lawinenlage, Streuung des Ensembles');
[$code] = holen($wPort, 'schnee_stunden.php?region=gibtsnicht');
pruefe($code === 404, 'Stundenseite: unbekannte Region 404');
[, $html] = holen($wPort, 'index.php?km=1000');
pruefe(str_contains($html, '<a href="./" aria-current="page">Wind</a><a href="schnee.php">Schnee</a>'), 'Wind-Seite: Schalter zum Schnee-Modus');
unlink("$sSite/lib/schneeregionen.json");
[$code] = holen($wPort, 'schnee.php');
pruefe($code === 503, 'ohne Schnee-Katalog: 503');
proc_terminate($sWeb);
