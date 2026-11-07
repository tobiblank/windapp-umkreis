<?php
/**
 * Vorlage fuer config.php
 *
 * Kopieren nach config.php (gleicher Ordner) und ausfuellen.
 * config.php steht in .gitignore und darf nie committet werden.
 */

return [
    // Rohdaten-Ordner des Sammlers, AUSSERHALB des Web-Roots
    'data_dir' => '/usr/home/DEINUSER/umkreisdaten',

    // Regionen-Katalog (im Repo: daten/regionen.json)
    'regionen_datei' => '/usr/home/DEINUSER/umkreis/regionen.json',

    // Optional: Modelle fuer die Vorhersage, ein Abruf fuer alle Regionen.
    // Ausserhalb ihres Gebiets liefert Open-Meteo fuer ein Modell null, das ist
    // kein Fehler (ICON-D2 deckt etwa nur Mitteleuropa und die Alpen ab).
    // 'modelle' => 'icon_d2,icon_eu,ecmwf_ifs,gfs_seamless,meteofrance_arome_france_hd',

    // Optional: freie Open-Meteo-Aufrufe je Tag fuer die Warnung in der Cron-Mail.
    // Laufen ammerseewind.de und diese App vom selben Server, teilen sie sich
    // vermutlich das Kontingent (Begrenzung je IP) - dann hier entsprechend weniger.
    // 'openmeteo_limit' => 10000,
];
