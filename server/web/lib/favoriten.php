<?php
/**
 * Deine Regionen (seit 13.11.2026, Schnee-Modus; seit 13.11.2026 auch im Wind-Modus).
 *
 * Ein Stern hinter dem Namen jeder Region merkt sie im Browser (localStorage, je Modus ein
 * Schluessel, nur die ids). Der Link "Nur deine Regionen" schreibt sie als fav=id,id,... in
 * die Adresse; dann zeigt die Seite nur diese Regionen, ohne Umkreis. Der Server merkt sich
 * nichts, es gibt kein Cookie. Die Seiten binden das ein: fav_aus_get(), fav_zeile_html()
 * ueber dem Zeitraum, fav_knopf_html() im Kopf jeder Region, fav_skript() am Ende.
 */

const FAV_TIPP = 'Tipp: ☆ bei einer Region antippen, dann kannst du nur deine Regionen anzeigen.';

/** ids aus ?fav=, nur bekannte, in der Reihenfolge des Katalogs */
function fav_aus_get(array $regionen, mixed $wert): array
{
    if (!is_string($wert) || $wert === '') {
        return [];
    }
    $gewuenscht = array_flip(explode(',', $wert));
    return array_values(array_filter(array_column($regionen, 'id'), fn($id) => isset($gewuenscht[$id])));
}

/**
 * Zeile ueber dem Zeitraum. Mit fav: sichtbar, mit Link zu allen Regionen ($alleLink) und
 * einem verborgenen Link, den das Skript zeigt, wenn sich die Sterne seitdem geaendert haben.
 * Ohne fav: verborgen; das Skript zeigt den Link oder den Tipp.
 */
function fav_zeile_html(array $fav, string $alleLink, string $zusatz = 'ohne Umkreis'): string
{
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($fav) {
        return '<p class="fav-zeile klein" id="favZeile" data-fav="' . $e(implode(',', $fav)) . '">★ Nur deine Regionen (' . count($fav) . ')'
             . ($zusatz !== '' ? ', ' . $e($zusatz) : '') . ' · <a href="' . $e($alleLink) . '">Alle Regionen</a>'
             . '<span id="favNeu" hidden> · <a id="favLink" href="#"></a></span></p>';
    }
    return '<p class="fav-zeile klein leise" id="favZeile" hidden><a id="favLink" href="#"></a><span id="favTipp">' . $e(FAV_TIPP) . '</span></p>';
}

/** Stern im Kopf einer Region (ohne JavaScript verborgen) */
function fav_knopf_html(array $region, array $fav): string
{
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<button type="button" class="fav" data-id="' . $e($region['id']) . '" aria-pressed="' . (in_array($region['id'], $fav, true) ? 'true' : 'false')
         . '" title="Zu deinen Regionen" aria-label="' . $e($region['name']) . ' zu deinen Regionen" hidden>★</button>';
}

/**
 * Skript: Sterne zeigen und umschalten, Link setzen. $seite: Pfad vor "?..." im Link
 * ("schnee.php" oder "./"). Mit fav= in der Adresse und leerem Speicher (Lesezeichen auf einem
 * anderen Geraet) uebernimmt es die ids der Adresse.
 */
function fav_skript(string $schluessel, string $seite): string
{
    $s = json_encode($schluessel);
    $p = json_encode($seite);
    return <<<JS
(function () {
  var S = $s, SEITE = $p, ids = [], knoepfe = document.querySelectorAll('.fav[data-id]');
  try { ids = JSON.parse(localStorage.getItem(S) || '[]'); } catch (e) { ids = []; }
  if (!Array.isArray(ids)) { ids = []; }
  var zeile = document.getElementById('favZeile'), link = document.getElementById('favLink'),
      tipp = document.getElementById('favTipp'), neu = document.getElementById('favNeu');
  var aktiv = zeile && zeile.dataset.fav ? zeile.dataset.fav.split(',') : null;
  if (!ids.length && aktiv) {
    ids = aktiv.slice();
    try { localStorage.setItem(S, JSON.stringify(ids)); } catch (e) {}
  }
  function gleich(a, b) { return a.slice().sort().join(',') === b.slice().sort().join(','); }
  function zeigen() {
    knoepfe.forEach(function (k) {
      var an = ids.indexOf(k.dataset.id) >= 0;
      k.setAttribute('aria-pressed', an ? 'true' : 'false');
      k.title = an ? 'Aus deinen Regionen nehmen' : 'Zu deinen Regionen';
      k.hidden = false;
    });
    if (!zeile || !link) { return; }
    var u = new URL(location.href);
    u.searchParams.set('fav', ids.join(','));
    u.hash = '';
    link.href = SEITE + u.search;
    if (aktiv) {
      neu.hidden = !ids.length || gleich(ids, aktiv);
      link.textContent = 'Auswahl aktualisieren (' + ids.length + ')';
      return;
    }
    zeile.hidden = !knoepfe.length && !ids.length;
    link.hidden = !ids.length;
    if (tipp) { tipp.hidden = !!ids.length; }
    link.textContent = '★ Nur deine Regionen (' + ids.length + ')';
  }
  knoepfe.forEach(function (k) {
    k.addEventListener('click', function (e) {
      e.preventDefault();
      var i = ids.indexOf(k.dataset.id);
      if (i >= 0) { ids.splice(i, 1); } else { ids.push(k.dataset.id); }
      try { localStorage.setItem(S, JSON.stringify(ids)); } catch (e2) {}
      zeigen();
    });
  });
  zeigen();
})();
JS;
}
