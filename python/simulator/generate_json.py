import json
import sys
import os

def generate_telemetry(input_json_path, output_json_path):
    with open(input_json_path, 'r', encoding='utf-8') as f:
        race_data = json.load(f)

    # Structure attendue par run_arcade_replay
    simulation_payload = {
        "title": race_data.get("title", "Grand Prix Symfony"),
        "total_laps": race_data.get("total_laps", 50),
        "drivers": race_data.get("drivers", []),
        "driver_colors": race_data.get("driver_colors", {}),
        "frames": race_data.get("frames", []),
        "track_statuses": race_data.get("track_statuses", []),
        "session_info": race_data.get("session_info", {})
    }

    with open(output_json_path, 'w', encoding='utf-8') as f:
        json.dump(simulation_payload, f, indent=2)

    print(f"Télémetrie générée avec succès : {output_json_path}")

if __name__ == "__main__":
    if len(sys.argv) > 2:
        generate_telemetry(sys.argv[1], sys.argv[2])