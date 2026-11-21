# X brand writing usage

## Install and invoke

Install this folder with the rest of the package. In a host project, the installer places it in both skill locations:

- Claude Code: `.claude/skills/x-brand-writing/`. Invoke `/x-brand-writing`.
- Codex: `.agents/skills/x-brand-writing/`. Invoke `$x-brand-writing`.

On a host that has AI Olympus installed, the skill loads the host's `agent-voice` skill before drafting. A portable standalone copy still drafts with the fallback in `SKILL.md`; it needs no dependency or script.

## Prompt patterns

```text
/x-brand-writing
Draft an English launch post for this project from README.md and CHANGELOG.md.
Audience: Laravel maintainers. Goal: explain the new capability without hype.
```

```text
$x-brand-writing
Write a Czech educational thread from docs/skills.md about how a project skill stays portable.
Use only verified repository facts and recommend an existing repository image.
```

```text
$x-brand-writing
Keep this edit as a style preference for this project: lead with a concrete use case,
avoid exclamation marks, and end without a question. Save it in this host only.
```

```text
$x-brand-writing
Forget the preference about ending without a question for this project. Do not publish anything.
```

```text
$x-brand-writing
Host: the fictional project "Signal Kite". Use only these facts: it helps volunteer
coordinators share shift changes, and its latest update adds a printable weekly view.
Draft an English launch post. Recommend no asset unless Signal Kite provides one.
```

## Output rules

Return exactly two variants. Each recommendation points to an existing, verified, shareable host-project path or URL and sits outside the copy-ready text. The same asset may support both variants. A missing suitable asset is stated plainly outside the drafts.

Use a weighted-length validator when one is available in the host. Otherwise keep each post conservatively short, around 120 non-URL characters or fewer with no more than one URL, and say that weighted validation was not verified. Do not treat an ordinary character count as a weighted result.

## Sample validation

The samples below are drafts only. They do not publish to X. They use the verified AI Olympus asset `assets/social-preview.png`. This repository has no suitable installed weighted-length validator, so each uses the conservative fallback and its exact weighted length is not verified.

### Product launch brief

Brief: Announce the new `x-brand-writing` skill in English to maintainers who want project-grounded X copy.

Variant A

Suggested asset: `assets/social-preview.png`

Weighted count: not verified — no suitable validator was available.

```text
x-brand-writing is new in AI Olympus.

It turns project notes and a diff into X drafts for a human to publish.
```

Variant B

Suggested asset: `assets/social-preview.png`

Weighted count: not verified — no suitable validator was available.

```text
Want X copy that sounds like the project?

x-brand-writing starts with evidence, returns two drafts, and never posts.
```

### Educational brief

Brief: Explain in Czech why short product copy should start with verified project evidence.

Both variants are complete two-post threads.

Variant A

Suggested asset: `assets/social-preview.png`

Weighted count: not verified — no suitable validator was available.

```text
Krátký příspěvek potřebuje ověřená fakta.
```

```text
Nejdřív README, změny a skutečný diff. Potom věta, která čtenáře zaujme.
```

Variant B

Suggested asset: `assets/social-preview.png`

Weighted count: not verified — no suitable validator was available.

```text
Marketing nemusí domýšlet úspěchy projektu.
```

```text
Když vychází z reálných změn a omezení, je stručný a důvěryhodný.
```

### Progress update brief

Brief: Share a Czech progress update about the new writing workflow without claiming that it is published or generally available.

Variant A

Suggested asset: `assets/social-preview.png`

Weighted count: not verified — no suitable validator was available.

```text
Pracujeme na x-brand-writing v AI Olympus.

Dvě varianty, ověřené podklady a grafika. Publikuje člověk.
```

Variant B

Suggested asset: `assets/social-preview.png`

Weighted count: not verified — no suitable validator was available.

```text
Posun v psaní pro X: návrh vychází z toho, co projekt obsahuje.

Workflow odděluje fakta, styl projektu a copy.
```

## Preference persistence check

1. A user edits a draft to lead with a concrete use case. The skill updates the host's `style.md` without another consent question and records the preference as confirmed only when the feedback makes it explicit.
2. On the next invocation, the skill reads that same host record before drafting.
3. The next draft leads with the concrete use case. A factual correction changes the evidence instead and does not become a style preference.

## Preference record

After a user edit or explicit feedback, create or update `<host-project-root>/.x-brand-writing/style.md`. Keep it compact:

```md
# X writing preferences

- Confirmed: Lead with a concrete use case. Avoid exclamation marks.
- Tentative: Prefer a practical closing over a question.
- Scope: Czech posts for maintainers.
- Evidence: README.md, CHANGELOG.md
```

Do not store full drafts, secrets, customer data, or preferences for another project. The current brief overrides this record.
