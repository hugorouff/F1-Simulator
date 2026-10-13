import gc
import json
import math
import sys

import numpy as np

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8")


def demo(n=700):
    t = np.linspace(0, 2 * math.pi, n, endpoint=False)
    r = 3000 + 900 * np.cos(2 * t) + 500 * np.sin(3 * t + 1)
    x, y = r * np.cos(t) * 1.4, r * np.sin(t)
    seg = np.hypot(np.diff(np.append(x, x[0])), np.diff(np.append(y, y[0])))
    s = np.concatenate(([0], np.cumsum(seg)[:-1]))
    length_m = float(seg.sum()) / 10
    
    data = {
        "name": "Circuit de démonstration", "location": "Demo", "country": "-",
        "length_m": round(length_m, 1), "ref_lap_s": 88.0,
        "x": [round(float(v), 1) for v in x], "y": [round(float(v), 1) for v in y],
        "s": [round(float(v) / 10, 1) for v in s],
        "drs_zones": [[40, 110], [400, 470]], "corners": [],
    }
    gc.collect()
    return data


if __name__ == "__main__":
    print(json.dumps(demo(), ensure_ascii=False))
