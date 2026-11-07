<?php
/**
 * Wohinfahrma - Web-App-Manifest (zum Startbildschirm hinzufuegen / installieren)
 *
 * Als PHP statt .webmanifest, damit der Content-Type stimmt, ohne dass der
 * Webserver die Endung kennen muss (wie in stegen-wind).
 * Alle Adressen relativ: funktioniert unter wohinfahrma.dablank.de wie unter
 * einer eigenen Domain. Eine installierte App haengt aber an der Adresse, unter
 * der sie installiert wurde - nach einem Domainwechsel neu installieren.
 * short_name steht unter dem Symbol auf dem Startbildschirm.
 */

declare(strict_types=1);

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');

echo json_encode([
    'id'               => './',
    'name'             => 'Wohinfahrma',
    'short_name'       => 'Fahrma',
    'description'      => 'Wo ist Wind? Rangliste der Windsport-Regionen im Umkreis',
    'lang'             => 'de',
    'start_url'        => './',
    'scope'            => './',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#0F1E24',
    'theme_color'      => '#0F1E24',
    'icons'            => [
        ['src' => 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
