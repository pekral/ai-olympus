This task has no tracker item.

## Merge risk <🟢 low | 🟡 medium | 🔴 high>
<the section of skills/resolve-issue/templates/pull-request-body.md, with the assignment file holding the one line Update the Composer dependencies>

## TL;DR
<count of updated, added, and removed packages; count of major updates; changed composer.json constraints. Every number is counted from the script output.>

## Summary

* **Major updates**
  * `<name>` <old> → <new>: <one plain sentence on what the jump means, from the release notes the run read, or "not verified">
* **Minor and patch updates**
  * <count>, among them `<name>` <old> → <new>
* **Added or removed packages**
  * `<name>` <added | removed>
* **Manifest**
  * <one bullet per composer.json constraint change>

## Major updates
| Package | From | To | Type |
| --- | --- | --- | --- |
| <name> | <old> | <new> | <require | require-dev> |

A major version may contain backward-incompatible changes (semver). Not verified: what breaks in this project; the package release notes and the CI result settle it.

## Other updates
| Package | From | To | Type | Level | Dev package |
| --- | --- | --- | --- | --- | --- |
| <name> | <old> | <new> | <updated | added | removed | downgraded> | <minor | patch | other> | <yes | no> |

## Manifest changes
<composer.json constraint changes from the diff, or none>

## Other changed files
- <path as git status --porcelain printed it>

## ✅ Testing

<the commands run and their result>
No tests were added: the change carries none. The full test suite is the CI result of the pull request.

## Audit

<the block of skills/resolve-issue/templates/pull-request-body.md, with its standalone-run fallback>

<!-- Folding: a section whose content is longer than 600 characters is a <details> block with its heading text as the summary; one of 600 or fewer stays an open ## section; Merge risk is never folded. rules/git/pull-requests.md PR Content Requirements owns the rule and the exact form. -->
<!-- Sections in this order; Merge risk comes first. Summary: a category only when it has a bullet; every value comes from the script output and the diff. Omit Major updates when no package has level major, and omit Other changed files when no path other than composer.json and composer.lock changed. -->
