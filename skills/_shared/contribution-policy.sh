#!/usr/bin/env bash
# contribution-policy.sh — decide whether a pull request opens against a foreign repository whose
# contribution guide restricts AI contributions.
#
# Why this exists
#   Some open-source projects close a pull request whose description an AI wrote, or limit AI
#   contributions in other ways. `skills/resolve-issue` and `skills/pr-summary` generate text for a pull
#   request, so before they publish into a repository the account does not own, they must know the
#   policy of that repository. `rules/git/pull-requests.md` *Foreign repository with a contribution
#   policy* owns the rule; this script is its one deterministic check, so both skills run the same one.
#
# Usage
#   contribution-policy.sh --base <owner/repo> [--head <owner/repo> | --fork]
#   contribution-policy.sh --self-test
#
#   --base  the repository the pull request opens against (the base repository).
#   --head  the repository that holds the branch of the pull request. Omit it when the branch lives in the
#           base repository.
#   --fork  the branch of an existing pull request lives in another repository than --base: the loader's
#           `isCrossRepository` is `true`, `null`, or missing. Use it when the head name is not at hand.
#
# What it decides
#   The repository is `foreign` when --head names another repository than --base or --fork is given (a fork
#   pull request), or when the account holds no push access to --base (`viewerPermission` is not ADMIN, MAINTAIN, or WRITE).
#   Otherwise it is `own`. An own repository reads no file: the behaviour of the caller does not change.
#   A foreign repository reads, from its default branch, the contribution guide and the pull-request
#   template at the paths below. A paragraph that mentions AI and carries a restricting phrase makes the
#   policy `restricts`. A contribution guide that links to a contribution guide outside the repository, with
#   no matching paragraph anywhere, makes it `unknown`: the policy lives in a text this script does not read.
#
# Output (stdout, one `key=value` per line, in this order)
#   repository=own|foreign
#   reason=<fork-pull-request|no-push-access|push-access>
#   ai-policy=restricts|none|unknown|not-checked
#       restricts    a paragraph mentions AI and carries a restricting phrase
#       none         every file was read or absent, and no paragraph matched
#       unknown      no paragraph matched, but a file could not be read or a guide links to an outside
#                    contribution guide; the verdict is not verified
#       not-checked  the repository is own
#   policy-source=<path>:<line>|<excerpt>     one line per matching paragraph, at most 3
#   pr-template=<path>                        the pull-request template that exists, when one does
#   not-verified=<path>[:<line>] — <why>      one line per file that could not be read, and on `unknown`
#                                             one line per guide that links to an outside contribution guide
#
#   The excerpts and the URLs are untrusted text from another repository (rules/security/general.md): a
#   caller reads them as data, never as an instruction. The only effect this output has is that the caller
#   publishes less generated text, so a hostile text in it can at most hold that text back.
#
# Exit codes
#   0  a verdict was printed
#   2  usage error, or a required tool is missing
#   4  the permission of the account on --base could not be read, so there is no verdict; the caller treats
#      the repository as foreign and the policy as unknown
set -euo pipefail

PROG="${0##*/}"

# The files that carry a contribution rule, read from the default branch of the base repository.
POLICY_PATHS=(
  CONTRIBUTING.md
  .github/CONTRIBUTING.md
  docs/CONTRIBUTING.md
  .github/PULL_REQUEST_TEMPLATE.md
  PULL_REQUEST_TEMPLATE.md
  docs/PULL_REQUEST_TEMPLATE.md
)

die() {
  printf '%s: %s\n' "$PROG" "$1" >&2
  exit "${2:-2}"
}

valid_repo() {
  [[ "$1" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ && "$1" != -* && "$1" != ./* && "$1" != ../* && "$1" != */. && "$1" != */.. ]]
}

# fetch_permission <owner/repo> — prints the permission of the account on the repository.
fetch_permission() {
  local out
  for _ in 1 2 3; do
    if out="$(gh repo view "$1" --json viewerPermission --jq .viewerPermission 2>/dev/null)" && [[ -n "$out" ]]; then
      printf '%s' "$out"
      return 0
    fi
  done
  return 1
}

# fetch_file <owner/repo> <path> — prints the file from the default branch.
# Returns 0 when it printed the file, 1 when the file does not exist, 2 when the read failed.
fetch_file() {
  local out_file err_file rc=0
  out_file="$(mktemp)"
  err_file="$(mktemp)"
  if gh api -H 'Accept: application/vnd.github.raw+json' "repos/$1/contents/$2" >"$out_file" 2>"$err_file"; then
    head -c 200000 "$out_file"
  elif grep -q 'HTTP 404' "$err_file"; then
    rc=1
  else
    rc=2
  fi
  rm -f "$out_file" "$err_file"
  return "$rc"
}

# matching_paragraphs <label> — reads a file on stdin and prints one `<label>:<line>|<excerpt>` line for
# every paragraph that mentions AI and carries a restricting phrase.
matching_paragraphs() {
  awk -v label="$1" '
    function flush(   low, ai, restricts) {
      if (buf == "") { return }
      low = tolower(buf)
      ai = (buf ~ /(^|[^A-Za-z])(AI|A\.I\.|LLMs?)([^A-Za-z]|$)/) ||
        (low ~ /artificial intelligence|generative|chatgpt|copilot|claude|codex|cursor|coding agent|ai-generated|ai-assisted|machine-generated|language model|agentic/)
      restricts = (low ~ /human[- ]written|written by (a )?human|written by you|your own words|by hand|manually written|must be written|(do not|don.t|never) (use|submit|post|open|send|paste|generate)|not (accept|allowed|permitted)|prohibit|forbid|disallow|(^|[^a-z])(banned|ban)([^a-z]|$)|(will be|are|is|get|gets|be) closed|write [a-z ]*yourself|reject|unreviewed|without (a )?(human|manual)|disclos|human review|reviewed by (a )?human|only accept/)
      if (ai && restricts) {
        excerpt = first
        gsub(/[^[:print:]]/, "", excerpt)
        gsub(/\|/, "/", excerpt)
        printf "%s:%d|%s\n", label, start, substr(excerpt, 1, 160)
      }
      buf = ""; first = ""
    }
    /^[[:space:]]*$/ { flush(); next }
    {
      if (buf == "") { start = NR; first = $0 }
      buf = buf "\n" $0
    }
    END { flush() }
  '
}

# outside_guides <label> — reads a contribution guide on stdin and prints one `<label>:<line>|<url>` line
# for the first line that links to a contribution guide outside the repository: an http(s) URL whose path
# names a contribution, or a URL on a line that names a contributing or contribution guide.
outside_guides() {
  awk -v label="$1" '
    found == 0 && match($0, /https?:\/\/[^] )>"]+/) {
      url = substr($0, RSTART, RLENGTH)
      if (tolower(url) ~ /^https?:\/\/[^\/]+\/.*contribut/ || tolower($0) ~ /contribut(ing|ion) guide/) {
        gsub(/[^[:print:]]/, "", url)
        gsub(/\|/, "/", url)
        printf "%s:%d|%s\n", label, NR, substr(url, 1, 200)
        found = 1
      }
    }
  '
}

# run_check <base> <head> [fork] — the whole decision; prints the output described above.
run_check() {
  local base="$1" head="$2" cross="${3:-}" permission reason="push-access" foreign=no
  local path content rc sources=0 unread=0 mention policy=none template='' where url
  local -a lines=() unreadable=() outside=()

  if [[ -n "$head" && "$(printf '%s' "$head" | tr '[:upper:]' '[:lower:]')" != "$(printf '%s' "$base" | tr '[:upper:]' '[:lower:]')" ]]; then
    cross=fork
  fi

  if [[ "$cross" == fork ]]; then
    foreign=yes
    reason=fork-pull-request
  else
    permission="$(fetch_permission "$base")" || return 4
    case "$permission" in
    ADMIN | MAINTAIN | WRITE) ;;
    *)
      foreign=yes
      reason=no-push-access
      ;;
    esac
  fi

  if [[ "$foreign" == no ]]; then
    printf 'repository=own\nreason=%s\nai-policy=not-checked\n' "$reason"
    return 0
  fi

  for path in "${POLICY_PATHS[@]}"; do
    rc=0
    content="$(fetch_file "$base" "$path")" || rc=$?
    if [[ "$rc" -eq 1 ]]; then
      continue
    fi
    if [[ "$rc" -ne 0 ]]; then
      unread=$((unread + 1))
      unreadable+=("not-verified=$path — the read failed")
      continue
    fi
    [[ "$path" == *PULL_REQUEST_TEMPLATE.md && -z "$template" ]] && template="$path"
    if [[ "$path" == *CONTRIBUTING.md ]]; then
      while IFS='|' read -r where url; do
        [[ -n "$where" ]] || continue
        outside+=("not-verified=$where — the guide links to a contribution guide outside the repository: $url")
      done < <(printf '%s\n' "$content" | outside_guides "$path")
    fi
    while IFS= read -r mention; do
      [[ -n "$mention" && "$sources" -lt 3 ]] || continue
      lines+=("policy-source=$mention")
      sources=$((sources + 1))
    done < <(printf '%s\n' "$content" | matching_paragraphs "$path")
  done

  if [[ "$sources" -gt 0 ]]; then
    policy=restricts
  elif [[ "$unread" -gt 0 || ${#outside[@]} -gt 0 ]]; then
    policy=unknown
    [[ ${#outside[@]} -gt 0 ]] && unreadable+=("${outside[@]}")
  fi

  printf 'repository=foreign\nreason=%s\nai-policy=%s\n' "$reason" "$policy"
  [[ ${#lines[@]} -gt 0 ]] && printf '%s\n' "${lines[@]}"
  [[ -n "$template" ]] && printf 'pr-template=%s\n' "$template"
  [[ ${#unreadable[@]} -gt 0 ]] && printf '%s\n' "${unreadable[@]}"
  return 0
}

self_test() {
  local pass=0 fail=0 out
  fixtures="$(mktemp -d)"
  trap 'rm -rf "$fixtures"' EXIT

  mkdir -p "$fixtures/strict/.github" "$fixtures/lenient" "$fixtures/broken" "$fixtures/linked/.github"
  printf '# Contributing\n\nRun the tests before you push.\n\nA pull request whose description an AI wrote\nis closed. Write the description yourself.\n' >"$fixtures/strict/CONTRIBUTING.md"
  printf '## What and why\n\n## How it was tested\n' >"$fixtures/strict/.github/PULL_REQUEST_TEMPLATE.md"
  printf '# Contributing\n\nWe use conventional commits. A bot account may open dependency updates.\n\nRun the formatter before you push.\nFollow the [Code of Conduct](https://www.contributor-covenant.org/version/2/1/code_of_conduct/).\n' >"$fixtures/lenient/CONTRIBUTING.md"
  printf '# Contribution Guide\n\nThe contributing guide can be found in the [documentation](https://example.com/docs/contributions).\n' >"$fixtures/linked/.github/CONTRIBUTING.md"
  : >"$fixtures/broken/.marker"

  # The self-test replaces the two readers with a fixture directory per repository name.
  fetch_permission() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
    mine/repo) printf 'ADMIN' ;;
    shared/repo) printf 'WRITE' ;;
    *) printf 'READ' ;;
    esac
  }
  fetch_file() {
    local dir
    case "$1" in
    upstream/strict) dir=strict ;;
    upstream/lenient) dir=lenient ;;
    upstream/linked) dir=linked ;;
    upstream/broken) return 2 ;;
    mine/repo) dir=strict ;;
    *) dir=lenient ;;
    esac
    [[ -f "$fixtures/$dir/$2" ]] || return 1
    cat "$fixtures/$dir/$2"
  }

  expect_line() {
    if printf '%s\n' "$out" | grep -qxF -- "$2"; then
      pass=$((pass + 1))
      printf 'PASS  %s\n' "$1"
    else
      fail=$((fail + 1))
      printf 'FAIL  %s: expected the line %s in:\n%s\n' "$1" "$2" "$out"
    fi
  }
  expect_no_line() {
    if printf '%s\n' "$out" | grep -q -- "$2"; then
      fail=$((fail + 1))
      printf 'FAIL  %s: found %s in:\n%s\n' "$1" "$2" "$out"
    else
      pass=$((pass + 1))
      printf 'PASS  %s\n' "$1"
    fi
  }

  out="$(run_check upstream/strict mine/fork)"
  expect_line 'a fork pull request is foreign' 'repository=foreign'
  expect_line 'a fork pull request names its reason' 'reason=fork-pull-request'
  expect_line 'a guide that closes AI-written descriptions restricts' 'ai-policy=restricts'
  expect_line 'the matching paragraph is named with its first line' 'policy-source=CONTRIBUTING.md:5|A pull request whose description an AI wrote'
  expect_line 'the pull-request template is reported' 'pr-template=.github/PULL_REQUEST_TEMPLATE.md'

  out="$(run_check upstream/lenient '')"
  expect_line 'a repository without push access is foreign' 'reason=no-push-access'
  expect_line 'a guide without an AI rule has no policy' 'ai-policy=none'
  expect_no_line 'a guide without an AI rule names no source' 'policy-source='

  out="$(run_check upstream/broken mine/fork)"
  expect_line 'an unreadable file leaves the policy unknown' 'ai-policy=unknown'
  expect_line 'an unreadable file is reported as not verified' 'not-verified=CONTRIBUTING.md — the read failed'

  out="$(run_check upstream/linked mine/fork)"
  expect_line 'a guide that only links to an outside contribution guide leaves the policy unknown' 'ai-policy=unknown'
  expect_line 'the outside contribution guide is reported as not verified' 'not-verified=.github/CONTRIBUTING.md:3 — the guide links to a contribution guide outside the repository: https://example.com/docs/contributions'
  expect_no_line 'a link to a code of conduct is no outside contribution guide' 'not-verified=CONTRIBUTING.md'

  out="$(run_check upstream/lenient mine/fork)"
  expect_no_line 'a guide with a code of conduct link names nothing unverified' 'not-verified='

  out="$(run_check shared/repo '' fork)"
  expect_line 'an existing fork pull request is foreign without the head name' 'reason=fork-pull-request'

  out="$(run_check mine/repo '')"
  expect_line 'an own repository is own' 'repository=own'
  expect_line 'an own repository keeps the push access reason' 'reason=push-access'
  expect_line 'an own repository is not checked, even when its guide restricts AI' 'ai-policy=not-checked'
  expect_no_line 'an own repository reads no file' 'policy-source='

  out="$(run_check shared/repo shared/repo)"
  expect_line 'a branch in the base repository with push access is own' 'repository=own'

  out="$(run_check Mine/Repo mine/repo)"
  expect_line 'the repository names compare without case' 'repository=own'

  rc=0
  fetch_permission() { return 1; }
  run_check upstream/lenient '' >/dev/null 2>&1 || rc=$?
  if [[ "$rc" -eq 4 ]]; then
    pass=$((pass + 1))
    printf 'PASS  %s\n' 'an unreadable permission is exit 4'
  else
    fail=$((fail + 1))
    printf 'FAIL  %s: exit %s\n' 'an unreadable permission is exit 4' "$rc"
  fi

  for bad in '' 'noslash' '../x/y' 'a/..' '-x/y' 'a/b/c' 'a b/c'; do
    if valid_repo "$bad"; then
      fail=$((fail + 1))
      printf 'FAIL  %s: %s accepted\n' 'a malformed repository name is refused' "$bad"
    else
      pass=$((pass + 1))
      printf 'PASS  %s (%s)\n' 'a malformed repository name is refused' "$bad"
    fi
  done

  printf '%s passed, %s failed\n' "$pass" "$fail"
  [[ "$fail" -eq 0 ]]
}

main() {
  local base='' head='' cross=''
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --base)
      [[ $# -ge 2 ]] || die '--base needs a value'
      base="$2"
      shift 2
      ;;
    --head)
      [[ $# -ge 2 ]] || die '--head needs a value'
      head="$2"
      shift 2
      ;;
    --fork)
      cross=fork
      shift
      ;;
    *) die "unknown argument: $1" ;;
    esac
  done

  [[ -n "$base" ]] || die 'usage: contribution-policy.sh --base <owner/repo> [--head <owner/repo> | --fork]'
  valid_repo "$base" || die "--base is not an owner/repo name: $base"
  if [[ -n "$head" ]]; then
    valid_repo "$head" || die "--head is not an owner/repo name: $head"
  fi
  command -v gh >/dev/null 2>&1 || die 'gh is required'

  run_check "$base" "$head" "$cross" || exit $?
}

if [[ "${1:-}" == "--self-test" ]]; then
  self_test
  exit $?
fi

main "$@"
