<?php
/**
 * Karte der Rangliste (seit 7.11.2026): Regionen als Punkte, eingefaerbt nach
 * der Chance am gewaehlten Tag, auf einer eigenen SVG-Grundlage (karte.svg aus
 * Natural Earth, tools/karte_bauen.py). Keine fremden Kachelserver.
 *
 * Projektion wie in tools/karte_bauen.py: Mercator, KARTE_K Einheiten je
 * Laengengrad, Ursprung (KARTE_LON0, KARTE_LAT_MAX) oben links.
 */

const KARTE_LON0 = -20.0;
const KARTE_LAT_MAX = 64.0;
const KARTE_K = 10.0;
const KARTE_BREITE = 560.0;      // (36 - -20) * K
const KARTE_HOEHE = 614.3;       // bis 22 Grad Nord
const KARTE_SEITE = 1.1;         // Hoehe : Breite des Ausschnitts (etwas hoeher als breit, fuers Handy)
const KARTE_MIN_GRAD = 2.5;      // kleinster Ausschnitt, in Laengengraden

const KARTE_PFEIL_AB_KN = 3;   // darunter keine Windrichtung auf der Landkarte (wie ammerseewind.de)
const ANKUNFT_PALME_BIS_BREITE = 46.0;   // Maennchen-Animation: Palme am Ziel nur am Meer suedlich davon, sonst Windsack

/**
 * Farbskala fuer Windstaerke (seit 8.11.2026, aus ammerseewind.de/farben.php):
 * angelehnt an Windy, blau leicht, gruen ab etwa 10 kn, ueber gelb und orange
 * zu rot, violett ab etwa 40 kn. Nur fuer Windstaerke; die Chance behaelt ihre
 * eigenen Farben (go, maybe, no).
 */
const WINDSKALA = [
    0  => [96, 120, 205],
    6  => [64, 160, 215],
    10 => [56, 190, 130],
    14 => [110, 205, 70],
    17 => [225, 212, 60],
    21 => [245, 160, 50],
    26 => [236, 88, 62],
    32 => [205, 64, 140],
    40 => [150, 90, 215],
    50 => [120, 70, 185],
];

/** CSS-Farbe fuer eine Windstaerke in kn, fliessend zwischen den Stuetzstellen */
function windfarbe(float $kn): string
{
    $stellen = array_keys(WINDSKALA);
    $c = WINDSKALA[end($stellen)];
    if ($kn <= $stellen[0]) {
        $c = WINDSKALA[$stellen[0]];
    } else {
        for ($i = 1; $i < count($stellen); $i++) {
            [$a, $b] = [$stellen[$i - 1], $stellen[$i]];
            if ($kn <= $b) {
                $t = ($kn - $a) / ($b - $a);
                $c = array_map(fn($x, $y) => (int)round($x + ($y - $x) * $t), WINDSKALA[$a], WINDSKALA[$b]);
                break;
            }
        }
    }
    return "rgb($c[0], $c[1], $c[2])";
}

/**
 * Farbskala fuer Neuschnee in cm (seit 9.11.2026, Schnee-Karte): grau ohne Schnee,
 * hellblau ab wenigen cm, kraeftiges Blau um 10 cm, ueber Indigo zu Violett und
 * Magenta ab 50 cm, aehnlich gaengigen Neuschneekarten. Blau bleibt "guter Schnee",
 * nie "sicher"; die Lawinenstufe hat ihre eigenen Farben.
 */
const SCHNEESKALA = [
    0   => [128, 146, 156],
    1   => [150, 196, 226],
    5   => [96, 168, 236],
    10  => [52, 120, 222],
    20  => [64, 72, 200],
    35  => [124, 64, 204],
    50  => [188, 70, 186],
    80  => [226, 96, 160],
];
const SCHNEESKALA_LEGENDE_BIS = 50;

/** Farbe fuer Neuschnee in cm, fliessend zwischen den Stuetzstellen (wie windfarbe) */
function schneefarbe_rgb(float $cm): array
{
    $stellen = array_keys(SCHNEESKALA);
    if ($cm <= $stellen[0]) {
        return SCHNEESKALA[$stellen[0]];
    }
    for ($i = 1; $i < count($stellen); $i++) {
        [$a, $b] = [$stellen[$i - 1], $stellen[$i]];
        if ($cm <= $b) {
            $t = ($cm - $a) / ($b - $a);
            return array_map(fn($x, $y) => (int)round($x + ($y - $x) * $t), SCHNEESKALA[$a], SCHNEESKALA[$b]);
        }
    }
    return SCHNEESKALA[end($stellen)];
}

function schneefarbe(float $cm): string
{
    $c = schneefarbe_rgb($cm);
    return "rgb($c[0], $c[1], $c[2])";
}

/** Ist die Farbe hell genug, dass die Zahl darauf dunkel stehen muss? (relative Helligkeit) */
function schneefarbe_hell(float $cm): bool
{
    [$r, $g, $b] = array_map(function (int $v): float {
        $v /= 255;
        return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, schneefarbe_rgb($cm));
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b > 0.3;
}

/** Legende als Farbverlauf 0-50 cm mit Marken */
function schneeskala_legende(): string
{
    $bis = SCHNEESKALA_LEGENDE_BIS;
    $teile = [];
    for ($cm = 0; $cm <= $bis; $cm += 1) {
        $teile[] = schneefarbe((float)$cm) . ' ' . round($cm / $bis * 100, 1) . '%';
    }
    $html = '<div class="windskala schneeskala" aria-hidden="true"><div class="verlauf" style="background: linear-gradient(to right, '
          . implode(', ', $teile) . ')"></div><div class="marken">';
    foreach ([0, 10, 20, 35, 50] as $m) {
        $html .= '<span style="left: ' . ($m / $bis * 100) . '%">' . $m . ($m === $bis ? '+ cm' : '') . '</span>';
    }
    return $html . '</div></div>';
}

/** Legende als Farbverlauf 0-40 kn mit Marken */
function windskala_legende(): string
{
    $teile = [];
    for ($kn = 0; $kn <= 40; $kn += 2) {
        $teile[] = windfarbe((float)$kn) . ' ' . round($kn / 40 * 100, 1) . '%';
    }
    $html = '<div class="windskala" aria-hidden="true"><div class="verlauf" style="background: linear-gradient(to right, '
          . implode(', ', $teile) . ')"></div><div class="marken">';
    foreach ([0, 10, 20, 30, 40] as $m) {
        $html .= '<span style="left: ' . ($m / 40 * 100) . '%">' . $m . ($m === 40 ? ' kn' : '') . '</span>';
    }
    return $html . '</div></div>';
}

function karte_merc(float $lat): float
{
    return rad2deg(log(tan(M_PI / 4 + deg2rad($lat) / 2)));
}

/** [x, y] in Karteneinheiten */
function karte_punkt(float $lat, float $lon): array
{
    return [($lon - KARTE_LON0) * KARTE_K, (karte_merc(KARTE_LAT_MAX) - karte_merc($lat)) * KARTE_K];
}

/** Radius eines Kreises von $km um $lat in Karteneinheiten (Mercator ist oertlich winkeltreu) */
function karte_km(float $km, float $lat): float
{
    return $km / (111.32 * cos(deg2rad($lat))) * KARTE_K;
}

/**
 * Ausschnitt [x, y, breite, hoehe] um alle Punkte, mit Rand, im Seitenverhaeltnis
 * KARTE_SEITE und nicht groesser als die Grundlage.
 * $punkte: Liste von [x, y]
 */
function karte_ausschnitt(array $punkte): array
{
    $xs = array_column($punkte, 0);
    $ys = array_column($punkte, 1);
    [$x0, $x1, $y0, $y1] = [min($xs), max($xs), min($ys), max($ys)];
    $w = max($x1 - $x0, KARTE_MIN_GRAD * KARTE_K);
    $h = max($y1 - $y0, KARTE_MIN_GRAD * KARTE_K);
    $w *= 1.2;   // Rand fuer die Punkte am Rand
    $h *= 1.2;
    if ($h / $w < KARTE_SEITE) {
        $h = $w * KARTE_SEITE;
    } else {
        $w = $h / KARTE_SEITE;
    }
    if ($w > KARTE_BREITE) {
        $w = KARTE_BREITE;
        $h = $w * KARTE_SEITE;
    }
    if ($h > KARTE_HOEHE) {
        $h = KARTE_HOEHE;
        $w = $h / KARTE_SEITE;
    }
    $cx = ($x0 + $x1) / 2;
    $cy = ($y0 + $y1) / 2;
    $x = min(max($cx - $w / 2, 0), KARTE_BREITE - $w);
    $y = min(max($cy - $h / 2, 0), KARTE_HOEHE - $h);
    return [round($x, 1), round($y, 1), round($w, 1), round($h, 1)];
}

/**
 * Namen an die Punkte setzen, ohne dass sie sich ueberlappen: die besten zuerst,
 * rechts vom Punkt, sonst links, sonst ohne Namen. Breite grob geschaetzt
 * (0,55 x Schriftgroesse je Zeichen). Seit 9.11.2026 kein Name direkt ueber oder unter
 * einem fremden Punkt (senkrechter Abstand zur Mitte mindestens KARTE_NAME_UNTER x $rp):
 * so las sich Silvaplana als Name des Reschensees. Seitlich reicht KARTE_NAME_SEITE x $rp.
 * $punkte: [schluessel => ['xy' => [x, y], 'name' => string, 'rang' => float]]
 * Rueckgabe [schluessel => 'rechts' | 'links'], fehlend = kein Name.
 */
const KARTE_NAME_UNTER = 1.8;  // fremder Punkt ueber/unter dem Namen: Mindestabstand zur Mitte, in Punktgroessen
const KARTE_NAME_SEITE = 1.0;  // fremder Punkt seitlich vom Namen

function karte_namen(array $punkte, float $rp, array $ausschnitt): array
{
    [$vx, $vy, $vw, $vh] = $ausschnitt;
    $schrift = $rp;
    uasort($punkte, fn($a, $b) => $b['rang'] <=> $a['rang']);
    // Belegte Flaechen: zuerst alle Punkte, dann die gesetzten Namen
    $belegt = [];
    foreach ($punkte as $p) {
        $belegt[] = [$p['xy'][0] - $rp, $p['xy'][1] - $rp, $p['xy'][0] + $rp, $p['xy'][1] + $rp];
    }
    // Liegt ein fremder Punkt zu nah am Namen? Ueber/unter dem Namen strenger als seitlich
    $zuNah = function (array $box, array $xy) use ($rp): bool {
        $dx = max($box[0] - $xy[0], 0, $xy[0] - $box[2]);
        $dy = max($box[1] - $xy[1], 0, $xy[1] - $box[3]);
        return $dx == 0 ? $dy < KARTE_NAME_UNTER * $rp : hypot($dx, $dy) < KARTE_NAME_SEITE * $rp;
    };
    $out = [];
    foreach ($punkte as $k => $p) {
        $b = mb_strlen($p['name']) * 0.55 * $schrift;
        [$x, $y] = $p['xy'];
        $kandidaten = [
            'rechts' => [$x + $rp * 1.3, $y - $schrift * 0.6, $x + $rp * 1.3 + $b, $y + $schrift * 0.6],
            'links'  => [$x - $rp * 1.3 - $b, $y - $schrift * 0.6, $x - $rp * 1.3, $y + $schrift * 0.6],
        ];
        foreach ($kandidaten as $seite => $box) {
            if ($box[0] < $vx || $box[2] > $vx + $vw || $box[1] < $vy || $box[3] > $vy + $vh) {
                continue;
            }
            $frei = true;
            foreach ($punkte as $k2 => $p2) {
                if ($k2 !== $k && $zuNah($box, $p2['xy'])) {
                    $frei = false;
                    break;
                }
            }
            foreach ($frei ? $belegt : [] as $o) {
                if ($box[0] < $o[2] && $box[2] > $o[0] && $box[1] < $o[3] && $box[3] > $o[1]) {
                    $frei = false;
                    break;
                }
            }
            if ($frei) {
                $out[$k] = $seite;
                $belegt[] = $box;
                break;
            }
        }
    }
    return $out;
}

/**
 * Wert eines Punkts: Chance am Tag $tag, oder ohne Tag die beste Chance der
 * angezeigten Tage (ohne Ensemble: 1 wenn das Modellmittel ein Fenster sieht).
 * Rueckgabe ['chance' => ?float, 'tag' => ?string, 'geht' => bool]
 */
function karte_wert(array $eintrag, ?string $tag): array
{
    $tage = $tag !== null ? [$tag => $eintrag['tage'][$tag] ?? null] : $eintrag['tage'];
    $best = ['chance' => null, 'tag' => null, 'geht' => false];
    foreach ($tage as $t => $d) {
        if ($d === null || !empty($d['vorbei']) || !empty($d['offen'])) {
            continue;
        }
        $c = $d['ens']['chance'] ?? null;
        $geht = (bool)$d['det']['geht'];
        $wert = $c ?? ($geht ? 1.0 : 0.0);
        $bisher = $best['chance'] ?? ($best['geht'] ? 1.0 : -1.0);
        if ($best['tag'] === null || $wert > $bisher) {
            $best = ['chance' => $c, 'tag' => $t, 'geht' => $geht];
        }
    }
    return $best;
}

// ===================================================================
// MAENNCHEN AM STANDORT (seit 8.11.2026, fuer beide Modi seit 9.11.2026)
// ===================================================================

/**
 * Standort als Maennchen mit allem, was die Animation braucht (Skript lib/maennchen.js):
 * Bus mit Fahrer und Spur, Puls am Ziel, Blicklinie, Ankunft am Ziel, Sprechblase.
 * Steht ueber den Punkten mit den Fuessen am Ort. Geometrie in Einheiten von 0,7 x $rp
 * (Kopf oben bei -3,65), die Gruppe skaliert. Ohne JavaScript steht es still da.
 * $ankunft: 'palme' (Meer im Sueden), 'sack' (Windsack), 'ski' (Schnee-Modus: Ski im
 * Schnee, Flocken; der Bus hat dann Ski auf dem Dach). Ohne Ziel nur das Maennchen.
 * $flugzeug (seit 9.11.2026, Flugziele im Wind-Modus): statt des Busses ein Flugzeug, das im
 * hohen Bogen hinfliegt; gleiche ids, damit das Skript es wie den Bus bewegt.
 */
function maennchen_svg(array $heimXY, float $rp, ?array $ziel, string $ankunft, string $titel, bool $flugzeug = false): string
{
    $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $u = $rp * 0.7;
    $hx = round($heimXY[0], 1);
    $hy = round($heimXY[1], 1);
    $arm = fn(string $id, float $x, float $grad) => '<rect class="arm" id="' . $id . '" x="-0.15" y="0" width="0.3" height="1.4" rx="0.15" transform="translate(' . $x . ' -2.2) rotate(' . $grad . ')"/>';
    $o = '';
    if ($ziel) {
        $zx = round($ziel[0], 1);
        $zy = round($ziel[1], 1);
        $o .= '<path class="busspur" id="heimSpur" d="" opacity="0"/>' . "\n"
            . '<circle class="puls" id="heimPuls" cx="' . $zx . '" cy="' . $zy . '" r="' . round($rp, 2) . '" opacity="0"/>' . "\n"
            . ($flugzeug
               // Seitenansicht, Nase nach rechts, Raeder auf y = 0 wie beim Bus
               ? '<g class="bus flugzeug" id="heimBus" data-art="flugzeug" opacity="0">'
                 . '<path class="leitwerk" d="M-2.55,-1.75L-3.05,-3.55H-2.35L-1.35,-1.75Z"/>'
                 . '<path class="wagen" d="M-3.05,-1.45Q-3.05,-2.05 -2.45,-2.05H1.9Q3.25,-2.05 3.4,-1.35Q3.25,-0.75 2.2,-0.75H-2.45Q-3.05,-0.75 -3.05,-1.45Z"/>'
                 . '<path class="fluegel" d="M-0.85,-1.3L0.65,-1.3L-0.55,0.15H-1.25Z"/>'
                 . '<path class="fenster" d="M2.35,-1.9Q3.05,-1.85 3.15,-1.45H2.35Z"/>'
                 . '<circle class="fenster" cx="-1.7" cy="-1.55" r="0.17"/><circle class="fenster" cx="-1.05" cy="-1.55" r="0.17"/><circle class="fenster" cx="0.95" cy="-1.55" r="0.17"/><circle class="fenster" cx="1.6" cy="-1.55" r="0.17"/>'
                 . '<line class="fahrwerk" x1="1.7" y1="-0.75" x2="1.7" y2="-0.3"/><line class="fahrwerk" x1="-0.9" y1="-0.75" x2="-0.9" y2="-0.3"/>'
                 . '<circle class="rad" cx="1.7" cy="-0.22" r="0.22"/><circle class="rad" cx="-0.9" cy="-0.22" r="0.22"/>'
                 . '<circle class="fahrer" id="heimFahrer" cx="2.55" cy="-1.55" r="0.25" opacity="0"/></g>' . "\n"
               : '<g class="bus" id="heimBus" opacity="0">'
            . ($ankunft === 'ski' ? '<rect class="skier" x="-2.6" y="-4.32" width="3.9" height="0.26" rx="0.13"/>' : '')
            . '<rect class="dach" x="-2.2" y="-4" width="3.2" height="0.6" rx="0.25"/>'
            . '<path class="wagen" d="M-2.8,-0.8V-2.9Q-2.8,-3.3 -2.4,-3.3H1.5L2.8,-1.9V-0.8Z"/>'
            . '<path class="fenster" d="M1.65,-3.05L2.5,-2H1.65Z"/>'
            . '<rect class="fenster" x="-2.35" y="-2.95" width="1.2" height="0.8" rx="0.2"/>'
            . '<rect class="fenster" x="-0.85" y="-2.95" width="1.4" height="0.8" rx="0.2"/>'
            . '<circle class="rad" cx="-1.6" cy="-0.7" r="0.62"/><circle class="nabe" cx="-1.6" cy="-0.7" r="0.22"/>'
            . '<circle class="rad" cx="1.6" cy="-0.7" r="0.62"/><circle class="nabe" cx="1.6" cy="-0.7" r="0.22"/>'
            . '<circle class="fahrer" id="heimFahrer" cx="0" cy="-2.5" r="0.3" opacity="0"/></g>' . "\n")
            . '<path class="blick" id="heimBlick" d="" opacity="0"/>' . "\n";
        if ($ankunft === 'palme') {
            $o .= '<g class="ankunft palme" id="heimAnkunft" data-art="palme" opacity="0"><path class="stamm" d="M0,0Q0.4,-2 0.15,-4"/><g id="heimWedel">';
            foreach ([-165, -125, -80, -40, -5] as $w) {
                $o .= '<path class="wedel" data-w="' . $w . '" d="M0,0Q1.1,-0.7 2.3,0.4"/>';
            }
            $o .= '</g></g>';
        } elseif ($ankunft === 'ski') {
            // Zwei Ski ueber Kreuz im Schneehaufen, ringsum fallen Flocken (Skript)
            $o .= '<g class="ankunft ski" id="heimAnkunft" data-art="ski" opacity="0"><g id="heimSki">'
                . '<rect class="brett" x="-0.28" y="-4.4" width="0.56" height="4.4" rx="0.28" transform="translate(-0.75 0) rotate(15)"/>'
                . '<rect class="brett" x="-0.28" y="-4.4" width="0.56" height="4.4" rx="0.28" transform="translate(0.75 0) rotate(-15)"/></g>'
                . '<path class="hang" d="M-2.3,0.15Q-1.2,-1.2 0,-1.05Q1.2,-1.2 2.3,0.15Z"/><g id="heimFlocken">';
            for ($i = 0; $i < 7; $i++) {
                $o .= '<circle class="flocke" r="0.32" opacity="0"/>';
            }
            $o .= '</g></g>';
        } else {
            $o .= '<g class="ankunft windsack" id="heimAnkunft" data-art="sack" opacity="0"><line class="mast" x1="0" y1="0" x2="0" y2="-4.2"/><g id="heimSack">';
            for ($i = 0; $i < 4; $i++) {
                $o .= '<polygon class="' . ($i % 2 ? 'hell' : 'rot') . '" points="0,0"/>';
            }
            $o .= '</g></g>';
        }
        $o .= "\n" . '<g class="juchhu" id="heimJuchhu" opacity="0"><path d="M0,0L0.9,-1H4.2Q4.6,-1 4.6,-1.4V-2.6Q4.6,-3 4.2,-3H-0.4Q-0.8,-3 -0.8,-2.6V-1.4Q-0.8,-1 -0.4,-1H0.25Z"/><text x="1.9" y="-1.58" font-size="1.2">Juchhu!</text></g>' . "\n";
    }
    $o .= '<g class="heim" id="heim" transform="translate(' . $hx . ' ' . $hy . ') scale(' . round($u, 3) . ')"'
        . "\n" . '   data-x="' . $hx . '" data-y="' . $hy . '" data-u="' . round($u, 3) . '" data-rp="' . round($rp, 3) . '"'
        . ($ziel ? ' data-wohin="' . round($ziel[0], 1) . ',' . round($ziel[1], 1) . '"' : '') . ">\n"
        . '  <title>' . $h($titel) . ($ziel ? ' · antippen: wohin fahr ma?' : '') . "</title>\n"
        . '  ' . $arm('heimArmL', -0.62, 12) . "\n"
        . '  ' . $arm('heimArmR', 0.62, -12) . "\n"
        . '  <rect class="arm" id="heimOber" x="-0.15" y="0" width="0.3" height="0.55" rx="0.15" opacity="0"/>' . "\n"
        . '  <rect class="arm" id="heimUnter" x="-0.15" y="0" width="0.3" height="0.85" rx="0.15" opacity="0"/>' . "\n"
        . '  <rect class="arm" id="heimZeig" x="-0.15" y="0" width="0.3" height="1.4" rx="0.15" opacity="0"/>' . "\n"
        . '  <path d="M-0.75,-2.35H0.75V-1.1H0.55V0H0.13V-1H-0.13V0H-0.55V-1.1H-0.75Z"/>' . "\n"
        . '  <circle id="heimKopf" cx="0" cy="-3.25" r="0.4"/>' . "\n"
        . '</g>';
    return $o;
}

/** Schalter "Ton beim Antippen des Maennchens" fuer den Fuss (das Skript zeigt ihn, wenn es ein Ziel gibt) */
function maennchen_ton_schalter(): string
{
    return '<div class="design" id="ton" role="group" aria-label="Ton" hidden><span>Ton beim Antippen des Männchens</span><button type="button" data-ton="an" aria-pressed="false">An</button><button type="button" data-ton="aus" aria-pressed="false">Aus</button></div>';
}
