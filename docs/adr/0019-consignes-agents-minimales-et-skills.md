# Les consignes pour agents tiennent en une page ; les procédures et la référence passent dans des skills

`CLAUDE.md` est chargé à chaque tour : chaque ligne y coûte de l'attention à l'agent, qu'elle serve ou non à la
tâche en cours. On suit la doctrine de Matt Pocock (skill `writing-for-agents`) : il ne garde que ce dont toute
tâche a besoin (le contexte, où sont les décisions et le vocabulaire, la règle sur le texte libre, quand un
changement est fini), et ce qui ne sert qu'à certaines tâches passe derrière un pointeur. Deux skills du projet,
dans `.claude/skills/` :

- `verify-import` : faire tourner l'import contre le simulateur, y compris à côté de la pile d'une autre
  worktree, avec le comportement du simulateur et ce que chaque passage doit montrer ;
- `test-seams` : les trois points d'entrée des tests convenus (S1, S2, S4) et la base de test reconstruite à
  chaque lancement.

## Considered Options

- Garder les invariants dans `CLAUDE.md` : ils répétaient les ADR 0001 à 0010, et deux textes pour une même règle
  finissent par diverger. `CLAUDE.md` renvoie aux ADR par les situations qui les touchent (identifier, retirer,
  signaler, classer, écrire) ; les tests du point S1 gardent les invariants.
- Recopier les commandes `docker compose` : le `Makefile` en est la source, et `make` les liste.
- Des fichiers `docs/agents/*.md` pointés depuis `CLAUDE.md` : même coût en contexte (une ligne), mais la
  vérification est une suite d'étapes avec un critère de fin, et une skill peut être appelée par une autre
  (`run`, `tdd`, `code-review`).
- Un `AGENTS.md` en plus : un seul fichier de consignes, pour qu'une règle n'ait qu'une source.

## Consequences

- Les invariants ne sont plus sous les yeux de l'agent à chaque tour. S'il change un comportement sans lire l'ADR,
  ce sont les tests qui l'arrêtent.
- La description d'une skill reste chargée en permanence : sa formulation décide si l'agent la trouve. Une skill
  qui ne se déclenche pas quand il le faut se corrige d'abord par sa description.
