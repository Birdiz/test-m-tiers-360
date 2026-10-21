# Une inscription absente de la source est retirée, jamais supprimée, et seulement après un passage complet

Des inscriptions disparaissent de l'API entre deux passages. On les marque « retirées » : elles sortent des
comptes mais restent en base pour l'historique de l'équipe opérations. On ne retire qu'après un passage
d'import complet, c'est-à-dire toutes les pages lues et autant d'identifiants vus que le total annoncé par
l'API. La pagination par numéro de page décale les réponses quand une inscription est supprimée pendant un
passage : sans ce contrôle, une inscription jamais lue serait retirée à tort.

## Consequences

- Si la disparition est une demande d'effacement RGPD, il faudra effacer les données chez nous aussi : question
  posée à Métiers 360.
- Un passage qui échoue ou qui voit un total incohérent applique les mises à jour lues, mais ne retire rien.
