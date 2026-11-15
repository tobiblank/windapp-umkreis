<?php
/**
 * Spot-Infos als HTML (seit 7.11.2026): aufklappbare Liste aus $region['infos'].
 * Die Eintraege stammen aus den Quellen im hinweis des Katalogs; Stand 7.11.2026,
 * nicht aktuell geprueft - das steht so auch auf der Seite.
 */

const INFO_NAMEN = ['niveau' => 'Niveau', 'zone' => 'Zone', 'sperre' => 'Sperre', 'saison' => 'Saison',
                    'gefahr' => 'Achtung', 'tipp' => 'Tipp'];
const INFO_REIHENFOLGE = ['sperre', 'zone', 'gefahr', 'niveau', 'saison', 'tipp'];

function spotinfo_html(array $region, bool $offen = false): string
{
    $infos = $region['infos'] ?? [];
    if (!$infos) {
        return '';
    }
    usort($infos, fn($a, $b) => array_search($a['art'], INFO_REIHENFOLGE, true) <=> array_search($b['art'], INFO_REIHENFOLGE, true));
    $e = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $o = '<details class="spotinfo klein"' . ($offen ? ' open' : '') . '><summary>Spot-Infos (' . count($infos) . ')'
       . (array_filter($infos, fn($i) => $i['art'] === 'sperre') ? ' <span class="marker sperre">Sperre</span>' : '') . '</summary><ul>';
    foreach ($infos as $i) {
        $o .= '<li><span class="marker info-' . $e($i['art']) . '">' . $e(INFO_NAMEN[$i['art']] ?? $i['art']) . '</span> ' . $e($i['text'])
            . (!empty($i['quelle']) ? ' <span class="leise">(' . $e($i['quelle']) . ')</span>' : '') . '</li>';
    }
    return $o . '</ul><p class="leise">Aus Spotführern und Behördenseiten übernommen (Quelle je Eintrag), nicht laufend geprüft. Vor Ort gelten die Regeln vor Ort.</p></details>';
}
