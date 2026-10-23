# Le relevé du passage est seul à savoir ce que la source contient encore

Savoir si un passage est complet et quelles inscriptions retirer (ADR 0002) demandait de lire cinq endroits : des
variables locales de l'importeur, les compteurs du passage, deux dépôts, et le numéro du dernier passage qui a vu
chaque inscription, écrit par trois méthodes de l'entité. Deux nombres de « vues » coexistaient : celui affiché
ne comptait que les soumissions lisibles, celui du contrôle de complétude comptait aussi les illisibles.

Un seul module, le relevé du passage (`RunTally`), possède désormais cette règle. L'importeur lui donne, page par
page et dans la transaction de la page, le total annoncé et l'identifiant de chaque soumission lue, lisible ou
non. Le relevé marque ces inscriptions comme vues en une requête par page (ce qui fait aussi redevenir active une
inscription retirée), remarque un total qui change, et décide de la fin du passage : échoué, incomplet ou complet,
avec les retraits. L'entité `Registration` ne connaît plus les passages : `lastSeenRunId` est nul jusqu'au
marquage, qui a lieu dans la même transaction, et une valeur nulle n'est jamais retirée.

Les soumissions vues sont comptées une fois chacune, lisibles ou non : c'est ce nombre que le passage enregistre,
que la commande affiche, et qu'on compare au total. Une soumission lue deux fois parce que les pages se sont
décalées ne compte qu'une fois, si bien que le passage est jugé incomplet plutôt que complet à tort.

## Considered Options

- Faire porter la règle à l'entité `ImportRun` : une entité ne peut pas interroger la base, et garder en mémoire
  les identifiants vus ferait croître la mémoire avec le volume (ADR 0009).
- Laisser l'entité `Registration` noter le passage qui l'a vue : la règle resterait coupée entre l'entité, qui
  écrit le numéro, et les requêtes qui le lisent.

## Consequences

- Les passages enregistrés avant cette décision gardent leur ancien nombre de vues (soumissions lisibles
  seulement) ; rien n'est recalculé.
- Le verrou et la fin d'un passage interrompu par une panne hors source restent hors du relevé (piste 4 de
  `docs/pistes-architecture.md`).
