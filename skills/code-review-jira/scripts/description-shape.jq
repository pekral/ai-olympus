# description-shape.jq — the one definition of the JIRA description shape that update-description.sh
# writes and load-issue.sh reads (rules/compound-engineering/assignment.md *The shape in a JIRA
# description*). Both scripts `include "description-shape";` with `jq -L <this directory>`, so the
# fixed titles and the test for the preserved original exist once.

# The fixed section titles of one language, in the order of the shape.
def sectionTitles($lang):
  if $lang == "cs" then ["Požadavky na aplikaci", "Akceptační kritéria", "Co teď neděláme"]
  else ["Requirements for the application", "Acceptance criteria", "What we are not doing now"] end;

def allSectionTitles: sectionTitles("en") + sectionTitles("cs");

def originalTitles: ["Original assignment", "Původní zadání"];

# A node is an original block by its title alone. Use it to find a candidate, never to trust one.
def isOriginal: type == "object" and (.type // "") == "expand" and ((.attrs.title // "") as $t | originalTitles | index($t) != null);

# The language of an original block, read from its title.
def originalLang: if (.attrs.title // "") == "Původní zadání" then "cs" else "en" end;

# Validate the opening and new sections, without the preserved original.
def assignmentProblems($lang):
  . as $c
    | ($c[1:] | map(select((.type // "") == "expand") | (.attrs.title // ""))) as $titles
    | ($titles | map(. as $t | sectionTitles($lang) | index($t))) as $ix
    | [ (if ($c | length) == 0 or ($c[0].type // "") != "paragraph" then "the new text does not open with one paragraph" else empty end),
        (if ($c | length) < 2 then "the new text has no collapsed section" else empty end),
        ($c[1:][] | select((.type // "") != "expand") | "a node outside a collapsed section after the opening paragraph: \(.type // "?")"),
        ($titles[] | select(. as $t | sectionTitles($lang) | index($t) == null) | "a section title outside the fixed list: \(.)"),
        (if ([range(1; $ix | length) | select($ix[.] != null and $ix[. - 1] != null and $ix[.] <= $ix[. - 1])] | length) > 0
         then "the sections are not in the fixed order, or one repeats" else empty end),
        ($c[1:][] | select((.type // "") == "expand") | select([.content[]? | .. | objects | select(.type == "text" and (.text? | type == "string" and test("\\S")))] | length == 0)
         | "an empty section: \(.attrs.title // "?")"),
        ($c[1:][] | select(.attrs.title == sectionTitles($lang)[1])
         | select((.content | length) != 1 or .content[0].type != "orderedList"
                  or any(.content[0].content[]?; .type != "listItem" or isempty(.. | objects | select(.type == "text" and (.text | test("\\S"))))))
         | "acceptance criteria must be a numbered list"),
        ([.. | objects | (.type // "") | select(IN("heading", "codeBlock", "mention", "inlineCard", "blockCard", "embedCard",
            "media", "mediaSingle", "mediaGroup", "mediaInline", "taskList", "decisionList", "layoutSection", "nestedExpand"))]
         | unique[] | "a node the new text never carries: \(.)"),
        ([.. | objects | .marks? // empty | .[] | (.type // "") | select(IN("link", "code"))] | unique[] | "a mark the new text never carries: \(.)"),
        ($c[1:][] | select((.type // "") == "expand") | .content[]? | .. | objects | select((.type // "") == "expand")
         | "a collapsed section inside a collapsed section")
      ] | unique | .[];

# Only a document with this exact prefix and one final original can be rewritten without loss.
def hasWrittenShape:
  if type != "array" or length < 3 or (.[-1] | isOriginal | not) or any(.[]; type != "object")
  then false
  else (.[-1] | originalLang) as $lang | .[:-1] | isempty(assignmentProblems($lang))
  end;
