# Le normaliseur analyse la réponse en types ; Symfony Validator ne sert qu'aux limites de la base

Le normaliseur fait deux choses : il écarte une soumission illisible (sans identifiant, statut, dates ou session
lisibles) et il nettoie les autres champs en gardant une anomalie quand ils sont sales (ADR 0003). Il ne valide
pas un tableau pour le transformer ensuite : chaque champ est lu une seule fois et devient son type (`string`,
`RegistrationStatus`, `\DateTimeImmutable`, `SessionSnapshot`), ou une soumission illisible, ou une anomalie.
`NormalizedSubmission` ne peut donc pas exister dans un état invalide : ses types non nuls sont ses règles.

Symfony Validator n'intervient qu'ensuite, pour vérifier qu'une valeur déjà lue tient dans sa colonne, avec les
contraintes que portent les entités (ADR 0014). Il ne sert ni à lire, ni à nettoyer, ni à juger une valeur que la
base accepte.

## Considered Options

- **Valider `NormalizedSubmission` dans son constructeur.** Pour les champs sales, cela casse l'ADR 0003 : une
  adresse invalide ou une naissance en 1900 ferait rejeter l'inscription, et l'équipe opérations ne verrait pas
  une personne qui viendra le jour J. Et l'objet ne peut pas produire l'anomalie : il porte la valeur nettoyée
  (e-mail vide), et non plus la valeur brute fautive que l'anomalie doit nommer. Pour les cas illisibles, les
  types du constructeur rejettent déjà tout ce qu'une contrainte rejetterait ; Validator suppose en plus de
  construire d'abord un objet invalide pour le contrôler ensuite.
- **Contraintes sur un objet d'entrée brut pour les cas illisibles, violations converties en soumission
  illisible.** Respecte l'ADR 0003, mais chaque règle serait écrite deux fois : la contrainte vérifie qu'un
  horodatage est lisible, puis le normaliseur doit quand même le lire pour obtenir la date. Les formats de la
  source (`Z` au lieu de `+00:00`, date stricte qui refuse le 30 février, session imbriquée) demandent des
  contraintes sur mesure qui appellent les mêmes fonctions de lecture. Les règles d'un même champ seraient
  réparties entre l'objet brut et le normaliseur, l'inverse du « tout au même endroit » recherché.
- **Contraintes pour tous les champs sales, violations converties en anomalies.** Validator dit qu'une valeur est
  fausse, il ne la transforme pas : passer le téléphone en `+33…`, lire `12/03/2008`, mettre un type de
  structure inconnu en « autre » resterait dans le normaliseur.

## Consequences

- La lecture et le nettoyage d'un champ, et la raison de son rejet ou de son anomalie, sont dans
  `SubmissionNormalizer` et se testent à cet endroit (seam S2). Seule la longueur ou la plage que la base peut
  tenir se lit sur l'entité, à côté de sa colonne.
