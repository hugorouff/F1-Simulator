"""Script de génération en masse des circuits de référence, nouveautés et historiques."""
import json
import os
import sys
from export_circuit import export

# Bibliothèque complète : Classiques + Historiques spécifiques + Saisons 2025/2026
TARGETS = [
    # --- Calendrier de référence moderne (Saison standard) ---
    (2023, "Bahrain Grand Prix", "bahrain_grand_prix.json"),
    (2023, "Saudi Arabian Grand Prix", "saudi_arabian_grand_prix.json"),
    (2023, "Australian Grand Prix", "australian_grand_prix.json"),
    (2023, "Azerbaijan Grand Prix", "azerbaijan_grand_prix.json"),
    (2023, "Miami Grand Prix", "miami_grand_prix.json"),
    (2023, "Monaco Grand Prix", "monaco_grand_prix.json"),
    (2023, "Spanish Grand Prix", "spanish_grand_prix.json"),
    (2023, "Canadian Grand Prix", "canadian_grand_prix.json"),
    (2023, "Austrian Grand Prix", "austrian_grand_prix.json"),
    (2023, "British Grand Prix", "british_grand_prix.json"),
    (2023, "Hungarian Grand Prix", "hungarian_grand_prix.json"),
    (2023, "Belgian Grand Prix", "belgian_grand_prix.json"),
    (2023, "Dutch Grand Prix", "dutch_grand_prix.json"),
    (2023, "Italian Grand Prix", "italian_grand_prix.json"),
    (2023, "Singapore Grand Prix", "singapore_grand_prix.json"),
    (2023, "Japanese Grand Prix", "japanese_grand_prix.json"),
    (2023, "Qatar Grand Prix", "qatar_grand_prix.json"),
    (2023, "United States Grand Prix", "united_states_grand_prix.json"),
    (2023, "Mexico City Grand Prix", "mexico_city_grand_prix.json"),
    (2023, "São Paulo Grand Prix", "s_o_paulo_grand_prix.json"),
    (2023, "Las Vegas Grand Prix", "las_vegas_grand_prix.json"),
    (2023, "Abu Dhabi Grand Prix", "abu_dhabi_grand_prix.json"),

    # --- Historiques / Circuits absents du calendrier classique ---
    (2018, "German Grand Prix", "2018_german_grand_prix.json"),       # Hockenheim
    (2021, "French Grand Prix", "2021_french_grand_prix.json"),       # Le Castellet / Paul Ricard
    (2021, "Portuguese Grand Prix", "2021_portuguese_grand_prix.json"), # Portimão
    (2020, "Eifel Grand Prix", "2020_eifel_grand_prix.json"),         # Nürburgring
    (2020, "Turkish Grand Prix", "2020_turkish_grand_prix.json"),     # Istanbul Park
    (2021, "Styrian Grand Prix", "2021_styrian_grand_prix.json"),     # Spielberg alternatif

    # --- Nouveautés 2026 ---
    (2026, "Malaysian Grand Prix", "2026_malaysian_grand_prix.json"),
    (2026, "Madrid Grand Prix", "2026_madrid_grand_prix.json"),
]

OUTPUT_DIR = os.path.join(os.path.dirname(os.path.dirname(__file__)), "public", "circuits")

def main():
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    total = len(TARGETS)
    print(f"🏁 Lancement de la génération de {total} circuits (références + historiques + 2025/2026)...\n")

    success, failed = 0, 0
    for index, (year, gp, filename) in enumerate(TARGETS, 1):
        target_path = os.path.join(OUTPUT_DIR, filename)
        
        if os.path.exists(target_path):
            print(f"[{index}/{total}] ⏩ {filename} existe déjà. Ignoré.")
            success += 1
            continue

        print(f"[{index}/{total}] ⏳ Génération de {gp} ({year})...")
        try:
            data = export(year, gp)
            with open(target_path, "w", encoding="utf-8") as f:
                json.dump(data, f, ensure_ascii=False)
            print(f"[{index}/{total}] ✅ Succès : {filename}")
            success += 1
        except Exception as e:
            print(f"[{index}/{total}] ❌ Erreur pour {gp} {year}: {e}")
            failed += 1

    print(f"\n🎉 Terminé ! {success} circuits prêts, {failed} échecs.")

if __name__ == "__main__":
    main()