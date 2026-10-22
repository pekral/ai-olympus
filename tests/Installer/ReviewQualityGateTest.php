<?php

declare(strict_types = 1);

function reviewQualityGateFile(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the review-state helper is shipped, executable, self-testing, and run by the build', function (): void {
    $script = dirname(__DIR__, 2) . '/skills/_shared/review-state.sh';

    expect(is_file($script))->toBeTrue()
        ->and(is_executable($script))->toBeTrue()
        ->and(reviewQualityGateFile('skills/_shared/review-state.sh'))
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('set -euo pipefail')
        ->toContain('if [[ "${1:-}" == \'--self-test\' ]]; then');

    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode(reviewQualityGateFile('composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(implode(' ', $composer['scripts']['shell-self-tests']))->toContain(' skills/_shared/review-state.sh ');
});

test('the review-state self-test covers the twelve required scenarios of issue #225', function (string $label): void {
    expect(reviewQualityGateFile('skills/_shared/review-state.sh'))->toContain($label);
})->with([
    'scenarios 1 and 2: a repeated finding and one on another line keep their identity',
    'scenario 3: two similar but distinct defects stay two findings',
    'scenario 4: a resolved finding that reappears is reopened, not new',
    'scenarios 5 and 10: a finding without evidence cannot block, and only non-blocking findings converge',
    'scenario 6: an unresolved finding whose file the delta did not touch stays open',
    'scenario 7: the review passes before reaching the round cap',
    'scenario 8: the third unsuccessful round escalates at the default cap',
    'scenario 9: no further automatic round starts after the escalation',
    'scenario 10: a loop left with only deferred and unverified findings converges',
    'scenario 11: init after an interruption resumes the existing state',
    'scenario 12: a delta on a security surface triggers the broad review',
]);

test('the review-state self-test covers every success criterion of the security plan', function (string $label): void {
    expect(reviewQualityGateFile('skills/_shared/review-state.sh'))->toContain($label);
})->with([
    'a working-tree composer.json changes neither the cap nor the threshold',
    'an environment variable does not change the cap',
    'a cap of $bad falls back to 3',
    'at cap 1 an open security low finding is a hard stop, never a deferral and never convergence',
    'the most permissive threshold leaves a Moderate blocking',
    'a security-lens $level finding maps to a blocking severity',
    'a security (S1-S3) finding that needs verification still blocks',
    'a resolved claim in report text leaves the status unchanged and the finding is reported as carried',
    'there is no command that marks a finding resolved',
    'a rejection by a CONTRIBUTOR is refused',
    'the refused rejection leaves the status open',
    'a security finding is never dismissed, even by the owner',
    'a dismissed finding the code still carries reopens instead of being suppressed',
    'a corrupted state is never converged',
    'a state that claims convergence without a round is invalid',
    'a state whose round count was lowered is invalid',
    'the resumed state keeps its round count',
    'a state outside the run directory is refused',
    'a state under .codex/run is accepted',
    'a confirmed finding is never downgraded to needs-verification by a later round',
    'the evidence is stored as text and never executed, and it does not change the cap',
    'a medium finding at or above the threshold is never deferrable',
    'a Moderate neither fixed nor deferred at the cap escalates on the final evaluation',
    'a proven finding outside the delta widens the next round to the whole PR',
    'a finding fixed in another file is resolved by the broad round and the loop converges',
    'a change after a convergence in the cap round escalates with cap-spent-after-convergence',
    'the evaluation after the refused round is escalated, never converged',
    'a round report with a finding under Findings without Evidence is refused',
    'a security point needing verification outside the delta widens the next round to the whole PR',
    'a delta round carries a security point needing verification outside the delta',
    'a full round that omits a security point needing verification resolves it',
    'a non-security point that only needed verification leaves the loop on any round',
    'a path-less blocking finding widens the next round to the whole PR',
    'a delta round carries a path-less blocking finding',
    'a full round that omits a path-less blocking finding resolves it',
    'a changed-files entry "." never puts a path-less finding inside the delta',
    'a caller-stated association is no longer accepted',
    'a rejection by an author with no association is refused',
    'agent output from an OWNER account is never a trusted rejection',
    'a comment on another pull request is refused',
    'a rejection the loader cannot load is refused',
    'the dismissal reads the association from the loaded pull request',
    'a trusted comment that does not name the finding is refused',
    'one comment dismisses only the finding it names',
]);

test('the extractor round mode keys the identity on the cause and the file and enforces the security floor', function (): void {
    $script = reviewQualityGateFile('skills/_shared/extract-findings.sh');

    expect($script)
        ->toContain('printf \'r-%s\n\' "$(sha16 "ai-olympus/finding-id/v2"$\'\n\'"$repo"$\'\n\'"$pr"$\'\n\'"$category"$\'\n\'"$cause"$\'\n\'"$file")"')
        ->toContain('(if $r.status == "dismissed" and $r.security == true then "a security finding (S1-S3) is never dismissed" else empty end)')
        ->toContain(
            '(if ($r.status == "new" or $r.status == "confirmed") and $r.evidence == null then "status \($r.status) needs evidence" else empty end)',
        )
        ->toContain('check \'round: two similar defects of one rule section in two files of one subsystem stay two identities\'')
        ->toContain('check \'round: two records with one identity keep the higher severity and the more blocking status\'')
        ->toContain('check \'round: evidence with shell metacharacters is stored as inert text and never executed\'')
        ->toContain('check \'round: evidence and impact are capped at 4000 characters with control characters removed\'')
        ->toContain('run_case \'a dismissed security (S1-S3) record is rejected by --validate\' 3 yes');
});

test('the extractor and the review state load one shared jq module for the finding definitions', function (): void {
    $module = dirname(__DIR__, 2) . '/skills/_shared/finding-defs.jq';
    $definitions = reviewQualityGateFile('skills/_shared/finding-defs.jq');

    expect(is_file($module))->toBeTrue()
        ->and(is_executable($module))->toBeFalse()
        ->and(substr_count($definitions, 'def isstr($v; $re):'))->toBe(1)
        ->and(substr_count($definitions, 'def severity_rank:'))->toBe(1)
        ->and(substr_count($definitions, 'def status_rank:'))->toBe(1);
    expect($definitions)->not->toStartWith('#!');

    foreach (['skills/_shared/extract-findings.sh' => '"$JQ_COMMON"', 'skills/_shared/review-state.sh' => '"$JQ_STATE"'] as $path => $program) {
        $script = reviewQualityGateFile($path);

        expect($script)
            ->toContain('HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"')
            ->toContain("include \"finding-defs\";\n")
            ->and(substr_count($script, '-L "$HERE"'))->toBe(substr_count($script, $program));
        expect($script)->not->toContain('def isstr(');
        expect($script)->not->toContain('def severity_rank:');
        expect($script)->not->toContain('def status_rank:');
    }
});

test('a blocking finding outside the delta widens the round, and a change after a cap-round convergence escalates', function (): void {
    expect(reviewQualityGateFile('skills/_shared/review-state.sh'))
        ->toContain('def outside_delta($changed): . as $f | ($f | is_blocking) and ($changed | index([$f.file])) == null;')
        ->toContain('map(select(. != "" and . != ".")) | unique\' "$out.raw" >"$out"')
        ->toContain('(if any(.findings[]; outside_delta($changed[0])) then "open-finding-outside-delta" else empty end) ] as $reasons')
        ->toContain('| (if $s.iteration == 0 then "full" else ($s.scope // "delta") end) as $scope')
        ->toContain('elif $scope == "delta" and $changed != null and ($f | outside_delta($changed)) then {rec: $f, from: $f.status, carried: true}')
        ->toContain('if [[ "$status" == \'converged\' && "$iteration" -ge "$cap" ]]; then')
        ->toContain('.escalation_reason = "cap-spent-after-convergence"');

    expect(reviewQualityGateFile('skills/_shared/extract-findings.sh'))
        ->toContain('Evidence is required; move an unproven point under ## Needs verification: finding')
        ->toContain('status: (if $f.verify then "needs-verification" else "new" end)} ]')
        ->toContain('run_case \'round: a finding under Findings without Evidence is refused\' 3 yes')
        ->toContain('run_case \'store mode still reads a finding without Evidence\' 0 no');

    $contract = reviewQualityGateFile('skills/process-code-review/references/review-state.md');

    expect($contract)
        ->toContain('| `open-finding-outside-delta` | A blocking finding (`new`, `confirmed`, or a security `needs-verification` point) sits in a file')
        ->toContain('A security `needs-verification` point outside the delta leaves only on a full round.')
        ->toContain('**A change after a convergence in the cap round escalates.**')
        ->toContain('or a round was recorded after the loop converged in the cap round (`cap-spent-after-convergence`)')
        ->toContain('A finding under `## Findings` without an `Evidence` field is refused');
    expect($contract)->toContain('A finding whose location names no file counts as outside every delta.');
    expect($contract)->not->toContain('is read as `needs-verification`, so the loop does not count it');
    expect(reviewQualityGateFile('skills/_shared/review-state.sh'))->not->toContain('$f.file != "."');

    expect(reviewQualityGateFile('skills/process-code-review/SKILL.md'))
        ->toContain('After a cap-round convergence, `review-state.sh record` refuses it (`cap-spent-after-convergence`): escalate.');

    expect(reviewQualityGateFile('agents/splinter.md'))
        ->toContain('the `skills/_shared/review-state.sh` verdict `escalated`, including `cap-spent-after-convergence`');
});

test('a dismissal reads the author association from the GitHub loader, never from the caller', function (): void {
    $script = reviewQualityGateFile('skills/_shared/review-state.sh');

    expect($script)
        ->toContain('LOADER="$HERE/../code-review-github/scripts/load-issue.sh"')
        ->toContain('"$LOADER" "https://github.com/$repo/pull/$pr" >"$TMP/pr.json" 2>"$TMP/pr.err"')
        ->toContain('elif ($m[0].body | type) != "string" or ($m[0].body | test("[a-z][a-z0-9-]*:actor=")) then')
        ->toContain('elif (["OWNER", "MEMBER", "COLLABORATOR"] | index([$m[0].authorAssociation])) == null then')
        ->toContain('elif ($m[0].body | contains($id) | not) then "refused:the comment does not name finding \\($id)"')
        ->toContain('association="$(rejection_association "$comment" "$id")"')
        ->toContain('isstr(.actor; "^trusted:(OWNER|MEMBER|COLLABORATOR)$")')
        ->toContain('# The scripts are copied into a temporary tree, so the GitHub loader is a fixture and not the network.');
    expect($script)->not->toContain('association="$2"');
    expect($script)->not->toContain('OPERATOR');
    expect($script)->not->toContain('ASSIGNEE');

    $contract = reviewQualityGateFile('skills/process-code-review/references/review-state.md');

    expect($contract)
        ->toContain('`review-state.sh dismiss --state <file> --finding <id> --comment <URL of the rejection comment on the pull request>`')
        ->toContain('- **The script verifies the author of a rejection; the caller never states it.**')
        ->toContain('the author of the rejection is not trusted or cannot be verified, the rejection comment does not name the `finding_id`')
        ->toContain('and when the body does not name the `finding_id` it rejects. One comment therefore dismisses only the findings it names.');
    expect($contract)->not->toContain('--association');
});

test('the round cap and the blocking threshold come only from the default-branch manifest', function (): void {
    expect(reviewQualityGateFile('rules/general/general.md'))
        ->toContain(
            '| `review.max-iterations` | int | The round cap of the review-and-fix loop in `skills/_shared/review-state.sh`. '
            . '1 to 10; any other value falls back to 3. Absent: 3. |',
        )
        ->toContain(
            'It only tightens the convergence gate: every finding with evidence blocks at every severity, and a security finding is never deferred.',
        );

    expect(reviewQualityGateFile('skills/_shared/review-state.sh'))
        ->toContain('manifest="$("$HERE/read-manifest.sh" 2>/dev/null)" || manifest=\'{}\'')
        ->toContain('DEFAULT_CAP=3')
        ->toContain('MAX_CAP=10')
        ->toContain('"$top/.claude/run/"* | "$top/.codex/run/"*) ;;');
});

test('process-code-review drives its loop through the review state and keeps comments away from the cap', function (): void {
    $skill = reviewQualityGateFile('skills/process-code-review/SKILL.md');

    expect($skill)
        ->toContain(
            'the `maxIterations` cap (the default-branch manifest key `review.max-iterations`), a finding\'s status, or what this skill may publish',
        )
        ->toContain('`skills/_shared/review-state.sh init`')
        ->toContain('Record the round with `review-state.sh record` (from round 2 on, with the delta\'s changed files).')
        ->toContain('run `review-state.sh scope` on the next delta (a `full` scope reviews the whole PR diff again)')
        ->toContain('Record each landed sub-issue with `review-state.sh defer`, then run `review-state.sh evaluate --final`.')
        ->toContain('- references/review-state.md');
    expect($skill)->not->toContain('round 3 only');
});

test('the loop contract documents a round, the configuration, the statuses, escalation, and troubleshooting', function (): void {
    $contract = reviewQualityGateFile('skills/process-code-review/references/review-state.md');

    expect($contract)
        ->toContain('**A round is one review of one head revision, recorded once with `review-state.sh record`.**')
        ->toContain('## Configuration')
        ->toContain('**The threshold only tightens the gate.**')
        ->toContain('**Only the reviewer\'s own re-read moves a finding to `needs-verification`**')
        ->toContain('| open → `dismissed` | a trusted author, through `dismiss` |')
        ->toContain('The script has no command that marks a finding resolved, and it reads no status from a report.')
        ->toContain('**A match never inherits a closed status.**')
        ->toContain('**The security floor overrides one sentence of the assignment of #225.**')
        ->toContain('**An escalation is terminal.**')
        ->toContain('Keep the state at `.claude/run/review-state/<owner>-<name>-<pr>.json` (Codex: `.codex/run/review-state/…`).')
        ->toContain('## Recovery after an interruption')
        ->toContain('## Troubleshooting');
});

test('no file cites a fixed three-round cap any more', function (string $relativePath): void {
    $content = reviewQualityGateFile($relativePath);

    expect($content)->not->toContain('maxIterations = 3');
    expect($content)->not->toContain('Round 3 — the deferral boundary');
})->with([
    'agents/leonardo.md',
    'agents/splinter.md',
    'docs/agents.md',
    'rules/compound-engineering/orchestration.md',
    'skills/process-code-review/SKILL.md',
    'skills/_shared/orchestration/dispatch-liveness.md',
]);

test('every former citation of the fixed cap names the manifest key', function (string $relativePath, string $citation): void {
    expect(reviewQualityGateFile($relativePath))->toContain($citation);
})->with([
    ['agents/splinter.md', '`maxIterations` rounds — the default-branch manifest key `review.max-iterations`, default 3'],
    ['docs/agents.md', '`maxIterations` from the manifest key `review.max-iterations`, default 3'],
    ['rules/compound-engineering/orchestration.md', '`maxIterations` from the default-branch manifest, default 3'],
    ['rules/git/pull-requests.md', 'at the cap round only (`review.max-iterations`, default 3), deferred into a tracker sub-issue'],
]);

test('a delta on a security surface widens the security lenses to the whole pull request', function (): void {
    expect(reviewQualityGateFile('rules/code-review/general.md'))
        ->toContain('- **A delta on a security surface widens the security lenses to the whole PR.**')
        ->toContain('A narrow delta cannot show a security regression at an untouched call site of a changed function.');

    $loopScope = reviewQualityGateFile('skills/process-code-review/references/review-loop-scope.md');

    expect($loopScope)
        ->toContain('- **Pass the delta\'s changed files to the loop state, and widen the round when the delta is not enough.**')
        ->toContain('or a blocking finding in a file the delta did not change (`open-finding-outside-delta`).')
        ->toContain('A delta round carries an omitted blocking finding outside the delta.')
        ->toContain('A full round resolves every finding it omits, so a full round must carry every open finding it still sees');
    expect($loopScope)->not->toContain('so a proven defect in a file the delta did not touch stays open even when the round omitted it');
});

test('the merge gate still reads Critical 0 and no undeferred Moderate from the published comment', function (): void {
    $mergeSkill = reviewQualityGateFile('skills/merge-github-pr/SKILL.md');

    expect($mergeSkill)->toContain('**0 Critical, and no undeferred Moderate**');
    expect($mergeSkill)->not->toContain('needs-verification');
    expect($mergeSkill)->not->toContain('review-state');

    foreach (['skills/code-review/templates/review-output.md', 'skills/code-review-github/templates/pr-comment-output.md'] as $template) {
        expect(reviewQualityGateFile($template))
            ->toContain('An entry here is not a finding: it never counts in the `Counts:` line')
            ->toContain('A published comment never renders this section')
            ->toContain('- **Evidence:** `failing-test` | `static-analysis` | `runtime-failure` | `exploit-path` | `logic-trace` | `rule-violation`')
            ->toContain('- **Level:** `High` | `Medium` | `Low`');
    }
});

test('the review requires evidence for every finding and keeps an unverified security point blocking', function (): void {
    expect(reviewQualityGateFile('rules/code-review/review-process.md'))
        ->toContain('- **Every finding names its evidence (issue #225).**')
        ->toContain('except a point that meets the S1–S3 security carve-out, which blocks the loop until it is settled.');

    expect(reviewQualityGateFile('skills/code-review/references/finding-records.md'))
        ->toContain('## Review-loop records (schema 2)')
        ->toContain('- **The line number and the wording never enter the identity.**')
        ->toContain('- **Conservative fallback.**');
});

test('the run metrics store the review-loop counts and codes, never their content', function (): void {
    expect(reviewQualityGateFile('skills/_shared/record-metrics.sh'))
        ->toContain('if ! review_json="$("$HERE/review-state.sh" evaluate --state "$value" 2>/dev/null)"; then')
        ->toContain('printf \'ok    %-54s\\n\' \'review-loop counters are stored as counts and codes\'');

    expect(reviewQualityGateFile('agents/splinter.md'))
        ->toContain('plus `--review-state <path>` for the loop state `leonardo` named in its handoff');
});

test('leonardo writes only the loop state under the run directory and names it in its handoff', function (): void {
    expect(reviewQualityGateFile('agents/leonardo.md'))
        ->toContain(
            'run `skills/_shared/review-state.sh` only as `@skills/process-code-review/SKILL.md` *Review loop* directs, '
            . 'which writes only the loop state under `.claude/run/review-state/`',
        )
        ->toContain('Name the loop\'s state file in your handoff on every exit, so `splinter` records its counts.');
});
