# Les entités portent les contraintes de la base ; chaque valeur de la source est vérifiée avant d'y entrer

Une valeur de la source que nos colonnes ne peuvent pas recevoir faisait échouer tout le passage sur une erreur
de base de données : un nom de plus de 255 caractères, un identifiant de plus de 64, une capacité au-delà d'un
`integer`, une année 0. Un octet nul était pire : le pilote coupe la valeur sans erreur, si bien qu'un identifiant
qui en contient ne se retrouve plus au passage suivant et casse l'unicité (formulaire, soumission) de l'ADR 0001.

Les entités portent des contraintes Symfony Validator qui reprennent leurs colonnes, et rien de plus : `Length` à
la longueur de la colonne, `Range` d'un `integer` pour la capacité, pas d'année avant l'an 1 pour les dates. Une
valeur fausse ou vide que la base accepte n'est pas leur affaire. Les longueurs sont des constantes des entités
(`Registration::NAME_LENGTH`…), lues par le mapping Doctrine et par la contrainte.

Les entités ne sont construites qu'à partir de ce que rendent `SubmissionNormalizer` et `FormApi` : c'est là que
chaque valeur est vérifiée, avec `validatePropertyValue` sur la propriété où elle finira, et que l'ADR 0003
s'applique.

- Une réponse que la base ne peut pas tenir (prénom, nom, e-mail, téléphone, structure) est importée avec le champ
  vide et une anomalie `too_long` qui garde la valeur brute. On ne la coupe pas : coupée, elle aurait l'air valide.
- Un identifiant de soumission ou de session, une date de soumission, une date ou une capacité de session que la
  base ne peut pas tenir rendent la soumission illisible : elle est consignée dans le passage, les autres sont
  importées.
- Le libellé et le lieu d'une session, et le titre du formulaire, sont coupés à la longueur de leur colonne : ils
  ne décrivent personne et n'ont pas d'anomalie pour garder le reste.
- Un identifiant de formulaire que la base ne peut pas tenir fait échouer le passage : aucune inscription ne
  pourrait être rattachée.
- Les octets nuls sont retirés du texte : aucune contrainte ne les voit, la base les tronque sans erreur.

Côté lecture, `GET /api/sessions/{id}/inscriptions` n'accepte qu'un identifiant sans octet nul et de 64
caractères au plus : sinon 404, au lieu d'une session trouvée par erreur (`ses_01%00` donnait `ses_01`). Un octet
UTF-8 invalide était déjà refusé par le routeur.

## Considered Options

- **Valider l'entité entière avant `flush`.** Une violation ne peut alors que rejeter l'inscription, alors que
  l'ADR 0003 l'importe et signale le champ ; et elle arrive après que l'entité gérée a été modifiée, qu'il
  faudrait défaire. Vérifier chaque valeur à l'entrée applique les mêmes contraintes, champ par champ.
- **Vérifier les longueurs à la main dans le normaliseur.** Moins de code et pas de dépendance, mais la règle ne
  se lit plus sur l'entité, à côté de la colonne qu'elle protège.
- **Rattraper l'erreur de base de données.** Le passage ne s'arrête plus, mais la page entière est perdue pour une
  seule valeur, et l'`EntityManager` est fermé après l'erreur.
