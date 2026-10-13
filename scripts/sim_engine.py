"""Moteur de simulation de course F1."""
import argparse
import gc
import json
import math
import random
import sys

import numpy as np

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8")

POINTS = [25, 18, 15, 12, 10, 8, 6, 4, 2, 1]
FRAME_DT = 1.0           # secondes entre deux images
PIT_LOSS = 21.0          # secondes perdues par arrêt aux stands
GRID_GAP_M = 8.0         # distance entre deux places sur la grille
SC_LAP_FACTOR = 1.45     # tour sous Safety Car = 145 % d'un tour normal
SC_GAP_S = 1.0           # écart visé entre voitures derrière la Safety Car

DEFAULT_CONFIG = {
    "weather": "dry",          # dry | rain | random
    "chaos": 1.0,              # multiplicateur de tous les incidents (0 = course propre)
    "safety_car": True,        # autorise les Safety Cars
    "team_strength": {},       # {"Mercedes": 0.97} écrase les valeurs par défaut
    "driver_modifiers": {},    # {"HAM": {"pace": -0.3, "reliability": 1.5}}
    "forced_events": [],       # [{"lap": 10, "type": "safety_car"}, {"lap": 5, "driver": "HAM", "type": "puncture"}]
}

TEAM_STRENGTH = {
    "red bull": 1.00, "mercedes": 0.97, "ferrari": 0.96, "mclaren": 0.96,
    "aston": 0.93, "alpine": 0.90, "williams": 0.88, "haas": 0.87,
    "alpha": 0.87, "sauber": 0.86, "alfa": 0.86, "racing point": 0.92,
    "renault": 0.90, "toro": 0.87, "rb": 0.89, "kick": 0.86,
}

TEAM_COLORS = {
    "red bull": "#3671C6", "mercedes": "#27F4D2", "ferrari": "#E8002D", "mclaren": "#FF8000",
    "aston": "#229971", "alpine": "#FF87BC", "williams": "#64C4FF", "haas": "#B6BABD",
    "alpha": "#6692FF", "sauber": "#52E252", "alfa": "#C92D4B", "racing point": "#F596C8",
    "renault": "#FFF500", "toro": "#6692FF", "rb": "#6692FF", "kick": "#52E252",
}

TYRES = {
    "S": {"offset": -0.55, "deg": 0.110, "life": 16},
    "M": {"offset": 0.0, "deg": 0.065, "life": 28},
    "H": {"offset": 0.50, "deg": 0.040, "life": 42},
    "W": {"offset": 4.0, "deg": 0.030, "life": 40},
}


def lookup(table, name, default):
    n = (name or "").lower()
    for key, val in table.items():
        if key in n:
            return val
    return default


def driver_code(name, used):
    parts = name.replace("-", " ").split()
    base = (parts[-1][:3] if parts else "DRV").upper()
    code, k = base, 1
    while code in used:
        code = (base[:2] + str(k))[:3]
        k += 1
    used.add(code)
    return code


def load_drivers(path):
    if path.lower().endswith(".xlsx"):
        import openpyxl
        ws = openpyxl.load_workbook(path, read_only=True).active
        rows = []
        for r in ws.iter_rows(values_only=True):
            if not r or not r[0]:
                continue
            i = lambda v: int(float(v or 0))
            rows.append({"name": str(r[0]), "season_wins": i(r[1]), "age": i(r[2]), "points": i(r[3]),
                         "car_wear": i(r[4]), "career_wins": i(r[5]), "career_races": i(r[6]),
                         "dnf": i(r[7]), "nationality": r[8], "career_podiums": i(r[9]),
                         "team": str(r[10] or ""), "championships": i(r[11])})
        return rows
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def skill_rating(d):
    races = max(d.get("career_races", 0), 1)
    win_rate = d.get("career_wins", 0) / races
    podium_rate = d.get("career_podiums", 0) / races
    experience = min(races / 250.0, 1.0)
    age = d.get("age", 28)
    age_factor = -0.012 * max(age - 36, 0) - 0.01 * max(22 - age, 0)
    r = (0.50 + 0.30 * min(win_rate / 0.30, 1.0) + 0.08 * min(podium_rate / 0.5, 1.0)
         + 0.05 * experience + 0.02 * min(d.get("championships", 0), 7) + age_factor)
    return max(0.3, min(r, 1.0))


class Race:
    def __init__(self, drivers, circuit, laps, seed, config):
        self.rng = random.Random(seed)
        self.circuit = circuit
        self.laps = laps
        self.cfg = {**DEFAULT_CONFIG, **(config or {})}
        for key in ("team_strength", "driver_modifiers"):
            if not isinstance(self.cfg.get(key), dict):
                self.cfg[key] = {}
        self.chaos = float(self.cfg["chaos"])
        self.events = []
        self.drivers = []
        used = set()
        for idx, d in enumerate(drivers):
            code = d.get("code") or driver_code(d["name"], used)
            used.add(code)
            team = d.get("team", "")
            strength = self.cfg["team_strength"].get(team) or lookup(TEAM_STRENGTH, team, 0.88)
            mods = self.cfg["driver_modifiers"].get(code, {})
            self.drivers.append({
                "src": d, "code": code, "name": d["name"], "team": team,
                "color": d.get("color") or lookup(TEAM_COLORS, team, "#AAAAAA"),
                "skill": skill_rating(d), "car": strength,
                "pace_mod": float(mods.get("pace", 0.0)),
                "reliab": float(mods.get("reliability", 1.0)),
                "wear": float(d.get("car_wear", 0)),
                "cum": [], "tyre": "M", "tyre_age": 0, "stops": [], "stint_log": [],
                "alive": True, "retire_lap": None, "retire_t": None, "retire_reason": None,
                "penalty_s": 0.0, "best_lap": None, "damage": 0.0, "stop_plan": [],
            })
        self.weather = self._pick_weather()
        self.rain_laps = self._rain_window()

    def _pick_weather(self):
        w = self.cfg["weather"]
        if w == "random":
            return self.rng.choice(["dry", "dry", "dry", "rain"])
        return w

    def _rain_window(self):
        if self.weather != "rain":
            return set()
        start = self.rng.randint(1, max(self.laps // 2, 1))
        length = self.rng.randint(max(self.laps // 4, 2), max(self.laps // 2, 3))
        return set(range(start, min(start + length, self.laps + 1)))

    def log(self, lap, t, kind, text, code=None):
        self.events.append({"lap": lap, "t": round(t, 1), "type": kind, "driver": code, "text": text})

    def base_lap(self, d, lap, raining):
        ref = self.circuit["ref_lap_s"]
        pace = ref * (1 + (1 - d["car"]) * 0.045 + (1 - d["skill"]) * 0.020)
        pace += d["pace_mod"]
        tyre = TYRES[d["tyre"]]
        pace += tyre["offset"] + tyre["deg"] * d["tyre_age"]
        if d["tyre_age"] > tyre["life"]:
            pace += 0.25 * (d["tyre_age"] - tyre["life"])
        pace -= 0.03 * lap
        pace += (d["wear"] / 100.0) * 1.4
        pace += d["damage"]
        if raining and d["tyre"] != "W":
            pace += 7.0 * (1.2 - d["skill"])
        elif not raining and d["tyre"] == "W":
            pace += 6.0
        noise = self.rng.gauss(0, 0.20 + (0.35 if raining else 0.0) * (1.1 - d["skill"]))
        return pace + noise

    def plan_stops(self, d):
        n = 1 if self.laps <= 30 else 2
        d["tyre"] = self.rng.choice(["S", "M", "M", "H"])
        d["stop_plan"] = sorted(
            max(2, int(self.laps * (i + 1) / (n + 1) + self.rng.randint(-2, 2))) for i in range(n)
        ) if self.laps >= 6 else []

    def run(self):
        order = sorted(self.drivers, key=lambda d: -(d["skill"] * 0.4 + d["car"] * 0.6) + self.rng.gauss(0, 0.04))
        for pos, d in enumerate(order):
            d["grid"] = pos + 1
            self.plan_stops(d)
            d["cum"] = [0.0]
            d["start_offset"] = (pos + 1) * 0.28
        forced = {}
        for e in self.cfg["forced_events"]:
            forced.setdefault(int(e["lap"]), []).append(e)

        sc_left = 0
        if self.weather == "rain":
            self.log(1, 0, "weather", "Pluie annoncée : la piste va se mouiller.")

        for lap in range(1, self.laps + 1):
            raining = lap in self.rain_laps
            if lap in self.rain_laps and (lap - 1) not in self.rain_laps and lap > 1:
                t = max((d["cum"][-1] for d in self.drivers if d["alive"]), default=0)
                self.log(lap, t, "weather", "La pluie commence !")
            if (lap - 1) in self.rain_laps and not raining:
                t = max((d["cum"][-1] for d in self.drivers if d["alive"]), default=0)
                self.log(lap, t, "weather", "La pluie s'arrête, la piste sèche.")

            alive = sorted([d for d in self.drivers if d["alive"]], key=lambda d: d["cum"][-1])
            if not alive:
                break
            leader_t = alive[0]["cum"][-1]

            trigger_sc = sc_left == 0 and self.cfg["safety_car"] and lap > 1 and (
                self.rng.random() < 0.025 * self.chaos
                or any(e["type"] == "safety_car" for e in forced.get(lap, [])))
            if trigger_sc:
                sc_left = self.rng.randint(2, 4)
                self.log(lap, leader_t, "safety_car", f"SAFETY CAR déployée au tour {lap}.")
            sc_active = sc_left > 0

            lap_times = {}
            for d in alive:
                lt = self.base_lap(d, lap, raining)
                if lap == 1:
                    lt += d["start_offset"] + 2.0
                extra, notes = self._incidents(d, lap, forced, raining, alive, leader_t)
                lt += extra
                if lap in d["stop_plan"] or "pit" in notes or self._wants_wets(d, raining):
                    new = "W" if raining else self.rng.choice(["M", "H"] if lap < self.laps * 0.6 else ["S", "M"])
                    cost = PIT_LOSS * (0.55 if sc_active else 1.0)
                    lt += cost
                    d["stops"].append(lap)
                    self.log(lap, d["cum"][-1], "pit", f"{d['code']} change pour des pneus {new}.", d["code"])
                    d["tyre"], d["tyre_age"] = new, 0
                if d["penalty_s"]:
                    lt += d["penalty_s"]
                    d["penalty_s"] = 0.0
                d["tyre_age"] += 1
                lap_times[d["code"]] = lt

            prev_new = None
            for d in sorted(alive, key=lambda d: d["cum"][-1]):
                if not d["alive"]:
                    continue
                lt = lap_times[d["code"]]
                if sc_active:
                    sc_lt = self.circuit["ref_lap_s"] * SC_LAP_FACTOR
                    target = d["cum"][-1] + sc_lt
                    if prev_new is not None:
                        target = max(d["cum"][-1] + 0.6 * sc_lt, min(target, prev_new + SC_GAP_S))
                    lt = target - d["cum"][-1]
                new_cum = d["cum"][-1] + lt
                if d.get("dnf_this_lap"):
                    frac = d.pop("dnf_this_lap")
                    d["alive"] = False
                    d["retire_lap"] = lap
                    d["retire_frac"] = frac
                    d["retire_t"] = d["cum"][-1] + lt * frac
                    d["cum"].append(d["retire_t"])
                    continue
                d["cum"].append(new_cum)
                prev_new = new_cum
                if d["best_lap"] is None or lt < d["best_lap"][0]:
                    if not sc_active:
                        d["best_lap"] = (lt, lap, new_cum)
            if sc_active:
                sc_left -= 1
                if sc_left == 0:
                    t = max((d["cum"][-1] for d in self.drivers if d["alive"]), default=0)
                    self.log(lap, t, "safety_car", "La Safety Car rentre aux stands : relance au prochain tour.")

    def _wants_wets(self, d, raining):
        if raining and d["tyre"] != "W" and self.rng.random() < 0.55:
            return True
        if not raining and d["tyre"] == "W" and self.rng.random() < 0.6:
            return True
        return False

    def _incidents(self, d, lap, forced, raining, alive, leader_t):
        extra, notes = 0.0, set()
        chaos = self.chaos
        rel = d["reliab"]
        t = d["cum"][-1]
        forced_here = [e for e in forced.get(lap, []) if e.get("driver"] == d["code"]]
        kinds = {e["type"] for e in forced_here}

        p_mech = 0.0035 * chaos * (0.6 + d["wear"] / 40.0) / max(rel, 0.1)
        if "mechanical" in kinds or self.rng.random() < p_mech:
            d["dnf_this_lap"] = self.rng.uniform(0.15, 0.9)
            d["retire_reason"] = self.rng.choice(["Moteur", "Boîte de vitesses", "Hydraulique", "Freins", "Surchauffe"])
            self.log(lap, t, "dnf", f"ABANDON de {d['code']} : {d['retire_reason']}.", d["code"])
            return 0.0, notes

        p_col = (0.0045 + (0.006 if raining else 0.0)) * chaos * (1.15 - d["skill"])
        if "collision" in kinds or (lap == 1 and self.rng.random() < p_col * 3) or self.rng.random() < p_col:
            heavy = self.rng.random() < 0.35
            if heavy:
                d["dnf_this_lap"] = self.rng.uniform(0.05, 0.6)
                d["retire_reason"] = "Accident"
                self.log(lap, t, "dnf", f"ACCIDENT : {d['code']} abandonne !", d["code"])
                return 0.0, notes
            self.log(lap, t, "collision", f"Accrochage pour {d['code']} : dommages à l'aileron.", d["code"])
            d["damage"] += self.rng.uniform(0.2, 0.9)
            extra += 3.0
            notes.add("pit")

        if "puncture" in kinds or self.rng.random() < 0.006 * chaos * (1 + max(d["tyre_age"] - 20, 0) / 10):
            self.log(lap, t, "puncture", f"Crevaison pour {d['code']} !", d["code"])
            extra += 6.0
            notes.add("pit")

        if "spin" in kinds or self.rng.random() < (0.008 + (0.03 if raining else 0.0)) * chaos * (1.2 - d["skill"]):
            self.log(lap, t, "spin", f"Tête-à-queue de {d['code']}.", d["code"])
            extra += self.rng.uniform(4, 9)

        if "penalty" in kinds or self.rng.random() < 0.012 * chaos:
            self.log(lap, t, "penalty", f"Pénalité de 5 s pour {d['code']}.", d["code"])
            d["penalty_s"] += 5.0
        return extra, notes

    def build_output(self):
        c = self.circuit
        length = c["length_m"]
        finish = [(d["cum"][-1] if d["alive"] else None) for d in self.drivers]
        race_end = max(v for v in finish if v is not None)
        t_end = race_end + 8
        times = np.arange(0, t_end + FRAME_DT, FRAME_DT)

        frames = []
        for d in self.drivers:
            knots_t = np.asarray(d["cum"], dtype=float)
            knots_d = np.arange(len(knots_t), dtype=float) * length
            knots_d[0] = -d["grid"] * GRID_GAP_M
            if not d["alive"]:
                knots_d[-1] = (len(knots_t) - 2 + d["retire_frac"]) * length
            dist = np.interp(times, knots_t, knots_d)
            if d["alive"]:
                after = times > knots_t[-1]
                dist[after] = knots_d[-1] + (times[after] - knots_t[-1]) * (length / (c["ref_lap_s"] * 1.8))
            frames.append({"code": d["code"], "d": [int(round(v)) for v in dist]})

        def completed(d):
            return len(d["cum"]) - 1 if d["alive"] else len(d["cum"]) - 2

        def sort_key(d):
            if d["alive"]:
                return (0, d["cum"][-1], 0)
            return (1, -completed(d), -d["retire_t"])
        classified = sorted(self.drivers, key=sort_key)
        winner_t = classified[0]["cum"][-1]
        results, fl = [], min((d for d in self.drivers if d["best_lap"]), key=lambda d: d["best_lap"][0], default=None)
        for pos, d in enumerate(classified, 1):
            laps_done = completed(d)
            pts = POINTS[pos - 1] if pos <= len(POINTS) and d["alive"] else 0
            if fl is d and d["alive"] and pos <= 10:
                pts += 1
            gap = None if pos == 1 else (round(d["cum"][-1] - winner_t, 3) if d["alive"] else None)
            results.append({
                "position": pos, "code": d["code"], "name": d["name"], "team": d["team"],
                "grid": d["grid"], "laps": laps_done, "status": "Finished" if d["alive"] else "DNF",
                "reason": d.get("retire_reason") if not d["alive"] else None,
                "time_s": round(d["cum"][-1], 3), "gap_s": gap, "points": pts,
                "stops": len(d["stops"]),
                "best_lap_s": round(d["best_lap"][0], 3) if d["best_lap"] else None,
                "fastest_lap": d is fl,
            })
        if fl:
            self.log(fl["best_lap"][1], fl["best_lap"][2], "fastest_lap",
                     f"Meilleur tour : {fl['code']} en {fl['best_lap'][0]:.3f} s (tour {fl['best_lap'][1]}).", fl["code"])

        updated = []
        for r in results:
            d = next(x for x in self.drivers if x["code"] == r["code"])
            s_ = dict(d["src"])
            s_["points"] = s_.get("points", 0) + r["points"]
            s_["career_races"] = s_.get("career_races", 0) + 1
            if r["position"] == 1:
                s_["season_wins"] = s_.get("season_wins", 0) + 1
                s_["career_wins"] = s_.get("career_wins", 0) + 1
            if r["position"] <= 3 and r["status"] == "Finished":
                s_["career_podiums"] = s_.get("career_podiums", 0) + 1
            if r["status"] == "DNF":
                s_["dnf"] = s_.get("dnf", 0) + 1
            s_["car_wear"] = min(100, int(d["wear"] + self.rng.randint(3, 9) + (8 if r["status"] == "DNF" else 0)))
            s_["code"] = d["code"]
            updated.append(s_)

        events = sorted(self.events, key=lambda e: e["t"])
        
        output_data = {
            "circuit": {k: c[k] for k in ("name", "location", "country", "length_m", "ref_lap_s", "x", "y", "s", "drs_zones", "corners") if k in c},
            "race": {"laps": self.laps, "weather": self.weather, "rain_laps": sorted(self.rain_laps),
                     "duration_s": round(race_end, 1), "frame_dt": FRAME_DT, "frame_count": len(times)},
            "drivers": [{"code": d["code"], "name": d["name"], "team": d["team"], "color": d["color"],
                         "grid": d["grid"]} for d in self.drivers],
            "frames": frames,
            "lap_times": {d["code"]: [round(d["cum"][i + 1] - d["cum"][i], 3) for i in range(len(d["cum"]) - 1)]
                          for d in self.drivers},
            "events": events,
            "results": results,
            "updated_drivers": updated,
        }
        
        gc.collect()
        return output_data


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--drivers", required=True)
    ap.add_argument("--circuit", required=True)
    ap.add_argument("--laps", type=int, default=20)
    ap.add_argument("--seed", type=int, default=None)
    ap.add_argument("--config", default=None, help="JSON d'influences")
    a = ap.parse_args()

    drivers = load_drivers(a.drivers)
    with open(a.circuit, encoding="utf-8") as f:
        circuit = json.load(f)
    config = {}
    if a.config:
        config = json.loads(a.config) if a.config.strip().startswith("{") else json.load(open(a.config, encoding="utf-8"))
    seed = a.seed if a.seed is not None else random.randrange(1, 10**9)
    
    race = Race(drivers, circuit, max(1, a.laps), seed, config)
    race.run()
    out = race.build_output()
    out["race"]["seed"] = seed
    
    print(json.dumps(out, ensure_ascii=False, separators=(",", ":")))
    gc.collect()


if __name__ == "__main__":
    main()
