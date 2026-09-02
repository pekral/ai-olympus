<?php

declare(strict_types = 1);

/**
 * Issue #20 — the code review already judged every database statement by the rows it reads. What
 * no rule owned is the moment the statement actually runs: the deploy, while the previous release
 * still serves traffic and the table is at production size.
 *
 * These tests pin the deploy half of the database walk — the SQL rule that defines it, the CR
 * bullet that raises it with its severities and its gating against the three neighbouring storage
 * bullets, and the two skill enumerations without which the walk never runs.
 */
test('the SQL rule defines deploy-safe schema changes (issue #20)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/sql/optimalize.md');

    expect($rule)->toContain('## Deploy-safe schema changes');

    // The statement has to declare how it will run, or the server picks silently and the fallback
    // is the expensive one. Losing this phrase turns the section into "be careful with ALTER".
    expect($rule)->toContain('ALGORITHM=INPLACE, LOCK=NONE');
    expect($rule)->toContain('fail immediately');
    expect($rule)->toContain('pt-online-schema-change');
    expect($rule)->toContain('gh-ost');

    // A backfill inside the DDL migration is the second half of the defect, and the deploy order
    // is what makes the split actionable rather than a preference.
    expect($rule)->toContain('**A data backfill never rides inside the schema migration.**');
    expect($rule)->toContain('expand → backfill → contract');

    // MySQL commits DDL implicitly, which is why down() is the only rollback there is.
    expect($rule)->toContain('DDL is not transactional in MySQL');
    expect($rule)->toContain('foreign_key_checks');

    // A change nobody sized is a change nobody can schedule — the PR-description obligation.
    expect($rule)->toContain('Size the change against production, not against the local database.');
});

test('the code-review rule raises deploy-safe schema changes with severities and gating (issue #20)', function (): void {
    $rule = codeReviewRuleContents();

    expect($rule)->toContain('- **Deploy-safe schema changes (issue #20)**');

    // The bullet exists to separate the deploy lens from the row-count lens the other DB bullets
    // already carry; without this sentence a reviewer reads it as another query-cost check.
    expect($rule)->toContain('during a deploy against a production-sized table');
    expect($rule)->toContain('the query bullets below judge a statement by the rows it reads, this one judges it by the lock it takes');

    // Both severities are declared, so the Strict rule compliance default never has to guess.
    expect($rule)->toContain('for the length of a table copy');
    expect($rule)->toContain('a change MySQL performs `INSTANT`');

    // Three neighbouring bullets can fire on a migration line; the gating is what keeps one
    // violation from being reported three times.
    expect($rule)->toContain('**Gating — one finding per line, never two:** this bullet owns *how the schema statement executes during the deploy*');
    expect($rule)->toContain('a destructive migration on a populated column is that bullet\'s finding, not this one');
    expect($rule)->toContain('the fix is moving the backfill out of the migration, not only batching it');

    // Findings land in the section the DB lens already owns, not in the generic buckets.
    expect($rule)->toContain('Fold the findings into the `## Database Analysis` section alongside the `mysql-problem-solver` findings.');

    // The code-review rule set declares no `paths:` key, so this bullet loads on every project,
    // while `ALGORITHM` / `LOCK` and the online-schema tools exist only on MySQL. Without the gate
    // the walk raises a Critical against a PostgreSQL project and hands its author invalid syntax.
    expect($rule)->toContain('**Scope: MySQL / MariaDB.**');
    expect($rule)->toContain('Raise this finding with that fix only on a MySQL / MariaDB project.');
    expect($rule)->toContain('On PostgreSQL use `@skills/postgres-patterns/SKILL.md` instead');
    expect($rule)->toContain('`CREATE INDEX CONCURRENTLY` with the migration\'s wrapping transaction disabled (`public $withinTransaction = false;`)');

    // Exactly one bullet, so a later edit cannot quietly duplicate the walk into a second one, and
    // exactly one engine gate, so a second, contradicting scope line cannot creep in beside it.
    expect(substr_count($rule, 'Deploy-safe schema changes (issue #20)'))->toBe(1);
    expect(substr_count($rule, '**Scope: MySQL / MariaDB.**'))->toBe(1);
});

/**
 * Issue #67 — the bullet fires on both engines, but its closing sentence names only
 * `mysql-problem-solver`, the lens a PostgreSQL project never runs. The sentence is the owner's
 * mandate from #20 and stays verbatim, so the fix is the explanation that follows it: the section
 * is one per review, and whichever DB lens ran is the one that fills it.
 */
test('the code-review rule explains why the fold sentence stays MySQL-named on PostgreSQL (issue #67)', function (): void {
    $rule = codeReviewRuleContents();

    // The explanation is appended, never a rewrite: the #20 mandate is still the sentence directly
    // above it, so a later edit cannot swap the mandate out and keep this test green.
    expect($rule)->toContain(
        '`mysql-problem-solver` findings.' . "\n"
        . 'That sentence names `mysql-problem-solver` because it quotes the issue #20 mandate verbatim, '
        . 'not because the destination changes with the engine.',
    );

    // Without this, a reviewer on a PostgreSQL project is told to fold the finding alongside the
    // findings of a lens that, by the mutually exclusive branching, never produced any.
    expect($rule)->toContain('On a PostgreSQL project that lens is `postgres-patterns`, and `mysql-problem-solver` never runs at all');
    expect($rule)->toContain('Read the sentence above as *alongside the findings of the DB lens this review actually ran*.');

    // One `## Database Analysis` section per review is the whole point of the explanation; a second
    // wording claiming a per-engine section would put the two sentences back in conflict.
    expect($rule)->toContain(
        'A review has exactly one `## Database Analysis` section, and the lens that fills it is the one the engine resolution selected.',
    );
});

test('the code-review and mysql skills run the deploy-safety walk they inherit (issue #20)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/code-review/SKILL.md');
    $mysql = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/SKILL.md');

    // The Core Analysis walk-through is enumerated by name in the skill, so a rule section absent
    // from that list is a rule the review never reaches.
    expect($skill)->toContain('**deploy-safe schema changes (issue #20)**');

    // mysql-problem-solver is the lens the CR hands every migration to; without a diagnosis entry
    // it inspects the query shape and never the lock the statement takes.
    expect($mysql)->toContain('- schema changes that block during a deploy');
    expect($mysql)->toContain('- online schema change for a blocking DDL statement');
    expect($mysql)->toContain('"Deploy-safe schema changes"');

    // The engine gate above redirects a PostgreSQL project here, so the target has to carry the
    // answer it promises — a redirect to guidance that does not exist is worse than no redirect.
    $postgres = (string) file_get_contents($packageDir . '/skills/postgres-patterns/SKILL.md');

    expect($postgres)->toContain('CREATE INDEX CONCURRENTLY');
    expect($postgres)->toContain('public $withinTransaction = false;');
});
