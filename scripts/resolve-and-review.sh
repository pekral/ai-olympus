#!/usr/bin/env bash
#
# resolve-and-review.sh — pipeline template for the standard task-resolution flow.
#
# Purpose
#   Guarantee that a task is always processed through the same skills, in the same
#   order, so the flow is fast, predictable, and cheap on tokens:
#
#       1. /resolve-issue          implement the change and open a PR   (always)
#       2. /code-review-<tracker>  a fresh code-review round on the PR  (always)
#       3. /process-code-review    resolve the findings                 (only when the CR round found any)
#       4. /merge-github-pr        merge into the base branch           (only with --merge)
#
# This is a TEMPLATE / WRAPPER: it validates the input, detects the source tracker,
# and prints the exact skills to run in order. It intentionally does NOT invoke the
# `claude` binary or any tracker CLI — you run each printed skill inside your Claude
# Code session (or `@`-mention the matching specialist agent). Nothing here mutates
# the repository, the tracker, or any remote.
#
# Usage
#   scripts/resolve-and-review.sh "<issue-ref|text>"
#   scripts/resolve-and-review.sh --merge "<issue-ref|text>"
#   scripts/resolve-and-review.sh --help
#
#   <issue-ref|text>  A GitHub issue/PR URL, a JIRA key/URL, a Bugsnag error URL,
#                     or a free-form description of the task.
#   --merge           Also print the /merge-github-pr step (merge is opt-in).

set -euo pipefail

print_usage() {
    cat <<'USAGE'
resolve-and-review.sh — print the ordered skill pipeline for a task.

Usage:
  scripts/resolve-and-review.sh "<issue-ref|text>"
  scripts/resolve-and-review.sh --merge "<issue-ref|text>"
  scripts/resolve-and-review.sh --help

Arguments:
  <issue-ref|text>   GitHub issue/PR URL, JIRA key/URL, Bugsnag error URL, or a
                     free-form task description.

Options:
  --merge            Also print the final /merge-github-pr step (merge is opt-in).
  --help, -h         Show this help and exit.

This wrapper only PRINTS the steps — it never runs `claude`, `gh`, `acli`, or any
other command that would change code, the tracker, or a remote.
USAGE
}

# Detect the source tracker from the assignment reference.
# Echoes one of: github | jira | bugsnag | text
detect_tracker() {
    local input="$1"

    if [[ "$input" == *"atlassian.net"* ]] || [[ "$input" =~ ^[A-Z][A-Z0-9]+-[0-9]+$ ]]; then
        echo "jira"
    elif [[ "$input" == *"bugsnag.com"* ]]; then
        echo "bugsnag"
    elif [[ "$input" == *"github.com"* ]] || [[ "$input" =~ ^#?[0-9]+$ ]]; then
        echo "github"
    else
        echo "text"
    fi
}

main() {
    local merge="false"
    local input=""

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --merge)
                merge="true"
                shift
                ;;
            --help | -h)
                print_usage
                exit 0
                ;;
            --*)
                echo "error: unknown option '$1'" >&2
                echo "run with --help for usage." >&2
                exit 1
                ;;
            *)
                if [[ -n "$input" ]]; then
                    echo "error: expected a single <issue-ref|text> argument (got extra: '$1')." >&2
                    echo "quote a multi-word description: scripts/resolve-and-review.sh \"fix the upload bug\"" >&2
                    exit 1
                fi
                input="$1"
                shift
                ;;
        esac
    done

    if [[ -z "$input" ]]; then
        echo "error: missing <issue-ref|text> argument." >&2
        echo "" >&2
        print_usage >&2
        exit 1
    fi

    local tracker
    tracker="$(detect_tracker "$input")"

    # The code-review step routes to the tracker-matching wrapper skill. Its argument
    # differs by tracker: code-review-github reviews a GitHub <PR>, while code-review-jira
    # and code-review-bugsnag take the original tracker reference and resolve the linked PR
    # themselves. A free-form text assignment has no tracker, so its PR is reviewed with
    # code-review-github.
    local cr_skill
    local cr_arg
    local cr_note=""
    case "$tracker" in
        jira)
            cr_skill="/code-review-jira"
            cr_arg="${input}"
            cr_note=" (JIRA reference — code-review-jira resolves the linked PR itself)"
            ;;
        bugsnag)
            cr_skill="/code-review-bugsnag"
            cr_arg="${input}"
            cr_note=" (Bugsnag reference — code-review-bugsnag resolves the linked PR itself)"
            ;;
        github)
            cr_skill="/code-review-github"
            cr_arg="<PR>"
            ;;
        text)
            cr_skill="/code-review-github"
            cr_arg="<PR>"
            cr_note=" (no tracker detected — the PR is a GitHub PR, so it reviews with code-review-github)"
            ;;
    esac

    cat <<PLAN
Task-resolution pipeline
========================
Assignment : ${input}
Tracker    : ${tracker}
Merge      : ${merge}

Run these skills IN ORDER inside your Claude Code session. Wait for each step to
finish before starting the next — every step feeds the one after it.

  1. /resolve-issue ${input}
       Implement the change and open the PR. A PR is ALWAYS created (unless you
       explicitly opt out inside the skill). Note the PR URL it reports — the next
       steps operate on it. Substitute it for <PR> below.

  2. ${cr_skill} ${cr_arg}${cr_note}
       Run a fresh code-review round on the PR opened in step 1.

  3. /process-code-review <PR>
       ONLY when step 2 reported findings. Resolve them and iterate the CR loop to
       0 Critical, no undeferred Moderate. Skip this step when step 2 was already clean.
PLAN

    if [[ "$merge" == "true" ]]; then
        cat <<'PLAN'

  4. /merge-github-pr <PR>
       Merge the PR into the base branch. Runs only because --merge was passed, and
       only once the code review has converged (0 Critical, no undeferred Moderate).
PLAN
    else
        cat <<'PLAN'

  (merge step skipped — pass --merge to include /merge-github-pr as step 4.)
PLAN
    fi
}

main "$@"
