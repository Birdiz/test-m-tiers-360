# Un échec qui ne vient pas de la source est consigné sur le passage, puis relancé

Une panne de la base ou un bogue en cours de passage laissait le passage « en cours » pour toujours : les
pages déjà écrites restaient, mais ni `app:inscriptions:runs` ni la synthèse de session ne disaient qu'il avait
échoué. Pire, les retraits étaient écrits avant le statut « complet » : si ce dernier échouait, un passage non
complet avait retiré des inscriptions, contre l'ADR 0002. Désormais :

- les retraits et le statut « complet » sont écrits dans une seule transaction ;
- toute erreur autre que la source est attrapée une seule fois, autour du passage, pour le marquer échoué avec
  sa raison, puis relancée. La commande ne l'attrape pas : Symfony l'affiche sur la sortie d'erreur avec sa
  trace et sort en code non nul, ce que cron voit déjà (ADR 0012) ;
- au démarrage, un passage encore « en cours » est marqué échoué (« interrompu ») : le verrou de la commande
  garantit qu'il n'est plus en vie. C'est le seul filet quand la base est hors d'atteinte, ou quand le processus
  est tué ou manque de mémoire, cas où aucun `catch` ne s'exécute.

## Considered Options

- Attraper l'erreur et rendre un passage échoué, comme pour la source : la trace d'un bogue disparaîtrait
  derrière une ligne de bilan.
- Un `try/catch` par écriture : chaque `flush` échoue de la même manière et pour la même suite (le passage
  s'arrête, rien n'est retiré), un seul suffit.
- Ne pas attraper du tout : c'était l'état précédent.

## Consequences

- Après une erreur de base, le gestionnaire d'entités est fermé : l'échec est écrit par une requête, et les
  compteurs du passage gardent ce que la dernière page validée a écrit.
- Seule la première ligne du message est gardée dans la raison : le détail d'une erreur Postgres (« Failing row
  contains … ») cite la ligne refusée entière, données personnelles comprises. La sortie d'erreur, elle, garde
  le message entier.
- L'échec de la toute première écriture, celle qui crée le passage, n'est consigné nulle part ailleurs que sur
  la sortie d'erreur : la base qui la refuse ne prendrait pas non plus la trace de l'échec.
