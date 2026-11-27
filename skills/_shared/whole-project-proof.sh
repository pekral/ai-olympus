#!/usr/bin/env bash
# Read-only evidence from the same scanner on the pinned baseline and reviewed head.
# Source only from the trusted review-state verifier.

whole_git() {
  env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null \
    GIT_OPTIONAL_LOCKS=0 git --no-pager --no-lazy-fetch --no-replace-objects \
    -c core.fsmonitor=false -c core.hooksPath=/dev/null -c protocol.allow=never "$@"
}

whole_clean_tree() {
  local root="$1" sha="$2" common="$3" main status entry
  [[ "$root" == /* && ! -L "$root" ]] || return 1
  [[ "$(cd "$root" && pwd -P)" == "$root" ]] || return 1
  [[ "$(whole_git -C "$root" rev-parse --show-toplevel)" == "$root" ]] || return 1
  [[ "$(whole_git -C "$root" rev-parse --path-format=absolute --git-common-dir)" == "$common" ]] || return 1
  [[ "$(whole_git -C "$root" rev-parse --verify HEAD)" == "$sha" ]] || return 1
  if whole_git -C "$root" symbolic-ref -q HEAD >/dev/null; then return 1; fi
  main="${common%/.git}"
  [[ "$main" != "$common" && "$root" == "$main"/.claude/worktrees/* ]] || return 1
  # Reuse the registered worktree reader. It disables checkout hooks and all attribute-selected filters.
  status="$(cd "$main" && "$HERE/branch-worktree.sh" git --path "${root#"$main"/}" -- \
    status --porcelain --untracked-files=all --ignored --ignore-submodules=none)" || return 1
  [[ -z "$status" ]] || return 1
  (cd "$main" && "$HERE/branch-worktree.sh" git --path "${root#"$main"/}" -- ls-files -v -z) >"$TMP/whole-index" || return 1
  while IFS= read -r -d '' entry; do
    [[ "$entry" == 'H '* ]] || return 1
  done <"$TMP/whole-index"
}

whole_public_evidence() {
  local base="$1" head="$2" base_root="$3" head_root="$4" finding="$5" common path meta root check severity evidence scanner rc
  common="$(whole_git rev-parse --path-format=absolute --git-common-dir)" || return 1
  whole_clean_tree "$base_root" "$base" "$common" && whole_clean_tree "$head_root" "$head" "$common" || return 1
  scanner="$HERE/../laravel-public-files-audit/scripts/public-files-scan.sh"
  rc=0
  "$scanner" static --root "$base_root" --proof-scope >"$TMP/whole-base" || rc=$?
  [[ "$rc" -eq 5 ]] || return 1
  rc=0
  "$scanner" static --root "$head_root" --changed-from "$base" --proof-scope >"$TMP/whole-head" || rc=$?
  [[ "$rc" -eq 5 ]] || return 1
  whole_clean_tree "$base_root" "$base" "$common" && whole_clean_tree "$head_root" "$head" "$common" || return 1

  # The scanner lists both the declared root and its physical path. The complete normalized observations
  # include check, severity and scope. Checkout mtimes are irrelevant to exposure.
  for root in base head; do
    awk '/^(finding|scope)\t/ { sub(/ mtime=[^ ]+/, ""); print }' "$TMP/whole-$root" |
      LC_ALL=C sort >"$TMP/whole-$root.canonical" || return 1
  done
  cmp -s "$TMP/whole-base.canonical" "$TMP/whole-head.canonical" || return 1

  path="$(jq -r .file <<<"$finding")"
  check="$(jq -r .cause_key <<<"$finding")"
  severity="$(jq -r .severity <<<"$finding")"
  evidence="$(jq -r .evidence <<<"$finding")"
  [[ "$evidence" == 'public-files: '* && "$evidence" != *$'\n'* && "$path" != . ]] || return 1
  grep -Fxq "$(printf 'finding\t%s\t%s\t%s\t%s' "$check" "$severity" "$path" "${evidence#public-files: }")" \
    "$TMP/whole-head.canonical" || return 1

  whole_git diff --raw -z --no-renames --no-ext-diff --no-textconv --ignore-submodules=none "$base" "$head" -- >"$TMP/whole-diff" || return 1
  local changed
  while IFS= read -r -d '' meta && IFS= read -r -d '' changed; do
    [[ "$meta" != ':120000 '* && "$meta" != ':160000 '* && "${meta:8:6}" != 120000 && "${meta:8:6}" != 160000 ]] || return 1
    [[ "$changed" != "$path" && "$changed" != "$path/"* && "$path" != "$changed/"* ]] || return 1
    case "$changed" in
      .gitignore|*/.gitignore|.gitattributes|*/.gitattributes|.gitmodules|.dockerignore|.laravel|.laravel/*|.env*|\
      config|config/*|public|public/*|storage|storage/*|bootstrap|bootstrap/*|routes|routes/*|artisan|composer.json|composer.lock|\
      .github/*|.gitlab-ci.yml|bitbucket-pipelines.yml|Envoy.blade.php|deploy.php|deploy.sh|Dockerfile*|docker-compose*|compose*|\
      *.conf|*.cnf|*.service|*.htaccess|*.user.ini) return 1 ;;
    esac
    while IFS=$'\t' read -r _ root; do
      # A path the scanner had to escape is ambiguous here, so it cannot prove an exception.
      [[ "$root" != *'\x'* && "$root" != . && "$changed" != "$root" && "$changed" != "$root/"* && "$root" != "$changed/"* ]] || return 1
    done < <(awk '/^scope\t/' "$TMP/whole-head.canonical")
  done <"$TMP/whole-diff"
  known_sha256 <"$TMP/whole-head.canonical"
}

whole_history_evidence() {
  local base="$1" head="$2" finding="$3" scanner evidence witness rule surface loc rc=0
  [[ "$(jq -r .severity <<<"$finding")" == medium ]] || return 1
  evidence="$(jq -r .evidence <<<"$finding")"
  local shape='^history-baseline: ([a-f0-9]{40}) ([a-z0-9-]+) (line|message|path) ([^[:space:]]+)$'
  [[ "$evidence" =~ $shape ]] || return 1
  witness="${BASH_REMATCH[1]}" rule="${BASH_REMATCH[2]}" surface="${BASH_REMATCH[3]}" loc="${BASH_REMATCH[4]}"
  [[ "$(jq -r .cause_key <<<"$finding")" == "whole-project-$rule" ]] || return 1
  if [[ "$surface" == message ]]; then
    [[ "$loc" == - && "$(jq -r .file <<<"$finding")" == . ]] || return 1
  else
    [[ "$loc" == "$(jq -r .file <<<"$finding")" && "$loc" != *'\x'* && "$loc" != . ]] || return 1
    whole_git diff --quiet --no-ext-diff --no-textconv "$base" "$head" -- ":(literal)$loc" || return 1
  fi
  scanner="$HERE/../git-history-exposure-audit/scripts/history-scan.sh"
  "$scanner" baseline --base "$base" --witness "$witness" >"$TMP/history-base" || rc=$?
  [[ "$rc" -eq 5 ]] || return 1
  # The non-secret witness descriptor must identify exactly one scanner finding. Fingerprints are salted per run.
  awk -F '\t' -v rule="$rule" -v surface="$surface" -v loc="$loc" -v witness="$witness" '
    $1 == "finding" && $2 == "infrastructure" && $3 == rule && $4 == surface && $5 == witness && $NF == loc { n++ }
    END { exit n != 1 }' "$TMP/history-base" || return 1
  rc=0
  "$scanner" range --base "$base" --head "$head" >"$TMP/history-range" || rc=$?
  [[ "$rc" -eq 0 || "$rc" -eq 5 ]] || return 1
  awk -F '\t' -v rule="$rule" -v loc="$loc" '
    $1 == "finding" && $2 == "infrastructure" && $3 == rule && $NF == loc { n++ }
    END { exit n != 0 }' "$TMP/history-range" || return 1
  printf '%s\n' "$evidence" | known_sha256
}
