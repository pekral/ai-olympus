# Input probing

This file owns how a testing agent probes every input of the user interface for hostile and invalid data. `@skills/interactive-testing/SKILL.md` *Check security and repair confirmed threats* step 3 applies it. `@rules/security/frontend.md` and `@rules/security/backend.md` define what a protected application does. This file defines only how the agent finds the inputs, which data it sends, and how it reports the result.

## Boundaries

Every boundary of step 5 of the skill applies to every probe in this file:

- Every payload is harmless. It proves an effect with a unique marker string, or with a DOM marker in place of `alert`. It never destroys data, never causes a denial of service, and never targets a third-party host. A payload never carries a real secret or real personal data.
- A probe that writes targets only a record this run created. Never change a hidden field, a route parameter, a query parameter, or a select or radio value to the identifier of a record this run did not create.
- On a request that is not proven read-only, an input that selects or identifies the record that the request writes gets no marker and no invalid value. This covers a hidden id or token, a route or query parameter, a select or radio value that names a record, and any key that feeds a WHERE clause or a filter of the write.
  - Exception: the traced source shows that the handler rejects the value before any write (validation, a parse failure that aborts the request, `findOrFail`, or route model binding).
  - Without that proof, the input is `not checked`, and the report row names this reason.
- On a request that is not proven read-only, markers and invalid values go only to content fields of the run's own record. That covers an empty or whitespace-only value, a lone brace, a wrong type, and a quote. Probe one input per request.
- No value exceeds 10,000 characters. A limit above 10,000 characters gets no over-length probe, and the report row records why.
  - An out-of-range number or date is exactly one step past the limit.
  - A number that sets a count, a repeat, a page size, or a file size gets no value above its limit plus one, and no out-of-range probe when it has no limit.
- A SQL or NoSQL payload that executes a condition goes only to a request that is proven read-only, and it stays bounded to the value of the run's own record.
  - No such payload may return or expose a row this run did not create, and none may feed a filter or a selection that a later write uses.
  - The bounded SQL pair is `<own value>' AND '1'='1` against `<own value>' AND '1'='2`. The bounded NoSQL pair is `[$in][]=<own value>` against a value that matches nothing.
  - A tautology, an OR clause, a comment sequence, a sleep call, and a not-equal operator are never sent.
  - A request counts as read-only only when the traced handler source shows that no data write of any form runs on any branch that the input value or the query result can reach, including a branch that runs only when the read returns rows or returns none.
  - A data write includes INSERT, UPDATE, DELETE, REPLACE, MERGE, UPSERT, TRUNCATE, DDL (CREATE, ALTER, DROP, CREATE TABLE ... AS SELECT), and a stored routine or trigger that writes. In a document store it includes insert*, update*, replace*, delete*, bulkWrite, findAndModify, findOneAnd*, and the aggregate stages `$merge` and `$out`. The list gives examples, and any other write counts too.
  - The report row names the traced handler and its branches.
  - The local query log of a normal value is supporting evidence and never the proof on its own.
  - A search, a filter, or a sort is not read-only by its role in the page.
  - A request that is not proven read-only is state-changing. It gets only non-executing markers: a lone single quote, or a lone brace or malformed JSON. The agent confirms an injection from the traced source, where the value reaches a string-built query, and never from a live condition-changing payload.
  - A request that is not proven read-only never receives a tautology, a boolean pair, `-- `, `#`, or `/*`.
- Mark every record a probe creates as test data, as *Missing test data* in the skill states. Remove it through *Scripted cleanup*.
- A probe that sends an e-mail, starts a job, or calls an external service needs the authorization of the scenario. Without it, stop before the effect and mark the probe `not checked`.

## 1. Build the input inventory

List every input that the exercised pages accept, before the first probe. Read the rendered page and its DOM. Do not infer an input from the source code alone.

| Input kind | Where to find it |
|---|---|
| Text field, textarea, search box | Visible form controls |
| Select, checkbox, radio | Visible form controls, with the options they offer |
| Hidden field | The DOM: `input[type=hidden]`, including record identifiers |
| File upload | `input[type=file]` and drop zones |
| Rich-text editor | Editor widgets whose content is saved as HTML or Markdown |
| URL query parameter | The query string of every exercised URL, and the parameters that a filter, sort, or search control adds |
| Route parameter | The path segments that carry an identifier or a slug |

Give each input one row: the page, the input, its kind, the context in which the application uses its value (a rendered page, a query, a path, a header, an export), and the client-side limits it carries (`required`, `maxlength`, `pattern`, `type`, `disabled`, the offered options). Add a row for an input that appears only in a later state, such as a modal, a tab, or a step after a save.

## 2. Hostile payloads

For each input, send the payloads that fit the context of its value. A payload class that cannot reach the context of the input is `not applicable`: record the reason and send no payload of that class.

| Class | Context of the value | Harmless payload |
|---|---|---|
| HTML and script injection, stored and reflected | A value rendered in a page, an attribute, or a script | `<img src=x onerror="document.title='XSS-<marker>'">` and `"><b id="XSS-<marker>">x</b>` |
| SQL injection marker | A value that reaches a query, a filter, or a sort | Request proven read-only over every branch: the bounded pair of the boundaries; compare the two responses. Any other request: only a lone single quote, sent to a content field of the run's own record, and confirm the injection from the traced source; never a tautology, a boolean pair, or a comment sequence |
| NoSQL injection marker | A value that reaches a document query | Request proven read-only over every branch only: the bounded pair of the boundaries; compare the two responses. Any other request: only a lone brace or malformed JSON, sent to a content field of the run's own record, and confirm the injection from the traced source; never an operator that widens the condition |
| Template or expression injection | A value that a template engine or an expression field evaluates | `{{7*7}}`, `${7*7}`, `<%= 7*7 %>`; arithmetic only, never a function call, a file read, or a command; the value `49` in the output proves the injection |
| Path traversal | A file name, a path-like field, or a download parameter | `../../<marker>` and `..%2f..%2f<marker>`, aimed at a name that does not exist, and only to a request that reads a file (a download or a preview) and is proven read-only. A field that saves, renames, moves, copies, or deletes a file gets no traversal sequence: confirm from the traced source whether the value reaches a path without normalization |
| Header and CRLF injection | A value that reaches a response header, a redirect, or an e-mail header | `<marker>%0d%0aX-Probe: <marker>`; look for the injected header. In an e-mail header, send it only with the e-mail authorization of the scenario, to a local mail catcher, and never as a `To`, `Cc`, or `Bcc` header |
| Formula injection | A value that reaches a CSV or spreadsheet export | `=1+1`, `+1+1`, `-1+1`, `@SUM(1)`; arithmetic only, never `HYPERLINK`, `DDE`, or a command; read the raw export file as text, never open it in a spreadsheet application, and check that the cell is neutralized |
| File upload with a mismatched type, extension, or content | A file upload | A text file named `.png`, an image named `.php`, a double extension, and a `Content-Type` that does not match the content; every file is harmless: a plain text body that holds only the marker, never code, with a file name that carries the unique marker so it never overwrites an existing file, and no request that executes the stored file |

Replace `<marker>` with one unique string for the run, so the agent finds a stored payload again on every page that shows it. After each payload, check the result in three places:

1. The page after the submit, and after a reload.
2. Every other page that shows the stored value, such as a list, a detail page, an export, or an administrator view.
3. The browser console and the network log.

## 3. Invalid data

For each input, send every class of invalid data that applies to its kind. The record-selecting and size limits of the boundaries apply to every class:

- empty and whitespace-only values;
- a value over the maximum length that the field, the column, or the validation rule sets, by one character, within the 10,000-character cap of the boundaries;
- a value of the wrong type, such as text in a number field;
- negative values, zero, and numbers and dates exactly one step out of range;
- unexpected Unicode: control characters, a right-to-left override, and emoji;
- a value outside the offered options of a select or a radio group, which names no existing record: use the marker, or an identifier that does not exist.

## 4. Bypass the client-side validation

A browser limit is a convenience and never protection. For each input that carries a client-side limit, send the invalid data of section 3 and the payloads of section 2 a second time, past that limit. The payload limits of section 2 and the boundaries apply to this second send, and it never adds a live condition-changing payload to a request that is not proven read-only:

1. Remove `required`, `maxlength`, `pattern`, `type`, and `disabled` in the DOM, or set a select to an option it does not offer.
2. Submit the form, or send the same request from the page, so the browser constraints do not run.
3. Check that the server rejects the value. When the server accepts it, the application relies on client-side validation alone, and the input counts as unprotected.

## 5. Result of each input

Every input of the inventory ends in exactly one result:

| Result | Meaning | Evidence |
|---|---|---|
| `protected` | The server rejected the value, or the output escaped it | The rejection message or status, or the escaped output, for each payload class |
| `vulnerable` | A payload executed, a payload persisted unescaped, or invalid data was stored | A minimal, redacted proof: the input, the value, the observed effect, and the expected denial |
| `not checked` | The agent did not probe the input | The reason, and the step that unblocks it |

A `vulnerable` input follows the flow of step 5 of the skill: it is a candidate first, the agent traces it, and it is confirmed only after a minimal proof. A read-only tester returns the proof to the caller and never classifies it. A scanner warning, or a payload that the page echoes back inside an escaped context, is not `vulnerable`. Keep exploitable details out of a public tracker: the *Input probing* row of the report names a `vulnerable` input as `vulnerable — see Security`, and the proof stays in the *Security* row.

On a shared or remote target every input is `not checked`, and the security check is `Blocked`, as step 5 of the skill states.

Report one row for each input: the page, the input, its kind, the payload classes sent, the traced handler and its branches, and the result. This paragraph is the one statement of the row fields. Report the `not checked` rows under **Untested** and the proof of each `vulnerable` row under **Security**.
