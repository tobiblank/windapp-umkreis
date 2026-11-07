# Wohinfahrma

*Wo ist Wind? Fahr ma.* – [wohinfahrma.dablank.de](https://wohinfahrma.dablank.de)

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
| Regionen-Katalog (`daten/regionen.json`) | 7.11.2026: Ammersee (Stegen, Herrsching, Eching), Nördliche Adria (Bora, Scirocco), Walchensee (grob, mit Link zu Marcos Vorhersage); als Entwurf, ungeprüft: Leucate/Gruissan, Hyères/Almanarre, Fehmarn, Île de Ré, Bretagne, Normandie, Pas de Calais, Deutsche Bucht, Klitmøller, Władysławowo; dazu 15 Seen (Starnberger See, Kochelsee, Chiemsee, Bodensee, Achensee, Reschensee, Silvaplana, Gardasee Nord, Comer See Nord, Urnersee, Neuenburgersee, Attersee, Traunsee, Zeller See, Neusiedler See). 28 Regionen. Sektorgrenzen teils geschätzt, siehe `hinweis` je Eintrag. Die Tests nutzen einen eigenen festen Katalog (`tests/regionen_test.json`) |
| Sammler (`server/sammler.php`) | 7.11.2026, läuft als GitHub Action zweimal am Tag (`sammeln.yml`); gegen einen Open-Meteo-Ersatz getestet, **noch nie gegen das echte Open-Meteo gelaufen** |
| Bewertung (`server/web/lib/bewertung.php`) | 7.11.2026: Stunde fahrbar, bester Block ≥ 2 h, Ensemble-Chance, Rangliste nach Luftlinie |
| Seite Rangliste (`server/web/index.php`) | 7.11.2026: Sportart, Umkreis (Luftlinie), Standort (auf 0,1° gerundet), „Heute bis übermorgen“ und „Tag 3 bis 7“; ohne Anmeldung, nicht in Suchmaschinen (`noindex`) |
| Impressum, Datenschutz (`server/web/*.html`) | 7.11.2026, aus stegen-wind abgeleitet – bitte prüfen |
| Rangliste auf der Kommandozeile (`server/rangliste.php`) | zum Ausprobieren gegen Rohdaten |
| Hosting | Hetzner Webhosting, Subdomain wohinfahrma.dablank.de (`public_html/wohinfahrma`), Deploy per GitHub Action. Domain wohinfahrma.de am 7.11.2026 bestellt; Umleitung von der Subdomain vorbereitet, aber auskommentiert (`server/web/.htaccess`) |
| Web-App zum Installieren | 7.11.2026: `manifest.php` (Name Wohinfahrma, unter dem Symbol „Fahrma“), `sw.js` und `offline.html` nach stegen-wind (nur Offline-Seite, nichts zwischengespeichert), Symbol als Platzhalter (`icon.svg`, PNG 192/512/180). Erst nach dem Umzug auf wohinfahrma.de auf dem Handy installieren |
| Quellen für Regionen | 7.11.2026 gesichtet, siehe „Quellen für Regionen und Spots“ |
| Regel-Windlagen | 7.11.2026: Druckdifferenzen und Grundströmung statt Modellwind für Thermik- und Föhnlagen; Reschensee, Silvaplana, Gardasee Nord, Urnersee. Stärken, Tageszeiten und Saison geschätzt, siehe „Regel-Windlagen“ |
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

Sektoren in Grad, **woher** der Wind kommt, im Uhrzeigersinn, `von` inklusive, `bis` exklusive. `[275, 20]` geht über Norden. Eine Region sollte nicht über eine Wetterscheide reichen (Gardasee Nord und Süd verhalten sich verschieden).

Geplante zusätzliche Felder (`grundstroemung`, `druck`, `saison`, `links`) siehe „Quellen für Regionen und Spots“.

## Bewertung

Eine Stunde ist fahrbar, wenn sie im Tagesfenster liegt (9 Uhr Ortszeit oder Sonnenaufgang bis Sonnenuntergang), der Wind im Fenster der Sportart liegt, die Richtung zu einer Windlage passt, er nicht ablandig ist, kein Gewitter gerechnet wird (mindestens die Hälfte der Modelle WMO 95–99) und, falls die Sportart das verlangt, die Welle passt. Ein Tag geht ab 2 fahrbaren Stunden am Stück.

**Heute zählt nur der Rest** (seit 7.11.2026, wie stegen-wind): ab jetzt + 30 Minuten, auf die nächste volle Stunde gerundet, bis zum Ende des Tagesfensters. Das gilt für Modellmittel und Ensemble; die Kachel heißt dann „Heute ab 15“. Bleiben weniger als 2 Stunden, steht dort „vorbei“, und der Tag zählt nicht zu den erwarteten guten Tagen. Grenze: Eine Chance über wenige Reststunden ist eine Näherung mit derselben Regel. `REST_VORLAUF_MIN` in `bewertung.php`.

- **Modellmittel:** Mittel aller Modelle mit Wert, Richtung als Vektormittel. Liefert Fenster, Windlage, mittleren Wind, Böen.
- **Chance:** Anteil der 51 Läufe des ECMWF-Ensembles (0,25°), an denen der Tag nach denselben Regeln geht, ohne Böen, Gewitter und Welle (liefert das Ensemble nicht).
- **Rangliste:** Regionen im Umkreis (Luftlinie zum Vorhersagepunkt). Sortierung wählbar (seit 7.11.2026, Parameter `nach`): **alle Tage** (Standard) = erwartete gute Tage, also Summe der Chancen über die angezeigten Tage; **ein einzelner Tag** = Chance an diesem Tag, dann Fenster nach Modellmittel, dann näher zuerst; **Entfernung**. Bei Gleichstand gewinnt die nähere Region.

**Alle Zahlen sind Rohwerte, nicht kalibriert.** An Thermik-Spots unterschätzen die Modelle den Wind vermutlich. Sportart-Voreinstellungen wie in stegen-wind (14 / 14 / 11 / 16 / 17 / 12 / 5–18 kn); dort ist nur 14 kn Directional kalibriert, der Rest sind Faustwerte.

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

| Quelle | Inhalt | Takt als Cronjob |
|---|---|---|
| `vorhersage` | ICON-D2, ICON-EU, ECMWF IFS 9 km, GFS, AROME-HD: Wind, Richtung, Böen, Temperatur, Wettercode, Regen, Bewölkung; 7 Tage | stündlich |
| `ensemble` | ECMWF IFS 0,25°, 51 Läufe: Wind, Richtung, Bewölkung; 8 Tage | alle 6 h |
| `welle` | Marine API: Wellenhöhe, -richtung, -periode, Swell; nur `typ: meer`; 7 Tage | alle 3 h |
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
2. Auf dem Server `config.php` in den Webordner legen (Vorlage `server/web/config.example.php`, nie ins Repo), mit `data_dir` = absoluter Pfad zu `<HOME_DIR>/umkreisdaten`.
3. SSL für die Subdomain in der konsoleH (SSL Manager) einschalten.
4. Unter *Actions* einmal „Deploy zu Hetzner“ und „Sammeln“ von Hand starten (*Run workflow*).

Ein Push auf `main` mit Änderungen an `server/web/` oder `daten/regionen.json` lädt nach bestandener Prüfung automatisch hoch (`deploy.yml`). Es wird nie etwas gelöscht. `daten/regionen.json` landet als `lib/regionen.json` im Webordner (per `.htaccess` gesperrt).

Ausprobieren auf der Kommandozeile: `php server/rangliste.php wingfoil 800 48.07,11.15` (braucht `server/web/config.php` mit `data_dir`).

## Prüfen

```
php tests/test.php
```

Ohne Netz: Einzelfunktionen, Katalog, Tagesfenster, der Sammler in beiden Betriebsarten gegen `tests/fake_openmeteo.php` (eingebauter PHP-Server), die Rangliste aus den geschriebenen Daten und ein Rauchtest der Seite (HTTP-Status, keine PHP-Meldungen, Rundung des Standorts, ohne Daten, ohne config.php). Läuft vor jedem Deploy (`deploy.yml`) und auf anderen Branches (`pruefen.yml`).

## Ordner

```
daten/regionen.json     Regionen-Katalog
server/sammler.php      Sammler (Cron), außerhalb des Webordners
server/rangliste.php    Rangliste auf der Kommandozeile
server/web/             Webordner der App
server/web/lib/         Katalog und Bewertung (per .htaccess gesperrt)
tests/                  Tests und Open-Meteo-Ersatz
```

## Nächste Schritte

1. Offene Punkte zum Sammler oben prüfen
2. Weitere Regionen eintragen; zuerst Silvaplana und Urnersee mit den neuen Feldern `grundstroemung`, `saison`, `links`
3. Marco Schanda fragen, ob Wohinfahrma seine Walchensee-Prognose zeigen darf; erst danach einbauen
4. wohinfahrma.de auf `public_html/wohinfahrma` zeigen lassen, SSL, dann Umleitung in `.htaccess` einschalten; Design und endgültiges Symbol
5. Zugang aus stegen-wind übernehmen, Wohnort und Sportarten je Person
6. Stegen: kalibrierte Chance aus ammerseewind.de über einen kleinen Endpoint übernehmen
7. Mail-Benachrichtigung
8. Regel-Windlagen nach dem ersten echten Lauf prüfen (Druckniveaus im Ensemble, plausible Tage); Stärken aus eigener Erfahrung eintragen
9. Später: weitere Regeln (Achensee, Walchensee, Mistral), Spot-Meldungen, Korrektur je Spot aus Sessions
