# Le statut d'une inscription reflète la source, sans retour en arrière

Le statut et les réponses d'une inscription reflètent la source, sans règle métier ajoutée : l'import n'écrit
que les champs venus de la source (voir ADR 0010 pour les données de l'équipe opérations). Seul garde-fou : une
version n'est appliquée que si sa date de modification n'est pas plus ancienne que celle déjà connue, pour ne
jamais revenir en arrière si l'API sert une version périmée.

Une inscription peut changer après sa session (réponse de Métiers 360 par mail), et on ne fige rien : la synthèse
reste le reflet de la source, que l'équipe opérations corrige dans l'outil de formulaire. Ce qui s'est passé le
jour J (présence) relève des données de l'équipe opérations (ADR 0010), pas de l'import.

## Considered Options

- Figer les inscriptions d'une session passée : cacherait les corrections faites après coup dans l'outil, et
  invente une règle métier que la source n'a pas.
- Appliquer le changement mais signaler « à revoir » une inscription modifiée après sa session : à ajouter si
  l'équipe opérations en exprime le besoin.
