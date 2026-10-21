# Les données saisies par l'équipe opérations vivront dans une table à part

La table des inscriptions appartient à l'import, qui la réécrit à chaque passage. Ce que l'équipe opérations
saisira un jour (présence le jour J, relances, notes) ira dans une table séparée, reliée à l'inscription, que
l'import ne touche jamais. Mélanger les deux dans une même ligne, c'est risquer qu'un passage d'import écrase une
saisie humaine. Rien n'est construit aujourd'hui : Métiers 360 le place hors du périmètre de l'exercice, sans l'exclure à terme
(réponse par mail). La table à part permettra de l'ajouter sans toucher à l'import.
