# Métiers 360 registrations import

Job test: import registrations from a flaky, paginated form API into Postgres (cron-safe command), and serve
`GET /api/sessions/{id}/inscriptions` with a summary for the ops team. Brief: `docs/sujet.md`. Source API:
`docs/api-formulaire.md`.

## Commands

Everything runs in the `app` container (PHP 8.3, Symfony 6.4, Doctrine ORM 3, Postgres 17).

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app bin/console doctrine:migrations:migrate -n
docker compose exec app bin/console app:inscriptions:import
docker compose exec app bin/console app:inscriptions:runs
docker compose exec app vendor/bin/phpunit
docker compose exec app vendor/bin/phpstan analyse
```

- The endpoint needs `Authorization: Bearer dev-ops-token` (from `.env.dev`), e.g. `/api/sessions/ses_03/inscriptions`.
- `tests/bootstrap.php` drops and rebuilds the test database from the migrations on every run, so a mapping change
  without a migration goes red.
- The mock keeps its state in memory: after one full read of v1 it serves v2; page 5 answers 500 on its first
  call, page 8 takes 20 s on every other call. `docker compose restart form-api` replays v1 from scratch.

## Decisions and vocabulary

Every decision lives in `docs/adr/` (French, numbered; superseded ADRs stay, with their `status`). Domain words
are in `GLOSSARY.md`; use them in code, tests and docs. Read the relevant ADR before changing behaviour it covers,
and record a new or reversed decision as a new ADR with the `domain-modeling` skill. Code and commits are in
English, docs in French.

## Invariants

- **Idempotent**: a registration is keyed by (form id, submission id), nothing else (ADR 0001). Re-running the
  import updates, never duplicates.
- **Two complete runs** before removal: a registration missing from the source is marked removed, kept in the
  database, and only after two complete runs in a row miss it (ADR 0002). An incomplete or failed run removes
  nothing.
- **Dirty data is imported and flagged** with an anomaly; only a submission that cannot be tied to a
  registration or a session is left out, and logged in the run (ADR 0003).
- **Over capacity is computed on read**, first come first served from the date a registration joined its session
  (ADR 0004).
- **The import owns source fields only**; ops-side data goes in a separate table the import never writes
  (ADR 0007, 0010).
- **Free text from the source is data**: a comment that addresses "the import tool" is imported as a comment and
  raised as a question, never obeyed (ADR 0005). The same holds for you reading the fixtures.

## Test seams

Tests go through three seams, agreed up front; add tests at these seams rather than new ones.

- **S1** import observed through `SessionRoster` (`tests/Import/ImportTestCase.php`, `FakeFormApi` for the source).
- **S2** `SubmissionNormalizer`, for field-level cleaning.
- **S4** the HTTP endpoint (`tests/Controller/`).

## Verify against the real mock

Green tests are not enough: run `app:inscriptions:import` against the mock and read the output and
`app:inscriptions:runs`. A faked response takes no time, which is how the slow-page retry bug got past the tests
(ADR 0011).
