# Modèle de données

- Division : une écurie appartient à une seule division.
- Pilote : catalogue historique/personnalisé ; pas de division permanente.
- Engagement : affecte un pilote à une écurie pour une saison.
- Saison -> Course -> Circuit.
- Course -> RésultatCourse.
- Course -> Tour -> TourPilote pour la télémétrie détaillée.
- Saison -> Simulation -> SimulationCourse.

Les statistiques de carrière restent dans `Pilote`; les résultats d'une saison/course restent dans `Engagement` et `ResultatCourse`.
