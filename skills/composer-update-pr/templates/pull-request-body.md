## TL;DR
<count of updated, added, and removed packages; count of major updates; changed composer.json constraints. Every number is counted from the script output.>

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

## Testing
<the commands run and their result>
No tests were added: the change carries none. The full test suite is the CI result of the pull request.

## Merge danger
<the section of skills/resolve-issue/templates/pull-request-body.md, with the assignment file holding the one line Update the Composer dependencies>

## Audit
<the section of skills/resolve-issue/templates/pull-request-body.md, with its standalone-run fallback>

This task has no tracker item.

<!-- Sections in this order. Omit Major updates when no package has level major, and omit Other changed files when no path other than composer.json and composer.lock changed. -->
