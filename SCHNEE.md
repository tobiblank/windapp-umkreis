# Wohinfahrma – Schnee

*Gebaut am 8.11.2026, **noch nie gegen echte Quellen gelaufen** (nur gegen `tests/fake_openmeteo.php`).* Ergänzung zu `README.md`.

Zweiter Modus in Wohinfahrma für Skitourengeher und Freerider, nur Alpen: Wo liegt in den nächsten Tagen frischer Schnee, und wo wird es schön? Dazu Schneehöhe, Neuschnee, Wetter und – immer sichtbar, aber nicht in der Wertung – die Lawinenwarnstufe.

## Grundsatz Lawine

Die App sagt „wo liegt guter Schnee“, nie „wo ist es sicher“. Die Warnstufe geht **nicht** in Prozent, Sortierung oder Farbe der Bewertung ein. Sie steht als eigenes Abzeichen in den offiziellen EAWS-Farben daneben, mit Link zum Bericht und Ausgabezeit, nur für Tage mit gültigem Bericht (meist heute und morgen). Keine eigene Hochrechnung. Die Bewertung nutzt deshalb eine **Blau-Skala** statt Grün/Gelb/Rot. Fester Hinweis oben auf der Seite, je Region, im Teilen-Text, auf der Stundenseite und im Impressum: „Die Lawinenlage stammt vom Warndienst. Sie ersetzt keine Beurteilung vor Ort. Wohinfahrma bewertet nur Schnee und Wetter.“

## Stand

| Baustein | Status |
|---|---|
| Seite `server/web/schnee.php` | Sportart (Freeride, Skitour), Umkreis 100–800 km Straße, Standort, Sortierung (alle Tage, ein Tag, Fahrzeit), Ansichten Liste (Heute – Übermorgen, Tag 3 – 7) und Woche, Karte mit blauen Punkten und Männchen (ohne Animation), Kacheln mit Chance, Neuschnee und Lawinen-Abzeichen, Detailzeile, Abfahrt, Teilen, geteilte Ansicht `?region=…&nach=…`. `noindex` |
| Stundenseite `server/web/schnee_stunden.php` | Neuschnee je Stunde (72 h bis 8 Uhr und der Tag), Schneefallgrenze und Nullgradgrenze gegen Tour- und Talhöhe, Wetter im Fenster, Streuung des Ensembles, Lawinenlage mit Expositionsrose |
| Schalter Wind · Schnee | im Kopf beider Seiten; es sind zwei Seiten, nicht ein Formular. Die Wind-Seite merkt sich ihre Wahl wie bisher, die Schnee-Seite merkt sich nichts im Browser |
| Katalog `daten/schneeregionen.json` | 9 Regionen ab Inning: Werdenfels, Allgäu/Oberstdorf, Kühtai/Sellrain, Stubai, Zillertal/Tux, Arlberg, Ötztal/Obergurgl, Ahrntal, Oberengadin. Punkte, Tour- und Talhöhen geschätzt. Prüfung `schneeregionen_pruefen()` |
| Bewertung `server/web/lib/schnee.php` | siehe „Bewertung“ |
| Lagebericht `server/web/lib/lawine.php` | liest CAAML v6 als JSON und XML, je Mikroregion Stufe (auch über/unter Höhengrenze), Probleme mit Expositionen und Höhe |
| Sammler | Quellen `schnee_vorhersage`, `schnee_ensemble`, `lawine` mit `--schnee <katalog>`; `--nur a,b` für einzelne Quellen |
| Action `sammeln.yml` | wie bisher 5:25 und 17:25 UTC, dazu 7:40 UTC nur `lawine` und `schnee_vorhersage` (Morgen-Update der Berichte) |
| Fahrzeiten | `fahrzeiten.php` nimmt den Schnee-Katalog als zweites Argument; gemeinsame `fahrzeiten.json`, ids müssen in beiden Katalogen verschieden sein (Test prüft das) |
| Deploy | lädt `daten/schneeregionen.json` als `lib/schneeregionen.json` hoch |
| Impressum, Datenschutz | Quellen ergänzt (SLF, Lawinen.report), Haftungssatz |
| Helfer `server/lawine_regionen.php` | `php server/lawine_regionen.php <data_dir> [suchtext]` listet regionID und Name aus den gesammelten Berichten |
| Tests | `tests/test_schnee.php` (am Ende von `test.php`): Katalog, Neuschnee je Stunde, CAAML JSON/XML, Sammler mit Freigabe und 404, Bewertung, Lage je Region, Fahrzeiten, Seiten |

Nicht gebaut: Verlauf über die Abrufe, Animation des Männchens, Mikroregionen als Polygone auf der Karte, feinere Alpen-Karte, Messstationen für die Schneehöhe, Roadtrip.

## Sportarten

| Sportart | Was zählt (Startwerte ohne Quelle, `SCHNEE_SPORTARTEN`) |
|---|---|
| Freeride | Neuschnee 72 h ≥ 15 cm, Bewölkung ≤ 40 %, Wind am Kamm ≤ 30 km/h |
| Skitour | Schneehöhe (Modell) ≥ 30 cm, Bewölkung ≤ 50 %, Wind am Kamm ≤ 40 km/h |

## Bewertung

Je Region und Tag:

- **Neuschnee:** Summe der letzten 72 h bis 8 Uhr Ortszeit. Je Stunde zählt der Niederschlag als Schnee (1 mm = 0,7 cm, wie Open-Meteo), wenn die Schneefallgrenze unter der Tourhöhe liegt. Schneefallgrenze = `snowfall_height`, sonst Nullgradgrenze − 300 m, sonst Temperatur auf Tourhöhe ≤ 1 °C. Ohne Niederschlag zählt `snowfall`. Fehlen mehr als 12 der 72 Stunden, ist der Neuschnee unbekannt.
- **Wetter am Tag** (8–15 Uhr): mittlere Bewölkung, Wind in 700 hPa (km/h), höchste Nullgradgrenze, Neuschnee am Tag, Böen im Tal.
- **Schneehöhe:** Modellwert `snow_depth` um 8 Uhr, angezeigt als „(Modell)“.
- **Chance:** Anteil der 51 Läufe des ECMWF-Ensembles, an denen der Tag nach denselben Regeln passt, ohne Wind am Kamm.
- **Heute** nach 13:30 Uhr Ortszeit „vorbei“ (weniger als 2 Stunden Fenster), wie im Wind-Modus.
- **Rangliste:** erwartete gute Tage (Summe der Chancen), dann Tage nach Modellmittel, dann Neuschnee.

Abgerufen wird auf **Tourhöhe** (`elevation` je Ort) und mit `past_days=3`. Modelle: `icon_d2,icon_eu,ecmwf_ifs,gfs_seamless` (`schnee_modelle` in `config.php` nur für den Cronjob).

## Lawinenlage

Warndienste stehen im Katalog unter `lawinendienste`:

| Feld | Bedeutung |
|---|---|
| `abruf` | URL, `{datum}` = Tag, für den der Bericht gilt; ohne `{datum}` gilt der eine Bericht für heute und morgen (SLF) |
| `format` | `json` oder `xml` |
| `praefixe` | Mikroregionen dieses Diensts (Anfang der regionID) |
| `freigegeben` | Präfixe, die gezeigt werden dürfen. Der Sammler ruft nur Dienste mit Freigabe ab und speichert nur freigegebene Mikroregionen. Ohne Freigabe zeigt die Seite einen Link zum Bericht |
| `name`, `kurz`, `url_bericht`, `lizenz` | Anzeige und Quellenangabe |

Je Region: `warndienst`, `eaws` (Präfixe der regionID) und/oder `eaws_namen` (Teil des Namens im Bericht, nur wenn `eaws` leer ist; dann steht „Zuordnung über den Namen“ dabei). Bei mehreren Mikroregionen gilt die höchste Stufe. Gezeigt wird der Bericht, der um 12 Uhr Ortszeit des Tages gilt, aus der neuesten Ausgabe.

Der Sammler holt je Dienst heute und morgen. HTTP 404 heißt „noch kein Bericht“ (morgen vor 17 Uhr, außerhalb der Saison) und ist kein Fehler. Gespeichert wird eine Zeile je Dienst: `{"ts", "source": "lawine", "ok", "dienst", "tage": {datum: [berichte]|null}}`.

### Freigabe am 8.11.2026

| Dienst | Abruf | Lizenz | Freigegeben |
|---|---|---|---|
| SLF | `aws.slf.ch/api/bulletin/caaml/de/json` (laut API-Seite: gerade gültiger Bericht, CAAML v6) | CC BY 4.0, Quellenangabe mit Link | `CH-` |
| Euregio | `static.avalanche.report/bulletins/{datum}/{datum}_EUREGIO_de_CAAMLv6.json` (Schema aus dem Verzeichnis von 2019; Host für 2026 ungeprüft) | Südtirol CC BY, Trentino CC BY 4.0; Tirol, Kärnten offen | `IT-32-BZ`, `IT-32-TN` |
| Bayern | `static.lawinen-warnung.eu/bulletins/{datum}/{datum}_DE-BY_de_CAAMLv6.xml` (Struktur an echter Datei vom 20.12.2025 gesehen) | offen | – |

Anfragen an LWD Tirol und LWD Bayern laufen; nach Antwort nur `freigegeben` ergänzen (`AT-07` bzw. `DE-BY`). Anmeldung beim SLF (data@slf.ch, Verteiler „Avalanche Bulletin“) empfohlen.

### Mikroregionen

Nur Bayern hat echte IDs: Werdenfels `DE-BY-30` („Werdenfelser Alpen“), Allgäu `DE-BY-12` („Allgäuer Alpen Mitte“, ob Oberstdorf ganz darin liegt, ungeprüft). Alle anderen über `eaws_namen` (geschätzt). Nach dem ersten Lauf mit `php server/lawine_regionen.php <data_dir> Engadin` die IDs nachsehen und als `eaws` eintragen.

## Beim ersten echten Lauf prüfen

1. Action „Sammeln“ von Hand starten, Ausgabe von `--debug` ansehen: `schnee_vorhersage`, `schnee_ensemble`, `lawine/slf`, `lawine/euregio` grün?
2. Nimmt Open-Meteo `elevation` als Liste je Ort an? Liefern die Modelle `snowfall_height`, das Ensemble `snow_depth`, `freezing_level_height` und `past_days`? Wenn eine Variable einen Fehler auslöst, aus der Abfrage in `quellen_schnee()` nehmen.
3. Plausibel: Neuschnee und Schneehöhe mit lawinen.report bzw. slf.ch vergleichen. Die Schneehöhe aus dem Modell ist im Gebirge vermutlich zu niedrig.
4. Euregio-Pfad: kommt eine Datei, oder 404 auch für heute? Dann den Dateinamen im Verzeichnis `static.avalanche.report/bulletins/<datum>/` nachsehen.
5. SLF: Stimmt die Zuordnung „Oberengadin“? IDs eintragen.
6. Speicher und Rechenzeit der Seite (9 Regionen, Ensemble mit 11 Tagen).

## Datenquellen (Prüfung 8.11.2026)

| Bereich | Quelle | Lizenz | Stand |
|---|---|---|---|
| Lagebericht Schweiz | SLF, `aws.slf.ch/api/bulletin/caaml` | CC BY 4.0 | geprüft |
| Lagebericht Euregio | Albina, `static.avalanche.report` | Südtirol, Trentino CC BY; Tirol, Kärnten offen | teils |
| Lagebericht Bayern | `static.lawinen-warnung.eu`, CAAML v6 XML | offen | Feed gefunden |
| Übrige Länder, AINEVA, Frankreich | – | offen bzw. eingeschränkt | später |
| Schneehöhe Stationen | SLF `measurement-api.slf.ch` (CC BY 4.0), GeoSphere SNOWGRID (CC BY 4.0) | | nicht eingebaut |
| Schnee und Wetter | Open-Meteo | CC BY 4.0 | Variablen dokumentiert, Verhalten im Gebirge ungeprüft |

Für die Rechnung nur Stufe und strukturierte Felder; Texte der Berichte nicht kürzen oder umschreiben, sondern verlinken.

## Nächste Schritte

1. Erster echter Lauf (siehe oben), danach Mikroregion-IDs eintragen
2. Antworten der Warndienste: Bayern, Tirol freigeben
3. Schwellen und Tourhöhen aus eigener Erfahrung anpassen
4. Messstationen für die Schneehöhe (SLF, GeoSphere)
5. Verlauf über die Abrufe, Animation des Männchens wie im Wind-Modus
6. Karte: Alpen-Ausschnitt feiner, Mikroregionen als Polygone (EAWS-Regionen, Lizenz im Repo prüfen)
7. Slogan für beide Modi
