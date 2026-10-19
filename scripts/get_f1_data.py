"""Récupère les résultats d'une session F1 via FastF1 et les renvoie en JSON."""
import gc
import json
import os
import sys
import unicodedata

import fastf1
import pandas as pd

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")


def enable_cache():
    cache_dir = "/tmp/fastf1_cache"
    try:
        os.makedirs(cache_dir, exist_ok=True)
        if os.access(cache_dir, os.W_OK):
            fastf1.Cache.enable_cache(cache_dir)
            
            # Indispensable pour éviter le blocage 403 de CloudFront sur Render
            fastf1.req.headers = {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            }
    except OSError:
        pass


def format_time(value):
    if pd.isna(value):
        return None
    total = value.total_seconds()
    minutes, seconds = divmod(total, 60)
    hours, minutes = divmod(int(minutes), 60)
    if hours:
        return f"{hours}:{minutes:02d}:{seconds:06.3f}"
    return f"{minutes}:{seconds:06.3f}"


def get_session_data(year, round_number, session_type):
    try:
        enable_cache()
        session = fastf1.get_session(int(year), int(round_number), session_type)
        session.load(telemetry=False, weather=False, messages=False)

        results = []
        for _, driver in session.results.iterrows():
            position = driver["Position"]
            results.append({
                "position": int(position) if not pd.isna(position) else None,
                "number": str(driver["DriverNumber"]),
                "code": str(driver["Abbreviation"]),
                "name": str(driver["FullName"]),
                "team": str(driver["TeamName"]),
                "status": str(driver["Status"]),
                "time": format_time(driver["Time"]),
            })

        event = str(session.event["EventName"]) if "EventName" in session.event else None
        
        gc.collect()
        return {"status": "success", "event": event, "data": results}
    except Exception as exc:
        gc.collect()
        return {"status": "error", "message": f"{type(exc).__name__}: {exc}"}


if __name__ == "__main__":
    year = sys.argv[1] if len(sys.argv) > 1 else 2023
    round_number = sys.argv[2] if len(sys.argv) > 2 else 1
    session_type = sys.argv[3] if len(sys.argv) > 3 else "R"

    print(json.dumps(get_session_data(year, round_number, session_type), ensure_ascii=False))
