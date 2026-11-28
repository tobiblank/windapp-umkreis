<?php
/**
 * Echte Lageberichte gegen lawine_lesen() pruefen (seit 10.11.2026), laeuft in
 * .github/workflows/lawine-pruefen.yml (mit Netz). Liest je freigegebenem Dienst die Datei
 * fuer heute und morgen, dazu die angegebenen Tage; Dienste ohne {datum} (SLF) einmal den
 * aktuellen Bericht. Seit 28.11.2026 ohne Angabe zusaetzlich der 27.3.2026 als Archivtag:
 * static.lawinen-warnung.eu (Bayern, GeoSphere) haelt aeltere Dateien nicht mehr vor, nur
 * avalanche.report (Euregio, Kaernten, Lombardei). 404 ist deshalb nur bei ausdruecklich
 * angegebenen vergangenen Tagen ein Fehler; heute, morgen und der Archivtag sind ein Hinweis
 * (ausserhalb der Saison und vor 17 Uhr fuer morgen gibt es keinen Bericht).
 *
 *   php tools/lawine_pruefen.php [daten/schneeregionen.json] [YYYY-MM-DD ...]
 *
 * Ausgabe je Datei: Berichtsteile, Mikroregionen, Stufen und Probleme als Text, wie die
 * Seite sie zeigt. Exit 1, wenn eine Datei fehlt oder nicht gelesen werden kann.
 */

require __DIR__ . '/../server/web/lib/lawine.php';

$katalog = $argv[1] ?? __DIR__ . '/../daten/schneeregionen.json';
$angegeben = array_slice($argv, 2);
$dienste = json_decode((string)file_get_contents($katalog), true)['lawinendienste'] ?? [];
$heuteD = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
$heute = $heuteD->format('Y-m-d');
$morgen = $heuteD->modify('+1 day')->format('Y-m-d');
$tage = $angegeben ?: ['2026-03-27'];
$fehler = 0;

foreach ($dienste as $id => $d) {
    if (!$d['freigegeben']) {
        continue;
    }
    $mitDatum = str_contains($d['abruf'], '{datum}');
    foreach ($mitDatum ? array_unique([...$tage, $heute, $morgen]) : ['aktuell'] as $tag) {
        $url = lawine_url($d, $tag);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true,
                                CURLOPT_USERAGENT => 'wohinfahrma.de (Pruefung Lagebericht)']);
        $body = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo "== $id $tag: HTTP $http, " . strlen($body) . " Bytes\n   $url\n";
        if ($http === 404 && !($mitDatum && in_array($tag, $angegeben, true) && $tag < $heute)) {
            echo '   ' . match (true) {
                $tag === 'aktuell', $tag === $heute => 'noch kein Bericht (ausserhalb der Saison normal)',
                $tag === $morgen => 'morgen noch kein Bericht (vor 17 Uhr normal)',
                default => 'Archivtag nicht mehr abrufbar (Hinweis, kein Fehler)',
            } . "\n";
            continue;
        }
        $b = $http === 200 ? lawine_lesen($body, $d['format']) : null;
        if (!$b) {
            echo "   FEHLER: nicht gelesen\n";
            $fehler++;
            continue;
        }
        $b = lawine_filtern($b, $d['freigegeben']);
        $roh = roh_bulletins($body, $d['format']);
        $roh_t = roh_tendenz($body, $d['format']);
        $ohneStufe = 0;
        foreach ($b as $x) {
            $ohneStufe += $x['stufen'] ? 0 : 1;
            if (lawine_mehrdeutig($x['stufen'])) {
                // Mehrere Stufen zur selben Tageszeit ohne Hoehengrenze: Rohdaten zeigen (seit 10.11.2026)
                echo '     MEHRDEUTIG, Rohdaten: ' . json_encode($roh[$x['id']] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
            printf("   %s | %s bis %s | %s\n     Stufe: %s\n", substr($x['id'], 0, 8), $x['von'], $x['bis'],
                   implode(', ', array_map(fn($r) => "$r[0] $r[1]", $x['regionen'])), lawine_stufen_text($x['stufen'], false) ?: '–');
            foreach (lawine_probleme_liste($x['probleme']) as $p) {
                echo "     Problem: {$p['text']}\n";
            }
            // Tendenz (seit 28.11.2026): gelesener Typ und, zur Pruefung, die Rohdaten
            echo '     Tendenz: ' . ($x['tendenz'] ? implode('; ', array_map(fn($t) => LAWINE_TENDENZ[$t['typ']] . " ({$t['von']} bis {$t['bis']})", $x['tendenz'])) : '–')
                 . ' | roh: ' . mb_substr(json_encode($roh_t[$x['id']] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 300) . "\n";
        }
        if (!$b || $ohneStufe) {
            echo "   FEHLER: " . (!$b ? 'keine freigegebene Mikroregion' : "$ohneStufe Teile ohne Gefahrenstufe") . "\n";
            $fehler++;
        }
    }
}
echo $fehler ? "\n$fehler FEHLER\n" : "\nAlles gelesen\n";
exit($fehler ? 1 : 0);

/** Rohe Stufen-Angaben je bulletinID (dangerRatings bzw. dangerRating), zur Fehlersuche */
function roh_bulletins(string $body, string $format): array
{
    if ($format === 'xml') {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($body, LIBXML_NONET)) {
            return [];
        }
        $liste = lawine_xml_array($dom->documentElement)['bulletin'] ?? [];
    } else {
        $liste = json_decode($body, true)['bulletins'] ?? [];
    }
    $out = [];
    foreach ($liste as $b) {
        $out[(string)($b['bulletinID'] ?? '')] = $b['dangerRatings'] ?? $b['dangerRating'] ?? null;
    }
    return $out;
}

/** Rohe Tendenz-Angaben je bulletinID (seit 28.11.2026), zur Pruefung, welche Dienste tendencyType liefern */
function roh_tendenz(string $body, string $format): array
{
    $out = [];
    if ($format === 'xml') {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($body, LIBXML_NONET)) {
            return [];
        }
        $roh = lawine_xml_array($dom->documentElement);
        $liste = is_array($roh) ? ($roh['bulletin'] ?? []) : [];
    } else {
        $liste = json_decode($body, true)['bulletins'] ?? [];
    }
    foreach ($liste as $b) {
        $out[(string)($b['bulletinID'] ?? '')] = $b['tendency'] ?? null;
    }
    return $out;
}
