# Pas de cache sur la synthèse d'une session, pour l'instant

La synthèse d'une session est calculée à chaque requête. À ce volume (une centaine d'inscriptions par
session), c'est une requête et quelques millisecondes ; un cache ajouterait un risque de données périmées
sans gain mesurable. Les données ne changent qu'à la fin d'un passage d'import : le jour où il faudra un
cache, la clé sera `session + dernier passage terminé` (sans invalidation à gérer), avec un `ETag` et
`Cache-Control: private` pour ne jamais laisser de données personnelles dans un cache partagé.

## Considered Options

- Cache applicatif indexé par passage : prêt à ajouter, à faire quand une mesure le justifie.
- Table de synthèse recalculée en fin de passage : un vrai modèle de lecture, mais une seconde source de
  vérité à maintenir pour un gain nul à ce volume.
