# Une inscription absente de la source est retirée, jamais supprimée, et seulement après deux passages complets

Des inscriptions disparaissent de l'API entre deux passages. On les marque « retirées » : elles sortent des
comptes mais restent en base pour l'historique de l'équipe opérations.

On ne retire qu'une inscription absente de **deux passages complets consécutifs**. Un passage est complet quand
toutes les pages ont été lues et qu'il a vu autant d'identifiants que le total annoncé par l'API. Ce contrôle
seul ne suffit pas : la pagination par numéro de page décale les réponses quand la source change pendant un
passage, et une suppression compensée par une nouvelle inscription garde le même total alors qu'une inscription
toujours présente n'a jamais été lue. Un test le reproduit. La retenue d'un passage supplémentaire écarte ce
faux retrait. Il faudrait que le décalage touche la même inscription deux fois de suite.

## Consequences

- Une vraie suppression n'est appliquée qu'au deuxième passage complet qui la constate (un intervalle de cron
  de plus).
- L'outil de formulaire ne donne pas la raison d'une disparition, et Métiers 360 n'a pas de position arrêtée
  (réponse par mail). Une disparition peut être une demande d'effacement, et ce sont en majorité des données de
  mineurs : une inscription retirée n'est donc gardée que jusqu'à la fin de son opération. Ensuite, ses données
  personnelles sont effacées et ne restent que les identifiants de la source, le statut et la session, pour les
  comptes et pour reconnaître une réapparition. Décidé, pas encore construit.
- Un passage qui échoue ou qui est incomplet applique les mises à jour lues, mais ne retire rien.
- Une inscription retirée qui réapparaît dans la source redevient active.
