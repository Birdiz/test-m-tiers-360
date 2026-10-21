# Les relances vers l'API du formulaire sont écrites dans le code, pas dans la configuration du client

On voulait relancer une page trop lente (la page 8 du simulateur met 20 s à répondre) en la coupant après
`max_duration` puis en la redemandant. L'option `retry_failed` du client HTTP de Symfony 6.4 ne le permet pas :
`RetryableHttpClient` partage `max_duration` entre toutes les tentatives, si bien qu'après une première tentative
coupée à 10 s, la relance échoue aussitôt ; et un `timeout` d'inactivité n'est pas relancé du tout. Une petite
boucle dans `FormApi` relance donc chaque page (3 fois, attente exponentielle plafonnée à 30 s, `Retry-After`
respecté), chaque tentative avec son propre `max_duration`.

## Consequences

- Découvert en lançant l'import sur le vrai simulateur : les tests passaient, car une réponse simulée ne
  consomme pas de temps. L'essai de bout en bout contre le simulateur fait partie de la vérification.
