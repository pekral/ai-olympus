🔴 **Problem:** The controller finds the invitation by token and never marks it accepted, so a forwarded link adds another member.

**Impact:** Anyone who receives a forwarded link joins the workspace.

**Fix:** accept only an invitation that is not accepted yet, and mark it accepted in the same step.

<!-- cr-inline:actor=bot -->
<!-- cr-inline:finding=r-aaaaaaaaaaaaaaaa -->