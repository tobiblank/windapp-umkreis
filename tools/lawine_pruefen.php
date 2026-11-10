<?php
/**
 * Echte Lageberichte gegen lawine_lesen() pruefen (seit 10.11.2026), laeuft in
 * .github/workflows/lawine-pruefen.yml (mit Netz). Liest je freigegebenem Dienst mit
 * {datum} die Datei fuer die angegebenen Tage (Standard: zwei Tage aus dem letzten
 * Winter), dazu heute. 404 ist bei vergangenen Tagen ein Fehler, heute nicht (ausserhalb
 * der Saison gibt es keinen Bericht).
 *
 *   php tools/lawine_pruefen.php [daten/schneeregionen.json] [YYYY-MM-DD ...]
 *
 * Ausgabe je Datei: Berichtsteile, Mikroregionen, Stufen und Probleme als Text, wie die
 * Seite sie zeigt. Exit 1, wenn eine Datei fehlt oder nicht gelesen werden kann.
 */

require __DIR__ . '/../server/web/lib/lawine.php';

$katalog = $argv[1] ?? __DIR__ . '/../daten/schneeregionen.json';
$tage = array_slice($argv, 2) ?: ['2026-03-27', '2026-04-17'];
$dienste = json_decode((string)file_get_contents($katalog), true)['lawinendienste'] ?? [];
$heute = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
$fehler = 0;

foreach ($dienste as $id => $d) {
    if (!$d['freigegeben'] || !str_contains($d['abruf'], '{datum}')) {
        continue;
    }
    foreach (array_unique([...$tage, $heute]) as $tag) {
        $url = lawine_url($d, $tag);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true,
                                CURLOPT_USERAGENT => 'wohinfahrma.de (Pruefung Lagebericht)']);
        $body = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo "== $id $tag: HTTP $http, " . strlen($body) . " Bytes\n   $url\n";
        if ($http === 404 && $tag === $heute) {
            echo "   heute noch kein Bericht (ausserhalb der Saison normal)\n";
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
