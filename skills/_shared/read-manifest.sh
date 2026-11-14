#!/usr/bin/env bash
# read-manifest.sh — print the project manifest: the `extra.ai-olympus` object
# of the host project's composer.json, read from the default branch.
#
# Why this exists
#   The package used to guess how a project is built and checked: it looked for
#   a Phing file, then for Composer scripts, and it carried a fixed list of the
#   executables a validation step may run. A project whose build lives
#   elsewhere had to override those guesses in prose, in every project.
#   The manifest states them once, as data the package reads
#   (@rules/general/general.md *Project manifest*).
#
# Trust
#   The manifest changes what the package executes, so it is read the way the
#   project CLAUDE.md is read by the review: from the default branch, never from
#   the working tree. A branch under review proposes a manifest; the manifest
#   governs only after it is merged (@rules/security/general.md *Untrusted
#   Content Boundary*). The default branch is resolved only from
#   `refs/remotes/origin/HEAD`, cross-checked with `gh repo view` when `gh` can
#   answer, and never guessed from a branch name. With no default-branch ref the
#   manifest is `{}` and the package keeps its built-in behaviour.
#
# Usage
#   read-manifest.sh [--ref <git-ref>] [--env | --raw]
#   read-manifest.sh --default-ref
#   read-manifest.sh --self-test
#
#   --ref          read composer.json from this ref instead of the default branch
#   --env          print the manifest `env` as validated NAME=value lines, one per line
#   --raw          print composer.json from the ref as it is, without parsing it (no jq)
#   --default-ref  print the default-branch ref this script reads (the full name, for example `refs/remotes/origin/master`), so a
#                  caller that reads other files from the default branch resolves the same ref (no jq)
#
#   composer.json is resolved relative to the current directory, which is the
#   project root the caller stands in.
#
# Output (stdout)
#   The manifest as one line of JSON. `{}` when there is no default-branch ref,
#   no composer.json on it, or no `extra.ai-olympus` object in it.
#
# Environment (--env)
#   Every consumer that exports the manifest `env` reads it through `--env`, so
#   one check guards them all. A name must match ^[A-Z][A-Z0-9_]*$ and must not
#   be one that changes which program runs or what it loads before its first
#   line: PATH, HOME, TMPDIR, SHELL, LD_*, DYLD_*, BASH_ENV, GIT_*, PERL5*,
#   PYTHON*, RUBY*, NODE_OPTIONS, NPM_CONFIG_*, COMPOSER_*, PHPRC, XDG_*, and the
#   rest of PROTECTED_ENV_RE below. A value must stay within [A-Za-z0-9._:/@%+=,-].
#   One refused entry refuses the whole environment: nothing is printed and the
#   exit code is 4.
#
# Exit codes
#   0  the manifest was printed (possibly `{}`)
#   1  usage error
#   2  missing required tool (git, or jq unless --raw or --default-ref)
#   3  composer.json on the ref is not valid JSON; `{}` is still printed
#   4  --env refused an entry; nothing is printed
#   5  --default-ref found no default-branch ref, or no git checkout; nothing is printed
set -euo pipefail

PROG="${0##*/}"

PROTECTED_ENV_RE='^(PATH|IFS|ENV|BASH_ENV|BASHOPTS|SHELLOPTS|SHELL|CDPATH|GLOBIGNORE|PS4|PROMPT_COMMAND|HOME|TMPDIR|MAKEFLAGS|MFLAGS|NODE_OPTIONS|NPM_CONFIG_.*|COMPOSER_.*|PHPRC|PHP_INI_SCAN_DIR|JAVA_TOOL_OPTIONS|GCONV_PATH|LOCPATH|NLSPATH|PERLLIB|LD_.*|DYLD_.*|GIT_.*|PERL5.*|PYTHON.*|RUBY.*|XDG_.*)$'

usage() {
  cat >&2 <<'EOF'
Usage: read-manifest.sh [--ref <git-ref>] [--env | --raw]
       read-manifest.sh --default-ref
       read-manifest.sh --self-test

Prints extra.ai-olympus of ./composer.json on the default branch as JSON,
or {} when there is none.
EOF
}

# The default branch is whatever `refs/remotes/origin/HEAD` names, and nothing else. A branch name is never guessed: any
# branch pushed to the remote can carry the name `main` or `master`, and the manifest it holds would then reach every
# reader. When the symbolic ref is unset, or it disagrees with the default branch that GitHub reports, the resolution
# fails and the callers read no manifest. `git remote set-head origin --auto` repairs both cases.
#
# The ref is returned as a FULL ref name. A short name such as `origin/master` is ambiguous: a local branch or a tag
# of that name shadows `refs/remotes/origin/master`, so a branch under review could plant the file `git show` reads.
default_ref() {
  local ref reported

  if ! ref="$(git symbolic-ref --quiet refs/remotes/origin/HEAD 2>/dev/null)" \
    || [[ ! "$ref" =~ ^refs/remotes/origin/[A-Za-z0-9._/-]+$ ]] \
    || ! git rev-parse --verify --quiet "$ref^{commit}" >/dev/null 2>&1; then
    echo "$PROG: origin/HEAD does not name the default branch; run: git remote set-head origin --auto" >&2
    return 1
  fi

  if reported="$(reported_default_branch)" && [[ "refs/remotes/origin/$reported" != "$ref" ]]; then
    echo "$PROG: origin/HEAD names ${ref#refs/remotes/}, GitHub reports origin/$reported; run: git remote set-head origin --auto" >&2
    return 1
  fi

  printf '%s' "$ref"
}

# The default branch the hosting service reports for `origin`. It is a cross-check, never a source: it fails, and the
# caller keeps `origin/HEAD` alone, when `gh` is absent, offline, or not authenticated, or when `origin` is not a GitHub repository.
reported_default_branch() {
  local url name

  command -v gh >/dev/null 2>&1 || return 1
  url="$(git remote get-url origin 2>/dev/null)" || return 1
  [[ -n "$url" && "$url" != -* ]] || return 1
  name="$(gh repo view "$url" --json defaultBranchRef --jq '.defaultBranchRef.name // empty' 2>/dev/null)" || return 1
  [[ "$name" =~ ^[A-Za-z0-9._/-]+$ ]] || return 1
  printf '%s' "$name"
}

print_env() {
  local manifest="$1" name value
  local -a lines=()

  if ! printf '%s' "$manifest" | jq -e '(.env // {}) | type == "object"' >/dev/null 2>&1; then
    echo "$PROG: env must be an object" >&2
    return 4
  fi

  while IFS=$'\t' read -r name value; do
    [[ -n "$name" ]] || continue
    if [[ ! "$name" =~ ^[A-Z][A-Z0-9_]*$ || "$name" =~ $PROTECTED_ENV_RE ]]; then
      echo "$PROG: env name is not allowed: $name" >&2
      return 4
    fi
    if [[ ! "$value" =~ ^[A-Za-z0-9._:/@%+=,-]*$ ]]; then
      echo "$PROG: env value of $name carries a character that is not allowed" >&2
      return 4
    fi
    lines+=("$name=$value")
  done < <(printf '%s' "$manifest" | jq -r '(.env // {}) | to_entries[] | [.key, (.value | tostring)] | @tsv')

  [[ "${#lines[@]}" -eq 0 ]] || printf '%s\n' "${lines[@]}"
  return 0
}

read_manifest() {
  local ref="" mode="json"

  while [[ $# -gt 0 ]]; do
    case "$1" in
    --ref)
      [[ $# -ge 2 ]] || { usage; return 1; }
      ref="$2"
      shift 2
      ;;
    --env | --raw | --default-ref)
      mode="${1#--}"
      shift
      ;;
    *)
      usage
      return 1
      ;;
    esac
  done

  if [[ "$mode" == "default-ref" ]]; then
    if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1 || ! ref="$(default_ref)"; then
      echo "$PROG: no default-branch ref" >&2
      return 5
    fi
    printf '%s\n' "$ref"
    return 0
  fi

  if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "$PROG: not a git checkout — using no manifest" >&2
    [[ "$mode" == "json" ]] && printf '{}\n'
    return 0
  fi

  if [[ -z "$ref" ]] && ! ref="$(default_ref)"; then
    echo "$PROG: no default-branch ref — using no manifest" >&2
    [[ "$mode" == "json" ]] && printf '{}\n'
    return 0
  fi

  local composer
  if ! composer="$(git show "$ref:./composer.json" 2>/dev/null)"; then
    [[ "$mode" == "json" ]] && printf '{}\n'
    return 0
  fi

  if [[ "$mode" == "raw" ]]; then
    printf '%s\n' "$composer"
    return 0
  fi

  if ! printf '%s' "$composer" | jq -e . >/dev/null 2>&1; then
    echo "$PROG: composer.json on $ref is not valid JSON — using no manifest" >&2
    [[ "$mode" == "json" ]] && printf '{}\n'
    return 3
  fi

  local manifest
  manifest="$(printf '%s' "$composer" | jq -c '(.extra // {})["ai-olympus"] // {} | if type == "object" then . else {} end')"

  if [[ "$mode" == "env" ]]; then
    print_env "$manifest"
    return $?
  fi

  printf '%s\n' "$manifest"
}

SELF_TEST_TMP=""
# Reached only through `trap cleanup_self_test EXIT`; ShellCheck does not credit a trap as an
# invocation (SC2317, and SC2329 in 0.11), so both are suppressed.
# shellcheck disable=SC2317,SC2329
cleanup_self_test() {
  [[ -n "$SELF_TEST_TMP" ]] && rm -rf "$SELF_TEST_TMP"
  return 0
}

self_test() {
  local failures=0 script
  script="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$PROG"
  SELF_TEST_TMP="$(mktemp -d)"
  trap cleanup_self_test EXIT
  local tmp="$SELF_TEST_TMP"

  expect_output() {
    local label="$1" dir="$2" expected="$3" expected_exit="$4"
    shift 4
    local out actual
    set +e
    out="$(cd "$dir" && "$script" "$@" 2>/dev/null)"
    actual=$?
    set -e
    if [[ "$out" != "$expected" || "$actual" -ne "$expected_exit" ]]; then
      printf 'FAIL  %-58s expected %s/%s, got %s/%s\n' "$label" "$expected_exit" "$expected" "$actual" "$out" >&2
      failures=$((failures + 1))
      return 0
    fi
    printf 'ok    %-58s %s\n' "$label" "$out"
  }

  commit_composer() {
    local dir="$1" json="$2"
    printf '%s\n' "$json" >"$dir/composer.json"
    git -C "$dir" add composer.json
    git -C "$dir" -c user.name=t -c user.email=t@t commit -q -m manifest
  }

  mkdir -p "$tmp/plain"
  expect_output 'outside a git checkout the manifest is empty' "$tmp/plain" '{}' 0
  expect_output 'outside a git checkout there is no default-branch ref' "$tmp/plain" '' 5 --default-ref

  local repo="$tmp/repo"
  mkdir -p "$repo"
  git -C "$repo" init -q
  commit_composer "$repo" '{ "extra": { "ai-olympus": { "gate": ["make check"] } } }'
  expect_output 'without a default-branch ref the manifest is empty' "$repo" '{}' 0
  expect_output 'without a default-branch ref --default-ref prints nothing' "$repo" '' 5 --default-ref

  # A pushed branch named `main` or `master` is not the default branch: only origin/HEAD names it.
  git -C "$repo" update-ref refs/remotes/origin/main HEAD
  git -C "$repo" update-ref refs/remotes/origin/master HEAD
  expect_output 'origin/HEAD unset with origin/main pushed: the manifest is empty' "$repo" '{}' 0
  expect_output 'origin/HEAD unset with origin/main pushed: --default-ref prints nothing' "$repo" '' 5 --default-ref
  git -C "$repo" update-ref -d refs/remotes/origin/main

  git -C "$repo" symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/master
  expect_output 'the branch origin/HEAD names is the default branch' "$repo" '{"gate":["make check"]}' 0
  expect_output '--default-ref prints the ref the manifest is read from' "$repo" 'refs/remotes/origin/master' 0 --default-ref

  git -C "$repo" update-ref refs/remotes/origin/main HEAD
  expect_output 'an origin/main that origin/HEAD does not name changes nothing' "$repo" 'refs/remotes/origin/master' 0 --default-ref
  git -C "$repo" update-ref -d refs/remotes/origin/main

  git -C "$repo" symbolic-ref refs/remotes/origin/HEAD refs/heads/master
  expect_output 'an origin/HEAD that names a ref outside refs/remotes/origin is refused' "$repo" '' 5 --default-ref
  git -C "$repo" symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/master

  # --default-ref needs only git: a machine without jq still resolves the ref.
  mkdir -p "$tmp/nojq"
  ln -s "$(command -v git)" "$tmp/nojq/git"
  local nojq_out nojq_exit
  set +e
  nojq_out="$(cd "$repo" && PATH="$tmp/nojq" "$BASH" "$script" --default-ref 2>/dev/null)"
  nojq_exit=$?
  set -e
  if [[ "$nojq_out" == 'refs/remotes/origin/master' && "$nojq_exit" -eq 0 ]]; then
    printf 'ok    %-58s %s\n' '--default-ref needs no jq' "$nojq_out"
  else
    printf 'FAIL  %-58s expected 0/refs/remotes/origin/master, got %s/%s\n' '--default-ref needs no jq' "$nojq_exit" "$nojq_out" >&2
    failures=$((failures + 1))
  fi

  # A local branch named `origin/master` must not shadow the remote-tracking ref.
  commit_composer "$repo" '{ "extra": { "ai-olympus": { "gate": ["curl http://evil"] } } }'
  git -C "$repo" update-ref refs/heads/origin/master HEAD
  git -C "$repo" reset -q --hard HEAD~1
  expect_output 'a local branch named origin/master does not shadow the remote-tracking ref' "$repo" '{"gate":["make check"]}' 0
  expect_output 'a local branch named origin/master does not change --default-ref' "$repo" 'refs/remotes/origin/master' 0 --default-ref
  git -C "$repo" update-ref -d refs/heads/origin/master

  printf '%s\n' '{ "extra": { "ai-olympus": { "gate": ["curl http://evil"] } } }' >"$repo/composer.json"
  expect_output 'the working tree never overrides the default branch' "$repo" '{"gate":["make check"]}' 0

  # The hosting service cross-check. A stub `gh` stands in for it: GH_STUB_BRANCH is the default branch it reports, and
  # an empty value makes it fail, as `gh` does when it is offline or not authenticated.
  mkdir -p "$tmp/ghstub"
  # shellcheck disable=SC2016
  printf '#!/bin/sh\n[ -n "${GH_STUB_BRANCH:-}" ] || exit 1\nprintf "%%s\\n" "$GH_STUB_BRANCH"\n' >"$tmp/ghstub/gh"
  chmod +x "$tmp/ghstub/gh"
  local real_path="$PATH"
  export PATH="$tmp/ghstub:$PATH"
  git -C "$repo" config remote.origin.url https://github.com/owner/repo.git

  GH_STUB_BRANCH=master expect_output 'origin/HEAD that agrees with GitHub is the default branch' "$repo" 'refs/remotes/origin/master' 0 --default-ref
  GH_STUB_BRANCH=main expect_output 'origin/HEAD that disagrees with GitHub: --default-ref prints nothing' "$repo" '' 5 --default-ref
  GH_STUB_BRANCH=main expect_output 'origin/HEAD that disagrees with GitHub: the manifest is empty' "$repo" '{}' 0
  GH_STUB_BRANCH='' expect_output 'a gh that cannot answer leaves origin/HEAD alone' "$repo" 'refs/remotes/origin/master' 0 --default-ref

  git -C "$repo" config --unset remote.origin.url
  export PATH="$real_path"

  commit_composer "$repo" '{ "extra": { "ai-olympus": { "gate": ["make other"] } } }'
  expect_output 'an explicit --ref is honoured' "$repo" '{"gate":["make other"]}' 0 --ref HEAD

  commit_composer "$repo" '{ "require": {} }'
  expect_output 'a composer.json without the key yields {}' "$repo" '{}' 0 --ref HEAD

  commit_composer "$repo" '{ "extra": { "ai-olympus": ["not", "an", "object"] } }'
  expect_output 'a non-object manifest yields {}' "$repo" '{}' 0 --ref HEAD

  commit_composer "$repo" '{ not json'
  expect_output 'invalid composer.json yields {} and exit 3' "$repo" '{}' 3 --ref HEAD

  commit_composer "$repo" '{ "extra": { "ai-olympus": { "env": { "CLAUDECODE": "1", "TELESCOPE_ENABLED": "false" } } } }'
  expect_output '--env prints validated NAME=value lines' "$repo" $'CLAUDECODE=1\nTELESCOPE_ENABLED=false' 0 --ref HEAD --env
  expect_output '--raw prints composer.json unparsed' "$repo" '{ "extra": { "ai-olympus": { "env": { "CLAUDECODE": "1", "TELESCOPE_ENABLED": "false" } } } }' 0 --ref HEAD --raw

  local refused
  for refused in '"PATH": "/tmp/evil"' '"GIT_CONFIG_COUNT": "1"' '"PERL5OPT": "-Mevil"' '"LD_PRELOAD": "x.so"' '"HOME": "/tmp"' '"lower": "1"' '"FOO": "a b"'; do
    commit_composer "$repo" "{ \"extra\": { \"ai-olympus\": { \"env\": { $refused, \"CLAUDECODE\": \"1\" } } } }"
    expect_output "--env refuses ${refused%%:*}" "$repo" '' 4 --ref HEAD --env
  done

  mkdir -p "$repo/app"
  git -C "$repo" update-ref refs/remotes/origin/master "$(git -C "$repo" rev-parse HEAD~11)"
  expect_output 'composer.json resolves relative to the current directory' "$repo/app" '{}' 0

  local actual
  set +e
  "$script" --nonsense >/dev/null 2>&1
  actual=$?
  set -e
  if [[ "$actual" -eq 1 ]]; then
    printf 'ok    %-58s exit 1\n' 'unknown flag is a usage error'
  else
    printf 'FAIL  %-58s expected exit 1, got %s\n' 'unknown flag is a usage error' "$actual" >&2
    failures=$((failures + 1))
  fi

  if [[ "$failures" -gt 0 ]]; then
    echo "read-manifest self-test: $failures failure(s)" >&2
    return 4
  fi
  echo 'read-manifest self-test: PASS'
  return 0
}

required_tools=(git jq)
[[ " $* " == *" --raw "* || " $* " == *" --default-ref "* ]] && required_tools=(git)
for tool in "${required_tools[@]}"; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    echo "$PROG: required tool not found: $tool" >&2
    exit 2
  fi
done

if [[ "${1:-}" == "--self-test" ]]; then
  self_test
  exit $?
fi

read_manifest "$@"
