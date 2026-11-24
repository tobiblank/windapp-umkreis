<?php
/**
 * Tests Schnee-Modus (seit 8.11.2026), eingebunden am Ende von tests/test.php.
 * Nutzt pruefe(), holen(), ohne_php_meldung(), $ROOT, $tmp und $port von dort.
 */

require_once "$ROOT/server/web/lib/schnee.php";
require_once "$ROOT/server/web/lib/lawine.php";
require_once "$ROOT/server/web/lib/abruf.php";

echo "Schnee: Katalog\n";
$sk = schnee_katalog_laden("$ROOT/daten/schneeregionen.json");
pruefe(count($sk['regionen']) >= 8, 'echter Schnee-Katalog gültig, mindestens 8 Regionen');
$windIds = array_column(regionen_laden("$ROOT/daten/regionen.json"), 'id');
pruefe(!array_intersect($windIds, array_column($sk['regionen'], 'id')), 'ids von Wind- und Schnee-Katalog verschieden (gemeinsame fahrzeiten.json)');
$frei = fn(string $d) => $sk['lawinendienste'][$d]['freigegeben'] ?? null;
pruefe($frei('bayern') === ['DE-BY'] && in_array('AT-07', $frei('euregio'), true) && $frei('salzburg') === ['AT-05'] && $frei('kaernten') === ['AT-02']
       && $frei('lombardia') === ['IT-25'] && $frei('vorarlberg') === ['AT-08'] && $frei('steiermark') === [],
       'freigegeben: Bayern (10.11.2026), Tirol, Salzburg, Kärnten, Lombardei (Mails bis 16.11.2026), Vorarlberg (17.11.2026); Steiermark noch nicht');
pruefe(schnee_katalog_laden(__DIR__ . '/schneeregionen_test.json')['regionen'][0]['typ'] === 'schnee', 'Testkatalog gültig, typ schnee gesetzt');
$kaputt = json_decode(file_get_contents(__DIR__ . '/schneeregionen_test.json'), true);
$kaputt['lawinendienste']['slf']['freigegeben'] = ['AT-'];
$kaputt['regionen'][0]['hoehe_tal'] = 3000;
$kaputt['regionen'][1]['warndienst'] = 'gibtsnicht';
$kaputt['regionen'][2]['eaws_namen'] = [];
unset($kaputt['regionen'][2]['eaws']);
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
$real = lawine_lesen((string)file_get_contents(__DIR__ . '/lawine_bayern_2026-04-17.xml'), 'xml');
pruefe($real && count($real) === 2 && count($real[0]['regionen']) === 4 && count($real[0]['stufen']) === 3 && count($real[0]['probleme']) === 4 && lawine_max($real[0]) === 2
       && $real[0]['stufen'][0] === ['stufe' => 1, 'ab' => null, 'bis' => null, 'zeit' => 'earlier'] && $real[0]['stufen'][2]['ab'] === '1600'
       && $real[1]['stufen'][0]['ab'] === null && $real[0]['bis'] === '2026-04-17T15:00:00Z',
       'Bayern, Aufbau wie die echte Datei vom 17.4.2026: Tageszeiten, Höhe mit uom, leeres elevation');
pruefe(lawine_stufen_text($real[0]['stufen'], true) === 'vormittags 1 gering; nachmittags 2 mäßig über 1.600 m, 1 gering unter 1.600 m'
       && lawine_stufen_text($lj[0]['stufen'], true) === 'erheblich über 2.200 m, darunter Stufe 2 mäßig',
       'Stufen nach Tageszeit: Vormittag und Nachmittag beide genannt (Abzeichen zeigt die höhere)');
$ohneGrenze = [['stufe' => 2, 'ab' => null, 'bis' => null, 'zeit' => 'all_day'], ['stufe' => 1, 'ab' => null, 'bis' => null, 'zeit' => 'all_day']];
pruefe(lawine_mehrdeutig($ohneGrenze) && !lawine_mehrdeutig($real[0]['stufen']) && !lawine_mehrdeutig($lj[0]['stufen'])
       && lawine_stufen_text($ohneGrenze) === '2 mäßig, 1 gering (wo welche Stufe gilt, fehlt in den Daten; siehe Bericht)',
       'zwei Stufen ohne Höhengrenze (Trentino, 17.4.2026): Lücke wird genannt, nicht geraten');
pruefe(array_column(lawine_probleme_liste($real[0]['probleme']), 'text') === ['Altschnee (N, NO, NW über 2.200 m)', 'nachmittags: Nassschnee (alle Expositionen über 1.600 m)', 'nachmittags: Nassschnee (alle Expositionen unter 1.600 m)'],
       'Probleme: Altschnee vor- und nachmittags nur einmal, Nassschnee mit „nachmittags“');
$tzB = new DateTimeZone('Europe/Berlin');
$jetztB = (new DateTimeImmutable('2026-04-16 18:00', $tzB))->getTimestamp();
pruefe(lawine_gueltig_text($real[0], $tzB, $jetztB) === 'ausgegeben heute 17:00, gültig bis morgen 17:00' && lawine_zeit_text('', $tzB) === ''
       && lawine_zeit_text('2026-04-20T15:00:00Z', $tzB, $jetztB) === 'Mo 17:00', 'Ausgabe und Gültigkeit in Ortszeit');
pruefe(lawine_gueltig_text($real[0] + ['abgerufen' => '2026-04-20T15:31:00Z'], $tzB, $jetztB) === 'ausgegeben heute 17:00, gültig bis morgen 17:00, abgerufen Mo 17:31',
       'Abrufzeit beim Warndienst hinter Ausgabe und Gültigkeit');
$auszug = lawine_auszug_text(['regionen' => [['DE-BY-30', 'Werdenfelser Alpen'], ['DE-BY-12', 'Allgäuer Alpen Mitte']], 'ueber_namen' => false], ['name' => 'Lawinenwarndienst Bayern']);
pruefe(str_starts_with($auszug, 'Auszug aus dem Lagebericht (Quelle: Lawinenwarndienst Bayern)') && str_contains($auszug, 'gekürzt und aufbereitet')
       && str_contains($auszug, 'Werdenfelser Alpen, Allgäuer Alpen Mitte') && str_contains($auszug, 'höchsten Stufe'), 'Auszug gekennzeichnet, mehrere Teilgebiete benannt');
pruefe(str_starts_with(lawine_warnung(['stufe' => 4])['text'], 'Hinweis von Wohinfahrma:') && str_contains(LAWINE_HAFTUNG, 'weder den aktuellen Lagebericht'),
       'eigene Hinweise als solche benannt; Haftung nennt den aktuellen Lagebericht');
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
foreach (['schnee_vorhersage', 'schnee_ensemble', 'schnee_raster', 'lawine', 'vorhersage'] as $q) {
    pruefe(count(glob("$tmp/schnee/$q/" . gmdate('Y-m-d') . '_*.ndjson.gz')) === 1, "$q: eine gezippte Datei für den Lauf");
}
pruefe(str_contains($ausText, 'lawine/euregio: ') && str_contains($ausText, 'noch kein Bericht (404)') && !str_contains($ausText, 'lawine/gesperrt'),
       'Lagebericht: 404 für morgen ist kein Fehler; nicht freigegebener Dienst wird nie abgerufen');
$sStand = daten_laden("$tmp/schnee", ['schnee_vorhersage', 'schnee_ensemble']);
pruefe(($sStand['schnee_vorhersage']['schnee-a']['test_elevation'] ?? null) === '2400,2000,2200,2400', 'Vorhersage auf Tourhöhe angefragt (elevation je Ort)');
pruefe(count($sStand['schnee_vorhersage']['schnee-a']['hourly']['time']) === 24 * 11, '3 Tage zurück (past_days) und 8 voraus');
$rs = schnee_raster_laden("$tmp/schnee");
$rp = schnee_raster_punkte();
$west = $rs['zellen']['r47.25_10.00'] ?? null;
$ost = $rs['zellen']['r47.25_13.00'] ?? null;
$gestern = gmdate('Y-m-d', time() - 86400);
pruefe($rs && count($rs['zellen']) === count($rp) && count($rp) > 200 && count($rp) < 400 && $west && $ost
       && ($west['neu'][gmdate('Y-m-d')] ?? 0) > 10 && ($ost['neu'][gmdate('Y-m-d')] ?? -1) == 0 && !isset($west['hourly']) && !isset($west['neu'][$gestern]),
       'Neuschnee-Raster: alle Punkte, nur Tageswerte gespeichert, Schnee nur unter der Schneefallgrenze');
pruefe(($west['hs'][gmdate('Y-m-d')] ?? null) === 80 && ($ost['hs'][gmdate('Y-m-d')] ?? null) === 10,
       'Raster: Schneehöhe im Modell je Tag in cm gespeichert (seit 18.11.2026)');
$rd = "$tmp/relief";
@mkdir($rd);
pruefe(relief_svg([0, 0, 560, 614], $rd) === '', 'Relief: ohne relief.json nichts');
file_put_contents("$rd/relief.json", json_encode(['datei' => 'relief.webp', 'z' => 8, 'x' => [131, 140], 'y' => [88, 93]]));
file_put_contents("$rd/relief.webp", 'x');
$rel = relief_svg([0, 0, 560, 614], $rd);
$ecke = karte_punkt(rad2deg(atan(sinh(M_PI * (1 - 2 * 88 / 256)))), 131 / 256 * 360 - 180);
pruefe(preg_match('#<image class="relief" href="relief\.webp\?v=\d+" x="([\d.]+)" y="([\d.]+)" width="([\d.]+)"#', $rel, $mr) === 1
       && abs((float)$mr[1] - $ecke[0]) < 0.01 && abs((float)$mr[2] - $ecke[1]) < 0.01 && abs((float)$mr[3] - 10 / 256 * 360 * KARTE_K) < 0.01
       && relief_svg([400, 0, 50, 50], $rd) === '', 'Relief: Ecken aus den Kacheln, außerhalb des Ausschnitts nichts');
pruefe(schnee_im_umriss(47.27, 11.39) && schnee_im_umriss(46.5, 9.84) && !schnee_im_umriss(48.14, 11.58) && !schnee_im_umriss(45.46, 9.19),
       'Alpenumriss: Innsbruck und Engadin drin, München und Mailand nicht');
$lagen = lawine_laden("$tmp/schnee");
$euIds = array_merge(...array_map(fn($b) => array_column($b['regionen'], 0), $lagen['euregio']['bulletins'] ?? []));
pruefe($euIds === ['IT-32-BZ-08-01'], 'Euregio: nur Südtirol gespeichert, Tirol nicht');
$mess = stationen_laden("$tmp/schnee");
pruefe(array_keys($mess['tirol']['stationen'] ?? []) === ['sta-gut', 'sta-fehler', 'sta-sprung'] && str_contains($ausText, 'stationen/tirol: ok, 3 von 4 Stationen') && str_contains($ausText, 'fehlen: sta-fehlt'),
       'Stationen: nur die aus dem Katalog gespeichert, fehlende im Bericht, kein Fehler');
$sKatA = schnee_katalog_laden(__DIR__ . '/schneeregionen_test.json')['regionen'][0];
$werte = stationen_region($sKatA, $mess);
pruefe(count($werte) === 2 && $werte[0]['wert']['name'] === 'Gutalm' && $werte[0]['wert']['hs'] == 42.3 && $werte[0]['wert']['hsd24'] == 5.2 && $werte[0]['wert']['hoehe'] === 2450
       && $werte[1]['wert']['name'] === 'Sprungjoch' && $werte[1]['wert']['hsd24'] === null,
       'Stationen: negative Schneehöhe fällt weg, unplausible Änderung in 24 h wird leer');
$geo = $mess['geosphere']['stationen'] ?? [];
pruefe(array_map('strval', array_keys($geo)) === ['111'] && $geo['111']['hs'] == 42.0 && abs($geo['111']['hsd24'] - 12.0) < 0.2 && $geo['111']['name'] === 'Hundertelf' && $geo['111']['hoehe'] === 2100
       && str_contains($ausText, 'stationen/geosphere: ok, 1 von 2 Stationen mit Schneehöhe') && str_contains($ausText, 'ohne Wert: 222'),
       'GeoSphere: letzter vorhandener Wert (letzter leer), Änderung gegen 24 h vorher, Station ohne Werte fehlt ohne Fehler');
$chm = $mess['meteoswiss']['stationen'] ?? [];
pruefe(array_keys($chm) === ['xaa'] && $chm['xaa']['hs'] == 55.0 && $chm['xaa']['hsd24'] == 5.0 && str_contains($ausText, 'stationen/meteoswiss: 1 von 2 Stationen mit Schneehöhe') && str_contains($ausText, 'ohne Wert: xbb'),
       'MeteoSwiss: CSV je Station, htoauts0, Station ohne Spalte fehlt ohne Fehler');
$bzm = $mess['suedtirol']['stationen'] ?? [];
pruefe(array_keys($bzm) === ['bz1', 'bz2'] && $bzm['bz1']['hs'] == 26.0 && abs($bzm['bz1']['hsd24'] - 6.0) < 0.2 && $bzm['bz2']['hs'] == 0.0 && $bzm['bz1']['name'] === 'Bezett eins'
       && str_contains($ausText, 'stationen/suedtirol: 2 von 3 Stationen mit Schneehöhe') && str_contains($ausText, 'ohne Wert: bz3'),
       'Südtirol: Zeitreihe je Station (Ortszeit mit Kürzel), leicht negativ = 0 cm, Station ohne Werte fehlt ohne Fehler');
pruefe(!isset($mess['slf-imis']) && !str_contains($ausText, 'stationen/slf-imis'), 'SLF IMIS: nicht von der Action abgerufen (nur Server, ein- bis zweimal am Tag)');
$sgm = $mess['snowgrid']['stationen'] ?? [];
pruefe(array_keys($sgm) === ['d-tour'] && $sgm['d-tour']['hs'] == 18.0 && $sgm['d-tour']['hsd24'] == 3.0 && !empty($sgm['d-tour']['modell'])
       && str_contains($ausText, 'stationen/snowgrid: ok, 1 von 2 Punkten mit Wert') && str_contains($ausText, 'ohne Wert: d-mitte'),
       'SNOWGRID: ein Abruf für alle Punkte, m in cm, Änderung zum Vortag, Punkt ohne Wert fehlt');
$sgw = stationen_wert($sgm['d-tour'], time() + 50 * 3600);
pruefe($sgw && $sgw['modell'] && !$sgw['alt'] && stationen_wert($sgm['d-tour'], time() + 4 * 86400) === null, 'SNOWGRID: Tageswert gilt länger als ein Messwert, ohne Zeitangabe');
pruefe(snowgrid_lesen('{"timestamps":[],"features":[{}]}', ['a', 'b'], []) === null && suedtirol_lesen('{"x":1}', 'a', []) === null, 'SNOWGRID/Südtirol: falsches Format wird erkannt');
pruefe(stationen_aus_reihe([1000 => 5.0, 1000 + 86400 => 8.0]) === [8.0, 3.0, gmdate('Y-m-d\TH:i:s\Z', 1000 + 86400)] && stationen_aus_reihe([5 => null]) === null
       && stationen_aus_reihe([0 => 5.0, 7200 => 6.0])[1] === null, 'Zeitreihe: Änderung nur mit Wert rund 24 h vorher');
pruefe(stationen_wert($mess['tirol']['stationen']['sta-gut'], time() + 4 * 86400) === null && stationen_wert($mess['tirol']['stationen']['sta-gut'], time() + 13 * 3600)['alt'],
       'Stationen: nach 12 h mit Zeit, nach 72 h nicht mehr angezeigt');
pruefe(stationen_pruefen(['messnetze' => ['x' => ['name' => 'X']], 'regionen' => [['id' => 'r', 'stationen' => ['y' => []]]]]) !== [], 'Katalog: fehlerhafte Messnetze und Stationen werden gemeldet');
pruefe(webcams_pruefen(['regionen' => [['id' => 'r']], 'webcams' => ['stationen' => ['ohnenetz' => ['name' => 'X', 'url' => 'http://x']], 'regionen' => ['gibtsnicht' => ['name' => 'Y', 'url' => 'https://y', 'km' => -1]]]]) !== []
       && webcams_pruefen(['regionen' => [['id' => 'r']], 'webcams' => ['regionen' => ['r' => ['name' => 'Y', 'url' => 'https://y']]]]) === [],
       'Katalog: fehlerhafte Webcams werden gemeldet, Webcam ohne km ist erlaubt');
exec($sCmd("$tmp/schnee_nur", '--nur lawine'), $ausN, $rcN);
pruefe($rcN === 0 && is_dir("$tmp/schnee_nur/lawine") && !is_dir("$tmp/schnee_nur/vorhersage") && !is_dir("$tmp/schnee_nur/schnee_ensemble"), '--nur lawine: nur der Lagebericht');

echo "Schnee: Abruf auf dem Server (lawine_holen.php)\n";
$srv = "$tmp/schnee_server";
$sKatS = schnee_katalog_laden(__DIR__ . '/schneeregionen_test.json');
$erg = server_abruf($srv, $sKatS, "http://127.0.0.1:$sPort");
pruefe($erg['lawine'] === 'ok' && $erg['stationen'] === 'ok' && is_file("$srv/lawine/server.ndjson") && is_file("$srv/stationen/server.ndjson")
       && !glob("$srv/lawine/*.gz") && ($GLOBALS['umkreis_abruf_wiederholungen'] ?? null) === 1,
       'Server-Abruf: Lagebericht und Stationen in je eine kleine Datei' . ($erg['lawine'] !== 'ok' || $erg['stationen'] !== 'ok' ? ': ' . json_encode($erg) : ''));
unset($GLOBALS['umkreis_abruf_wiederholungen'], $GLOBALS['umkreis_abruf_warte_fehler']);
$imS = stationen_laden($srv)['slf-imis']['stationen'] ?? [];
pruefe(array_keys($imS) === ['im1'] && $imS['im1']['hs'] == 36.0 && $imS['im1']['hsd24'] == 5.0 && $imS['im1']['hn24'] == 6.4 && !empty($imS['im1']['tageswert'])
       && $imS['im1']['zeit'] === gmdate('Y-m-d\T00:00:00\Z') && preg_match('#stationen/slf-imis: ok, 1 von 2 Stationen mit Schneehöhe, .* \(ohne Wert: im2\)#u', implode("\n", $erg['bericht'])) === 1,
       'SLF IMIS: Tageswert 0 UTC, Änderung zum Vortag, HN_1D, nur Stationen aus dem Katalog');
$gReg = ['id' => 'gardasee-nord', 'fremdprognose' => ['format' => 'garda', 'abruf' => 'https://kitepredictionlagodigarda.onrender.com/forecast']];
$ergG = server_abruf("$tmp/schnee_garda", $sKatS, "http://127.0.0.1:$sPort", null, [$gReg, ['id' => 'ohne']]);
$ergG2 = server_abruf("$tmp/schnee_garda", $sKatS, "http://127.0.0.1:$sPort", time() + 30 * 60, [$gReg]);
unset($GLOBALS['umkreis_abruf_wiederholungen'], $GLOBALS['umkreis_abruf_warte_fehler']);
$gDatei = json_decode((string)@file_get_contents("$tmp/schnee_garda/fremd/gardasee-nord.json"), true);
pruefe(($ergG['fremd/gardasee-nord'] ?? '') === 'ok' && ($ergG2['fremd/gardasee-nord'] ?? '') === 'zu frueh' && !isset($ergG['fremd/ohne'])
       && fremdprognose_garda($gDatei['payload'] ?? []) !== null,
       'Server-Abruf: Gardasee-Vorhersage geholt und abgelegt, danach erst nach dem nächsten Rechenlauf' . (($ergG['fremd/gardasee-nord'] ?? '') !== 'ok' ? ': ' . json_encode($ergG) : ''));
$tag = gmmktime(0, 0, 0, 11, 23, 2026);
$imisNetz = ['abruf_stunden_utc' => [6, 16]];
pruefe(stationen_netz_faellig($imisNetz, null, null, $tag + 3600) && !stationen_netz_faellig($imisNetz, $tag - 4 * 3600, null, $tag + 5 * 3600)
       && stationen_netz_faellig($imisNetz, $tag - 4 * 3600, null, $tag + 6 * 3600) && !stationen_netz_faellig($imisNetz, $tag + 6 * 3600, null, $tag + 15 * 3600)
       && stationen_netz_faellig($imisNetz, $tag + 6 * 3600, null, $tag + 16 * 3600 + 60) && !stationen_netz_faellig($imisNetz, $tag + 6 * 3600, $tag + 16 * 3600 + 60, $tag + 17 * 3600)
       && stationen_netz_faellig($imisNetz, $tag + 6 * 3600, $tag + 16 * 3600 + 60, $tag + 19 * 3600) && stationen_netz_faellig([], 1, 1, 2),
       'SLF IMIS: je Zeitfenster (6 und 16 UTC) ein guter Abruf, nach Fehlversuch frühestens 2 h später');
pruefe(slf_imis_lesen('{"a":1}', ['x'], []) === null && slf_imis_lesen('[{"x":1}]', ['x'], []) === null && slf_imis_lesen('[]', ['x'], []) === [],
       'SLF IMIS: falsches Format wird erkannt');
$imW = stationen_wert($imS['im1'], gmmktime(20, 0, 0) );
$imW2 = stationen_wert($imS['im1'], intdiv(time(), 86400) * 86400 + 40 * 3600);
pruefe($imW && !$imW['alt'] && $imW['tageswert'] && $imW['hn24'] == 6.4 && $imW2 && $imW2['alt'], 'SLF IMIS: Tageswert erst nach 36 h mit Datum');
@mkdir("$tmp/schnee/stationen", 0700, true);
copy("$srv/stationen/server.ndjson", "$tmp/schnee/stationen/server.ndjson");   // fuer die Seitentests: wie auf dem Server
$erg2 = server_abruf($srv, $sKatS, "http://127.0.0.1:$sPort");
$erg3 = server_abruf($srv, $sKatS, "http://127.0.0.1:$sPort", time() + 25 * 60);
pruefe($erg2['lawine'] === 'zu frueh' && $erg2['stationen'] === 'zu frueh' && $erg3['lawine'] === 'ok' && $erg3['stationen'] === 'zu frueh',
       'Server-Abruf: Mindestabstand 20 min (Lagebericht) und 50 min (Stationen)');
$erg55 = server_abruf($srv, $sKatS, "http://127.0.0.1:$sPort", time() + 55 * 60);
unset($GLOBALS['umkreis_abruf_wiederholungen'], $GLOBALS['umkreis_abruf_warte_fehler']);
pruefe($erg55['stationen'] === 'ok' && !str_contains(implode("\n", $erg55['bericht']), 'stationen/slf-imis') && isset(stationen_laden($srv)['slf-imis']),
       'SLF IMIS: beim nächsten Stationsabruf nicht noch einmal, alter Stand bleibt');
$lagenS = lawine_laden($srv);
$messS = stationen_laden($srv);
pruefe(($lagenS['euregio']['alt_stunden'] ?? null) === LAWINE_ALT_STUNDEN_SERVER && ($lagen['euregio']['alt_stunden'] ?? null) === LAWINE_ALT_STUNDEN
       && array_keys($messS['tirol']['stationen'] ?? []) === ['sta-gut', 'sta-fehler', 'sta-sprung'],
       'Server-Abruf: lesbar wie die Action-Dateien, kurze Frist für "nicht aktualisiert" nur mit Server-Abruf');
// Neueste Zeile je Dienst gewinnt, egal aus welcher Datei (Action-Lauf oder Server)
$mix = "$tmp/schnee_mix";
@mkdir("$mix/lawine", 0700, true);
$zl = fn(string $ts, string $id, bool $ok = true) => ['ts' => $ts, 'source' => 'lawine', 'ok' => $ok, 'dienst' => 'x', 'regionen' => ['x'],
                                                     'tage' => $ok ? [gmdate('Y-m-d') => [['id' => $id, 'von' => '', 'veroeffentlicht' => '']]] : []];
$tsAlt = gmdate('Y-m-d\TH:i:s\Z', time() - 3 * 3600);
$tsNeu = gmdate('Y-m-d\TH:i:s\Z', time() - 600);
file_put_contents("$mix/lawine/" . gmdate('Y-m-d') . '_2359.ndjson.gz', gzencode(json_encode($zl($tsAlt, 'action')) . "\n"));
server_zeilen_merken($mix, 'lawine', 'dienst', [$zl($tsNeu, 'server')]);
$lm = lawine_laden($mix);
pruefe(($lm['x']['bulletins'][0]['id'] ?? '') === 'server' && $lm['x']['ts'] === $tsNeu && lawine_stoerung($lm['x']) === null,
       'neuester Bericht gewinnt: Server-Zeile neuer als der Action-Lauf mit späterem Dateinamen');
server_zeilen_merken($mix, 'lawine', 'dienst', [$zl(gmdate('Y-m-d\TH:i:s\Z'), '', false)]);
$lm = lawine_laden($mix);
pruefe(($lm['x']['bulletins'][0]['id'] ?? '') === 'server' && $lm['x']['versuch_ok'] === false && substr_count((string)file_get_contents("$mix/lawine/server.ndjson"), "\n") === 2
       && str_contains(lawine_stoerung($lm['x'])['text'], 'fehlgeschlagen'),
       'Server-Fehlversuch: letzte gute Zeile bleibt, Hinweis "letzter Abruf fehlgeschlagen"');
server_zeilen_merken($mix, 'lawine', 'dienst', [$zl($tsAlt, 'server')]);
$lm = lawine_laden($mix);
pruefe(str_contains(lawine_stoerung($lm['x'])['text'] ?? '', 'länger nicht aktualisiert') && lawine_stoerung(['ts' => $tsAlt, 'bulletins' => []]) === null,
       'mit Server-Abruf: Hinweis schon nach 2 h, ohne erst nach 13 h');

echo "Schnee: Bewertung\n";
$sKat = schnee_katalog_laden(__DIR__ . '/schneeregionen_test.json');
$sTage = [];
for ($i = 0; $i < 4; $i++) {
    $sTage[] = (new DateTimeImmutable("+$i day", new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
}
$fruehmorgens = new DateTimeImmutable('today 05:00', new DateTimeZone('Europe/Berlin'));
$sRl = schnee_rangliste($sKat['regionen'], $sStand, [48.07, 11.15], 1000, $sTage, $fruehmorgens);
$sBy = [];
foreach ($sRl as $e) {
    $sBy[$e['region']['id']] = $e;
}
[$t0, $t1, $t2, $t3] = $sTage;
$a1 = $sBy['schnee-a']['tage'][$t1] ?? null;
pruefe($a1 && $a1['det']['neu72'] > 22 && $a1['det']['neu72'] < 32 && $a1['det']['geht'],
       'Region A morgen: rund 25 cm Neuschnee in 72 h, sonnig, Pulvertag (' . ($a1['det']['neu72'] ?? '?') . ' cm)');
pruefe(abs($a1['det']['pulver'] - $a1['det']['neu72']) < 0.01 && $sBy['schnee-a']['pulver_max'] >= $a1['det']['pulver'],
       'Pulver = Neuschnee 72 h am Pulvertag, Region nach dem besten Tag');
$aT = $sBy['schnee-a']['tage'];
$summe24 = ($aT[$t1]['det']['neu24'] ?? 0) + ($aT[$t2]['det']['neu24'] ?? 0) + ($aT[$t3]['det']['neu24'] ?? 0);
pruefe(($aT[$t3]['det']['neu72'] ?? null) !== null && abs($summe24 - $aT[$t3]['det']['neu72']) < 0.2,
       'Neuschnee je Tag (24 h bis 8 Uhr) addiert sich zu den 72 h (' . $summe24 . ' / ' . ($aT[$t3]['det']['neu72'] ?? '?') . ' cm)');
pruefe(($aT[$t0]['det']['neu24'] ?? 0) > 10 && ($aT[$t1]['det']['neu24'] ?? 1) < 1,
       'Region A: Schnee von gestern ab 8 Uhr steht heute (' . ($aT[$t0]['det']['neu24'] ?? '?') . ' cm), morgen nichts Neues, die 72 h zählen ihn weiter');
$sp = $aT[$t1]['ens'] ?? null;
pruefe($sp && $sp['neu24_p10'] !== null && $sp['neu24_p10'] <= $sp['neu24_p50'] && $sp['neu24_p50'] <= $sp['neu24_p90'], 'Ensemble: Spanne des Neuschnees (10./50./90. Perzentil)');
pruefe(schnee_klasse($aT[$t1]) === 's-go' && schnee_klasse(['det' => ['geht' => false, 'neu24' => 12.0]]) === 's-maybe'
       && schnee_klasse(['det' => ['geht' => false, 'neu24' => 4.0]]) === 's-no' && schnee_klasse(['det' => ['geht' => false, 'neu24' => 0.0]]) === 'nix',
       'Blau-Skala: passt, sonst nach Neuschnee');
$kw = schnee_karte_wert($sBy['schnee-a'], null);
pruefe($kw['geht'] && $kw['klasse'] === 's-go' && abs($kw['neu'] - array_sum(array_map(fn($d) => (float)($d['det']['neu24'] ?? 0), array_filter($aT, fn($d) => !$d['vorbei'] && !$d['offen'])))) < 0.2,
       'Karte: Summe des Neuschnees im Zeitraum, kräftiges Blau, wenn ein Tag passt');
// Temperatur (seit 13.11.2026): tiefste der Nacht (Vortag ab 18 Uhr bis 8 Uhr), hoechste im Fenster
$tSt = [];
foreach (['2026-03-09' => range(0, 23), '2026-03-10' => range(0, 23)] as $d => $hs) {
    foreach ($hs as $h) {
        $tSt[$d][$h] = ['neu' => 0.0, 'temp' => $d === '2026-03-09' ? ($h === 12 ? -20.0 : ($h === 22 ? -6.0 : 2.0)) : ($h === 5 ? -8.0 : ($h === 13 ? 7.5 : ($h === 20 ? 12.0 : 1.0)))];
    }
}
$tk = schnee_kennwerte($tSt, '2026-03-10', SCHNEE_TAG);
pruefe($tk['temp_nacht'] === -8.0 && $tk['temp_tag'] === 7.5 && schnee_temp_text($tk) === "\u{2212}8 / 8°",
       'Temperatur: Nacht −8 (Vortagsmittag und Abend nach dem Fenster zählen nicht), Tag 7,5 → „−8 / 8°“');
pruefe(schnee_grad(-0.4) === '0°' && schnee_grad(-0.6) === "\u{2212}1°" && schnee_grad(null) === '?' && schnee_temp_text(['temp_nacht' => null, 'temp_tag' => null]) === null,
       'Temperatur: kein „−0“, ohne Werte nichts');
// Firn (seit 13.11.2026): nur Maerz bis Juni
$fk = ['temp_nacht' => -5.0, 'temp_tag' => 4.0, 'wolken' => 10.0, 'neu72' => 2.0];
pruefe((schnee_firn($fk, '2026-04-10')['art'] ?? null) === 'firn' && str_contains(schnee_firn($fk, '2026-04-10')['text'], "\u{2212}5°") && schnee_firn($fk, '2026-01-10') === null,
       'Firn: nachts gefroren, am Tag über 0, sonnig, kein Pulver; im Januar kein Hinweis');
pruefe(schnee_firn(['temp_tag' => -1.0] + $fk, '2026-04-10') === null && schnee_firn(['neu72' => 15.0] + $fk, '2026-04-10') === null
       && schnee_firn(['wolken' => 90.0] + $fk, '2026-04-10') === null && schnee_firn(['temp_nacht' => -0.5] + $fk, '2026-04-10') === null,
       'Firn: nicht bei Frost am Tag, frischem Pulver, Wolken oder kaum Frost in der Nacht');
pruefe((schnee_firn(['temp_nacht' => 2.0] + $fk, '2026-05-02')['kurz'] ?? null) === 'kein Frost' && schnee_firn(['temp_nacht' => null] + $fk, '2026-05-02') === null,
       'Nacht über 0: „kein Frost“, ohne Werte nichts');
pruefe(array_key_exists('firn', $aT[$t1]['det']), 'Bewertung trägt den Firn-Hinweis je Tag');
pruefe(($aT[$t1]['det']['temp_nacht'] ?? null) == -2.0 && ($aT[$t1]['det']['temp_tag'] ?? null) == -2.0, 'Region A: Temperatur aus dem Modellmittel');
pruefe(($sBy['schnee-a']['tage'][$t3]['det']['grund'] ?? '') === 'zu wenig Neuschnee', 'Region A in drei Tagen: zu wenig Neuschnee');
pruefe(($sBy['schnee-b']['tage'][$t1]['det']['neu72'] ?? null) == 0.0 && $sBy['schnee-b']['tage'][$t1]['det']['grenze'] == 2800, 'Region B: Regen über der Tourhöhe zählt nicht');
pruefe(array_key_first($sBy) === 'schnee-a' || array_key_first($sBy) === 'schnee-d', 'Rangliste: Regionen mit Neuschnee vorn');
pruefe(($sBy['schnee-a']['tage'][$t0]['vorbei'] ?? true) === false && $sBy['schnee-a']['tage'][$t0]['fenster'] === SCHNEE_TAG, 'heute früh: Tag nicht vorbei, volles Tourenfenster');
$spaet = schnee_region_bewerten($sKat['regionen'][0], ['vorhersage' => $sStand['schnee_vorhersage']['schnee-a'], 'ensemble' => null], [$t0],
                                new DateTimeImmutable('today 14:00', new DateTimeZone('Europe/Vienna')))[$t0];
pruefe($spaet['vorbei'] && !$spaet['det']['geht'] && $spaet['det']['pulver'] === 0.0, 'heute nach 13:30: vorbei, kein Pulver');
pruefe(schnee_tag_geht(['neu72' => 30, 'hs' => 5, 'wolken' => 10, 'kamm' => 90]) === [true, null] && schnee_kamm_stark(['kamm' => 90.0]) && !schnee_kamm_stark(['kamm' => 20.0]),
       'Wind am Kamm und wenig Schneehöhe schließen nichts aus, starker Wind nur als Hinweis');
pruefe(schnee_tag_geht(['neu72' => 30, 'hs' => 50, 'wolken' => 75, 'kamm' => 10])[1] === 'zu bewölkt'
       && schnee_tag_geht(['neu72' => 30, 'hs' => 50, 'wolken' => SCHNEE_WOLKIG, 'kamm' => 10])[0], 'schöner Tag = höchstens teils sonnig wie das Symbol');
$kr = schnee_kriterien(['neu72' => 7.0, 'hs' => 7.0, 'wolken' => 100.0, 'kamm' => null]);
$kr2 = schnee_kriterien(['neu72' => 13.0, 'hs' => 7.0, 'wolken' => null, 'kamm' => null]);
pruefe(count($kr) === 2 && $kr[0]['ok'] === false && $kr[0]['wert'] === '7 cm' && $kr[1]['ok'] === false && $kr2[0]['ok'] === true && $kr2[1]['ok'] === null
       && array_column(array_filter($kr, fn($c) => $c['ok'] === false), 'grund') === ['zu wenig Neuschnee', 'zu bewölkt'],
       'Regeln einzeln: alle Gründe, unbekannt bleibt offen');
$sortiert = schnee_sortieren([
    ['region' => ['id' => 'nebel'], 'km' => 50, 'pulver_max' => 0.0, 'neu_summe' => 40.0],
    ['region' => ['id' => 'wenig'], 'km' => 50, 'pulver_max' => 12.0, 'neu_summe' => 12.0],
    ['region' => ['id' => 'viel'], 'km' => 300, 'pulver_max' => 30.0, 'neu_summe' => 30.0],
], 'summe');
pruefe(array_column(array_column($sortiert, 'region'), 'id') === ['viel', 'wenig', 'nebel'], 'Rangliste: 30 cm bei Sonne vor 12 cm bei Sonne vor 40 cm im Nebel');
$prioListe = [
    ['region' => ['id' => 'nebel40'], 'km' => 50, 'pulver_max' => 0.0, 'neu_summe' => 40.0, 'schoene_tage' => 0,
     'tage' => ['T' => ['det' => ['pulver' => 0.0, 'neu24' => 40.0, 'neu72' => 40.0, 'wolken' => 95.0]]], 'fahrt' => ['min' => 200]],
    ['region' => ['id' => 'sonne28'], 'km' => 60, 'pulver_max' => 28.0, 'neu_summe' => 28.0, 'schoene_tage' => 2,
     'tage' => ['T' => ['det' => ['pulver' => 28.0, 'neu24' => 28.0, 'neu72' => 28.0, 'wolken' => 10.0]]], 'fahrt' => ['min' => 150]],
    ['region' => ['id' => 'sonne18'], 'km' => 70, 'pulver_max' => 18.0, 'neu_summe' => 18.0, 'schoene_tage' => 2,
     'tage' => ['T' => ['det' => ['pulver' => 18.0, 'neu24' => 18.0, 'neu72' => 18.0, 'wolken' => 5.0]]], 'fahrt' => ['min' => 90]],
    ['region' => ['id' => 'nebel25'], 'km' => 80, 'pulver_max' => 0.0, 'neu_summe' => 25.0, 'schoene_tage' => 0,
     'tage' => ['T' => ['det' => ['pulver' => 0.0, 'neu24' => 25.0, 'neu72' => 25.0, 'wolken' => 90.0]]], 'fahrt' => ['min' => 60]],
];
$ids = fn($l) => array_column(array_column($l, 'region'), 'id');
pruefe($ids(schnee_sortieren($prioListe, 'summe')) === ['sonne28', 'sonne18', 'nebel40', 'nebel25'], 'Priorität Pulver + Sonne (Standard): Pulvertage zuerst');
pruefe($ids(schnee_sortieren($prioListe, 'summe', 'neu')) === ['nebel40', 'sonne28', 'sonne18', 'nebel25'], 'Priorität Neuschnee: Stufe ab 30 cm zuerst, in der Stufe 15–30 cm die Sonne vor mehr cm');
pruefe($ids(schnee_sortieren($prioListe, 'T', 'neu')) === ['nebel40', 'sonne28', 'sonne18', 'nebel25'], 'Priorität Neuschnee an einem Tag: Stufe, dann Sonne (wie das Symbol), dann cm');
pruefe($ids(schnee_sortieren($prioListe, 'summe', 'km')) === ['nebel25', 'sonne18', 'sonne28', 'nebel40'] && $ids(schnee_sortieren($prioListe, 'km')) === $ids(schnee_sortieren($prioListe, 'summe', 'km')),
       'Priorität Fahrzeit, alter Parameter nach=km gleichwertig');
pruefe(schnee_neu_stufe(4.9) === 0 && schnee_neu_stufe(5.0) === 1 && schnee_neu_stufe(15.0) === 2 && schnee_neu_stufe(30.0) === 3 && schnee_neu_stufe(null) === 0, 'Neuschnee-Stufen ab 5, 15, 30 cm');
pruefe(lawine_warnung(null) === null && lawine_warnung(['stufe' => 2]) === null && lawine_warnung(['stufe' => 3])['klasse'] === 'warn-erheblich'
       && str_contains(lawine_warnung(['stufe' => 3])['text'], 'meisten tödlichen') && lawine_warnung(['stufe' => 4])['klasse'] === 'warn-hoch'
       && lawine_warnung(['stufe' => 5])['klasse'] === 'warn-hoch', 'Lawinen-Warnzeichen: ab Stufe 3, ab Stufe 4 hoch');
pruefe(schneefarbe(0) === 'rgb(128, 146, 156)' && schneefarbe(10) === 'rgb(52, 120, 222)' && schneefarbe(500) === schneefarbe(80)
       && schneefarbe_hell(1.0) && !schneefarbe_hell(20.0), 'Neuschnee-Farbskala');

echo "Schnee: Lawinenlage je Region\n";
$sR = [];
foreach ($sKat['regionen'] as $r) {
    $sR[$r['id']] = $r;
}
$la = lawine_lage($sR['schnee-a'], $lagen, $t1);
pruefe($la && $la['stufe'] === 3 && $la['ueber_namen'] && $la['regionen'] === [['CH-7111', 'Oberengadin']], 'A: SLF über den Namen „Oberengadin“, Stufe 3 morgen');
$lb = lawine_lage($sR['schnee-b'], $lagen, $t0);
pruefe($lb && $lb['stufe'] === 2 && ($lb['probleme'][0]['aspekte'] ?? []) === ['N', 'NW'] && !$lb['ueber_namen'], 'B: Bayern (XML) über DE-BY-30, Stufe 2, Triebschnee N und NW');
pruefe(lawine_lage($sR['schnee-c'], $lagen, $t0)['stufe'] === 4, 'C: Südtirol über die Mikroregion IT-32-BZ-08, Stufe 4 (freigegeben, für das Warnzeichen)');
pruefe(lawine_lage($sR['schnee-a'], $lagen, $t3) === null, 'ohne gültigen Bericht (in drei Tagen): keine Lage, keine Hochrechnung');
$tzR = new DateTimeZone($sR['schnee-b']['zeitzone']);
$um = fn(string $uhr) => (new DateTimeImmutable("2026-01-15 $uhr", $tzR))->getTimestamp();
$bAlt = ['id' => 'alt', 'veroeffentlicht' => '2026-01-14T16:00:00Z', 'von' => '2026-01-14T16:00:00Z', 'bis' => '2026-01-15T16:00:00Z', 'regionen' => [['DE-BY-30', 'Werdenfels']],
         'stufen' => [['stufe' => 3, 'ab' => null, 'bis' => null, 'zeit' => 'all_day']], 'probleme' => []];
$bNeu = ['id' => 'neu', 'veroeffentlicht' => '2026-01-15T16:00:00Z', 'von' => '2026-01-15T16:00:00Z', 'bis' => '2026-01-16T16:00:00Z'] + $bAlt;
$bNeu['stufen'][0]['stufe'] = 1;
$nurAlt = [$sR['schnee-b']['warndienst'] => ['ts' => 'x', 'bulletins' => [$bAlt]]];
$beide = [$sR['schnee-b']['warndienst'] => ['ts' => 'x', 'bulletins' => [$bAlt, $bNeu]]];
pruefe(lawine_lage($sR['schnee-b'], $nurAlt, '2026-01-15', $um('10:00'))['stufe'] === 3 && lawine_lage($sR['schnee-b'], $nurAlt, '2026-01-15', $um('18:00')) === null
       && lawine_lage($sR['schnee-b'], $beide, '2026-01-15', $um('18:00'))['stufe'] === 1 && lawine_lage($sR['schnee-b'], $nurAlt, '2026-01-15', $um('10:00') + 2 * 86400) !== null,
       'heute nach 17 Uhr: abgelaufener Bericht gilt nicht mehr, der neue schon; andere Tage weiter um 12 Uhr');
$jetzt0 = time();
pruefe(lawine_stoerung(null)['stand'] === '' && lawine_stoerung(['ts' => gmdate('Y-m-d\TH:i:s\Z', $jetzt0 - 3600), 'bulletins' => [], 'versuch_ok' => true], $jetzt0) === null
       && str_contains(lawine_stoerung(['ts' => gmdate('Y-m-d\TH:i:s\Z', $jetzt0 - 3600), 'bulletins' => [], 'versuch_ok' => false], $jetzt0)['text'], 'fehlgeschlagen')
       && str_contains(lawine_stoerung(['ts' => gmdate('Y-m-d\TH:i:s\Z', $jetzt0 - 20 * 3600), 'bulletins' => []], $jetzt0)['text'], 'länger nicht aktualisiert')
       && lawine_stoerung(['ts' => '', 'bulletins' => [], 'versuch_ok' => false], $jetzt0)['stand'] === '',
       'Störung: keine Daten, letzter Versuch fehlgeschlagen, zu alt; frisch und gut ohne Hinweis');
pruefe(!lawine_freigegeben($sR['schnee-d'], $sKat['lawinendienste']) && lawine_freigegeben($sR['schnee-b'], $sKat['lawinendienste']), 'Freigabe je Warndienst');
$skId = fn(string $id) => array_values(array_filter($sk['regionen'], fn($r) => $r['id'] === $id))[0];
pruefe(lawine_freigegeben($skId('werdenfels'), $sk['lawinendienste']) && lawine_freigegeben($skId('oberengadin'), $sk['lawinendienste'])
       && lawine_freigegeben($skId('stubai'), $sk['lawinendienste']) && lawine_freigegeben($skId('gastein-rauris'), $sk['lawinendienste'])
       && lawine_freigegeben($skId('kleinwalsertal'), $sk['lawinendienste']) && !lawine_freigegeben($skId('soelktaeler'), $sk['lawinendienste']),
       'echter Katalog: Werdenfels, Oberengadin, Stubai, Gastein und Kleinwalsertal mit Stufe, Sölktäler (Steiermark) nur Link');

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
foreach (['schnee.php', 'schnee.php?km=800', 'schnee.php?km=800&ansicht=reise', 'schnee.php?km=800&ansicht=woche', 'schnee.php?sport=skitour&km=800&nach=km', 'schnee.php?km=800&prio=neu', "schnee.php?km=800&prio=neu&nach=$t1", 'schnee.php?km=800&prio=km&ansicht=woche', 'schnee.php?km=800&prio=<x>',
          "schnee.php?km=800&nach=$t1", 'schnee.php?sport=<script>&km=12', 'schnee_stunden.php?region=schnee-a', "schnee_stunden.php?region=schnee-b&sport=skitour&tag=$t0",
          "schnee.php?region=schnee-a&nach=$t1", 'schnee.php?region=schnee-a&nach=2020-01-01'] as $u) {
    [$code, $html] = holen($wPort, $u);
    pruefe($code === 200 && ohne_php_meldung($html), "$u: 200 ohne PHP-Meldung");
}
[, $html] = holen($wPort, 'schnee.php?km=800');
pruefe(str_contains($html, 'id="r-schnee-a"') && str_contains($html, 'class="tag s-go') && !preg_match('/class="tag (go|maybe|no)\b/', $html),
       'Kacheln in der Blau-Skala, nie Grün/Gelb/Rot');
pruefe(str_contains($html, 'id="heimBus"') && str_contains($html, 'data-art="ski"') && str_contains($html, 'class="skier"') && str_contains($html, "getElementById('heim')")
       && preg_match('/class="heim" id="heim"[^>]* data-wohin="[\d.]+,[\d.]+"/', $html) && preg_match('/data-schneit="[\d.]+,[\d.]+,\d+"/', $html)
       && str_contains($html, 'id="ton"') && strrpos($html, 'class="heim"') > strrpos($html, 'class="punkt '),
       'Karte: Männchen mit Ziel (Pulvertag), Bus mit Ski auf dem Dach, Ski am Ziel, Flocken an guten Regionen, Ton-Schalter');
pruefe(preg_match('#id="r-schnee-a".*?<p class="maps klein"><a class="maps-knopf" href="https://www.google.com/maps/search/\?api=1&amp;query=[\d.%C-]+"#s', $html) === 1, 'Schnee-Karte: Maps-Knopf');
if (getenv('UMKREIS_SCHNEE_OFFEN')) {   // zum Ansehen im Browser: Schnee-Seite eine Weile offen lassen (Sekunden in UMKREIS_SEITE_SEK)
    file_put_contents(getenv('UMKREIS_SCHNEE_OFFEN'), (string)$wPort);
    sleep((int)(getenv('UMKREIS_SEITE_SEK') ?: 90));
}
pruefe(str_contains($html, 'class="lw lw3 warn-erheblich"') && str_contains($html, 'class="lw lw2"') && str_contains($html, 'Zuordnung über den Namen'), 'Lawinen-Abzeichen in EAWS-Farben, Hinweis auf Namenszuordnung');
pruefe(preg_match('#id="r-schnee-c".*?class="tag [^"]*warn-hoch"#s', $html) === 1 && str_contains($html, 'class="lw lw4 warn-hoch"') && str_contains($html, '<span class="warn" aria-label="Warnung">⚠')
       && preg_match('#class="warn-punkt warn-hoch"#', $html) === 1 && preg_match('#class="warn-punkt warn-erheblich"#', $html) === 1
       && str_contains($html, 'Bei Stufe 3 passieren die meisten tödlichen Lawinenunfälle.'),
       'Lawinen-Warnzeichen: Stufe 4 Kachel rot umrandet, Dreieck im Abzeichen und auf der Karte, Hinweis zu Stufe 3');
pruefe(preg_match('#<a href="schnee_stunden\.php\?[^"]*tag=\d{4}-\d\d-\d\d[^"]*\#lawine" class="warn-link">\s*<title>⚠ [^<]*Lawinenwarnstufe \d [^<]*antippen#u', $html) === 1
       && str_contains($html, 'class="tippflaeche"'), 'Karte: Dreieck ist ein eigener Link zum Tag mit der Warnung');
pruefe(str_contains($html, '<select name="prio">') && str_contains($html, '<option value="pulver" selected>Pulver + Sonne</option>') && str_contains($html, '<select name="nach">')
       && preg_match('#<select name="nach">(.*?)</select>#s', $html, $mNach) && !str_contains($mNach[1], 'value="km"'), 'Auswahl: Tag und Priorität getrennt');
pruefe(substr_count($html, 'noch die eigene Beurteilung vor Ort') >= 3 && str_contains($html, '<p class="karte klein haftung">'), 'Haftungshinweis oben, je Region und im Fuß');
pruefe(preg_match('#id="r-schnee-b".*?ausgegeben heute \d+:\d\d, gültig bis \S+ \d+:\d\d, abgerufen heute \d+:\d\d.*?Auszug aus dem Lagebericht \(Quelle: LWD Bayern Test\)#s', $html) === 1
       && str_contains($html, 'Hinweis von Wohinfahrma: Lawinenwarnstufe 4') && str_contains($html, 'Warndreiecke und ihre Texte sind Hinweise von Wohinfahrma')
       && !str_contains($html, 'lawine-stoerung'),
       'Lawinenlage: Ausgabe und Gültigkeit, Auszug mit Quelle, eigene Hinweise benannt, ohne Störung kein Störungshinweis');
pruefe(preg_match('#id="r-schnee-d".*?Lawinenlage: <a href="https://example.org/bericht"#s', $html) === 1 && !preg_match('#id="r-schnee-d".*?class="lw lw#s', explode('id="r-schnee-a"', $html)[0] ?? ''),
       'nicht freigegebener Dienst: nur Link, keine Stufe');
pruefe(str_contains($html, '<nav class="modus" aria-label="Modus"><a href="./">Wind</a><a href="schnee.php" aria-current="page">Schnee</a>'), 'Schalter Wind · Schnee');
pruefe(str_contains($html, '<details class="karte landkarte" id="landkarte" open>') && str_contains($html, 'class="punkt s-go"'), 'Karte mit blauen Punkten');
pruefe(str_contains($html, 'class="windfeld schneefeld schneeraster feld-neu"') && str_contains($html, 'Neuschnee auf 2.000 m') && str_contains($html, 'id="feldWahl"'),
       'Karte mit Neuschnee-Raster und Schalter');
pruefe(str_contains($html, 'class="windfeld schneefeld schneeraster feld-hs"') && str_contains($html, 'data-feld="hs"') && str_contains($html, 'Schneehöhe im Modell: 80 cm')
       && str_contains($html, 'class="windskala hsskala"') && str_contains($html, 'Fläche: Schneehöhe im Modell'),
       'Karte: Schneehöhe als zweite Fläche mit eigener Legende und Wahl');
pruefe(str_contains($html, 'class="teilen" hidden') && str_contains($html, 'Abfahrt bis'), 'gute Tage: Teilen und Abfahrt');
pruefe(!str_contains($html, 'name="sport"') && !str_contains($html, 'sport=') && str_contains($html, 'Pulvertag:') && str_contains($html, '<b>Pulvertag</b>'),
       'keine Sportart mehr: kein Schalter, keine Links mit sport=, gute Tage heißen Pulvertag');
[, $hs] = holen($wPort, 'schnee_stunden.php?region=schnee-a&tag=' . $t1);
pruefe(str_contains($hs, '<a class="maps-knopf" href="https://www.google.com/maps/search/?api=1&amp;query='), 'Schnee-Stundenseite: Maps-Knopf');
pruefe(str_contains($hs, '✓ Pulvertag') && str_contains($hs, 'Wind am Kamm') && str_contains($hs, '>Schneehöhe<'), 'Stundenseite: Urteil Pulvertag, Schneehöhe und Wind am Kamm als Kennzahl');
pruefe(str_contains($hs, '<span class="t">Temperatur</span><span class="w">−2 / −2°</span><span class="u">Nacht / Tag</span>')
       && preg_match('#id="r-schnee-a".*?<span class="k">Temp</span> −2 / −2°</span>#s', $html) === 1
       && preg_match('#id="r-schnee-b".*?<span class="k">Temp</span> 3 / 3°</span>#s', $html) === 1 && str_contains($html, 'Temperatur Nacht / Tag −2 / −2°C'),
       'Temperatur Nacht / Tag in Kachel, Tooltip und Stundenseite');
pruefe(preg_match('#<div class="kz kz-schnee"><span class="t">Neuschnee 24 h.*?<div class="kz kz-temp"[^>]*><span class="t">Temperatur.*?<div class="kz kz-sonne"><span class="t">Himmel.*?<div class="kz kz-wind"><span class="t">Wind am Kamm#s', $hs) === 1,
       'Stundenseite: Kennzahlen nach Bedeutung gruppiert und gefärbt (Schnee, Temperatur, Sonne, Wind)');
pruefe(!preg_match('/\d+ %(?! Wolken)/u', strip_tags(preg_replace('#<details class="leise klein erklaerung">.*?</details>#s', '', $html))), 'keine Prozent-Chance mehr auf der Seite');
pruefe(str_contains($html, 'Zahl: Neuschnee in cm'), 'Karte: Zahl ist Neuschnee in cm');
// Seit 13.11.2026: Maennchen faehrt nicht zu Stufe 4/5, sondern schuettelt den Kopf
pruefe(!preg_match('/class="heim" id="heim"[^>]* data-nein=/', $html) && !str_contains($html, 'id="heimNein"') && str_contains($html, 'id="heimJuchhu" data-b="5.4"'),
       'Männchen: bei Stufe 3 am besten Ziel kein Kopfschütteln');
$mN = maennchen_svg([10, 10], 2.0, [30, 40], 'ski', 'Inning', false, null, ['xy' => [50, 5], 'text' => 'Naa, Stufe 4!']);
$mN0 = maennchen_svg([10, 10], 2.0, null, 'ski', 'Inning', false, null, ['xy' => [50, 5], 'text' => 'Naa, Stufe 5!']);
pruefe(preg_match('/data-nein="50,5" data-wohin="30,40">/', $mN) === 1 && str_contains($mN, 'id="heimNeinPuls"') && str_contains($mN, '>Naa, Stufe 4!</text>')
       && substr_count($mN, 'id="heimBlick"') === 1 && substr_count($mN0, 'id="heimBlick"') === 1 && str_contains($mN0, 'data-nein="50,5">')
       && !str_contains($mN0, 'data-wohin') && !str_contains($mN0, 'id="heimBus"') && str_contains($mN0, 'antippen: wohin fahr ma?'),
       'Männchen mit Region über Stufe 3: Puls, Sprechblase, Blicklinie; ohne Ziel kein Bus');
pruefe(str_contains($html, '>Kacheln</a>') && str_contains($html, '>Tabelle 7 Tage</a>') && preg_match('#>Heute – (So|Mo|Di|Mi|Do|Fr|Sa)</a>#u', $html) === 1
       && preg_match('#>(So|Mo|Di|Mi|Do|Fr|Sa) – (So|Mo|Di|Mi|Do|Fr|Sa)</a>#u', $html) === 1 && !str_contains($html, 'Tag 3 – 7'),
       'Ansichten: Kacheln mit Wochentagen, Tabelle 7 Tage');
// Deine Regionen (seit 13.11.2026)
pruefe(str_contains($html, 'class="fav" data-id="schnee-a" aria-pressed="false"') && str_contains($html, 'id="favZeile" hidden') && str_contains($html, "'wohinfahrma_fav_schnee'")
       || str_contains($html, '"wohinfahrma_fav_schnee"'), 'Sterne an den Regionen, Zeile für deine Regionen');
[$cF, $hF] = holen($wPort, 'schnee.php?km=100&fav=schnee-c,<x>,schnee-a');
pruefe($cF === 200 && ohne_php_meldung($hF) && str_contains($hF, 'id="r-schnee-a"') && str_contains($hF, 'id="r-schnee-c"') && !str_contains($hF, 'id="r-schnee-b"')
       && str_contains($hF, '★ Nur deine Regionen (2), ohne Umkreis') && !str_contains($hF, 'class="umkreis"') && !str_contains($hF, '<select name="km">')
       && str_contains($hF, 'name="fav" value="schnee-a,schnee-c"') && str_contains($hF, 'fav=schnee-a%2Cschnee-c') && !str_contains($hF, '&lt;x&gt;')
       && str_contains($hF, 'class="fav" data-id="schnee-a" aria-pressed="true"'),
       'nur deine Regionen: ohne Umkreis, unbekannte ids weg, Auswahl behält fav');
pruefe(preg_match('#id="r-schnee-a".*?Schneehöhe gemessen heute .*?Gutalm <span class="leise">2.450 m</span> <b>42 cm</b>.*?\(24 h \+5\)#s', $html) === 1 && !str_contains($html, 'Fehlerkopf')
       && str_contains($html, 'Messwerte: <a href="https://lawinen.report/weather/stations"'), 'Liste: gemessene Schneehöhe mit Änderung, Messfehler ausgeblendet, Quelle im Fuß');
pruefe(preg_match('#id="r-schnee-d".*?Schneehöhe im Modell, Stand \w\w \d+\.\d+\. \(<a href="https://data.hub.geosphere.at/dataset/snowgrid_cl-v2-1d-1km"[^>]*>SNOWGRID ↗</a>\):</span> <span[^>]*>2.350 m <b>18 cm</b>.*?\(Vortag \+3\)#s', $html) === 1
       && !preg_match('#id="r-schnee-d".*?Schneehöhe gemessen#s', explode('id="r-schnee-a"', $html)[0] . 'x') && str_contains($html, 'Modellwerte: <a href="https://data.hub.geosphere.at/dataset/snowgrid_cl-v2-1d-1km"'),
       'Liste: SNOWGRID als Modellwert abgesetzt, nicht als Messung');
$karte = fn(string $id) => explode('<article', explode('id="r-' . $id . '"', $html, 2)[1] ?? '')[0];
$wa = $karte('schnee-a');
pruefe(str_contains($wa, '>Cam Gutalm ↗</a> <span class="leise">an der Station Gutalm</span>') && str_contains($wa, 'title="Gutalm - Blick nach Süden, 2.450 m"')
       && substr_count($wa, 'https://example.org/cam/tal/') === 1 && str_contains($wa, '>Cam Tal ↗</a> <span class="leise">3,4 km von Sprungjoch</span>'),
       'Liste: Webcam an der Station, nächste Webcam mit Entfernung, geteilte Webcam nur einmal (bei der nächsten Station)');
pruefe(str_contains($karte('schnee-b'), '>Cam Hundert ↗</a> <span class="leise">12 km von Hundertelf</span>'), 'Liste: Webcam ab 10 km ohne Nachkomma');
$wd = $karte('schnee-d');
pruefe(str_contains($wd, '>Cam Region D ↗</a> <span class="leise">rund 4,2 km vom Tourengebiet</span>') && !str_contains($wd, 'cam/modell'),
       'Liste: nur SNOWGRID-Punkte → Webcam der Region, keine zum Modellpunkt');
pruefe(str_contains($karte('schnee-c'), '>Cam Region C ↗</a> <span class="leise">im Gebiet</span>') && str_contains($html, 'Webcams: verlinkt, nicht eingebunden'),
       'Liste: Station ohne Webcam → Webcam der Region ohne km; Quelle im Fuß');
$wb = $karte('schnee-b');
pruefe(preg_match('#Schneehöhe gemessen heute [^(]*\(<a href="https://www.slf.ch/" rel="noopener">WSL-Institut für Schnee- und Lawinenforschung SLF ↗</a>, <a href="https://data.hub.geosphere.at/dataset/tawes-v1-10min"[^>]*>GeoSphere ↗</a>, <a href="https://www.meteoschweiz.admin.ch/"#u', $wb) === 1
       && preg_match('#Imeins <span class="leise">2.600 m</span> <b>36 cm</b> <span class="leise" title="[^"]*">\(Vortag \+5\)</span> <span class="leise" title="[^"]*SNOWPACK[^"]*">· Neuschnee 6 cm \(Modell SNOWPACK\)</span>#u', $wb) === 1
       && !str_contains($wb, 'Imzwei') && str_contains($html, 'Messwerte: <a href="https://www.slf.ch/"'),
       'Liste: SLF IMIS mit Tageswert, Neuschnee als Modell SNOWPACK gekennzeichnet, Quelle SLF mit Link auf slf.ch');
pruefe(preg_match('#id="r-schnee-c".*?Schneehöhe gemessen heute .*?Bezett eins <span class="leise">2.450 m</span> <b>26 cm</b>.*?\(24 h \+6\).*?Bezett zwei <span class="leise">2.000 m</span> <b>0 cm</b>#s', $html) === 1,
       'Liste: Südtiroler Stationen');
[, $hw] = holen($wPort, 'schnee.php?km=800&prio=neu&ansicht=woche');
pruefe(str_contains($hw, '<option value="neu" selected>Neuschnee</option>') && str_contains($hw, 'prio=neu') && preg_match('#<td class="[^"]*warn-(hoch|erheblich)"#', $hw) === 1,
       'Woche: Priorität bleibt in den Links, Warnzeichen in den Zellen');
[, $html] = holen($wPort, "schnee.php?region=schnee-a&nach=$t1");
pruefe(str_contains($html, 'Geteilte Vorhersage') && !str_contains($html, 'id="r-schnee-b"') && !str_contains($html, 'id="wahl"'), 'geteilte Vorhersage: nur diese Region, ohne Auswahl');
[, $html] = holen($wPort, 'schnee_stunden.php?region=schnee-a&tag=' . $t1);
pruefe(str_contains($html, 'class="s-schnee"') && str_contains($html, 'class="s-sfg"') && str_contains($html, 'class="lw lw3"') && (str_contains($html, 'Spanne ') || str_contains($html, 'Ensemble einig'))
       && str_contains($html, '<ul class="kriterien') && !str_contains($html, 's-berg'),
       'Stundenseite: Schneebalken, Schneefallgrenze, Lawinenlage, Spanne des Ensembles, Regeln einzeln');
[, $html] = holen($wPort, 'schnee_stunden.php?region=schnee-a&tag=' . $t0);
pruefe(str_contains($html, 'Schneehöhe gemessen') && str_contains($html, 'Sprungjoch'), 'Stundenseite heute: gemessene Schneehöhe');
pruefe(str_contains($html, 'Webcams:') && str_contains($html, 'https://example.org/cam/gutalm/'), 'Stundenseite heute: Webcams');
pruefe(!str_contains(holen($wPort, 'schnee_stunden.php?region=schnee-a&tag=' . $t1)[1], 'Schneehöhe gemessen'), 'Stundenseite morgen: keine Messung');
[, $hOhneS] = holen($wPort, 'schnee_stunden.php?region=schnee-a&tag=' . $t3);
pruefe(preg_match('#Kein gültiger Lagebericht für diesen Tag\..*?<span class="leise">abgerufen heute \d+:\d\d</span>#u', $hOhneS) === 1,
       'ohne gültigen Bericht: trotzdem, wann zuletzt beim Dienst abgerufen wurde');
pruefe(preg_match('#Berichte gibt es meist nur für heute und morgen\. Vorhanden:.*?\#lawine">.*?<span class="lw lw\d">Gefahrenstufe \d</span>#su', $hOhneS) === 1
       && str_contains($hOhneS, 'id="lawine"'), 'ohne gültigen Bericht: Tage mit Bericht verlinkt');
[$code] = holen($wPort, 'schnee_stunden.php?region=gibtsnicht');
pruefe($code === 404, 'Stundenseite: unbekannte Region 404');
[, $html] = holen($wPort, 'index.php?km=1000');
pruefe(str_contains($html, '<a href="./" aria-current="page">Wind</a><a href="schnee.php">Schnee</a>'), 'Wind-Seite: Schalter zum Schnee-Modus');
$stoerDatei = "$tmp/schnee/lawine/" . gmdate('Y-m-d') . '.ndjson';
file_put_contents($stoerDatei, json_encode(['ts' => gmdate('Y-m-d\TH:i:s\Z', time() + 60), 'source' => 'lawine', 'ok' => false, 'dienst' => 'euregio', 'tage' => [], 'err' => 'http=500']) . "\n");
[, $hSt] = holen($wPort, 'schnee.php?km=800');
pruefe(str_contains($hSt, '<p class="karte klein lawine-stoerung">') && preg_match('#id="r-schnee-c".*?Abruf gestört.*?Der letzte Abruf beim Warndienst ist fehlgeschlagen.*?class="lw lw4#s', $hSt) === 1
       && substr_count($hSt, 'Abruf gestört') === 1,
       'Störung beim Abruf: Hinweis oben und bei der Region, letzter guter Bericht bleibt sichtbar; anderer Dienst unberührt');
[, $hSt] = holen($wPort, 'schnee_stunden.php?region=schnee-c&tag=' . $t0);
pruefe(str_contains($hSt, 'class="klein lawine-stoerung"') && str_contains($hSt, 'Stand der Daten:'), 'Stundenseite: Störungshinweis mit Stand');
unlink($stoerDatei);
// lawine_holen.php (seit 13.11.2026): nur mit Schluessel aus config.php, nur per POST
$aSchl = str_repeat('b2', 12);
$aPort = $port + 9;
$aServer = proc_open(['php', '-S', "127.0.0.1:$aPort", __DIR__ . '/fake_openmeteo.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $aPort); $i++) {
    usleep(100000);
}
[$code] = senden($wPort, 'lawine_holen.php', ['schluessel' => $aSchl]);
$cfgAlt = (string)file_get_contents("$sSite/config.php");
file_put_contents("$sSite/config.php", '<?php return ' . var_export(['data_dir' => "$tmp/schnee_http", 'abruf_schluessel' => $aSchl,
                  'test_url_ersatz' => "http://127.0.0.1:$aPort"], true) . ';');
[$code2] = senden($wPort, 'lawine_holen.php', ['schluessel' => 'falsch']);
[$code3] = holen($wPort, 'lawine_holen.php');
[$code4, $antw] = senden($wPort, 'lawine_holen.php', ['schluessel' => $aSchl]);
$jA = json_decode($antw, true);
pruefe($code === 404 && $code2 === 404 && $code3 === 404 && $code4 === 200 && ($jA['lawine'] ?? '') === 'ok' && ($jA['stationen'] ?? '') === 'ok'
       && is_file("$tmp/schnee_http/lawine/server.ndjson"),
       'lawine_holen.php: ohne oder mit falschem Schlüssel 404, mit Schlüssel holt es Lagebericht und Stationen' . ($code4 === 200 ? '' : " (HTTP $code4: " . substr($antw, 0, 200) . ')'));
file_put_contents("$sSite/config.php", $cfgAlt);
proc_terminate($aServer);
unlink("$sSite/lib/schneeregionen.json");
[$code] = holen($wPort, 'schnee.php');
pruefe($code === 503, 'ohne Schnee-Katalog: 503');
proc_terminate($sWeb);
