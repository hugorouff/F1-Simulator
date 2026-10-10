# Replay & simulation F1 — fichiers à copier dans ton projet Symfony

Copie ces dossiers à la racine de `f1-simulator` (ils s'ajoutent à l'existant, rien n'est écrasé) :

    scripts/export_circuit.py      tracé réel d'un circuit via FastF1 (JSON)
    scripts/make_demo_circuit.py   circuit fictif hors ligne (pour tester)
    scripts/sim_engine.py          moteur de simulation (pilotes + influences + incidents)
    data/pilotes.json              tes 20 pilotes (convertis depuis ton f1.xlsx)
    src/Service/PythonRunner.php
    src/Controller/ReplayController.php
    templates/replay/index.html.twig

## Installation

    php bin/console cache:clear
    symfony server:start

Ouvre http://127.0.0.1:8000/replay

1. Coche « Circuit de démonstration » pour tester tout de suite (aucun téléchargement).
2. Décoche-le pour un vrai circuit : le premier chargement d'un circuit prend 1 à 2 min
   (FastF1), puis il est mis en cache dans `var/circuits/`.

Dépendances Python : numpy est déjà installé avec f1-race-replay.
Pour lire directement un .xlsx à la place du JSON : `python\venv\Scripts\pip install openpyxl`.

## Test en ligne de commande (sans Symfony)

    python\venv\Scripts\python.exe scripts\make_demo_circuit.py > var\demo.json
    python\venv\Scripts\python.exe scripts\sim_engine.py --drivers data\pilotes.json --circuit var\demo.json --laps 15 --seed 7

## Lien dans la barre de navigation (templates/base.html.twig)

Ajoute dans le <nav>, à côté du logo :

    <a href="{{ path('app_replay') }}" class="text-sm text-gray-300 hover:text-white">Simulation</a>
    <a href="{{ path('app_simulator') }}" class="text-sm text-gray-300 hover:text-white">Résultats FastF1</a>

## Régler la simulation

- Force des voitures : dictionnaire `TEAM_STRENGTH` en haut de `sim_engine.py`.
- Niveau des pilotes : calculé par `skill_rating()` depuis victoires, courses, podiums,
  titres et âge (les mêmes colonnes que ton Excel).
- Influences : formulaire de la page (météo, chaos, Safety Car, événements forcés,
  modificateurs par pilote).
- « Appliquer à mes pilotes » met à jour `data/pilotes.json` (sauvegarde dans `pilotes.bak.json`).
