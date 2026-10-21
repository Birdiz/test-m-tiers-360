# « Hors capacité » est calculé à la lecture, pas stocké

L'outil de formulaire ne ferme pas les sessions pleines (ex. 47 inscriptions actives pour 40 places à Belley). On ne refuse
rien à l'import. À la lecture, les inscriptions actives sont classées par date de soumission (premier arrivé,
premier servi) et celles au-delà de la capacité sont marquées hors capacité. Comme ce n'est pas un statut
stocké, une annulation fait remonter automatiquement la suivante, sans traitement à rejouer.

## Considered Options

- Statut « liste d'attente » écrit à l'import : à recalculer à chaque annulation, et invente une règle métier
  que la source n'a pas. La règle « premier arrivé » reste à confirmer par Métiers 360.
