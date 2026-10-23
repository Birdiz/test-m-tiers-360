# Les limites des colonnes sont tenues à l'entrée de la source, pas par des contraintes sur les entités

Une valeur de la source que nos colonnes ne peuvent pas recevoir faisait échouer tout le passage sur une erreur
de base de données : un nom de plus de 255 caractères, un identifiant de plus de 64, une capacité au-delà d'un
`integer`, une année 0. Un octet nul était pire : le pilote coupe la valeur sans erreur, si bien qu'un identifiant
qui en contient ne se retrouve plus au passage suivant et casse l'unicité (formulaire, soumission) de l'ADR 0001.

Les entités ne sont construites qu'à partir de ce que rend `SubmissionNormalizer` (et `FormApi` pour
l'opération) : c'est la seule entrée, et c'est là que les limites sont tenues, en suivant l'ADR 0003.

- Une réponse trop longue (prénom, nom, structure) est importée avec le champ vide et une anomalie `too_long`
  qui garde la valeur brute. On ne la coupe pas : coupée, elle aurait l'air valide.
- Un identifiant de soumission ou de session trop long, une capacité hors limites ou une date avant l'an 1 rendent
  la soumission illisible : elle est consignée dans le passage, les autres sont importées.
- Le libellé et le lieu d'une session, et le titre du formulaire, sont coupés à la longueur de leur colonne : ils
  ne décrivent personne et n'ont pas d'anomalie pour garder le reste.
- Un identifiant de formulaire trop long fait échouer le passage : aucune inscription ne pourrait être rattachée.
- Les octets nuls sont retirés du texte libre.

Les longueurs sont des constantes des entités (`Registration::NAME_LENGTH`…), lues par le mapping Doctrine et par
le normaliseur : une seule valeur à changer avec la migration. L'adresse e-mail (254 caractères au plus une fois
validée) et le téléphone (12, une fois normalisé) tiennent déjà dans leurs colonnes.

Côté lecture, `GET /api/sessions/{id}/inscriptions` n'accepte qu'un identifiant sans octet nul et de 64
caractères au plus : sinon 404, au lieu d'une session trouvée par erreur (`ses_01%00` donnait `ses_01`). Un octet
UTF-8 invalide était déjà refusé par le routeur.

## Considered Options

- **Contraintes Symfony Validator sur les entités, vérifiées avant `flush`.** C'est l'usage courant quand une
  entité reçoit un formulaire ; ici aucune ne reçoit de saisie. Une violation ne peut que rejeter l'inscription,
  alors que l'ADR 0003 l'importe et la signale ; et elle arrive après que l'entité gérée a été modifiée, qu'il
  faudrait défaire. Les mêmes longueurs seraient écrites une troisième fois (migration, mapping, contrainte), pour
  une dépendance de plus (`symfony/validator` n'est pas installé).
- **Rattraper l'erreur de base de données.** Le passage ne s'arrête plus, mais la page entière est perdue pour une
  seule valeur, et l'`EntityManager` est fermé après l'erreur.
