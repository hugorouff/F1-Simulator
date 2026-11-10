import os
import json
import glob
import re

repo_dir = "tmp_racetracks"
json_file = os.path.join(repo_dir, "tracks.json")
svg_dir = os.path.join(repo_dir, "images")
output_dir = "public/circuits"

if not os.path.isfile(json_file):
    print("❌ Le fichier tracks.json est introuvable dans tmp_racetracks.")
    exit(1)

os.makedirs(output_dir, exist_ok=True)

with open(json_file, 'r', encoding='utf-8') as f:
    tracks = json.load(f)

print(f"🏎️ Traitement de {len(tracks)} circuits en local (Python)...")
created = 0

for track in tracks:
    name = track.get("name")
    layouts = track.get("layouts", [])
    if not name or not layouts:
        continue

    layout = layouts[0]
    image_id = layout.get("image_id")
    if not image_id:
        continue

    svg_path = os.path.join(svg_dir, f"track_{image_id}.svg")
    coordinates = []

    # 1. Extraction des points depuis le SVG
    if os.path.isfile(svg_path):
        with open(svg_path, 'r', encoding='utf-8') as sf:
            svg_content = sf.read()
            paths = re.findall(r'd="([^"]+)"', svg_content)
            if paths:
                longest_path = max(paths, key=len)
                points = re.findall(r'([0-9\.\-]+)[,\s]+([0-9\.\-]+)', longest_path)
                if len(points) >= 10:
                    ref_lat = float(track.get("latitude") or 0.0)
                    ref_lon = float(track.get("longitude") or 0.0)
                    for pt in points:
                        x = float(pt[0])
                        y = float(pt[1])
                        lon = ref_lon + (x - 500) * 0.00005
                        lat = ref_lat - (y - 500) * 0.00005
                        coordinates.append([round(lon, 6), round(lat, 6)])

    # 2. Repli géométrique si le SVG n'a pas pu être parsé
    if len(coordinates) < 10 and track.get("latitude") and track.get("longitude"):
        lat = float(track["latitude"])
        lon = float(track["longitude"])
        length_m = float(layout.get("length") or 5.0) * 1000
        radius_deg = (length_m / (2 * 3.1415926535)) / 111000
        
        for i in range(61):
            angle = (i / 60) * 2 * 3.1415926535
            r_modifier = 1.0 + (0.15 * __import__('math').cos(3 * angle))
            p_lon = round(lon + (radius_deg * r_modifier * __import__('math').cos(angle)), 6)
            p_lat = round(lat + (radius_deg * r_modifier * __import__('math').sin(angle)), 6)
            coordinates.append([p_lon, p_lat])

    if not coordinates:
        continue

    slug = re.sub(r'[^a-z0-9-]+', '-', name.lower()).strip('-')
    length_meters = float(layout.get("length") or 5.0) * 1000

    geo_json = {
        "type": "FeatureCollection",
        "name": slug,
        "features": [
            {
                "type": "Feature",
                "properties": {
                    "id": slug,
                    "Name": name,
                    "Location": track.get("region") or track.get("country") or "Circuit",
                    "length": length_meters,
                    "country": track.get("country") or "XX"
                },
                "geometry": {
                    "type": "LineString",
                    "coordinates": coordinates
                }
            }
        ]
    }

    output_file = os.path.join(output_dir, f"{slug}.geojson")
    with open(output_file, 'w', encoding='utf-8') as out_f:
        json.dump(geo_json, out_f, ensure_ascii=False, indent=2)
    created += 1

print(f"✅ {created} circuits générés et ajoutés dans public/circuits/ !")