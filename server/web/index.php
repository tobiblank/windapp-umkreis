<?php
/**
 * Wohinfahrma - Rangliste der Regionen im Umkreis
 *
 * Parameter (alle optional, die Seite merkt sich die Wahl im Browser):
 *   sport   Schluessel aus SPORTARTEN (bewertung.php)
 *   km      Umkreis in km Luftlinie
 *   ort     "Breite,Laenge", auf 0,1 Grad gerundet (ca. 10 km) - genauer wird
 *           nichts angenommen, damit kein genauer Standort im Zugriffsprotokoll steht
 *   ansicht bald (heute bis uebermorgen) oder reise (Tag 3 bis 7)
 */

declare(strict_types=1);
date_default_timezone_set('UTC');
require __DIR__ . '/lib/regionen.php';
require __DIR__ . '/lib/bewertung.php';

const TZ_SEITE = 'Europe/Berlin';
const HEIM_STANDARD = [48.07, 11.15];      // Inning am Ammersee
const UMKREISE = [100, 200, 400, 700, 1000, 1500, 2500];
const WOCHENTAGE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function zahl(float $v, int $stellen = 0): string
{
    return number_format($v, $stellen, ',', '.');
}

function fehlerseite(string $text): never
{
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Wohinfahrma</title><link rel="icon" href="favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="stil.css"><main class="seite"><h1 class="marke">Wohin<span>fahrma</span></h1>'
       . '<p class="karte">' . h($text) . '</p></main>';
    exit;
}

$cfg = @include __DIR__ . '/config.php';
if (!is_array($cfg) || empty($cfg['data_dir'])) {
    fehlerseite('Die App ist noch nicht eingerichtet (config.php fehlt).');
}
try {
    $regionen = regionen_laden($cfg['regionen_datei'] ?? __DIR__ . '/lib/regionen.json');
} catch (RuntimeException $e) {
    fehlerseite('Der Regionen-Katalog ist nicht lesbar.');
}

// --- Auswahl ----------------------------------------------------------
$sportId = isset($_GET['sport']) && isset(SPORTARTEN[$_GET['sport']]) ? (string)$_GET['sport'] : 'kite_twintip';
$sport = SPORTARTEN[$sportId];
$km = isset($_GET['km']) && in_array((int)$_GET['km'], UMKREISE, true) ? (int)$_GET['km'] : 1000;
$ansicht = ($_GET['ansicht'] ?? '') === 'reise' ? 'reise' : 'bald';

$heim = HEIM_STANDARD;
$eigenerOrt = false;
if (isset($_GET['ort']) && preg_match('/^(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)$/', (string)$_GET['ort'], $m)) {
    $lat = round((float)$m[1], 1);
    $lon = round((float)$m[2], 1);
    if (abs($lat) <= 90 && abs($lon) <= 180) {
        $heim = [$lat, $lon];
        $eigenerOrt = true;
    }
}

$tzSeite = new DateTimeZone(TZ_SEITE);
$heute = new DateTimeImmutable('today', $tzSeite);

// Geteilte Vorhersage (Knopf "Teilen"): ?sport=..&region=<id>&nach=<YYYY-MM-DD>
// zeigt nur diese Region, ohne Umkreis und Standort; der Zeitraum folgt aus dem Tag.
$geteilt = null;
$geteiltTag = null;      // geteilter Tag, noch im Vorhersagezeitraum
$geteiltVorbei = null;   // geteilter Tag, der schon vorbei ist
if (is_string($_GET['region'] ?? null)) {
    foreach ($regionen as $r) {
        if ($r['id'] === $_GET['region']) {
            $geteilt = $r;
            break;
        }
    }
}
if ($geteilt && is_string($_GET['nach'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['nach'])) {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $_GET['nach'], $tzSeite);
    if ($d && $d->format('Y-m-d') === $_GET['nach']) {
        $diff = (int)$heute->diff($d)->format('%r%a');
        if ($diff >= 0 && $diff <= 6) {
            $ansicht = $diff <= 2 ? 'bald' : 'reise';
            $geteiltTag = $_GET['nach'];
        } elseif ($diff < 0) {
            $geteiltVorbei = $_GET['nach'];
        }
    }
}

[$von, $bis] = $ansicht === 'reise' ? [3, 6] : [0, 2];
$tage = [];
for ($i = $von; $i <= $bis; $i++) {
    $tage[] = $heute->modify("+$i day")->format('Y-m-d');
}

function tag_kurz(string $tag, DateTimeImmutable $heute): string
{
    $d = new DateTimeImmutable($tag, $heute->getTimezone());
    $diff = (int)$heute->diff($d)->format('%r%a');
    return match ($diff) {
        0 => 'Heute',
        1 => 'Morgen',
        default => WOCHENTAGE[(int)$d->format('w')] . ' ' . $d->format('j.'),
    };
}

/** Tag mit Datum, fuer geteilte Texte (dort waere "Morgen" beim Empfaenger falsch) */
function tag_datum(string $tag, DateTimeZone $tz): string
{
    $d = new DateTimeImmutable($tag, $tz);
    return WOCHENTAGE[(int)$d->format('w')] . ' ' . $d->format('j.n.');
}

// --- Rechnen ----------------------------------------------------------
$stand = daten_laden(rtrim($cfg['data_dir'], '/'));
$liste = $geteilt
    ? rangliste([$geteilt], $stand, $sport, $heim, INF, $tage)
    : rangliste($regionen, $stand, $sport, $heim, (float)$km, $tage);
// Sortierung: alle Tage (summe), ein angezeigter Tag oder Entfernung
$nach = (string)($_GET['nach'] ?? 'summe');
if ($nach !== 'km' && !in_array($nach, $tage, true)) {
    $nach = 'summe';
}
$liste = rangliste_sortieren($liste, $nach);

$zeiten = [];
foreach ($stand['_ts'] as $proRegion) {
    foreach ($proRegion as $ts) {
        $zeiten[] = $ts;
    }
}
$standText = null;
if ($zeiten) {
    $neu = (new DateTimeImmutable(max($zeiten)))->setTimezone($tzSeite);
    $standText = ($neu->format('Y-m-d') === $heute->format('Y-m-d') ? 'heute' : WOCHENTAGE[(int)$neu->format('w')] . ' ' . $neu->format('j.n.'))
               . ' ' . $neu->format('H:i') . ' Uhr';
}

function stufe(?float $chance): string
{
    if ($chance === null) {
        return 'leer';
    }
    return $chance >= 0.7 ? 'go' : ($chance >= 0.4 ? 'maybe' : ($chance >= 0.15 ? 'no' : 'nix'));
}

function link_mit(array $aenderung): string
{
    global $sportId, $km, $ansicht, $heim, $eigenerOrt, $nach;
    $p = ['sport' => $sportId, 'km' => $km, 'ansicht' => $ansicht];
    if ($nach !== 'summe') {
        $p['nach'] = $nach;
    }
    if ($eigenerOrt) {
        $p['ort'] = $heim[0] . ',' . $heim[1];
    }
    return '?' . http_build_query(array_merge($p, $aenderung));
}

$wind = $sport['min'] . (isset($sport['max']) ? '–' . $sport['max'] : '+') . ' kn';

/** Teilen nur, wenn die Vorhersage gut ist: Fenster im Modellmittel und Chance ab 40 % (oder kein Ensemble) */
function teilenswert(array $d): bool
{
    $c = $d['ens']['chance'] ?? null;
    return $d['det']['geht'] && empty($d['vorbei']) && ($c === null || $c >= 0.4);
}

function teilen_link(array $region, string $tag): string
{
    global $sportId;
    return '?' . http_build_query(['sport' => $sportId, 'region' => $region['id'], 'nach' => $tag]);
}

/** Stand zum Zeitpunkt des Teilens; der Link zeigt beim Empfaenger die dann aktuelle Vorhersage */
function teilen_text(array $region, string $tag, array $d, array $sport, DateTimeZone $tz): string
{
    $det = $d['det'];
    $teile = [$d['spot_name'] ?? null, $det['windlage'] ?? null, $det['von'] . '–' . $det['bis'] . ' Uhr'];
    if (!empty($det['regel'])) {
        $teile[] = 'typisch ' . zahl((float)$det['staerke'][0]) . '–' . zahl((float)$det['staerke'][1]) . ' kn';
    } else {
        $teile[] = 'Ø ' . zahl($det['mittel_kn']) . ' kn' . ($det['boe_max'] !== null ? ', Böen bis ' . zahl($det['boe_max']) . ' kn' : '');
    }
    $c = $d['ens']['chance'] ?? null;
    if ($c !== null) {
        $teile[] = zahl(100 * $c) . ' % Chance';
    }
    return $region['name'] . ', ' . tag_datum($tag, $tz) . ': ' . implode(' · ', array_filter($teile)) . ' (' . $sport['name'] . ')';
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Wohinfahrma</title>
<link rel="stylesheet" href="stil.css">
<link rel="manifest" href="manifest.php">
<link rel="icon" href="favicon.ico" sizes="32x32">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="apple-mobile-web-app-title" content="Fahrma">
<meta name="theme-color" content="#eef3f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0f1e24" media="(prefers-color-scheme: dark)">
</head>
<body>
<main class="seite">

<header class="kopf">
  <div class="logozeile">
    <svg class="logo" viewBox="0 0 100 100" width="40" height="40" aria-hidden="true"><path class="logo-marke" d="M50 7C31.2 7 17 21.6 17 40.5C17 64 50 93 50 93S83 64 83 40.5C83 21.6 68.8 7 50 7Z"/><path class="logo-fahne" d="M31 36H57a7 7 0 1 0 -7 -7M31 48H65a7 7 0 1 1 -7 7"/></svg>
    <div>
      <h1 class="marke">Wohin<span>fahrma</span></h1>
      <p class="leise klein">Wo ist Wind? Fahr ma.</p>
    </div>
  </div>
  <?php if ($standText): ?><p class="leise klein rechts">Stand<br><?= h($standText) ?></p><?php endif ?>
</header>

<?php if ($geteilt): ?>
<p class="karte geteilt">
  Geteilte Vorhersage<?= $geteiltTag ? ' für ' . h(tag_datum($geteiltTag, $tzSeite)) : '' ?>, <?= h($sport['name']) ?>, aktueller Stand.
  <?php if ($geteiltVorbei): ?>Der geteilte Tag (<?= h(tag_datum($geteiltVorbei, $tzSeite)) ?>) ist vorbei.<?php endif ?>
  <a href="./">Zur Rangliste</a>
</p>
<?php else: ?>
<form class="wahl karte" method="get" id="wahl">
  <label>Sportart
    <select name="sport">
      <?php foreach (SPORTARTEN as $id => $s): ?>
        <option value="<?= h($id) ?>"<?= $id === $sportId ? ' selected' : '' ?>><?= h($s['name']) ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <label>Umkreis
    <select name="km">
      <?php foreach (UMKREISE as $u): ?>
        <option value="<?= $u ?>"<?= $u === $km ? ' selected' : '' ?>><?= zahl($u) ?> km</option>
      <?php endforeach ?>
    </select>
  </label>
  <input type="hidden" name="ansicht" value="<?= h($ansicht) ?>">
  <?php if ($eigenerOrt): ?><input type="hidden" name="ort" value="<?= h($heim[0] . ',' . $heim[1]) ?>"><?php endif ?>
  <p class="ort klein leise">
    Von <?= $eigenerOrt ? 'deinem Standort (auf etwa 10 km gerundet)' : 'Inning am Ammersee' ?> ·
    <button type="button" class="textknopf" id="standort">Meinen Standort nehmen</button>
    <?php if ($eigenerOrt): ?> · <a href="<?= h(link_mit(['ort' => null])) ?>">Inning</a><?php endif ?>
  </p>
  <noscript><button class="knopf">Anzeigen</button></noscript>
</form>

<nav class="reiter" aria-label="Zeitraum">
  <a href="<?= h(link_mit(['ansicht' => 'bald', 'nach' => null])) ?>"<?= $ansicht === 'bald' ? ' aria-current="page"' : '' ?>>Heute bis übermorgen</a>
  <a href="<?= h(link_mit(['ansicht' => 'reise', 'nach' => null])) ?>"<?= $ansicht === 'reise' ? ' aria-current="page"' : '' ?>>Tag 3 bis 7</a>
</nav>

<nav class="sortierung klein" aria-label="Sortierung">
  <span class="leise">Sortiert nach</span>
  <a href="<?= h(link_mit(['nach' => null])) ?>"<?= $nach === 'summe' ? ' aria-current="true"' : '' ?>>alle Tage</a>
  <?php foreach ($tage as $t): ?>
    <a href="<?= h(link_mit(['nach' => $t])) ?>"<?= $nach === $t ? ' aria-current="true"' : '' ?>><?= h(tag_kurz($t, $heute)) ?></a>
  <?php endforeach ?>
  <a href="<?= h(link_mit(['nach' => 'km'])) ?>"<?= $nach === 'km' ? ' aria-current="true"' : '' ?>>Entfernung</a>
</nav>
<?php endif ?>

<p class="leise klein erklaerung">
  <?= h($sport['name']) ?>, <?= h($wind) ?>, mindestens <?= MIN_STUNDEN ?> Stunden am Stück zwischen 9 Uhr und Sonnenuntergang<?= $ansicht === 'bald' ? ', heute erst ab einer halben Stunde nach jetzt' : '' ?>.
  Prozent = Anteil der 51 Läufe des ECMWF-Ensembles, an denen das klappt.
</p>

<?php if (!$zeiten): ?>
  <p class="karte">Noch keine Vorhersagedaten. Der Sammler läuft zweimal am Tag, gegen 6:30 und 18:30 Uhr.</p>
<?php elseif (!$liste && $geteilt): ?>
  <p class="karte">Für <?= h($geteilt['name']) ?> gibt es gerade keine Vorhersagedaten. <a href="./">Zur Rangliste</a></p>
<?php elseif (!$liste): ?>
  <p class="karte">Keine Region im Umkreis von <?= zahl($km) ?> km. <a href="<?= h(link_mit(['km' => UMKREISE[count(UMKREISE) - 1]])) ?>">Umkreis vergrößern</a></p>
<?php endif ?>

<?php foreach ($liste as $e):
    $r = $e['region'];
    $guteTage = array_filter($e['tage'], fn($t) => $t['det']['geht'] || (($t['ens']['chance'] ?? 0) >= 0.15));
?>
  <article class="karte region<?= $guteTage ? '' : ' still' ?>">
    <div class="region-kopf">
      <h2><?= h($r['name']) ?><?php if (!empty($r['thermik'])): ?> <span class="marker thermik" title="Thermik bilden die Modelle schlecht ab: die Zahl ist hier vermutlich zu niedrig">Thermik</span><?php endif ?></h2>
      <?php if (!$geteilt): ?><span class="leise klein"><?= zahl($e['km']) ?> km</span><?php endif ?>
    </div>
    <div class="tage" style="--n: <?= count($tage) ?>">
      <?php foreach ($tage as $t):
          $d = $e['tage'][$t];
          $c = $d['ens']['chance'] ?? null;
      ?>
        <?php if (!empty($d['vorbei'])): ?>
        <div class="tag leer" title="Für heute bleibt nicht mehr genug Zeit bis Sonnenuntergang">
          <span class="t"><?= h(tag_kurz($t, $heute)) ?></span>
          <span class="w vorbei">vorbei</span>
        </div>
        <?php else: ?>
        <div class="tag <?= stufe($c) ?><?= $nach === $t ? ' gewaehlt' : '' ?>" title="<?= h(($c === null ? 'keine Ensemble-Daten' : zahl(100 * $c) . ' % der Läufe')
                                                     . (!empty($d['rest']) ? ', nur noch ' . $d['fenster'][0] . '–' . $d['fenster'][1] . ' Uhr' : '')) ?>">
          <span class="t"><?= h(tag_kurz($t, $heute)) ?><?= !empty($d['rest']) && $d['fenster'][0] > FENSTER_AB ? ' ab ' . $d['fenster'][0] : '' ?></span>
          <span class="w"><?= $c === null ? ($d['det']['geht'] ? 'ja' : '–') : zahl(100 * $c) . '<small>%</small>' ?></span>
        </div>
        <?php endif ?>
      <?php endforeach ?>
    </div>
    <?php if ($guteTage): ?>
      <ul class="details klein">
        <?php foreach ($guteTage as $t => $d):
            $det = $d['det'];
        ?>
          <li>
            <b><?= h(tag_kurz($t, $heute)) ?>:</b>
            <?php if ($det['geht']): ?>
              <?= h($d['spot_name'] ?? 'ganze Region') ?> ·
              <?= h($det['windlage'] ?? 'jede Richtung') ?> ·
              <?= $det['von'] ?>–<?= $det['bis'] ?> Uhr ·
              <?php if (!empty($det['regel'])): ?>
                typisch <?= zahl((float)$det['staerke'][0]) ?>–<?= zahl((float)$det['staerke'][1]) ?> kn
              <?php else: ?>
                Ø <?= zahl($det['mittel_kn']) ?> kn<?php if ($det['boe_max'] !== null): ?>, Böen bis <?= zahl($det['boe_max']) ?> kn<?php endif ?>
              <?php endif ?>
              <?php if ($det['charakter']): ?><span class="leise">(<?= h($det['charakter']) ?>)</span><?php endif ?>
              <?php if ($det['grob']): ?><span class="marker">grob</span><?php endif ?>
              <?php if (!empty($det['regel'])): ?><span class="marker regel" title="Aus Druckdifferenz und/oder Grundströmung abgeleitet, nicht aus dem Modellwind am See. Die Stärke ist ein geschätzter typischer Wert.">Regel</span><?php endif ?>
              <?php if (teilenswert($d)): ?><button type="button" class="teilen" hidden
                data-titel="<?= h('Wohinfahrma: ' . $r['name'] . ', ' . tag_datum($t, $tzSeite)) ?>"
                data-text="<?= h(teilen_text($r, $t, $d, $sport, $tzSeite)) ?>"
                data-url="<?= h(teilen_link($r, $t)) ?>"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M12 3v12M7 8l5-5 5 5M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>Teilen</button><?php endif ?>
            <?php else: ?>
              <span class="leise">Modellmittel: <?= h($det['grund']) ?>, nur ein Teil der Ensemble-Läufe sieht ein Fenster</span>
            <?php endif ?>
          </li>
        <?php endforeach ?>
      </ul>
    <?php else: ?>
      <p class="klein leise">Kein passender Tag in diesem Zeitraum.</p>
    <?php endif ?>
    <?php if (!empty($r['links'])): ?>
      <p class="links klein">Genauer:
        <?php foreach ($r['links'] as $i => $l): ?><?= $i ? ' · ' : '' ?><a href="<?= h($l['url']) ?>" rel="noopener"><?= h($l['name']) ?> ↗</a><?php endforeach ?>
      </p>
    <?php endif ?>
  </article>
<?php endforeach ?>

<footer class="fuss klein leise">
  <p><b>Regel</b> heißt: Die Windlage ist aus Druckdifferenzen und Grundströmung abgeleitet (nach lokalen Vorhersageseiten wie kitewetter.at), die Stärke ist ein geschätzter typischer Wert.</p>
  <p><b>Rohwerte, nicht kalibriert.</b> Modelle unterschätzen lokale Thermik oft (Seen mit „Thermik“ markiert, dort lieber die verlinkte lokale Vorhersage ansehen); Böen und Gewitter prüft nur das Modellmittel. Entfernung als Luftlinie zum Vorhersagepunkt der Region.</p>
  <p>Vorhersagen: <a href="https://open-meteo.com/">Weather data by Open-Meteo.com</a> (CC BY 4.0) · Für Stegen genauer: <a href="https://ammerseewind.de">ammerseewind.de</a></p>
  <p><a href="impressum.html">Impressum</a> · <a href="datenschutz.html">Datenschutz</a></p>
</footer>
</main>

<script>
// Installierbare Web-App: Service Worker nur fuer die Offline-Seite (sw.js)
if ('serviceWorker' in navigator) {
  addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () {}); });
}
// Teilen: Web Share (Handy) oder in die Zwischenablage; ohne beides bleibt der Knopf verborgen
(function () {
  var kannKopieren = !!(navigator.clipboard && window.isSecureContext);
  if (!navigator.share && !kannKopieren) { return; }
  document.querySelectorAll('.teilen').forEach(function (k) {
    k.hidden = false;
    k.addEventListener('click', function () {
      var url = new URL(k.dataset.url, location.href).href;
      function kopieren() {
        navigator.clipboard.writeText(k.dataset.text + '\n' + url).then(function () {
          var alt = k.lastChild.textContent;
          k.lastChild.textContent = 'Kopiert';
          setTimeout(function () { k.lastChild.textContent = alt; }, 2000);
        }, function () {});
      }
      if (navigator.share) {
        navigator.share({ title: k.dataset.titel, text: k.dataset.text, url: url }).catch(function (e) {
          if (e && e.name !== 'AbortError' && kannKopieren) { kopieren(); }
        });
      } else {
        kopieren();
      }
    });
  });
})();
(function () {
  var form = document.getElementById('wahl');
  if (!form) { return; }   // geteilte Vorhersage: keine Auswahl, nichts merken
  var SCHLUESSEL = 'wohinfahrma_wahl';
  function lesen() { try { return JSON.parse(localStorage.getItem(SCHLUESSEL) || 'null'); } catch (e) { return null; } }
  function merken(p) { try { localStorage.setItem(SCHLUESSEL, JSON.stringify(p)); } catch (e) {} }

  // Ohne Parameter: letzte Wahl wiederherstellen
  if (!location.search) {
    var alt = lesen();
    if (alt && alt.sport) {
      location.replace('?' + new URLSearchParams(alt).toString());
      return;
    }
  }
  var jetzt = Object.fromEntries(new URLSearchParams(location.search));
  if (jetzt.sport) { merken(jetzt); }

  form.querySelectorAll('select').forEach(function (s) {
    s.addEventListener('change', function () { form.submit(); });
  });

  var knopf = document.getElementById('standort');
  if (!('geolocation' in navigator)) { knopf.hidden = true; return; }
  knopf.addEventListener('click', function () {
    knopf.textContent = 'Standort wird bestimmt …';
    navigator.geolocation.getCurrentPosition(function (pos) {
      // Auf 0,1 Grad runden, bevor irgendetwas den Browser verlässt
      var ort = form.querySelector('input[name=ort]') || form.appendChild(Object.assign(document.createElement('input'), { type: 'hidden', name: 'ort' }));
      ort.value = pos.coords.latitude.toFixed(1) + ',' + pos.coords.longitude.toFixed(1);
      form.submit();
    }, function () {
      knopf.textContent = 'Standort nicht verfügbar';
    }, { maximumAge: 3600000, timeout: 10000 });
  });
})();
</script>
</body>
</html>
