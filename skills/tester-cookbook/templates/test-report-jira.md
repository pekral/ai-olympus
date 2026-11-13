_Authors:_ <real change authors, comma-separated, in commit order | unknown — git history did not yield a recognisable identity>
_Available behind:_ <exact label of the admin switch, query parameter, or allow-listed account>

h3. Brief steps to reach the result
* <one click-path line to the affected screen>
* <one click-path line>

h3. What to report back to the dev team
* <a visible symptom, in plain language, that the tester recognises in the UI>
* <a visible symptom>

=== END OF COMMENT BODY — NOTHING BELOW THIS LINE IS EVER PUBLISHED ===

TEMPLATE GUIDANCE. The published comment ends at the line above. The intermediate source has no comment syntax, so this guidance sits below the marker instead of inside the body.

- The source is converted to ADF by upsert-comment.sh --create. No Markdown heading, no fence, no table.
- Omit the Available behind line when the change reaches every user. When it is set, the first step enables the switch.
- Omit Brief steps when the dev-team report is enough. What to report back is required.
- Translate the line labels and the headings into the language of the task. UI labels stay verbatim.
