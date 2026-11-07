# Subsystem worker brief

The exact brief `@skills/simplification-audit/SKILL.md` step 2 hands to each subsystem worker, plus the eight fields every returned recommendation must carry.

## The brief

Hand the quoted block below over verbatim, with the assigned subsystem's inventory row substituted into the first line. That block is the whole of what a worker receives, and it states the worker's read-only limit itself. Do not paraphrase it and do not add goals of your own.

> Review the assigned subsystem for at most two materially useful simplifications in its data structures, state representation, or organizing model. Inspect its implementation, public interfaces, major call sites, and existing tests. Stay within the assigned ownership boundary. You may identify cross-subsystem concerns, but do not expand the scope to solve them.
>
> Look for:
> - scattered booleans or nullable fields that permit invalid combinations and should become a state machine or discriminated union;
> - repeated assumptions about object shape that need a shared typed model;
> - duplicated branching that a small map, registry, reducer, or command model would remove;
> - unclear state or behavior ownership that a small module boundary would clarify;
> - repeated scans, transformations, or lookups where a more appropriate collection or index would materially simplify behavior;
> - lifecycle, concurrency, or async states whose representation permits stale or contradictory state;
> - a class or layer that fails the deletion test (below);
> - a project-owned interface below the threshold in `@rules/php/core-standards.md` *Design Principles* (below).
>
> Deletion test: for each class or layer you consider, ask what happens if it is deleted. If the complexity disappears, it carried nothing (for example a wrapper whose every method passes its arguments unchanged to one other class). If the complexity reappears in every caller, it earns its place (for example a price calculator that owns VAT, discounts, and rounding). Record the verdict as evidence. Only a class that carried nothing is a finding. A layer that a project rule prescribes, such as `@rules/laravel/architecture.md` *Architecture*, justifies itself by that rule. A deep module, which is a small interface that hides a lot of logic, passes the test.
>
> One adapter is a hypothetical seam, and two make it real. The threshold that decides when an interface is real is in `@rules/php/core-standards.md` *Design Principles*, and test doubles do not count.
>
> Do not force an abstraction. Prefer boring local code when it is already clear. Do not recommend changes solely for stylistic consistency, hypothetical extensibility, minor line-count reduction, or moving existing branching behind a new type.
>
> Return at most two opportunities. If nothing clearly meets the threshold, return skip.
>
> You are read-only, exactly as the coordinator is. Never edit a file, run a test, implement a recommendation, commit, or push. Read-only inspection commands are allowed.

## The eight required fields

Every recommendation the worker returns carries all eight, in this order. A finding missing any one of them fails the schema-completeness pass in step 4 and is sent back or dropped.

1. **Verdict** — `recommend` or `skip`.
2. **Evidence** — exact file and line references, plus the deletion-test verdict (`carried nothing` or `earns its place`) or the interface's implementation and consumer counts when the finding rests on either.
3. **Current complexity or invalid states** — what the present representation allows that it should not, or what it forces a reader to hold in their head.
4. **Proposed representation and why it is simpler** — the target shape, and the concrete reason it is simpler rather than merely different.
5. **Smallest credible implementation scope** — the affected files and interfaces, and nothing beyond them.
6. **Regression risks and migration concerns** — what can break, and what already-stored data or already-published interface the change has to carry forward.
7. **Existing and additional validation required** — the tests that already cover the target lines, and the tests that would have to be added first.
8. **Not verified** — every point the evidence does not settle, each with the step that settles it, or `none`. Never a confidence level (`@rules/general/general.md` *Verified statements only*).
