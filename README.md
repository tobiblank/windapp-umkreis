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
| Regionen-Katalog (`daten/regionen.json`) | 7.11.2026: Ammersee (Stegen, Herrsching, Eching) und Nördliche Adria (Bora, Scirocco). Sektorgrenzen teils geschätzt, siehe `hinweis` je Eintrag |
| Sammler (`server/sammler.php`) | 7.11.2026, läuft als GitHub Action zweimal am Tag (`sammeln.yml`); gegen einen Open-Meteo-Ersatz getestet, **noch nie gegen das echte Open-Meteo gelaufen** |
| Bewertung (`server/web/lib/bewertung.php`) | 7.11.2026: Stunde fahrbar, bester Block ≥ 2 h, Ensemble-Chance, Rangliste nach Luftlinie |
| Seite Rangliste (`server/web/index.php`) | 7.11.2026: Sportart, Umkreis (Luftlinie), Standort (auf 0,1° gerundet), „Heute bis übermorgen“ und „Tag 3 bis 7“; ohne Anmeldung, nicht in Suchmaschinen (`noindex`) |
| Impressum, Datenschutz (`server/web/*.html`) | 7.11.2026, aus stegen-wind abgeleitet – bitte prüfen |
| Rangliste auf der Kommandozeile (`server/rangliste.php`) | zum Ausprobieren gegen Rohdaten |
| Hosting | Hetzner Webhosting, Subdomain wohinfahrma.dablank.de (`public_html/wohinfahrma`), Deploy per GitHub Action |
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
| `hinweis` | Herkunft und offene Fragen |

Sektoren in Grad, **woher** der Wind kommt, im Uhrzeigersinn, `von` inklusive, `bis` exklusive. `[275, 20]` geht über Norden. Eine Region sollte nicht über eine Wetterscheide reichen (Gardasee Nord und Süd verhalten sich verschieden).

## Bewertung

Eine Stunde ist fahrbar, wenn sie im Tagesfenster liegt (9 Uhr Ortszeit oder Sonnenaufgang bis Sonnenuntergang), der Wind im Fenster der Sportart liegt, die Richtung zu einer Windlage passt, er nicht ablandig ist, kein Gewitter gerechnet wird (mindestens die Hälfte der Modelle WMO 95–99) und, falls die Sportart das verlangt, die Welle passt. Ein Tag geht ab 2 fahrbaren Stunden am Stück.

**Heute zählt nur der Rest** (seit 7.11.2026, wie stegen-wind): ab jetzt + 30 Minuten, auf die nächste volle Stunde gerundet, bis zum Ende des Tagesfensters. Das gilt für Modellmittel und Ensemble; die Kachel heißt dann „Heute ab 15“. Bleiben weniger als 2 Stunden, steht dort „vorbei“, und der Tag zählt nicht zu den erwarteten guten Tagen. Grenze: Eine Chance über wenige Reststunden ist eine Näherung mit derselben Regel. `REST_VORLAUF_MIN` in `bewertung.php`.

- **Modellmittel:** Mittel aller Modelle mit Wert, Richtung als Vektormittel. Liefert Fenster, Windlage, mittleren Wind, Böen.
- **Chance:** Anteil der 51 Läufe des ECMWF-Ensembles (0,25°), an denen der Tag nach denselben Regeln geht, ohne Böen, Gewitter und Welle (liefert das Ensemble nicht).
- **Rangliste:** Regionen im Umkreis (Luftlinie zum Vorhersagepunkt), sortiert nach erwarteten guten Tagen = Summe der Chancen über die gewählten Tage.

**Alle Zahlen sind Rohwerte, nicht kalibriert.** An Thermik-Spots unterschätzen die Modelle den Wind vermutlich. Sportart-Voreinstellungen wie in stegen-wind (14 / 14 / 11 / 16 / 17 / 12 / 5–18 kn); dort ist nur 14 kn Directional kalibriert, der Rest sind Faustwerte.

## Sammler

`server/sammler.php`, ein Abruf für bis zu 25 Regionen (Koordinaten kommagetrennt). Zwei Betriebsarten:

- **GitHub Action** (Standard seit 7.11.2026, `.github/workflows/sammeln.yml`): zweimal am Tag, 5:25 und 17:25 UTC (6:25 / 18:25 Uhr im Winter, eine Stunde später im Sommer). `php server/sammler.php --action daten/regionen.json ausgabe` ruft jede Quelle einmal ab und schreibt je Quelle eine gezippte Datei für den Lauf (`vorhersage/2026-11-07_0525.ndjson.gz`); die Action lädt sie per FTPS nach `<HOME_DIR>/umkreisdaten/`. Kein Cronjob auf Hetzner, Abrufe von GitHub-IPs, also nicht aus dem Open-Meteo-Kontingent von ammerseewind.de. Bei jedem Fehler wird die Action rot (GitHub mailt); was geklappt hat, ist trotzdem hochgeladen.
- **Cronjob** (alle 10 Minuten, ohne `--action`): Takt je Quelle wie unten, eine Datei je Tag, Wiederholung bei Fehlern. Für später, falls mehr Abrufe am Tag nötig werden.

| Quelle | Inhalt | Takt als Cronjob |
|---|---|---|
| `vorhersage` | ICON-D2, ICON-EU, ECMWF IFS 9 km, GFS, AROME-HD: Wind, Richtung, Böen, Temperatur, Wettercode, Regen, Bewölkung; 7 Tage | stündlich |
| `ensemble` | ECMWF IFS 0,25°, 51 Läufe: Wind, Richtung; 8 Tage | alle 6 h |
| `welle` | Marine API: Wellenhöhe, -richtung, -periode, Swell; nur `typ: meer`; 7 Tage | alle 3 h |

Außerhalb ihres Gebiets liefert Open-Meteo für ein Modell null; das Mittel nimmt dann die übrigen.

**Gitterzelle:** Open-Meteo wählt die Zelle standardmäßig bevorzugt an Land (`cell_selection=land`). Am 7.11.2026 lieferte das ECMWF-Ensemble für die Adria (angefragt 45,5° N) deshalb 45,75° N mit 0 m Höhe an der friaulischen Küste: alle 51 Läufe 6–11 kn, die feineren Modelle 17 kn. Meer-Regionen werden seitdem getrennt mit `cell_selection=sea` abgerufen. Seen bleiben beim Standard, weil ungeprüft ist, wie `sea` im Binnenland wirkt. Welche Zelle geliefert wurde, steht in `latitude`/`longitude`/`elevation` jedes Eintrags.

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

1. Erster echter Lauf des Sammlers, offene Punkte oben prüfen
2. Weitere Regionen eintragen
3. Web-App zum Installieren (Manifest, Service Worker)
4. Zugang aus stegen-wind übernehmen, Wohnort und Sportarten je Person
5. Stegen: kalibrierte Chance aus ammerseewind.de über einen kleinen Endpoint übernehmen
6. Mail-Benachrichtigung
7. Später: Spot-Meldungen, Korrektur je Spot aus Sessions
