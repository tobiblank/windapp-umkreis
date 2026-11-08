# Wohinfahrma

*Wo ist Wind? Fahr ma.* – [wohinfahrma.de](https://wohinfahrma.de)

Rangliste von Windsport-Regionen im einstellbaren Umkreis: Wo lohnt es sich in den nächsten Tagen, für die eigene Sportart? Von den Spots um den Ammersee bis Adria, Ostsee, Mittelmeer und Atlantik.

Eigenständige App. [ammerseewind.de](https://ammerseewind.de) (Repo `stegen-wind`) bleibt die spezialisierte, kalibrierte Vorhersage für Stegen; von dort stammen Aufbau und Teile des Codes.

## Grundgedanke

Windys Windalarm fragt „Ist Spot X gut?“. Diese App fragt umgekehrt „**Wo** ist es gut?“. Dafür sind die Bedingungen getrennt:

- **Person:** Sportart mit Windfenster (min, optional max), später Böen-Grenze, Welle, Wohnort.
- **Region und Spot:** welche Windlagen dort tragen, wo der Wind ablandig ist.

Die Vorhersage kommt **einmal pro Region** (ein Vorhersagepunkt). Bewertet wird **pro Spot** mit dessen Richtungen; die Region bekommt den Wert ihres besten Spots. Eine Region ohne Spots wird mit ihren eigenen Windlagen bewertet. Eine Region ganz ohne Windlagen bekommt keinen Richtungsfilter und ist als „grob“ markiert.

## Stand

| Baustein | Status |
|---|---|
| Regionen-Katalog (`daten/regionen.json`) | 7.11.2026: Ammersee (Stegen, Herrsching, Eching; Link zu ammerseewind.de), Nördliche Adria (Bora, Scirocco), Walchensee (grob, mit Link zu Marcos Vorhersage); als Entwurf, ungeprüft: Leucate/Gruissan, Hyères/Almanarre, Fehmarn, Île de Ré, Bretagne, Normandie, Pas de Calais, Deutsche Bucht, Klitmøller, Władysławowo; dazu 15 Seen (Starnberger See, Kochelsee, Chiemsee, Bodensee, Achensee, Reschensee, Silvaplana, Gardasee Nord, Comer See Nord, Urnersee, Neuenburgersee, Attersee, Traunsee, Zeller See, Neusiedler See). 28 Regionen. Sektorgrenzen teils geschätzt, siehe `hinweis` je Eintrag. Die Tests nutzen einen eigenen festen Katalog (`tests/regionen_test.json`) |
| Sammler (`server/sammler.php`) | 7.11.2026, läuft als GitHub Action zweimal am Tag (`sammeln.yml`); gegen einen Open-Meteo-Ersatz getestet, **noch nie gegen das echte Open-Meteo gelaufen** |
| Bewertung (`server/web/lib/bewertung.php`) | 7.11.2026: Stunde fahrbar, bester Block ≥ 2 h, Ensemble-Chance, Rangliste; seit 8.11.2026 Tage ohne Daten „offen“ statt 0 % |
| Seite Rangliste (`server/web/index.php`) | 7.11.2026: Sportart, Umkreis (seit 7.11.2026 Straßen-km, siehe „Fahrzeit und Abfahrt“), Standort (auf 0,1° gerundet), „Heute bis übermorgen“ und „Tag 3 bis 7“, Knopf „Teilen“ an guten Tagen (siehe „Teilen“); ohne Anmeldung, nicht in Suchmaschinen (`noindex`) |
| Impressum, Datenschutz (`server/web/*.html`) | 7.11.2026, aus stegen-wind abgeleitet – bitte prüfen |
| Rangliste auf der Kommandozeile (`server/rangliste.php`) | zum Ausprobieren gegen Rohdaten |
| Hosting | Hetzner Webhosting, seit 7.11.2026 unter **wohinfahrma.de** (Ordner `public_html/wohinfahrma`, Let's Encrypt); wohinfahrma.dablank.de und www leiten dauerhaft um (`server/web/.htaccess`). Deploy per GitHub Action |
| Web-App zum Installieren | 7.11.2026: `manifest.php` (Name Wohinfahrma, unter dem Symbol „Fahrma“), `sw.js` und `offline.html` nach stegen-wind (nur Offline-Seite, nichts zwischengespeichert), Symbol als Platzhalter (`icon.svg`, PNG 192/512/180). Erst nach dem Umzug auf wohinfahrma.de auf dem Handy installieren. Hinweis „Als App installieren“ beim ersten Besuch (Chrome/Edge/Android mit Knopf, iPhone als Text), nach „Nicht jetzt“ 30 Tage Ruhe |
| Quellen für Regionen | 7.11.2026 gesichtet, siehe „Quellen für Regionen und Spots“ |
| Regel-Windlagen | 7.11.2026: Druckdifferenzen und Grundströmung statt Modellwind für Thermik- und Föhnlagen; Reschensee, Silvaplana, Gardasee Nord, Urnersee. Stärken, Tageszeiten und Saison geschätzt, siehe „Regel-Windlagen“ |
| Verlauf über die Abrufe | 7.11.2026: dieselbe Chance aus bis zu drei früheren Abrufen, Pfeil in der Kachel und „stabil / steigt / fällt / schwankt“ in der Detailzeile, mit Zwischenspeicher; siehe „Verlauf über die Abrufe“ |
| Landkarte | 7.11.2026: Regionen als Punkte über der Rangliste, eingefärbt nach der Chance am gewählten Tag, mit Umkreis; eigene SVG-Grundlage aus Natural Earth, keine fremden Server; siehe „Landkarte“ |
| Fahrzeit und Abfahrt | 7.11.2026: Fahrzeit ab Inning laut openrouteservice (GitHub Action `fahrzeiten.yml`, braucht Secret `ORS_API_KEY`), sonst aus der Luftlinie geschätzt; „Abfahrt bis …“ an guten Tagen; Sortierung „Fahrzeit“; Umkreis nach Straßen-km. Echter ORS-Lauf am 7.11.2026: 40 von 40 Zielen; siehe „Fahrzeit und Abfahrt“ |
| Flugziele | 7.11.2026: Auswahl „Anreise: Auto / Flug“; Flug zeigt alle Flugziele ohne Umkreis, mit Flughafen und geschätztem Transfer; siehe „Flugziele“ |
| Roadtrip | 7.11.2026: dritter Reiter, Reise vom Standort und zurück über bis zu 7 Tage, Hin-, Rückfahrt und Etappen bis 5 h; die App sucht die beste Länge; siehe „Roadtrip“ |
| Woche | 7.11.2026: Reiter „Woche“, Matrix Regionen × 7 Tage; siehe „Woche“ |
| Stunden | 7.11.2026: `stunden.php`, Diagramm je Stunde mit allen Modellen, Ensemble-Spanne, Böen, Richtung, fahrbaren Stunden; Vergleich zweier Regionen; siehe „Stunden“ |
| Wasser und Neopren | 7.11.2026: Meerestemperatur aus der Marine API (neue Quelle `wasser`), Neopren-Faustregel in der Detailzeile und auf der Stundenseite; Seen nur Luft und gefühlt. **Variablenname noch nie gegen das echte Open-Meteo geprüft**; siehe „Wasser und Neopren“ |
| Wellen | 7.11.2026: Welle und Dünung in der Detailzeile und als Diagramm auf der Stundenseite; Sportarten Kite Wave, Windsurf Wave (mit Mindestwelle) und Wellenreiten; siehe „Wellen“ |
| Spot-Infos | 7.11.2026: Katalogfeld `infos` (Niveau, Zone, Sperre, Saison, Achtung, Tipp) für 15 Regionen, aus den Quellen im `hinweis`; Sperren mit Monaten wirken auf die Bewertung; siehe „Spot-Infos“ |
| Bedienung | 7.11.2026 aufgeräumt: Sortierung als Auswahlfeld im Formular; drei Ansichten Liste · Woche · Roadtrip; Zeitraum (Heute – Übermorgen / Tag 3 – 7) nur in der Liste; Erklärung eingeklappt hinter „Wie wird gerechnet?“ |
| Farbe | 7.11.2026: Einstellungen und Ansichten in Wasserblau (`--steuer`, `--akzent`), damit sie sich von den Ergebnissen abheben; Grün/Gelb/Rot bleiben der Bewertung vorbehalten. Regionen und Roadtrips mit Farbbalken links nach dem besten Tag (`beste_stufe()`) |
| Hell / Dunkel | 7.11.2026: wie das Gerät, im Fuß festlegbar auf „Hell“ oder „Dunkel“ (nur im Browser gespeichert); siehe „Hell und Dunkel“ |
| Zugang, Benachrichtigung | offen |

## Regionen-Katalog

`daten/regionen.json`, geprüft von `regionen_pruefen()` (`server/web/lib/regionen.php`).

```json
{"id": "adria-nord", "name": "Nördliche Adria", "typ": "meer",
 "punkt": [45.5, 13.0], "zeitzone": "Europe/Rome",
 "windlagen": [
   {"name": "Bora", "sektor": [20, 80], "charakter": "böig"},
   {"name": "Scirocco", "sektor": [135, 200]}
 ],
 "spots": []}
```

| Feld | Bedeutung |
|---|---|
| `id` | a-z, 0-9, `-`; eindeutig |
| `typ` | `see` oder `meer`. Nur `meer` bekommt Wellendaten |
| `punkt` | Vorhersagepunkt [Breite, Länge]. **Auf das Wasser legen**: Open-Meteo nimmt die nächste Gitterzelle, an Land ist der 10-m-Wind wegen der Bodenreibung meist schwächer, besonders bei groben Modellen |
| `zeitzone` | für Tagesfenster und Anzeige |
| `windlagen` | Windlagen, die in der ganzen Region tragen; leer = Richtung hängt am Spot |
| `spots[].windlagen` | ersetzt die Windlagen der Region für diesen Spot (z. B. Herrsching nur West) |
| `spots[].ablandig` | Sektoren, in denen der Wind ablandig ist; die Stunde zählt dann nicht. Immer am Spot, weil es an der Ausrichtung des Ufers hängt |
| `charakter` | frei, z. B. „böig“; wird angezeigt |
| `hinweis` | Herkunft und offene Fragen (nicht auf der Seite) |
| `links` | optional, `[{name, url}]`: lokale Spezialprognose, auf der Seite als „Genauer: …“ verlinkt; nur `https://` |
| `thermik` | optional, `true`: Thermik-See. Die Seite zeigt den Hinweis „Thermik“, weil das Modellmittel den Wind dort vermutlich unterschätzt |
| `anreise` | optional, `["auto"]` (Standard), `["flug"]` oder `["auto", "flug"]`: in welcher Ansicht die Region erscheint. Reine Flugziele bekommen keine Fahrzeit |
| `flughafen` | optional, `{name, transfer_min}`: Flughafen für die Ansicht „Flug“, Transfer zum Spot in Minuten (geschätzt) |
| `infos` | optional, `[{art, text, quelle, monate, sportarten}]`: Spot-Infos, `art` aus niveau, zone, sperre, saison, gefahr, tipp. Eine `sperre` mit `monate` [von, bis] und `sportarten` (Gruppen: kite, windsurf, wing, surf, segeln) sperrt diese Tage für diese Sportarten |
| `anfahrt` | optional, `[Breite, Länge]`: Ziel für die Fahrzeit (Parkplatz am Spot). Ohne: `punkt` des ersten Spots, sonst der Vorhersagepunkt (der auf dem Wasser liegt und erst auf die nächste Straße einrasten muss) |

Sektoren in Grad, **woher** der Wind kommt, im Uhrzeigersinn, `von` inklusive, `bis` exklusive. `[275, 20]` geht über Norden. Eine Region sollte nicht über eine Wetterscheide reichen (Gardasee Nord und Süd verhalten sich verschieden).

Geplante zusätzliche Felder (`grundstroemung`, `druck`, `saison`, `links`) siehe „Quellen für Regionen und Spots“.

## Bewertung

Eine Stunde ist fahrbar, wenn sie im Tagesfenster liegt (9 Uhr Ortszeit oder Sonnenaufgang bis Sonnenuntergang), der Wind im Fenster der Sportart liegt, die Richtung zu einer Windlage passt, er nicht ablandig ist, kein Gewitter gerechnet wird (mindestens die Hälfte der Modelle WMO 95–99) und, falls die Sportart das verlangt, die Welle passt. Ein Tag geht ab 2 fahrbaren Stunden am Stück.

**Tage ohne Daten** (seit 8.11.2026): Am 8.11.2026 zeigte Adria Nordwest für Windsurf Wave am Samstag 0 %, obwohl Windy dort 2 m Welle und über 20 kn sah. Ursache: Der letzte Sammler-Lauf war von 20:59 UTC am Vortag (geplant 17:25), Modellmittel und Marine API reichten mit 7 Tagen nur bis Freitag, das Ensemble mit 8 Tagen bis Samstag; ohne Welle zählte jeder Ensemble-Lauf als „keine Welle“. Seitdem holt der Sammler alle Quellen für 8 Tage (die Marine API erlaubt laut Doku höchstens 8), und ein Tag ohne Werte im Fenster ist **offen**: Fehlt das Modellmittel, heißt der Grund „noch keine Vorhersage“; fehlt am Meer für eine Wave-Sportart oder Wellenreiten die Welle, „noch keine Wellenvorhersage“. Ensemble-Läufe zählen bei Wave-Sportarten am Meer nur mit Welle (`welle_fehlt`). Bleibt keine Chance, zeigen Kachel, Woche und Stundenseite „?“ statt 0 %, die Karte überspringt den Tag, Roadtrip und erwartete gute Tage zählen ihn nicht. Ohne Modellmittel, aber mit Ensemble (Kite, Wing usw.) bleibt die Chance stehen. An Seen bleibt „keine Wellendaten“, dort gehen Wave-Sportarten nie.

**Heute zählt nur der Rest** (seit 7.11.2026, wie stegen-wind): ab jetzt + 30 Minuten, auf die nächste volle Stunde gerundet, bis zum Ende des Tagesfensters. Das gilt für Modellmittel und Ensemble; die Kachel heißt dann „Heute ab 15“. Bleiben weniger als 2 Stunden, steht dort „vorbei“, und der Tag zählt nicht zu den erwarteten guten Tagen. Grenze: Eine Chance über wenige Reststunden ist eine Näherung mit derselben Regel. `REST_VORLAUF_MIN` in `bewertung.php`.

- **Modellmittel:** Mittel aller Modelle mit Wert, Richtung als Vektormittel. Liefert Fenster, Windlage, mittleren Wind, Böen.
- **Chance:** Anteil der 51 Läufe des ECMWF-Ensembles (0,25°), an denen der Tag nach denselben Regeln geht, ohne Böen, Gewitter und Welle (liefert das Ensemble nicht).
- **Rangliste:** Regionen im Umkreis (seit 7.11.2026 nach Straßen-km, siehe „Fahrzeit und Abfahrt“; vorher Luftlinie zum Vorhersagepunkt). Sortierung wählbar (seit 7.11.2026, Parameter `nach`): **alle Tage** (Standard) = erwartete gute Tage, also Summe der Chancen über die angezeigten Tage; **ein einzelner Tag** = Chance an diesem Tag, dann Fenster nach Modellmittel, dann näher zuerst; **Entfernung**. Bei Gleichstand gewinnt die nähere Region.

## Teilen

Seit 7.11.2026. Der Knopf „Teilen“ steht in der Detailzeile eines Tages, wenn das Modellmittel ein Fenster sieht und die Chance mindestens 40 % ist (ohne Ensemble: Fenster genügt). Auf dem Handy öffnet er das Teilen-Menü (Web Share), am Rechner kopiert er Text und Link; ohne beides bleibt er verborgen.

- **Text:** Stand beim Teilen, mit Datum statt „Morgen“: „Nördliche Adria, So 8.11.: Bora · 10–15 Uhr · Ø 22 kn, Böen bis 32 kn · 100 % Chance (Kite Twintip)“.
- **Link:** `?sport=…&region=<id>&nach=<YYYY-MM-DD>`. Zeigt nur diese Region mit dem Tag markiert, mit der **dann aktuellen** Vorhersage; Zeitraum folgt aus dem Tag (Tag 3–6 → „Tag 3 bis 7“). Kein Umkreis, kein Standort im Link, keine Entfernung, keine Auswahl; die Ansicht überschreibt die gemerkte Wahl des Empfängers nicht. Ist der Tag vorbei, steht ein Hinweis dabei.

## Verlauf über die Abrufe

Seit 7.11.2026. Vor einer langen Fahrt zählt nicht nur die Chance, sondern ob sie hält. Für jeden angezeigten Tag rechnet `verlauf_rechnen()` dieselbe Chance aus bis zu drei früheren Ensemble-Abrufen (`VERLAUF_FRUEHER`), höchstens drei UTC-Tage zurück, mit **demselben Fenster wie jetzt** (heute also mit dem Restfenster), damit sich nur die Vorhersage ändert, nicht die Frage.

- **Gleiche Modellläufe zählen einmal:** Liefert Open-Meteo bei zwei Abrufen denselben ECMWF-Lauf, ist der Kontrolllauf gleich (Fingerabdruck); der ältere Abruf wird übersprungen. Ein Abruf heißt also nicht zwingend ein neuer Modelllauf, aber zwei gezählte Werte stammen aus verschiedenen.
- **Regel-Windlagen:** Druck und Höhenwind kommen aus dem Abruf, der zeitlich am nächsten liegt (höchstens 90 Minuten). Fehlen sie, zählt der frühere Abruf für diese Region nicht.
- **Einordnung** (`verlauf_trend()`, Schwelle 15 Prozentpunkte): *stabil* (mindestens 3 Werte, Spanne ≤ 15), *steigt* / *fällt* (jetzt mindestens 15 über bzw. unter dem ältesten Wert), *schwankt* (größere Spanne ohne klare Richtung), *kaum verändert* (nur 2 Werte).
- **Anzeige:** In der Kachel „↑ von 40“ bzw. „↓ von 80“, nur bei steigt/fällt; in der Detailzeile z. B. „stabil seit 4 Abrufen“ oder „fällt (80 → 55 → 40 %)“; im Tooltip die Uhrzeiten der Abrufe.
- **Zwischenspeicher:** Frühere Abrufe ändern sich nicht. Das Ergebnis liegt je Sportart, Zeitraum und Stunde in `<data_dir>/cache/verlauf-*.json` (Einstellung `cache_dir` in `config.php`, `null` = aus); Dateien älter als ein Tag werden gelöscht. Gemessen mit 40 Regionen und Testdaten: erster Aufruf 0,9 s, danach 0,23 s.

**Rechenzeit** (gleicher Tag): Die Umrechnung der Zeitreihe in Ortszeit lief bis dahin für jeden der 51 Ensemble-Läufe einzeln (`ortszeiten()` macht das jetzt einmal je Abruf); die Rangliste für 40 Regionen brauchte dadurch 2,4 s, jetzt 0,16 s. `daten_laden()` entpackt ältere Zeilen nur noch, wenn ihnen noch Regionen fehlen. 

**Speicher** (7.11.2026 abends): Nach dem Verlauf zeigte wohinfahrma.de HTTP 500. Nachgestellt mit 43 Regionen und vier Läufen in Originalgröße (Ensemble rund 5 MB je Lauf, wie im echten Sammler-Lauf um 16:05 UTC) brauchte die Seite 146 MB und brach bei `memory_limit=128M` in `verlauf_rechnen()` ab; schon die Fassung davor lag bei 136 MB. Das Limit bei Hetzner selbst ist weiter ungeprüft, 128 MB ist also die wahrscheinliche, nicht die belegte Ursache. Seitdem:
- `zeile_eintraege()` teilt die `payload`-Liste als Text in ihre Einträge (jeder beginnt mit `{"latitude":`) und entpackt nur, was gebraucht wird; passt die Teilung nicht, wird wie bisher die ganze Zeile entpackt.
- `daten_laden(…, nur: [ids])` behält Einträge nur für Regionen im Umkreis; Stand (`_ts`) und Fingerabdruck des Ensembles (`_fp`) gibt es für alle, der Verlauf braucht nur diese.
- Die Seite gibt die Rohdaten nach der Rangliste frei.
- Ergebnis mit denselben Daten: 96 MB (1000 km), 108 MB (2500 km), Seite unverändert. Den Rest braucht vor allem `ensemble_stunden()` für die größte Region und der Stand selbst; er wächst mit der Zahl der Regionen im Umkreis.
- `index.php` bittet um 256 MB, falls weniger eingestellt ist (wirkt nur, wo der Server das erlaubt), und zeigt bei einem Abbruch eine lesbare Seite mit dem eingestellten `memory_limit` statt eines leeren HTTP 500.

## Landkarte

Seit 7.11.2026. Über der Rangliste, einklappbar (der Browser merkt sich den Zustand), nicht in der geteilten Ansicht.

- **Punkte:** je Region am Vorhersagepunkt. Farbe und Zahl wie in den Kacheln: die Chance am Tag, der unter „Sortiert nach“ gewählt ist; bei „alle Tage“ oder „Entfernung“ die beste Chance im Zeitraum (Tooltip nennt den Tag). Zahl erst ab 15 %. Gute Punkte liegen oben. Antippen öffnet die Stundenseite der Region für den gewählten Tag, ohne gewählten Tag für ihren besten Tag (seit 8.11.2026; vorher Sprung zur Regionskarte `#r-<id>`).
- **Windpfeil** (seit 8.11.2026, wie die Stationen auf ammerseewind.de): Spitze am Rand des Punkts, zeigt, wohin der Wind weht; in der Farbe des Punkts. Am guten Tag Mittel und Richtung im Fenster des Modellmittels (bei Regel-Windlagen typische Stärke und Sektormitte), sonst Mittel über das Tagesfenster (`tag_wind()`); unter 3 kn keine Spitze (`KARTE_PFEIL_AB_KN`). Wellenreiten: immer das Tagesmittel. Im Tooltip „aus NO, Ø 18 kn im Fenster“.
- **Standort** (seit 8.11.2026): Männchen in der Akzentfarbe, steht mit den Füßen am Standort (Inning oder der eigene), über den Punkten gezeichnet, damit es zwischen den Alpenregionen nicht verschwindet. Bei Flugzielen kein Standort.
- **Farbfeld Windstärke** (seit 8.11.2026): weicher Fleck je Region (SVG-Unschärfe) in der Windskala von ammerseewind.de (`windfarbe()`, angelehnt an Windy), dazu die Legende 0–40 kn. Bewusst nur an den Regionen, nicht dazwischen: über Hunderte Kilometer und die Alpen hinweg wäre eine Mischung wie im Windfeld von ammerseewind.de erfunden. Knopf „Farbfeld Windstärke“ schaltet es aus und an (Standard an, gemerkt in `wohinfahrma_farbfeld`).
- **Namen:** die besten zuerst, rechts oder links vom Punkt, nur wo sie nichts verdecken; im großen Umkreis bekommen deshalb nicht alle einen.
- **Ausschnitt:** alle Regionen im Umkreis und der Standort, etwas höher als breit; gestrichelt der Umkreis als Luftlinie (Kreis um den Standort, in Mercator nur ungefähr). Weil der Umkreis die Straße zählt, fehlen am Rand manche Regionen innerhalb des Kreises; das steht unter der Karte.
- **Grundlage:** `server/web/karte.svg` (108 KB, gezippt rund 30 KB), Länder mit großen Seen aus Natural Earth 1:50 Mio. (gemeinfrei), 20° W bis 36° O, 22° bis 64° N, Mercator. Kleine Seen wie der Ammersee fehlen in diesem Maßstab. Neu erzeugen mit `python3 tools/karte_bauen.py <ne_50m_admin_0_countries_lakes.geojson> server/web/karte.svg` (nur Standardbibliothek); die Projektion steht zusätzlich in `lib/karte.php`, der Test vergleicht beide. Regionen außerhalb des Ausschnitts meldet der Test.
- Die Seite bindet die Grundlage mit `<use href="karte.svg#land">` ein: einmal geladen, dann aus dem Browser-Cache, und die Farben kommen aus `stil.css` (hell und dunkel).

## Fahrzeit und Abfahrt

Seit 7.11.2026. Angezeigt und sortiert wird nach Fahrzeit mit dem Auto, ohne Verkehr, Pausen und Grenzwartezeiten.

- **Umkreis nach Straße** (seit 7.11.2026, Wunsch: bei 1.000 km standen Île de Ré mit 1.298 km und Normandie mit 1.117 km Straße in der Liste): Eine Region gehört zum Umkreis, wenn die Straßen-km höchstens so groß sind. Genau aus `fahrzeiten.json`, sonst geschätzt als Luftlinie × Umwegfaktor (`fahrt_umweg()`: Median Straße/Luftlinie der genauen Werte bis 1500 km, ab 5 Werten, begrenzt auf 1,05–1,8; ohne Datei 1,25; ab Inning mit den Daten vom 7.11.2026: 1,30). Ohne Landweg zählt die Luftlinie. Angezeigt „≈ 1.180 km Straße“, auf 10 km gerundet. Mit den echten Daten ab Inning fallen bei 1.000 km sieben Regionen weg (Leucate, Île de Ré, Normandie, Władysławowo, Sinis, Golf de Roses, Gargano), bei 400 km die beiden Adria-Regionen und der Neuenburgersee. Die Vorauswahl beim Laden bleibt die Luftlinie (Straße ist praktisch nie kürzer).

- **Abruf:** `server/fahrzeiten.php` als GitHub Action `fahrzeiten.yml` (bei Änderung des Katalogs auf `main`, am 1. jedes Monats, von Hand). Ziel je Region ist `anfahrt`, sonst der `punkt` des ersten Spots, sonst der Vorhersagepunkt. Erst rastet jedes Ziel auf die nächste Straße ein (ORS-Snapping mit 2, 10, 30 km Radius; lehnt der Server einen Radius ab, bleibt es beim kleineren), dann **eine** Matrix-Anfrage von Inning zu allen Zielen. Ergebnis `fahrzeiten.json` im Datenordner. Gesendet werden nur Katalog-Koordinaten und Inning, **nie der Standort von Besuchern**.
- **Kontingent:** Kostenloser ORS-Plan mit API-Schlüssel; laut ORS-Forum 500 Matrix-Anfragen am Tag und bis zu 3500 Strecken je Anfrage. Hier eine Anfrage je Lauf, dazu eine Snapping-Anfrage je Radius.
- **Genau oder geschätzt:** Genau nur, wenn der Standort Inning ist (± 0,05°). Sonst `Sockel + Faktor × km Luftlinie`, beides als robuste Gerade (Theil-Sen: Median der Steigungen, ein Fähr-Ausreißer verschiebt sie kaum) aus den genauen Werten bis 1500 km Luftlinie (weiter weg sind Fähren dabei; ab 5 Werten, Sockel 0–60 min, Faktor 0,5–1,2 min/km); ohne Datei 15 min + 0,75 min/km (geschätzt: Umweg ~1,25, Autobahn ~100 km/h). Angezeigt mit „≈“, auf 15 Minuten gerundet. Bis 7.11.2026 nur ein Faktor von 0,95 min/km: Pas de Calais kam auf ≈ 12:15 h statt etwa 9–10 h. Ohne Straße am Ziel fehlt die Region in der Datei und wird ebenfalls geschätzt (Abhilfe: `anfahrt` setzen). Findet ORS eine Straße, aber keine Route (Insel ohne Fähre im Netz), steht dort „kein Landweg“, auch für andere Standorte.
- **Abfahrt** in der Detailzeile guter Tage: Beginn des Fensters (Ortszeit der Region) minus Fahrzeit minus 15 Minuten Aufbau, in deutscher Zeit, auf 5 Minuten abgerundet: „Abfahrt bis 9:15“. Vor 5 Uhr: „Anreise am Vortag“. Heute, wenn die Zeit vorbei ist: „jetzt los: ab 13:15 dort“ (Ortszeit), sofern danach noch 2 Stunden im Fenster bleiben, sonst „heute nicht mehr erreichbar“. Nicht in der geteilten Ansicht.
- **Quellenangabe** im Fuß, wenn die Datei da ist: openrouteservice/HeiGIT und OpenStreetMap (ODbL).

**Ungeprüft** (nur gegen `tests/fake_ors.php`): die Pfade `/v2/snap/driving-car` und `/v2/matrix/driving-car` beim gehosteten ORS, der größte erlaubte Snapping-Radius, die Einheit von `snapped_distance` (angenommen Meter), ob die Matrix bei fehlender Route `null` oder `0` liefert (beides gilt als keine Route) und wie ORS Fähren einrechnet (Sardinien, Sizilien). Beim ersten Lauf die Ausgabe von `--debug` in der Action ansehen.

## Flugziele

Seit 7.11.2026. Im Formular „Anreise: Auto / Flug“ (Parameter `anreise=flug`, wird wie die übrige Wahl gemerkt).

- **Auto:** wie bisher, Umkreis um den Standort; Regionen ohne `auto` in `anreise` fehlen.
- **Flug:** alle Regionen mit `flug` in `anreise`, ohne Umkreis und ohne Standort. Im Kopf „Flug: Lanzarote (ACE)“ und „≈ 25 min Transfer“; „Sortiert nach Transfer“ statt Fahrzeit; keine Abfahrtszeit; Karte ohne Standort und Umkreis. Flugzeiten, Verbindungen und Preise sind nicht berücksichtigt.
- **Eingetragen** (von Claude, Flughafen und Transfer geschätzt, im `hinweis` vermerkt): nur Flug: Famara (ACE, 25 min), Fuerteventura Nord (FUE, 35), El Médano (TFS, 10), Prasonisi (RHO, 90). Auto und Flug: Sinis (CAG, 90), Kartibubbo (TPS, 40), Tarifa (AGP, 105). Sardinien und Sizilien gehen mit Fähre auch mit dem Auto, Tarifa ist rund 20 Stunden entfernt.
- Der Fahrzeiten-Abruf überspringt reine Flugziele.

## Roadtrip

Seit 7.11.2026, am selben Abend neu gedacht: Die erste Fassung (Ketten guter Tage an nahen Regionen, ohne Anreise) schlug „Île de Ré morgen“ bei 12 Stunden Fahrt vor und reiste nie. Jetzt ist ein Roadtrip eine Reise **vom Standort und zurück**; die App sucht die beste Länge. Dritter Reiter (`ansicht=roadtrip`), über alle 7 Tage, nur mit Anreise Auto. Code in `lib/roadtrip.php`.

- **Hinfahrt** ab 6 Uhr (heute frühestens jetzt), höchstens 10 Stunden am Tag (bis zu einer Stunde mehr, wenn man dann ankommt) und bis 23 Uhr, längere Strecken mit Übernachtung unterwegs. Reicht es am Ankunftstag noch für 2 Stunden im Fenster, ist er ein Windtag, sonst ein **Fahrtag**.
- **Jeden weiteren Tag** bleiben (Windtag oder Pause) oder **abends nach dem Fenster weiterfahren**, Etappe höchstens 5 Stunden (aus der Luftlinie geschätzt, Formel wie bei der Fahrzeit), Ankunft bis 23 Uhr. Keine Region zweimal (sonst Normandie → Île de Ré → Normandie → Pas de Calais, im Titel nicht zu sehen).
- **Rückfahrt** nach dem letzten Windtag: abends, wenn man bis 23 Uhr zu Hause ist, sonst ab dem nächsten Morgen.
- **Guter Tag:** nicht vorbei, Chance mindestens 50 % (ohne Ensemble: Modellmittel sieht ein Fenster, zählt als 50 %). Mindestens 2 Windtage.
- **Bilanz** = Summe der Chancen der Windtage − 0,08 je Fahrstunde (bis 7.11.2026 abends 0,05: zwei mäßige Tage an der Nordsee für 16,5 h Fahrt kamen nach vorn) − 0,3 je Tag ohne Wind (Fahrtag, Pause, Rückfahrtag). Damit muss ein weites Ziel deutlich mehr gute Tage bringen. Eine dynamische Programmierung je Abreisetag findet je Tag und Region die beste Bilanz; jedes Ende nach einem Windtag ist ein Kandidat, also jede Länge.
- **Vorschläge:** die 5 besten mit positiver Bilanz; ein Vorschlag fällt weg, wenn mindestens die Hälfte seiner Windtage am selben Ort liegt wie bei einem besseren. Es sind Alternativen.
- **Anzeige:** Route im Titel („Achensee → Adria Nordost“), Windtage, Fahrzeit und Kilometer insgesamt (Straße laut ORS, sonst Luftlinie × 1,25), Zeitraum; Hinfahrt mit „los bis 7:25“ (Fensterbeginn minus Fahrt und 15 min Aufbau) oder Abfahrt und Ankunft; je Tag Chance, Region (Link in die passende Ansicht), Windlage, Fenster, Wind; Pausen und Fahrtage; Etappen; Rückfahrt mit Ankunft zu Hause.
- **Grenzen:** Zeitzonen zwischen den Regionen werden ignoriert (in Europa höchstens eine Stunde). Ob die Lage über mehrere Tage stabil bleibt, prüft niemand. Kosten und Schwellen sind geschätzt (Konstanten oben in `roadtrip.php`). Kein Verlauf und keine Karte in dieser Ansicht.

## Woche

Seit 7.11.2026. Reiter „Woche“ (`ansicht=woche`) zwischen „Tag 3 bis 7“ und „Roadtrip“: eine Tabelle mit einer Zeile je Region im Umkreis (bzw. je Flugziel) und den 7 Tagen als Spalten. Zelle = Chance in der Farbe der Kacheln; leer = 0 %, „ja“ = Fenster nach Modellmittel ohne Ensemble, heute nach dem Fenster leer. Unter dem Namen die Fahrzeit (bzw. der Flughafen). Tooltip mit Fenster und Wind. Zeile und Zelle verlinken in die passende Ansicht der Region. Sortieren: „alle Tage“, „Fahrzeit“ oder Spaltenkopf antippen (ein zweites Antippen hebt es auf). Karte wie sonst darüber; kein Verlauf in dieser Ansicht.

## Stunden

Seit 7.11.2026. Eigene Seite `server/web/stunden.php?region=<id>&sport=…&tag=YYYY-MM-DD&vergleich=<id>`, verlinkt aus jeder Detailzeile („Stunden ›“), aus jeder Zelle der Woche und seit 8.11.2026 aus der Regionskarte: Tageskachel = dieser Tag, Name oder übrige Fläche der Karte = gewählter Tag bzw. bester Tag (`stunden_link()`; ein kleines Skript macht die ganze Karte antippbar, Links, Knöpfe, „Spot-Infos“ und markierter Text bleiben ausgenommen), dazu aus den Punkten der Landkarte. Rechnung in `lib/stunden.php` (`region_stunden()`), für den besten Spot des Tages wie in der Rangliste.

- **Tage** als Knöpfe mit Chance; ohne `tag` der beste Tag.
- **Diagramm** (SVG ohne Fremdbibliothek, Farben aus `stil.css`): Tagesfenster hinterlegt, Wind je Modell dünn, Modellmittel dick, Böen gestrichelt, Band = 10. bis 90. Perzentil der 51 Ensemble-Läufe, Windfenster der Sportart als waagrechte Linien, darüber Pfeile (wohin der Wind weht), darunter je Stunde: fahrbar im Modellmittel (grün; Tooltip mit Grund) und Anteil der Ensemble-Läufe, die die Stunde fahrbar sehen. Zeigt das Tagesfenster ±2 Stunden, Ortszeit der Region.
- **Vergleich:** zweite Region im selben Maßstab darunter (Auswahl am Ende der Seite).
- Bei Regel-Windlagen rechnet die Bewertung mit der typischen Stärke, die Linien zeigen trotzdem den Modellwind.

## Wasser und Neopren

Seit 7.11.2026.

- **Quelle:** Sammler-Quelle `wasser`, Marine API `hourly=sea_surface_temperature`, nur `typ: meer`, alle 6 h, mit `cell_selection=sea` wie die anderen Meer-Abrufe. Eigene Quelle, damit ein Fehler dort die Wellendaten nicht mitreißt. Den Variablennamen kenne ich aus der Variablenliste der Rust-Bibliothek openmeteo-rs, nicht aus einem echten Abruf: beim ersten Lauf in der Action prüfen, ob `wasser` grün ist.
- **Je Tag** (`tag_temperaturen()`): Mittel von Luft, Wasser und Wind über das gefundene Fenster (sonst das Tagesfenster), in `region_bewerten()` als `temp`.
- **Neopren** (`lib/neopren.php`), Faustregel nach dem Wasser, Grenzen geschätzt (Quellen schwanken um 1–2 °C): ab 24 °C Lycra/Shorty, 21 Shorty 2 mm, 18 3/2, 15 4/3, 12 5/4 + Schuhe, 9 5/4–6/5 mit Haube, Schuhen, Handschuhen, darunter 6/5 mit allem. Eine Stufe wärmer, wenn die gefühlte Temperatur mehr als 5 °C unter dem Wasser liegt. Gefühlt = Windchill (Environment Canada/NWS) bis 10 °C Luft und ab 4,8 km/h Wind, darüber die Luft.
- **Seen:** keine Wassertemperatur, deshalb keine Empfehlung, nur „Luft 12 °C, gefühlt 6 °C“. Im Frühjahr ist das Wasser viel kälter als die Luft, im Herbst wärmer; die Luft allein wäre irreführend. Später denkbar: Messwerte bayerischer Seen (Gewässerkundlicher Dienst), Nutzungsbedingungen prüfen.
- **Anzeige:** in der Detailzeile guter Tage „· Wasser 14 °C, 5/4 mm, Schuhe“ (Tooltip mit Luft und Begründung), auf der Stundenseite unter dem Fenster.

## Wellen

Seit 7.11.2026. Die Wellendaten der Marine API (Quelle `welle`, nur Meer) sammelt die App seit dem Anfang; jetzt werden sie gezeigt und bewertet.

- **Anzeige:** In der Detailzeile guter Tage am Meer „· Welle 1,2 m (Dünung 1,0 m, 11 s aus W)“, gemittelt über das Fenster (`tag_welle()`). Auf der Stundenseite ein zweites Diagramm: Balken = gesamte Welle, Linie = Dünung, Pfeile = wohin die Dünung läuft, Zahlen = Periode.
- **Kite Wave, Windsurf Wave:** Wind wie Kite/Windsurf, dazu Welle ab 1,0 m (Faustwert). Das Ensemble hat keine Welle; damit die Chance keine Tage mit zu kleiner Welle zählt, bekommt jeder Lauf die Wellenhöhe der Marine-Vorhersage (`ensemble_welle_einmischen()`).
- **Wellenreiten** (`surf`, `stunde_surfbar()`): kein Mindestwind. Eine Stunde geht bei Dünung ab 0,8 m und Periode ab 8 s (Faustwerte), wenn die Dünung nicht aus einem ablandigen Sektor des Spots kommt (vom Land kommt keine Welle; Spots ohne `ablandig` sind „grob“), der Wind höchstens 12 kn hat oder ablandig ist (dann gut, im Text „Wind ablandig“), und kein Gewitter. Dünung = Swell der Marine API, ohne Swell die gesamte Welle. Keine Ensemble-Chance; die Kacheln zeigen „ja“ oder „–“. Seen gehen nie.
- **Grenzen:** Die Marine API rechnet auf einem groben Gitter vor der Küste; Brandung, Riffe und Abschattung an einem Strand kennt sie nicht. Für die Session vor Ort eine Surfvorhersage für den Spot nehmen.

## Spot-Infos

Seit 7.11.2026. Katalogfeld `infos` je Region (siehe Tabelle oben), angezeigt als aufklappbarer Block „Spot-Infos (n)“ in der Regionskarte und auf der Stundenseite (`lib/spotinfo.php`), Reihenfolge Sperre, Zone, Achtung, Niveau, Saison, Tipp; eine Sperre steht schon im zugeklappten Titel.

- **Inhalt:** nur, was mit Quelle schon im `hinweis` stand (15 Regionen, z. B. Urnersee Kitesperre Nov/Dez, Tarifa Kitezonen 15.6.–15.9., Zandvoort Zonen und Strömung, Famara bei Hochwasser ohne Strand, Lago di Santa Croce Kiteverbot am Poetto). Nichts dazuerfunden; auf der Seite als „Stand 7.11.2026, nicht aktuell geprüft“ gekennzeichnet.
- **Sperren:** `region_sperre()` prüft Monat und Gruppe der Sportart (neues Feld `gruppe` in `SPORTARTEN`); ein gesperrter Tag geht nicht, die Ensemble-Chance ist 0, und die Karte nennt statt „Kein passender Tag“ den Grund.
- **Pflege:** Neue Infos mit Quelle eintragen; ohne Quelle lieber weglassen.

**Alle Zahlen sind Rohwerte, nicht kalibriert.** An Thermik-Spots unterschätzen die Modelle den Wind vermutlich. Sportart-Voreinstellungen wie in stegen-wind (14 / 14 / 11 / 16 / 17 / 12 / 5–18 kn); dort ist nur 14 kn Directional kalibriert, der Rest sind Faustwerte.

## Hell und Dunkel

Seit 7.11.2026. Standard wie das Gerät (`prefers-color-scheme`, z. B. Android „Dunkles Design“ mit Zeitplan). Im Fuß der Rangliste und der Stundenseite: **Wie Gerät · Hell · Dunkel**. Die Wahl steht nur im Browser (`localStorage`, `wohinfahrma_design`) und setzt `data-theme="light|dark"` auf `<html>`; ein kleines Skript im Kopf setzt sie vor dem ersten Zeichnen, damit nichts aufblitzt, und passt die Farbe der Browserleiste an (`lib/design.php`). In `stil.css` stehen die dunklen Farben zweimal (für das Gerät und für die Wahl); der Test prüft, dass beide gleich sind. Ohne JavaScript ist der Schalter unsichtbar, dann gilt das Gerät. Impressum und Datenschutz folgen weiter nur dem Gerät.

## Regel-Windlagen

Seit 7.11.2026, nach dem Vorbild lokaler Vorhersageseiten (kitewetter.at, kitesailing.ch, unhooked). Für Thermik- und Föhnlagen ist der 10-m-Modellwind am See schlecht; diese Seiten schließen aus Druckdifferenzen und Grundströmung, **ob** die Windlage kommt. Eine Windlage mit `druck` und/oder `grundstroemung` ist eine Regel-Windlage:

```json
{"name": "Malojawind", "sektor": [180, 250], "charakter": "Thermik",
 "druck": [{"plus": "lugano", "minus": "zuerich", "min": -4}],
 "grundstroemung": {"hoehe": "700hPa", "sektor": [180, 290], "kn_min": 4},
 "wetter": "sonnig", "zeit": [12, 18], "saison": [5, 10], "staerke": [15, 22]}
```

| Feld | Bedeutung |
|---|---|
| `druck` | Liste von Bedingungen: Differenz `plus` − `minus` (Meeresspiegeldruck, hPa) zwischen zwei `druckpunkte`, mit `min` und/oder `max`. Alle müssen erfüllt sein |
| `grundstroemung` | Wind in `10m`, `850hPa` oder `700hPa` am Vorhersagepunkt: `sektor`, `kn_min`, `kn_max` |
| `wetter` | `sonnig`: mittlere Bewölkung höchstens 50 % |
| `zeit` | Ortsstunden [ab, bis), in denen die Windlage weht |
| `saison` | Monate [von, bis], auch über den Jahreswechsel |
| `staerke` | **Pflicht:** typische Stärke in kn, wenn die Lage zutrifft |

Sind alle Bedingungen einer Stunde erfüllt, rechnet die Bewertung mit der Mitte von `staerke` und der Mitte des `sektor` und prüft dann wie sonst Windfenster der Sportart, ablandig und Gewitter. Böen gibt es dafür keine. Das Ensemble prüft jeden der 51 Läufe mit seinen eigenen Druck- und Höhenwerten; die Chance ist der Anteil der Läufe, an denen die Regel trägt. Die Seite zeigt solche Tage mit „typisch 15–22 kn“ und dem Hinweis **Regel**.

`druckpunkte` stehen oben im Katalog (`{id: {name, punkt}}`); der Sammler holt Luftdruck nur für sie und Höhenwind nur für Regionen, deren Regeln 850 oder 700 hPa brauchen.

**Grenzen:**
- Die Schwellen stammen von Seiten, die mit Stationsdruck (DWD Open Data) arbeiten; hier ist es Modell-Meeresspiegeldruck. Sie sind deshalb nur Startwerte.
- Stärken, Tageszeiten, Saison und die Höhe der Grundströmung bei Silvaplana sind geschätzt (im `hinweis` je Region vermerkt) und sollten aus eigener Erfahrung korrigiert werden.
- Ob das ECMWF-Ensemble bei Open-Meteo Druckniveaus (700/850 hPa) liefert, ist ungeprüft; die Quellen `hoehe` und `hoehe_ensemble` laufen deshalb getrennt, ein Fehler dort lässt die Vorhersage unberührt.
- Bora (Maribor − Triest) und Bise (Genf − Güttingen) bleiben normale Windlagen, weil die Modelle diese großräumigen Winde gut erfassen.

## Sammler

`server/sammler.php`, ein Abruf für bis zu 25 Regionen (Koordinaten kommagetrennt). Zwei Betriebsarten:

- **GitHub Action** (Standard seit 7.11.2026, `.github/workflows/sammeln.yml`): zweimal am Tag, 5:25 und 17:25 UTC (6:25 / 18:25 Uhr im Winter, eine Stunde später im Sommer). `php server/sammler.php --action daten/regionen.json ausgabe` ruft jede Quelle einmal ab und schreibt je Quelle eine gezippte Datei für den Lauf (`vorhersage/2026-11-07_0525.ndjson.gz`); die Action lädt sie per FTPS nach `<HOME_DIR>/umkreisdaten/`. Kein Cronjob auf Hetzner, Abrufe von GitHub-IPs, also nicht aus dem Open-Meteo-Kontingent von ammerseewind.de. Bei jedem Fehler wird die Action rot (GitHub mailt); was geklappt hat, ist trotzdem hochgeladen.
- **Cronjob** (alle 10 Minuten, ohne `--action`): Takt je Quelle wie unten, eine Datei je Tag, Wiederholung bei Fehlern. Für später, falls mehr Abrufe am Tag nötig werden.

**HTTP 429** (7.11.2026, Lauf mit 43 Regionen): Open-Meteo meldete beim Ensemble und den Begleitdaten „Minutely API request limit exceeded“. Der Sammler wartet bei 429 jetzt 65 s und versucht es einmal neu (`UMKREIS_WARTE_429` für Tests).

| Quelle | Inhalt | Takt als Cronjob |
|---|---|---|
| `vorhersage` | ICON-D2, ICON-EU, ECMWF IFS 9 km, GFS, AROME-HD: Wind, Richtung, Böen, Temperatur, Wettercode, Regen, Bewölkung; 8 Tage (bis 8.11.2026: 7) | stündlich |
| `ensemble` | ECMWF IFS 0,25°, 51 Läufe: Wind, Richtung, Bewölkung; 8 Tage | alle 6 h |
| `welle` | Marine API: Wellenhöhe, -richtung, -periode, Swell; nur `typ: meer`; 8 Tage | alle 3 h |
| `wasser` | Marine API: Meerestemperatur (`sea_surface_temperature`); nur `typ: meer`; 8 Tage | alle 6 h |
| `druck` / `druck_ensemble` | Meeresspiegeldruck an den `druckpunkte`, Modelle wie `vorhersage` bzw. ECMWF-Ensemble | stündlich / alle 6 h |
| `hoehe` / `hoehe_ensemble` | Wind in 850 und 700 hPa, nur Regionen mit Grundströmung in dieser Höhe | stündlich / alle 6 h |

Außerhalb ihres Gebiets liefert Open-Meteo für ein Modell null; das Mittel nimmt dann die übrigen.

**Gitterzelle:** Open-Meteo wählt die Zelle standardmäßig bevorzugt an Land (`cell_selection=land`). Am 7.11.2026 lieferte das ECMWF-Ensemble für die Adria (angefragt 45,5° N) deshalb 45,75° N mit 0 m Höhe an der friaulischen Küste: alle 51 Läufe 6–11 kn, die feineren Modelle 17 kn. Meer-Regionen werden seitdem getrennt mit `cell_selection=sea` abgerufen. Seen bleiben beim Standard, weil ungeprüft ist, wie `sea` im Binnenland wirkt. Bei kleinen Seen (Kochelsee, Zeller See) liegt die Zelle deshalb womöglich an Land; prüfen über `latitude`/`longitude`/`elevation` im ersten echten Lauf. Welche Zelle geliefert wurde, steht in `latitude`/`longitude`/`elevation` jedes Eintrags.

Format wie stegen-wind (als Cronjob eine Datei pro Quelle und Tag, ab vorgestern gezippt; als Action eine gezippte je Lauf). `daten_laden()` liest beides:

```json
{"ts":"2026-11-07T12:00:03Z","source":"vorhersage","ok":true,"http":200,"ms":812,
 "regionen":["ammersee","adria-nord"],"payload":[{...},{...}]}
```

`payload` ist immer eine Liste in der Reihenfolge von `regionen`. Als Cronjob: Bleiben die Stundenwerte gleich (kein neuer Modelllauf), wird nichts geschrieben. Ein fehlgeschlagener Abruf wird protokolliert und im nächsten Lauf wiederholt; per Cron-Mail gemeldet wird erst, wenn im ganzen Takt nichts geklappt hat.

**Ungeprüft, beim ersten echten Lauf mit `php sammler.php --debug` kontrollieren:**

- dass Open-Meteo mehrere Koordinaten in einem Abruf annimmt und als Liste zurückgibt,
- die Variablennamen der Marine API,
- nur Cronjob: die Zählung. `om_gewicht()` nimmt an, dass jeder Ort einzeln zählt, Ensemble-Läufe aber nicht extra. Beides ist eine Annahme. Als Cronjob auf demselben Server wie ammerseewind.de teilen sich beide vermutlich das freie Kontingent (`openmeteo_limit` in `config.php`).

Größe grob, aus dem Test hochgerechnet: rund 20 KB je Region und Vorhersage-Abruf, 75 KB je Region und Ensemble-Abruf, ungezippt.

## Quellen für Regionen und Spots

Stand 7.11.2026. Für Windlagen, Sektoren und `hinweis`; Regeln in eigenen Worten übernehmen, mit Link, nichts automatisch abgreifen.

| Quelle | Was sie liefert | Nutzung |
|---|---|---|
| [kitewetter.at](https://www.kitewetter.at) | Spotseiten für rund 20 Alpenreviere (u. a. Ammersee/Starnberger See, Achensee, Attersee, Bodensee, Chiemsee, Comer See, Gardasee/Idrosee, Reschensee, Silvaplana, Traunsee, Walchensee/Kochelsee, Zell am See, Neusiedler See, Adria). Arbeitet mit AROME 1,3 km, ICON-D2, INCA, MOSMIX, Föhndiagramm (Druckdifferenz Bozen–Innsbruck, Lugano–Zürich aus DWD Open Data), Thermikvorhersage, Radiosonde München | Kandidatenliste für den Katalog, Vorbild für die Methode, Link je Region. Nutzungsbedingungen nicht geprüft |
| [kitesailing.ch](https://kitesailing.ch/en/spot/weather-kitesurfing) | Silvaplana: Kiten nur bei S bis SW, N sehr selten fahrbar. Malojawind ab kurz vor Mittag bis später Nachmittag; Höhenströmung N–SO schwächt, kräftige W–SW stärkt. Schwellen der Druckdifferenz: ab 0 hPa Südföhn (gut), 0 bis −4 hPa Maloja setzt sich meist durch, unter −6 hPa Nordföhn meist zu stark | Aktuelle Quelle für Silvaplana, auch für Zonen und Saison |
| [unhooked.ch](https://www.unhooked.ch/2008/spotguide/) | Spotguide Schweiz mit „Windverhältnisse“ je Spot. **Keine eigene Vorhersage** (anders als kitesailing.ch schreibt): Das Team wertet meteoblue-Karten (10 m, 850, 700 hPa) von Hand aus | Faustregeln für die Grundströmung, siehe unten. Copyright 2008, jüngstes gesehenes Archiv 2016, keine Nutzungsbedingungen gefunden |
| Walchensee Wind Predictor (Marco Schanda), `walchenseewindpredictor.onrender.com/forecast` | Je Tag `chance`, `status` (go/maybe/no), `peak_kt`, dazu `generated_at`, `stale`; rechnet um 6, 10 und 21 Uhr; Thermikfenster laut stegen-wind 11–18 Uhr | In ammerseewind.de seit 6./7.11.2026 eingebunden, **Zustimmung gilt nur dafür**. Für Wohinfahrma erst Marco fragen. Dann nicht erneut abrufen, sondern die Datei mitlesen, die der Sammler von ammerseewind.de holt (zweiter Pfad in `config.php`). Walchensee bekommt seine Zahl statt des Rohwerts, nur für Kite-Sportarten, markiert „Prognose von Marco Schanda“; Frische wie in prognose.php: höchstens 18 h alt, nicht `stale` |
| [addicted-sports.com](https://www.addicted-sports.com/forecast/) | Spotvorhersage Walchensee Urfeld aus eigenem MOS-Modell: Grundwind, Böen, Windwahrscheinlichkeit, Druckgradient, Höhenwind 850 hPa, Schichtung 2 m–850 hPa | Link je Region, Vorbild für Thermik-Kennwerte |
| [kiteloopers.com](https://www.kiteloopers.com/legal/terms) | Rund 1700 Spots weltweit | **Nur von Hand zum Gegenprüfen.** Die Bedingungen verbieten Scraping und automatisierte Extraktion; die Übernahme wesentlicher Teile der Datenbank (Spots, Windstatistik) gilt dort als Verletzung des Datenbankrechts |

### Faustregeln aus unhooked.ch

- **Silvaplana:** SW-Grundströmung 4–10 kn und gutes Wetter → Malojawind laut Seite in etwa 95 % der Fälle; schon leichte Nordströmung vom Julier verhindert ihn. Mitte Februar bis Ende Oktober, Einsetzen 11–14 Uhr, bis 17–20 Uhr.
- **Urnersee:** Thermiktag, wenn Windfinder um 14 Uhr 4–8 kn aus NW bis NO zeigt. Südföhn eher für Windsurfer. Kiten im November und Dezember verboten, nur in ausgewiesenen Zonen.
- **Estavayer (Neuenburgersee):** Bise und Westwind, ab etwa 9–10 kn auf Windfinder.

**Wie belastbar:** Der Mechanismus hängt an der Topographie und gilt vermutlich weiter. Die Knotenwerte sind dagegen auf das Modell geeicht, das Windfinder damals zeigte (vermutlich grob, etwa GFS). Wohinfahrma rechnet mit einem anderen Modellmittel, deshalb sind sie nur Startwerte und im `hinweis` als „geschätzt, nach unhooked“ zu kennzeichnen, später gegen eigene Tage am Spot prüfen. Zonen, Verbote, Gebühren und Saison nicht von unhooked übernehmen, sondern aus einer aktuellen Quelle (z. B. kitesailing.ch). Klimawandel wirkt eher auf die Saison (Eiszeit am Silvaplanersee, Wassertemperatur) als auf den Mechanismus; belastbare Zahlen dazu liegen nicht vor.

### Folgen für die Bewertung

Thermik-Spots (Silvaplana, Walchensee, Gardasee, Urnersee) bildet das 10-m-Modellmittel schlecht ab. Die Spezialseiten stützen sich deshalb auf Grundströmung, Druckdifferenzen, Höhenwind und Schichtung. Geplante optionale Felder im Katalog:

| Feld | Bedeutung |
|---|---|
| `windlagen[].grundstroemung` | `{sektor, kn_min, kn_max}`: die Windlage zählt, wenn die Grundströmung (10 m oder 850 hPa, noch offen) passt, statt des Modellwinds am Spot |
| `windlagen[].druck` | später: Druckdifferenz zweier Punkte mit Schwelle (z. B. Lugano–Zürich für Maloja). Ungeprüft, ob Open-Meteo Luftdruck und 850-hPa-Werte für alle Modelle im Abruf liefert |
| `saison` | Monate, in denen der Spot geht oder erlaubt ist (z. B. Urnersee nicht Nov/Dez) |
| `links` | lokale Spezialprognose je Region (kitewetter.at, kitesailing.ch, addicted-sports). Wohinfahrma sagt „wohin“, die lokale Seite „wie genau“ |

Bis das eingebaut ist, Thermik-Regionen als „grob“ markieren.

## Einrichtung

1. Im Repo unter *Settings → Secrets and variables → Actions*:
   - Secrets `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD` (dieselben Werte wie bei stegen-wind)
   - Variable `WEB_DIR`: Webordner relativ zum FTP-Startordner, z. B. `public_html/wohinfahrma`
   - Variable `HOME_DIR` (optional): Ordner, in dem `umkreisdaten/` angelegt wird, relativ zum FTP-Startordner; leer = Startordner
   - Secret `ORS_API_KEY` (optional, für genaue Fahrzeiten): kostenloser Schlüssel bei openrouteservice.org; ohne Schlüssel schätzt die Seite aus der Luftlinie
2. Auf dem Server `config.php` in den Webordner legen (Vorlage `server/web/config.example.php`, nie ins Repo), mit `data_dir` = absoluter Pfad zu `<HOME_DIR>/umkreisdaten`.
3. SSL für die Subdomain in der konsoleH (SSL Manager) einschalten.
4. Unter *Actions* einmal „Deploy zu Hetzner“, „Sammeln“ und „Fahrzeiten“ von Hand starten (*Run workflow*).

Ein Push auf `main` mit Änderungen an `server/web/` oder `daten/regionen.json` lädt nach bestandener Prüfung automatisch hoch (`deploy.yml`). Es wird nie etwas gelöscht. `daten/regionen.json` landet als `lib/regionen.json` im Webordner (per `.htaccess` gesperrt).

Ausprobieren auf der Kommandozeile: `php server/rangliste.php wingfoil 800 48.07,11.15` (braucht `server/web/config.php` mit `data_dir`).

## Prüfen

```
php tests/test.php
```

Ohne Netz: Einzelfunktionen, Katalog, Tagesfenster, der Sammler in beiden Betriebsarten gegen `tests/fake_openmeteo.php` (eingebauter PHP-Server), die Rangliste aus den geschriebenen Daten, der Verlauf über frühere Abrufe, die Landkarte (Projektion, Ausschnitt, Namen), die Fahrzeiten gegen `tests/fake_ors.php` (Einrasten, Schätzung, Abfahrt) und ein Rauchtest der Seite (HTTP-Status, keine PHP-Meldungen, Rundung des Standorts, geteilte Vorhersage und Teilen-Knopf, ohne Daten, ohne config.php). Läuft vor jedem Deploy (`deploy.yml`) und auf anderen Branches (`pruefen.yml`).

## Ordner

```
daten/regionen.json     Regionen-Katalog
server/sammler.php      Sammler (Cron), außerhalb des Webordners
server/rangliste.php    Rangliste auf der Kommandozeile
server/fahrzeiten.php   Fahrzeiten bei openrouteservice (GitHub Action)
server/web/             Webordner der App
server/web/lib/         Katalog und Bewertung (per .htaccess gesperrt)
server/web/stunden.php  Stundenansicht einer Region
tests/                  Tests und Open-Meteo-Ersatz
tools/karte_bauen.py    erzeugt server/web/karte.svg aus Natural Earth
```

## Nächste Schritte

1. Offene Punkte zum Sammler oben prüfen
2. Weitere Regionen eintragen; zuerst Silvaplana und Urnersee mit den neuen Feldern `grundstroemung`, `saison`, `links`
3. Marco Schanda fragen, ob Wohinfahrma seine Walchensee-Prognose zeigen darf; erst danach einbauen
4. Design und endgültiges Symbol (in Arbeit)
5. Zugang aus stegen-wind übernehmen, Wohnort und Sportarten je Person
6. Stegen: kalibrierte Chance aus ammerseewind.de über einen kleinen Endpoint übernehmen
7. Mail-Benachrichtigung
8. Regel-Windlagen nach dem ersten echten Lauf prüfen (Druckniveaus im Ensemble, plausible Tage); Stärken aus eigener Erfahrung eintragen
9. Später: weitere Regeln (Achensee, Walchensee, Mistral), Spot-Meldungen, Korrektur je Spot aus Sessions

### Ideen, in dieser Reihenfolge (7.11.2026)

1. ~~Verlauf über die Abrufe~~ (erledigt, siehe oben)
2. ~~Karte~~ (erledigt, siehe „Landkarte“)
3. ~~Fahrzeit statt Luftlinie, mit Abfahrtsempfehlung~~ (erledigt, siehe „Fahrzeit und Abfahrt“; später: Umkreis in Stunden, genaue Zeiten auch für andere Startorte)
4. ~~Fahr- und Flugziele trennen~~ (erledigt, siehe „Flugziele“)
5. ~~Roadtrip-Modus~~ (erledigt, siehe „Roadtrip“)
6. ~~Matrix Regionen × Tage~~ (erledigt, siehe „Woche“)
7. ~~Stundenansicht je Region, Vergleich zweier Regionen~~ (erledigt, siehe „Stunden“)
8. ~~Wassertemperatur und Neoprenempfehlung~~ (erledigt fürs Meer, siehe „Wasser und Neopren“)
9. ~~Gezeiten~~ (verworfen 7.11.2026: entscheidet nicht „wohin“, sondern „wann“; das klärt eine Gezeitentabelle vor Ort. Stattdessen Wellen, siehe „Wellen“)
10. ~~Spot-Infos~~ (erledigt, siehe „Spot-Infos“)
11. Kalendereintrag (.ics) für ein gutes Fenster
12. Rückblick mit Messwerten (Pioupiou offen; Holfuy, Windguru-Stationen: Lizenz klären)
