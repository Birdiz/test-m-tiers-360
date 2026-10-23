# Les dossiers de `src/` sont rangés par raison de changer, pas par sorte de classe

`Entity/` ne contient plus que les classes que Doctrine mappe. Les enums stockés dans une colonne d'entité
(`ImportRunStatus`, `RegistrationStatus`, `StructureType`) vont dans `Enum/` : ils sont lus à la fois par les
entités, l'import, la synthèse et les dépôts, aucun de ces dossiers ne les possède. `Import/` est découpé selon
ce qui le fait changer :
- `Import/Source/` change quand le contrat de l'API du formulaire change (`docs/api-formulaire.md`, ADR 0011) :
  le client, la page lue et l'exception levée quand une page n'a pas la forme documentée ;
- `Import/Submission/` change quand une règle de nettoyage change (ADR 0003) : le normaliseur, ce qu'il produit,
  les anomalies et la soumission illisible ;
- `RegistrationImporter` reste à la racine d'`Import/` : c'est le passage d'import, qui assemble les deux
  (ADR 0002, 0009).

Une exception reste à côté de ce qui la lève, et `AnomalyCode` à côté d'`Anomaly`, dont il est un champ.
`ReviewReason` reste dans `Roster/` : calculé à la lecture, jamais stocké, lu par la seule synthèse.

## Considered Options

- Des dossiers par sorte (`Exception/`, `Dto/`, `Enum/` pour tous les enums) : on saurait ce qu'est une classe,
  pas ce qu'il faut toucher ensemble quand l'API ou une règle change. `Enum/` n'est gardé que pour les valeurs
  partagées par plusieurs dossiers.
- Les enums à côté de leur entité (`Entity/Enum/`) : `RegistrationStatus` et `StructureType` sont aussi le
  vocabulaire de la soumission normalisée et de la synthèse, pas seulement des colonnes.
- Ranger aussi les tests en miroir : ils sont rangés par point d'appui (S1, S2, S4), pas par classe testée, et
  restent où ils sont.

## Consequences

- Les entités dépendent toujours d'`Import/Submission/` (`Registration` est construite depuis la soumission
  normalisée, `Session` depuis son instantané) : le déplacement rend cette dépendance visible, il ne la supprime
  pas.
