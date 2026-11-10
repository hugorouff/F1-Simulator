#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
build_from_svg.py
Convertit les SVG de RaceTracksAPI en circuits JSON pour le simulateur
(format x / y / s en metres, identique a convertGeoJsonToEngineFormat).

Usage (a la racine du projet) :
    python scripts/build_from_svg.py
    python scripts/build_from_svg.py --only monza --debug
"""
import argparse
import glob
import json
import math
import os
import re
import sys
import unicodedata
import xml.etree.ElementTree as ET

N_POINTS = 700
CURVE_STEPS = 24
DUP_RADIUS_KM = 3.0
BACINGER_RE = re.compile(r'^[a-z]{2}-\d{4}$')
NUM_RE = re.compile(r'[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?')


# --------------------------------------------------------------------------
# Parseur de chemins SVG (M L H V C S Q T A Z, absolus et relatifs)
# --------------------------------------------------------------------------
class PathScanner:
    def __init__(self, text):
        self.s, self.i, self.n = text, 0, len(text)

    def skip(self):
        while self.i < self.n and self.s[self.i] in ' ,\t\r\n':
            self.i += 1

    def at_end(self):
        self.skip()
        return self.i >= self.n

    def peek(self):
        self.skip()
        return self.s[self.i] if self.i < self.n else ''

    def number(self):
        self.skip()
        m = NUM_RE.match(self.s, self.i)
        if not m:
            raise ValueError("nombre attendu en position %d" % self.i)
        self.i = m.end()
        return float(m.group())

    def flag(self):
        self.skip()
        c = self.s[self.i] if self.i < self.n else ''
        if c not in ('0', '1'):
            raise ValueError("flag d'arc attendu en position %d" % self.i)
        self.i += 1
        return int(c)


def cubic(p0, p1, p2, p3, steps=CURVE_STEPS):
    out = []
    for k in range(1, steps + 1):
        t = k / steps
        u = 1 - t
        out.append((
            u**3 * p0[0] + 3 * u * u * t * p1[0] + 3 * u * t * t * p2[0] + t**3 * p3[0],
            u**3 * p0[1] + 3 * u * u * t * p1[1] + 3 * u * t * t * p2[1] + t**3 * p3[1],
        ))
    return out


def quad(p0, p1, p2, steps=CURVE_STEPS):
    out = []
    for k in range(1, steps + 1):
        t = k / steps
        u = 1 - t
        out.append((
            u * u * p0[0] + 2 * u * t * p1[0] + t * t * p2[0],
            u * u * p0[1] + 2 * u * t * p1[1] + t * t * p2[1],
        ))
    return out


def arc(x1, y1, rx, ry, rot, fa, fs, x2, y2, steps=CURVE_STEPS):
    if rx == 0 or ry == 0 or (x1 == x2 and y1 == y2):
        return [(x2, y2)]
    rx, ry = abs(rx), abs(ry)
    phi = math.radians(rot)
    cp, sp = math.cos(phi), math.sin(phi)
    dx, dy = (x1 - x2) / 2, (y1 - y2) / 2
    x1p, y1p = cp * dx + sp * dy, -sp * dx + cp * dy
    lam = x1p**2 / rx**2 + y1p**2 / ry**2
    if lam > 1:
        f = math.sqrt(lam)
        rx, ry = rx * f, ry * f
    den = rx**2 * y1p**2 + ry**2 * x1p**2
    num = rx**2 * ry**2 - rx**2 * y1p**2 - ry**2 * x1p**2
    co = math.sqrt(max(0.0, num / den)) if den else 0.0
    if fa == fs:
        co = -co
    cxp, cyp = co * rx * y1p / ry, -co * ry * x1p / rx
    cx = cp * cxp - sp * cyp + (x1 + x2) / 2
    cy = sp * cxp + cp * cyp + (y1 + y2) / 2
    ux, uy = (x1p - cxp) / rx, (y1p - cyp) / ry
    vx, vy = (-x1p - cxp) / rx, (-y1p - cyp) / ry
    th1 = math.atan2(uy, ux)
    dth = math.atan2(ux * vy - uy * vx, ux * vx + uy * vy)
    if not fs and dth > 0:
        dth -= 2 * math.pi
    elif fs and dth < 0:
        dth += 2 * math.pi
    out = []
    for k in range(1, steps + 1):
        t = th1 + dth * k / steps
        ex, ey = rx * math.cos(t), ry * math.sin(t)
        out.append((cp * ex - sp * ey + cx, sp * ex + cp * ey + cy))
    out[-1] = (x2, y2)
    return out


def parse_path(d):
    """Retourne une liste de sous-chemins, chacun = liste de points (x, y)."""
    sc = PathScanner(d)
    subs, cur = [], []
    x = y = sx = sy = 0.0
    cmd = None
    c_ctrl = q_ctrl = None

    def flush():
        if len(cur) > 1:
            subs.append(list(cur))

    while not sc.at_end():
        ch = sc.peek()
        if ch.isalpha():
            sc.i += 1
            cmd = ch
            if cmd in 'Zz':
                if cur:
                    cur.append((sx, sy))
                flush()
                cur = [(sx, sy)]
                x, y = sx, sy
                c_ctrl = q_ctrl = None
                continue
        elif cmd is None or cmd in 'Zz':
            raise ValueError("donnees inattendues en position %d" % sc.i)

        rel = cmd.islower()
        C = cmd.upper()
        ox, oy = (x, y) if rel else (0.0, 0.0)

        if C == 'M':
            nx, ny = sc.number() + ox, sc.number() + oy
            flush()
            cur = [(nx, ny)]
            x, y, sx, sy = nx, ny, nx, ny
            cmd = 'l' if rel else 'L'      # paires suivantes = lineto implicites
            c_ctrl = q_ctrl = None
        elif C == 'L':
            x, y = sc.number() + ox, sc.number() + oy
            cur.append((x, y))
            c_ctrl = q_ctrl = None
        elif C == 'H':
            x = sc.number() + ox
            cur.append((x, y))
            c_ctrl = q_ctrl = None
        elif C == 'V':
            y = sc.number() + oy
            cur.append((x, y))
            c_ctrl = q_ctrl = None
        elif C == 'C':
            p1 = (sc.number() + ox, sc.number() + oy)
            p2 = (sc.number() + ox, sc.number() + oy)
            p3 = (sc.number() + ox, sc.number() + oy)
            cur.extend(cubic((x, y), p1, p2, p3))
            c_ctrl, q_ctrl = p2, None
            x, y = p3
        elif C == 'S':
            p1 = (2 * x - c_ctrl[0], 2 * y - c_ctrl[1]) if c_ctrl else (x, y)
            p2 = (sc.number() + ox, sc.number() + oy)
            p3 = (sc.number() + ox, sc.number() + oy)
            cur.extend(cubic((x, y), p1, p2, p3))
            c_ctrl, q_ctrl = p2, None
            x, y = p3
        elif C == 'Q':
            p1 = (sc.number() + ox, sc.number() + oy)
            p2 = (sc.number() + ox, sc.number() + oy)
            cur.extend(quad((x, y), p1, p2))
            q_ctrl, c_ctrl = p1, None
            x, y = p2
        elif C == 'T':
            p1 = (2 * x - q_ctrl[0], 2 * y - q_ctrl[1]) if q_ctrl else (x, y)
            p2 = (sc.number() + ox, sc.number() + oy)
            cur.extend(quad((x, y), p1, p2))
            q_ctrl, c_ctrl = p1, None
            x, y = p2
        elif C == 'A':
            rx, ry, rot = sc.number(), sc.number(), sc.number()
            fa, fs = sc.flag(), sc.flag()
            nx, ny = sc.number() + ox, sc.number() + oy
            cur.extend(arc(x, y, rx, ry, rot, fa, fs, nx, ny))
            x, y = nx, ny
            c_ctrl = q_ctrl = None
        else:
            raise ValueError("commande SVG inconnue : %r" % cmd)

    flush()
    return subs


# --------------------------------------------------------------------------
# Lecture du SVG : on collecte tous les tracés candidats
# --------------------------------------------------------------------------
def polyline_length(pts):
    return sum(math.dist(a, b) for a, b in zip(pts, pts[1:]))


def svg_candidates(svg_file):
    root = ET.parse(svg_file).getroot()
    cands, errors = [], []
    for el in root.iter():
        tag = el.tag.rsplit('}', 1)[-1].lower()
        subs = []
        try:
            if tag == 'path' and el.get('d'):
                subs = parse_path(el.get('d'))
            elif tag in ('polyline', 'polygon') and el.get('points'):
                nums = [float(v) for v in NUM_RE.findall(el.get('points'))]
                pts = list(zip(nums[0::2], nums[1::2]))
                if tag == 'polygon' and pts:
                    pts.append(pts[0])
                subs = [pts] if len(pts) > 1 else []
            elif tag == 'line':
                subs = [[(float(el.get('x1', 0)), float(el.get('y1', 0))),
                         (float(el.get('x2', 0)), float(el.get('y2', 0)))]]
        except ValueError as e:
            errors.append("<%s id=%r> : %s" % (tag, el.get('id'), e))
            continue
        for pts in subs:
            length = polyline_length(pts)
            if length > 0:
                cands.append({'tag': tag, 'id': el.get('id') or el.get('class') or '',
                              'pts': pts, 'len': length})
    return cands, errors


# --------------------------------------------------------------------------
# Points -> circuit au format simulateur
# --------------------------------------------------------------------------
def resample_closed(pts, n):
    cum = [0.0]
    for a, b in zip(pts, pts[1:]):
        cum.append(cum[-1] + math.dist(a, b))
    total = cum[-1]
    out, j = [], 0
    for k in range(n):
        target = total * k / n
        while j < len(cum) - 2 and cum[j + 1] < target:
            j += 1
        seg = cum[j + 1] - cum[j]
        t = (target - cum[j]) / seg if seg > 0 else 0.0
        out.append((pts[j][0] + t * (pts[j + 1][0] - pts[j][0]),
                    pts[j][1] + t * (pts[j + 1][1] - pts[j][1])))
    return out


def build_xys(points, length_m):
    pts = [points[0]]
    for p in points[1:]:
        if math.dist(p, pts[-1]) > 1e-9:
            pts.append(p)
    if len(pts) < 3:
        raise ValueError("moins de 3 points exploitables")

    warn = None
    gap = math.dist(pts[-1], pts[0])
    if gap > 1e-9:
        total = polyline_length(pts)
        if gap > 0.15 * total:
            warn = "trace non ferme (ecart %.0f%% de la longueur)" % (100 * gap / total)
        pts.append(pts[0])

    res = resample_closed(pts, N_POINTS)
    x0, y0 = res[0]
    xs = [p[0] - x0 for p in res]
    ys = [-(p[1] - y0) for p in res]            # axe y du SVG vers le bas -> on inverse
    n = len(xs)
    chords = [math.hypot(xs[(i + 1) % n] - xs[i], ys[(i + 1) % n] - ys[i]) for i in range(n)]
    k = length_m / sum(chords)
    xs, ys, chords = [v * k for v in xs], [v * k for v in ys], [c * k for c in chords]
    s, acc = [], 0.0
    for c in chords:
        s.append(acc)
        acc += c
    r = lambda arr: [round(v, 1) for v in arr]
    return r(xs), r(ys), r(s), warn


# --------------------------------------------------------------------------
# Utilitaires
# --------------------------------------------------------------------------
def slugify(name):
    ascii_ = unicodedata.normalize('NFKD', name).encode('ascii', 'ignore').decode()
    return re.sub(r'[^a-z0-9]+', '-', ascii_.lower()).strip('-')[:50].strip('-')


def haversine_km(lat1, lon1, lat2, lon2):
    p1, p2 = math.radians(lat1), math.radians(lat2)
    a = math.sin((p2 - p1) / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(math.radians(lon2 - lon1) / 2) ** 2
    return 6371 * 2 * math.asin(math.sqrt(a))


def bacinger_centers(out_dir):
    res = []
    for f in glob.glob(os.path.join(out_dir, '*.geojson')):
        base = os.path.splitext(os.path.basename(f))[0]
        if not BACINGER_RE.match(base):
            continue
        try:
            with open(f, encoding='utf-8') as fh:
                coords = json.load(fh)['features'][0]['geometry']['coordinates']
            lons, lats = [c[0] for c in coords], [c[1] for c in coords]
            res.append((base, (min(lats) + max(lats)) / 2, (min(lons) + max(lons)) / 2))
        except Exception:
            continue
    return res


def pick_layout(track):
    layouts = [l for l in (track.get('layouts') or []) if l.get('image_id') and l.get('length')]
    current = [l for l in layouts if l.get('end_year') is None]
    pool = current or layouts
    return pool[0] if pool else None


def main():
    ap = argparse.ArgumentParser(description="SVG RaceTracksAPI -> circuits du simulateur")
    ap.add_argument('--repo', default='tmp_racetracks', help="dossier clone de RaceTracksAPI")
    ap.add_argument('--out', default='public/circuits', help="dossier de sortie")
    ap.add_argument('--only', help="ne traiter que les circuits dont le nom contient ce texte")
    ap.add_argument('--keep-duplicates', action='store_true',
                    help="ne pas sauter les circuits deja fournis par Bacinger")
    ap.add_argument('--avg-speed', type=float, default=58.0,
                    help="vitesse moyenne (m/s) pour estimer ref_lap_s = longueur / vitesse (defaut 58, comme ton ReplayController)")
    ap.add_argument('--debug', action='store_true', help="detail des traces candidats par SVG")
    args = ap.parse_args()

    tracks_file = os.path.join(args.repo, 'tracks.json')
    svg_dir = os.path.join(args.repo, 'images')
    if not os.path.isfile(tracks_file):
        sys.exit("tracks.json introuvable dans %s (git clone d'abord)" % args.repo)
    os.makedirs(args.out, exist_ok=True)

    with open(tracks_file, encoding='utf-8') as fh:
        tracks = json.load(fh)
    bac = [] if args.keep_duplicates else bacinger_centers(args.out)

    ok, skipped, failed, used = 0, [], [], set()
    for track in tracks:
        name = track.get('name') or ''
        if not name or track.get('isFictional'):
            continue
        if args.only and args.only.lower() not in name.lower():
            continue

        layout = pick_layout(track)
        if layout is None:
            failed.append((name, "aucun layout avec image_id et longueur"))
            continue

        try:
            lat, lon = float(track['latitude']), float(track['longitude'])
        except (TypeError, ValueError, KeyError):
            lat = lon = None
        if lat is not None:
            dup = next((b for b, blat, blon in bac if haversine_km(lat, lon, blat, blon) < DUP_RADIUS_KM), None)
            if dup:
                skipped.append((name, dup))
                continue

        svg_file = os.path.join(svg_dir, 'track_%s.svg' % layout['image_id'])
        if not os.path.isfile(svg_file):
            failed.append((name, "SVG introuvable : %s" % svg_file))
            continue

        try:
            cands, errors = svg_candidates(svg_file)
        except ET.ParseError as e:
            failed.append((name, "SVG illisible : %s" % e))
            continue

        if args.debug:
            print("\n== %s  (%s)" % (name, os.path.basename(svg_file)))
            for c in sorted(cands, key=lambda c: -c['len'])[:6]:
                print("   %-9s %-18s %5d pts  longueur svg %.1f" % (c['tag'], c['id'][:18], len(c['pts']), c['len']))
            for e in errors:
                print("   ERREUR", e)

        if not cands:
            failed.append((name, "aucun trace dans le SVG" + (" (%s)" % errors[0] if errors else "")))
            continue

        best = max(cands, key=lambda c: c['len'])
        length_m = float(layout['length']) * 1000
        try:
            xs, ys, s, warn = build_xys(best['pts'], length_m)
        except ValueError as e:
            failed.append((name, str(e)))
            continue

        slug = slugify(name) or 'circuit'
        base, i = slug, 2
        while slug in used:
            slug = "%s-%d" % (base[:46], i)
            i += 1
        used.add(slug)

        data = {
            "name": name,
            "location": track.get('region') or track.get('country') or name,
            "country": track.get('country') or 'XX',
            "year": int(layout.get('start_year') or 0),
            "length_m": length_m,
            "ref_lap_s": round(length_m / args.avg_speed, 1),   # temps au tour de reference (estime)
            "x": xs, "y": ys, "s": s,
            "drs_zones": [],
            "corners": [],
        }
        with open(os.path.join(args.out, slug + '.json'), 'w', encoding='utf-8') as fh:
            json.dump(data, fh, ensure_ascii=False)
        ok += 1
        if warn:
            print("[!] %s : %s" % (name, warn))

    print("\n%d circuits ecrits dans %s" % (ok, args.out))
    if skipped:
        print("%d ignores (deja couverts par Bacinger, utilise --keep-duplicates pour les forcer)" % len(skipped))
    if failed:
        print("%d en echec :" % len(failed))
        for n, why in failed:
            print("   - %s : %s" % (n, why))


if __name__ == '__main__':
    main()