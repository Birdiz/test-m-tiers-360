# « Hors capacité » est calculé à la lecture, pas stocké

L'outil de formulaire ne ferme pas les sessions pleines (ex. 47 inscriptions actives pour 40 places à Belley). On ne refuse
rien à l'import. À la lecture, les inscriptions actives sont classées par date de soumission (premier arrivé,
premier servi) et celles au-delà de la capacité sont marquées hors capacité. Comme ce n'est pas un statut
stocké, une annulation fait remonter automatiquement la suivante, sans traitement à rejouer. Métiers 360 n'a pas
de règle arrêtée pour la surréservation (réponse par mail) : ce qui compte pour l'équipe est une vue fiable de
chaque session, et « premier arrivé » reste notre hypothèse.

Une inscription déplacée d'une autre session prend son rang à la date où elle y est arrivée (la date de
modification de la version qui montre le déplacement), pas à sa date de soumission : sinon les trois
déplacements vers Belley du jeu de données passeraient devant des personnes inscrites à Belley avant eux.

## Considered Options

- Statut « liste d'attente » écrit à l'import : à recalculer à chaque annulation, et invente une règle métier
  que la source n'a pas.
