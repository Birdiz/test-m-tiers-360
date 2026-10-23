# Le suffixe `Dto` ne va qu'aux classes qui portent des données sans rien décider

Une classe prend le suffixe `Dto` quand elle ne fait que transporter des données d'une étape à l'autre : `final
readonly`, propriétés publiques promues, aucune méthode hors du constructeur. Le nom garde le mot du domaine et
l'étape avant le suffixe : `NormalizedSubmissionDto`, pas `SubmissionDto`, car une soumission brute (un tableau
lu dans l'API) et une soumission normalisée ne sont pas la même chose, et c'est l'étape qui le dit. Le suffixe
s'écrit `Dto`, comme Symfony écrit `Json`, `Http` ou `Uuid`. Il s'applique aujourd'hui à `NormalizedSubmissionDto`,
`SessionSnapshotDto` et `SourcePageDto`.

Les autres classes de données gardent leur nom du domaine, parce qu'elles décident quelque chose : `Anomaly` est
un terme du glossaire et choisit sa forme JSON ; `Roster`, `RosterEntry` et `RosterSummary` fixent le contrat de
l'API (noms des champs, regroupements, ce qu'on ne publie pas) ; les exceptions sont des exceptions. Le suffixe
ne figure pas dans `GLOSSARY.md`, qui reste sans détail d'implémentation.

## Considered Options

- Aucun suffixe : le mot du domaine suffit, et `final readonly` se lit dès l'ouverture du fichier. Écarté, car
  `NormalizedSubmission` et `SubmissionNormalizer` voisinent dans le même dossier et ne se distinguent pas au
  nom du fichier, l'un porteur de données, l'autre service.
- `SubmissionDTO` : perd l'étape et entre en conflit avec le glossaire, qui réserve « soumission » à ce que
  sert la source ; `DTO` en capitales s'écarte de l'usage Symfony.
- `Dto` sur toute classe de données, `RosterEntry` comprise : la limite devient un jugement au cas par cas, alors
  que « aucune méthode hors du constructeur » se vérifie à la lecture.
