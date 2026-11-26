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

# The input is the array of the top-level nodes of a description. It is true only when the last node is
# the original block and every node before it has the shape update-description.sh writes: one opening
# paragraph, then one or more collapsed sections whose titles come from the fixed list of the language
# of the original title. A description that only borrows the title of the original is never this shape.
# The shape cannot tell a hand-made description of the same shape from the script's own output.
def hasWrittenShape:
  if length < 3
     or (.[-1] | isOriginal | not)
     or (.[0] | type != "object" or (.type // "") != "paragraph")
  then false
  else (.[-1] | originalLang) as $lang
    | [.[1:-1][] | type == "object" and (.type // "") == "expand" and ((.attrs.title // "") as $t | sectionTitles($lang) | index($t) != null)]
    | all
  end;
