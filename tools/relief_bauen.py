#!/usr/bin/env python3
"""
Hangschatten der Alpen fuer die Schnee-Karte (seit 18.11.2026).

Laedt Hoehenkacheln (Terrarium, Mapzen Terrain Tiles auf AWS Open Data) in einer
Zoomstufe, rechnet einen Hangschatten und speichert ihn als WebP mit Alpha:
Schatten schwarz, Sonnenhaenge weiss, ebenes Gelaende durchsichtig. So wirkt das
Bild auf heller und dunkler Karte. Die Seite legt es unter die Flaechen
(lib/karte.php, relief_svg()).

Die Kacheln sind Web-Mercator wie karte.svg (tools/karte_bauen.py): das Bild
braucht keine Umprojektion, nur die Lage der Ecken. Die steht in relief.json.

Aufruf (in der Action "Relief bauen", hier kein Netz noetig ausser den Kacheln):
    python3 tools/relief_bauen.py server/web/relief.webp

Quellen (Pflichtangaben laut github.com/tilezen/joerd/blob/master/docs/attribution.md):
EU-DEM (Copernicus, EU), SRTM und GMTED2010 (USGS), ETOPO1 (NOAA) u. a.
"""
import io
import json
import math
import os
import sys
import time
import urllib.request

import numpy as np
from PIL import Image

Z = 8                       # rund 425 m je Pixel in den Alpen
X0, X1 = 131, 140           # Laenge 4,2 bis 18,3 Grad Ost
Y0, Y1 = 88, 93             # Breite 49,3 bis 43,1 Grad Nord
URL = 'https://s3.amazonaws.com/elevation-tiles-prod/terrarium/{z}/{x}/{y}.png'

UEBERHOEHUNG = 2.0          # bei 425 m je Pixel wirken echte Hangneigungen zu flach
SONNE_AZIMUT = 315.0        # Licht von Nordwesten, wie ueblich
SONNE_HOEHE = 45.0
SCHATTEN_MAX = 0.75         # Deckkraft der tiefsten Schatten
LICHT_MAX = 0.45            # Deckkraft der hellsten Sonnenhaenge
RAND_PX = 80                # weicher Rand, damit das Bild nicht mit einer Kante endet


def kachel(x, y):
    url = URL.format(z=Z, x=x, y=y)
    for versuch in range(4):
        try:
            with urllib.request.urlopen(url, timeout=30) as r:
                bild = Image.open(io.BytesIO(r.read())).convert('RGB')
            a = np.asarray(bild, dtype=np.float64)
            return a[:, :, 0] * 256 + a[:, :, 1] + a[:, :, 2] / 256 - 32768
        except Exception as e:  # noqa: BLE001
            print(f'  {x}/{y}: {e}, neuer Versuch', flush=True)
            time.sleep(2 + 3 * versuch)
    raise SystemExit(f'Kachel {x}/{y} nicht geladen')


def breite(y_px):
    """Breite (Grad) am oberen Rand der Pixelzeile y_px (ganzes Bild, ab Kachel Y0)."""
    n = 2 ** Z * 256
    yy = Y0 * 256 + y_px
    return math.degrees(math.atan(math.sinh(math.pi * (1 - 2 * yy / n))))


def main(ziel):
    reihen = []
    for y in range(Y0, Y1 + 1):
        reihen.append(np.hstack([kachel(x, y) for x in range(X0, X1 + 1)]))
        print(f'Reihe {y} geladen', flush=True)
    h = np.vstack(reihen)
    h = np.maximum(h, 0.0)                      # Meer und Seegrund flach
    hoehe_px, breite_px = h.shape
    print(f'Hoehe {h.min():.0f} bis {h.max():.0f} m, {breite_px} x {hoehe_px} Pixel')

    # Pixelgroesse in m je Zeile (Mercator: quadratisch, schrumpft mit cos(Breite))
    lat = np.array([breite(i + 0.5) for i in range(hoehe_px)])
    zelle = 40075016.686 * np.cos(np.radians(lat)) / (256 * 2 ** Z)
    dzdy_px, dzdx_px = np.gradient(h)
    dzdx = dzdx_px / zelle[:, None] * UEBERHOEHUNG
    dzdn = -dzdy_px / zelle[:, None] * UEBERHOEHUNG     # Zeilen laufen nach Sueden

    # Normale (-dz/dx, -dz/dn, 1), Licht aus SONNE_AZIMUT (im Uhrzeigersinn ab Nord)
    az, alt = math.radians(SONNE_AZIMUT), math.radians(SONNE_HOEHE)
    lx, ln, lz = math.sin(az) * math.cos(alt), math.cos(az) * math.cos(alt), math.sin(alt)
    betrag = np.sqrt(dzdx ** 2 + dzdn ** 2 + 1)
    licht = (-dzdx * lx - dzdn * ln + lz) / betrag
    abw = licht - math.sin(alt)                         # 0 = eben

    schatten = np.clip(-abw / 0.55, 0, 1) ** 0.8 * SCHATTEN_MAX
    hell = np.clip(abw / 0.3, 0, 1) ** 0.9 * LICHT_MAX

    # weicher Rand
    yy, xx = np.mgrid[0:hoehe_px, 0:breite_px]
    rand = np.minimum.reduce([xx, yy, breite_px - 1 - xx, hoehe_px - 1 - yy]).astype(np.float64)
    rand = np.clip(rand / RAND_PX, 0, 1)
    rand = rand * rand * (3 - 2 * rand)

    alpha = np.maximum(schatten, hell) * rand
    grau = np.where(hell > schatten, 255, 0)
    rgba = np.zeros((hoehe_px, breite_px, 4), dtype=np.uint8)
    rgba[:, :, 0] = rgba[:, :, 1] = rgba[:, :, 2] = grau
    rgba[:, :, 3] = np.round(alpha * 255).astype(np.uint8)

    Image.fromarray(rgba, 'RGBA').save(ziel, 'WEBP', quality=75, alpha_quality=70, method=6)
    meta = {
        'datei': os.path.basename(ziel),
        'z': Z, 'x': [X0, X1], 'y': [Y0, Y1],
        'pixel': [breite_px, hoehe_px],
        'quelle': URL.format(z='{z}', x='{x}', y='{y}'),
        'gebaut': time.strftime('%Y-%m-%d'),
        'einstellungen': {'ueberhoehung': UEBERHOEHUNG, 'azimut': SONNE_AZIMUT, 'sonnenhoehe': SONNE_HOEHE},
    }
    with open(os.path.join(os.path.dirname(ziel), 'relief.json'), 'w') as f:
        json.dump(meta, f, indent=1)
        f.write('\n')
    print(f'{ziel}: {os.path.getsize(ziel) / 1024:.0f} KB')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)
    main(sys.argv[1])
