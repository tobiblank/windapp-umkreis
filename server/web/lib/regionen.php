<?php
/**
 * Regionen-Katalog laden und pruefen (daten/regionen.json).
 *
 * Aufbau siehe README, Abschnitt "Regionen-Katalog". Kurz:
 *   Region: id, name, typ (see|meer), punkt [lat, lon], zeitzone,
 *           windlagen [{name, sektor [von, bis], charakter?}], spots [...]
 *   Spot:   id, name, punkt?, windlagen? (ersetzt die der Region),
 *           ablandig? [[von, bis], ...], hinweis?
 * Sektoren in Grad, aus welcher Richtung der Wind kommt, von inklusive,
 * bis exklusive, im Uhrzeigersinn; [330, 30] geht ueber Norden.
 */

function regionen_laden(string $datei): array
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
    return $d['regionen'];
}

/** Liste von Fehlermeldungen, leer = in Ordnung */
function regionen_pruefen(array $d): array
{
    $f = [];
    $ids = [];
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
        foreach (windlagen_pruefen($r['windlagen'] ?? null) as $m) {
            $f[] = "$wo: $m";
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
                foreach (windlagen_pruefen($s['windlagen']) as $m) {
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

function windlagen_pruefen($wl): array
{
    if (!is_array($wl)) {
        return ['windlagen muss eine Liste sein (darf leer sein)'];
    }
    $f = [];
    foreach ($wl as $w) {
        if (empty($w['name'])) {
            $f[] = 'Windlage ohne name';
        }
        if (!sektor_ok($w['sektor'] ?? null)) {
            $f[] = 'Windlage ' . ($w['name'] ?? '?') . ': sektor muss [von, bis] in 0-360 Grad sein';
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
