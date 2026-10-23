# PHPStan au niveau 10, avec les types que Doctrine et PHPUnit savent donner

PHPStan passe du niveau 6 au niveau 10, le plus haut, sur `src` et `tests`, sans baseline ni `@phpstan-ignore`.
Les niveaux 9 et 10 refusent qu'on lise un `mixed` sans l'avoir vérifié : c'est précisément la frontière de ce
projet, qui lit un JSON qu'il ne maîtrise pas. La montée a trouvé un vrai défaut : `FormApi` vérifiait que `data`
est une liste, pas que chaque entrée est un objet ; un `null` dans `data` faisait planter l'import (`TypeError`)
au lieu d'être écarté et signalé dans le passage comme une réponse illisible (ADR 0003).

## Consequences

- Le niveau est écrit `10`, pas `max` : `max` changerait de sens sans prévenir le jour où PHPStan ajoute un niveau.
- phpstan-doctrine reçoit le gestionnaire d'entités (`tests/object-manager.php`) : il vérifie le DQL et connaît le
  type des résultats des requêtes. Il ne type `getOneOrNullResult()` que si le mode d'hydratation est donné, d'où
  le `Query::HYDRATE_OBJECT` explicite dans `ImportRunRepository`.
- phpstan-phpunit est ajouté : il comprend les assertions et signale celles qui échoueront toujours (il a repéré
  un `@var` trop étroit pendant la montée).
- Le JSON décodé reste `mixed` jusqu'à ce qu'on le vérifie. Dans `src`, on le vérifie champ par champ (`FormApi`,
  `SubmissionNormalizer`), jamais avec un `@var`. Dans les tests, un `@var` décrit la forme que les assertions
  lisent : si la réponse ne l'a pas, ces assertions échouent, donc l'annotation ne cache rien.

## Considered Options

- S'arrêter au niveau 8 : il ne coûtait qu'une correction, mais c'est aux niveaux 9 et 10 que se trouvent les
  erreurs propres à ce projet, celles du `mixed` venu de la source.
