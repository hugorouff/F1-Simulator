"""Exporte le tracé réel d'un circuit (via FastF1) en JSON.

Usage : python export_circuit.py <année> <grand_prix> [fichier_sortie.json]

Le tracé vient du tour le plus rapide des qualifications (même méthode que
f1-race-replay). Sans fichier de sortie, le JSON est écrit sur stdout.
"""
import json
import logging
import os
import sys

import numpy as np

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8")

logging.getLogger("fastf1").setLevel(logging.ERROR)

N_POINTS = 700  # points du tracé après rééchantillonnage


def resample(x, y, distance, n=N_POINTS):
    """Rééchantillonne le tracé à intervalles de distance réguliers."""
    d = np.asarray(distance, dtype=float)
    keep = np.concatenate(([True], np.diff(d) > 0))  # distances strictement croissantes
    x, y, d = np.asarray(x)[keep], np.asarray(y)[keep], d[keep]
    grid = np.linspace(d[0], d[-1], n, endpoint=False)
    return np.interp(grid, d, x), np.interp(grid, d, y), grid - d[0], float(d[-1] - d[0])


def drs_zones(drs, n_src, n_dst=N_POINTS):
    """Convertit la colonne DRS (>=10 = ouvert) en zones [début, fin] d'indices."""
    flags = np.asarray(drs) >= 10
    idx = np.interp(np.linspace(0, n_src - 1, n_dst), np.arange(n_src), flags.astype(float)) > 0.5
    zones, start = [], None
    for i, on in enumerate(idx):
        if on and start is None:
            start = i
        elif not on and start is not None:
            zones.append([start, i - 1])
            start = None
    if start is not None:
        zones.append([start, n_dst - 1])
    return zones


def export(year, gp):
    import fastf1

    cache_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "var", "cache", "fastf1")
    os.makedirs(cache_dir, exist_ok=True)
    fastf1.Cache.enable_cache(cache_dir)

    # Qualifications d'abord (meilleur tracé), sinon la course
    session, lap = None, None
    for code in ("Q", "R"):
        try:
            s = fastf1.get_session(int(year), gp, code)
            s.load(telemetry=True, weather=False, messages=False)
            lap = s.laps.pick_fastest()
            if lap is not None:
                session = s
                break
        except Exception:
            continue
    if session is None or lap is None:
        raise RuntimeError("Aucun tour valide trouvé pour ce Grand Prix.")

    tel = lap.get_telemetry()
    x, y, s_grid, length_units = resample(tel["X"], tel["Y"], tel["Distance"])
    ref_lap = lap["LapTime"].total_seconds()

    # Numéros de virages (facultatif : peut échouer hors ligne)
    corners = []
    try:
        info = session.get_circuit_info()
        track_xy = np.column_stack([x, y])
        for _, c in info.corners.iterrows():
            i = int(np.argmin(np.hypot(track_xy[:, 0] - c["X"], track_xy[:, 1] - c["Y"])))
            corners.append({"n": f'{int(c["Number"])}{c["Letter"] or ""}', "i": i})
    except Exception:
        pass

    return {
        "name": str(session.event["EventName"]),
        "location": str(session.event.get("Location", "")),
        "country": str(session.event.get("Country", "")),
        "year": int(year),
        "length_m": round(length_units, 1),  # Distance FastF1 en mètres
        "ref_lap_s": round(ref_lap, 3),
        "x": [round(float(v), 1) for v in x],
        "y": [round(float(v), 1) for v in y],
        "s": [round(float(v), 1) for v in s_grid],
        "drs_zones": drs_zones(tel["DRS"], len(tel)) if "DRS" in tel else [],
        "corners": corners,
    }


if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage : export_circuit.py <année> <grand_prix> [sortie.json]", file=sys.stderr)
        sys.exit(2)
    try:
        data = export(sys.argv[1], sys.argv[2])
        payload = json.dumps(data, ensure_ascii=False)
        if len(sys.argv) > 3:
            with open(sys.argv[3], "w", encoding="utf-8") as f:
                f.write(payload)
            print(json.dumps({"status": "success", "file": sys.argv[3]}))
        else:
            print(payload)
    except Exception as e:
        print(json.dumps({"status": "error", "message": str(e)}))
        sys.exit(1)
