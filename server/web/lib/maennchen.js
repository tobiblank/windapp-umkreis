// Maennchen auf der Landkarte (seit 8.11.2026, aus index.php ausgelagert und im Schnee-Modus
// seit 9.11.2026; Markup: maennchen_svg() in lib/karte.php): Hand an die Stirn, nach links und rechts
// umsehen, auf die beste Region zeigen (mit gestrichelter Blicklinie), vor Freude mit
// hochgerissenen Armen springen ("Juchhu!" in einer Sprechblase), in den Bus steigen
// und als Fahrer hinfahren; am Ziel wiegt sich eine Palme (Meer im Sueden) oder flattert ein
// Windsack, im Schnee-Modus stecken Ski im Schnee und es schneit; dann steht es wieder zu Hause
// und zeigt hin. Ohne gute Region: umsehen und Schultern zucken. Gleichzeitig ziehen an guten
// Regionen Windstriche in Windrichtung ueber den Punkt (Laenge und Tempo nach der Staerke), und
// ihr Windpfeil wackelt kurz; im Schnee-Modus fallen dort Flocken (mehr bei mehr Neuschnee).
// Startet, sobald die Karte sichtbar ist; Scrollen, Antippen der Karte oder eine Taste beenden
// sofort in der Endpose. Bei "Bewegung reduzieren" gleich die Endpose. Antippen des Maennchens
// spielt alles neu ab, dann mit Ton: Jubelpfiff beim Juchhu, Doppelhupe beim Einsteigen,
// Motor waehrend der Fahrt. Bei Flugzielen (seit 9.11.2026) ein Flugzeug statt des Busses: hoher
// Bogen, die Nase folgt der Bahn, es wird unterwegs etwas groesser (hoch oben), Duesenrauschen
// statt Hupe und Motor; statt der gestrichelten Spur bleiben kleine graue „CO₂“-Wölkchen
// hinter dem Flugzeug stehen, auch nach der Landung
// (Web Audio, ohne Datei; seit 8.11.2026). Beim Laden bleibt es stumm, weil Browser Ton erst
// nach einem Antippen erlauben und eine Wetterseite nicht von selbst brummen soll. Schalter
// "Ton" im Fuss, gemerkt in wohinfahrma_ton (Standard an). Zeiten in ms, Laengen in Einheiten
// des Maennchens.
(function () {
  var heim = document.getElementById('heim');
  if (!heim || !window.requestAnimationFrame) { return; }
  var svg = heim.ownerSVGElement;
  var NS = 'http://www.w3.org/2000/svg';
  var X = +heim.dataset.x, Y = +heim.dataset.y, U = +heim.dataset.u, RP = +heim.dataset.rp;
  var ziel = heim.dataset.wohin ? heim.dataset.wohin.split(',').map(Number) : null;
  function el(id) { return document.getElementById(id); }
  var kopf = el('heimKopf'), armL = el('heimArmL'), armR = el('heimArmR'), ober = el('heimOber'),
      unter = el('heimUnter'), zeig = el('heimZeig'), bus = el('heimBus'), spur = el('heimSpur'),
      puls = el('heimPuls'), fahrer = el('heimFahrer'), blick = el('heimBlick'), ankunft = el('heimAnkunft'),
      juchhu = el('heimJuchhu');
  var ARM = 1.4, SX = 0.62, SY = -2.2, ELLE = [1.05, -2.55];
  var dir = ziel && ziel[0] < X ? -1 : 1;
  var flieger = !!(bus && bus.dataset.art === 'flugzeug');
  var fahrwerk = flieger ? bus.querySelectorAll('.fahrwerk, .rad') : [];
  // Fahrt im Bogen vom Maennchen zum Ziel, der Bus parkt neben dem Punkt. Nur die Entfernung
  // zaehlt: bis 8.11.2026 abends musste das Ziel auch seitlich weit genug weg sein, dann fuhr
  // der Bus zu Zielen fast genau im Sueden (Adria ab Inning) nie los.
  var A = null, B = null, C = null;
  if (ziel && bus) {
    A = [X + 4.2 * U * dir, Y];
    B = [ziel[0] - (RP * 1.4 + 3 * U) * dir, ziel[1] + RP * 0.8];
    var dx = B[0] - A[0], dy = B[1] - A[1], d = Math.sqrt(dx * dx + dy * dy);
    if (d > 6 * U) {
      var nx = -dy / d, ny = dx / d;
      if (ny > 0) { nx = -nx; ny = -ny; }   // Bogen nach oben
      var bogen = flieger ? 0.35 : 0.2;
      C = [(A[0] + B[0]) / 2 + nx * d * bogen, (A[1] + B[1]) / 2 + ny * d * bogen];
      spur.setAttribute('d', 'M' + A + 'Q' + C + ' ' + B);
    } else { A = null; }
  }
  var fahrt = !!A;
  // CO2-Woelkchen entlang der Flugbahn (seit 9.11.2026), erscheinen, wenn das Flugzeug vorbei ist
  var woelkchen = [];
  if (fahrt && flieger) {
    var co2 = document.createElementNS(NS, 'g');
    co2.setAttribute('class', 'co2spur');
    bus.parentNode.insertBefore(co2, bus);
    // seit 9.11.2026 abends 6 groessere statt 9 kleiner: auf dem Handy war "CO₂" kaum zu lesen
    for (var i = 0; i < 6; i++) {
      var f = 0.1 + i * 0.15, uf = 1 - f, g = document.createElementNS(NS, 'g');
      var wx = uf * uf * A[0] + 2 * uf * f * C[0] + f * f * B[0], wy = uf * uf * A[1] + 2 * uf * f * C[1] + f * f * B[1] - 1.6 * U;
      g.innerHTML = '<circle cx="-0.65" cy="0.1" r="0.62"/><circle cx="0.6" cy="0.1" r="0.62"/><circle cx="0" cy="-0.25" r="0.8"/>'
                  + '<text x="0" y="0.32" font-size="0.78">CO₂</text>';
      g.setAttribute('opacity', 0);
      co2.appendChild(g);
      woelkchen.push({ g: g, f: f, x: wx, y: wy });
    }
  }
  // Ankunft: ueber dem Zielpunkt
  var wedel = [], sack = [], flocken = [], art = ankunft ? ankunft.dataset.art : null;
  if (ankunft) {
    wedel = Array.prototype.slice.call(ankunft.querySelectorAll('.wedel'));
    sack = Array.prototype.slice.call(ankunft.querySelectorAll('polygon'));
    flocken = Array.prototype.slice.call(ankunft.querySelectorAll('.flocke'));
  }
  // Schnee-Modus: an guten Regionen (data-schneit="x,y,cm") fallen Flocken ueber den Punkt,
  // 3 bis 7 je nach Neuschnee, ueber den Punkten und unter dem Maennchen
  var schneien = [];
  var schneit = svg.querySelectorAll('[data-schneit]');
  if (schneit.length) {
    var lage = document.createElementNS(NS, 'g');
    lage.setAttribute('class', 'schneeflocken');
    var vor = el('heimSpur') || heim;
    vor.parentNode.insertBefore(lage, vor);
    Array.prototype.forEach.call(schneit, function (s) {
      var w = s.dataset.schneit.split(',').map(Number), n = Math.max(3, Math.min(7, 3 + Math.round(w[2] / 10)));
      var fl = [];
      for (var i = 0; i < n; i++) {
        var c = document.createElementNS(NS, 'circle');
        c.setAttribute('r', (RP * 0.28).toFixed(2));
        c.setAttribute('opacity', 0);
        lage.appendChild(c);
        fl.push(c);
      }
      schneien.push({ x: w[0], y: w[1], fl: fl, k: schneien.length });
    });
  }
  // Windstriche an guten Regionen: 4 je Region, unter den Punkten
  var wehen = [];
  var pfeile = svg.querySelectorAll('.windpfeil[data-weht]');
  if (pfeile.length) {
    var schicht = document.createElementNS(NS, 'g');
    schicht.setAttribute('class', 'windstriche');
    var erster = svg.querySelector('.punkt');
    erster.parentNode.insertBefore(schicht, erster);
    Array.prototype.forEach.call(pfeile, function (pf) {
      var w = pf.dataset.weht.split(',').map(Number), th = w[2] * Math.PI / 180;
      var striche = [];
      for (var i = 0; i < 4; i++) {
        var l = document.createElementNS(NS, 'line');
        l.setAttribute('opacity', 0);
        schicht.appendChild(l);
        striche.push(l);
      }
      wehen.push({ pf: pf, trafo: pf.getAttribute('transform'), x: w[0], y: w[1], von: w[2], kn: w[3],
                   vx: -Math.sin(th), vy: Math.cos(th), striche: striche, k: wehen.length });
    });
  }
  // Juchhu von 4000 bis 4900 ms; alles danach (Bus, Fahrt, Ankunft) liegt seit 8.11.2026 abends
  // um 900 ms spaeter als in der ersten Fassung
  var ENDE = ziel ? (fahrt ? 10200 : 5300) : 4300;
  // Sprechblase ueber dem Kopf; ist darueber kein Platz (Standort am oberen Kartenrand),
  // rechts neben dem Kopf mit dem Zipfel nach links
  var blaseOben = true;
  if (juchhu) {
    var vb = svg.viewBox && svg.viewBox.baseVal;
    if (vb && Y - 7.4 * U < vb.y) {
      blaseOben = false;
      juchhu.querySelector('path').setAttribute('d', 'M0,0L0.8,-0.4V-0.6Q0.8,-1 1.2,-1H5.6Q6,-1 6,-0.6V0.6Q6,1 5.6,1H1.2Q0.8,1 0.8,0.6V0.4Z');
      var tx = juchhu.querySelector('text');
      tx.setAttribute('x', '3.4'); tx.setAttribute('y', '0.42');
    }
  }
  function cl(v) { return v < 0 ? 0 : v > 1 ? 1 : v; }
  function ease(t) { return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; }
  function seg(t, a, b) { return ease(cl((t - a) / (b - a))); }
  function wende(s) { return Math.abs(s) < 0.12 ? (s < 0 ? -0.12 : 0.12) : s; }   // schmal statt unsichtbar
  function grad(x, y) { return Math.atan2(y, x) * 180 / Math.PI - 90; }   // Arm zeigt in Richtung (x, y)
  function arm(e, x, y, w, sichtbar) {
    e.setAttribute('transform', 'translate(' + x + ' ' + y + ') rotate(' + w.toFixed(1) + ')');
    e.setAttribute('opacity', sichtbar ? 1 : 0);
  }
  function schnee(t) {   // Flocken an guten Regionen, wie die Windstriche bis 4200 ms
    schneien.forEach(function (g) {
      g.fl.forEach(function (c, i) {
        var p = (t - i * 260 - g.k * 150) / 1500;
        if (t > 4200 || p < 0) { c.setAttribute('opacity', 0); return; }
        p = p % 1;
        var x = g.x + RP * (((i * 0.618 + g.k * 0.29) % 1) * 3 - 1.5) + RP * 0.35 * Math.sin(t / 300 + i * 2.1);
        var y = g.y + RP * (-2.8 + 4.4 * p);
        c.setAttribute('cx', x.toFixed(2)); c.setAttribute('cy', y.toFixed(2));
        c.setAttribute('opacity', (Math.sin(Math.PI * p) * (t > 3700 ? cl((4200 - t) / 500) : 1)).toFixed(2));
      });
    });
  }
  function wind(t) {
    schnee(t);
    wehen.forEach(function (g) {
      var wackel = t < 1600 ? 10 * Math.sin(t / 70) * (1 - t / 1600) : 0;
      g.pf.setAttribute('transform', wackel ? g.trafo.replace(/rotate\(([-\d.]+)\)/, 'rotate(' + (g.von + wackel).toFixed(1) + ')') : g.trafo);
      var tempo = 0.6 + g.kn / 30, len = RP * (1.1 + g.kn / 30);
      g.striche.forEach(function (l, i) {
        var p = (t - i * 380 - g.k * 120) / (1100 / tempo);
        if (t > 4200 || p < 0) { l.setAttribute('opacity', 0); return; }
        p = p % 1;
        var s = RP * (-3.3 + 6.6 * p), off = RP * [-0.8, 0.35, -0.2, 0.9][i];
        var x1 = g.x + g.vx * s - g.vy * off, y1 = g.y + g.vy * s + g.vx * off;
        l.setAttribute('x1', x1.toFixed(2)); l.setAttribute('y1', y1.toFixed(2));
        l.setAttribute('x2', (x1 + g.vx * len).toFixed(2)); l.setAttribute('y2', (y1 + g.vy * len).toFixed(2));
        l.setAttribute('opacity', (0.75 * Math.sin(Math.PI * p) * (t > 3700 ? cl((4200 - t) / 500) : 1)).toFixed(2));
      });
    });
  }
  function weht(t, op) {   // Palme, Windsack oder Ski im Schnee am Ziel
    if (!ankunft) { return; }
    var auf = seg(t, 7900, 8250), an = t > 7900 ? t - 7900 : 0, s = 0.3 + 0.7 * auf;
    ankunft.setAttribute('opacity', (auf * op).toFixed(2));
    var tr = 'translate(' + ziel[0] + ' ' + (ziel[1] - RP * 1.05) + ') scale(' + (s * U) + ')';
    if (art === 'ski') {
      // Ski stecken, wippen beim Einstecken kurz nach; ringsum fallen Flocken
      var wipp = an && an < 900 ? 6 * Math.sin(an / 60) * (1 - an / 900) : 0;
      ankunft.setAttribute('transform', tr + ' rotate(' + wipp.toFixed(1) + ')');
      flocken.forEach(function (c, i) {
        var p = an ? ((an / 1600) + i / flocken.length) % 1 : 0;
        c.setAttribute('cx', (-2.4 + ((i * 0.618) % 1) * 4.8 + 0.4 * Math.sin(an / 280 + i * 1.7)).toFixed(2));
        c.setAttribute('cy', (-6.2 + 6 * p).toFixed(2));
        c.setAttribute('opacity', an ? Math.sin(Math.PI * p).toFixed(2) : 0);
      });
    } else if (art === 'palme') {
      var neig = an ? 5 + 6 * Math.sin(an / 260) : 0;
      ankunft.setAttribute('transform', tr + ' rotate(' + neig.toFixed(1) + ')');
      wedel.forEach(function (w, i) {
        var fl = an ? 9 * Math.sin(an / 120 + i * 1.3) : 0;
        w.setAttribute('transform', 'translate(0.15 -4) rotate(' + (+w.dataset.w + fl + neig * 0.8).toFixed(1) + ')');
      });
    } else {
      ankunft.setAttribute('transform', tr);
      var winkel = an ? 8 + 7 * Math.sin(an / 220) : 80;
      sack.forEach(function (p, i) {
        var x0 = i * 0.85, x1 = x0 + 0.85, h0 = 0.55 - i * 0.08, h1 = 0.55 - (i + 1) * 0.08;
        var fl = an ? 0.22 * Math.sin(an / 70 + i * 0.9) * (i / 3) : 0;
        p.setAttribute('points', [x0, -h0 + fl * 0.6, x1, -h1 + fl, x1, h1 + fl, x0, h0 + fl * 0.6].map(function (v) { return v.toFixed(2); }).join(' '));
      });
      el('heimSack').setAttribute('transform', 'translate(0 -3.9) rotate(' + winkel.toFixed(1) + ')');
    }
  }
  function zeichne(t) {
    var sal = seg(t, 300, 650);
    var scan = (t > 650 && t < 1500 ? Math.sin((t - 650) / 850 * Math.PI * 2) : 0)
             + (t > 2000 && t < 2800 ? Math.sin((t - 2000) / 800 * Math.PI * 2) : 0);
    var sx = wende(-Math.cos(seg(t, 1500, 2000) * Math.PI));   // erst nach links, dann nach rechts
    if (dir < 0 && t > 2800) { sx = wende(Math.cos(seg(t, 2800, 3200) * Math.PI)); }
    var runter = seg(t, 3200, 3450);
    var hk = (0.35 * sal + 0.15 * scan) * (1 - runter);
    sal *= 1 - runter;
    var zuck = ziel ? 0 : Math.sin(Math.PI * cl((t - 3450) / 700));
    var hoch = ziel ? seg(t, 4000, 4130) * (1 - seg(t, 4750, 4900)) : 0;   // Juchhu: Arme hoch
    var sprung = ziel && t > 4000 && t < 4900 ? Math.abs(Math.sin((t - 4000) / 900 * Math.PI * 2)) : 0;
    var zg = ziel ? seg(t, 3350, 3700) * (1 - hoch) : 0;
    var bl = ziel ? seg(t, 3500, 3800) * (1 - seg(t, 3900, 4100)) : 0;
    var walk = 0, manOp = 1, busOp = 0, fa = 0, rr = 0;
    if (fahrt) {
      if (t < 9400) {
        if (t >= 4000) { zg = 0; }
        walk = seg(t, 5300, 5700);
        manOp = 1 - seg(t, 5500, 5750);
      } else {
        manOp = seg(t, 9600, 10100);
      }
      busOp = seg(t, 4900, 5200) * (1 - seg(t, 9400, 9800));
      fa = seg(t, 5800, 7900);
      rr = cl((t - 7900) / 700);
    }
    var r1 = ziel ? cl((t - 3600) / 700) : 0;
    var ring = r1 > 0 && r1 < 1 ? r1 : rr > 0 && rr < 1 ? rr : 0;
    if (t > 3200 && ziel) { sx = dir; }
    heim.setAttribute('transform', 'translate(' + (X + walk * 2.5 * U * dir) + ' ' + (Y - sprung * U) + ') scale(' + (sx * U) + ' ' + U + ')');
    heim.setAttribute('opacity', manOp);
    kopf.setAttribute('cx', hk.toFixed(3));
    arm(armL, -SX, SY, 12 + 50 * zuck + 138 * hoch, true);
    arm(armR, SX, SY, -12 - 50 * zuck - 138 * hoch, (sal < 0.05 && zg < 0.02) || hoch > 0.05);
    if (juchhu) {
      var jb = t < 4000 ? 0 : t < 4120 ? (t - 4000) / 120 : 1 - seg(t, 4900, 5150), pop = 0.85 + 0.15 * cl((t - 4000) / 150);
      juchhu.setAttribute('transform', 'translate(' + (X + (blaseOben ? 0.5 : 1.2) * U) + ' ' + (Y - (blaseOben ? 4.1 : 3.1) * U) + ') scale(' + (U * pop).toFixed(3) + ')');
      juchhu.setAttribute('opacity', jb.toFixed(2));
    }
    arm(ober, SX, SY, grad(ELLE[0] - SX, ELLE[1] - SY), sal >= 0.05);
    arm(unter, ELLE[0], ELLE[1], grad(hk + 0.42 - ELLE[0], -3.35 - ELLE[1]), sal >= 0.05);
    if (ziel) {
      // Genau zum Ziel zeigen; die Blicklinie macht es eindeutig, auch wenn der Arm steil nach
      // unten zeigt (bis 8.11.2026 abends auf 35 Grad begrenzt, dann zeigte er aufs falsche Ziel)
      var ox = X + SX * U * dir, oy = Y + SY * U, wx = ziel[0] - ox, wy = ziel[1] - oy;
      arm(zeig, SX, SY, grad(Math.abs(wx), wy), zg >= 0.02);
      zeig.setAttribute('height', (ARM * zg).toFixed(3));
      var wl = Math.sqrt(wx * wx + wy * wy) || 1, hx = ox + wx / wl * ARM * U, hy = oy + wy / wl * ARM * U;
      var ex = ziel[0] - wx / wl * RP * 1.3, ey = ziel[1] - wy / wl * RP * 1.3;
      blick.setAttribute('d', 'M' + hx.toFixed(1) + ',' + hy.toFixed(1) + 'L' + (hx + (ex - hx) * bl).toFixed(1) + ',' + (hy + (ey - hy) * bl).toFixed(1));
      blick.setAttribute('opacity', bl > 0.02 ? 1 : 0);
    }
    if (fahrt) {
      var u = 1 - fa, px = u * u * A[0] + 2 * u * fa * C[0] + fa * fa * B[0], py = u * u * A[1] + 2 * u * fa * C[1] + fa * fa * B[1];
      var faehrt = t > 5800 && t < 7900;
      var hop = faehrt && !flieger ? -Math.abs(Math.sin(t / 85)) * 0.25 * U : 0;
      var dreh = '', gr = 1;
      if (flieger && faehrt) {   // Nase entlang der Bahn, am Start und bei der Landung waagrecht
        var tx = 2 * u * (C[0] - A[0]) + 2 * fa * (B[0] - C[0]), ty = 2 * u * (C[1] - A[1]) + 2 * fa * (B[1] - C[1]);
        var w = (dir > 0 ? Math.atan2(ty, tx) : Math.atan2(-ty, -tx)) * 180 / Math.PI;
        dreh = ' rotate(' + (w * Math.min(1, fa * 6, (1 - fa) * 6)).toFixed(1) + ')';
        gr = 1 + 0.35 * Math.sin(Math.PI * fa);
      }
      Array.prototype.forEach.call(fahrwerk, function (e) {   // Fahrwerk in der Luft eingezogen
        e.setAttribute('opacity', faehrt && fa > 0.12 && fa < 0.88 ? 0 : 1);
      });
      bus.setAttribute('transform', 'translate(' + px + ' ' + (py + hop) + ')' + dreh + ' scale(' + (dir * U * gr) + ' ' + (U * gr) + ')');
      bus.setAttribute('opacity', busOp);
      fahrer.setAttribute('opacity', t > 5700 && t < 9400 ? 1 : 0);   // das Maennchen sitzt am Steuer
      spur.setAttribute('opacity', flieger ? 0 : faehrt ? 0.6 : t >= 7900 && t < 8500 ? 0.6 * (1 - seg(t, 7900, 8500)) : 0);
      woelkchen.forEach(function (w) {   // bleiben stehen, steigen ein wenig und werden groesser
        var a = cl((fa - w.f) * 5);
        w.g.setAttribute('opacity', (0.85 * a).toFixed(2));
        w.g.setAttribute('transform', 'translate(' + w.x.toFixed(2) + ' ' + (w.y - 0.5 * U * a).toFixed(2) + ') scale(' + (U * (1.3 + 0.5 * a)).toFixed(3) + ')');
      });
      spur.style.strokeDashoffset = -t / 40;
      weht(t, 1 - seg(t, 9400, 9800));
    }
    if (puls) {
      puls.setAttribute('r', (RP * (1 + 1.8 * ring)).toFixed(2));
      puls.setAttribute('opacity', ring > 0 ? 1 - ring : 0);
    }
    wind(t);
  }
  // Ton: nur nach Antippen und nur, wenn der Bus faehrt
  var TON = 'wohinfahrma_ton', tonAn = true, ctx = null, master = null, knoten = [];
  try { tonAn = localStorage.getItem(TON) !== 'aus'; } catch (e) {}
  var tonBox = el('ton');
  function tonZeigen() {
    [].forEach.call(tonBox.querySelectorAll('button'), function (b) {
      b.setAttribute('aria-pressed', (b.dataset.ton === 'an') === tonAn ? 'true' : 'false');
    });
  }
  if (tonBox && ziel) {
    tonBox.hidden = false;
    tonZeigen();
    tonBox.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b) { return; }
      tonAn = b.dataset.ton === 'an';
      try { localStorage.setItem(TON, tonAn ? 'an' : 'aus'); } catch (e2) {}
      if (!tonAn) { tonStopp(); }
      tonZeigen();
    });
  }
  function hupe(t0) {   // Zweiklang, zweimal kurz
    [[0, 0.16], [0.24, 0.2]].forEach(function (z) {
      var g = ctx.createGain(), f = ctx.createBiquadFilter(), a = t0 + z[0], d = z[1];
      f.type = 'lowpass'; f.frequency.value = 1800; f.connect(g); g.connect(master);
      g.gain.setValueAtTime(0, a); g.gain.linearRampToValueAtTime(0.35, a + 0.015);
      g.gain.setValueAtTime(0.35, a + d - 0.03); g.gain.linearRampToValueAtTime(0, a + d);
      [392, 494].forEach(function (fr) {
        var o = ctx.createOscillator(); o.type = 'square'; o.frequency.value = fr; o.connect(f);
        o.start(a); o.stop(a + d + 0.02); knoten.push(o);
      });
    });
  }
  function pfiff(t0) {   // Jubelpfiff "ju-huu": kurz, dann aufsteigend mit Vibrato
    function ton(a, d, f0, f1, vib) {
      var o = ctx.createOscillator(), g = ctx.createGain(), l = ctx.createOscillator(), lg = ctx.createGain();
      o.type = 'sine';
      o.frequency.setValueAtTime(f0, a); o.frequency.exponentialRampToValueAtTime(f1, a + d * 0.7);
      o.frequency.setValueAtTime(f1, a + d * 0.7); o.frequency.exponentialRampToValueAtTime(f1 * 0.93, a + d);
      l.frequency.value = vib || 1; lg.gain.value = vib ? f1 * 0.025 : 0; l.connect(lg); lg.connect(o.frequency);
      g.gain.setValueAtTime(0, a); g.gain.linearRampToValueAtTime(0.9, a + 0.02);
      g.gain.setValueAtTime(0.9, a + d - 0.06); g.gain.linearRampToValueAtTime(0, a + d);
      o.connect(g); g.connect(master);
      [o, l].forEach(function (n) { n.start(a); n.stop(a + d + 0.02); knoten.push(n); });
    }
    ton(t0, 0.13, 620, 760, 0);
    ton(t0 + 0.19, 0.42, 700, 1180, 7);
  }
  function motor(t0, dauer) {   // tiefes Brummen mit Tuckern, schwillt an und rollt aus
    var o = ctx.createOscillator(), o2 = ctx.createOscillator(), lfo = ctx.createOscillator();
    var f = ctx.createBiquadFilter(), g = ctx.createGain(), tuck = ctx.createGain(), lfoG = ctx.createGain();
    o.type = 'sawtooth'; o2.type = 'square'; lfo.type = 'sine'; f.type = 'lowpass'; f.Q.value = 3;
    o.connect(f); o2.connect(f); f.connect(tuck); tuck.connect(g); g.connect(master);
    lfo.connect(lfoG); lfoG.gain.value = 0.45; lfoG.connect(tuck.gain); tuck.gain.value = 0.6;
    function rampe(p, v0, v1, v2) {
      p.setValueAtTime(v0, t0); p.linearRampToValueAtTime(v1, t0 + 0.7);
      p.setValueAtTime(v1, t0 + dauer - 0.7); p.linearRampToValueAtTime(v2, t0 + dauer);
    }
    rampe(o.frequency, 38, 62, 34); rampe(o2.frequency, 19, 31, 17);
    rampe(lfo.frequency, 9, 15, 7); rampe(f.frequency, 260, 500, 200);
    g.gain.setValueAtTime(0, t0); g.gain.linearRampToValueAtTime(0.5, t0 + 0.25);
    g.gain.setValueAtTime(0.5, t0 + dauer - 0.6); g.gain.linearRampToValueAtTime(0, t0 + dauer + 0.15);
    [o, o2, lfo].forEach(function (n) { n.start(t0); n.stop(t0 + dauer + 0.25); knoten.push(n); });
  }
  function duese(t0, dauer) {   // Duesenrauschen: gefiltertes Rauschen, schwillt beim Start an
    var n = Math.floor(ctx.sampleRate * (dauer + 0.5)), buf = ctx.createBuffer(1, n, ctx.sampleRate), d = buf.getChannelData(0);
    for (var i = 0; i < n; i++) { d[i] = Math.random() * 2 - 1; }
    var q = ctx.createBufferSource(), f = ctx.createBiquadFilter(), g = ctx.createGain();
    q.buffer = buf; f.type = 'bandpass'; f.Q.value = 0.8;
    q.connect(f); f.connect(g); g.connect(master);
    f.frequency.setValueAtTime(350, t0); f.frequency.exponentialRampToValueAtTime(1400, t0 + dauer * 0.35);
    f.frequency.exponentialRampToValueAtTime(500, t0 + dauer);
    g.gain.setValueAtTime(0, t0); g.gain.linearRampToValueAtTime(0.9, t0 + dauer * 0.3);
    g.gain.setValueAtTime(0.9, t0 + dauer * 0.55); g.gain.linearRampToValueAtTime(0, t0 + dauer + 0.3);
    q.start(t0); q.stop(t0 + dauer + 0.4); knoten.push(q);
  }
  function tonStart() {   // im Antippen aufrufen, sonst erlaubt der Browser keinen Ton
    if (!tonAn || !ziel) { return; }
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) { return; }
    try {
      if (!ctx) { ctx = new AC(); }
      if (ctx.state === 'suspended') { ctx.resume(); }
      master = ctx.createGain(); master.gain.value = 0.2; master.connect(ctx.destination);
      var t0 = ctx.currentTime;
      pfiff(t0 + 4.0);            // Juchhu
      if (fahrt && flieger) {
        duese(t0 + 5.6, 2.4);     // Flug 5800 bis 7900 ms
      } else if (fahrt) {
        hupe(t0 + 5.35);          // beim Einsteigen
        motor(t0 + 5.75, 2.25);   // Fahrt 5800 bis 7900 ms
      }
    } catch (e) { master = null; }
  }
  function tonStopp() {
    if (!master) { return; }
    try {
      master.gain.setTargetAtTime(0, ctx.currentTime, 0.02);
      knoten.forEach(function (n) { try { n.stop(ctx.currentTime + 0.1); } catch (e) {} });
    } catch (e) {}
    knoten = []; master = null;
  }
  var t0 = null, raf = null, laeuft = false, gestoppt = 0, gestartet = 0;
  function bild(ts) {
    if (t0 === null) { t0 = ts; }
    var t = ts - t0;
    zeichne(Math.min(t, ENDE));
    if (t < ENDE) { raf = requestAnimationFrame(bild); } else { laeuft = false; }
  }
  function stopp() {
    if (!laeuft) { return; }
    cancelAnimationFrame(raf);
    laeuft = false;
    gestoppt = Date.now();
    tonStopp();
    zeichne(ENDE);
  }
  var still = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  function start(mitTon) {
    cancelAnimationFrame(raf);
    tonStopp();
    if (still) { zeichne(ENDE); return; }
    if (mitTon) { tonStart(); }
    t0 = null;
    laeuft = true;
    gestartet = Date.now();
    raf = requestAnimationFrame(bild);
  }
  // Scrollen beendet; der Schwung des Scrollens, der die Karte ins Bild gebracht hat, nicht
  addEventListener('scroll', function () { if (Date.now() - gestartet > 400) { stopp(); } }, { passive: true });
  addEventListener('keydown', stopp);
  svg.addEventListener('pointerdown', stopp);
  heim.classList.add('tippbar');
  heim.addEventListener('click', function (e) {
    e.preventDefault();
    if (!laeuft && Date.now() - gestoppt > 400) { start(true); }
  });
  // Einmal je Seitenaufruf, sobald die Karte zu gut der Haelfte sichtbar ist (auch nach dem Aufklappen)
  if (!('IntersectionObserver' in window)) { start(); return; }
  var io = new IntersectionObserver(function (eintraege) {
    if (eintraege.some(function (e) { return e.isIntersecting; })) { io.disconnect(); start(); }
  }, { threshold: 0.55 });
  io.observe(svg);
})();
