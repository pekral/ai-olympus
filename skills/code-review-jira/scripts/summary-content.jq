# Remove only a publisher-added mediaSingle and its matching caption inside a finding item.
def summary_content:
  walk(if type == "object" and .type == "listItem" then
    .content |= reduce .[] as $node ([];
      if $node.type == "mediaSingle" and $node.content[0].type == "media"
         and .[-1] == {type:"paragraph",content:[{type:"text",text:$node.content[0].attrs.alt}]}
      then .[:-1] else . + [$node] end)
  else . end);
