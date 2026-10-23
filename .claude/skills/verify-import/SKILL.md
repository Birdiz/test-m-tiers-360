---
name: verify-import
description: Start the stack and run the import end to end against the form API mock, then read what each run did. Use after changing the import, FormApi or the endpoint, before calling the change done, or when asked to run the app.
---

# Verify the import against the mock

A faked response takes no time and never changes mid-run, so green tests miss what only the mock does: that is how
the slow-page retry bug got past them (ADR 0011). This run is the proof.

## The mock

`mock-api/server.mjs` keeps its state in memory:

- page 5 answers 500 on its first call only, so only the first run after a reset retries it;
- page 8 takes 20 s on every other call, longer than the import waits for a page, so every run retries it once;
- after one full read of v1 (293 submissions) it serves v2 (303) for good: a run's `Seen` tells which it read.

`make reset-mock` replays v1 from scratch.

## Steps

1. **Pick the compose command** and pass it as `COMPOSE="…"` to every `make` call below. `make summary` curls
   from the host instead: pass it `APP_URL=http://localhost:<port the app is published on>`.
   - This worktree's stack already up (`docker compose ls` lists this worktree's folder name, with its config
     files): the same `-f` files.
   - Otherwise, ports 8000, 5432 or 8090 held by another stack (`docker ps`):
     `docker compose -f docker-compose.yml -f .claude/skills/verify-import/ports.yml`, app on 8010.
   - Otherwise, the defaults (app on 8000).
2. **Start**: `make start`, then `make runs`. An empty history means an empty database: go on. A history means
   Created, Updated and Removed will not match: ask the user before emptying the database
   (`<compose command> down -v`, then `make start` again), since it is their local data. Without their yes, run
   anyway, judge only Status, Seen, Unreadable and Retries, and report the other counts next to the table. The
   counters count source versions applied: a version older than the stored one is skipped, and a removed
   registration that reappears becomes active again without counting as updated.
3. **Reset the mock**: `make reset-mock`.
4. **Run the import three times**: `make import`, three times in a row, one call each so each run's output stays
   apart. Each exits 0. A retry is a `[notice] Retrying page N in … ms (<cause>)` line on stderr.
5. **Read the history and the endpoint**: `make runs`, then `make summary SESSION=ses_03`.

## Done when

Every line of this table matches, or the difference is reported to the user with the output that shows it:

| Run | Status | Seen | Created | Updated | Removed | Unreadable | Retries |
|---|---|---|---|---|---|---|---|
| 1 | completed | 293 | 293 | 0 | 0 | 0 | page 5 (status 500), page 8 (timed out) |
| 2 | completed | 303 | 12 | 11 | 0 | 0 | page 8 (timed out) |
| 3 | completed | 303 | 0 | 0 | 2 | 0 | page 8 (timed out) |

Run 2 removes nothing: a registration missing from the source is removed only after two complete runs in a row
miss it (ADR 0002). The last three lines of `make runs` are these runs, and `make summary` prints JSON whose `session.id` is
`ses_03` (an error body prints too, so read it). A change meant to move these numbers updates this table in the
same commit.
