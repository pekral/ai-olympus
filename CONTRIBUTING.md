# Contributing to AI Olympus

Thank you for your interest in improving this project. This guide covers how to set up the repository, what the quality gate actually checks, and the conventions your pull request is expected to follow.

## Getting started

1. Fork the repository and clone your fork.
2. Install dependencies: `composer install`.
3. (Optional, for Claude Code users) Sync this package's own rules/skills into the repository's `.claude/` directory: `vendor/bin/ai-olympus install --force`.

## The quality gate: `composer build`

Once the work is finished and the review has converged, run:

```bash
composer build
```

This is the authoritative gate — see the `scripts` section of `composer.json` for the exact commands. It runs, in order:

1. `bin/ai-olympus install --force --allow-subagent-writes` — reinstalls this package's own rules/skills (the repository dogfoods its own installer).
2. `@fix` — auto-fixes: `skill-check-fix`, `composer-normalize-fix`, `rector-fix`, `pint-fix`, `phpcs-fix`.
3. `@check` — the full check suite, which must pass with **zero errors**:
   - `skill-check` — the `SKILL.md` linter (`npx skill-check check skills --no-security-scan`); required whenever a change touches a `skills/**/SKILL.md` file.
   - `composer-normalize-check` — `composer.json` normalization (dry-run).
   - `phpcs-check` — PHP CodeSniffer.
   - `pint-check` — Laravel Pint.
   - `rector-check` — Rector (dry-run).
   - `analyse` — PHPStan static analysis.
   - `security-audit` — `composer audit`.
   - `shell-self-tests` — self-tests for the shared shell scripts under `skills/_shared/`.
   - `test:coverage` — the Pest test suite with **100% code coverage required** (`--min=100`, via PCOV).

See `README.md` → *Development & Testing* for the individual commands (`composer check`, `composer fix`, `composer phpcs-check`, …) if you want to run a single step instead of the full gate.

**CI is not the same gate.** `.github/workflows/pr.yml` runs only a subset of `@check` — `security-audit`, `phpcs-check`, `pint-check`, `rector-check`, `analyse`, and `test:coverage`. It does **not** run `skill-check` or `composer-normalize-check`. A green CI check on your PR is therefore not proof that the full `composer build` passes — run it locally before you mark the pull request ready.

## Adding or changing a skill

If your change adds a new Agent skill under `skills/`, use the `skill-creator` skill (`skills/skill-creator/SKILL.md`) to scaffold it — it generates a `SKILL.md` that already follows this repository's conventions and the `skill-check.config.json` limits, and reminds you to update the README skill count and the changelog. If you are changing an existing skill, run `composer skill-check` after editing its `SKILL.md`.

## What's actually version-controlled

Contributions target `skills/`, `rules/`, `agents/`, `src/`, `tests/`, `docs/`, and the root project files (`README.md`, `CLAUDE.md`, `composer.json`, etc.) — these are the tracked directories and files. `.claude/`, `.cursor/`, and `.codex/` are local, machine-generated Claude Code / Cursor / Codex artifacts listed in `.gitignore`; changes there are never part of a pull request.

## Commits and pull requests

Follow `rules/git/general.md`:

- Commit format: `type(scope): description` (`feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`), lowercase `type`/`scope`, no trailing period.
- Commit messages and PR titles are always in **English**, regardless of the language the issue or PR description is written in.
- Never include AI co-author trailers (no `Co-Authored-By:` lines, no "Generated with …" notes).
- Put the literal `Closes #123` in the **pull request body**, not only in a commit message — GitHub reads the link off the body, and every review skill here reads it long before the merge.
- Open the PR as a **Draft** until the code review has converged (0 Critical + 0 Moderate findings) — this repository's review/fix loop promotes it out of Draft once it's ready to merge.
- The merge strategy is rebase-and-merge.

## Changelog

Add one bullet to `CHANGELOG.md` under `## [Unreleased]`, matching the existing format: an emoji, a bold category (`**Added**`, `**Changed**`, `**Fixed**`, `**Removed**`, …), a short description, ending with `Per issue #N.` (or an equivalent short attribution if there is no tracked issue).

## Reporting bugs and requesting features

Please use the issue templates (offered automatically when you open a new issue) so bug reports include the environment details needed to reproduce them.

## Reporting a security vulnerability

Do **not** open a public issue for a security vulnerability — see [SECURITY.md](SECURITY.md) for the private reporting process.

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold it.
