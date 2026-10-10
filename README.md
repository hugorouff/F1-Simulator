# F1 Simulator

Projet personnel réunissant :
- Symfony 7.4
- Doctrine ORM
- PostgreSQL
- import/export Excel via PhpSpreadsheet
- moteur de simulation Python
- projet `IAmTomShaw/f1-race-replay` intégré dans `python/f1-race-replay`

## Installation

## Saisons et courses

Ouvre `/saisons`, crée les divisions dans l’ordre (F1 niveau 1, F2 niveau 2, etc.), puis crée une saison globale avec un nom et un nombre de courses. La page affiche un catalogue dédupliqué de circuits FastF1; choisis exactement ce nombre de circuits, sans sélectionner d’année. FastF1 choisit automatiquement l’édition disponible pour obtenir le tracé.

Inscris ensuite les écuries et leurs pilotes à chaque saison. Pour chaque circuit, ouvre le replay de chaque division afin de simuler et enregistrer ses résultats. La saison ne peut être clôturée que lorsque chaque engagement a un résultat sur toutes ses manches. À la clôture, les deux premières écuries montent d’un niveau sauf en F1, et les deux dernières descendent s’il existe un niveau inférieur. Les classements restent associés à la division d’origine. Le replay accepte jusqu’à 200 tours et les tracés réels sont disponibles à partir de 2018.

Le catalogue des circuits est dédupliqué par lieu et pays; le millésime le plus récent est choisi en interne pour le tracé du replay. FastF1 fournit les circuits et tracés F1. Les calendriers officiels propres à F2/F3 ne sont pas encore séparés; ces divisions courent sur le catalogue de circuits commun.

La page d’accueil `/` présente le roster chargé par le simulateur, les saisons enregistrées et une animation du tracé FastF1 de Monza.

## Imports JSON

Ouvre `/import` pour charger des listes de pilotes ou d’écuries. L’import fait un upsert par nom et conserve les entrées absentes du fichier. Le JSON des pilotes remplace aussi `data/pilotes.json` (l’ancien fichier est sauvegardé en `data/pilotes.bak.json`). Les écuries doivent référencer une division existante par nom ou identifiant, sauf si une division par défaut est choisie dans le formulaire.

Pilotes : `name` (ou `prenom` + `nom`), `nationality`, `date_of_birth`, `career_wins`, `career_races`, `career_podiums`, `season_wins`, `championships`, `active`, `team`.

Écuries : `name`, `division` (nom ou identifiant, facultatif avec division par défaut), `car_condition`, `constructor_titles`, `active`.

Chaque saison est sérialisée dans `data/saisons/{id}.json` après création, après chaque résultat de course et après clôture. Le fichier contient les courses, engagements, classements et mouvements de division; il est téléchargeable depuis la page Saisons.

### 1. PHP/Symfony

Installer les dépendances :

```powershell
composer install
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
symfony server:start
```

### 2. F1 Race Replay

Depuis PowerShell à la racine :

```powershell
.\scripts\install-f1-race-replay.ps1
```

Puis :

```powershell
cd python\f1-race-replay
python -m venv venv
.\venv\Scripts\Activate.ps1
pip install -r requirements.txt
python main.py
```

Le code original et ses fonctionnalités sont conservés dans son propre sous-dossier.
