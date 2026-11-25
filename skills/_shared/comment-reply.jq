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
# mentionText: string|null, parentId: string|null, agent: bool }. `pos` is the place in the chronological list, `agent`
# is true only for a comment of the operator's own account that carries an agent marker (machine output, never a reply),
# and `parentId` is a native parent reference when the tracker data carries one. A GitHub comment carries its
# `mentionText` (github_mention_text) and no `mentions`; a JIRA comment carries the account IDs it mentions in `mentions`.
include "review-trust";

# github_item_kind($number) — the input is the REST issue object of a GitHub item (`gh api repos/<o>/<r>/issues/<n>`).
# "pull" when it carries a `pull_request` field, which GitHub sends for a pull request, "issue" for an issue of that
# number, and "unknown" for anything else. The URL never decides it: an issue URL can name a pull request, and the
# reviews and review comments of a pull request are replies this module never reads. A helper acts only on "issue".
def github_item_kind($number):
  if type != "object" or .number != $number then "unknown"
  elif has("pull_request") then "pull"
  else "issue" end;

# other_agent_marker — the input is a comment body. True when it carries an agent marker of a namespace other than
# `cr-comment`: such a comment is machine output of another kind, never a plain review comment.
def other_agent_marker:
  type == "string" and ([scan("([a-z][a-z0-9-]*):actor=") | .[0]] | any(. != "cr-comment"));

# char_refs_decoded — the input is a GitHub comment body. Every decimal or hexadecimal character reference becomes its
# character, with or without its `;`, as an HTML block decodes it: the digits are read greedily, leading zeros and either
# case of `x` included (`&#64`, `&#x40;`, `&#X0040`). The named references of HTML5 that give `@`, `_`, or a letter
# become their text with or without the `;`, in any letter case: `&commat;` gives `@`, `&lowbar;` and `&UnderBar;` give
# `_`, and `&fjlig;` gives `fj`. No other named reference gives `@`, a letter, a digit, `_`, or `-`. A code point the
# renderer does not keep becomes U+FFFD. One pass: a decoded `&` never starts a second reference.
def char_refs_decoded:
  def code_point: if . >= 1 and . <= 1114111 and (. < 55296 or . > 57343) then . else 65533 end;
  def hex_value: ascii_downcase | explode | reduce .[] as $d (0; . * 16 + (if $d >= 97 then $d - 87 else $d - 48 end));
  gsub("&(?:#(?<dec>[0-9]+);?|#[xX](?<hex>[0-9A-Fa-f]+);?|(?<named>(?i:commat|lowbar|underbar|fjlig));?)";
    if .named != null then ({commat: "@", lowbar: "_", underbar: "_", fjlig: "fj"}[.named | ascii_downcase])
    elif .dec != null then [.dec | tonumber | code_point] | implode
    else [.hex | hex_value | code_point] | implode end);

# github_mention_text — the input is a GitHub comment body. The text that the mention test of points_at reads: the body
# with its character references decoded, every backslash removed (a Markdown escape such as `@name\-x` renders as
# `@name-x`), the KELVIN SIGN U+212A read as `k` (GitHub reads it as `k` in a login, and it is the only non-ASCII
# character whose lower case is an ASCII letter), and in lower case. Removing a character that no login holds never
# breaks an `@<login>` the body already holds, so each step only adds mentions.
# The text mentions a login when it holds `@` followed directly by that login, in lower case. Nothing in front of the `@`
# and nothing after the login is read, and a login inside a code block or a code span counts too: the test reads no
# Markdown structure. It reads no HTML either: a text that holds both `@` and `<` counts as a mention of every login
# (github_mention_any), because the renderer can drop a tag and join the text around it into one login. A mention
# counted in excess, such as `ops@bot.example`, `@bot-helper` for `bot`, or `<b>@alice</b>`, only keeps a comment.
def github_mention_text:
  char_refs_decoded | gsub("\\\\"; "") | gsub("K"; "k") | ascii_downcase;

# github_mention_any — the input is a mention text. True when it holds both `@` and `<`: the test never guesses which
# tag the renderer keeps, so such a text mentions every login. The error direction is "the comment stays".
def github_mention_any:
  contains("@") and contains("<");

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
# mention of its author by another account. On GitHub the mention text holds `@` and the author login, without case, or
# it holds both `@` and `<` (github_mention_any).
def points_at($reply; $original):
  ($reply.parentId // "") == $original.id
  or ($reply.body | test("(issuecomment-|focusedCommentId=|/comments/)" + $original.id + "([^0-9]|$)"))
  or quotes($reply; $original)
  or (($original.authorKey != null)
      and ($reply.authorKey != $original.authorKey)
      and (if ($reply.mentionText | type) == "string"
           then ($reply.mentionText | contains("@" + ($original.authorKey | ascii_downcase)) or github_mention_any)
           else (($reply.mentions // []) | map(ascii_downcase) | index($original.authorKey | ascii_downcase)) != null end));

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
          mentions: [],
          mentionText: (.body // "" | github_mention_text),
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
          mentions: [],
          mentionText: (.body // "" | github_mention_text),
          parentId: null }
      | . + { agent: (.authorKey == $actor and (.body | agent_marker)) });
