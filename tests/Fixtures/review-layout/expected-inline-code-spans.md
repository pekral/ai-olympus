🔴 **Problem:** `App\Http\Controllers\OrderController` returns `Collection<int, Order>` to a guest.

**Impact:** A guest reads every order of `App\Models\Order`.

**Fix:** call `$this->authorize()` before the query. An unclosed &#96;tick before &lt;img src=x&gt; stays text.

<!-- cr-inline:actor=bot -->
<!-- cr-inline:finding=r-aaaaaaaaaaaaaaaa -->