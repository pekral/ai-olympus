🔴 **Problem:** <code>App&#92;Http&#92;Controllers&#92;OrderController</code> returns <code>Collection&#60;int, Order&#62;</code> to a guest.

**Impact:** A guest reads every order of <code>App&#92;Models&#92;Order</code>.

**Fix:** call <code>$this-&#62;authorize&#40;&#41;</code> before the query. An unclosed &#96;tick before &lt;img src=x&gt; stays text.

<!-- cr-inline:actor=bot -->
<!-- cr-inline:finding=r-aaaaaaaaaaaaaaaa -->