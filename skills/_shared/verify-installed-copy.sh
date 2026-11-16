#!/usr/bin/env bash
# verify-installed-copy.sh — the one check that a skill copy holds the content the default branch ships, before a
# step runs it as a trusted copy.
#
# Why this exists
#   The merge gate and the code review run some package scripts as a trusted copy, never the copy of the branch under
#   review. A trusted copy runs only in the clone that `skills/merge-github-pr/references/merge-exemptions.md`
#   *The trusted classifier* item 6 defines: the default-branch file below `skills/`, or the copy the package
#   installed below `.claude/skills/` (Claude Code) or `.agents/skills/` (Codex). This script is the only place that
#   decides whether such a file can run as a trusted copy. That section owns when a step runs it.
#
# Usage
#   verify-installed-copy.sh [--installed-reference] --root <untouched clone> --commit <default-branch commit id>
#                            <installed path>...
#   verify-installed-copy.sh --self-test
#
#   --root   the absolute path of the top of the untouched clone of merge-exemptions.md item 6. A `verified` verdict
#            proves nothing for any other root, the main worktree included.
#   --commit the full object id of the default-branch tip commit, as GitHub names it; never a ref name
#   --installed-reference  for a path below `.claude/skills/` or `.agents/skills/` whose default branch neither ships
#            nor tracks the file, the recorded hash of check 3 is the trusted reference, and check 4 is skipped. It changes
#            nothing for a path that has a default-branch blob, and nothing for a path below `skills/`. Two procedures
#            pass it, each in a clone where the package install of the default-branch tip wrote the copy and the
#            reference: the review mode of `skills/git-history-exposure-audit/SKILL.md`, whose callers are the code
#            review and the security check of `skills/merge-github-pr-fast/SKILL.md` step 2 (steps 2 and 3 of that mode),
#            and the record parser of `skills/merge-github-pr/references/trusted-record-parser.md`. The classifier of
#            the merge gate never passes it, so the low-risk path, which needs that classifier, takes the full path in
#            such a project before its security check runs.
#   <installed path>  a path relative to the root, below `skills/`, `.claude/skills/`, or `.agents/skills/`
#
#   The script reads no file beside itself, so a caller runs it from the default-branch blob:
#   `git show "${TIP:?}:skills/_shared/verify-installed-copy.sh" | bash -s -- --root ... --commit "${TIP:?}" ...`
#   Under `--installed-reference` the default branch carries no blob of this script: the caller runs the installed copy
#   of the clone and lists its path among the paths, so the script checks its own copy against the recorded hash.
#   Every git command of the script reads the objects the commit names, with replacement objects, hooks, and the
#   file-system monitor off (`GIT_NO_REPLACE_OBJECTS=1`, `core.hooksPath=/dev/null`, `core.fsmonitor=false`).
#
# What it checks
#   0. The git directory of the root has one worktree. Otherwise every path is refused with `other-worktree`.
#   Then, for every path, in this order:
#   1. No component below the root is a symbolic link: not the file and not a directory on the way to it.
#   2. The path is a regular file.
#   3. For a path below `.claude/skills/` or `.agents/skills/` only: its SHA-256 equals the hash the installer
#      recorded for that path in
#      `<git dir of the root>/ai-olympus/installed-copies.sha256`. The installer writes that reference outside the
#      working tree, so no checkout and no file of a branch changes it. A missing reference, a reference that is
#      not a regular file, and a path the reference does not list exactly once are a refusal.
#   4. It equals the default-branch blob at `skills/<path below the skills directory>` when the default branch
#      ships that file, otherwise at the given path when the default branch tracks it. No blob is a refusal, except
#      for an installed copy under `--installed-reference`, which check 3 already verified.
#
# Output (stdout)
#   One line per path: `installed-copy=verified|<path>` or `installed-copy=refused|<path>|<reason>`, where the reason
#   is one of `other-worktree`, `symlink`, `no-installed-copy`, `not-regular`, `no-reference`, `hash-mismatch`,
#   `no-default-branch-blob`, `blob-mismatch`. The last line is `installed-copy-verdict=verified` only when every path
#   passed every check; every other outcome ends with `installed-copy-verdict=refused`. A caller runs the copy only
#   after it read the verified verdict line: an empty output is a refusal too.
#
# Exit codes
#   0  every path is verified
#   1  usage error
#   2  missing required tool (git, and sha256sum or shasum)
#   3  at least one path is refused, or a git command failed

set -uo pipefail
export GIT_NO_REPLACE_OBJECTS=1
export GIT_CONFIG_COUNT=2 GIT_CONFIG_KEY_0=core.hooksPath GIT_CONFIG_VALUE_0=/dev/null \
  GIT_CONFIG_KEY_1=core.fsmonitor GIT_CONFIG_VALUE_1=false

REFERENCE_FILE='ai-olympus/installed-copies.sha256'

verdict_refused() {
  printf 'installed-copy-verdict=refused\n'
}

usage_error() {
  printf 'verify-installed-copy.sh: %s\n' "$1" >&2
  verdict_refused
  exit 1
}

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" </dev/null | awk '{print $1}'
  else
    shasum -a 256 "$1" </dev/null | awk '{print $1}'
  fi
}

# A path is relative, below a skills directory, and has no empty, `.`, or `..` component.
valid_installed_path() {
  local path="$1" component parts=()
  [[ "$path" =~ ^(\.(claude|agents)/)?skills/. ]] || return 1
  [[ "$path" != *$'\n'* ]] || return 1
  IFS='/' read -r -a parts <<<"$path"
  for component in "${parts[@]}"; do
    [[ -n "$component" && "$component" != '.' && "$component" != '..' ]] || return 1
  done
  [[ "$path" != */ && "$path" != *//* ]]
}

# Prints the refusal reason of the file checks 1 and 2, or nothing when the path passed them.
file_refusal() {
  local path="$2" current="$1" component parts=()
  IFS='/' read -r -a parts <<<"$path"
  for component in "${parts[@]}"; do
    current="$current/$component"
    if [[ -L "$current" ]]; then
      printf 'symlink'
      return
    fi
  done
  if [[ ! -e "$current" ]]; then
    printf 'no-installed-copy'
  elif [[ ! -f "$current" ]]; then
    printf 'not-regular'
  fi
}

# Prints the hash the installer recorded for the path, or nothing when the reference does not list it exactly once.
recorded_hash() {
  local reference="$1" path="$2"
  [[ -f "$reference" && ! -L "$reference" && ! -L "${reference%/*}" ]] || return 0
  awk -v path="$path" '
    length($0) > 66 && substr($0, 65, 2) == "  " && substr($0, 67) == path && substr($0, 1, 64) ~ /^[0-9a-f]+$/ {
      count++; hash = substr($0, 1, 64)
    }
    END { if (count == 1) print hash }
  ' "$reference"
}

# Prints the refusal reason of check 4, or nothing when the copy equals the default-branch blob.
blob_refusal() {
  local root="$1" commit="$2" path="$3" file="$4" source="$3" blob_path
  [[ "$path" != skills/* ]] && source="skills/${path#.*/skills/}"
  blob_path=''
  if [[ "$(git -C "$root" cat-file -t "$commit:$source" </dev/null 2>/dev/null)" == 'blob' ]]; then
    blob_path="$source"
  elif [[ "$(git -C "$root" cat-file -t "$commit:$path" </dev/null 2>/dev/null)" == 'blob' ]]; then
    blob_path="$path"
  fi
  if [[ -z "$blob_path" ]]; then
    printf 'no-default-branch-blob'
  elif ! git -C "$root" cat-file blob "$commit:$blob_path" </dev/null 2>/dev/null | cmp -s - "$file"; then
    printf 'blob-mismatch'
  fi
}

verify_main() {
  local root='' commit='' installed_reference=0 paths=()
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --installed-reference) installed_reference=1; shift ;;
      --root) [[ $# -ge 2 ]] || usage_error '--root needs a value'; root="$2"; shift 2 ;;
      --commit) [[ $# -ge 2 ]] || usage_error '--commit needs a value'; commit="$2"; shift 2 ;;
      --) shift; paths+=("$@"); break ;;
      -*) usage_error "unknown option: $1" ;;
      *) paths+=("$1"); shift ;;
    esac
  done

  [[ "$root" == /* ]] || usage_error '--root must be an absolute path'
  [[ -d "$root" ]] || usage_error '--root is not a directory'
  [[ "$commit" =~ ^([0-9a-f]{40}|[0-9a-f]{64})$ ]] || usage_error '--commit must be a full hexadecimal commit object id'
  [[ ${#paths[@]} -gt 0 ]] || usage_error 'no installed path given'
  local path
  for path in "${paths[@]}"; do
    valid_installed_path "$path" || usage_error "not an installed skills path: $path"
  done

  if ! command -v git >/dev/null 2>&1; then
    printf 'verify-installed-copy.sh: required tool not found: git\n' >&2
    verdict_refused
    exit 2
  fi
  if ! command -v sha256sum >/dev/null 2>&1 && ! command -v shasum >/dev/null 2>&1; then
    printf 'verify-installed-copy.sh: required tool not found: sha256sum or shasum\n' >&2
    verdict_refused
    exit 2
  fi

  # The root is resolved physically once. A symbolic link above the root belongs to the machine, not to the tree.
  root="$(cd -P -- "$root" 2>/dev/null && pwd -P)" || { verdict_refused; exit 3; }
  local git_dir
  git_dir="$(git -C "$root" rev-parse --absolute-git-dir </dev/null 2>/dev/null)" || git_dir=''
  if [[ -z "$git_dir" || "$(git -C "$root" rev-parse --show-toplevel </dev/null 2>/dev/null)" != "$root" ]] \
    || [[ "$(git -C "$root" rev-parse --verify --quiet --end-of-options "$commit^{commit}" </dev/null 2>/dev/null)" != "$commit" ]]; then
    printf 'verify-installed-copy.sh: the root is not the top of a git checkout, or the commit is not in it\n' >&2
    verdict_refused
    exit 3
  fi

  local refused=0 reason recorded worktrees
  worktrees="$(git -C "$root" worktree list --porcelain </dev/null 2>/dev/null | grep -c '^worktree ')"
  for path in "${paths[@]}"; do
    reason=''
    [[ "$worktrees" == 1 ]] || reason='other-worktree'
    [[ -n "$reason" ]] || reason="$(file_refusal "$root" "$path")"
    if [[ -z "$reason" && "$path" != skills/* ]]; then
      recorded="$(recorded_hash "$git_dir/$REFERENCE_FILE" "$path")"
      if [[ -z "$recorded" ]]; then
        reason='no-reference'
      elif [[ "$(sha256_of "$root/$path")" != "$recorded" ]]; then
        reason='hash-mismatch'
      fi
    fi
    if [[ -z "$reason" ]]; then
      reason="$(blob_refusal "$root" "$commit" "$path" "$root/$path")"
      [[ "$reason" == 'no-default-branch-blob' && "$installed_reference" == 1 && "$path" != skills/* ]] && reason=''
    fi
    if [[ -z "$reason" ]]; then
      printf 'installed-copy=verified|%s\n' "$path"
    else
      printf 'installed-copy=refused|%s|%s\n' "$path" "$reason"
      refused=1
    fi
  done

  if [[ "$refused" -ne 0 ]]; then
    verdict_refused
    exit 3
  fi
  printf 'installed-copy-verdict=verified\n'
  exit 0
}

verify_self_test() {
  local script tmp failures=0 checks=0
  script="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
  tmp="$(mktemp -d)"
  # shellcheck disable=SC2064
  trap "rm -rf '$tmp'" EXIT

  local main="$tmp/main" ref='refs/remotes/origin/master' file='.claude/skills/_shared/tool.sh'
  commit() { git -C "$main" -c user.name=self-test -c user.email=self-test@example.invalid commit -q "$@"; }
  tip() { git -C "$main" rev-parse --verify "$ref^{commit}"; }
  git init -q --bare "$tmp/origin.git"
  git init -q -b master "$main"
  git -C "$main" remote add origin "$tmp/origin.git"
  mkdir -p "$main/skills/_shared"
  printf '#!/usr/bin/env bash\necho package\n' >"$main/skills/_shared/tool.sh"
  printf 'tracked copy\n' >"$main/skills/_shared/other.sh"
  git -C "$main" add -A
  commit -m base
  git -C "$main" push -q origin master 2>/dev/null
  git -C "$main" fetch -q origin

  # install <relative path> <content source>: write the copy and record its hash, as the installer does.
  install_copy() {
    mkdir -p "$(dirname "$main/$1")" "$main/.git/ai-olympus"
    cp "$2" "$main/$1"
    printf '%s  %s\n' "$(sha256_of "$main/$1")" "$1" >>"$main/.git/ai-olympus/installed-copies.sha256"
  }
  reset_install() {
    rm -rf "$main/.claude" "$main/.agents" "$main/.git/ai-olympus"
    install_copy "$file" "$main/skills/_shared/tool.sh"
  }

  expect() {
    local label="$1" expected_rc="$2" expected_line="$3" output rc
    shift 3
    output="$(bash "$script" "$@" 2>/dev/null)"
    rc=$?
    checks=$((checks + 1))
    if [[ "$rc" -ne "$expected_rc" ]] || ! grep -qxF -- "$expected_line" <<<"$output"; then
      echo "FAIL: $label — expected exit $expected_rc and [$expected_line], got exit $rc: $output" >&2
      failures=$((failures + 1))
    else
      echo "ok    $label"
    fi
  }

  reset_install
  expect 'a copy that matches the recorded hash and the default-branch blob is verified' 0 'installed-copy-verdict=verified' \
    --root "$main" --commit "$(tip)" "$file"
  install_copy '.agents/skills/_shared/tool.sh' "$main/skills/_shared/tool.sh"
  expect 'the Codex copy below .agents/skills is verified the same way' 0 'installed-copy=verified|.agents/skills/_shared/tool.sh' \
    --root "$main" --commit "$(tip)" "$file" '.agents/skills/_shared/tool.sh'

  local output
  output="$(bash -s -- --root "$main" --commit "$(tip)" "$file" <"$script" 2>/dev/null)"
  checks=$((checks + 1))
  if [[ "$(tail -n 1 <<<"$output")" == 'installed-copy-verdict=verified' ]]; then
    echo 'ok    the script runs from standard input, as a caller runs the default-branch blob'
  else
    echo "FAIL: the script run from standard input printed [$output]" >&2
    failures=$((failures + 1))
  fi

  # A copy whose content differs from the recorded hash.
  reset_install
  printf '#!/usr/bin/env bash\necho replaced\n' >"$main/$file"
  expect 'a copy that differs from the recorded hash is refused' 3 "installed-copy=refused|$file|hash-mismatch" --root "$main" --commit "$(tip)" "$file"
  expect 'a refused copy ends with the refused verdict' 3 'installed-copy-verdict=refused' --root "$main" --commit "$(tip)" "$file"

  # A copy that matches its recorded hash but not the default-branch blob.
  rm -rf "$main/.claude" "$main/.git/ai-olympus"
  printf '#!/usr/bin/env bash\necho replaced\n' >"$tmp/replaced.sh"
  install_copy "$file" "$tmp/replaced.sh"
  expect 'a copy that matches its recorded hash but not the default-branch blob is refused' 3 \
    "installed-copy=refused|$file|blob-mismatch" --root "$main" --commit "$(tip)" "$file"

  # A replacement object for the default-branch blob: the check reads the blob the commit names.
  local original_blob replacement_blob default_tip
  original_blob="$(git -C "$main" rev-parse "$ref:skills/_shared/tool.sh")"
  replacement_blob="$(git -C "$main" hash-object -w "$tmp/replaced.sh")"
  git -C "$main" replace "$original_blob" "$replacement_blob"
  expect 'a replacement object for the default-branch blob does not change the verdict' 3 \
    "installed-copy=refused|$file|blob-mismatch" --root "$main" --commit "$(tip)" "$file"
  git -C "$main" replace -d "$original_blob" >/dev/null

  # The check takes the commit by object id and reads no ref: a moved local ref does not change the verdict.
  default_tip="$(tip)"
  git -C "$main" checkout -q -b moved
  cp "$tmp/replaced.sh" "$main/skills/_shared/tool.sh"
  git -C "$main" add -A skills
  commit -m moved
  git -C "$main" update-ref "$ref" "$(git -C "$main" rev-parse HEAD)"
  git -C "$main" symbolic-ref refs/remotes/origin/HEAD "$ref"
  expect 'a local ref that moved does not change the verdict for the given commit' 3 \
    "installed-copy=refused|$file|blob-mismatch" --root "$main" --commit "$default_tip" "$file"
  git -C "$main" checkout -q master
  git -C "$main" update-ref "$ref" "$default_tip"
  git -C "$main" branch -q -D moved

  # Every git command of the check runs with hooks and the file-system monitor off.
  reset_install
  mkdir -p "$tmp/fakebin"
  # shellcheck disable=SC2016 # the fake git expands the variables when it runs
  printf '#!/usr/bin/env bash\nprintf "%%s|%%s|%%s|%%s|%%s\\n" "${GIT_CONFIG_COUNT:-}" "${GIT_CONFIG_KEY_0:-}" "${GIT_CONFIG_VALUE_0:-}" "${GIT_CONFIG_KEY_1:-}" "${GIT_CONFIG_VALUE_1:-}" >>"%s"\nexec "%s" "$@"\n' \
    "$tmp/git-env" "$(command -v git)" >"$tmp/fakebin/git"
  chmod +x "$tmp/fakebin/git"
  PATH="$tmp/fakebin:$PATH" bash "$script" --root "$main" --commit "$(tip)" "$file" >/dev/null 2>&1
  checks=$((checks + 1))
  if [[ -s "$tmp/git-env" ]] && ! grep -qvxF '2|core.hooksPath|/dev/null|core.fsmonitor|false' "$tmp/git-env"; then
    echo 'ok    every git command runs with hooks and the file-system monitor off'
  else
    echo "FAIL: a git command ran without hooks and the file-system monitor off: $(sort -u "$tmp/git-env" 2>/dev/null | tr '\n' ' ')" >&2
    failures=$((failures + 1))
  fi
  rm -rf "$tmp/fakebin" "$tmp/git-env"

  # A root that shares its git directory with another worktree.
  reset_install
  git -C "$main" worktree add -q --detach "$tmp/second" 2>/dev/null
  expect 'a root whose git directory another worktree shares is refused' 3 "installed-copy=refused|$file|other-worktree" \
    --root "$main" --commit "$(tip)" "$file"
  git -C "$main" worktree remove --force "$tmp/second"

  # A file of the default branch in the checkout: no installer reference, the blob at the same path.
  reset_install
  rm -rf "$main/.git/ai-olympus"
  expect 'a default-branch file of the checkout is verified without a recorded hash' 0 'installed-copy=verified|skills/_shared/tool.sh' \
    --root "$main" --commit "$(tip)" 'skills/_shared/tool.sh'
  cp "$tmp/replaced.sh" "$main/skills/_shared/tool.sh"
  expect 'a checkout file that differs from the default-branch blob is refused' 3 'installed-copy=refused|skills/_shared/tool.sh|blob-mismatch' \
    --root "$main" --commit "$(tip)" 'skills/_shared/tool.sh'
  git -C "$main" checkout -q -- skills/_shared/tool.sh

  # A symbolic link as the file.
  reset_install
  rm "$main/$file"
  ln -s "$main/skills/_shared/tool.sh" "$main/$file"
  expect 'a copy that is a symbolic link is refused' 3 "installed-copy=refused|$file|symlink" \
    --root "$main" --commit "$(tip)" "$file"

  # A symbolic link as a directory component on the way to the file.
  reset_install
  rm -rf "$main/.claude/skills/_shared"
  ln -s ../../skills/_shared "$main/.claude/skills/_shared"
  expect 'a copy below a symbolically linked directory is refused' 3 "installed-copy=refused|$file|symlink" \
    --root "$main" --commit "$(tip)" "$file"
  reset_install
  mv "$main/.claude" "$tmp/claude-elsewhere"
  ln -s "$tmp/claude-elsewhere" "$main/.claude"
  expect 'a copy below a symbolically linked top directory is refused' 3 "installed-copy=refused|$file|symlink" \
    --root "$main" --commit "$(tip)" "$file"
  rm -f "$main/.claude"
  rm -rf "$tmp/claude-elsewhere"

  reset_install
  rm "$main/$file"
  mkdir "$main/$file"
  expect 'a path that is not a regular file is refused' 3 "installed-copy=refused|$file|not-regular" --root "$main" --commit "$(tip)" "$file"

  reset_install
  rm "$main/$file"
  expect 'a missing copy is refused' 3 "installed-copy=refused|$file|no-installed-copy" --root "$main" --commit "$(tip)" "$file"

  reset_install
  rm -rf "$main/.git/ai-olympus"
  expect 'a copy with no recorded reference is refused' 3 "installed-copy=refused|$file|no-reference" --root "$main" --commit "$(tip)" "$file"

  reset_install
  printf '%s  %s\n' "$(sha256_of "$main/$file")" "$file" >>"$main/.git/ai-olympus/installed-copies.sha256"
  expect 'a path the reference lists twice is refused' 3 "installed-copy=refused|$file|no-reference" --root "$main" --commit "$(tip)" "$file"

  reset_install
  mv "$main/.git/ai-olympus/installed-copies.sha256" "$tmp/reference"
  ln -s "$tmp/reference" "$main/.git/ai-olympus/installed-copies.sha256"
  expect 'a reference that is a symbolic link is refused' 3 "installed-copy=refused|$file|no-reference" --root "$main" --commit "$(tip)" "$file"

  reset_install
  install_copy '.claude/skills/_shared/unknown.sh' "$main/skills/_shared/tool.sh"
  expect 'a copy the default branch does not ship or track is refused' 3 \
    'installed-copy=refused|.claude/skills/_shared/unknown.sh|no-default-branch-blob' --root "$main" --commit "$(tip)" '.claude/skills/_shared/unknown.sh'

  # --installed-reference: a project whose default branch neither ships nor tracks the copy.
  expect 'with --installed-reference, a copy the default branch neither ships nor tracks is verified by its recorded hash' 0 \
    'installed-copy=verified|.claude/skills/_shared/unknown.sh' \
    --installed-reference --root "$main" --commit "$(tip)" '.claude/skills/_shared/unknown.sh'
  install_copy '.agents/skills/_shared/unknown.sh' "$main/skills/_shared/tool.sh"
  expect 'with --installed-reference, the Codex copy below .agents/skills is verified the same way' 0 'installed-copy-verdict=verified' \
    --installed-reference --root "$main" --commit "$(tip)" '.claude/skills/_shared/unknown.sh' '.agents/skills/_shared/unknown.sh'
  printf '#!/usr/bin/env bash\necho replaced\n' >"$main/.claude/skills/_shared/unknown.sh"
  expect 'with --installed-reference, a copy that differs from the recorded hash is refused' 3 \
    'installed-copy=refused|.claude/skills/_shared/unknown.sh|hash-mismatch' \
    --installed-reference --root "$main" --commit "$(tip)" '.claude/skills/_shared/unknown.sh'
  rm -rf "$main/.git/ai-olympus"
  expect 'with --installed-reference, a copy with no recorded reference is refused' 3 \
    'installed-copy=refused|.agents/skills/_shared/unknown.sh|no-reference' \
    --installed-reference --root "$main" --commit "$(tip)" '.agents/skills/_shared/unknown.sh'
  reset_install
  mkdir -p "$main/.claude/skills/_shared"
  cp "$main/skills/_shared/tool.sh" "$main/.claude/skills/_shared/unlisted.sh"
  expect 'with --installed-reference, a copy the reference does not list is refused' 3 \
    'installed-copy=refused|.claude/skills/_shared/unlisted.sh|no-reference' \
    --installed-reference --root "$main" --commit "$(tip)" '.claude/skills/_shared/unlisted.sh'
  rm -rf "$main/.claude" "$main/.git/ai-olympus"
  install_copy "$file" "$tmp/replaced.sh"
  expect 'with --installed-reference, a copy that differs from an existing default-branch blob is still refused' 3 \
    "installed-copy=refused|$file|blob-mismatch" --installed-reference --root "$main" --commit "$(tip)" "$file"
  reset_install
  cp "$main/skills/_shared/tool.sh" "$main/skills/_shared/untracked.sh"
  expect 'with --installed-reference, a skills/ path with no default-branch blob is still refused' 3 \
    'installed-copy=refused|skills/_shared/untracked.sh|no-default-branch-blob' \
    --installed-reference --root "$main" --commit "$(tip)" 'skills/_shared/untracked.sh'
  rm -f "$main/skills/_shared/untracked.sh"

  # A project that tracks the installed copy instead of shipping skills/: the blob at the installed path counts.
  reset_install
  install_copy '.claude/skills/tracked/run.sh' "$main/skills/_shared/other.sh"
  git -C "$main" add -f '.claude/skills/tracked/run.sh'
  commit -m tracked
  git -C "$main" push -q origin master 2>/dev/null
  git -C "$main" fetch -q origin
  expect 'a copy the default branch tracks at the installed path is verified' 0 'installed-copy=verified|.claude/skills/tracked/run.sh' \
    --root "$main" --commit "$(tip)" '.claude/skills/tracked/run.sh'

  expect 'a ref name in place of a commit id is a usage error' 1 'installed-copy-verdict=refused' --root "$main" --commit "$ref" "$file"
  expect 'a short commit id is a usage error' 1 'installed-copy-verdict=refused' --root "$main" --commit "$(tip | cut -c1-12)" "$file"
  expect 'a commit that is not in the checkout is refused' 3 'installed-copy-verdict=refused' \
    --root "$main" --commit '0123456789abcdef0123456789abcdef01234567' "$file"
  expect 'a path outside the skills directories is a usage error' 1 'installed-copy-verdict=refused' \
    --root "$main" --commit "$(tip)" 'src/tool.sh'
  expect 'a path with a parent component is a usage error' 1 'installed-copy-verdict=refused' \
    --root "$main" --commit "$(tip)" '.claude/skills/../../skills/_shared/tool.sh'
  expect 'a root below the top of the checkout is refused' 3 'installed-copy-verdict=refused' --root "$main/skills" --commit "$(tip)" "$file"
  expect 'a relative root is a usage error' 1 'installed-copy-verdict=refused' --root 'main' --commit "$(tip)" "$file"
  expect 'no path is a usage error' 1 'installed-copy-verdict=refused' --root "$main" --commit "$(tip)"
  mkdir -p "$tmp/plain/.claude/skills/_shared"
  GIT_CEILING_DIRECTORIES="$tmp" expect 'a root outside a git checkout is refused' 3 'installed-copy-verdict=refused' --root "$tmp/plain" --commit "$(tip)" "$file"

  if [[ "$failures" -gt 0 ]]; then
    echo "verify-installed-copy self-test: $failures failure(s) ($checks checks)" >&2
    return 4
  fi
  echo "verify-installed-copy self-test: PASS ($checks checks)"
  return 0
}

if [[ "${1:-}" == '--self-test' ]]; then
  verify_self_test
  exit $?
fi
verify_main "$@"
