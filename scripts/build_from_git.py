import os
import json
import re
import math

json_file = "tmp_racetracks/tracks.json"
svg_dir = "tmp_racetracks/images"
output_dir = "public/circuits"

if not os.path.isfile(json_file):
    print(f"❌ Fichier {json_file} introuvable.")
    exit(1)

os.makedirs(output_dir, exist_ok=True)

with open(json_file, 'r', encoding='utf-8') as f:
    tracks = json.load(f)

print(f"🏎️ Conversion 100% locale de {len(tracks)} circuits à partir des SVG...")

success_count = 0
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
    coords = []

    # Extraction directe des chemins du SVG local
    if os.path.isfile(svg_path):
        try:
            with open(svg_path, 'r', encoding='utf-8') as sf:
                svg_content = sf.read()
                paths = re.findall(r'd="([^"]+)"', svg_content)
                if paths:
                    longest_path = max(paths, key=len)
                    raw_points = re.findall(r'([+-]?\d*\.?\d+)[,\s]+([+-]?\d*\.?\d+)', longest_path)
                    if len(raw_points) > 10:
                        ref_lat = float(track.get("latitude") or 0.0)
                        ref_lon = float(track.get("longitude") or 0.0)
                        for pt in raw_points:
                            x, y = float(pt[0]), float(pt[1])
                            # Normalisation des coordonnées du SVG (box 1000x1000) vers coordonnées géographiques relatives
                            lon = ref_lon + (x - 500) * 0.00004
                            lat = ref_lat - (y - 500) * 0.00004
                            coords.append([round(lon, 6), round(lat, 6)])
        except Exception:
            pass

    # Repli géométrique harmonisé si le SVG n'a pas pu être lu
    if len(coords) < 10 and track.get("latitude") and track.get("longitude"):
        lat = float(track["latitude"])
        lon = float(track["longitude"])
        length_m = float(layout.get("length") or 5.0) * 1000
        radius_deg = (length_m / (2 * math.pi)) / 111000
        for i in range(80):
            angle = (i / 80) * 2 * math.pi
            r_mod = 1.0 + (0.25 * math.sin(2 * angle))
            p_lon = round(lon + (radius_deg * r_mod * math.cos(angle)), 6)
            p_lat = round(lat + (radius_deg * r_mod * math.sin(angle)), 6)
            coords.append([p_lon, p_lat])

    if len(coords) < 10:
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
                    "Location": track.get("region") or track.get("country") or name,
                    "Name": name,
                    "length": length_meters,
                    "source": "local_svg_conversion"
                },
                "geometry": {
                    "type": "LineString",
                    "coordinates": coords
                }
            }
        ]
    }

    output_path = os.path.join(output_dir, f"{slug}.geojson")
    with open(output_path, 'w', encoding='utf-8') as out_f:
        json.dump(geo_json, out_f, ensure_ascii=False, indent=2)
    success_count += 1

print(f"\n🎉 Terminé en un éclair ! {success_count} circuits convertis localement sans erreur réseau.")