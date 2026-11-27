include "review-trust";

# Check every complete PR text that can close the tracked fix.
def known_pr_closure($q; $repo; $n; $page):
  ($repo | ascii_downcase | gsub("\\."; "\\.")) as $r
      | "(?<![a-z0-9])(close[sd]?|fix(e[sd])?|resolve[sd]?)(?![a-z])" as $kw
      | ("(?<![a-z0-9_./-])(#|gh-)0*\($n)(?![0-9])"
          + "|(?<![a-z0-9_.-])\($r)#0*\($n)(?![0-9])"
          + "|https?://(www\\.)?github\\.com/\($r)/(issues|pull)/0*\($n)(?![0-9])") as $ref
  | (if $n | test("^[A-Z]") then "(?<![A-Z0-9_])" + $n + "(?![A-Z0-9_-])" else $ref end) as $ref
  | # The lines of a text: a line break is CR LF, CR, LF, VT, FF, NEL, LS, or PS. Literal splits keep the cost linear,
        # where a regex split of jq costs more than the square of the line count.
        def lines: reduce ("\r\n", "\r", "\u000b", "\u000c", "\u0085", "\u2028", "\u2029") as $s (.; split($s) | join("\n"))
          | split("\n");
        # The next line with text of each line, computed once from the last line up.
        def next_text($ls): reduce ($ls | to_entries | reverse)[] as $e ({n: "", out: []};
            .out += [.n] | if ($e.value | test("\\S")) then .n = $e.value else . end) | .out | reverse;
        def closing: lines as $ls
          | next_text($ls) as $nt
          | any(range(0; $ls | length); . as $i
              | if ($ls[$i] | test($kw; "i") | not) then false
                else (($ls[$i + 1] // "") | test($ref; "i")) as $imm
                  | ($nt[$i] | test($ref; "i")) as $nxt
                  | any($ls[$i] | match($kw; "gi");
                      ($ls[$i][(.offset + .length):]) as $tail
                      | ($tail | test($ref; "i"))
                        or (if ($tail | test("[a-z0-9]"; "i")) then $imm else $nxt end)) end);
        # The messages a commit can have had before the loader cut its headline: the plain one, and, behind a cut
        # headline, the headline and the body joined with nothing, a space, or a line break between.
        def messages: .messageHeadline as $h | .messageBody as $b
          | [$h + "\n\n" + $b]
            + (if ($h | endswith("\u2026"))
               then ($h | rtrimstr("\u2026")) as $x | ($b | ltrimstr("\u2026")) as $y | [$x + $y, $x + " " + $y, $x + "\n" + $y]
               else [] end);
        def branch_closes: test($kw; "i")
          and (if $n | test("^[A-Z]") then test($ref; "i") else test("(?<![0-9])0*\($n)(?![0-9])") end);
  if ($q | type) != "object" or $q.kind != "pr" or ($q.commits | type) != "array" or ($q.closingIssues | type) != "array" then
    "refused:the loader did not return complete pull request metadata"
  elif $q.knownProof.pullComplete != true or ($q.commits | length) >= $page then
    "refused:the loader may have cut the commit list at its page size of \($page)"
  elif ($q.commits | all(.[]; (.messageHeadline | type) == "string" and (.messageBody | type) == "string") | not) then
    "refused:the loader returned a commit without its message headline and body"
  elif ($q.title | type) != "string" or ($q.body | type) != "string" then
    "refused:the loader returned a pull request without its title and body"
  elif ($q.headRefName | type) != "string" then
    "refused:the loader returned a pull request without its branch name"
  elif ($q.commits | any(.[]; messages | any(.[]; closing))) then "refused:a commit of the pull request closes the linked issue"
  elif ($q.title | closing) then "refused:the title of the pull request closes the linked issue"
  elif ($q.body | closing) then "refused:the body of the pull request closes the linked issue"
  elif ($q.headRefName | branch_closes) then "refused:the branch name of the pull request names a closing keyword and the linked issue"
  else "ok" end;

def github_trusted_issue:
  (.authorAssociation | write_access) and .knownProof.association == .authorAssociation
  and .knownProof.bodyTrusted == true and .knownProof.authorType == "User";
