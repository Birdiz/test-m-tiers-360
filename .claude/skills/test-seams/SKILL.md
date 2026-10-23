---
name: test-seams
description: The three agreed test seams of this repo and how the test database is built. Use before writing, moving or reviewing a PHPUnit test, or when a test goes red after a Doctrine mapping change.
---

# Test seams

Three seams were agreed up front. A new test goes through one of them; a test that needs a fourth seam is a
design question for the user, raised before the test is written.

- **S1, the import**, observed through what the ops team reads: `SessionRoster`. Extend
  `tests/Import/ImportTestCase.php`; script the source with `FakeFormApi` (`formApi()`), then `runImport()` and
  read `roster('ses_..')`.
- **S2, field-level cleaning**: `SubmissionNormalizer`, in `tests/Import/SubmissionNormalizerTest.php`.
- **S4, the HTTP endpoint**: `GET /api/sessions/{id}/inscriptions`, in `tests/Controller/`.

Build every submission with `tests/Import/Submissions.php`, shaped like `docs/api-formulaire.md`, overriding only
the answers the test is about.

## The test database

`tests/bootstrap.php` drops the test database and rebuilds it from the migrations on every run. A Doctrine
mapping change goes red until its migration exists: generate it with
`docker compose exec app bin/console doctrine:migrations:diff` and read it before committing.

## What the seams cannot see

A faked response takes no time and the fake never changes revision mid-run. A change to retries, timeouts or
paging is proven by the `verify-import` skill against the real mock as well (ADR 0011).
