import sys
import json
import os
import logging

import fastf1
import pandas as pd

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8")

logging.getLogger("fastf1").setLevel(logging.ERROR)

cache_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "var", "cache", "fastf1")
os.makedirs(cache_dir, exist_ok=True)
fastf1.Cache.enable_cache(cache_dir)


def format_time(td, is_leader):
    total = td.total_seconds()
    if is_leader:
        h = int(total // 3600)
        m = int((total % 3600) // 60)
        s = total % 60
        return f"{h}:{m:02d}:{s:06.3f}"
    return f"+{total:.3f}s"


def get_session_data(year, gp, session_type):
    try:
        session = fastf1.get_session(int(year), gp, session_type)
        session.load(telemetry=False, laps=False, weather=False, messages=False)

        results = []
        for _, d in session.results.iterrows():
            pos = d["Position"]
            time_val = d["Time"]
            position = int(pos) if pd.notna(pos) else None
            time_str = None
            if pd.notna(time_val):
                time_str = format_time(time_val, position == 1)

            results.append({
                "position": position,
                "number": str(d["DriverNumber"]),
                "code": d["Abbreviation"],
                "name": d["FullName"],
                "team": d["TeamName"],
                "status": d["Status"],
                "time": time_str,
            })

        event = session.event
        return {
            "status": "success",
            "event": str(event["EventName"]),
            "data": results,
        }
    except Exception as e:
        return {"status": "error", "message": str(e)}


if __name__ == "__main__":
    year = sys.argv[1] if len(sys.argv) > 1 else 2020
    gp = sys.argv[2] if len(sys.argv) > 2 else "Monza"
    session_type = sys.argv[3] if len(sys.argv) > 3 else "R"
    print(json.dumps(get_session_data(year, gp, session_type)))