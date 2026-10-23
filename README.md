# Import des inscriptions Métiers 360

La commande `app:inscriptions:import` importe les inscriptions de l'API du formulaire dans PostgreSQL. Elle
peut tourner en cron. `GET /api/sessions/{id}/inscriptions` renvoie la synthèse d'une session. Les décisions
sont dans [`docs/adr/`](docs/adr/) et le vocabulaire dans [`GLOSSARY.md`](GLOSSARY.md). Les consignes pour un
agent sont dans [`CLAUDE.md`](CLAUDE.md) et ses skills dans [`.claude/skills/`](.claude/skills/)
([ADR 0013](docs/adr/0013-consignes-agents-minimales-et-skills.md)).

## Lancer le projet

Avec `make` (`make` seul liste les cibles) :

```bash
make start
make import
make runs
make summary SESSION=ses_03
make check
```

Sans `make` :

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app bin/console doctrine:migrations:migrate -n
docker compose exec app bin/console app:inscriptions:import
docker compose exec app bin/console app:inscriptions:runs
curl -H 'Authorization: Bearer dev-ops-token' http://localhost:8000/api/sessions/ses_03/inscriptions
docker compose exec app vendor/bin/phpunit
docker compose exec app vendor/bin/phpstan analyse
```

```cron
*/15 * * * * cd /chemin/du/projet && docker compose exec -T app bin/console app:inscriptions:import >> var/log/import.log 2>&1
```

## Les trois hypothèses qui ont le plus pesé

1. **Une inscription absente de la source est retirée, jamais supprimée, et seulement après deux passages
   complets de suite** ([ADR 0002](docs/adr/0002-retrait-apres-passage-complet.md)). La pagination par numéro
   de page décale les réponses quand la source change pendant un passage. Un seul passage « complet » peut
   donc manquer une inscription toujours présente. Un test reproduit ce cas. L'outil de formulaire ne donne pas
   la raison d'une disparition, et ce peut être une demande d'effacement : à la fin de l'opération, les données
   personnelles d'une inscription retirée sont effacées (décidé, pas construit).
2. **Les données sales sont importées et signalées**
   ([ADR 0003](docs/adr/0003-donnees-sales-importees-et-signalees.md)). Si on rejetait une inscription, on
   cacherait à l'équipe une personne qui viendra le jour J. Le champ normalisé reste vide, et une anomalie dit
   quoi corriger dans le formulaire. Seule une réponse impossible à rattacher à une session est écartée, et elle
   est consignée dans le passage.
3. **Premier arrivé, premier servi, à partir de la date où l'inscription a rejoint sa session. La
   surréservation est calculée à la lecture** ([ADR 0004](docs/adr/0004-hors-capacite-calcule-a-la-lecture.md)).
   Le formulaire ne ferme pas les sessions pleines (47 inscriptions actives pour 40 places à Belley). Rien n'est
   refusé à l'import. Une annulation fait remonter la suivante sans traitement à rejouer. Métiers 360 n'a pas
   de règle arrêtée : c'est notre hypothèse pour une vue fiable de chaque session.

## Laissé de côté volontairement

- **Asynchrone (Messenger)** : personne n'attend la fin d'un passage lancé par cron
  ([ADR 0009](docs/adr/0009-passage-synchrone-lu-page-a-page.md), qui remplace la 0006). L'import est un service
  indépendant de la commande, prêt pour un futur bouton « Importer ».
- **Cache sur la synthèse** : une requête de quelques millisecondes à ce volume. La clé est déjà choisie pour le
  jour où il faudra un cache ([ADR 0008](docs/adr/0008-pas-de-cache-sur-la-synthese.md)).
- **Données saisies par l'équipe opérations** (présence, relances, notes) : elles iront dans une table à part
  que l'import ne touche pas. Rien n'est construit sans besoin exprimé
  ([ADR 0010](docs/adr/0010-donnees-operations-dans-une-table-a-part.md)).
- **Authentification réelle / SSO** : un jeton porteur unique pour l'équipe opérations suffit au débrief.
- **Figer les sessions passées** : une inscription peut changer après sa session, et la synthèse reste le reflet
  de la source. La présence du jour J ira dans les données de l'équipe opérations
  ([ADR 0007](docs/adr/0007-l-import-ne-possede-que-les-champs-de-la-source.md)).
- **Monolog, alertes, Sentry** : à brancher sur l'outillage de Métiers 360, que je ne connais pas. La commande
  écrit sur stdout/stderr et sort en code 1 en cas d'échec, et chaque passage est historisé
  ([ADR 0012](docs/adr/0012-observabilite-legere.md)).
- **Une seule opération par API** : `ses_01` n'est pas préfixé par l'opération dans l'URL, puisque l'API ne sert
  qu'un formulaire.
- **Le verrou n'est pas testé** : c'est le `LockableTrait` de Symfony.
- **Le téléphone n'est normalisé que pour les formats français.**

## Avec une journée de plus

- Effacer les données personnelles des inscriptions retirées à la fin de leur opération. C'est décidé, pas
  construit ([ADR 0002](docs/adr/0002-retrait-apres-passage-complet.md)).
- Automatiser l'essai de bout en bout contre le simulateur (trois passages, comptes attendus). C'est lui, pas
  les tests, qui a trouvé le bug des relances sur la page lente
  ([ADR 0011](docs/adr/0011-relances-dans-le-code.md)).
- Tester deux passages lancés en même temps contre une vraie base.

## Utilisation de l'IA

Claude Code, avec les skills de Matt Pocock dans l'ordre : *grilling* → *domain-modeling* → *codebase-design* →
*tdd* → *code-review* → *writing-for-agents*.

- **Délégué** : l'écriture du code, des tests et des ADR, à partir des décisions prises en séance.
- **Vérifié moi-même** : j'ai relu et amendé chaque ADR et relu les diffs du code. J'ai lancé l'import contre
  le simulateur et lu sa sortie. J'ai appelé l'endpoint et jugé la synthèse.
- J'ai pris les décisions pendant les séances de *grilling*, en contestant les propositions de l'outil.
  L'asynchrone a été contesté deux fois, puis abandonné. Le cache a été jugé superflu, et ce refus est
  consigné comme une décision. La recopie du statut de la source a été remise en question.
- **La décision la plus marquante contre l'outil** : il proposait de conserver la réponse brute complète de
  chaque soumission. J'ai refusé : ce sont en majorité des données de mineurs. Une anomalie ne garde que la
  valeur fautive ([ADR 0003](docs/adr/0003-donnees-sales-importees-et-signalees.md)).

## Temps consacré

3 h 15 d'attention, en 3 sessions : 2 h pour la mise en place, le *grilling* et les ADR ; 1 h pour la
conception, le TDD, la revue et les corrections ; 15 min pour le README et le `CLAUDE.md`.
