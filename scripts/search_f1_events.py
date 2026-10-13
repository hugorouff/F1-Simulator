import gc
import json
import logging
import math
import os
import sys
import unicodedata
from datetime import date
from pathlib import Path

import fastf1

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8")

logging.getLogger("fastf1").setLevel(logging.ERROR)


def enable_cache():
    cache_dir = "/tmp/fastf1_cache"
    try:
        os.makedirs(cache_dir, exist_ok=True)
        if os.access(cache_dir, os.W_OK):
            fastf1.Cache.enable_cache(cache_dir)
    except OSError:
        pass


def clean(value):
    if value is None or (isinstance(value, float) and math.isnan(value)):
        return None
    if hasattr(value, "isoformat"):
        return value.date().isoformat() if hasattr(value, "date") else value.isoformat()
    return str(value)


def normalized(value):
    decomposed = unicodedata.normalize("NFKD", value.casefold())
    return "".join(char for char in decomposed if not unicodedata.combining(char))


def unique_circuits(events):
    circuits = {}
    today = date.today().isoformat()
    for event in events:
        location = event.get("location") or event.get("name") or ""
        country = event.get("country") or ""
        key = normalized(f"{location}|{country}")
        if not key.strip("|"):
            continue
        current = circuits.get(key)
        event_is_past = bool(event.get("date") and event["date"] <= today)
        current_is_past = bool(current and current.get("date") and current["date"] <= today)
        if current is None or (event_is_past, event.get("date") or "") > (current_is_past, current.get("date") or ""):
            event["circuit_key"] = key
            circuits[key] = event

    return sorted(circuits.values(), key=lambda event: (normalized(event.get("location") or ""), event.get("country") or ""))


def catalog(path):
    if path.is_file():
        with path.open(encoding="utf-8") as source:
            return unique_circuits(json.load(source))

    events = []
    current_year = date.today().year
    for year in range(1950, current_year + 1):
        try:
            schedule = fastf1.get_event_schedule(year, include_testing=False)
        except Exception:
            continue

        for _, event in schedule.iterrows():
            round_number = int(event["RoundNumber"])
            if round_number < 1:
                continue
            events.append({
                "year": year,
                "round": round_number,
                "name": clean(event.get("EventName")),
                "location": clean(event.get("Location")),
                "country": clean(event.get("Country")),
                "date": clean(event.get("EventDate")),
            })

    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(".tmp")
    with temporary.open("w", encoding="utf-8") as output:
        json.dump(events, output, ensure_ascii=False)
    temporary.replace(path)
    return events


def main():
    if len(sys.argv) != 2:
        raise ValueError("Usage: search_f1_events.py <search>")

    query = normalized(sys.argv[1].strip())
    if query and len(query) < 2:
        raise ValueError("La recherche doit contenir au moins deux caractères.")

    enable_cache()
    cat_path = Path("/tmp/fastf1_cache/event_catalog.json")
    events = catalog(cat_path)
    circuits = unique_circuits(events)
    matches = circuits if not query else [
        event for event in events
        if query in normalized(" ".join(str(event.get(key) or "") for key in ("name", "location", "country")))
    ]
    if query:
        matches = unique_circuits(matches)
        
    gc.collect()
    print(json.dumps({"events": matches if not query else matches[:250]}, ensure_ascii=False))


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        gc.collect()
        print(json.dumps({"error": str(error)}, ensure_ascii=False), file=sys.stderr)
        sys.exit(1)
