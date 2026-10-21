# Une inscription est identifiée par la source, pas par la personne

Une inscription est identifiée par le couple (identifiant du formulaire, identifiant de la réponse) fourni par
l'outil externe, et c'est la seule clé d'unicité. On ne modélise pas de « personne » : l'e-mail manque,
change de casse, ou est partagé par des frères et sœurs (`famille.girard@…`), donc aucune clé métier n'est
fiable. Les doublons probables (même session, même e-mail normalisé, même prénom, même date de naissance)
sont signalés dans la synthèse et laissés à l'équipe opérations, qui corrige dans l'outil de formulaire.

## Considered Options

- Clé métier e-mail + session : fusionne deux enfants inscrits avec l'e-mail familial.
- Fusion automatique des doublons : il faudrait choisir quelle inscription garder, et sur quelle session
  quand la personne s'est inscrite à deux sessions. Ce choix revient à l'équipe opérations.
