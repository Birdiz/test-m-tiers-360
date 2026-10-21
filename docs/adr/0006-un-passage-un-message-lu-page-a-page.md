# Un passage d'import = un message asynchrone, lu page par page

La commande `app:inscriptions:import` envoie un message `RunImport` sur un transport Messenger asynchrone
et rend la main tout de suite : rien de ce qui déclenche un import (cron, futur bouton) n'attend la fin.
Un worker consomme le message et lit l'API page par page. Chaque page est enregistrée dans une transaction
courte, et les retraits ne sont appliqués qu'à la fin d'un passage complet (ADR 0002). La mémoire reste
celle d'une page, quel que soit le volume. Un verrou empêche deux passages simultanés. `--sync` exécute le
passage dans la commande, pour le débogage.

## Considered Options

- Tout charger en mémoire puis une seule transaction : simple, mais la mémoire croît avec le volume.
- Un message par page : stocke une seconde fois les données personnelles dans la file, et il faudrait un
  coordinateur pour savoir quand le passage est complet.
- Import synchrone dans la commande : convient au cron, mais fait attendre un futur déclenchement depuis
  l'interface.
