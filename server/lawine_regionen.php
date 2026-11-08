<?php
/**
 * Wohinfahrma - Mikroregionen in den gesammelten Lageberichten (seit 8.11.2026)
 *
 *     php server/lawine_regionen.php <data_dir> [suchtext]
 *
 * Listet regionID und Name aus den neuesten Berichten je Warndienst, damit man die
 * IDs fuer "eaws" in daten/schneeregionen.json nachsehen kann. Nur freigegebene
 * Mikroregionen stehen in den Dateien (der Sammler speichert keine anderen).
 */
require __DIR__ . '/web/lib/lawine.php';

if (!isset($argv[1]) || !is_dir($argv[1])) {
    fwrite(STDERR, "Aufruf: php lawine_regionen.php <data_dir> [suchtext]\n");
    exit(2);
}
$suche = $argv[2] ?? '';
foreach (lawine_laden($argv[1]) as $dienst => $l) {
    $alle = [];
    foreach ($l['bulletins'] as $b) {
        foreach ($b['regionen'] as [$id, $name]) {
            $alle[$id] = $name;
        }
    }
    ksort($alle);
    echo "$dienst (Stand {$l['ts']}):\n";
    foreach ($alle as $id => $name) {
        if ($suche === '' || mb_stripos("$id $name", $suche) !== false) {
            printf("  %-16s %s\n", $id, $name);
        }
    }
}
