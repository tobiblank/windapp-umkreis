# Wohinfahrma – Schnee

*Gebaut am 8.11.2026, **noch nie gegen echte Quellen gelaufen** (nur gegen `tests/fake_openmeteo.php`).* Ergänzung zu `README.md`.

Zweiter Modus in Wohinfahrma für Skitourengeher und Freerider, nur Alpen: Wo liegt in den nächsten Tagen frischer Schnee, und wo wird es schön? Dazu Schneehöhe, Neuschnee, Wetter und – immer sichtbar, aber nicht in der Wertung – die Lawinenwarnstufe.

## Grundsatz Lawine

Die App sagt „wo liegt guter Schnee“, nie „wo ist es sicher“. Die Warnstufe geht **nicht** in Sortierung oder Farbe der Bewertung ein. Sie steht als eigenes Abzeichen in den offiziellen EAWS-Farben daneben (Kachel „LWS 3“, im Lawinenblock und auf der Stundenseite „Gefahrenstufe 3“ wie im Bericht), mit Link zum Bericht und Ausgabezeit, nur für Tage mit gültigem Bericht (meist heute und morgen). Keine eigene Hochrechnung. Die Bewertung nutzt deshalb eine **Blau-Skala** statt Grün/Gelb/Rot. Fester Hinweis oben auf der Seite, je Region, im Teilen-Text, auf der Stundenseite und im Impressum: „Die Lawinenlage stammt vom Warndienst. Sie ersetzt keine Beurteilung vor Ort. Wohinfahrma bewertet nur Schnee und Wetter.“

## Stand

| Baustein | Status |
|---|---|
| Seite `server/web/schnee.php` | Sportart (Freeride, Skitour), Umkreis 100–800 km Straße, Standort, Sortierung (alle Tage, ein Tag, Fahrzeit), Ansichten Liste (Heute – Übermorgen, Tag 3 – 7) und Woche, Karte mit Punkten in einer Neuschnee-Farbskala (seit 9.11.2026: grau, hellblau, blau, violett, magenta ab 50 cm, `SCHNEESKALA` in `lib/karte.php`, mit weichem Farbfeld je Region und Legende; Zahl: Neuschnee im Zeitraum bzw. am gewählten Tag in cm; Ring = passt) und Männchen (ohne Animation), Kacheln mit allem auf einen Blick: Wetter (☀ ⛅ ☁, ❄ bei Schneefall am Tag), Neuschnee je Tag (24 h bis 8 Uhr, seit 9.11.2026), Schneehöhe, LWS, darunter klein die Spanne aus dem Ensemble; Wind und Gründe im Tooltip und auf der Stundenseite. Darunter nur für gute Tage Abfahrt, Details und Teilen; Lawinenlage einklappbar, geteilte Ansicht `?region=…&nach=…`. `noindex` |
| Stundenseite `server/web/schnee_stunden.php` | Seit 9.11.2026 neu aufgebaut (vorher Textblock, Bergsilhouette, vier Höhenlinien; unübersichtlich): oben das Urteil „Passt (nicht) für …“ mit allen fehlenden Gründen, darunter jede Regel der Sportart einzeln mit Wert und Schwelle (`schnee_kriterien()`), dann Kennzahlen als Kacheln (Neuschnee 24 h mit Spanne des Ensembles, Schneefallgrenze, Nullgrad, Schneehöhe, Himmel, Böen). Ein Diagramm mit einer Zeitachse: Niederschlag je Stunde auf Tourhöhe, blau als Schnee, grau als Regen, in Abschnitten von 8 bis 8 Uhr mit der Summe darüber (wie die Tagesknöpfe), Strich „jetzt“; darunter nur die Schneefallgrenze gegen Tour- und Talhöhe. Lawinenlage mit Expositionsrose |
| Schalter Wind · Schnee | im Kopf beider Seiten; es sind zwei Seiten, nicht ein Formular. Die Wind-Seite merkt sich ihre Wahl wie bisher, die Schnee-Seite merkt sich nichts im Browser |
| Katalog `daten/schneeregionen.json` | 9 Regionen ab Inning: Werdenfels, Allgäu/Oberstdorf, Kühtai/Sellrain, Stubai, Zillertal/Tux, Arlberg, Ötztal/Obergurgl, Ahrntal, Oberengadin. Seit 9.11.2026 dazu Kitzbüheler Alpen, Osttirol (Kals, Matrei), Gastein/Rauris (Salzburg), Nassfeld/Karnische Alpen (Kärnten), Schladminger Tauern/Sölktäler (Steiermark), danach Salzburg aufgeteilt (Obertauern, Lungau, Saalbach/Glemmtal, Hochkönig zusätzlich zu Gastein/Rauris): 18 Regionen; Mikroregion-IDs aus der Tiroler Stationsdatei bzw. aus EAWS-Berichten vom April 2026. Punkte, Tour- und Talhöhen geschätzt. Prüfung `schneeregionen_pruefen()` |
| Bewertung `server/web/lib/schnee.php` | siehe „Bewertung“ |
| Lagebericht `server/web/lib/lawine.php` | liest CAAML v6 als JSON und XML, je Mikroregion Stufe (auch über/unter Höhengrenze), Probleme mit Expositionen und Höhe |
| Sammler | Quellen `schnee_vorhersage`, `schnee_ensemble`, `lawine` mit `--schnee <katalog>`; `--nur a,b` für einzelne Quellen |
| Action `sammeln.yml` | wie bisher 5:25 und 17:25 UTC, dazu 7:40 UTC nur `lawine` und `schnee_vorhersage` (Morgen-Update der Berichte) |
| Fahrzeiten | `fahrzeiten.php` nimmt den Schnee-Katalog als zweites Argument; gemeinsame `fahrzeiten.json`, ids müssen in beiden Katalogen verschieden sein (Test prüft das) |
| Deploy | lädt `daten/schneeregionen.json` als `lib/schneeregionen.json` hoch |
| Impressum, Datenschutz | Quellen ergänzt (SLF, Lawinen.report), Haftungssatz |
| Helfer `server/lawine_regionen.php` | `php server/lawine_regionen.php <data_dir> [suchtext]` listet regionID und Name aus den gesammelten Berichten |
| Tests | `tests/test_schnee.php` (am Ende von `test.php`): Katalog, Neuschnee je Stunde, CAAML JSON/XML, Sammler mit Freigabe und 404, Bewertung, Lage je Region, Fahrzeiten, Seiten |

| Messstationen (seit 9.11.2026) | `lib/stationen.php`, Sammler-Quelle `stationen` (stündlich als Cronjob, in der Action bei jedem Lauf, auch um 7:40 UTC). Gemessene Schneehöhe und ihre Änderung in 24 h je Region unter den Kacheln (nur „Heute – Übermorgen“) und auf der Stundenseite für heute, siehe „Messstationen“ |

Nicht gebaut: Verlauf über die Abrufe, Animation des Männchens, Mikroregionen als Polygone auf der Karte, feinere Alpen-Karte, Roadtrip.

## Neuschnee-Raster (Karte)

Seit 9.11.2026. Die Karte zeigt den Neuschnee nicht nur an den Regionen, sondern als Fläche über die Alpen.

- **Gitter:** 0,25° (rund 25 km), nur Punkte im groben Alpenumriss `SCHNEE_RASTER_UMRISS` (`lib/schnee.php`, von Hand nach dem Alpenbogen gezogen, nicht die Grenze der Alpenkonvention): 294 Punkte von den Seealpen bis zum Wienerwald.
- **Abruf:** Sammler-Quelle `schnee_raster`, Open-Meteo `forecast` ohne `models` (best_match), nur `precipitation, snowfall, snowfall_height, freezing_level_height`, `past_days=1`, 8 Tage, `elevation` 2.000 m. Drei Abrufe zu je 100 Orten, vor jedem eine Minute Pause (Minutenlimit), in den Läufen um 5:25 und 17:25 UTC, nicht um 7:40. Der Lauf dauert dadurch rund drei Minuten länger (`timeout-minutes` 30).
- **Gespeichert** wird je Punkt nur der Neuschnee je Tag (24 h bis 8 Uhr MEZ) auf 2.000 m, mit derselben Rechnung wie bei den Regionen (`schnee_raster_eintrag()`), wenige KB je Lauf. Die Seite liest das mit `schnee_raster_laden()`, höchstens 36 Stunden alt.
- **Karte:** je Zelle ein Rechteck in der Neuschnee-Farbe, leicht weichgezeichnet; Summe im Zeitraum oder der gewählte Tag, wie bei den Punkten. Die Punkte behalten ihren Wert auf Tourhöhe. Schalter „Fläche ein/aus“ (`wohinfahrma_schneefeld`). Ohne Raster wie vorher ein weicher Fleck je Region.
- **Grenzen:** 25 km sind in den Alpen grob, Täler und einzelne Kämme verschwimmen; ein Modell statt des Mittels. Feste Höhe statt Gelände, sonst zeigen Täler Regen und Kämme Schnee.
- **Kontingent:** Zählt Open-Meteo jeden Ort einzeln (vermutet, nicht geprüft), sind das rund 600 Aufrufe am Tag zusätzlich.

## Messstationen

Seit 9.11.2026. Im Katalog `messnetze` (`{id: {art, name, kurz, abruf, url, lizenz, stationen?}}`; `art` = `lwd-geojson` (Standard, eine Datei aller Stationen), `geosphere`, `meteoswiss`, `suedtirol`, `snowgrid`) und je Region `stationen` (`{netz: [id, …]}`, Reihenfolge = Anzeige). Der Sammler holt je Netz einen Abruf (MeteoSchweiz und Südtirol: einen je Station) und speichert nur die genannten Stationen.

- **Tirol** (`tirol`): Datensatz „Wetterstationsdaten Tirol“ (Land Tirol, LWD und Hydrographischer Dienst Tirol) auf data.gv.at, CC BY 4.0, stündlich. Felder an der Datei vom 9.11.2026 11:00 Uhr gesehen: 221 Stationen, 120 mit Schneehöhe; je Station `plot`, `name`, `LWD-Region` (EAWS-Mikroregion, z. B. „AT-07-22 Stubaier Alpen Mitte“), `date`, `HS` (Schneehöhe cm), `HSD24`/`HSD48`/`HSD72` (Änderung der Schneehöhe, mit Setzung, also kein Neuschnee), `LT`, Höhe als dritte Koordinate. **Die Abruf-URL ist geschätzt** (`wiski.tirol.gv.at/lawine/produkte/ogd.geojson`), beim ersten Lauf prüfen.
- **Gewählt** am 9.11.2026 nach Nähe zum Vorhersagepunkt und Höhe nahe der Tourhöhe: Kühtai/Sellrain Gossenkölle, Lampsenspitze, Breiter Grieskogel; Stubai Schöckbichl, Eissee (Stubaier Gletscher), Dresdner Hütte; Zillertal/Tux Tuxerjoch, Wandspitze, Lämmerbichlalm; Arlberg Ulmerhütte, Gampberg, Kapall; Ötztal/Gurgl Rosskar, Sonnbergalm. Franz-Senn-Hütte bewusst nicht (meldete −1,3 cm).
- **Plausibilität** (`stationen_wert()`): Schneehöhe unter −0,5 cm oder über 15 m fällt weg, leicht negativ zählt als 0; Änderung über 150 cm in 24 h wird leer (gesehen: −23 cm bei 0 cm Schnee); älter als 12 h mit Zeit, älter als 72 h nicht mehr.
- **Ohne eigene Stationen:** Werdenfels und Allgäu (Lawinenwarndienst Bayern ohne offene Schnittstelle gefunden; Tiroler bzw. Vorarlberger Stationen jenseits der Grenze), Oberengadin nur Samedan (SLF IMIS frei, laut Bedingungen aber nicht zur Speisung fremder Informationssysteme; vorher beim SLF fragen).
- **Südtirol** (`suedtirol`, seit 9.11.2026 eingebaut): Open-Data-Dienst des Landeswetterdiensts (`geoservices.buergernetz.bz.it/services/meteo/v1/`, CC0). Eine Zeitreihe je Station (`timeseries?station_code=…&sensor_code=HS&date_from=…&date_to=…`, YmdHi in Ortszeit), Antwort `[{DATE: "2026-11-09T12:30:00CET", VALUE}]`, neueste zuerst. Auf aperem Boden zeigen manche Pegel −1 bis −3 cm; bis −5 cm gilt als 0. Ahrntal: Rein/Tristenboden 2.450 m, Weißenbach/Fadner Alm 2.155 m, Prettau/Merbalm 2.006 m (gewählt mit „Stationen suchen“, 12 Südtiroler Stationen mit Schneepegel im Umkreis der Euregio-Regionen).
- **SNOWGRID** (`snowgrid`, seit 9.11.2026 eingebaut): Schneedeckenmodell SNOWGRID-CL von GeoSphere Austria (CC BY 4.0), 1-km-Raster, Tageswert bis gestern, nur Österreich (außerhalb null). **Kein Messwert**: steht auf der Seite in einer eigenen Zeile „Schneehöhe im Modell, Stand …“ mit Änderung zum Vortag. Ein Abruf für alle Punkte (`/v1/timeseries/historical/snowgrid_cl-v2-1d-1km?parameters=snow_depth&lat_lon=…&lat_lon=…`, Features in derselben Reihenfolge, Meter). Je österreichische Region ein Punkt in Tourhöhe und einer in mittlerer Höhe (Kühtai nur Tourhöhe), gewählt mit `tools/snowgrid_punkte.php` (Action „SNOWGRID-Punkte suchen“: Raster um den Vorhersagepunkt, Höhe aus dem Copernicus-DEM über Open-Meteo, ±100 m, nächster Punkt). Die Zelle hat 1 km, ihre mittlere Höhe weicht von der Punkthöhe ab. Nassfeld: der Vorhersagepunkt liegt südlich der Grenze, die Punkte sind auf der Kärntner Seite. Wird 100 h nach dem Stand nicht mehr gezeigt.
- **GeoSphere Austria** (`geosphere`, seit 9.11.2026 eingebaut): Messnetz TAWES, Parameter `SCHNEE`, CC BY 4.0. Ein Abruf der Zeitreihe der letzten 26 h für alle Stationen (`/v1/station/historical/tawes-v1-10min?parameters=SCHNEE&station_ids=…&start=…&end=…`); angezeigt wird der letzte vorhandene Wert, die Änderung gegen den Wert rund 24 h davor. Namen und Höhen stehen im Katalog (`messnetze.geosphere.stationen`, aus den Metadaten vom 9.11.2026, 288 Stationen). Gewählt am 9.11.2026 mit `tools/stationen_suchen.php` (Action „Stationen suchen“, von Hand; listet je Region alle Stationen im Umkreis, die gerade eine Schneehöhe melden): Allgäu Mittelberg, Warth, Tannheim; Ötztal zusätzlich Pitztaler Gletscher; Gastein/Rauris Sonnblick, Mallnitz; Obertauern Katschberg, Kölnbreinsperre; Lungau Kölnbreinsperre, Katschberg, St. Michael; Saalbach Saalbach, Hahnenkamm; Nassfeld nur Hermagor (Tal, 17 km); Sölktäler Dachstein-Gletscher, Ramsau. TAWES misst vor allem in Tälern; über 1.900 m nur Sonnblick, Pitztaler Gletscher, Dachstein, Kölnbreinsperre. Ohne Schneepegel: u. a. Kolm-Saigurn, Bad Gastein, Obertauern, Schmittenhöhe, Villacher Alpe, Gröbming. Hochkönig und Ahrntal ohne Station im Umkreis von 25 km. Antwortformat aus der API-Doku geschätzt.
- **MeteoSchweiz** (`meteoswiss`, seit 9.11.2026 eingebaut): SwissMetNet, eine CSV je Station (`…/ogd-smn_<kürzel>_t_now.csv`, seit gestern 12 UTC), Spalte `htoauts0`. Oberengadin: nur Samedan meldet eine Schneehöhe (Segl-Maria, Bernina nicht).
- **Werdenfels:** Tiroler Stationen jenseits der Grenze (Hohe Munde, Puitegg, Mieminger Gebirge).
- **Weitere Quellen** (recherchiert 9.11.2026, noch nicht eingebaut):
  - **Österreich, alle Länder (eingebaut, siehe oben):** GeoSphere Austria, Messnetz TAWES (rund 270 Stationen), `https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min?parameters=SCHNEE&station_ids=…`, Parameter `SCHNEE` (Schneehöhe cm, 10-Minuten-Rohdaten), CC BY 4.0. Laut einem fremden Projekt (GitHub MeteoMartini/MID, PR 513) waren die neuesten Werte bei einer Abfrage oft leer; also den letzten Wert der letzten Stunden nehmen. Welche Stationen in den Regionen liegen, steht in `…/tawes-v1-10min/metadata` (von hier nicht abrufbar).
  - **Österreich flächig (eingebaut, siehe oben):** GeoSphere SNOWGRID-CL, Schneehöhe und Wasserwert auf 1 km, täglich (Stand bis gestern), CC BY 4.0. Ein Modell mit Messungen, kein Messwert.
  - **Schweiz (eingebaut, siehe oben):** MeteoSwiss SwissMetNet (rund 260 Stationen), Parameter `htoauts0` (Schneehöhe automatisch, 10 Minuten), Open Data mit Quellenangabe „MeteoSwiss“; Stationsliste `data.geo.admin.ch/ch.meteoschweiz.ogd-smn/ogd-smn_meta_stations.csv`. Für das Oberengadin vermutlich Samedan und Segl-Maria (ungeprüft).
  - **Bayern:** Gewässerkundlicher Dienst Bayern (LfU), Rubrik Schnee, Einzel- und Tageswerte, CC BY 4.0 (z. B. Schneibsteinhaus); DWD Open Data täglich `SHK_TAG` (Zugspitze, Garmisch, Oberstdorf), einmal am Tag.
  - **Südtirol (eingebaut, siehe oben):** Meteo-Webservices des Landeswetterdiensts, Sensor `HS`, Datensatz „Misure meteo e idrografiche“ auf data.civis.bz.it unter CC0.
  - **LWD Salzburg, Kärnten, Steiermark:** eigene Stationsnetze auf ihren Seiten, aber keine offene Schnittstelle gefunden; anfragen wie bei der Lawinenlage.
- **Nebenbei:** Die Stationen nennen die EAWS-Mikroregion; daraus ließen sich die `eaws`-IDs der Tiroler Regionen eintragen (z. B. Stubai AT-07-22, Gurgl AT-07-21, Tux AT-07-15).

## Sportarten

| Sportart | Was zählt (Startwerte ohne Quelle, `SCHNEE_SPORTARTEN`) |
|---|---|
| Freeride | Neuschnee 72 h ≥ 15 cm, Bewölkung ≤ 40 %, Wind am Kamm ≤ 30 km/h |
| Skitour | Schneehöhe (Modell) ≥ 30 cm, Bewölkung ≤ 50 %, Wind am Kamm ≤ 40 km/h |

## Bewertung

Je Region und Tag:

- **Neuschnee je Tag** (angezeigt, seit 9.11.2026): Summe der 24 h bis 8 Uhr Ortszeit, wie die Messung am Morgen. Die Tage lassen sich addieren (vorher zeigte jede Kachel die 72 h; wer addierte, zählte denselben Schnee bis zu dreimal). Fehlen mehr als 4 der 24 Stunden, ist er unbekannt.
- **Neuschnee 72 h** (für die Regel bei Freeride, damit der schöne Tag nach dem Schneefall zählt): Summe der letzten 72 h bis 8 Uhr Ortszeit. Je Stunde zählt der Niederschlag als Schnee (1 mm = 0,7 cm, wie Open-Meteo), wenn die Schneefallgrenze unter der Tourhöhe liegt. Schneefallgrenze = `snowfall_height`, sonst Nullgradgrenze − 300 m, sonst Temperatur auf Tourhöhe ≤ 1 °C. Ohne Niederschlag zählt `snowfall`. Fehlen mehr als 12 der 72 Stunden, ist der Neuschnee unbekannt.
- **Wetter am Tag** (8–15 Uhr): mittlere Bewölkung, Wind in 700 hPa (km/h), höchste Nullgradgrenze, Neuschnee am Tag, Böen im Tal.
- **Schneehöhe:** Modellwert `snow_depth` um 8 Uhr, angezeigt als „(Modell)“.
- **Spanne:** Neuschnee je Tag in den 51 Läufen des ECMWF-Ensembles, 10. bis 90. Perzentil (Kachel „14–22 cm“, Stundenseite mit Mitte). Bis 9.11.2026 stand dort eine Chance in Prozent (Anteil der Läufe, an denen der Tag passt). Abgeschafft auf Wunsch: Sie fasste mehrere Schwellen zusammen, sagte nicht, was fehlt, und 14 cm bei Sonne zählten als 0 %. Intern wird sie noch gerechnet (`ens.chance`), aber nirgends gezeigt.
- **Farbe** (`schnee_klasse()`): kräftiges Blau = passt nach Modellmittel; sonst nach dem Neuschnee des Tages, ab 10 cm und ab 3 cm heller.
- **Heute** nach 13:30 Uhr Ortszeit „vorbei“ (weniger als 2 Stunden Fenster), wie im Wind-Modus.
- **Rangliste:** passende Tage (Modellmittel), dann Neuschnee im Zeitraum (Summe je Tag), dann näher. Ein einzelner Tag: passt, dann Neuschnee 72 h, dann 24 h.

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
| Salzburg, Kärnten, Steiermark (seit 9.11.2026) | `static.avalanche.report/eaws_bulletins/{datum}/{datum}-AT-05.json` (bzw. AT-02, AT-06), EAWS-Sammlung, Format wie CAAML ohne Versionsangabe; ob `lawine_lesen()` es liest, ungeprüft | offen | – |

Anfragen an LWD Tirol und LWD Bayern laufen; nach Antwort nur `freigegeben` ergänzen (`AT-07` bzw. `DE-BY`). Anmeldung beim SLF (data@slf.ch, Verteiler „Avalanche Bulletin“) empfohlen.

### Mikroregionen

Echte IDs haben Bayern und seit 9.11.2026 die fünf neuen österreichischen Regionen (Tirol aus `LWD-Region` der Stationsdatei, Salzburg, Kärnten, Steiermark aus `static.avalanche.report/eaws_bulletins/<datum>/<datum>-AT-0x.json`). Bayern: Werdenfels `DE-BY-30` („Werdenfelser Alpen“), Allgäu `DE-BY-12` („Allgäuer Alpen Mitte“, ob Oberstdorf ganz darin liegt, ungeprüft). Alle anderen über `eaws_namen` (geschätzt). Nach dem ersten Lauf mit `php server/lawine_regionen.php <data_dir> Engadin` die IDs nachsehen und als `eaws` eintragen.

## Beim ersten echten Lauf prüfen

0. Seit 9.11.2026: `schnee_raster/0` bis `/2` grün? Liefert best_match `snowfall_height` im ganzen Alpenraum, und kommen keine HTTP 429?

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
| Schneehöhe Stationen | Tirol: LWD/HD Tirol über data.gv.at (GeoJSON, stündlich, CC BY 4.0), seit 9.11.2026 eingebaut. Schweiz: SLF IMIS `measurement-api.slf.ch` (CC BY 4.0; laut SLF-Bedingungen aber „nicht für die Speisung von Informationssystemen“, vorher beim SLF nachfragen). Südtirol: `geoservices.buergernetz.bz.it/services/meteo/v1/` (CC0), seit 9.11.2026 eingebaut. GeoSphere TAWES und MeteoSchweiz eingebaut. Bayern: keine offene Schnittstelle gefunden. GeoSphere SNOWGRID (CC BY 4.0, Modell) eingebaut | Stand 9.11.2026 | eingebaut außer SLF und Bayern |
| Schnee und Wetter | Open-Meteo | CC BY 4.0 | Variablen dokumentiert, Verhalten im Gebirge ungeprüft |

Für die Rechnung nur Stufe und strukturierte Felder; Texte der Berichte nicht kürzen oder umschreiben, sondern verlinken.

## Nächste Schritte

1. Erster echter Lauf (siehe oben), danach Mikroregion-IDs eintragen
2. Antworten der Warndienste: Bayern, Tirol freigeben
3. Schwellen und Tourhöhen aus eigener Erfahrung anpassen
4. Weitere Messstationen (SLF nach Rückfrage)
5. Verlauf über die Abrufe, Animation des Männchens wie im Wind-Modus
6. Karte: Alpen-Ausschnitt feiner, Mikroregionen als Polygone (EAWS-Regionen, Lizenz im Repo prüfen)
7. Slogan für beide Modi
