import json
import os
import sys

import pandas as pd

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

import fastf1  # noqa: E402


def enable_cache():
    base = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
    candidates = [os.path.join(base, "var", "cache", "fastf1"), "/tmp/fastf1"]
    for path in candidates:
        try:
            os.makedirs(path, exist_ok=True)
            if os.access(path, os.W_OK):
                fastf1.Cache.enable_cache(path)
                return
        except OSError:
            continue


def format_time(value):
    if pd.isna(value):
        return "N/A"
    total = value.total_seconds()
    minutes, seconds = divmod(total, 60)
    hours, minutes = divmod(int(minutes), 60)
    if hours:
        return f"{hours}:{minutes:02d}:{seconds:06.3f}"
    return f"{minutes}:{seconds:06.3f}"


def get_session_data(year, grand_prix, session_type):
    try:
        enable_cache()
        session = fastf1.get_session(int(year), grand_prix, session_type)
        session.load(telemetry=False, weather=False, messages=False)

        results = []
        for _, driver in session.results.iterrows():
            position = driver["Position"]
            results.append({
                "position": int(position) if not pd.isna(position) else "NC",
                "number": str(driver["DriverNumber"]),
                "code": str(driver["Abbreviation"]),
                "name": str(driver["FullName"]),
                "team": str(driver["TeamName"]),
                "status": str(driver["Status"]),
                "time": format_time(driver["Time"]),
            })

        event = str(session.event["EventName"]) if "EventName" in session.event else None
        return {"status": "success", "event": event, "data": results}
    except Exception as exc:  # message renvoyé au contrôleur
        return {"status": "error", "message": f"{type(exc).__name__}: {exc}"}


if __name__ == "__main__":
    year = sys.argv[1] if len(sys.argv) > 1 else 2023
    grand_prix = sys.argv[2] if len(sys.argv) > 2 else "Monza"
    session_type = sys.argv[3] if len(sys.argv) > 3 else "R"

    print(json.dumps(get_session_data(year, grand_prix, session_type), ensure_ascii=False))