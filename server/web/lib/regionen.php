<?php
/**
 * Regionen-Katalog laden und pruefen (daten/regionen.json).
 *
 * Aufbau siehe README, Abschnitt "Regionen-Katalog". Kurz:
 *   Region: id, name, typ (see|meer), punkt [lat, lon], zeitzone,
 *           windlagen [{name, sektor [von, bis], charakter?}], spots [...],
 *           links? [{name, url}] (lokale Spezialprognose, nur verlinkt),
 *           thermik? true (Modellwind unterschaetzt den Wind vermutlich; auf der Seite markiert)
 *           alplakes? "ammersee": Schluessel des Simstrat-Seemodells bei Alplakes (Eawag),
 *           nur bei Seen; der Sammler holt damit die Wassertemperatur (seit 8.11.2026)
 *           anfahrt? [lat, lon] (Ziel fuer die Fahrzeit, z. B. Parkplatz am Spot; sonst
 *           punkt des ersten Spots, sonst der Vorhersagepunkt; seit 7.11.2026)
 *           anreise? ["auto"] (Standard) | ["flug"] | ["auto", "flug"]: in welcher
 *           Ansicht die Region erscheint (seit 7.11.2026)
 *           flughafen? {name, transfer_min?}: naechster sinnvoller Flughafen, Transfer
 *           zum Spot in Minuten; fuer Flugziele
 *           infos? [{art, text, quelle?, monate?, sportarten?}]: Spot-Infos (seit 7.11.2026),
 *           art aus INFO_ARTEN. Eine sperre mit monate [von, bis] und sportarten
 *           (Gruppen aus SPORTARTEN, z. B. "kite") sperrt diese Tage in der Bewertung.
 *   Spot:   id, name, punkt?, windlagen? (ersetzt die der Region),
 *           ablandig? [[von, bis], ...], hinweis?
 * Sektoren in Grad, aus welcher Richtung der Wind kommt, von inklusive,
 * bis exklusive, im Uhrzeigersinn; [330, 30] geht ueber Norden.
 *
 * Regel-Windlagen (seit 7.11.2026) haben zusaetzlich:
 *   druck          [{plus, minus, min?, max?}]  Druckdifferenz plus - minus in hPa,
 *                  plus/minus sind Schluessel aus druckpunkte (oberste Ebene)
 *   grundstroemung {hoehe: 10m|850hPa|700hPa, sektor?, kn_min?, kn_max?}
 *   wetter         "sonnig" (mittlere Bewoelkung hoechstens 50 %)
 *   zeit           [ab, bis) Ortsstunden, saison [von, bis] Monate (1-12)
 *   staerke        [von, bis] kn: typische Staerke, wenn die Lage zutrifft (Pflicht)
 * Oberste Ebene: druckpunkte {id: {name, punkt}}.
 */

const HOEHEN = ['10m', '850hPa', '700hPa'];
const ANREISEN = ['auto', 'flug'];
const INFO_ARTEN = ['niveau', 'zone', 'sperre', 'saison', 'gefahr', 'tipp'];

/** Anreisearten einer Region, Standard nur Auto */
function region_anreise(array $r): array
{
    return $r['anreise'] ?? ['auto'];
}

/** Ganzer Katalog (Regionen und Druckpunkte), geprueft */
function katalog_laden(string $datei): array
{
    $txt = @file_get_contents($datei);
    if ($txt === false) {
        throw new RuntimeException("Regionen-Datei fehlt: $datei");
    }
    $d = json_decode($txt, true);
    if (!is_array($d) || !isset($d['regionen']) || !is_array($d['regionen'])) {
        throw new RuntimeException("Regionen-Datei ist kein gueltiges JSON mit 'regionen': $datei");
    }
    $fehler = regionen_pruefen($d);
    if ($fehler) {
        throw new RuntimeException("Regionen-Datei fehlerhaft:\n- " . implode("\n- ", $fehler));
    }
    $d['druckpunkte'] ??= [];
    return $d;
}

function regionen_laden(string $datei): array
{
    return katalog_laden($datei)['regionen'];
}

/** Liste von Fehlermeldungen, leer = in Ordnung */
function regionen_pruefen(array $d): array
{
    $f = [];
    $ids = [];
    $druckpunkte = $d['druckpunkte'] ?? [];
    if (!is_array($druckpunkte)) {
        $f[] = 'druckpunkte muss ein Objekt sein';
        $druckpunkte = [];
    }
    foreach ($druckpunkte as $id => $p) {
        if (!preg_match('/^[a-z0-9-]+$/', (string)$id) || empty($p['name']) || !punkt_ok($p['punkt'] ?? null)) {
            $f[] = "Druckpunkt $id: braucht id (a-z, 0-9, -), name und punkt";
        }
    }
    foreach ($d['regionen'] ?? [] as $n => $r) {
        $wo = "Region " . ($r['id'] ?? "#$n");
        $id = $r['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-z0-9-]+$/', $id)) {
            $f[] = "$wo: id fehlt oder enthaelt andere Zeichen als a-z, 0-9, -";
        } elseif (isset($ids[$id])) {
            $f[] = "$wo: id doppelt";
        }
        $ids[$id] = true;
        if (empty($r['name']) || !is_string($r['name'])) {
            $f[] = "$wo: name fehlt";
        }
        if (!in_array($r['typ'] ?? null, ['see', 'meer'], true)) {
            $f[] = "$wo: typ muss 'see' oder 'meer' sein";
        }
        if (!punkt_ok($r['punkt'] ?? null)) {
            $f[] = "$wo: punkt muss [Breite, Laenge] sein";
        }
        if (!in_array($r['zeitzone'] ?? '', timezone_identifiers_list(), true)) {
            $f[] = "$wo: zeitzone unbekannt";
        }
        foreach (windlagen_pruefen($r['windlagen'] ?? null, $druckpunkte) as $m) {
            $f[] = "$wo: $m";
        }
        foreach ($r['infos'] ?? [] as $k => $info) {
            if (!in_array($info['art'] ?? null, INFO_ARTEN, true) || !is_string($info['text'] ?? null) || $info['text'] === ''
                || (isset($info['quelle']) && !is_string($info['quelle']))) {
                $f[] = "$wo: infos[$k] braucht art (" . implode(', ', INFO_ARTEN) . ') und text';
            }
            if (isset($info['monate']) && (!is_array($info['monate']) || count($info['monate']) !== 2
                || array_filter($info['monate'], fn($m) => !is_int($m) || $m < 1 || $m > 12))) {
                $f[] = "$wo: infos[$k].monate muss [von, bis] mit Monaten 1-12 sein";
            }
            if (isset($info['sportarten']) && (!is_array($info['sportarten']) || array_filter($info['sportarten'], fn($g) => !is_string($g)))) {
                $f[] = "$wo: infos[$k].sportarten muss eine Liste von Gruppen sein";
            }
        }
        if (isset($r['anreise']) && (!is_array($r['anreise']) || !$r['anreise']
            || array_diff($r['anreise'], ANREISEN) || count(array_unique($r['anreise'])) !== count($r['anreise']))) {
            $f[] = "$wo: anreise muss eine Liste aus " . implode(', ', ANREISEN) . ' sein';
        }
        if (isset($r['flughafen']) && (!is_array($r['flughafen']) || !is_string($r['flughafen']['name'] ?? null) || $r['flughafen']['name'] === ''
            || (isset($r['flughafen']['transfer_min']) && (!is_int($r['flughafen']['transfer_min']) || $r['flughafen']['transfer_min'] < 0)))) {
            $f[] = "$wo: flughafen braucht name und optional transfer_min (ganze Minuten)";
        }
        if (isset($r['anfahrt']) && !punkt_ok($r['anfahrt'])) {
            $f[] = "$wo: anfahrt muss [Breite, Laenge] sein";
        }
        if (isset($r['thermik']) && !is_bool($r['thermik'])) {
            $f[] = "$wo: thermik muss true oder false sein";
        }
        if (isset($r['alplakes']) && (($r['typ'] ?? '') !== 'see' || !is_string($r['alplakes']) || !preg_match('/^[a-z0-9]+$/', $r['alplakes']))) {
            $f[] = "$wo: alplakes nur bei Seen, als Schluessel des Simstrat-Modells (a-z, 0-9)";
        }
        foreach ($r['links'] ?? [] as $l) {
            if (empty($l['name']) || !is_string($l['url'] ?? null) || !preg_match('#^https://[^\s"<>]+$#', $l['url'])) {
                $f[] = "$wo: links braucht name und eine https-Adresse";
            }
        }
        $spotIds = [];
        foreach ($r['spots'] ?? [] as $k => $s) {
            $sw = "$wo, Spot " . ($s['id'] ?? "#$k");
            $sid = $s['id'] ?? '';
            if (!is_string($sid) || !preg_match('/^[a-z0-9-]+$/', $sid)) {
                $f[] = "$sw: id fehlt oder enthaelt andere Zeichen als a-z, 0-9, -";
            } elseif (isset($spotIds[$sid])) {
                $f[] = "$sw: id doppelt";
            }
            $spotIds[$sid] = true;
            if (empty($s['name']) || !is_string($s['name'])) {
                $f[] = "$sw: name fehlt";
            }
            if (isset($s['punkt']) && !punkt_ok($s['punkt'])) {
                $f[] = "$sw: punkt muss [Breite, Laenge] sein";
            }
            if (array_key_exists('windlagen', $s)) {
                foreach (windlagen_pruefen($s['windlagen'], $druckpunkte) as $m) {
                    $f[] = "$sw: $m";
                }
            }
            foreach ($s['ablandig'] ?? [] as $sek) {
                if (!sektor_ok($sek)) {
                    $f[] = "$sw: ablandig enthaelt einen ungueltigen Sektor";
                }
            }
        }
    }
    return $f;
}

function windlagen_pruefen($wl, array $druckpunkte = []): array
{
    if (!is_array($wl)) {
        return ['windlagen muss eine Liste sein (darf leer sein)'];
    }
    $f = [];
    foreach ($wl as $w) {
        $n = 'Windlage ' . ($w['name'] ?? '?');
        if (empty($w['name'])) {
            $f[] = 'Windlage ohne name';
        }
        if (!sektor_ok($w['sektor'] ?? null)) {
            $f[] = "$n: sektor muss [von, bis] in 0-360 Grad sein";
        }
        if (isset($w['druck'])) {
            if (!is_array($w['druck']) || !$w['druck']) {
                $f[] = "$n: druck muss eine nicht leere Liste sein";
            } else {
                foreach ($w['druck'] as $d) {
                    if (!isset($druckpunkte[$d['plus'] ?? '']) || !isset($druckpunkte[$d['minus'] ?? ''])) {
                        $f[] = "$n: druck braucht plus und minus aus druckpunkte";
                    }
                    if (!isset($d['min']) && !isset($d['max'])) {
                        $f[] = "$n: druck braucht min oder max (hPa)";
                    }
                    foreach (['min', 'max'] as $k) {
                        if (isset($d[$k]) && !is_numeric($d[$k])) {
                            $f[] = "$n: druck.$k muss eine Zahl sein";
                        }
                    }
                }
            }
        }
        if (isset($w['grundstroemung'])) {
            $g = $w['grundstroemung'];
            if (!in_array($g['hoehe'] ?? null, HOEHEN, true)) {
                $f[] = "$n: grundstroemung.hoehe muss " . implode(', ', HOEHEN) . ' sein';
            }
            if (isset($g['sektor']) && !sektor_ok($g['sektor'])) {
                $f[] = "$n: grundstroemung.sektor ungueltig";
            }
            if (!isset($g['sektor']) && !isset($g['kn_min']) && !isset($g['kn_max'])) {
                $f[] = "$n: grundstroemung braucht sektor, kn_min oder kn_max";
            }
        }
        if (isset($w['wetter']) && $w['wetter'] !== 'sonnig') {
            $f[] = "$n: wetter kennt nur \"sonnig\"";
        }
        if (isset($w['zeit']) && !(is_array($w['zeit']) && count($w['zeit']) === 2
                && is_int($w['zeit'][0]) && is_int($w['zeit'][1]) && $w['zeit'][0] >= 0 && $w['zeit'][1] <= 24 && $w['zeit'][0] < $w['zeit'][1])) {
            $f[] = "$n: zeit muss [ab, bis] in vollen Stunden 0-24 sein";
        }
        if (isset($w['saison']) && !(is_array($w['saison']) && count($w['saison']) === 2
                && is_int($w['saison'][0]) && is_int($w['saison'][1])
                && $w['saison'][0] >= 1 && $w['saison'][0] <= 12 && $w['saison'][1] >= 1 && $w['saison'][1] <= 12)) {
            $f[] = "$n: saison muss [von, bis] in Monaten 1-12 sein";
        }
        $regel = isset($w['druck']) || isset($w['grundstroemung']);
        if ($regel && !(is_array($w['staerke'] ?? null) && count($w['staerke']) === 2
                && is_numeric($w['staerke'][0]) && is_numeric($w['staerke'][1]) && $w['staerke'][0] <= $w['staerke'][1])) {
            $f[] = "$n: Regel-Windlage braucht staerke [von, bis] in kn";
        }
        if (!$regel && (isset($w['staerke']) || isset($w['wetter']) || isset($w['zeit']) || isset($w['saison']))) {
            $f[] = "$n: staerke, wetter, zeit und saison gibt es nur bei Regel-Windlagen (mit druck oder grundstroemung)";
        }
    }
    return $f;
}

function punkt_ok($p): bool
{
    return is_array($p) && count($p) === 2
        && is_numeric($p[0]) && is_numeric($p[1])
        && $p[0] >= -90 && $p[0] <= 90 && $p[1] >= -180 && $p[1] <= 180;
}

function sektor_ok($s): bool
{
    return is_array($s) && count($s) === 2
        && is_numeric($s[0]) && is_numeric($s[1])
        && $s[0] >= 0 && $s[0] <= 360 && $s[1] >= 0 && $s[1] <= 360
        && $s[0] != $s[1];
}
