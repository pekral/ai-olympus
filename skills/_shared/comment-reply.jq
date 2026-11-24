# comment-reply.jq — the one definition of "a comment has a reply" that the review-merge helpers share.
#
# A jq module, never run on its own. A script loads it from its own directory with
# `jq -L "$HERE" 'include "comment-reply"; …'`. It is program text this package ships; no comment, payload, or
# other input contributes to it. A tracker comment has no native thread on a GitHub issue, on a JIRA issue, or on a
# Bugsnag error, so a reply is recognised by what it points at. The test is deliberately wide: a comment taken for a
# reply stays on the tracker, and a missed reply could orphan a person's answer.
#
# Users: skills/merge-review-comments/scripts/plan-merge.sh (the plan), skills/_shared/delete-owned-github-comment.sh
# and skills/code-review-jira/scripts/delete-owned-comment.sh (the `--review-merge` guard that re-proves it before a delete).
#
# A normalised comment is { id: string, pos: number, authorKey: string|null, body: string, mentions: [string],
# parentId: string|null, agent: bool }. `pos` is the place in the chronological list, `agent` is true only for a comment
# of the operator's own account that carries an agent marker (machine output, never a reply), and `parentId` is a native
# parent reference when the tracker data carries one.
include "review-trust";

# github_mentions — the input is a GitHub comment body. The logins it mentions as `@<login>`, in lower case, outside a
# fenced code block and outside an inline code span. An address such as `name@host` is no mention.
def github_mentions:
  [ split("\n")
    | foreach .[] as $line ({fenced: false, keep: null};
        if ($line | test("^[ \t]*(```|~~~)")) then {fenced: (.fenced | not), keep: null}
        elif .fenced then .keep = null
        else .keep = $line end;
        .keep // empty)
    | gsub("`[^`]*`"; "")
    | scan("(?<![A-Za-z0-9_/`])@([A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)") | .[0] | ascii_downcase ]
  | unique;

# A reply quotes the original: it holds, as a substring, a whole line of the original that is at least 20 characters long
# and is no marker line. A `>` or `bq.` prefix in front of the quoted line changes nothing, and a line that a person types
# out again counts too. A comment of the operator's own account that carries an agent marker never gets here (`agent`).
def quotes($reply; $original):
  [ ($original.body | split("\n"))[]
    | gsub("^[ \t]+|[ \t\r]+$"; "")
    | select(length >= 20 and (contains(":actor=") | not)) ] as $lines
  | any($lines[]; . as $line | ($reply.body | contains($line)));

# True when a later comment points at the original: a native parent reference, a link to its id, a quoted line, or a
# mention of its author by another account. A GitHub login is compared without case.
def points_at($reply; $original):
  ($reply.parentId // "") == $original.id
  or ($reply.body | test("(issuecomment-|focusedCommentId=|/comments/)" + $original.id + "([^0-9]|$)"))
  or quotes($reply; $original)
  or (($original.authorKey != null)
      and ($reply.authorKey != $original.authorKey)
      and (($reply.mentions // []) | map(ascii_downcase) | index($original.authorKey | ascii_downcase)) != null);

# has_reply($original; $all) — true when a later comment that is not machine output of the operator's account points
# at the original.
def has_reply($original; $all):
  any($all[]; . as $reply
    | $reply.pos > $original.pos
      and ($reply.agent | not)
      and points_at($reply; $original));

# github_normalised($actor) — the input is the `comments` array of the GitHub issue loader. The comment id is the number
# in the `#issuecomment-<id>` fragment of its URL; a comment without one gets a null id, and no helper acts on it.
def github_normalised($actor):
  to_entries
  | map(.key as $pos | .value
      | { id: ((.url // "" | capture("issuecomment-(?<id>[0-9]+)$")? // {}) | .id // null),
          pos: $pos,
          author: .author,
          authorKey: .author,
          authorAssociation: (.authorAssociation // null),
          createdAt: (.createdAt // null),
          updatedAt: (.updatedAt // null),
          url: (.url // null),
          body: (.body // ""),
          mentions: (.body // "" | github_mentions),
          parentId: null,
          restricted: false }
      | . + { agent: (.authorKey == $actor and (.body | agent_marker)) });

# jira_normalised($actor) — the input is the `comments` array of the JIRA issue loader (`$actor` is the account ID).
def jira_normalised($actor):
  to_entries
  | map(.key as $pos | .value
      | { id: ((.id // null) | if . == null then null else tostring end),
          pos: $pos,
          author: .author,
          authorKey: (.authorAccountId // null),
          createdAt: (.created // null),
          updatedAt: null,
          url: null,
          body: (.body // ""),
          mentions: (.mentionAccountIds // []),
          parentId: null,
          restricted: ((.visibility // null) != null) }
      | . + { agent: (.authorKey == $actor and (.body | agent_marker)) });

# jira_acli_normalised($actor) — the input is the `fields.comment.comments` array of `acli jira workitem view`, which the
# delete helper reads. The body is an Atlassian Document: its text nodes, the target of a linked text, and the URL of a
# smart link become lines, its mention nodes the mentions.
def jira_acli_normalised($actor):
  to_entries
  | map(.key as $pos | .value
      | (.body // "") as $raw
      | { id: ((.id // null) | if . == null then null else tostring end),
          pos: $pos,
          authorKey: (.author.accountId // null),
          body: (if ($raw | type) == "string" then $raw
                 else ([ $raw | .. | objects
                         | if .type? == "text" then (.text // ""), ((.marks // [])[] | select(.type? == "link") | .attrs.href? // empty)
                           elif ((.type? // "") | IN("inlineCard", "blockCard", "embedCard")) then (.attrs.url? // empty)
                           else empty end ] | join("\n")) end),
          mentions: (if ($raw | type) == "string" then []
                     else [ $raw | .. | objects | select(.type? == "mention") | .attrs.id? // empty ] end),
          parentId: null }
      | . + { agent: (.authorKey == $actor and (.body | agent_marker)) });

# github_api_normalised($actor) — the input is the REST list of the issue comments, which the delete helper reads.
def github_api_normalised($actor):
  to_entries
  | map(.key as $pos | .value
      | { id: (.id | tostring),
          pos: $pos,
          authorKey: (.user.login // null),
          body: (.body // ""),
          mentions: (.body // "" | github_mentions),
          parentId: null }
      | . + { agent: (.authorKey == $actor and (.body | agent_marker)) });
