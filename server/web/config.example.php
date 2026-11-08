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

    // Optional: Zwischenspeicher fuer den Verlauf ueber die Abrufe, ausserhalb
    // des Web-Roots und vom Webserver beschreibbar. Standard: <data_dir>/cache.
    // null = ohne Zwischenspeicher (rechnet bei jedem Aufruf neu, langsamer).
    // 'cache_dir' => '/usr/home/DEINUSER/umkreisdaten/cache',

    // Optional: Startseite (ohne Parameter) fuer Suchmaschinen freigeben.
    // Standard false = ueberall noindex. Erst einschalten, wenn Impressum
    // und Datenschutz geprueft sind. Alle anderen Ansichten bleiben noindex.
    // 'indexieren' => true,

    // Optional: Bestaetigungscode der Google Search Console fuer eine
    // URL-Praefix-Property (Methode "HTML-Tag": nur der Wert aus content="...").
    // Fuer eine Domain-Property (DNS-TXT-Eintrag) nicht noetig.
    // 'google_verifizierung' => 'abc123...',

    // Optional (seit 8.11.2026): Schnee-Katalog. Standard: lib/schneeregionen.json,
    // dorthin laedt die Action "Deploy" daten/schneeregionen.json.
    // 'schneeregionen_datei' => '/usr/home/DEINUSER/umkreis/schneeregionen.json',
    // Nur Cronjob: Modelle fuer den Schnee-Modus
    // 'schnee_modelle' => 'icon_d2,icon_eu,ecmwf_ifs,gfs_seamless',

    // Nur fuer den Sammler als Cronjob (server/sammler.php ohne --action):
    // 'modelle' => 'icon_d2,icon_eu,ecmwf_ifs,gfs_seamless,meteofrance_arome_france_hd',
    // 'openmeteo_limit' => 10000,
];
