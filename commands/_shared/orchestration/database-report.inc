# Advisory database report

Use the changed access surfaces and the engine determined by `commands/_shared/orchestration/specialized-reviews.inc`.

        - **Who dispatches it.** `commands/_shared/krang-workflow.inc` dispatches `casey` in every *Parallel CR round*, beside `leonardo` and `raphael`; it stops `Not applicable` only when no part applies. A review run inside a subagent reports the need in its handoff (`agents/leonardo.md` *Output* → `Database report:`), and the caller dispatches from that field. For the SQL parts the caller uses the engine resolution this review already made, and `casey` runs the same resolution step itself, by the same sources in the same order. The NoSQL part needs no resolution. A review-only run (`/report-code-review`) is one such round.
          A top-level session that ran the review itself dispatches it once, after the final run of the review loop — the converged one, or the last one — never after each iteration. `leonardo` dispatches nobody.
        - **When nobody can dispatch it.** A review inside a subagent never writes the not-run line; its handoff field carries the need to its caller. A top-level caller that finds `casey` not registered writes `Database report not run — dispatch casey` in its own output. It never runs `mysql-problem-solver` inline instead, and never skips the line silently.

For a Laravel SQL report, supply `commands/_shared/tinker-explain.inc` from this installation. It owns the original guard values and the exact trusted Tinker snippet.
