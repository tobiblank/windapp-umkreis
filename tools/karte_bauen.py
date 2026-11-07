#!/usr/bin/env python3
"""
Kartengrundlage fuer Wohinfahrma (seit 7.11.2026): Laender Europas mit
Nordafrika und Kanaren als eine SVG-Datei, ohne fremde Kachelserver.

Quelle: Natural Earth, ne_50m_admin_0_countries_lakes.geojson (gemeinfrei,
"Made with Natural Earth"). Grosse Seen sind darin ausgespart (Bodensee,
Gardasee, Genfer See ...); kleine wie der Ammersee fehlen bei 1:50 Mio.

    python3 tools/karte_bauen.py <ne_50m_admin_0_countries_lakes.geojson> server/web/karte.svg

Projektion: Mercator, in Einheiten von KARTE_K je Laengengrad, Ursprung
(KARTE_LON0, KARTE_LAT_MAX) oben links. Dieselben Zahlen stehen in
server/web/lib/karte.php (die Punkte der Regionen) und als data-Attribute im
SVG; tests/test.php vergleicht beides. Nur Standardbibliothek.
"""

import json
import math
import sys

LON0, LON1 = -20.0, 36.0     # Westen bis Osten
LAT0, LAT1 = 22.0, 64.0      # Sueden bis Norden
K = 10.0                     # Einheiten je Laengengrad
EPS = 0.04                   # Vereinfachung in Einheiten (0,004 Grad, rund 400 m)
MIN_FLAECHE = 0.02           # kleinere Inseln weglassen (Einheiten^2)


def merc(lat):
    return math.degrees(math.log(math.tan(math.pi / 4 + math.radians(lat) / 2)))


YMAX = merc(LAT1)


def proj(lon, lat):
    return ((lon - LON0) * K, (YMAX - merc(lat)) * K)


def clip(ring):
    """Sutherland-Hodgman gegen das Rechteck [LON0, LON1] x [LAT0, LAT1]"""
    kanten = [
        (lambda p: p[0] >= LON0, lambda a, b: schnitt_x(a, b, LON0)),
        (lambda p: p[0] <= LON1, lambda a, b: schnitt_x(a, b, LON1)),
        (lambda p: p[1] >= LAT0, lambda a, b: schnitt_y(a, b, LAT0)),
        (lambda p: p[1] <= LAT1, lambda a, b: schnitt_y(a, b, LAT1)),
    ]
    pts = ring
    for innen, schnitt in kanten:
        if not pts:
            break
        aus = []
        prev = pts[-1]
        for p in pts:
            if innen(p):
                if not innen(prev):
                    aus.append(schnitt(prev, p))
                aus.append(p)
            elif innen(prev):
                aus.append(schnitt(prev, p))
            prev = p
        pts = aus
    return pts


def schnitt_x(a, b, x):
    t = (x - a[0]) / (b[0] - a[0])
    return (x, a[1] + t * (b[1] - a[1]))


def schnitt_y(a, b, y):
    t = (y - a[1]) / (b[1] - a[1])
    return (a[0] + t * (b[0] - a[0]), y)


def dp(pts, eps):
    """Douglas-Peucker, iterativ"""
    if len(pts) < 3:
        return pts
    behalten = [False] * len(pts)
    behalten[0] = behalten[-1] = True
    stapel = [(0, len(pts) - 1)]
    while stapel:
        a, b = stapel.pop()
        ax, ay = pts[a]
        bx, by = pts[b]
        dx, dy = bx - ax, by - ay
        lang = math.hypot(dx, dy)
        best, idx = -1.0, -1
        for i in range(a + 1, b):
            px, py = pts[i]
            d = abs(dy * (px - ax) - dx * (py - ay)) / lang if lang > 0 else math.hypot(px - ax, py - ay)
            if d > best:
                best, idx = d, i
        if best > eps:
            behalten[idx] = True
            stapel += [(a, idx), (idx, b)]
    return [p for p, k in zip(pts, behalten) if k]


def flaeche(pts):
    return abs(sum(x1 * y2 - x2 * y1 for (x1, y1), (x2, y2) in zip(pts, pts[1:] + pts[:1]))) / 2


def pfad(pts):
    teile = []
    vx = vy = None
    for i, (x, y) in enumerate(pts):
        x, y = round(x, 1), round(y, 1)
        if (x, y) == (vx, vy):
            continue
        if i == 0:
            teile.append(f"M{x:g} {y:g}")
        else:
            teile.append(f"l{round(x - vx, 1):g} {round(y - vy, 1):g}")
        vx, vy = x, y
    s = "".join(teile).replace(" -", "-") + "z"
    return s


def main(quelle, ziel):
    daten = json.load(open(quelle, encoding="utf-8"))
    stuecke = []
    for f in daten["features"]:
        g = f["geometry"]
        polys = g["coordinates"] if g["type"] == "MultiPolygon" else [g["coordinates"]]
        for poly in polys:
            for nr, ring in enumerate(poly):
                ring = [(float(x), float(y)) for x, y in ring[:-1]]
                if not ring:
                    continue
                xs = [p[0] for p in ring]
                ys = [p[1] for p in ring]
                if max(xs) < LON0 or min(xs) > LON1 or max(ys) < LAT0 or min(ys) > LAT1:
                    continue
                c = clip(ring)
                if len(c) < 3:
                    continue
                pr = dp([proj(x, y) for x, y in c] + [proj(*c[0])], EPS)[:-1]
                if len(pr) < 3 or flaeche(pr) < MIN_FLAECHE:
                    continue
                stuecke.append(pfad(pr))
    breite = (LON1 - LON0) * K
    hoehe = (YMAX - merc(LAT0)) * K
    svg = (
        '<svg xmlns="http://www.w3.org/2000/svg" '
        f'viewBox="0 0 {breite:g} {hoehe:.1f}" '
        f'data-lon0="{LON0:g}" data-lat-max="{LAT1:g}" data-k="{K:g}">'
        '<!-- Made with Natural Earth (gemeinfrei). Erzeugt mit tools/karte_bauen.py -->'
        f'<path id="land" fill-rule="evenodd" vector-effect="non-scaling-stroke" d="{"".join(stuecke)}"/></svg>\n'
    )
    open(ziel, "w", encoding="utf-8").write(svg)
    print(f"{len(stuecke)} Ringe, {len(svg) / 1000:.0f} KB, {breite:g} x {hoehe:.1f} Einheiten")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    main(sys.argv[1], sys.argv[2])
