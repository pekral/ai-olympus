# Coverage units, roles, finding lifecycle, and fingerprint

`@skills/security-audit/SKILL.md` reads this file in steps 2 to 5.

## Coverage units

A coverage unit is one attack class on one surface of one subsystem. `coverage-ledger.json` holds one entry per unit, and `schemas/coverage.schema.json` is the contract.

| Field | Content |
|-------|---------|
| `coverage_id` | A stable ID: `C1`, `C2`, … |
| `surface` | The audited surface the reconnaissance found, one value of the schema's `surface` list. |
| `boundary` | The trust boundary the surface crosses, as a snake_case key, for example `unauthenticated_to_authenticated`, `tenant_to_tenant`, `user_to_administrator`, `input_to_command`, `untrusted_content_to_agent`. |
| `subsystem` | The subsystem that owns the surface. |
| `attack_class` | The hunting area of the unit (*Hunting areas* below). |
| `paths` | The files and directories the unit covers. |
| `status` | `queued`, `candidate`, `covered`, `blocked`, `deferred`, or `out_of_scope`. |
| `agent` | The ID of the hunter that holds the unit (`hunter-<n>`), or `null` before a hunter opens. |
| `findings` | The fingerprints of the findings the unit produced. |
| `reason` | Why a `blocked`, `deferred`, or `out_of_scope` unit was not covered. |
| `inputs` | The security inputs on the unit's path (*Security inputs* below). Required for a `covered` unit. |
| `inputs_digest` | The output of `scripts/audit-artifacts.sh digest` over the unit's `paths` and input paths. Required for a `covered` unit. |
| `threats` | One outcome per AI and agent threat class (*AI and agent threat model* below). Required for a `covered` unit of attack class `ai_tools`. |
| `action_binding` | The five action dimensions (*Action binding* below). Required for a `covered` unit whose surface is `privileged_action`, `agent_side_effect`, `ai_tool`, or `mcp_integration`. |
| `reused_from` | In an incremental run, the run that hunted a unit this run reuses unchanged (`references/incremental-audit.md`). |

| Status | Meaning | Final |
|--------|---------|-------|
| `queued` | In the inventory, waiting for a hunter. | no |
| `candidate` | Hunted; its candidates wait for a verifier. | no |
| `covered` | Hunted, and every candidate it produced has a verdict. | yes |
| `blocked` | A hunter could not analyse it, for example the code is unreadable or generated at deploy time. | yes, with a reason |
| `deferred` | No hunter reached it, for example the hunting budget was spent. | yes, with a reason |
| `out_of_scope` | Outside the scope the user named. | yes, with a reason |

A finished run leaves no unit `queued` or `candidate`. One hunter holds one unit, and one hunter pass is one pass of the budget.

## Security inputs

A security input is a file whose change can change the security verdict of a unit. A covered unit lists every security input on its path, each with its kind and a path relative to the top of the work tree. The digest of the unit's `paths` and input paths proves in a later run that none of them changed.

| `kind` | The file holds |
|--------|----------------|
| `source` | the entrypoint where attacker input enters |
| `sink` | the operation where the input does harm |
| `authorization` | an authorization layer: a policy, a gate, a role or permission check |
| `middleware` | a middleware or a route definition that attaches one |
| `validation` | a validation of the input |
| `sanitization` | a sanitization or an encoding of the input |
| `framework_protection` | a framework protection on the path, such as a global scope or a CSRF check |
| `configuration` | configuration that changes the path, including the lock file of a dependency |
| `trust_boundary` | the code that decides which side of a trust boundary a value is on |
| `tool_authority` | the permissions, scopes, or credentials a tool or an agent acts with |
| `action_binding` | the code that binds an action to its principal, authority, resource, and arguments |

- **Every file a finding cites is an input** of every unit that lists the finding: its evidence, the location of every control, its sink, and a source written as `<path>#<symbol>`. `validate` refuses a finding that cites a file outside the paths and inputs of a unit that lists it, because a change to that file would not invalidate the unit.
- **A git-ignored file is not an input.** The digest does not see it, so `digest` and `validate` refuse it. Record a dependency by its lock file (`composer.lock`, `package-lock.json`) as a `configuration` input instead of its installed directory.

## Hunting areas

Every unit names one attack class. The class decides which lenses of the *Core Checks* of `@skills/security-review/SKILL.md` the hunter walks.

| `attack_class` | Hunting area | Lenses |
|----------------|--------------|--------|
| `auth_authz` | auth/authz | *Authentication & Access Control*, *Session & Token Management* |
| `injection_input` | injection/input | *Input & Injection*, *Hidden / Invisible Characters in Stored Fields* |
| `files_ssrf` | files/SSRF | *File Handling*, *External Interaction (APIs & SSRF)*, *Server-Side Request Forgery (SSRF)* |
| `queues_webhooks` | queues/webhooks | *Queues & Background Jobs*, and the webhook signature checks of *External Interaction (APIs & SSRF)* |
| `data_isolation` | data isolation | *Data Exposure*, and the tenant checks of *Authentication & Access Control* |
| `ai_tools` | AI/tools | `@rules/security/general.md`: untrusted content that reaches an agent, a tool call, or an MCP integration, and every side effect an agent can trigger; every class of *AI and agent threat model* below, and *Action binding* below |
| `wildcard` | wildcard | Every remaining lens — *Cryptography & Secrets*, *Security Logging & Monitoring*, *Malicious Code & Supply-Chain Indicators*, *Network Exposure of New Services and Ports*, *Dependencies & Configuration* — and business logic no named class holds |

## AI and agent threat model

A unit of attack class `ai_tools` walks the ten threat classes below. Its hunter returns one outcome per class: `candidate` with the candidate, `no_candidate` with what it traced, or `not_applicable` with the reason the class cannot occur on this surface. `coverage.schema.json` refuses a covered `ai_tools` unit that leaves a class out, and `validate` refuses a unit with a `candidate` outcome that lists no finding.

| `class` | The weakness |
|---------|--------------|
| `prompt_provenance_confusion` | The agent cannot tell an instruction from a trusted source from text in untrusted content, so the text acts as an instruction. |
| `persistent_memory_poisoning` | Untrusted content is written to a memory, a brief, or a store that a later run loads as trusted context. |
| `cross_session_context_bleed` | Context, memory, or a credential of one user, tenant, or session reaches the run of another. |
| `tool_argument_injection` | Untrusted content sets or changes the arguments of a tool call. |
| `confused_deputy` | The agent uses its own authority to do what the requester has no authority to do. |
| `excessive_tool_authority` | A tool holds more permission, scope, or reach than its task needs. |
| `mcp_tool_identity_confusion` | A tool or an MCP server is identified by a name or a description that another server can claim or shadow. |
| `action_confirmation_binding_failure` | A confirmation covers a different action, resource, or argument set than the one that runs. |
| `schema_dispatcher_disagreement` | The schema the model sees and the dispatcher that runs the call disagree on names, types, defaults, or extra fields. |
| `unbounded_agent_action_loop` | The agent can repeat actions or tool calls with no bound on count, cost, or side effects. |

## Action binding

A privileged action is safe only when it is bound to exactly who asks for it and exactly what it does. **Authorization of the user alone does not bind the action.** A user who may refund an order does not make every refund an agent sends in that user's session a refund of that order, of that amount, to that account.

A covered unit whose surface is `privileged_action`, `agent_side_effect`, `ai_tool`, or `mcp_integration` records each of the five dimensions with a status, the `path:line` that shows it, and an observation.

| Dimension | Bound when the code proves |
|-----------|----------------------------|
| `principal` | which user, tenant, or service the action runs for |
| `authority` | that this principal holds the right for this action, and that the tool or agent does not add authority of its own |
| `action` | the exact operation that runs, not a broader one or a different one behind the same tool |
| `resource` | the exact record, account, or file the action changes |
| `arguments` | the exact arguments, unchanged between the request, the confirmation, and the dispatch |

The status is `bound`, `unbound`, or `unclear`. `validate` refuses a unit with an `unbound` or `unclear` dimension that lists no finding: a dimension that is not bound is a candidate for a verifier.

## Finding lifecycle

Every finding is in exactly one state, set by the verifier and confirmed by the coordinator. **Only a validated finding reaches `confirmed`:** a verifier that is not one of its hunters tried to refute it, read the full path, and recorded all six kinds of control without one that stops the flow or leaves it unclear.

| State | Meaning | Severity | `boundary_failure_demonstrated` | `verifier` |
|-------|---------|----------|---------------------------------|------------|
| `confirmed` | The full path from source to sink was read and no control stops it. | `critical`, `high`, `medium`, or `low` | `true` | a `verifier-<n>` ID |
| `needs_validation` | The path looks reachable but a point stays open. `validation_needed` names the step that settles it. | `unrated`, unless `boundary_failure_demonstrated` is `true` | `false` unless demonstrated | a `verifier-<n>` ID, or `null` when no verifier reached it |
| `rejected` | A control stops the flow or the sink is unreachable. `rejection_reason` cites it. | `unrated` | `false` | a `verifier-<n>` ID |

**A `needs_validation` finding never carries a definitive severity without a demonstrated security-boundary failure.** A severity is a claim about impact. The audit makes that claim only for a flow it showed to cross the boundary. `findings.schema.json` and `scripts/audit-artifacts.sh validate` enforce it.

**A control on the path never ends in `confirmed`.** A middleware, a policy, a framework protection, a validation, a sanitization, or a configuration that stops the flow makes the finding `rejected`. One whose effect the verifier could not settle makes it `needs_validation`. `findings.schema.json` refuses a `confirmed` finding with a control of effect `stops` or `unclear`, and one that does not record all six kinds.

The severity of a confirmed finding is the risk-based severity of `@skills/security-review/SKILL.md` *Prioritization*: likelihood times impact, with the likelihood and impact stated in the finding.

A `rejected` finding stays in `findings.json`. It records the path the audit already walked, so the next audit does not walk it again.

## Fingerprint

The fingerprint identifies one root cause across hunters, waves, and runs. Its input is the fingerprint basis, four normalised values, never the prose of the finding:

| Basis field | Form |
|-------------|------|
| `boundary` | the affected trust boundary as a snake_case key |
| `source` | the relevant source or entrypoint: `<METHOD> <route>`, `<kind>:<name>` (for example `queue:invoices`, `command:app:import`, `mcp:search`), or `<path>#<symbol>` |
| `sink` | the vulnerable sink as `<path>#<symbol>`, without a line number, so the fingerprint survives an unrelated edit above it |
| `root_cause` | the root cause as a CWE identifier, for example `CWE-89` |

The fingerprint is the first 16 hexadecimal digits of the SHA-256 of `boundary|source|sink|root_cause`, exactly as written. Compute it with `scripts/audit-artifacts.sh fingerprint <boundary> <source> <sink> <root_cause>`, never by hand. `validate` recomputes it from the basis and refuses a mismatch.

**Deduplicate by the fingerprint, never by text similarity.** Two candidates with the same fingerprint are one finding, however differently they are worded; the finding lists every hunter that proposed it. Two candidates with different fingerprints stay two findings, however similar their wording reads. When two findings look like one root cause but their bases differ, keep both and correct the basis that is wrong.

## The finding fields

Every finding in `findings.json` carries: `fingerprint`, `fingerprint_basis`, `verdict`, `severity`, `boundary`, `entrypoint`, `sink`, `root_cause`, `evidence` (one or more `{location, observation}` with `location` as `path:line`), `remediation`, `boundary_failure_demonstrated`, `hunters`, `verifier`, and `controls` (one `{kind, effect, location, observation}` per control kind the verifier recorded; `location` and `observation` may be left out only for effect `absent`). A `needs_validation` finding adds `validation_needed`. A `rejected` finding adds `rejection_reason`. `schemas/findings.schema.json` is the contract.
