# Wohinfahrma – Schnee

*Gebaut am 8.11.2026, seit dem Abend mit echten Daten; am 11.11.2026 abgeglichen (README.md, „Abgleich mit echten Daten“).* Ergänzung zu `README.md`.

Zweiter Modus in Wohinfahrma für Skitourengeher und Freerider, nur Alpen: Wo liegt in den nächsten Tagen frischer Schnee, und wo wird es schön? Dazu Schneehöhe, Neuschnee, Wetter und – immer sichtbar, aber nicht in der Wertung – die Lawinenwarnstufe.

## Grundsatz Lawine

Die App sagt „wo liegt guter Schnee“, nie „wo ist es sicher“. Die Warnstufe geht **nicht** in Sortierung oder Farbe der Bewertung ein. Seit 10.11.2026 (Wunsch Tobias) steht ab **Stufe 3 ein Warndreieck** ⚠ bei der Region: im Abzeichen der Kachel, auf dem Kartenpunkt (orange bzw. rot, bei „alle Tage“ die höchste Stufe heute/morgen), in der Wochenansicht, als Textzeile bei Pulvertagen, im Lawinenblock, auf der Stundenseite und im Teilen-Text. Ab **Stufe 4** ist der Tag zusätzlich rot umrandet. Der Platz in der Rangliste bleibt. Seit 13.11.2026 (Wunsch Tobias) fährt das **Männchen** auf der Karte nicht zu Regionen ab **Stufe 4** (`MAENNCHEN_NEIN_AB` in `lib/lawine.php`, dieselbe Stufe wie das Dreieck auf dem Kartenpunkt): Ist die beste Region mit Pulvertag dort, zeigt es erst hin, schüttelt den Kopf („Naa, Stufe 4!“, roter Puls, ein tiefes „Nö“ als Ton) und fährt dann zur besten Region mit höchstens Stufe 3; gibt es keine, zuckt es mit den Schultern. Begründung für Stufe 3: Laut Lawinenwarndienst Bayern geschahen in den Bayerischen Alpen seit 1993/94 48 % der 52 tödlichen Lawinenunfälle bei Stufe 3 (`lawine_warnung()` in `lib/lawine.php`). Ohne Bericht (ab übermorgen, nicht freigegebene Dienste) kein Dreieck, nur „LWS –“. Sie steht als eigenes Abzeichen in den offiziellen EAWS-Farben daneben (Kachel „LWS 3“, im Lawinenblock und auf der Stundenseite „Gefahrenstufe 3“ wie im Bericht), mit Link zum Bericht und Ausgabezeit, nur für Tage mit gültigem Bericht (meist heute und morgen). Keine eigene Hochrechnung. Die Bewertung nutzt deshalb eine **Blau-Skala** statt Grün/Gelb/Rot. Fester Hinweis oben auf der Seite, je Region, im Teilen-Text, auf der Stundenseite und im Impressum (`LAWINE_HAFTUNG`, seit 10.11.2026 nach den Hinweisen des LWD Bayern): „Die Lawinenlage stammt vom Warndienst, ohne Gewähr. Sie ersetzt weder den aktuellen Lagebericht auf dessen Website noch die eigene Beurteilung vor Ort. Wohinfahrma bewertet nur Schnee und Wetter.“

## Stand

| Baustein | Status |
|---|---|
| Seite `server/web/schnee.php` | Umkreis 100–800 km Straße, Standort, Tag (alle Tage oder ein Tag) und Priorität (seit 10.11.2026: Pulver + Sonne, Neuschnee, Fahrzeit; vorher eine Auswahl „Sortierung“ mit Fahrzeit darin, `nach=km` gilt weiter), Ansichten Kacheln (Zeitraum nach Wochentagen, z. B. „Heute – So“ und „Mo – Do“; bis 12.11.2026 „Heute – Übermorgen“ und „Tag 3 – 7“) und Tabelle 7 Tage (bis 12.11.2026 „Liste“ und „Woche“; umbenannt nach Rückmeldung eines Nutzers, auch im Wind-Modus, `zeitraum_namen()` in `lib/design.php`), Deine Regionen (seit 13.11.2026, siehe unten), Karte mit Punkten in einer Neuschnee-Farbskala (seit 9.11.2026: grau, hellblau, blau, violett, magenta ab 50 cm, `SCHNEESKALA` in `lib/karte.php`, mit weichem Farbfeld je Region und Legende; Zahl: Neuschnee im Zeitraum bzw. am gewählten Tag in cm; Ring = Pulvertag) und Männchen (seit 9.11.2026 animiert wie im Wind-Modus: zeigt auf die beste Region mit Pulvertag, Juchhu, fährt im Bus mit Ski auf dem Dach hin, am Ziel stecken Ski im Schnee und es schneit; an Regionen mit Pulvertag fallen Flocken, mehr bei mehr Neuschnee; ohne Pulvertag Schulterzucken), Kacheln mit allem auf einen Blick: Wetter (☀ ⛅ ☁, ❄ bei Schneefall am Tag), Neuschnee je Tag (24 h bis 8 Uhr, seit 9.11.2026), Schneehöhe, LWS, darunter klein die Spanne aus dem Ensemble; Wind und Gründe im Tooltip und auf der Stundenseite. Darunter nur für gute Tage Abfahrt, Details und Teilen; Lawinenlage einklappbar, geteilte Ansicht `?region=…&nach=…`. `noindex` |
| Stundenseite `server/web/schnee_stunden.php` | Seit 9.11.2026 neu aufgebaut (vorher Textblock, Bergsilhouette, vier Höhenlinien; unübersichtlich): oben das Urteil „(Kein) Pulvertag“ mit allen fehlenden Gründen, darunter beide Regeln einzeln mit Wert und Schwelle (`schnee_kriterien()`; seit 16.11.2026 als zwei große Kacheln, erfüllt blau umrandet, sonst gelb; Wunsch Tobias: Neuschnee 72 h ging als kleine Zeile unter), dann Kennzahlen als Kacheln (Neuschnee 24 h mit Spanne des Ensembles, Schneefallgrenze, Nullgrad, Schneehöhe, Himmel, Wind am Kamm, Böen). Ein Diagramm mit einer Zeitachse: Niederschlag je Stunde auf Tourhöhe, blau als Schnee, grau als Regen, in Abschnitten von 8 bis 8 Uhr mit der Summe darüber (wie die Tagesknöpfe), Strich „jetzt“; darunter nur die Schneefallgrenze gegen Tour- und Talhöhe. Lawinenlage mit Expositionsrose |
| Schalter Wind · Schnee | im Kopf beider Seiten; es sind zwei Seiten, nicht ein Formular. Die Wind-Seite merkt sich ihre Wahl wie bisher, die Schnee-Seite merkt sich keine Auswahl im Browser, nur die Sterne für „Deine Regionen“ (seit 13.11.2026) |
| Katalog `daten/schneeregionen.json` | 9 Regionen ab Inning: Werdenfels, Allgäu/Oberstdorf, Kühtai/Sellrain, Stubai, Zillertal/Tux, Arlberg, Ötztal/Obergurgl, Ahrntal, Oberengadin. Seit 9.11.2026 dazu Kitzbüheler Alpen, Osttirol (Kals, Matrei), Gastein/Rauris (Salzburg), Nassfeld/Karnische Alpen (Kärnten), Schladminger Tauern/Sölktäler (Steiermark), danach Salzburg aufgeteilt (Obertauern, Lungau, Saalbach/Glemmtal, Hochkönig zusätzlich zu Gastein/Rauris): 18 Regionen; danach Lechtaler Alpen, Lenggries/Isarwinkel, Spitzingsee, Berchtesgadener Alpen, Sterzing/Wipptal, Martelltal, Brixen, Pustertal (Antholz, Gsies), Livigno, Sulden: 28 Regionen; seit 10.11.2026 (Wunsch Tobias) Achensee/Rofan, Tuxer Alpen West, Fieberbrunn/Pillersee, Seefeld/Leutasch, Ehrwald, Tannheimer Tal, Kleinwalsertal (Vorarlberg), Pitztal/Kaunertal, Silvretta/Galtür, Kaprun/Kitzsteinhorn: 38 Regionen, IDs mit „EAWS-Regionen zuordnen“, Stationen und SNOWGRID-Punkte mit den Actions geprüft, Anfahrten gegen das DEM geprüft (Höhe im Log). Wildschönau/Alpbachtal bewusst nicht, liegt in denselben Mikroregionen wie „Kitzbüheler Alpen“ und kaum 15 km davon; Mikroregion-IDs aus der Tiroler Stationsdatei bzw. aus EAWS-Berichten vom April 2026. Punkte, Tour- und Talhöhen geschätzt. Prüfung `schneeregionen_pruefen()` |
| Bewertung `server/web/lib/schnee.php` | siehe „Bewertung“ |
| Lagebericht `server/web/lib/lawine.php` | liest CAAML v6 als JSON und XML, je Mikroregion Stufe (auch über/unter Höhengrenze), Probleme mit Expositionen und Höhe |
| Sammler | Quellen `schnee_vorhersage`, `schnee_ensemble`, `lawine` mit `--schnee <katalog>`; `--nur a,b` für einzelne Quellen |
| Action `sammeln.yml` | wie bisher 5:25 und 17:25 UTC, dazu 7:40 UTC nur `lawine` und `schnee_vorhersage` (Morgen-Update der Berichte) |
| Abruf auf dem Server (seit 13.11.2026) | `lawine_holen.php` mit `server_abruf()` aus `lib/abruf.php`: Lageberichte und Stationen direkt vom Hetzner-Server, angestoßen von `melden.php` auf ammerseewind.de etwa alle 30 Minuten. Siehe „Abruf auf dem Server“ |
| Fahrzeiten | `fahrzeiten.php` nimmt den Schnee-Katalog als zweites Argument; gemeinsame `fahrzeiten.json`, ids müssen in beiden Katalogen verschieden sein (Test prüft das) |
| Deploy | lädt `daten/schneeregionen.json` als `lib/schneeregionen.json` hoch |
| Impressum, Datenschutz | Quellen ergänzt (SLF, Lawinen.report), Haftungssatz |
| Helfer `server/lawine_regionen.php` | `php server/lawine_regionen.php <data_dir> [suchtext]` listet regionID und Name aus den gesammelten Berichten |
| Tests | `tests/test_schnee.php` (am Ende von `test.php`): Katalog, Neuschnee je Stunde, CAAML JSON/XML, Sammler mit Freigabe und 404, Bewertung, Lage je Region, Fahrzeiten, Seiten |

| Messstationen (seit 9.11.2026) | `lib/stationen.php`, Sammler-Quelle `stationen` (stündlich als Cronjob, in der Action bei jedem Lauf, auch um 7:40 UTC). Gemessene Schneehöhe und ihre Änderung in 24 h je Region unter den Kacheln (nur im ersten Zeitraum, heute bis übermorgen) und auf der Stundenseite für heute, siehe „Messstationen“ |

| Orte auf der Karte (seit 10.11.2026) | Schnee-Modus: feste Auswahl von Tobias `KARTE_ORTE_SCHNEE` in `lib/karte.php` (Salzburg, Wien, Innsbruck, Bozen, Livigno, Oberstdorf, St. Anton, Bad Tölz, Garmisch), immer gezeigt, sofern im Ausschnitt; vor den Regionsnamen gesetzt (die weichen aus) und über den Regionspunkten gezeichnet. Danach weitere Orte aus `KARTE_ORTE_SCHNEE_LUECKEN` (rund 40, z. B. Kufstein, Lienz, Villach, Meran, Chur, Linz), nur wo sie keinen Punkt, Namen oder festen Ort verdecken; im dichten Tiroler Mittelteil bleibt dafür kaum Platz. Wien liegt erst ab einem größeren Umkreis im Ausschnitt. Wind-Modus: `KARTE_ORTE`, große Städte immer, Alpenorte ab einem Ausschnitt bis 10 Längengrade, nur wo nichts verdeckt wird. Koordinaten aus dem Gedächtnis, auf rund 1 km |

Nicht gebaut: Verlauf über die Abrufe, Mikroregionen als Polygone auf der Karte, feinere Alpen-Karte, Roadtrip.

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

- **Tirol** (`tirol`): Datensatz „Wetterstationsdaten Tirol“ (Land Tirol, LWD und Hydrographischer Dienst Tirol) auf data.gv.at, CC BY 4.0, stündlich. Felder an der Datei vom 9.11.2026 11:00 Uhr gesehen: 221 Stationen, 120 mit Schneehöhe; je Station `plot`, `name`, `LWD-Region` (EAWS-Mikroregion, z. B. „AT-07-22 Stubaier Alpen Mitte“), `date`, `HS` (Schneehöhe cm), `HSD24`/`HSD48`/`HSD72` (Änderung der Schneehöhe, mit Setzung, also kein Neuschnee), `LT`, Höhe als dritte Koordinate. Abruf-URL `wiski.tirol.gv.at/lawine/produkte/ogd.geojson`, am 11.11.2026 bestätigt (36 von 36 Stationen).
- **Gewählt** am 9.11.2026 nach Nähe zum Vorhersagepunkt und Höhe nahe der Tourhöhe: Kühtai/Sellrain Gossenkölle, Lampsenspitze, Breiter Grieskogel; Stubai Schöckbichl, Eissee (Stubaier Gletscher), Dresdner Hütte; Zillertal/Tux Tuxerjoch, Wandspitze, Lämmerbichlalm; Arlberg Ulmerhütte, Gampberg, Kapall; Ötztal/Gurgl Rosskar, Sonnbergalm. Franz-Senn-Hütte bewusst nicht (meldete −1,3 cm).
- **Plausibilität** (`stationen_wert()`): Schneehöhe unter −0,5 cm oder über 15 m fällt weg, leicht negativ zählt als 0; Änderung über 150 cm in 24 h wird leer (gesehen: −23 cm bei 0 cm Schnee); älter als 12 h mit Zeit, älter als 72 h nicht mehr.
- **Ohne eigene Stationen:** Werdenfels und Allgäu (Lawinenwarndienst Bayern ohne offene Schnittstelle gefunden; Tiroler bzw. Vorarlberger Stationen jenseits der Grenze), Oberengadin nur Samedan (SLF IMIS frei, laut Bedingungen aber nicht zur Speisung fremder Informationssysteme; beim SLF am 10.11.2026 im Vorgang DATA-2406 angefragt, Antwort steht aus).
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

## Webcams (seit 12.11.2026)

- Unter der Zeile mit den Messstationen (nur im ersten Zeitraum, heute bis übermorgen, und auf der Stundenseite für heute) steht je Messstation die **nächste Webcam**, nur als Link auf ihre Seite, kein Bild: „an der Station …“ unter 0,5 km, sonst „x km von …“ (Luftlinie). Teilen sich Stationen eine Webcam, steht sie einmal, bei der nächsten Station. SNOWGRID-Punkte bekommen keine Webcam. Regionen ohne Messstation (oder ohne Webcam an ihren Stationen) zeigen die Webcam der Region („rund x km vom Tourengebiet“, ohne km „im Gebiet“).
- Katalog: `webcams.stationen` (`"netz:id"` → name, url, titel, km, hoehe) und `webcams.regionen` in `daten/schneeregionen.json`; geprüft in `webcams_pruefen()`.
- Ausgewählt mit `tools/webcams_suchen.php` (Action „Webcams suchen“): Liste von foto-webcam.eu (`/webcam/include/metadata.php`, am 12.11.2026 384 Kameras mit Lage; `list.php` lieferte nichts), Lage der Stationen aus den Messnetzen, höchstens 25 km. Von Hand ergänzt: Samedan → feratel Diavolezza (km geschätzt), Livigno → Webcam-Seite Carosello 3000.
- Grenzen: Luftlinie, nicht Sichtbarkeit. Einige Webcams liegen hinter einem Kamm oder tief im Tal (z. B. Erfurter Hütte → Kaltenbach im Zillertal, 19,5 km; Weißsee → Langtaufers, 6,6 km). Die Entfernung steht deshalb immer dabei.

## Deine Regionen (seit 13.11.2026)

Wunsch eines Nutzers: eigene Berge festlegen. Umgesetzt als Favoriten aus dem Katalog, ohne eigene Punkte.

- Stern ☆/★ hinter dem Namen jeder Region; der Browser merkt sich die ids (`wohinfahrma_fav_schnee`, localStorage). Die Seite merkt sich sonst weiter keine Auswahl.
- Mit mindestens einem Stern steht über dem Zeitraum „★ Nur deine Regionen (n)“; der Link setzt `fav=id,id,…`. Dann zeigt die Seite nur diese Regionen, **ohne Umkreis** (Auswahl Umkreis und Kreis auf der Karte fallen weg), Karte und Männchen ebenfalls. Unbekannte ids fallen weg. „Alle Regionen“ führt zurück. Ein Lesezeichen mit `fav=` funktioniert auch auf einem anderen Gerät.
- Kein Cookie; Datenschutz Abschnitt 5 ergänzt (ids in der Adresse, damit in den Logfiles).
- Gemeinsamer Code mit dem Wind-Modus (seit 13.11.2026 dort ebenfalls, siehe README) in `lib/favoriten.php`. Ändern sich die Sterne, während „Nur deine Regionen“ aktiv ist, erscheint „Auswahl aktualisieren“.
- Nicht gebaut: eigene Punkte mit Koordinaten (bräuchten Tourhöhe, EAWS-Mikroregion, Fahrzeit und einen Platz im Sammler).

## Pulvertag (eine Regel für alle)

Seit 9.11.2026 keine Sportarten mehr. Vorher gab es Freeride (Neuschnee 72 h ≥ 15 cm, Bewölkung ≤ 40 %, Wind am Kamm ≤ 30 km/h) und Skitour (Schneehöhe im Modell ≥ 30 cm, Bewölkung ≤ 50 %, Wind am Kamm ≤ 40 km/h). Beide Schwellensätze waren ohne Quelle gesetzt; die Skitour-Regel hing an der Modell-Schneehöhe, dem unsichersten Wert. Der Unterschied war nur eine Gewichtung derselben Daten.

| Regel (`SCHNEE_REGEL`, Startwerte ohne Quelle) | Wert |
|---|---|
| Neuschnee 72 h bis 8 Uhr | ab 10 cm (dieselbe Grenze wie das mittlere Blau) |
| Bewölkung 8–15 Uhr | bis 60 % = höchstens „teils sonnig“, also wie das Symbol ⛅ (`SCHNEE_WOLKIG`) |

- **Schneehöhe** und **Wind am Kamm** schließen nichts aus. Die Schneehöhe steht in Kachel und Stundenseite (Modell) und darunter die Messstationen; ob es für eine Tour reicht, beurteilt man selbst.
- **Wind am Kamm** ab 50 km/h in 700 hPa (`SCHNEE_KAMM_HINWEIS`, Startwert ohne Quelle): 💨 in der Kachel und auf der Stundenseite, „viel Wind am Kamm“ in Details und Teilen-Text.
- Der Parameter `sport=` in alten Links wird ignoriert.

## Bewertung

Je Region und Tag:

- **Neuschnee je Tag** (angezeigt, seit 9.11.2026): Summe der 24 h bis 8 Uhr Ortszeit, wie die Messung am Morgen. Die Tage lassen sich addieren (vorher zeigte jede Kachel die 72 h; wer addierte, zählte denselben Schnee bis zu dreimal). Fehlen mehr als 4 der 24 Stunden, ist er unbekannt.
- **Neuschnee 72 h** (für die Regel Pulvertag, damit der schöne Tag nach dem Schneefall zählt): Summe der letzten 72 h bis 8 Uhr Ortszeit. Je Stunde zählt der Niederschlag als Schnee (1 mm = 0,7 cm, wie Open-Meteo), wenn die Schneefallgrenze unter der Tourhöhe liegt. Schneefallgrenze = `snowfall_height`, sonst Nullgradgrenze − 300 m, sonst Temperatur auf Tourhöhe ≤ 1 °C. Ohne Niederschlag zählt `snowfall`. Fehlen mehr als 12 der 72 Stunden, ist der Neuschnee unbekannt.
- **Wetter am Tag** (8–15 Uhr): mittlere Bewölkung, Wind in 700 hPa (km/h), höchste Nullgradgrenze, Neuschnee am Tag, Böen im Tal.
- **Schneehöhe:** Modellwert `snow_depth` um 8 Uhr, angezeigt als „(Modell)“.
- **Temperatur** (seit 13.11.2026, Kachel „Temp −9 / −2°“, Stundenseite „Nacht / Tag“): `temperature_2m` auf Tourhöhe (Modellmittel), tiefste der Nacht davor (18 Uhr am Vortag bis 8 Uhr, `SCHNEE_NACHT_AB`) und höchste im Tagesfenster. Nur angezeigt, keine Regel. Vor allem fürs Frühjahr: Gefriert es in der Nacht, trägt der Firn am Morgen; wie warm es am Tag wird, sagt, wann er aufweicht. Der Talort kann deutlich wärmer sein (oder bei Inversion kälter); gezeigt wird bewusst nur die Tourhöhe.
- **Firn** (seit 13.11.2026, nur März bis Juni, `schnee_firn()`): „Firn“ in der Kachel und ein Satz auf der Stundenseite, wenn es auf Tourhöhe nachts auf höchstens −1° abkühlt, am Tag mindestens 1° erreicht, der Tag höchstens teils sonnig ist und kein frischer Pulver liegt (Neuschnee 72 h unter 10 cm). „kein Frost“, wenn die Nacht über 0° bleibt (Schnee friert nicht durch). Nur Hinweis, ändert weder Farbe noch Platz. Startwerte ohne Quelle.
- **Kennzahlen der Stundenseite** (seit 13.11.2026) nach Bedeutung gruppiert und gefärbt: Schnee (blau), Temperatur und Nullgrad (orange), Himmel (gelb), Wind (türkis).
- **Spanne:** Neuschnee je Tag in den 51 Läufen des ECMWF-Ensembles, 10. bis 90. Perzentil (Kachel „14–22 cm“, Stundenseite mit Mitte). Bis 9.11.2026 stand dort eine Chance in Prozent (Anteil der Läufe, an denen der Tag passt). Abgeschafft auf Wunsch: Sie fasste mehrere Schwellen zusammen, sagte nicht, was fehlt, und 14 cm bei Sonne zählten als 0 %. Intern wird sie noch gerechnet (`ens.chance`), aber nirgends gezeigt.
- **Farbe** (`schnee_klasse()`): kräftiges Blau = Pulvertag nach Modellmittel; sonst nach dem Neuschnee des Tages, ab 10 cm und ab 3 cm heller.
- **Heute** nach 13:30 Uhr Ortszeit „vorbei“ (weniger als 2 Stunden Fenster), wie im Wind-Modus.
- **Pulver** je Tag: Neuschnee 72 h, wenn der Tag ein Pulvertag ist, sonst 0.
- **Rangliste** (seit 9.11.2026): bester Pulvertag im Zeitraum (`pulver_max`), dann Neuschnee im Zeitraum (Summe je Tag), dann näher. 30 cm bei Sonne vor 12 cm bei Sonne, beide vor 40 cm im Nebel. Vorher zählte die Anzahl passender Tage. Ein einzelner Tag: Pulver, dann Neuschnee 72 h, dann 24 h. Die Karte (Rang der Punkte, Ziel beim Antippen) und die Stundenseite (Tag ohne Auswahl) nehmen ebenfalls den besten Pulvertag.
- **Priorität** (seit 10.11.2026, Parameter `prio`, `SCHNEE_PRIOS`): `pulver` = Rangliste wie oben (Standard). `neu` = Neuschnee in Stufen (`SCHNEE_NEU_STUFEN`: ab 5, 15, 30 cm), innerhalb einer Stufe die Sonne (alle Tage: Zahl der schönen Tage; ein Tag: sonnig vor teils sonnig vor bewölkt), dann die cm. Stufen, weil sonst bei 17 gegen 18 cm fast immer nur der erste Wert entschiede. `km` = Fahrzeit. Die Lawinenstufe geht in keine Priorität ein. Profile mit Schneehöhe sind bewusst noch nicht dabei: Gemessen wird nur in einem Teil der Regionen, sonst nur Modellwerte.

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

Je Region: `warndienst`, `eaws` (Präfixe der regionID) und/oder `eaws_namen` (Teil des Namens im Bericht, nur wenn `eaws` leer ist; dann steht „Zuordnung über den Namen“ dabei). Bei mehreren Mikroregionen gilt die höchste Stufe. Gezeigt wird der Bericht, der um 12 Uhr Ortszeit des Tages gilt, aus der neuesten Ausgabe; für heute ab Mittag der Bericht, der jetzt gilt (seit 10.11.2026; vorher stand nach 17 Uhr unter „heute“ noch der abgelaufene Bericht).

Der Sammler holt je Dienst heute und morgen. HTTP 404 heißt „noch kein Bericht“ (morgen vor 17 Uhr, außerhalb der Saison) und ist kein Fehler. Gespeichert wird eine Zeile je Dienst: `{"ts", "source": "lawine", "ok", "dienst", "tage": {datum: [berichte]|null}}`.

### Abruf auf dem Server (seit 13.11.2026)

**Warum:** Der Zeitplan der GitHub Action startete im November 2026 drei bis acht Stunden zu spät (geplant 5:25 UTC, gelaufen 13:38–13:53; geplant 17:25, gelaufen 22:26–23:33) oder gar nicht. Am 13.11.2026 stand um 14 Uhr noch der Bericht vom Vorabend in der App. Für den Lagebericht ist das zu lang. Der Anstoß der Action durch ammerseewind.de (seit 11.11.2026, `workflow_dispatch`) macht die drei Läufe pünktlich, ein Update des Warndiensts zwischen den Läufen käme aber trotzdem erst beim nächsten Lauf.

**Wie:** `lawine_holen.php` (POST, `schluessel` = `abruf_schluessel` aus `config.php`, mindestens 20 Zeichen; sonst 404) ruft `server_abruf()` auf. `melden.php` auf ammerseewind.de (Cronjob alle 10 Minuten) stößt es an, sobald eine neue halbe Stunde angefangen hat (`wohinfahrma_lawine_holen()` in `export_wohinfahrma.php`, Schlüssel dort `wohinfahrma_abruf_schluessel`). Kein zweiter Cronjob nötig.

- **Was:** alle freigegebenen Warndienste (wie der Sammler, heute und morgen), höchstens alle 20 Minuten; die Messstationen höchstens alle 50 Minuten. Zusammen laut Log vom 13.11.2026 wenige Sekunden. Kein Open-Meteo, also kein Kontingent.
- **Wohin:** je Quelle eine kleine Datei `lawine/server.ndjson` und `stationen/server.ndjson`, atomar ersetzt: je Dienst bzw. Netz die letzte gute Zeile und, falls danach ein Versuch scheiterte, die Fehlerzeile. Die Datei wächst nicht; die Seite liest nicht Dutzende Abrufe ein. Verlauf gibt es weiter in den Dateien der Action.
- **Lesen:** `lawine_laden()` und `stationen_laden()` nehmen je Dienst bzw. Netz die Zeile mit dem neuesten `ts`, egal aus welcher Datei. Vorher galt die Reihenfolge der Dateinamen, und `2026-11-13_1310.ndjson.gz` sortiert hinter `server.ndjson` bzw. einer Tagesdatei.
- **Im Seitenaufruf nicht lange warten:** eine Wiederholung nach 5 s statt zwei nach 15 s, Zeitlimit 10 s je Abruf, nach 100 s kein weiterer Dienst und kein weiteres Netz (gilt als Fehlversuch). Zwei Anstöße gleichzeitig: der zweite bekommt 409.
- **Hinweis „nicht aktualisiert“:** Ist `lawine/server.ndjson` jünger als 3 Tage (Server-Abruf eingerichtet), erscheint er schon nach 2 statt 13 Stunden (`LAWINE_ALT_STUNDEN_SERVER`). Fällt der Server-Abruf aus, merkt man es also bald. Liegt er länger als 3 Tage still, gilt wieder die Frist für die Action.
- **Anzeige:** Seit 13.11.2026 steht bei der Lawinenlage hinter Ausgabe und Gültigkeit „abgerufen heute 17:30“ (letzter guter Abruf beim Dienst, `lawine_gueltig_text()`). Springt die Zeit etwa halbstündlich weiter, läuft der Abruf auf dem Server.
- **Einrichten:** Schlüssel erzeugen (`php -r 'echo bin2hex(random_bytes(20)), "\n";'`), in die `config.php` von Wohinfahrma als `abruf_schluessel` und in die von ammerseewind.de als `wohinfahrma_abruf_schluessel`. Ohne Schlüssel bleibt alles wie vorher.

### Hinweise des LWD Bayern zur Weiterverarbeitung (seit 10.11.2026)

Freigabe per Mail der Lawinenwarnzentrale Bayern (Dr. Thomas Feistl, 10.11.2026): Einbindung über die CAAML-Dateien, Hinweise auf `lawinenwarndienst.bayern.de/presse/` beachten. Messdaten nur von der Website; eine Datenschnittstelle ist angekündigt. Umgesetzt für alle Dienste, nicht nur Bayern:

| Hinweis | Umsetzung |
|---|---|
| Quellenangabe mit Link auf den aktuellen Bericht | Link bei jeder Region und auf der Stundenseite, voller Name im Fuß und im Impressum |
| Ausgabe- und Gültigkeitszeit, aktuellster Datensatz | „ausgegeben heute 17:00, gültig bis morgen 17:00“ (`lawine_gueltig_text()`); nur gültige Berichte, heute ab Mittag der jetzt gültige |
| Vollständig, korrekt, eindeutig | Stufen nach Tageszeit beide („vormittags …; nachmittags …“), Höhengrenzen, Probleme je Tageszeit (`lawine_probleme_liste()`). Mehrere Stufen ohne Höhengrenze (in der Trentiner Datei vom 17.4.2026 gesehen: zwei Ganztagsstufen ohne `elevation`) werden nicht geraten; der Text sagt „wo welche Stufe gilt, fehlt in den Daten; siehe Bericht“ (`lawine_mehrdeutig()`), das Abzeichen zeigt die höhere |
| Bearbeitungen kennzeichnen, eigene Bewertungen trennen | „Auszug aus dem Lagebericht (Quelle: …): nur Gefahrenstufe und Lawinenprobleme, ohne die Texte; von Wohinfahrma gekürzt und aufbereitet“ (`lawine_auszug_text()`), mehrere Teilgebiete benannt; Warndreieck-Texte beginnen mit „Hinweis von Wohinfahrma:“ und stehen nach den Angaben des Warndiensts |
| Angemessen abrufen | drei Läufe am Tag (6:25, 8:40, 18:25 Uhr im Winter), je Dienst zwei Dateien |
| Ausfälle deutlich anzeigen | `lawine_stoerung()`: letzter Abruf fehlgeschlagen, letzter guter Abruf älter als 13 h (mit Abruf auf dem Server: 2 h) oder gar keine Daten: Hinweis oben, bei der Region („Abruf gestört“, Block aufgeklappt) und auf der Stundenseite, mit Stand |
| Haftung, Eigenverantwortung | `LAWINE_HAFTUNG` (siehe oben) |

Echte Berichte prüft die Action „Lagebericht prüfen“ (`tools/lawine_pruefen.php`, bei Änderungen am Parser oder Katalog und von Hand). Testdatei `tests/lawine_bayern_2026-04-17.xml` nach dem Aufbau der echten Datei vom 17.4.2026 (Tageszeiten, Nassschnee).

### Freigabe am 8.11.2026

| Dienst | Abruf | Lizenz | Freigegeben |
|---|---|---|---|
| SLF | `aws.slf.ch/api/bulletin/caaml/de/json` (laut API-Seite: gerade gültiger Bericht, CAAML v6) | CC BY 4.0, Quellenangabe mit Link | `CH-` |
| Euregio | `static.avalanche.report/bulletins/{datum}/{datum}_EUREGIO_de_CAAMLv6.json` (laut Euregio der richtige und dauerhafte Abrufweg, siehe lawinen.report/more/open-data) | CC BY 4.0 (Tirol laut Mail der Euregio; Südtirol, Trentino laut Open-Data-Portalen) | `AT-07` (seit 16.11.2026), `IT-32-BZ`, `IT-32-TN` |
| Bayern | `static.lawinen-warnung.eu/bulletins/{datum}/{datum}_DE-BY_de_CAAMLv6.xml` (Struktur an echten Dateien vom 20.12.2025 und 17.4.2026 gesehen) | Weiterverarbeitung erlaubt (Mail 10.11.2026), Hinweise siehe oben | `DE-BY` (seit 10.11.2026) |
| Salzburg | seit 16.11.2026 `static.lawinen-warnung.eu/bulletins/{datum}/{datum}_GEOSPHERE_de_CAAMLv6.json` (gemeinsame Datei mehrerer Länder, gespeichert wird nur `AT-05`; am 16.11.2026 mit den Dateien vom 27.3. und 17.4.2026 geprüft, alle Salzburger Mikroregionen mit Stufe) | Verwertung privat und kommerziell erlaubt (Mail LWD Salzburg), Lizenz soll bis Saisonstart auf der Website stehen | `AT-05` (seit 16.11.2026) |
| Kärnten | seit 16.11.2026 `static.avalanche.report/bulletins/{datum}/{datum}_AT-02_de_CAAMLv6.json` (Kärnten arbeitet auf avalanche.report; am 16.11.2026 mit den Dateien vom 27.3. und 17.4.2026 geprüft, Nassfeld `AT-02-15-02` enthalten) | CC BY 4.0 (Mail der Euregio; LWD Kärnten verweist auf lawinenwarndienst.ktn.gv.at/info/opendata) | `AT-02` (seit 16.11.2026) |
| Lombardei (Livigno) | EAWS-Sammlung `static.avalanche.report/eaws_bulletins/{datum}/{datum}-IT-25.json`; am 16.11.2026 mit den Dateien vom 27.3. und 17.4.2026 fehlerfrei gelesen, dort aber nur `IT-25-SO-01` bis `-04`, nie `IT-25-SO-LI`: Livigno bekommt so vermutlich keine Stufe (offen, siehe Nächste Schritte) | Open Data über AINEVA (bollettini.aineva.it/more/open-data, Lizenztext von hier nicht abrufbar); Quelle Centro Regionale Neve e Valanghe di ARPA Lombardia nennen (Mail ARPA Lombardia) | `IT-25` (seit 16.11.2026) |
| Vorarlberg (Kleinwalsertal) | seit 17.11.2026 dieselbe GEOSPHERE-Datei wie Salzburg, gespeichert nur `AT-08`; am 17.11.2026 mit den Dateien vom 27.3. und 17.4.2026 geprüft, `AT-08-02` (Kleinwalsertal) enthalten | frei nutzbar, Bedingungen laut vorarlberg.at/impressum (Mail LWD Vorarlberg, 17.11.2026), Auflagen siehe unten | `AT-08` (seit 17.11.2026) |
| Steiermark (seit 9.11.2026) | `static.avalanche.report/eaws_bulletins/{datum}/{datum}-AT-06.json`, EAWS-Sammlung | offen (angefragt 14.11.2026) | – |

LWD Tirol hat über die Euregio geantwortet (eingegangen bis 16.11.2026, Fynn Renner): Tiroler und Kärntner Berichte CC BY 4.0, Abrufweg static.avalanche.report, Ausgabe täglich 17 Uhr, Aktualisierungen jederzeit, Abruf nach Fair-use-Prinzip. `AT-07` seit 16.11.2026 freigegeben. LWD Bayern hat am 10.11.2026 geantwortet (siehe oben). Beim SLF im Verteiler „Avalanche Bulletin“ angemeldet (info@dablank.de, bestätigt am 9.11.2026, Vorgang DATA-2406). Im selben Vorgang am 10.11.2026 angefragt, ob die IMIS-Schneehöhen (Oberengadin, ein- bis zweimal am Tag über `measurement-api.slf.ch`, mit Quellenangabe) angezeigt werden dürfen; Antwort steht aus.

Weder Österreich noch Italien hat einen gemeinsamen Warndienst, der Freigaben erteilt: Zuständig ist je Bundesland bzw. Region der eigene Dienst. lawinen.at und das gemeinsame Ausgabetool von Salzburg, Kärnten, Steiermark, Ober- und Niederösterreich bündeln nur die Anzeige; in Italien koordiniert AINEVA, den Bericht gibt aber jede Region heraus (Südtirol und Trentino über die Euregio). Eine Freigabe gilt deshalb nur für den Dienst, der sie gibt.

### Auflagen des LWD Vorarlberg (17.11.2026)

Laut Mail „gleichermaßen wie mit den Nachbarwarndiensten“, deckt sich mit den Hinweisen des LWD Bayern (oben):

| Auflage | Umsetzung |
|---|---|
| Quellenangabe „Lawinenwarndienst Vorarlberg“ mit anklickbarem Link | Name und Link `www.vorarlberg.at/lawine` bei der Region, auf der Stundenseite, im Impressum |
| Veröffentlichung und Gültigkeitsende aus `validTime` | `lawine_gueltig_text()` |
| Abgelaufene Daten nicht als aktuell; ausbleibende Aktualisierung kennzeichnen | Abgelaufene Berichte werden nicht gezeigt; `lawine_stoerung()` meldet Fehlabruf oder Stand älter als 13 h |
| Amtliche Stufe unverändert in offiziellen Farben, nicht mit eigenen Kennzahlen vermischt | EAWS-Farben, eigenes Abzeichen, geht in keine Bewertung ein; Warndreieck als „Hinweis von Wohinfahrma“ getrennt |
| Hinweis, dass die App weder Bericht noch eigene Beurteilung ersetzt | `LAWINE_HAFTUNG` |

### Kontakte der Warndienste (Stand 14.11.2026)

| Dienst | Adresse | Quelle der Adresse | Anfrage |
|---|---|---|---|
| LWD Tirol (`AT-07`) | lawine@tirol.gv.at | – | 8.11.2026; Antwort der Euregio bis 16.11.2026: CC BY 4.0, freigegeben (Erinnerung damit hinfällig) |
| LWD Bayern (`DE-BY`) | – | – | freigegeben 10.11.2026 |
| SLF (`CH-`) | data@slf.ch | – | CC BY 4.0; IMIS-Rückfrage 10.11.2026 (DATA-2406), Antwort steht aus; Erinnerung entworfen 14.11.2026, noch nicht gesendet |
| LWD Salzburg (`AT-05`) | lawine.salzburg@geosphere.at | Abwesenheitsnotiz von lawine@salzburg.gv.at (14.11.2026: „keine Weiterleitung“); die Adresse im Impressum nicht mehr nehmen | 14.11.2026; Antwort bis 16.11.2026 (Mag. Michael Reisecker): privat und kommerziell verwertbar, freigegeben |
| LWD Kärnten (`AT-02`) | abt3.katastrophenschutz@ktn.gv.at | lawinenwarndienst.ktn.gv.at (die ältere lawine@ktn.gv.at nicht nehmen) | 14.11.2026; Antwort bis 16.11.2026 (Leonardo Zoltan): Verweis auf lawinenwarndienst.ktn.gv.at/info/opendata; CC BY 4.0 laut Euregio, freigegeben |
| LWD Steiermark (`AT-06`) | katastrophenschutz@stmk.gv.at | Lagebericht 2024, Impressum lawis.at | 14.11.2026 gesendet, Antwort steht aus |
| LWD Vorarlberg (`AT-08`) | lawinenwarndienst@lwz-vorarlberg.at (Antwort von andreas.pecl@lwz-vorarlberg.at) | Lageberichte 2024 | 14.11.2026; Antwort 17.11.2026 (Andreas Pecl): frei nutzbar, freigegeben; möchte informiert werden, wenn die Anwendung online ist |
| ARPA Lombardia, Bormio (`IT-25`, Livigno) | nivometeo@arpalombardia.it | Bollettino Dezember 2025 | 14.11.2026; Antwort bis 16.11.2026 (Segreteria): Open Data über AINEVA, Quelle nennen; freigegeben |

Nach dem Versand das Datum eintragen; nach einer Freigabe `freigegeben` im Katalog ergänzen.

### Neue Region: Lawinenlage prüfen (Arbeitsregel seit 14.11.2026, Wunsch Tobias)

Bei jeder neuen Schnee-Region, ohne eigene Rückfrage:

1. Mikroregion bestimmen (`eaws`, Action „EAWS-Regionen zuordnen“) und den zuständigen Warndienst (`warndienst`).
2. Prüfen, ob der Dienst im Katalog unter `lawinendienste` steht und das Präfix in `freigegeben` enthalten ist. Wenn ja: fertig.
3. Wenn nein: Gibt es einen maschinenlesbaren Bericht (CAAML, EAWS-Sammlung), und unter welcher Lizenz? Steht eine offene Lizenz (z. B. CC BY) beim Dienst selbst, mit Quelle freigeben und hier vermerken.
4. Sonst: Adresse des Diensts recherchieren (bevorzugt aktuelles Impressum oder Kontaktseite, sonst Lagebericht); eine Adresse aus dem Impressum kann trotzdem veraltet sein (Salzburg, 14.11.2026), Quelle angeben, in die Kontakttabelle eintragen. Vorher prüfen, ob der Dienst schon angefragt ist (Tabelle oben), damit keine doppelte Anfrage entsteht.
5. Anfrage als Mail entwerfen, nach dem Muster der Anfragen vom 14.11.2026: Seite und Zweck, betroffene Regionen, Frage nach Erlaubnis und Lizenz, Umfang (nur Stufe und Probleme, Texte verlinkt, Quellenangabe mit Ausgabe- und Gültigkeitszeit, Abruf dreimal am Tag, Haftungshinweis), Verweis auf die bestehenden Freigaben, bis zur Antwort nur Link. Sprache des Diensts (Italien: italienisch, Frankreich: französisch), Absender Tobias Blank, wohinfahrma.de. Tobias verschickt selbst.
6. Bis zur Freigabe bleibt der Dienst ohne `freigegeben`; die Seite zeigt nur den Link zum Bericht.

### Mikroregionen

Seit 9.11.2026 haben alle Regionen echte IDs, bestimmt mit `tools/eaws_zuordnen.php` (Action „EAWS-Regionen zuordnen“): Grenzen der Mikroregionen aus `gitlab.com/eaws/eaws-regions` (CC BY 4.0), je Region Vorhersagepunkt, Anfahrt und fünf Punkte 5 km rundum. Livigno ist eine eigene Mikroregion (`IT-25-SO-LI`, Warndienst `lombardia`, Bericht in der EAWS-Sammlung unter `IT-25`, Lizenz offen). `eaws_namen` bleibt als Notbehelf für neue Regionen.

## Beim ersten echten Lauf prüfen

Stand 11.11.2026 (Action „Daten prüfen“): 0 bis 2 erledigt – Raster mit 294 Punkten ohne HTTP 429, alle Quellen grün, `elevation` als Liste angenommen (geliefert = Tourhöhe), `past_days` kommt an, Ensemble liefert `snow_depth`, aber `freezing_level_height` nur null (ECMWF und Ensemble), `snowfall_height` nur bei ICON. 3: Modell-Schneehöhe oft unter den Stationen (siehe README). 4 und 5: noch offen, bis die Saison beginnt (Euregio und Bayern 404, SLF ohne Berichtsteile). 6: siehe Vorrechnen in README.


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
| Lagebericht Bayern | `static.lawinen-warnung.eu`, CAAML v6 XML | Weiterverarbeitung erlaubt (10.11.2026) | eingebaut |
| Übrige Länder, AINEVA, Frankreich | – | offen bzw. eingeschränkt | später |
| Schneehöhe Stationen | Tirol: LWD/HD Tirol über data.gv.at (GeoJSON, stündlich, CC BY 4.0), seit 9.11.2026 eingebaut. Schweiz: SLF IMIS `measurement-api.slf.ch` (CC BY 4.0; laut SLF-Bedingungen aber „nicht für die Speisung von Informationssystemen“, Rückfrage beim SLF seit 10.11.2026 offen). Südtirol: `geoservices.buergernetz.bz.it/services/meteo/v1/` (CC0), seit 9.11.2026 eingebaut. GeoSphere TAWES und MeteoSchweiz eingebaut. Bayern: keine offene Schnittstelle gefunden. GeoSphere SNOWGRID (CC BY 4.0, Modell) eingebaut | Stand 9.11.2026 | eingebaut außer SLF und Bayern |
| Schnee und Wetter | Open-Meteo | CC BY 4.0 | Variablen dokumentiert, Verhalten im Gebirge ungeprüft |

Für die Rechnung nur Stufe und strukturierte Felder; Texte der Berichte nicht kürzen oder umschreiben, sondern verlinken.

## Nächste Schritte

1. ~~Erster echter Lauf~~ (abgeglichen 11.11.2026); Lageberichte und Zuordnung Oberengadin prüfen, sobald die Saison beginnt
2. ~~Tirol freigeben~~ (16.11.2026, dazu Salzburg, Kärnten, Lombardei; Vorarlberg 17.11.2026). Offen: Steiermark; LWD Vorarlberg Bescheid geben, dass die Anzeige online ist; Lizenztext von AINEVA und Kärnten selbst lesen; Livigno: welche Zone im Lombardei-Bericht (nur `IT-25-SO-01` bis `-04` gesehen, nicht `IT-25-SO-LI`), bei ARPA Lombardia oder im AINEVA-Bericht nachsehen; Bayern: Messdaten erst über die angekündigte Schnittstelle
3. Schwellen (`SCHNEE_REGEL`, `SCHNEE_KAMM_HINWEIS`) und Tourhöhen aus eigener Erfahrung anpassen
4. Weitere Messstationen (SLF IMIS nach Antwort auf die Rückfrage vom 10.11.2026, Vorgang DATA-2406)
5. Verlauf über die Abrufe
6. Karte: Alpen-Ausschnitt feiner, Mikroregionen als Polygone (EAWS-Regionen, Lizenz im Repo prüfen)
7. Slogan für beide Modi
