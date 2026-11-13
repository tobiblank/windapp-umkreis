<?php
/**
 * Lawinenlage (seit 8.11.2026, Schnee-Modus): Lageberichte im Format CAAML v6
 * einlesen und je Mikroregion zusammenfassen.
 *
 * GRUNDSATZ: Die App sagt "wo liegt guter Schnee", nie "wo ist es sicher". Die
 * Gefahrenstufe geht nicht in Bewertung, Sortierung oder Farbe der Schnee-Kacheln
 * ein. Sie steht daneben, mit Link zum Bericht und Ausgabezeit. Keine eigene
 * Hochrechnung: nur Tage, fuer die ein Bericht gilt (meist heute und morgen).
 *
 * Quellen stehen im Katalog (daten/schneeregionen.json, "lawinendienste"):
 *   abruf       URL, {datum} = Tag, fuer den der Bericht gilt (YYYY-MM-DD)
 *   format      json (SLF, Euregio) oder xml (Bayern)
 *   praefixe    Mikroregionen dieses Diensts (Anfang der regionID)
 *   freigegeben Praefixe, deren Daten gezeigt werden duerfen (Lizenz geklaert).
 *               Leer: der Sammler ruft den Dienst nicht ab, die Seite zeigt nur
 *               einen Link zum Bericht.
 *
 * Gelesen werden beide Formen von CAAML v6: JSON (bulletins[].regions[],
 * dangerRatings[], avalancheProblems[]) und XML (bulletin/region/dangerRating/
 * avalancheProblem, Namensraum http://caaml.org/Schemas/V6.0/Profiles/BulletinEAWS).
 * Geprueft am 8.11.2026 nur an der Struktur einer echten Bayern-Datei (XML, ohne
 * Lawinenprobleme und ohne Hoehengrenze) und an der API-Beschreibung des SLF;
 * Felder wie aspects und elevation in XML sind nach dem CAAML-Schema angenommen.
 */

const LAWINE_STUFEN = ['low' => 1, 'moderate' => 2, 'considerable' => 3, 'high' => 4, 'very_high' => 5];
const LAWINE_STUFEN_NAMEN = [1 => 'gering', 2 => 'mäßig', 3 => 'erheblich', 4 => 'groß', 5 => 'sehr groß'];
const LAWINE_PROBLEME = [
    'new_snow'                       => 'Neuschnee',
    'wind_slab'                      => 'Triebschnee',
    'persistent_weak_layers'         => 'Altschnee',
    'wet_snow'                       => 'Nassschnee',
    'gliding_snow'                   => 'Gleitschnee',
    'cornices'                       => 'Wechten',
    'favourable_situation'           => 'günstige Situation',
    'no_distinct_avalanche_problem'  => 'kein ausgeprägtes Problem',
];
const LAWINE_ASPEKTE = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
const LAWINE_ASPEKTE_DE = ['N' => 'N', 'NE' => 'NO', 'E' => 'O', 'SE' => 'SO', 'S' => 'S', 'SW' => 'SW', 'W' => 'W', 'NW' => 'NW'];
const LAWINE_SUCHTAGE = 2;   // so viele UTC-Tage Sammler-Dateien zurueck lesen
const LAWINE_ALT_STUNDEN = 13;   // letzter guter Abruf aelter: Hinweis "nicht aktuell" (lawine_stoerung)
const LAWINE_ZEITEN = ['all_day' => '', 'earlier' => 'vormittags', 'later' => 'nachmittags'];
const LAWINE_HAFTUNG = 'Die Lawinenlage stammt vom Warndienst, ohne Gewähr. Sie ersetzt weder den aktuellen Lagebericht auf dessen Website noch die eigene Beurteilung vor Ort. Wohinfahrma bewertet nur Schnee und Wetter.';

// ===================================================================
// EINLESEN
// ===================================================================

/** XML-Element als Array, ohne Namensraeume: Attribute als Schluessel, Kinder (nach localName) als Liste */
function lawine_xml_array(DOMElement $el): array|string
{
    $out = [];
    foreach ($el->attributes as $a) {
        $out[$a->localName] = $a->value;
    }
    $kinder = false;
    foreach ($el->childNodes as $k) {
        if ($k instanceof DOMElement) {
            $kinder = true;
            $out[$k->localName][] = lawine_xml_array($k);
        }
    }
    return !$kinder && !$out ? trim($el->textContent) : $out;
}

/** Erstes Element einer Liste aus lawine_xml_array() oder der Wert selbst */
function lawine_eins($v)
{
    return is_array($v) && array_is_list($v) ? ($v[0] ?? null) : $v;
}

/** Hoehengrenze aus {lowerBound, upperBound} (JSON) oder [{lowerBound: [..]}] (XML) */
function lawine_hoehe($e): array
{
    $e = lawine_eins($e);
    if (!is_array($e)) {
        return [null, null];
    }
    $ab = lawine_eins($e['lowerBound'] ?? null);
    $bis = lawine_eins($e['upperBound'] ?? null);
    return [is_scalar($ab) && $ab !== '' ? (string)$ab : null, is_scalar($bis) && $bis !== '' ? (string)$bis : null];
}

/**
 * Bericht (CAAML v6, JSON oder XML) in die Form der App:
 *   [['id', 'veroeffentlicht', 'von', 'bis', 'regionen' => [[id, name], ...],
 *     'stufen' => [['stufe' => 1..5|0, 'ab' => '2200'|'treeline'|null, 'bis' => ..., 'zeit' => 'all_day'|'earlier'|'later']],
 *     'probleme' => [['typ', 'aspekte' => [...], 'ab', 'bis', 'zeit']]], ...]
 * Stufe 0 = no_snow / no_rating. Rueckgabe null, wenn der Text kein Bericht ist.
 */
function lawine_lesen(string $text, string $format): ?array
{
    if ($format === 'xml') {
        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $ok = $text !== '' && $dom->loadXML($text, LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if (!$ok || !$dom->documentElement || $dom->documentElement->localName !== 'bulletins') {
            return null;   // auch CAAML v5 (ObsCollection): anderes Format
        }
        $roh = lawine_xml_array($dom->documentElement);
        $bulletins = is_array($roh) ? ($roh['bulletin'] ?? []) : [];
    } else {
        $j = json_decode($text, true);
        if (!is_array($j) || !isset($j['bulletins']) || !is_array($j['bulletins'])) {
            return null;
        }
        $bulletins = $j['bulletins'];
    }
    $out = [];
    foreach ($bulletins as $b) {
        if (!is_array($b)) {
            continue;
        }
        $vt = lawine_eins($b['validTime'] ?? null);
        $regionen = [];
        foreach ($b['regions'] ?? $b['region'] ?? [] as $r) {
            $id = $r['regionID'] ?? null;
            if (is_string($id) && $id !== '') {
                $name = lawine_eins($r['name'] ?? '');
                $regionen[] = [$id, is_string($name) ? $name : ''];
            }
        }
        $stufen = [];
        foreach ($b['dangerRatings'] ?? $b['dangerRating'] ?? [] as $d) {
            $wert = lawine_eins($d['mainValue'] ?? null);
            if (!is_string($wert)) {
                continue;
            }
            [$ab, $bis] = lawine_hoehe($d['elevation'] ?? null);
            $stufen[] = ['stufe' => LAWINE_STUFEN[$wert] ?? 0, 'ab' => $ab, 'bis' => $bis,
                         'zeit' => (string)(lawine_eins($d['validTimePeriod'] ?? null) ?? 'all_day')];
        }
        $probleme = [];
        foreach ($b['avalancheProblems'] ?? $b['avalancheProblem'] ?? [] as $p) {
            $typ = lawine_eins($p['problemType'] ?? null);
            if (!is_string($typ)) {
                continue;
            }
            // JSON: aspects = ["N", ...]; XML: aspect = [..] direkt oder in aspects
            $asp = $p['aspects'] ?? $p['aspect'] ?? [];
            if (is_array($asp) && isset($asp[0]['aspect'])) {
                $asp = $asp[0]['aspect'];
            }
            $asp = array_values(array_intersect(LAWINE_ASPEKTE, array_map(fn($a) => strtoupper((string)lawine_eins($a)), (array)$asp)));
            [$ab, $bis] = lawine_hoehe($p['elevation'] ?? null);
            $probleme[] = ['typ' => $typ, 'aspekte' => $asp, 'ab' => $ab, 'bis' => $bis,
                           'zeit' => (string)(lawine_eins($p['validTimePeriod'] ?? null) ?? 'all_day')];
        }
        $out[] = [
            'id'              => (string)($b['bulletinID'] ?? ''),
            'veroeffentlicht' => (string)(lawine_eins($b['publicationTime'] ?? null) ?? ''),
            'von'             => (string)(lawine_eins($vt['startTime'] ?? null) ?? ''),
            'bis'             => (string)(lawine_eins($vt['endTime'] ?? null) ?? ''),
            'regionen'        => $regionen,
            'stufen'          => $stufen,
            'probleme'        => $probleme,
        ];
    }
    return $out;
}

/** Nur Berichtsteile fuer freigegebene Mikroregionen behalten (Regionsliste gekuerzt) */
function lawine_filtern(array $bulletins, array $freigegeben): array
{
    $out = [];
    foreach ($bulletins as $b) {
        $b['regionen'] = array_values(array_filter($b['regionen'], fn($r) => lawine_praefix($r[0], $freigegeben)));
        if ($b['regionen']) {
            $out[] = $b;
        }
    }
    return $out;
}

function lawine_praefix(string $id, array $praefixe): bool
{
    foreach ($praefixe as $p) {
        if ($p !== '' && str_starts_with($id, $p)) {
            return true;
        }
    }
    return false;
}

/** Abruf-URL fuer einen Tag */
function lawine_url(array $dienst, string $datum): string
{
    return str_replace('{datum}', $datum, $dienst['abruf']);
}

// ===================================================================
// LADEN (Seite)
// ===================================================================

/**
 * Neueste Berichte je Dienst aus den Sammler-Dateien (lawine/<lauf>.ndjson.gz oder
 * lawine/<tag>.ndjson). Eine Zeile je Dienst und Abruf:
 *   {"ts", "source": "lawine", "ok", "dienst", "tage": {datum: [bulletins]|null}}
 * Rueckgabe [dienst => ['ts' => ..., 'bulletins' => [...], 'versuch' => ts, 'versuch_ok' => bool]];
 * je Dienst die neueste gute Zeile, darin alle abgerufenen Tage zusammen. Seit 10.11.2026
 * auch der neueste Abrufversuch ueberhaupt (versuch, versuch_ok), damit die Seite eine
 * Stoerung anzeigen kann (Hinweise des LWD Bayern: Ausfaelle deutlich kennzeichnen).
 * Ein Dienst nur mit fehlgeschlagenen Versuchen steht mit 'ts' => '' und ohne Berichte da.
 */
function lawine_laden(string $dataDir, ?int $jetzt = null): array
{
    $jetzt ??= time();
    $dateien = [];
    for ($t = 0; $t < LAWINE_SUCHTAGE; $t++) {
        $d = gmdate('Y-m-d', $jetzt - $t * 86400);
        $dateien = array_merge($dateien, glob("$dataDir/lawine/$d.ndjson") ?: [], glob("$dataDir/lawine/{$d}_*.ndjson.gz") ?: []);
    }
    rsort($dateien);
    $out = [];
    $versuch = [];
    foreach ($dateien as $f) {
        $txt = (string)@file_get_contents($f);
        if (str_ends_with($f, '.gz')) {
            $txt = (string)@gzdecode($txt);
        }
        $zeilen = array_reverse(array_values(array_filter(array_map('trim', explode("\n", $txt)))));
        foreach ($zeilen as $z) {
            $j = json_decode($z, true);
            $d = $j['dienst'] ?? null;
            if (is_array($j) && is_string($d) && (string)($j['ts'] ?? '') > ($versuch[$d][0] ?? '')) {
                $versuch[$d] = [(string)$j['ts'], !empty($j['ok'])];
            }
            if (!is_array($j) || empty($j['ok']) || !is_string($d) || isset($out[$d]) || !is_array($j['tage'] ?? null)) {
                continue;
            }
            $b = [];
            $gesehen = [];
            foreach ($j['tage'] as $liste) {
                foreach (is_array($liste) ? $liste : [] as $x) {
                    // Derselbe Bericht kann unter zwei Tagen stehen (Albina legt ihn je Gueltigkeitstag ab)
                    $k = ($x['id'] ?? '') . '|' . ($x['von'] ?? '') . '|' . ($x['veroeffentlicht'] ?? '');
                    if (!isset($gesehen[$k])) {
                        $gesehen[$k] = true;
                        $b[] = $x;
                    }
                }
            }
            $out[$d] = ['ts' => (string)($j['ts'] ?? ''), 'bulletins' => $b];
        }
    }
    foreach ($versuch as $d => [$ts, $ok]) {
        $out[$d] ??= ['ts' => '', 'bulletins' => []];
        $out[$d]['versuch'] = $ts;
        $out[$d]['versuch_ok'] = $ok;
    }
    return $out;
}

/**
 * Stoerung beim Abruf eines Warndiensts (seit 10.11.2026) oder null. Gestoert heisst:
 * gar keine Daten, der letzte Versuch ist fehlgeschlagen oder der letzte gute Abruf ist
 * aelter als LAWINE_ALT_STUNDEN (Laeufe 6:25, 8:40, 18:25 Uhr: laengste Luecke 10 h).
 * Rueckgabe ['text' => ..., 'stand' => ts|''] fuer den Hinweis auf der Seite.
 */
function lawine_stoerung(?array $lage, ?int $jetzt = null): ?array
{
    $jetzt ??= time();
    $stand = (string)($lage['ts'] ?? '');
    $t = $stand !== '' ? strtotime($stand) : false;
    if (!$lage || $t === false) {
        return ['text' => 'Die Daten des Warndiensts konnten nicht abgerufen werden.', 'stand' => ''];
    }
    if (isset($lage['versuch_ok']) && !$lage['versuch_ok']) {
        return ['text' => 'Der letzte Abruf beim Warndienst ist fehlgeschlagen. Ein neuerer Bericht kann fehlen.', 'stand' => $stand];
    }
    if ($jetzt - $t > LAWINE_ALT_STUNDEN * 3600) {
        return ['text' => 'Die Daten des Warndiensts wurden länger nicht aktualisiert. Ein neuerer Bericht kann fehlen.', 'stand' => $stand];
    }
    return null;
}

/**
 * Lage einer Schnee-Region an einem Ortstag: Bericht, der um 12 Uhr Ortszeit gilt
 * (heute ab Mittag: der Bericht, der jetzt gilt; seit 10.11.2026, damit nach 17 Uhr kein
 * abgelaufener Bericht als aktuell erscheint),
 * fuer die Mikroregionen der Region (eaws: Praefix der regionID; ohne eaws ueber
 * eaws_namen, Teil des Namens). Bei mehreren Mikroregionen gilt die hoechste Stufe.
 * Rueckgabe null (kein Bericht fuer diesen Tag) oder
 *   ['stufe' => max, 'teile' => [stufen], 'probleme' => [...], 'veroeffentlicht', 'von', 'bis',
 *    'regionen' => [[id, name]], 'ueber_namen' => bool]
 */
function lawine_lage(array $region, array $lagen, string $tag, ?int $jetzt = null): ?array
{
    $lage = $lagen[$region['warndienst'] ?? ''] ?? null;
    if (!$lage || !$lage['bulletins']) {
        return null;
    }
    $jetzt ??= time();
    $tz = new DateTimeZone($region['zeitzone']);
    $mittag = (new DateTimeImmutable("$tag 12:00", $tz))->getTimestamp();
    if ($tag === (new DateTimeImmutable('@' . $jetzt))->setTimezone($tz)->format('Y-m-d')) {
        $mittag = max($mittag, $jetzt);
    }
    $eaws = $region['eaws'] ?? [];
    $namen = $region['eaws_namen'] ?? [];
    $treffer = [];
    foreach ($lage['bulletins'] as $b) {
        $von = strtotime($b['von']);
        $bis = strtotime($b['bis']);
        if ($von === false || $bis === false || $mittag < $von || $mittag >= $bis) {
            continue;
        }
        $passt = array_values(array_filter($b['regionen'], function ($r) use ($eaws, $namen) {
            if ($eaws) {
                return lawine_praefix($r[0], $eaws);
            }
            foreach ($namen as $n) {
                if ($n !== '' && mb_stripos($r[1], $n) !== false) {
                    return true;
                }
            }
            return false;
        }));
        if ($passt) {
            $treffer[] = [$b, $passt];
        }
    }
    if (!$treffer) {
        return null;
    }
    // Neueste Ausgabe zuerst; bei gleicher Ausgabe der Bericht mit der hoechsten Stufe
    usort($treffer, fn($a, $b) => [$b[0]['veroeffentlicht'], lawine_max($b[0])] <=> [$a[0]['veroeffentlicht'], lawine_max($a[0])]);
    $neu = $treffer[0][0]['veroeffentlicht'];
    $treffer = array_values(array_filter($treffer, fn($t) => $t[0]['veroeffentlicht'] === $neu));
    $b = $treffer[0][0];
    $regionen = [];
    foreach ($treffer as [, $rs]) {
        $regionen = array_merge($regionen, $rs);
    }
    return [
        'stufe'           => lawine_max($b),
        'teile'           => $b['stufen'],
        'probleme'        => $b['probleme'],
        'veroeffentlicht' => $b['veroeffentlicht'],
        'von'             => $b['von'],
        'bis'             => $b['bis'],
        'regionen'        => $regionen,
        'mehrere'         => count($treffer) > 1,
        'ueber_namen'     => !$eaws,
    ];
}

/** Hoechste Stufe eines Berichts (0 = keine Einstufung) */
function lawine_max(array $b): int
{
    return $b['stufen'] ? max(array_column($b['stufen'], 'stufe')) : 0;
}

/** Ab dieser Lawinenwarnstufe faehrt das Maennchen auf der Karte nicht hin (seit 13.11.2026) */
const MAENNCHEN_NEIN_AB = 4;

/**
 * Warnzeichen zu einer Lage (seit 10.11.2026): ab Stufe 3 steht ein Dreieck bei der Region,
 * ab Stufe 4 zusaetzlich rot umrandet. Die Reihenfolge der Rangliste aendert sich nicht.
 * Der Text ist ein eigener Hinweis, kein Inhalt des Warndiensts, und beginnt deshalb mit
 * "Hinweis von Wohinfahrma:" (Hinweise des LWD Bayern: eigene Bewertungen trennen).
 * Stufe 3: Laut Lawinenwarndienst Bayern geschahen in den Bayerischen Alpen seit 1993/94
 * 48 % der 52 toedlichen Lawinenunfaelle bei Stufe 3 (2022_toedl_Unfaelle_pro_Gefahrenstufe.pdf).
 * Rueckgabe null (keine Lage, Stufe 0 bis 2) oder ['stufe', 'klasse' (warn-hoch|warn-erheblich), 'text']
 */
function lawine_warnung(?array $lage): ?array
{
    $s = $lage['stufe'] ?? 0;
    if ($s >= 4) {
        return ['stufe' => $s, 'klasse' => 'warn-hoch',
                'text' => 'Hinweis von Wohinfahrma: Lawinenwarnstufe ' . $s . ' (' . LAWINE_STUFEN_NAMEN[$s] . '). Lagebericht lesen, bevor du losfährst.'];
    }
    if ($s === 3) {
        return ['stufe' => 3, 'klasse' => 'warn-erheblich',
                'text' => 'Hinweis von Wohinfahrma: Lawinenwarnstufe 3 (erheblich). Bei Stufe 3 passieren die meisten tödlichen Lawinenunfälle.'];
    }
    return null;
}

/** Ist der Warndienst einer Region fuer die Anzeige freigegeben (mindestens ein Praefix der Region)? */
function lawine_freigegeben(array $region, array $dienste): bool
{
    $d = $dienste[$region['warndienst'] ?? ''] ?? null;
    if (!$d || !$d['freigegeben']) {
        return false;
    }
    foreach ($region['eaws'] ?? [] as $id) {
        if (lawine_praefix($id, $d['freigegeben'])) {
            return true;
        }
    }
    // Ohne eingetragene Mikroregion: freigegeben, wenn der ganze Dienst es ist
    return !($region['eaws'] ?? []) && count($d['freigegeben']) >= count($d['praefixe']);
}

// ===================================================================
// TEXTE
// ===================================================================

/**
 * Mehrdeutig (seit 10.11.2026): mindestens zwei Stufen zur selben Tageszeit, von denen
 * mindestens zwei keine Hoehengrenze haben. Dann ist nicht klar, wo welche Stufe gilt.
 */
function lawine_mehrdeutig(array $teile): bool
{
    $ohne = [];
    foreach ($teile as $t) {
        if ($t['ab'] === null && $t['bis'] === null) {
            $z = $t['zeit'] ?? 'all_day';
            $ohne[$z] = ($ohne[$z] ?? 0) + 1;
        }
    }
    return $ohne && max($ohne) > 1;
}

/** "2200 m", "Waldgrenze"; null bleibt null */
function lawine_hoehe_text(?string $h): ?string
{
    if ($h === null) {
        return null;
    }
    return match (strtolower($h)) {
        'treeline' => 'Waldgrenze',
        default    => is_numeric($h) ? number_format((float)$h, 0, ',', '.') . ' m' : $h,
    };
}

/**
 * "3 erheblich über 2.200 m, 2 mäßig unter 2.200 m"; mit $nachAbzeichen (das Abzeichen
 * davor zeigt schon "Stufe 3"): "erheblich über 2.200 m, darunter Stufe 2 mäßig".
 * Teilt der Bericht nach Tageszeit (earlier/later, z. B. Nassschnee im Fruehjahr), stehen
 * seit 10.11.2026 beide Teile da: "vormittags 1 gering; nachmittags 2 mäßig über 1.600 m, ..."
 * (vorher fiel der Nachmittag weg, das Abzeichen zeigte aber dessen hoehere Stufe).
 */
function lawine_stufen_text(array $teile, bool $nachAbzeichen = false): string
{
    $gruppen = [];
    foreach ($teile as $t) {
        $z = $t['zeit'] ?? 'all_day';
        $gruppen[isset(LAWINE_ZEITEN[$z]) ? $z : 'all_day'][] = $t;
    }
    if (count($gruppen) > 1) {
        $out = [];
        foreach (LAWINE_ZEITEN as $z => $wort) {
            if (isset($gruppen[$z])) {
                $out[] = ($wort !== '' ? "$wort " : '') . lawine_stufen_text($gruppen[$z]);
            }
        }
        return implode('; ', $out);
    }
    usort($teile, fn($a, $b) => $b['stufe'] <=> $a['stufe']);
    $out = [];
    foreach ($teile as $t) {
        $name = $t['stufe'] ? $t['stufe'] . ' ' . LAWINE_STUFEN_NAMEN[$t['stufe']] : 'keine Einstufung';
        $wo = $t['ab'] !== null ? ' über ' . lawine_hoehe_text($t['ab']) : ($t['bis'] !== null ? ' unter ' . lawine_hoehe_text($t['bis']) : '');
        $out[] = $name . $wo;
    }
    $out = array_values(array_unique($out));
    // Mehrere Stufen ohne Hoehengrenze (gesehen bei Trentino, 17.4.2026): nicht raten, sagen
    $luecke = lawine_mehrdeutig($teile) ? ' (wo welche Stufe gilt, fehlt in den Daten; siehe Bericht)' : '';
    if ($nachAbzeichen && $out) {
        $out[0] = preg_replace('/^\d /', '', $out[0]);
        foreach (array_slice(array_keys($out), 1) as $i) {
            $out[$i] = preg_match('/^(\d) (.+) unter /u', $out[$i], $m) ? "darunter Stufe $m[1] $m[2]" : 'Stufe ' . $out[$i];
        }
    }
    return implode(', ', $out) . $luecke;
}

/** "Triebschnee (N, NO, O über 2000 m)" */
function lawine_problem_text(array $p): string
{
    $name = LAWINE_PROBLEME[$p['typ']] ?? $p['typ'];
    $asp = $p['aspekte'] && count($p['aspekte']) < 8 ? implode(', ', array_map(fn($a) => LAWINE_ASPEKTE_DE[$a], $p['aspekte'])) : ($p['aspekte'] ? 'alle Expositionen' : '');
    $wo = $p['ab'] !== null ? 'über ' . lawine_hoehe_text($p['ab']) : ($p['bis'] !== null ? 'unter ' . lawine_hoehe_text($p['bis']) : '');
    $zusatz = trim($asp . ($asp && $wo ? ' ' : '') . $wo);
    return $name . ($zusatz !== '' ? " ($zusatz)" : '');
}

/**
 * Lawinenprobleme fuer die Anzeige (seit 10.11.2026): gleiche Probleme (Typ, Expositionen,
 * Hoehe) aus Vormittag und Nachmittag nur einmal; gilt eines nur zu einer Tageszeit,
 * steht sie davor. Rueckgabe [['text' => "nachmittags: Nassschnee (...)", 'aspekte' => [...]], ...]
 */
function lawine_probleme_liste(array $probleme): array
{
    $sammel = [];
    foreach ($probleme as $p) {
        $k = $p['typ'] . '|' . implode(',', $p['aspekte']) . '|' . $p['ab'] . '|' . $p['bis'];
        $sammel[$k]['p'] ??= $p;
        $sammel[$k]['zeiten'][$p['zeit'] ?? 'all_day'] = true;
    }
    $out = [];
    foreach ($sammel as ['p' => $p, 'zeiten' => $z]) {
        $nur = count($z) === 1 && !isset($z['all_day']) ? (LAWINE_ZEITEN[array_key_first($z)] ?? '') : '';
        $out[] = ['text' => ($nur !== '' ? "$nur: " : '') . lawine_problem_text($p), 'aspekte' => $p['aspekte']];
    }
    return $out;
}

/**
 * Zeitpunkt eines Berichts fuer die Anzeige: "heute 17:00", "Mo 17:00" (Ortszeit $tz),
 * mehr als 6 Tage entfernt mit Datum. Leer, wenn der Zeitpunkt fehlt.
 */
function lawine_zeit_text(string $iso, DateTimeZone $tz, ?int $jetzt = null): string
{
    $t = $iso !== '' ? strtotime($iso) : false;
    if ($t === false) {
        return '';
    }
    $jetzt ??= time();
    $d = (new DateTimeImmutable('@' . $t))->setTimezone($tz);
    $heute = (new DateTimeImmutable('@' . $jetzt))->setTimezone($tz)->format('Y-m-d');
    $morgen = (new DateTimeImmutable('@' . ($jetzt + 86400)))->setTimezone($tz)->format('Y-m-d');
    $tag = match (true) {
        $d->format('Y-m-d') === $heute  => 'heute',
        $d->format('Y-m-d') === $morgen => 'morgen',
        abs($t - $jetzt) > 6 * 86400    => $d->format('j.n.'),
        default                         => ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int)$d->format('w')],
    };
    return $tag . ' ' . $d->format('G:i');
}

/** "ausgegeben heute 17:00, gültig bis morgen 17:00" */
function lawine_gueltig_text(array $lage, DateTimeZone $tz, ?int $jetzt = null): string
{
    $aus = lawine_zeit_text($lage['veroeffentlicht'] ?? '', $tz, $jetzt);
    $bis = lawine_zeit_text($lage['bis'] ?? '', $tz, $jetzt);
    return implode(', ', array_filter([$aus !== '' ? "ausgegeben $aus" : '', $bis !== '' ? "gültig bis $bis" : '']));
}

/**
 * Kennzeichnung der Aufbereitung (Hinweise des LWD Bayern, seit 10.11.2026): Auszug,
 * zusammengefasste Mikroregionen, Zuordnung ueber den Namen.
 */
function lawine_auszug_text(array $lage, array $dienst): string
{
    $namen = array_values(array_unique(array_map(fn($r) => $r[1] ?: $r[0], $lage['regionen'])));
    $t = 'Auszug aus dem Lagebericht (Quelle: ' . ($dienst['name'] ?? 'Warndienst') . '): nur Gefahrenstufe und Lawinenprobleme, ohne die Texte; von Wohinfahrma gekürzt und aufbereitet. '
       . 'Gilt für ' . implode(', ', $namen) . ($lage['ueber_namen'] ? ' (Zuordnung über den Namen)' : '') . '.';
    if (count($namen) > 1) {
        $t .= ' Bei mehreren Teilgebieten steht hier das mit der höchsten Stufe.';
    }
    return $t;
}

/** Kleine Expositionsrose als SVG (8 Sektoren, betroffene gefuellt), fuer das Lawinen-Abzeichen */
function lawine_rose(array $aspekte, int $px = 18): string
{
    $s = '';
    foreach (LAWINE_ASPEKTE as $i => $a) {
        $w1 = deg2rad($i * 45 - 22.5 - 90);
        $w2 = deg2rad($i * 45 + 22.5 - 90);
        $p = sprintf('M10,10 L%.2f,%.2f A9,9 0 0 1 %.2f,%.2f Z', 10 + 9 * cos($w1), 10 + 9 * sin($w1), 10 + 9 * cos($w2), 10 + 9 * sin($w2));
        $s .= '<path d="' . $p . '"' . (in_array($a, $aspekte, true) ? ' class="an"' : '') . '/>';
    }
    return '<svg class="rose" viewBox="0 0 20 20" width="' . $px . '" height="' . $px . '" aria-hidden="true">' . $s . '</svg>';
}
