# Pistes d'architecture

Pistes relevées par la revue d'architecture du 23 octobre 2026 : des modules à approfondir (plus de comportement
derrière une interface plus petite). Ce ne sont pas des décisions : une piste explorée et tranchée devient un ADR,
une piste écartée pour une raison durable aussi, pour qu'une revue suivante ne la repropose pas.

Force : **forte**, **à explorer** ou **spéculative**.

## 1. Un seul module pour le passage complet et le retrait — tranchée (ADR 0013)

Force : forte. ADR 0002, 0003, 0009.

La règle « retirée après deux passages complets » est répartie entre les variables locales de
`RegistrationImporter`, les compteurs d'`ImportRun`, `RegistrationRepository` (`countSeenIn`,
`removeNotSeenSince`), `ImportRunRepository::lastCompletedBefore` et le champ `lastSeenRunId` de
`Registration`. Deux nombres de « vues » coexistent : `ImportRun::seen` ne compte que les soumissions lisibles,
le contrôle de complétude compte aussi les illisibles. Aucun test n'atteint le statut `Incomplete`.

## 2. FormApi cache son transport et ses attentes

Force : à explorer. ADR 0011.

- **Fichiers** : `src/Import/FormApi.php`, `src/Import/RegistrationImporter.php` (le `catch` de
  `ExceptionInterface`, la détection d'un total qui change), `config/services.yaml`.
- **Friction** : l'importeur doit connaître les exceptions du client HTTP de Symfony, et c'est lui qui vérifie
  que le total annoncé ne change pas pendant la lecture. `usleep` est câblé en dur et les tests passent
  `retryDelayMs: 0` : le backoff, le plafond de 30 s et `Retry-After` ne sont pas testés, aucun 429 n'est joué.
- **Piste** : `FormApi` traduit tout échec de la source en un seul échec du domaine, garantit « toutes les pages
  lues, à total stable », et attend à travers une horloge injectée que les tests peuvent observer.
- **À ne pas faire** : une interface `FormApi` avec un faux. Il n'y aurait qu'un adaptateur, et le faux
  cacherait de nouveau le code de relance aux tests (la leçon de l'ADR 0011). Le seam reste le client HTTP, qui a
  déjà deux adaptateurs (le vrai client et `FakeFormApi`).
- **Tests** : S1, plus un 429 avec `Retry-After`, en vérifiant les pages demandées et le temps attendu.

## 3. Une seule lecture pour la synthèse de session

Force : à explorer. ADR 0004, 0008.

- **Fichiers** : `src/Roster/SessionRoster.php`, `src/Roster/Roster.php`, `RegistrationRepository::findBySession`
  et `findInOtherSessionsByEmails`, `ImportRunRepository::lastFinished` et `lastCompleted`.
- **Friction** : le « premier arrivé, premier servi » de l'ADR 0004 est coupé en deux : l'ordre est un
  `ORDER BY` du dépôt, le rang est l'indice du tableau dans `Roster`, qui fait confiance à un docblock « in queue
  order ». `Roster::of` a cinq paramètres, un seul appelant et aucun test direct : les erreurs se logent dans les
  requêtes. `SessionRoster` cherche la session par son seul identifiant externe, sans son opération (l'unicité
  est sur le couple). La fraîcheur des passages est globale, pas propre à l'opération.
- **Piste** : un seul module récupère, ordonne et classe ; `Roster` reste le modèle de lecture retourné. Au test
  de suppression, `Roster::of` est un simple relais.
- **Tests** : inchangés (S1, S4).
- **À noter dans l'ADR 0004** : une inscription dont le premier import montre déjà un changement de session prend
  sa date de création comme date d'arrivée ; son rang dépend alors de l'historique des imports, pas seulement de
  la source.

## 4. L'importeur garantit « un passage à la fois » et termine toujours son passage

Force : à explorer. ADR 0009, 0012.

- **Fichiers** : `src/Command/ImportRegistrationsCommand.php` (verrou, statut → code de sortie),
  `src/Import/RegistrationImporter.php`, `src/Command/ListImportRunsCommand.php`.
- **Friction** : le verrou exigé par l'ADR 0009 vit dans la commande ; le gestionnaire de message que l'ADR
  prévoit le contournerait. Une exception qui ne vient pas de la source (erreur de base de données) sort de
  `run()` et laisse le passage à `running` pour toujours. La correspondance statut → code de sortie (ADR 0012)
  n'est couverte par aucun seam de test.
- **Piste** : l'importeur prend le verrou et termine toujours son passage ; la commande ne fait que l'afficher.
- **Tests** : S1, « un deuxième passage simultané est refusé » et « une panne termine le passage en échec ».

## 5. Des types valeur pour les formes stockées

Force : spéculative.

- **Fichiers** : `src/Entity/Registration.php` (anomalies), `src/Entity/ImportRun.php` (soumissions illisibles),
  les deux commandes.
- **Friction** : anomalies et soumissions illisibles sont stockées en tableaux bruts ; chaque lecteur répète leur
  forme et le repli `?? '(no id)'`. L'entité `Registration` dépend de `App\Import`.
- **Piste** : un type valeur (ou un type Doctrine) par forme stockée. Peu de levier : deux appelants chacun.

## Vérifié, à laisser tel quel

- `SubmissionNormalizer` : profond (une méthode, beaucoup de règles), testé directement (S2).
- `Anomaly`, `SessionSnapshot`, `NormalizedSubmission`, `UnreadableSubmission`, `RosterEntry`, `RosterSummary` :
  passent le test de suppression.
- `FakeFormApi` se branche sur le client HTTP : deux adaptateurs, un vrai seam. À garder.
- Le retrait en une requête et le détachement page par page : imposés par la borne mémoire de l'ADR 0009.
