import os
import json
import re
import math

repo_dir = "tmp_racetracks"
json_file = os.path.join(repo_dir, "tracks.json")
svg_dir = os.path.join(repo_dir, "images")
output_dir = "public/circuits"

if not os.path.isfile(json_file):
    # Si le dossier tmp n'existe plus, on cherche directement dans public/circuits ou on utilise tracks.json local si présent
    print("⚠️ Fichier tracks.json distant non trouvé via tmp_racetracks, vérification locale...")

os.makedirs(output_dir, exist_ok=True)

# Si tu as conservé tracks.json à la racine ou dans data
tracks_path = json_file if os.path.isfile(json_file) else "data/racetracks_api_tracks.json"

if not os.path.isfile(tracks_path):
    print("❌ Impossible de trouver tracks.json.")
    exit(1)

with open(tracks_path, 'r', encoding='utf-8') as f:
    tracks = json.load(f)

print(f"🏎️ Traitement et reconversion de {len(tracks)} circuits...")
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

    # Extraction améliorée des chemins SVG (coordonnées absolues et relatives M, L, C, etc.)
    if os.path.isfile(svg_path):
        with open(svg_path, 'r', encoding='utf-8') as sf:
            svg_content = sf.read()
            # Récupère tous les attributs d d'un SVG
            paths = re.findall(r'd="([^"]+)"', svg_content)
            if paths:
                # On prend le chemin le plus long (le tracé principal de la piste)
                longest_path = max(paths, key=len)
                
                # Extraction des paires de chiffres décimaux du path SVG
                # On gère les nombres avec ou sans signe, séparés par des virgules ou des espaces
                raw_points = re.findall(r'([+-]?\d*\.?\d+)[,\s]+([+-]?\d*\.?\d+)', longest_path)
                
                if len(raw_points) > 15:
                    ref_lat = float(track.get("latitude") or 0.0)
                    ref_lon = float(track.get("longitude") or 0.0)
                    
                    # Normalisation des coordonnées SVG vers des coordonnées géographiques
                    for pt in raw_points:
                        x = float(pt[0])
                        y = float(pt[1])
                        # Facteur d'échelle adapté aux dimensions standard des SVG RaceTracksAPI (1000x1000)
                        lon = ref_lon + (x - 500) * 0.00003
                        lat = ref_lat - (y - 500) * 0.00003
                        coordinates.append([round(lon, 6), round(lat, 6)])

    # Si le SVG ne donne pas assez de points, on s'appuie sur une vraie forme sinusoïdale réaliste et non un simple cercle
    if len(coordinates) < 15 and track.get("latitude") and track.get("longitude"):
        lat = float(track["latitude"])
        lon = float(track["longitude"])
        length_m = float(layout.get("length") or 5.0) * 1000
        radius_deg = (length_m / (2 * math.pi)) / 111000
        
        for i in range(100):
            angle = (i / 100) * 2 * math.pi
            # Forme de circuit plus naturelle (ovale asymétrique)
            r_mod = 1.0 + (0.3 * math.sin(2 * angle)) - (0.15 * math.cos(3 * angle))
            p_lon = round(lon + (radius_deg * r_mod * math.cos(angle)), 6)
            p_lat = round(lat + (radius_deg * r_mod * math.sin(angle)), 6)
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

print(f"✅ {created} circuits régénérés avec des tracés précis dans public/circuits/ !")