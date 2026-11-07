<?php
/**
 * Vorlage fuer config.php
 *
 * Kopieren nach config.php (gleicher Ordner) und ausfuellen.
 * config.php steht in .gitignore und darf nie committet werden.
 */

return [
    // Rohdaten-Ordner, AUSSERHALB des Web-Roots. Hier legt die GitHub Action
    // "Sammeln" die Daten ab (<HOME_DIR>/umkreisdaten). Absoluter Pfad.
    'data_dir' => '/usr/home/DEINUSER/umkreisdaten',

    // Optional: Regionen-Katalog. Standard: lib/regionen.json im Webordner,
    // dorthin laedt die Action "Deploy" daten/regionen.json.
    // 'regionen_datei' => '/usr/home/DEINUSER/umkreis/regionen.json',

    // Nur fuer den Sammler als Cronjob (server/sammler.php ohne --action):
    // 'modelle' => 'icon_d2,icon_eu,ecmwf_ifs,gfs_seamless,meteofrance_arome_france_hd',
    // 'openmeteo_limit' => 10000,
];
