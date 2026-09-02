---
title: How I gave my Laravel project an AI development team
published: false
tags: laravel, php, ai, opensource
canonical_url: https://pekral.cz/blog/ai-development-team-for-laravel
---

> **Draft — not published.** Set `published: true` only after the canonical article is live on
> pekral.cz at the URL in `canonical_url` above, so the mirror never outranks its own original.
> The demo recording is still missing; see the marker further down.

Yesterday a pull request landed in my repository that deleted 4,782 lines and added 106. Fifteen
classes gone, three test files gone, five documentation files rewritten. The suite came back with 636 tests
passing and coverage at 100%, and the code review reported zero critical and zero moderate
findings.

I wrote none of it. I wrote one sentence in a GitHub comment:

> ai-olympus bash-guard chci úplně smazat z repa!

*(I want bash-guard deleted from the repo entirely.)*

That comment contradicted the issue I had filed three minutes earlier, which had asked for three
careful fixes instead. The agent noticed the contradiction, took the newer instruction as the real
one, said so in the pull request description, and then deleted the feature.

This post is about how that setup works, why the obvious version of it does not, and what it still
cannot do.

## The problem was never the code

I kept writing the same review comments. Not interesting ones — the same ones. A method with six
parameters that should take a data object. A query built inline in a controller instead of a
repository. A `catch (\Throwable)` swallowing an error nobody would ever see again. A new test that
asserted the happy path and left the branch it was written for uncovered.

None of those needed a senior developer to catch. All of them needed a senior developer to catch,
because the rules lived in my head and in the heads of two other people, and nowhere a machine
could read them.

The usual answer is a `CLAUDE.md` file with your conventions in it. I tried that first. It works
until the second project, and then you have two files that were identical on the day you copied
one and have not been identical since. By project four you are not maintaining standards, you are
maintaining copies of standards.

## Why one big prompt does not work

The first real attempt was a single agent with a very long prompt: here are the standards, here is
the issue, implement it and review your own work.

It failed in a specific and repeatable way. The agent implemented something, then reviewed it, then
declared it good. Of course it did. It was reviewing the reasoning it had just produced, with that
reasoning still in its context. Every judgement call it made while writing was still the most
available answer when it read.

A reviewer who wrote the code is not a reviewer. That is not an AI limitation — it is why we invented
pull requests.

So the roster got split. Five agents, each with its own capability boundary, and none of them
reviewing code it wrote itself:

- **`daedalus`** resolves the source, decides the route, and dispatches. It also owns the backlog:
  it triages the open issues into a defensible order, and splits a subject too broad for one pull
  request into separately deliverable ones. It holds `Task`, `Read`, `Glob`, `Grep`, `Bash`. It
  never writes code.
- **`hephaestus`** implements. It is the only agent that holds `Write` and `Edit`.
- **`athena`** reviews — code quality, architecture, and security in one pass — and drives the fix
  loop until it converges. It holds no `Write` and no `Edit`.
- **`argus`** is the only agent that runs the application — the API over real HTTP, the UI in a real
  browser — and returns a per-criterion verdict: met, not met, partial, or blocked. It never edits
  code.
- **`hermes`** writes the human-facing report once the loop converges.

The split matters because of what each agent *cannot* do, not what it can. `athena` reviewing
`hephaestus`'s diff is reading code it did not write, with none of the author's reasoning in context.
It finds things. In the pull request above it found two, both mine, both real, and both fixed before
the review was published.

## Why a Composer plugin, not a copied file

The standards ship as a Composer package: 25 rule files and 53 skills, installed into
`.claude/rules` and `.claude/skills` by a binary.

```bash
composer require pekral/ai-olympus:dev-master --dev
vendor/bin/ai-olympus install --force
```

The reason is drift. A copied `CLAUDE.md` has no version, no changelog, and no way to tell whether
the project you opened this morning has the rule you added last week. A `composer.lock` entry has
all three. When a rule changes, `composer update` carries it, and every project that has not updated
knows exactly which version it is on.

There is a second install path as of this week, for projects without Composer:

```text
/plugin marketplace add pekral/ai-olympus
/plugin install ai-olympus@ai-olympus
```

That one has an honest gap, which is worth stating rather than glossing: Claude Code reads `skills/`
and `agents/` out of a plugin directory, but it reads neither `rules/` nor a `CLAUDE.md`. There is no
plugin mechanism for a project-scoped always-on instruction file. So the rules travel by one extra
command, `/ai-olympus:install-rules`, and the two paths are not equivalent. Composer is
still the better one on a PHP project.

## The safety design is mostly about what is refused

Three things stop this from being a machine that rewrites your repository while you are at lunch.

**Read-only agents are read-only in the frontmatter.** `athena`, `hermes`, `daedalus`, and `argus`
each carry `disallowedTools: Write, Edit`. That is enforced by the harness, not by the
agent's own good intentions. `hephaestus` carries `disallowedTools: WebSearch, WebFetch` for the
mirror-image reason: the agent that writes files has no business fetching a third-party URL.

**`composer build` is a gate, not a suggestion.** It runs the installer, then five fixers, then ten
checkers — PHPCS, Pint, Rector, PHPStan, a security audit, shell self-tests, ShellCheck, and Pest
with `--min=100`. Nothing gets pushed until it exits zero. The 100% figure is on changed lines, and
it is the reason a fix arrives with the test that proves it.

**Nothing merges on its own.** A pull request opens as a Draft, and it stays a Draft until the review
converges to zero critical findings with no undeferred moderate. The merge itself is a separate step that only
runs when I asked for it in that run — not implied by "resolve this issue".

## One complete run, start to finish

Here is the run I opened with, in full.

I filed an issue: the package shipped an optional `PreToolUse` hook that checked every Bash command
against a per-agent policy, and it kept interrupting ordinary work. Any command whose program name is
built at runtime — `"$BIN" --version`, `$(which php) -v` — came back as *ask*, and there was no way to
turn it off, because the opt-in flag had never been given an inverse. I proposed three fixes.

Then I read it again and left the one-line comment: delete it.

What the run did:

1. Read the issue, read the comment, and treated the comment as the current requirement — the issue
   body's three fixes became moot rather than being implemented anyway.
2. Deleted 15 classes, the `bash-guard` subcommand, the stdin wiring that existed only for it, four
   error factories, and the `$processExecutor` argument that existed only for the hook's
   install-time smoke run.
3. Rewrote five documents so the removal did not leave them promising a protection that no longer
   existed.
4. Ran the review. Two moderate findings, both about the removal's honesty rather than its
   correctness: the undo procedure for anyone who had installed the hook had been deleted along with
   the flag's documentation, and the changelog understated what a stale hook entry now does.
5. Verified that second finding by running it instead of reasoning about it:

   ```console
   $ php bin/ai-olympus bash-guard </dev/null
   Unknown command: bash-guard
   $ echo $?
   1
   ```

   A non-zero exit other than 2 is a non-blocking hook error, so the command still runs — but the
   error prints on *every* Bash call until the entry is deleted. That is worse than the changelog had
   said, so both the changelog and `SECURITY.md` now say it, with the cleanup steps.
6. Fixed both, re-ran the gate, published the review at 0/0/0, and merged.

```console
$ composer build
Tests:    636 passed (4576 assertions)
Total: 100.0 %
$ git show --stat e26995b | tail -1
36 files changed, 106 insertions(+), 4782 deletions(-)
```

<!-- DEMO RECORDING GOES HERE — asciinema embed, 45–75 s, see issue #1.
     Do not publish this article until the recording is in place: the paragraph
     above describes a run the reader should be able to watch. -->

The part I did not expect is step 4. The review's findings were not about the code — the deletion was
mechanical. They were about the documentation telling the truth about a consequence. That is the
review I would have skipped at 6pm on a Friday.

## What it does not do

- **Claude Code only.** There is no Cursor, Copilot, or Windsurf target. The `--editor` flag was
  removed rather than left half-supported.
- **It needs a paid Claude plan.** Five agents, a review loop that can run up to three iterations, and
  a full local build per push is not a free-tier workload.
- **The Bash boundary is advisory, not enforced.** Every agent holds `Bash`, and `Bash` subsumes both
  write access and network access no matter what `disallowedTools` says. A "read-only" agent's own
  instructions do not stop `cat > file`. The package documents this in `SECURITY.md` rather than
  implying otherwise — and the pull request I opened with is what happened when I tried to enforce it
  in code and the cure turned out to be worse than the disease.
- **It is opinionated.** PHP 8.x, Laravel conventions, Pest over PHPUnit classes, PHPStan at the
  strictest level the project's own rules allow, DTOs over associative arrays. If you disagree with
  those, you will be fighting the rules rather than using them.
- **It does not replace review.** It replaces the *first* review — the one that finds the six-parameter
  method. Someone still has to decide whether the feature was worth building.

## Try it

The repository is at
[pekral/ai-olympus](https://github.com/pekral/ai-olympus). The
rules and skills are useful on their own if you never want to run an agent — half of them are not
PHP-specific at all.

If it saves you one review cycle, a star helps other people find it.
