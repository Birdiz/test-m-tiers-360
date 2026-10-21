# Les données saisies par l'équipe opérations vivront dans une table à part

La table des inscriptions appartient à l'import, qui la réécrit à chaque passage. Ce que l'équipe opérations
saisira un jour (présence le jour J, relances, notes) ira dans une table séparée, reliée à l'inscription, que
l'import ne touche jamais. Mélanger les deux dans une même ligne, c'est risquer qu'un passage d'import écrase une
saisie humaine. Rien n'est construit aujourd'hui, faute de besoin exprimé (question posée à Métiers 360).
