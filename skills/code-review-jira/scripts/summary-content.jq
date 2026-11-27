# Remove only a publisher-added mediaSingle and its matching caption inside a finding item.
def publisher_caption($label):
  {type:"paragraph",content:[{type:"text",text:$label}]} as $plain
  | . == $plain or . == ($plain | .content[0].marks = [{type:"strong"}]);

def summary_content:
  walk(if type == "object" and .type == "listItem" then
    .content |= reduce .[] as $node ([];
      if $node.type == "mediaSingle" and $node.content[0].type == "media"
         and (.[-1] | publisher_caption($node.content[0].attrs.alt))
      then .[:-1] else . + [$node] end)
  else . end);
