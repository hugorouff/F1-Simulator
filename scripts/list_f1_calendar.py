"""Print a season's Formula 1 event schedule from FastF1 as JSON."""
import json
import logging
import math
from pathlib import Path
import sys

import fastf1


if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8")

logging.getLogger("fastf1").setLevel(logging.ERROR)


def clean(value):
    if value is None or (isinstance(value, float) and math.isnan(value)):
        return None
    if hasattr(value, "isoformat"):
        return value.date().isoformat() if hasattr(value, "date") else value.isoformat()
    return str(value)


def main():
    if len(sys.argv) != 2:
        raise ValueError("Usage: list_f1_calendar.py <year>")

    year = int(sys.argv[1])
    cache_dir = Path(__file__).resolve().parent.parent / "var" / "cache" / "fastf1"
    cache_dir.mkdir(parents=True, exist_ok=True)
    fastf1.Cache.enable_cache(str(cache_dir))
    schedule = fastf1.get_event_schedule(year, include_testing=False)
    events = []
    for _, event in schedule.iterrows():
        round_number = int(event["RoundNumber"])
        if round_number < 1:
            continue
        events.append({
            "round": round_number,
            "name": clean(event.get("EventName")),
            "location": clean(event.get("Location")),
            "country": clean(event.get("Country")),
            "date": clean(event.get("EventDate")),
        })

    print(json.dumps({"year": year, "events": events}, ensure_ascii=False))


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(json.dumps({"error": str(error)}, ensure_ascii=False), file=sys.stderr)
        sys.exit(1)