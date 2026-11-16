#!/usr/bin/env bash
# discover-fixers.sh — find the fixer commands that a project's dependency files declare, so `krang`
# can propose them as the manifest key `pr-gate`.
#
# Why this exists
#   The `pr-gate` fixers run before every push (skills/resolve-issue/references/quality-gates.md
#   *Gate placement*). A project that sets no `pr-gate` pushes code its own fixers would have changed,
#   and CI fails on the style one round later. This script reads the dependency files once and prints
#   the fixer commands it found. `krang` proposes them through a pull request
#   (skills/_shared/orchestration/pr-gate-discovery.md). The manifest governs only after that pull
#   request merges.
#
# What it reads
#   composer.json  `scripts` and `require-dev`
#   package.json   `scripts` and `devDependencies`
#   Both from a git ref, never from the working tree: the default branch by default, the same ref
#   read-manifest.sh resolves.
#
# What it prints
#   The project's own fix script wins over the tools it wraps, per ecosystem: `composer fix` replaces
#   Rector, Pint, PHP CS Fixer, PHPCBF, and Composer Normalize, and `npm run lint:fix` replaces ESLint and
#   Prettier. Every command comes from the fixed tables below. A script name or a package name from
#   the file never becomes command text, so a hostile dependency file cannot plant a command. Every
#   command passes the allow-list of project-commands.sh, which run-gate.sh --exclusive applies.
#   A tool with no command that the allow-list accepts (Stylelint needs a file glob) is listed under
#   `unmapped`: the project needs its own fix script for it.
#
# Usage
#   discover-fixers.sh [--ref <git-ref>]
#   discover-fixers.sh --compare <base-ref> <head-ref>
#   discover-fixers.sh --self-test
#
# Output (stdout): one line of JSON.
#   Discovery:
#     status    configured  the manifest on the ref already sets `pr-gate`; nothing was analysed
#               propose     no `pr-gate`, and the dependency files name at least one fixer
#               none        no `pr-gate`, and no fixer was found
#               no-manifest the ref has no composer.json, so the key has nowhere to live
#     ref       the ref that was read
#     pr_gate   the recorded value, or null
#     commands  the fixer commands, in run order (absent on `configured`)
#     tools     the fixer packages found, as <file>:<package> (absent on `configured`)
#     unmapped  fixer packages with no command the gate accepts (absent on `configured`)
#     searched  per file: whether it exists, the script names and the packages that were looked for
#   Compare (a change of the dependency files, for the refresh of `pr-gate`):
#     status    unchanged   the fixer packages are the same on both refs
#               current     the packages changed, and the commands on <head-ref> equal `pr-gate` on <base-ref>
#               propose     the packages changed, and the commands on <head-ref> differ from that `pr-gate`
#     added, removed        the fixer packages that <head-ref> adds or removes
#     commands              the fixer commands on <head-ref>
#     pr_gate               `pr-gate` on <base-ref>, or null
#
# Exit codes
#   0  the result was printed
#   1  usage error, or a ref that is not a valid commit
#   2  missing required tool (git or jq)
#   3  a dependency file on the ref is not valid JSON
#   5  no default-branch ref (read-manifest.sh --default-ref found none)
set -euo pipefail

PROG="${0##*/}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# The fixed tables. A project script is named only when its name is in the list; a tool only when
# its package is. The order of a table is the run order: a rewriting refactor first, then the code
# style, then the file layout. The jq program is single-quoted on purpose: `$name` is a jq variable.
# shellcheck disable=SC2016
JQ_TABLES='
def composer_scripts: ["fix", "cs-fix", "cs:fix", "fix-cs", "fix:cs", "format", "lint:fix", "lint-fix"];
def composer_tools: [
  {package: "rector/rector", command: "vendor/bin/rector process"},
  {package: "laravel/pint", command: "vendor/bin/pint"},
  {package: "friendsofphp/php-cs-fixer", command: "vendor/bin/php-cs-fixer fix"},
  {package: "php-cs-fixer/shim", command: "vendor/bin/php-cs-fixer fix"},
  {package: "squizlabs/php_codesniffer", command: "vendor/bin/phpcbf"},
  {package: "ergebnis/composer-normalize", command: "composer normalize"}
];
def npm_scripts: ["fix", "lint:fix", "lint-fix", "format"];
def npm_tools: [
  {package: "eslint", command: "npx eslint --fix ."},
  {package: "prettier", command: "npx prettier --write ."},
  {package: "stylelint", command: null}
];
def names($object): ($object // {}) | if type == "object" then keys_unsorted else [] end;
def has_name($list; $name): any($list[]; . == $name);
def side($doc; $file; $deps_key; $scripts; $tools; $runner):
  if $doc == null then
    {tools: [], commands: [], unmapped: [],
     searched: {file: $file, present: false, scripts: $scripts, packages: [$tools[].package]}}
  else
    names($doc.scripts) as $own
    | names($doc[$deps_key]) as $deps
    | [$tools[] | select(.package as $p | has_name($deps; $p))] as $found
    | ([$scripts[] | select(. as $s | has_name($own; $s))] | first) as $script
    | {
        tools: [$found[] | "\($file):\(.package)"],
        commands: (if $script != null then ["\($runner) \($script)"]
                   else [$found[] | .command | select(. != null)] end),
        unmapped: (if $script != null then []
                   else [$found[] | select(.command == null) | "\($file):\(.package)"] end),
        searched: {file: $file, present: true, keys: ["scripts", $deps_key], scripts: $scripts,
                   packages: [$tools[].package]}
      }
  end;
def analyse($composer; $package):
  side($composer; "composer.json"; "require-dev"; composer_scripts; composer_tools; "composer") as $c
  | side($package; "package.json"; "devDependencies"; npm_scripts; npm_tools; "npm run") as $n
  | {
      commands: ($c.commands + $n.commands | reduce .[] as $x ([]; if index([$x]) then . else . + [$x] end)),
      tools: ($c.tools + $n.tools),
      unmapped: ($c.unmapped + $n.unmapped),
      searched: [$c.searched, $n.searched]
    };
def manifest($composer):
  ($composer.extra // {}) | (if type == "object" then .["ai-olympus"] else null end) // {}
  | if type == "object" then . else {} end;
def recorded($composer): if $composer == null then null else manifest($composer)["pr-gate"] end;
'

usage() {
  cat >&2 <<'EOF'
Usage: discover-fixers.sh [--ref <git-ref>]
       discover-fixers.sh --compare <base-ref> <head-ref>
       discover-fixers.sh --self-test

Prints the fixer commands composer.json and package.json declare on the ref, as JSON.
EOF
}

# A ref is a name git resolves to a commit, never an option and never a path with a colon.
valid_ref() {
  local ref="$1"
  [[ "$ref" =~ ^[A-Za-z0-9._/@^~-]{1,200}$ && "$ref" != -* ]] || return 1
  git rev-parse --verify --quiet "$ref^{commit}" >/dev/null 2>&1
}

# Prints the file on the ref, or `null` when the ref has none. Returns 3 when the file is not JSON.
read_json() {
  local ref="$1" file="$2" content
  if ! content="$(git show "$ref:./$file" 2>/dev/null)"; then
    printf 'null'
    return 0
  fi
  if ! printf '%s' "$content" | jq -e 'type == "object"' >/dev/null 2>&1; then
    echo "$PROG: $file on $ref is not a valid JSON object" >&2
    return 3
  fi
  printf '%s' "$content" | jq -c .
}

discover() {
  local ref="$1" composer package
  composer="$(read_json "$ref" composer.json)" || return 3
  package="$(read_json "$ref" package.json)" || return 3

  jq -nc --arg ref "$ref" --argjson composer "$composer" --argjson package "$package" "$JQ_TABLES"'
    recorded($composer) as $recorded
    | if $composer != null and (manifest($composer) | has("pr-gate")) then
        {status: "configured", ref: $ref, pr_gate: $recorded}
      else
        analyse($composer; $package) as $a
        | {
            status: (if $composer == null then "no-manifest" elif ($a.commands | length) > 0 then "propose" else "none" end),
            ref: $ref,
            pr_gate: null,
            commands: $a.commands,
            tools: $a.tools,
            unmapped: $a.unmapped,
            searched: $a.searched
          }
      end'
}

compare() {
  local base="$1" head="$2" base_composer base_package head_composer head_package
  base_composer="$(read_json "$base" composer.json)" || return 3
  base_package="$(read_json "$base" package.json)" || return 3
  head_composer="$(read_json "$head" composer.json)" || return 3
  head_package="$(read_json "$head" package.json)" || return 3

  jq -nc --arg base "$base" --arg head "$head" \
    --argjson bc "$base_composer" --argjson bp "$base_package" \
    --argjson hc "$head_composer" --argjson hp "$head_package" "$JQ_TABLES"'
    analyse($bc; $bp) as $b
    | analyse($hc; $hp) as $h
    | recorded($bc) as $recorded
    | ($h.tools - $b.tools) as $added
    | ($b.tools - $h.tools) as $removed
    | {
        status: (if ($added + $removed | length) == 0 then "unchanged"
                 elif $recorded == $h.commands then "current"
                 else "propose" end),
        base: $base,
        head: $head,
        added: $added,
        removed: $removed,
        commands: $h.commands,
        pr_gate: $recorded
      }'
}

require_tools() {
  local tool
  for tool in git jq; do
    command -v "$tool" >/dev/null 2>&1 || { echo "$PROG: $tool is required" >&2; exit 2; }
  done
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
  local failures=0 script repo out code
  script="$SCRIPT_DIR/$PROG"
  SELF_TEST_TMP="$(mktemp -d)"
  trap cleanup_self_test EXIT
  repo="$SELF_TEST_TMP/repo"
  mkdir -p "$repo"
  git -C "$repo" init -q

  # The emitted commands must pass the allow-list that run-gate.sh --exclusive applies.
  # shellcheck source=project-commands.sh
  source "$SCRIPT_DIR/project-commands.sh"

  check() {
    local label="$1" verdict="$2"
    if [[ "$verdict" == pass ]]; then
      printf 'ok    %s\n' "$label"
    else
      printf 'FAIL  %s\n' "$label" >&2
      printf '      output: %s\n' "$out" >&2
      failures=$((failures + 1))
    fi
  }

  run() {
    set +e
    out="$(cd "$repo" && "$script" "$@" 2>/dev/null)"
    code=$?
    set -e
  }

  # Writes composer.json and package.json (`-` removes the file) and commits them; prints the commit.
  commit_files() {
    local composer="$1" package="$2"
    if [[ "$composer" == - ]]; then rm -f "$repo/composer.json"; else printf '%s\n' "$composer" >"$repo/composer.json"; fi
    if [[ "$package" == - ]]; then rm -f "$repo/package.json"; else printf '%s\n' "$package" >"$repo/package.json"; fi
    git -C "$repo" add -A
    git -C "$repo" -c user.name=t -c user.email=t@t commit -q --allow-empty -m files
    git -C "$repo" rev-parse HEAD
  }

  all_commands_valid() {
    local command
    while IFS= read -r command; do
      [[ -z "$command" ]] && continue
      validate_command "$command" >/dev/null 2>&1 || return 1
    done < <(printf '%s' "$out" | jq -r '.commands[]?')
    return 0
  }

  local tools_only script_wins npm_tools npm_script configured empty no_manifest hostile base head same rescript

  tools_only="$(commit_files '{"require-dev": {"ergebnis/composer-normalize": "^2", "squizlabs/php_codesniffer": "^3", "laravel/pint": "^1", "rector/rector": "^2", "friendsofphp/php-cs-fixer": "^3", "php-cs-fixer/shim": "^3"}}' -)"
  run --ref "$tools_only"
  check 'without a project script every tool is proposed, in run order' "$([[ "$code" -eq 0 && "$(printf '%s' "$out" | jq -c '[.status, .commands]')" == '["propose",["vendor/bin/rector process","vendor/bin/pint","vendor/bin/php-cs-fixer fix","vendor/bin/phpcbf","composer normalize"]]' ]] && echo pass)"
  check 'two packages of one tool give one command' "$([[ "$(printf '%s' "$out" | jq '[.commands[] | select(. == "vendor/bin/php-cs-fixer fix")] | length')" == 1 ]] && echo pass)"
  check 'the output states what was searched' "$([[ "$(printf '%s' "$out" | jq -c '[.searched[] | [.file, .present]]')" == '[["composer.json",true],["package.json",false]]' && "$(printf '%s' "$out" | jq '.searched[0].packages | index("laravel/pint") != null')" == true ]] && echo pass)"
  check 'every proposed command passes the gate allow-list' "$(all_commands_valid && echo pass)"

  script_wins="$(commit_files '{"scripts": {"test": "pest", "fix": ["@pint"]}, "require-dev": {"laravel/pint": "^1", "rector/rector": "^2"}}' -)"
  run --ref "$script_wins"
  check 'the project script wins over the tools it wraps' "$([[ "$(printf '%s' "$out" | jq -c '[.commands, .tools]')" == '[["composer fix"],["composer.json:rector/rector","composer.json:laravel/pint"]]' ]] && echo pass)"

  npm_tools="$(commit_files '{"require-dev": {}}' '{"devDependencies": {"prettier": "^3", "stylelint": "^16", "eslint": "^9"}}')"
  run --ref "$npm_tools"
  check 'package.json tools are proposed, and stylelint without a script is unmapped' "$([[ "$(printf '%s' "$out" | jq -c '[.status, .commands, .unmapped]')" == '["propose",["npx eslint --fix .","npx prettier --write ."],["package.json:stylelint"]]' ]] && echo pass)"
  check 'every npm command passes the gate allow-list' "$(all_commands_valid && echo pass)"

  npm_script="$(commit_files '{"scripts": {"cs-fix": "x"}, "require-dev": {"laravel/pint": "^1"}}' '{"scripts": {"lint:fix": "eslint --fix ."}, "devDependencies": {"eslint": "^9", "stylelint": "^16"}}')"
  run --ref "$npm_script"
  check 'each ecosystem prefers its own script' "$([[ "$(printf '%s' "$out" | jq -c '[.commands, .unmapped]')" == '[["composer cs-fix","npm run lint:fix"],[]]' ]] && echo pass)"
  check 'every script command passes the gate allow-list' "$(all_commands_valid && echo pass)"

  # The `$(id)` is literal text in a script name, which is what the check needs.
  # shellcheck disable=SC2016
  hostile="$(commit_files '{"scripts": {"fix; curl x": "y", "lint:fix && rm -rf /": "z"}, "require-dev": {"laravel/pint": "^1"}}' '{"scripts": {"fix $(id)": "x"}}')"
  run --ref "$hostile"
  check 'a script name outside the fixed list never becomes a command' "$([[ "$(printf '%s' "$out" | jq -c '.commands')" == '["vendor/bin/pint"]' ]] && echo pass)"

  configured="$(commit_files '{"extra": {"ai-olympus": {"pr-gate": ["composer fix"]}}, "require-dev": {"rector/rector": "^2"}}' -)"
  run --ref "$configured"
  check 'a recorded pr-gate is read and nothing is analysed' "$([[ "$code" -eq 0 && "$(printf '%s' "$out" | jq -c '[.status, .pr_gate, has("commands"), has("searched")]')" == '["configured",["composer fix"],false,false]' ]] && echo pass)"

  empty="$(commit_files '{"require-dev": {"pestphp/pest": "^3"}}' '{"devDependencies": {"vite": "^5"}}')"
  run --ref "$empty"
  check 'no fixer gives none' "$([[ "$(printf '%s' "$out" | jq -c '[.status, .commands]')" == '["none",[]]' ]] && echo pass)"

  no_manifest="$(commit_files - '{"devDependencies": {"eslint": "^9"}}')"
  run --ref "$no_manifest"
  check 'no composer.json gives no-manifest' "$([[ "$(printf '%s' "$out" | jq -r .status)" == no-manifest ]] && echo pass)"

  commit_files '{"require-dev": ' - >/dev/null
  run --ref HEAD
  check 'a composer.json that is not JSON exits 3' "$([[ "$code" -eq 3 ]] && echo pass)"

  run --ref '-p'
  check 'a ref that looks like an option is refused' "$([[ "$code" -eq 1 ]] && echo pass)"
  run --ref 'HEAD:composer.json'
  check 'a ref with a colon is refused' "$([[ "$code" -eq 1 ]] && echo pass)"
  run --ref 'no-such-ref'
  check 'an unknown ref is refused' "$([[ "$code" -eq 1 ]] && echo pass)"

  run
  check 'without origin/HEAD there is no default ref' "$([[ "$code" -eq 5 ]] && echo pass)"
  git -C "$repo" update-ref refs/remotes/origin/master "$tools_only"
  git -C "$repo" symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/master
  run
  check 'the default branch is read by default' "$([[ "$code" -eq 0 && "$(printf '%s' "$out" | jq -r .ref)" == refs/remotes/origin/master && "$(printf '%s' "$out" | jq -r .status)" == propose ]] && echo pass)"

  base="$(commit_files '{"extra": {"ai-olympus": {"pr-gate": ["vendor/bin/pint"]}}, "require-dev": {"laravel/pint": "^1"}}' -)"
  head="$(commit_files '{"extra": {"ai-olympus": {"pr-gate": ["vendor/bin/pint"]}}, "require-dev": {"laravel/pint": "^1", "rector/rector": "^2"}}' -)"
  run --compare "$base" "$head"
  check 'an added fixer tool proposes the updated pr-gate' "$([[ "$code" -eq 0 && "$(printf '%s' "$out" | jq -c '[.status, .added, .removed, .commands]')" == '["propose",["composer.json:rector/rector"],[],["vendor/bin/rector process","vendor/bin/pint"]]' ]] && echo pass)"
  local both
  both="$(commit_files '{"extra": {"ai-olympus": {"pr-gate": ["vendor/bin/rector process", "vendor/bin/pint"]}}, "require-dev": {"laravel/pint": "^1", "rector/rector": "^2"}}' -)"
  run --compare "$both" "$base"
  check 'a removed fixer tool proposes the updated pr-gate' "$([[ "$(printf '%s' "$out" | jq -c '[.status, .removed]')" == '["propose",["composer.json:rector/rector"]]' ]] && echo pass)"

  same="$(commit_files '{"extra": {"ai-olympus": {"pr-gate": ["vendor/bin/pint"]}}, "require-dev": {"laravel/pint": "^1.2", "pestphp/pest": "^3"}}' -)"
  run --compare "$base" "$same"
  check 'a dependency change without a fixer change is unchanged' "$([[ "$(printf '%s' "$out" | jq -r .status)" == unchanged ]] && echo pass)"

  rescript="$(commit_files '{"extra": {"ai-olympus": {"pr-gate": ["composer fix"]}}, "scripts": {"fix": "x"}, "require-dev": {"laravel/pint": "^1"}}' -)"
  head="$(commit_files '{"extra": {"ai-olympus": {"pr-gate": ["composer fix"]}}, "scripts": {"fix": "x"}, "require-dev": {"laravel/pint": "^1", "rector/rector": "^2"}}' -)"
  run --compare "$rescript" "$head"
  check 'a tool the recorded project script already covers is current' "$([[ "$(printf '%s' "$out" | jq -c '[.status, .pr_gate]')" == '["current",["composer fix"]]' ]] && echo pass)"

  run --compare "$base"
  check 'compare needs two refs' "$([[ "$code" -eq 1 ]] && echo pass)"
  run --unknown
  check 'an unknown option is a usage error' "$([[ "$code" -eq 1 ]] && echo pass)"

  if [[ "$failures" -gt 0 ]]; then
    printf '%s self-test: %d failure(s)\n' "$PROG" "$failures" >&2
    return 1
  fi
  printf '%s self-test: all checks passed\n' "$PROG"
}

main() {
  local ref='' base='' head='' mode=discover

  while [[ $# -gt 0 ]]; do
    case "$1" in
    --self-test)
      require_tools
      self_test
      return $?
      ;;
    --ref)
      [[ $# -ge 2 ]] || { usage; return 1; }
      ref="$2"
      shift 2
      ;;
    --compare)
      [[ $# -ge 3 ]] || { usage; return 1; }
      mode=compare
      base="$2"
      head="$3"
      shift 3
      ;;
    -h | --help)
      usage
      return 0
      ;;
    *)
      usage
      return 1
      ;;
    esac
  done

  require_tools

  if [[ "$mode" == compare ]]; then
    valid_ref "$base" || { echo "$PROG: not a valid commit ref: $base" >&2; return 1; }
    valid_ref "$head" || { echo "$PROG: not a valid commit ref: $head" >&2; return 1; }
    compare "$base" "$head"
    return $?
  fi

  if [[ -z "$ref" ]]; then
    ref="$("$SCRIPT_DIR/read-manifest.sh" --default-ref 2>/dev/null)" || {
      echo "$PROG: no default-branch ref; run: git remote set-head origin --auto" >&2
      return 5
    }
  fi
  valid_ref "$ref" || { echo "$PROG: not a valid commit ref: $ref" >&2; return 1; }
  discover "$ref"
}

main "$@"
