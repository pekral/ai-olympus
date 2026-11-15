#!/usr/bin/env bash
# verify-installed-copy.sh — the one check that an installed skill copy is the copy the package installed and the
# copy the default branch ships, before a step runs it as a trusted copy.
#
# Why this exists
#   The merge gate and the code review run some package scripts as a trusted copy: the installed copy under
#   `.claude/skills/` (Claude Code) or `.agents/skills/` (Codex) of the main worktree, never the copy of the branch
#   under review. A file in the main worktree is a trusted copy only while it holds the package content. A symbolic
#   link makes the content follow whatever the working tree holds, and code that runs in that tree can rewrite the
#   file. This script is the only place that decides whether an installed copy can run as a trusted copy.
#   `skills/merge-github-pr/references/merge-exemptions.md` *The trusted classifier* owns when a step runs it.
#
# Usage
#   verify-installed-copy.sh --root <main worktree> --ref <refs/remotes/origin/<branch>> <installed path>...
#   verify-installed-copy.sh --self-test
#
#   --root   the absolute path of the main worktree, the parent of `git rev-parse --path-format=absolute
#            --git-common-dir`
#   --ref    the fully qualified default-branch ref (`refs/remotes/origin/<branch>`), never a short name
#   <installed path>  a path relative to the root, below `.claude/skills/` or `.agents/skills/`
#
#   The script reads no file beside itself, so a caller runs it from the default-branch blob:
#   `git show "${REF:?}:skills/_shared/verify-installed-copy.sh" | bash -s -- --root ... --ref "${REF:?}" ...`
#
# What it checks, for every path, in this order
#   1. No component below the root is a symbolic link: not the file and not a directory on the way to it.
#   2. The path is a regular file.
#   3. Its SHA-256 equals the hash the installer recorded for that path in
#      `<git dir of the root>/ai-olympus/installed-copies.sha256`. The installer writes that reference outside the
#      working tree, so no checkout and no file of a branch changes it. A missing reference, a reference that is
#      not a regular file, and a path the reference does not list exactly once are a refusal.
#   4. It equals the default-branch blob at `skills/<path below the skills directory>` when the default branch
#      ships that file, otherwise at the installed path when the default branch tracks it. No blob is a refusal.
#
# Output (stdout)
#   One line per path: `installed-copy=verified|<path>` or `installed-copy=refused|<path>|<reason>`, where the reason
#   is one of `symlink`, `no-installed-copy`, `not-regular`, `no-reference`, `hash-mismatch`,
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

# A path is relative, below an installed skills directory, and has no empty, `.`, or `..` component.
valid_installed_path() {
  local path="$1" component parts=()
  [[ "$path" =~ ^\.(claude|agents)/skills/. ]] || return 1
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
  local root="$1" ref="$2" path="$3" file="$4" source blob_path
  source="skills/${path#.*/skills/}"
  blob_path=''
  if [[ "$(git -C "$root" cat-file -t "$ref:$source" </dev/null 2>/dev/null)" == 'blob' ]]; then
    blob_path="$source"
  elif [[ "$(git -C "$root" cat-file -t "$ref:$path" </dev/null 2>/dev/null)" == 'blob' ]]; then
    blob_path="$path"
  fi
  if [[ -z "$blob_path" ]]; then
    printf 'no-default-branch-blob'
  elif ! git -C "$root" cat-file blob "$ref:$blob_path" </dev/null 2>/dev/null | cmp -s - "$file"; then
    printf 'blob-mismatch'
  fi
}

verify_main() {
  local root='' ref='' paths=()
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --root) [[ $# -ge 2 ]] || usage_error '--root needs a value'; root="$2"; shift 2 ;;
      --ref) [[ $# -ge 2 ]] || usage_error '--ref needs a value'; ref="$2"; shift 2 ;;
      --) shift; paths+=("$@"); break ;;
      -*) usage_error "unknown option: $1" ;;
      *) paths+=("$1"); shift ;;
    esac
  done

  [[ "$root" == /* ]] || usage_error '--root must be an absolute path'
  [[ -d "$root" ]] || usage_error '--root is not a directory'
  [[ "$ref" =~ ^refs/remotes/origin/[A-Za-z0-9._/-]+$ && "$ref" != *..* ]] \
    || usage_error '--ref must be a fully qualified refs/remotes/origin/<branch> ref'
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
    || ! git -C "$root" rev-parse --verify --quiet "$ref^{commit}" </dev/null >/dev/null 2>&1; then
    printf 'verify-installed-copy.sh: the root is not the top of a git checkout, or the ref does not resolve to a commit\n' >&2
    verdict_refused
    exit 3
  fi

  local refused=0 reason recorded
  for path in "${paths[@]}"; do
    reason="$(file_refusal "$root" "$path")"
    if [[ -z "$reason" ]]; then
      recorded="$(recorded_hash "$git_dir/$REFERENCE_FILE" "$path")"
      if [[ -z "$recorded" ]]; then
        reason='no-reference'
      elif [[ "$(sha256_of "$root/$path")" != "$recorded" ]]; then
        reason='hash-mismatch'
      else
        reason="$(blob_refusal "$root" "$ref" "$path" "$root/$path")"
      fi
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
    --root "$main" --ref "$ref" "$file"
  install_copy '.agents/skills/_shared/tool.sh' "$main/skills/_shared/tool.sh"
  expect 'the Codex copy below .agents/skills is verified the same way' 0 'installed-copy=verified|.agents/skills/_shared/tool.sh' \
    --root "$main" --ref "$ref" "$file" '.agents/skills/_shared/tool.sh'

  local output
  output="$(bash -s -- --root "$main" --ref "$ref" "$file" <"$script" 2>/dev/null)"
  checks=$((checks + 1))
  if [[ "$(tail -n 1 <<<"$output")" == 'installed-copy-verdict=verified' ]]; then
    echo 'ok    the script runs from standard input, as a caller runs the default-branch blob'
  else
    echo "FAIL: the script run from standard input printed [$output]" >&2
    failures=$((failures + 1))
  fi

  # Replaced content: code that ran in the tree rewrote the copy after the install.
  reset_install
  printf '#!/usr/bin/env bash\necho replaced\n' >"$main/$file"
  expect 'a copy rewritten after the install is refused' 3 "installed-copy=refused|$file|hash-mismatch" --root "$main" --ref "$ref" "$file"
  expect 'a refused copy ends with the refused verdict' 3 'installed-copy-verdict=refused' --root "$main" --ref "$ref" "$file"

  # Replaced content and reference together: the default-branch blob still refuses the copy.
  rm -rf "$main/.claude" "$main/.git/ai-olympus"
  printf '#!/usr/bin/env bash\necho replaced\n' >"$tmp/replaced.sh"
  install_copy "$file" "$tmp/replaced.sh"
  expect 'a copy and a reference rewritten together are refused by the default-branch blob' 3 \
    "installed-copy=refused|$file|blob-mismatch" --root "$main" --ref "$ref" "$file"

  # A symbolic link as the file: the content follows the working tree.
  reset_install
  rm "$main/$file"
  ln -s "$main/skills/_shared/tool.sh" "$main/$file"
  expect 'a copy that is a symbolic link into the working tree is refused' 3 "installed-copy=refused|$file|symlink" \
    --root "$main" --ref "$ref" "$file"

  # A symbolic link as a directory component on the way to the file.
  reset_install
  rm -rf "$main/.claude/skills/_shared"
  ln -s ../../skills/_shared "$main/.claude/skills/_shared"
  expect 'a copy below a symbolically linked directory is refused' 3 "installed-copy=refused|$file|symlink" \
    --root "$main" --ref "$ref" "$file"
  reset_install
  mv "$main/.claude" "$tmp/claude-elsewhere"
  ln -s "$tmp/claude-elsewhere" "$main/.claude"
  expect 'a copy below a symbolically linked top directory is refused' 3 "installed-copy=refused|$file|symlink" \
    --root "$main" --ref "$ref" "$file"
  rm -f "$main/.claude"
  rm -rf "$tmp/claude-elsewhere"

  reset_install
  rm "$main/$file"
  mkdir "$main/$file"
  expect 'a path that is not a regular file is refused' 3 "installed-copy=refused|$file|not-regular" --root "$main" --ref "$ref" "$file"

  reset_install
  rm "$main/$file"
  expect 'a missing copy is refused' 3 "installed-copy=refused|$file|no-installed-copy" --root "$main" --ref "$ref" "$file"

  reset_install
  rm -rf "$main/.git/ai-olympus"
  expect 'a copy with no recorded reference is refused' 3 "installed-copy=refused|$file|no-reference" --root "$main" --ref "$ref" "$file"

  reset_install
  printf '%s  %s\n' "$(sha256_of "$main/$file")" "$file" >>"$main/.git/ai-olympus/installed-copies.sha256"
  expect 'a path the reference lists twice is refused' 3 "installed-copy=refused|$file|no-reference" --root "$main" --ref "$ref" "$file"

  reset_install
  mv "$main/.git/ai-olympus/installed-copies.sha256" "$tmp/reference"
  ln -s "$tmp/reference" "$main/.git/ai-olympus/installed-copies.sha256"
  expect 'a reference that is a symbolic link is refused' 3 "installed-copy=refused|$file|no-reference" --root "$main" --ref "$ref" "$file"

  reset_install
  install_copy '.claude/skills/_shared/unknown.sh' "$main/skills/_shared/tool.sh"
  expect 'a copy the default branch does not ship or track is refused' 3 \
    'installed-copy=refused|.claude/skills/_shared/unknown.sh|no-default-branch-blob' --root "$main" --ref "$ref" '.claude/skills/_shared/unknown.sh'

  # A project that tracks the installed copy instead of shipping skills/: the blob at the installed path counts.
  reset_install
  install_copy '.claude/skills/tracked/run.sh' "$main/skills/_shared/other.sh"
  git -C "$main" add -f '.claude/skills/tracked/run.sh'
  commit -m tracked
  git -C "$main" push -q origin master 2>/dev/null
  git -C "$main" fetch -q origin
  expect 'a copy the default branch tracks at the installed path is verified' 0 'installed-copy=verified|.claude/skills/tracked/run.sh' \
    --root "$main" --ref "$ref" '.claude/skills/tracked/run.sh'

  expect 'a short ref is a usage error' 1 'installed-copy-verdict=refused' --root "$main" --ref 'origin/master' "$file"
  expect 'a ref that does not resolve is refused' 3 'installed-copy-verdict=refused' --root "$main" --ref 'refs/remotes/origin/absent' "$file"
  expect 'a path outside the installed skills directories is a usage error' 1 'installed-copy-verdict=refused' \
    --root "$main" --ref "$ref" 'skills/_shared/tool.sh'
  expect 'a path with a parent component is a usage error' 1 'installed-copy-verdict=refused' \
    --root "$main" --ref "$ref" '.claude/skills/../../skills/_shared/tool.sh'
  expect 'a root below the top of the checkout is refused' 3 'installed-copy-verdict=refused' --root "$main/skills" --ref "$ref" "$file"
  expect 'a relative root is a usage error' 1 'installed-copy-verdict=refused' --root 'main' --ref "$ref" "$file"
  expect 'no path is a usage error' 1 'installed-copy-verdict=refused' --root "$main" --ref "$ref"
  mkdir -p "$tmp/plain/.claude/skills/_shared"
  GIT_CEILING_DIRECTORIES="$tmp" expect 'a root outside a git checkout is refused' 3 'installed-copy-verdict=refused' --root "$tmp/plain" --ref "$ref" "$file"

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
