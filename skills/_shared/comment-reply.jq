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

# other_agent_marker — the input is a comment body. True when it carries an agent marker of a namespace other than
# `cr-comment`: such a comment is machine output of another kind, never a plain review comment.
def other_agent_marker:
  type == "string" and ([scan("([a-z][a-z0-9-]*):actor=") | .[0]] | any(. != "cr-comment"));

# char_refs_decoded — the input is a GitHub comment body. Every decimal or hexadecimal character reference (`&#64;`,
# `&#x40;`, leading zeros and either case of `x` included) becomes its character, and `&commat;` becomes `@`, in one
# pass. No other named reference of HTML5 gives `@`, a letter, a digit, or `-`. A code point the renderer does not keep
# becomes U+FFFD.
def char_refs_decoded:
  def code_point: if . >= 1 and . <= 1114111 and (. < 55296 or . > 57343) then . else 65533 end;
  def hex_value: ascii_downcase | explode | reduce .[] as $d (0; . * 16 + (if $d >= 97 then $d - 87 else $d - 48 end));
  gsub("&(?:#(?<dec>[0-9]+)|#[xX](?<hex>[0-9A-Fa-f]+)|(?<named>[cC][oO][mM][mM][aA][tT]));";
    if .named != null then "@"
    elif .dec != null then [.dec | tonumber | code_point] | implode
    else [.hex | hex_value | code_point] | implode end);

# github_mentions — the input is a GitHub comment body. Every login it names as `@<login>` once its character
# references are decoded, in lower case. A login inside a code block or a code span counts too: the test reads no
# Markdown structure, so no difference from the GitHub renderer can hide a mention, and a login counted in excess only
# keeps a comment. Only an ASCII letter or digit in front of the `@`, as in an address such as `name@host`, makes it no
# mention: every markup that can end a text run right before the `@` ends in another character (`_`, `*`, `>`, `;`), so
# that letter or digit stands in front of the `@` in the rendered text too, where GitHub reads no mention.
def github_mentions:
  [ char_refs_decoded | scan("(?<![A-Za-z0-9])@([A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)") | .[0] | ascii_downcase ]
  | unique;

# marker_line($marker) — the input is a GitHub comment body. True when a line of the body is exactly $marker, trailing
# blanks aside, and no code block can hold that line. A line ends at `\n`, `\r\n`, or a lone `\r`, as in CommonMark.
# The reading fails closed: a marker that a code block could hold does not count, so a quoted marker never identifies a
# comment. It follows the CommonMark fence rules only where they are certain for a line at column 0, where the marker
# stands:
# - a fence opens with three or more backticks or tildes at column 0 (a backtick fence has no backtick after the run),
#   and only a line of the same character, at least as long, with at most three spaces in front and nothing after the
#   run, closes it; an unclosed fence runs to the end of the body;
# - a fence line with an indent, a tab, or a quote or list prefix makes every later marker uncertain, because it can
#   belong to a list item or a quote whose end the reading does not track;
# - a fence line inside an HTML block makes every later marker uncertain too, because the block can hold it as text;
#   an HTML block opens with a `<` line and ends at its end mark, or at a blank line for a plain tag. A marker inside
#   an HTML comment or a raw-text block does not count.
def marker_line($marker):
  def prefix: "^(?:[ \t>]|[-+*][ \t]|[0-9]{1,9}[.)][ \t])*";
  def html_end:
    if test("^<!--") then "-->"
    elif test("^<\\?") then "\\?>"
    elif test("^<!\\[CDATA\\[") then "\\]\\]>"
    elif test("^<![A-Za-z]") then ">"
    elif test("^<(?i:pre|script|style|textarea)(?:[ \t>]|$)") then "(?i:</(?:pre|script|style|textarea)>)"
    else "blank" end;
  def html_closed($end): if $end == "blank" then test("^[ \t]*$") else test($end) end;
  gsub("\r\n?"; "\n") | split("\n")
  | any(foreach .[] as $raw ({open: null, html: null, doubt: false, hit: false};
      ($raw | sub("[ \t\r]+$"; "")) as $line
      | ($line | test(prefix + "(?:```|~~~)")) as $fenceLike
      | .hit = false
      | if .open != null then
          ("^ {0,3}" + .open.char + "{" + (.open.length | tostring) + ",}$") as $closer
          | (if ($line | test($closer)) then .open = null else . end)
        elif .html != null then
          (if $fenceLike then .doubt = true else . end)
          | .html as $end
          | (if ($line | html_closed($end)) then .html = null else . end)
          | (if .html == "blank" and $line == $marker and (.doubt | not) then .hit = true else . end)
        elif $line == $marker then .hit = (.doubt | not)
        elif $fenceLike then
          ($line | capture("^(?<run>`{3,}|~{3,})(?<rest>.*)$")? // null) as $fence
          | if $fence == null then .doubt = true
            elif ($fence.run[0:1] == "`" and ($fence.rest | contains("`"))) then .
            else .open = {char: $fence.run[0:1], length: ($fence.run | length)} end
        elif ($line | sub(prefix; "") | startswith("<")) then
          ($line | sub(prefix; "") | html_end) as $end
          | if ($line | sub(prefix; "") | .[1:] | html_closed($end)) and $end != "blank" then . else .html = $end end
        else . end;
      .hit); .);

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
