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
exec($sCmd("$tmp/schnee_nur", '--nur lawine'), $ausN, $rcN);
pruefe($rcN === 0 && is_dir("$tmp/schnee_nur/lawine") && !is_dir("$tmp/schnee_nur/vorhersage") && !is_dir("$tmp/schnee_nur/schnee_ensemble"), '--nur lawine: nur der Lagebericht');

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
pruefe(str_contains($html, 'class="windfeld schneefeld schneeraster"') && str_contains($html, 'Neuschnee auf 2.000 m') && str_contains($html, 'id="feldKnopf"'),
       'Karte mit Neuschnee-Raster und Schalter');
pruefe(str_contains($html, 'class="teilen" hidden') && str_contains($html, 'Abfahrt bis'), 'gute Tage: Teilen und Abfahrt');
pruefe(!str_contains($html, 'name="sport"') && !str_contains($html, 'sport=') && str_contains($html, 'Pulvertag:') && str_contains($html, '<b>Pulvertag</b>'),
       'keine Sportart mehr: kein Schalter, keine Links mit sport=, gute Tage heißen Pulvertag');
[, $hs] = holen($wPort, 'schnee_stunden.php?region=schnee-a&tag=' . $t1);
pruefe(str_contains($hs, '✓ Pulvertag') && str_contains($hs, 'Wind am Kamm') && str_contains($hs, '>Schneehöhe<'), 'Stundenseite: Urteil Pulvertag, Schneehöhe und Wind am Kamm als Kennzahl');
pruefe(!preg_match('/\d+ %(?! Wolken)/u', strip_tags(preg_replace('#<details class="leise klein erklaerung">.*?</details>#s', '', $html))), 'keine Prozent-Chance mehr auf der Seite');
pruefe(str_contains($html, 'Zahl: Neuschnee in cm'), 'Karte: Zahl ist Neuschnee in cm');
pruefe(preg_match('#id="r-schnee-a".*?Schneehöhe gemessen heute .*?Gutalm <span class="leise">2.450 m</span> <b>42 cm</b>.*?\(24 h \+5\)#s', $html) === 1 && !str_contains($html, 'Fehlerkopf')
       && str_contains($html, 'Messwerte: <a href="https://lawinen.report/weather/stations"'), 'Liste: gemessene Schneehöhe mit Änderung, Messfehler ausgeblendet, Quelle im Fuß');
pruefe(preg_match('#id="r-schnee-d".*?Schneehöhe im Modell, Stand \w\w \d+\.\d+\. \(<a href="https://data.hub.geosphere.at/dataset/snowgrid_cl-v2-1d-1km"[^>]*>SNOWGRID ↗</a>\):</span> <span[^>]*>2.350 m <b>18 cm</b>.*?\(Vortag \+3\)#s', $html) === 1
       && !preg_match('#id="r-schnee-d".*?Schneehöhe gemessen#s', explode('id="r-schnee-a"', $html)[0] . 'x') && str_contains($html, 'Modellwerte: <a href="https://data.hub.geosphere.at/dataset/snowgrid_cl-v2-1d-1km"'),
       'Liste: SNOWGRID als Modellwert abgesetzt, nicht als Messung');
pruefe(preg_match('#id="r-schnee-c".*?Schneehöhe gemessen heute .*?Bezett eins <span class="leise">2.450 m</span> <b>26 cm</b>.*?\(24 h \+6\).*?Bezett zwei <span class="leise">2.000 m</span> <b>0 cm</b>#s', $html) === 1,
       'Liste: Südtiroler Stationen');
[, $html] = holen($wPort, "schnee.php?region=schnee-a&nach=$t1");
pruefe(str_contains($html, 'Geteilte Vorhersage') && !str_contains($html, 'id="r-schnee-b"') && !str_contains($html, 'id="wahl"'), 'geteilte Vorhersage: nur diese Region, ohne Auswahl');
[, $html] = holen($wPort, 'schnee_stunden.php?region=schnee-a&tag=' . $t1);
pruefe(str_contains($html, 'class="s-schnee"') && str_contains($html, 'class="s-sfg"') && str_contains($html, 'class="lw lw3"') && (str_contains($html, 'Spanne ') || str_contains($html, 'Ensemble einig'))
       && str_contains($html, '<ul class="kriterien') && !str_contains($html, 's-berg'),
       'Stundenseite: Schneebalken, Schneefallgrenze, Lawinenlage, Spanne des Ensembles, Regeln einzeln');
[, $html] = holen($wPort, 'schnee_stunden.php?region=schnee-a&tag=' . $t0);
pruefe(str_contains($html, 'Schneehöhe gemessen') && str_contains($html, 'Sprungjoch'), 'Stundenseite heute: gemessene Schneehöhe');
pruefe(!str_contains(holen($wPort, 'schnee_stunden.php?region=schnee-a&tag=' . $t1)[1], 'Schneehöhe gemessen'), 'Stundenseite morgen: keine Messung');
[$code] = holen($wPort, 'schnee_stunden.php?region=gibtsnicht');
pruefe($code === 404, 'Stundenseite: unbekannte Region 404');
[, $html] = holen($wPort, 'index.php?km=1000');
pruefe(str_contains($html, '<a href="./" aria-current="page">Wind</a><a href="schnee.php">Schnee</a>'), 'Wind-Seite: Schalter zum Schnee-Modus');
unlink("$sSite/lib/schneeregionen.json");
[$code] = holen($wPort, 'schnee.php');
pruefe($code === 503, 'ohne Schnee-Katalog: 503');
proc_terminate($sWeb);
