# Le statut d'une inscription reflète la source, sans retour en arrière

Le statut et les réponses d'une inscription reflètent la source, sans règle métier ajoutée : l'import n'écrit
que les champs venus de la source (voir ADR 0010 pour les données de l'équipe opérations). Seul garde-fou : une
version n'est appliquée que si sa date de modification n'est pas plus ancienne que celle déjà connue, pour ne
jamais revenir en arrière si l'API sert une version périmée. Figer les inscriptions d'une session passée est une
règle métier en attente de Métiers 360.
