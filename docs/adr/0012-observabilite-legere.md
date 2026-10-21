# Observabilité légère : sortie de la commande, historique des passages, journaux

Pour voir facilement qu'un import a échoué, sans ajouter d'outil :
- la commande écrit son bilan sur la sortie standard, les erreurs et avertissements sur la sortie d'erreur, et
  sort en code 1 quand la source a échoué : cron sait déjà envoyer ou journaliser cela ;
- chaque passage est conservé avec son statut, ses compteurs, sa raison et les réponses illisibles ;
  `app:inscriptions:runs` les affiche, et la synthèse d'une session dit si le dernier passage a échoué ;
- les journaux passent par le logger intégré de Symfony vers la sortie d'erreur (`docker compose logs`, cron) :
  erreur sur un échec, avertissement sur une réponse illisible ou un passage incomplet, notice sur une relance.
  Son niveau est fixé par `LOG_LEVEL` (`notice` par défaut) : sans cela, il n'écrit que les erreurs.

## Considered Options

- Monolog, alertes, Sentry ou métriques : utiles en production, à brancher sur l'outillage de Métiers 360,
  qu'on ne connaît pas.
