#!/usr/bin/env bash
# github-target.sh — shared library: which GitHub item a wrapper writes to, and which account writes.
#
# Sourced, not executed, by `skills/code-review-github/scripts/upsert-comment.sh` and
# `skills/code-review-github/scripts/post-inline-review.sh`. Both resolve the same two things before their
# first GitHub call, and both must resolve them the same way, so the code exists here once.
#
# The caller sets PROG (the script name its messages start with) before it calls a function.
#
# github_target_resolve <input> <kinds>
#   <input>  a bare number (resolved against the git remote `origin`) or a github.com URL.
#   <kinds>  the path kinds the URL may name, as an extended-regex alternation: `issues|pull` or `pull`.
#   Sets OWNER, REPO, NUMBER, and NWO (`owner/repo`). On a problem it prints the message on stderr and
#   returns 1. The caller decides the exit code; this function never exits.
#
# github_actor_resolve
#   Sets ACTOR to the login of the account `gh` is authenticated as, through `gh api user --jq .login`.
#   A transient failure (rate limit, network error, token refresh) is retried up to three times, and the
#   last stderr of `gh` is part of the message. On a problem it prints the message on stderr and returns 3.

# The variables this library sets are read by the script that sources it.
# shellcheck disable=SC2034
github_target_noun() {
  case "$1" in
  *issues*) printf 'issue/PR' ;;
  *) printf 'pull request' ;;
  esac
}

github_target_from_remote() {
  local remote
  remote="$(git config --get remote.origin.url 2>/dev/null || true)"
  if [[ -z "$remote" ]]; then
    return 1
  fi
  printf '%s' "$remote" \
    | sed -E -e 's#^git@github\.com:#https://github.com/#' -e 's#\.git$##' \
    | sed -nE 's#^https?://github\.com/([^/]+)/([^/]+).*#\1 \2#p'
}

github_target_resolve() {
  local input="$1" kinds="$2" parsed
  OWNER=""
  REPO=""
  NUMBER=""
  NWO=""

  if [[ "$input" =~ ^https?://(www\.)?github\.com/ ]]; then
    parsed="$(printf '%s' "$input" | sed -nE "s#^https?://(www\\.)?github\\.com/([^/]+)/([^/]+)/(${kinds})/([0-9]+).*#\\2 \\3 \\5#p" || true)"
    if [[ -z "$parsed" ]]; then
      echo "${PROG:-github-target.sh}: could not extract $(github_target_noun "$kinds") from URL: $input" >&2
      return 1
    fi
  elif [[ "$input" =~ ^[0-9]+$ ]]; then
    parsed="$(github_target_from_remote || true)"
    if [[ -z "$parsed" ]]; then
      echo "${PROG:-github-target.sh}: cannot resolve repo from git remote — pass a full URL instead" >&2
      return 1
    fi
    parsed="$parsed $input"
  else
    echo "${PROG:-github-target.sh}: argument must be a bare number or a github.com URL: $input" >&2
    return 1
  fi

  OWNER="$(printf '%s' "$parsed" | awk '{print $1}')"
  REPO="$(printf '%s' "$parsed" | awk '{print $2}')"
  NUMBER="$(printf '%s' "$parsed" | awk '{print $3}')"
  NWO="${OWNER}/${REPO}"
}

github_actor_resolve() {
  local attempt err_file err=""
  ACTOR=""
  err_file="$(mktemp)"
  for attempt in 1 2 3; do
    : >"$err_file"
    if ACTOR="$(gh api user --jq .login 2>"$err_file")" && [[ -n "$ACTOR" ]]; then
      rm -f "$err_file"
      return 0
    fi
    ACTOR=""
    err="$(cat "$err_file")"
    if [[ $attempt -lt 3 ]]; then
      sleep 1
    fi
  done
  rm -f "$err_file"

  if [[ -n "$err" ]]; then
    echo "${PROG:-github-target.sh}: failed to resolve current GitHub actor after 3 attempts: ${err}" >&2
  else
    echo "${PROG:-github-target.sh}: failed to resolve current GitHub actor — is gh authenticated? (run: gh auth status)" >&2
  fi
  return 3
}
