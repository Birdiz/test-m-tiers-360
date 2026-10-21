# L'import ne possède que les champs venus de la source

Le statut et les réponses d'une inscription reflètent la source, sans règle métier ajoutée. Deux garde-fous :
une version n'est appliquée que si sa date de modification n'est pas plus ancienne que celle déjà connue, pour ne
jamais revenir en arrière si l'API sert une version périmée. Et l'import n'écrit que les champs venus de la source :
les informations que l'équipe opérations saisira un jour (présence, relances) vivront dans une table à part que
l'import ne touche jamais. Figer les inscriptions d'une session passée est une règle métier en attente de
Métiers 360.
