# Inscriptions Métiers 360

Import des inscriptions collectées par l'outil de formulaire externe, pour donner à l'équipe opérations
une vue fiable de chaque session.

## Organisation

**Opération** :
Journée(s) de découverte des métiers montée(s) avec une région ou un réseau. Une opération correspond à un
formulaire de l'outil externe.
_Éviter_ : événement, campagne

**Session** :
Créneau d'une opération à une date et dans un lieu, avec un nombre de places (sa **capacité**).
_Éviter_ : créneau, atelier

**Structure** :
Organisme qui accompagne la personne inscrite : lycée, mission locale ou agence France Travail.
_Éviter_ : établissement, partenaire

## Inscriptions

**Inscription** :
Une réponse au formulaire, identifiée par l'outil externe, qui demande une place dans une session.
Une même personne peut avoir plusieurs inscriptions. Dans le code : `Registration`.
_Éviter_ : participant, réponse

**Soumission** :
Une inscription telle que l'API du formulaire la sert, avant import : c'est le mot de l'API (`submissions`),
réservé à ce qui vient de la source. Dans le code : `Submission`.

**Inscription active** :
Inscription que la source donne comme soumise (ni annulée, ni retirée). Seules les inscriptions actives
occupent une place.

**Annulation** :
La personne a renoncé à sa place depuis le lien reçu par mail. L'inscription reste connue, mais n'occupe
plus de place.
_Éviter_ : désinscription, suppression

**Inscription retirée** :
Inscription qui n'apparaît plus dans la source alors qu'un passage d'import complet a eu lieu. Elle est
conservée chez nous mais n'occupe plus de place.
_Éviter_ : supprimée, annulée

**Doublon probable** :
Deux inscriptions actives d'une même session qui semblent venir de la même personne. Signalé à l'équipe
opérations, jamais fusionné automatiquement.
_Éviter_ : doublon (sans qualificatif), fusion

**Hors capacité** :
Inscription active arrivée après que la session a atteint sa capacité, dans l'ordre des dates de soumission.
_Éviter_ : liste d'attente, refusée

**Surréservation** :
Situation d'une session qui compte plus d'inscriptions actives que de places.

**Anomalie** :
Champ d'une inscription que l'import n'a pas pu comprendre (adresse invalide, date illisible…). L'inscription
est importée quand même ; l'anomalie dit quoi corriger dans l'outil de formulaire.
_Éviter_ : erreur, rejet

## Import

**Passage d'import** :
Une exécution de l'import qui relit toutes les inscriptions de la source. Il est **complet** quand toutes
les pages ont été lues et que le nombre de soumissions vues, lisibles ou non et chacune comptée une fois,
correspond au total annoncé par la source. Dans le code : `ImportRun`.
_Éviter_ : synchro, job

**Relevé du passage** :
Ce qu'un passage d'import a vu de la source (le total annoncé, les soumissions vues) et ce qu'il en conclut :
s'il est complet, et quelles inscriptions sont retirées. Dans le code : `RunTally`.
_Éviter_ : bilan, rapport

**Soumission illisible** :
Soumission impossible à rattacher à une inscription ou à une session (sans identifiant, sans session…).
Elle n'est pas importée mais elle est consignée dans le passage d'import.

## Lecture

**Synthèse de session** :
Ce que l'équipe opérations lit pour une session : les inscriptions actives dans l'ordre d'arrivée et leurs
comptes. Dans le code : `Roster`.
_Éviter_ : rapport, export

**À revoir** :
Inscription active que l'équipe opérations devrait regarder, avec ses raisons : doublon probable, inscrite sur
une autre session, injoignable, anomalie, commentaire.

**Injoignable** :
Inscription sans adresse e-mail valide ni téléphone.
