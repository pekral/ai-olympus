# Review finding state

## Finding statuses

| Status | Meaning | Blocks |
| --- | --- | --- |
| `new` | First reported in the latest round, with evidence. | yes |
| `confirmed` | Reported with evidence in an earlier round and still present. | yes |
| `needs-verification` | Reported under `## Needs verification`. | only when it meets S1–S3 |
| `resolved` | The reviewer re-read the cited location and the construct is gone. | no |
| `dismissed` | Rejected by a trusted author with a recorded reason. Never a security finding. | no |

## Evidence and blocking

A finding may block only when it names a concrete defect or a violated explicit requirement, carries evidence another reader can assess without trusting the review, has not been resolved or dismissed, and is not deferred. The review template's `Evidence` field names the kind: a failing test, a static-analysis error, a reproducible runtime failure, a concrete exploit path, a traced logic defect, or the explicit rule the line breaks. A point without such evidence goes under `## Needs verification` and does not block.

**A security finding blocks even while unverified.** Every other point that cannot yet be verified stays non-blocking under `## Needs verification`; an S1–S3 point that needs verification blocks until it is proven and fixed, or proven absent (`@rules/general/general.md` *Project instructions take precedence*, item 1).
