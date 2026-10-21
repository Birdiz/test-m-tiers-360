# Un passage d'import est synchrone, déclenché par cron, et lu page par page

La commande `app:inscriptions:import` exécute le passage elle-même, déclenchée par cron. Personne n'attend
sa fin : un traitement asynchrone (Messenger, worker, transport) n'apporterait rien tant qu'aucun déclencheur
HTTP n'existe. Le passage lit l'API page par page et enregistre chaque page dans une transaction courte ; les
retraits ne sont appliqués qu'à la fin d'un passage complet (ADR 0002). La mémoire reste celle d'une page, quel
que soit le volume. Un verrou empêche deux passages simultanés, car un passage peut durer plus longtemps que
l'intervalle du cron quand l'API est lente.

## Considered Options

- Message asynchrone par passage (ADR 0006, remplacée) : à faire le jour où un bouton « Importer » existe.
  L'import est un service indépendant de la commande, donc un gestionnaire de message n'aura qu'à l'appeler.
- Tout charger en mémoire puis une seule transaction : la mémoire croît avec le volume.
