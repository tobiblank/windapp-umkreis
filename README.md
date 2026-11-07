# Windapp Umkreis

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
| Sammler (`server/sammler.php`) | 7.11.2026, gegen einen Open-Meteo-Ersatz getestet, **noch nie gegen das echte Open-Meteo gelaufen** |
| Bewertung (`server/web/lib/bewertung.php`) | 7.11.2026: Stunde fahrbar, bester Block ≥ 2 h, Ensemble-Chance, Rangliste nach Luftlinie |
| Rangliste auf der Kommandozeile (`server/rangliste.php`) | zum Ausprobieren, bevor es eine Seite gibt |
| Seiten, Zugang, Benachrichtigung | offen |
| Hosting, Domain, Deployment | offen |

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

- **Modellmittel:** Mittel aller Modelle mit Wert, Richtung als Vektormittel. Liefert Fenster, Windlage, mittleren Wind, Böen.
- **Chance:** Anteil der 51 Läufe des ECMWF-Ensembles (0,25°), an denen der Tag nach denselben Regeln geht, ohne Böen, Gewitter und Welle (liefert das Ensemble nicht).
- **Rangliste:** Regionen im Umkreis (Luftlinie zum Vorhersagepunkt), sortiert nach erwarteten guten Tagen = Summe der Chancen über die gewählten Tage.

**Alle Zahlen sind Rohwerte, nicht kalibriert.** An Thermik-Spots unterschätzen die Modelle den Wind vermutlich. Sportart-Voreinstellungen wie in stegen-wind (14 / 14 / 11 / 16 / 17 / 12 / 5–18 kn); dort ist nur 14 kn Directional kalibriert, der Rest sind Faustwerte.

## Sammler

`server/sammler.php`, Cronjob alle 10 Minuten. Ein Abruf für bis zu 25 Regionen (Koordinaten kommagetrennt).

| Quelle | Inhalt | Takt |
|---|---|---|
| `vorhersage` | ICON-D2, ICON-EU, ECMWF IFS 9 km, GFS, AROME-HD: Wind, Richtung, Böen, Temperatur, Wettercode, Regen, Bewölkung; 7 Tage | stündlich |
| `ensemble` | ECMWF IFS 0,25°, 51 Läufe: Wind, Richtung; 8 Tage | alle 6 h |
| `welle` | Marine API: Wellenhöhe, -richtung, -periode, Swell; nur `typ: meer`; 7 Tage | alle 3 h |

Außerhalb ihres Gebiets liefert Open-Meteo für ein Modell null (ICON-D2 an der Adria); das Mittel nimmt dann die übrigen.

Format wie stegen-wind, eine Datei pro Quelle und Tag, ab vorgestern gezippt:

```json
{"ts":"2026-11-07T12:00:03Z","source":"vorhersage","ok":true,"http":200,"ms":812,
 "regionen":["ammersee","adria-nord"],"payload":[{...},{...}]}
```

`payload` ist immer eine Liste in der Reihenfolge von `regionen`. Bleiben die Stundenwerte gleich (kein neuer Modelllauf), wird nichts geschrieben. Ein fehlgeschlagener Abruf wird protokolliert und im nächsten Lauf wiederholt; per Cron-Mail gemeldet wird erst, wenn im ganzen Takt nichts geklappt hat.

**Ungeprüft, beim ersten echten Lauf mit `php sammler.php --debug` kontrollieren:**

- dass Open-Meteo mehrere Koordinaten in einem Abruf annimmt und als Liste zurückgibt,
- die Variablennamen der Marine API,
- die Zählung: `om_gewicht()` nimmt an, dass jeder Ort einzeln zählt, Ensemble-Läufe aber nicht extra. Beides ist eine Annahme. Läuft die App auf demselben Server wie ammerseewind.de, teilen sich beide vermutlich das freie Kontingent (`openmeteo_limit` in `config.php`).

Größe grob, aus dem Test hochgerechnet: rund 20 KB je Region und Vorhersage-Abruf, 75 KB je Region und Ensemble-Abruf, ungezippt.

## Einrichtung

1. `server/web/config.example.php` als `config.php` in den Webordner legen und ausfüllen (nie ins Repo).
2. `server/sammler.php` und `server/rangliste.php` außerhalb des Webordners ablegen, `$WEB_DIR` darin anpassen.
3. `daten/regionen.json` an den Ort aus `regionen_datei` legen.
4. Cronjob: `/usr/bin/php /usr/home/DEINUSER/sammler.php`
5. Ausprobieren: `php rangliste.php wingfoil 800 48.07,11.15`

## Prüfen

```
php tests/test.php
```

Ohne Netz: Einzelfunktionen, Katalog, Tagesfenster, dann der Sammler gegen `tests/fake_openmeteo.php` (eingebauter PHP-Server) und die Rangliste aus den geschriebenen Daten. Läuft auch als GitHub Action bei jedem Push (`.github/workflows/pruefen.yml`).

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

1. Hosting und Domain festlegen, erster echter Lauf des Sammlers
2. Weitere Regionen eintragen
3. Seite „Rangliste“: Sportart, Umkreis, Tagesmodus (heute bis übermorgen) und Reisemodus (Tag 3–7)
4. Zugang und Gastmodus aus stegen-wind übernehmen, Wohnort je Person
5. Stegen: kalibrierte Chance aus ammerseewind.de über einen kleinen Endpoint übernehmen
6. Mail-Benachrichtigung
7. Später: Spot-Meldungen, Korrektur je Spot aus Sessions
