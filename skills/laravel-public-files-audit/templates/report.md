# Public files audit — {{project}}

- **Mode:** {{static | runtime}}
- **Result:** {{the exit code and the summary line of the script}}
- **Published:** nothing. This report stays in the run output.

## Findings

Order: Critical, then High, then Medium. One row per `finding` line of the script.

| Severity | Check | Path | Evidence | Remediation |
|---|---|---|---|---|
| {{severity}} | `{{check}}` | `{{path}}` | {{evidence, as the script printed it}} | {{the remediation of the check, below}} |

## Not verified

- {{each not-verified line, with the step that settles it}}

## Remediation per check

The owner performs every step. The audit deletes, moves, and changes nothing.

| Check | Remediation |
|---|---|
| `dump-archive-backup` | Move the file out of the web root, or delete it after a copy is in a private location. Rotate every credential the dump or the backup holds. Create dumps and backups outside `public/`. |
| `env-config-copy` | Remove the copy from the web root and rotate every value it holds. Keep `.env` and keys in the project root, never under `public/`. |
| `log-debug` | Move the log or the debug file out of the web root, and point the logger to `storage/logs/`. Remove a `phpinfo` or debug script. |
| `public-storage-script` | Remove the script from the public disk. Validate the type and the extension of each upload, store uploads under a generated name, and configure the web server to never execute a file below `public/storage`. |
| `symlink-permission` | Point a public symlink only to the public disk, as `config/filesystems.php` `links` declares it. Remove the write permission for others (`o-w`) from the reported entry. |
| `gitignore-deploy` | Add the probe path to `.gitignore`, as the Laravel default does. Change the deployment step so that it never writes a dump, an archive, a backup, an environment file, or a log under `public/`. |
| `http-reachable` | Remove the file first, then deny the path in the web server configuration. Run the runtime mode again to confirm a `404` or `403` answer. |
