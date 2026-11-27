"""Attach reviewed screenshots and place their media nodes under the corresponding finding."""
import fcntl
import hashlib
import http.client
import json
import os
import re
import secrets
import stat
import subprocess
import sys
from contextlib import contextmanager, nullcontext
import tempfile
from pathlib import Path

from jira_attachment_media import DestinationChanged, Unconfirmed, attachment_identity, attachment_inventory, authenticated_identity, check_api_actor, require, run_cli, target_key

HERE = Path(__file__).resolve().parent
SHARED = HERE.parent.parent / "_shared"
FINDINGS = ("⚠️ Findings from the code review", "⚠️ Nálezy z kontroly kódu")


def canonical_png(slug, source, expected):
    require(re.fullmatch(r"[0-9a-f]{64}", expected), "invalid capture digest")
    with tempfile.TemporaryDirectory() as out:
        result = run_cli("bash", str(SHARED / "screenshot-guard.sh"), "file", "--slug", slug, "--out", out, source)
        path = Path(out) / Path(source).name
        require(str(path) == result.strip(), "unexpected canonical PNG path")
        data = path.read_bytes()
    require(hashlib.sha256(data).hexdigest() == expected, "capture changed after review")
    return data


def checked_item(item, name, size, actor):
    require(re.fullmatch(r"[1-9][0-9]*", str(item["id"])) and item["filename"] == name
            and item["size"] == size and item["mimeType"] == "image/png"
            and item["author"]["accountId"] == actor, "Jira attachment metadata does not match the approved capture")


def upload(site, issue_id, header, name, data):
    require(isinstance(issue_id, str) and re.fullmatch(r"[1-9][0-9]*", issue_id), "invalid Jira issue ID")
    boundary = secrets.token_hex(24)
    part = ('--' + boundary + '\r\nContent-Disposition: form-data; name="file"; filename="' + name
            + '"\r\nContent-Type: image/png\r\n\r\n').encode() + data + ('\r\n--' + boundary + '--\r\n').encode()
    connection = http.client.HTTPSConnection(site, timeout=30)
    try:
        connection.request("POST", "/rest/api/3/issue/" + issue_id + "/attachments", body=part, headers={
            "Authorization": header, "Content-Type": "multipart/form-data; boundary=" + boundary,
            "X-Atlassian-Token": "no-check", "Accept": "application/json",
        })
        response = connection.getresponse()
        payload = response.read(65537)
        require(response.status == 200 and not response.headers.get_all("Location") and len(payload) <= 65536, "unconfirmed attachment upload; inspect the ticket")
        items = json.loads(payload)
        require(isinstance(items, list) and len(items) == 1, "ambiguous Jira upload response; inspect the ticket")
        return items[0]
    finally:
        connection.close()


def audit_record(file, target, code, detail):
    run_cli("bash", str(SHARED / "audit-trail.sh"), "record", "--file", str(file), "--target", target,
            "--status", str(code), "--detail", detail)


class UncertainWrite(Unconfirmed):
    """The run must reconcile a possible external write before publishing or retrying."""


@contextmanager
def locked_ledger(slug, *, writable=False):
    root = Path(run_cli("git", "rev-parse", "--show-toplevel").strip()).resolve()
    file = Path(os.environ.get("AUDIT_TRAIL_FILE", ""))
    require(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,99}", slug)
            and file in [root / r / "run" / (slug + ".audit") for r in (".codex", ".claude")]
            and file.resolve() == file, "the existing audit ledger for this run is required")
    access = os.O_RDWR if writable else os.O_RDONLY
    fd = os.open(file, access | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd) as ledger:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == os.getuid()
                and not info.st_mode & 0o022, "invalid run ledger")
        fcntl.flock(fd, fcntl.LOCK_EX)
        yield file, ledger


def durable_intent(file, ledger, target, detail):
    durable_record(file, ledger, target, 3, detail)


def durable_record(file, ledger, target, code, detail):
    ledger.seek(0, os.SEEK_END)
    audit_record(file, target, code, detail)
    result = (["external-write", target, detail + " (exit 0)"] if code == 0 else
              ["note", target, "write failed (exit 3); " + detail + "; the remote state was not verified"])
    require(any(line.rstrip("\n").split("|")[2:] == result for line in ledger),
            "intent did not reach the locked audit file")
    os.fsync(ledger.fileno())


def pending_uploads(ledger, site, key):
    ledger.seek(0)
    prefix = f"jira attachment {site}/{key}/"
    pending = set()
    for line in ledger:
        fields = line.rstrip("\n").split("|")
        if len(fields) != 5 or not fields[3].startswith(prefix):
            continue
        name = fields[3][len(prefix):]
        if fields[2] == "note" and "upload intent; no automatic retry" in fields[4]:
            pending.add(name)
        elif fields[2] == "external-write" and fields[4] == "stored attachment bytes and media identity verified (exit 0)":
            pending.discard(name)
    return pending


def unresolved(slug, site, key):
    with locked_ledger(slug) as (_, ledger):
        return pending_uploads(ledger, site, key)


def attachment_proof(site, actor, header, item, name, data, expected):
    checked_item(item, name, len(data), actor)
    return {"attachment_id": str(item["id"]), **attachment_identity(site, header, item, expected)}


def confirmed_file(key, slug, name, data, expected, binding, issue_id, *, session=None, locked=None, before_upload=None):
    target_key(key)
    require(re.fullmatch(r"[a-z0-9-]{1,140}\.png", name) and hashlib.sha256(data).hexdigest() == expected
            and 0 < len(data) <= 2097152, "invalid reviewed PNG")
    target = "jira attachment " + binding[0] + "/" + key + "/" + name
    with (locked_ledger(slug, writable=True) if locked is None else nullcontext(locked)) as (file, ledger):
        pending = pending_uploads(ledger, binding[0], key)
        ledger.seek(0)
        attempted = any(line.split("|")[3:4] == [target] for line in ledger)
        try:
            site, actor, header = authenticated_identity(*binding) if session is None else session
            require((site, actor) == binding, "active Jira profile changed before attaching the screenshot", DestinationChanged)
            def inventory():
                return [i for i in attachment_inventory(key, site, issue_id, header=header) if i["filename"] == name]

            items = inventory()
            if not items:
                require(not (attempted or pending), "previous upload attempt requires reconciliation", UncertainWrite)
                check_api_actor(site, actor, header)
                if before_upload is not None:
                    before_upload()
                try:
                    durable_intent(file, ledger, target, "upload intent; no automatic retry")
                except (Unconfirmed, OSError, subprocess.SubprocessError) as error:
                    raise UncertainWrite("attachment intent could not be made durable") from error
                attempted = True
                created = upload(site, issue_id, header, name, data)
                known = str(created["id"])
                require(re.fullmatch(r"[1-9][0-9]*", known), "upload response did not identify an attachment")
                durable_record(file, ledger, target, 0, "upload acknowledged, attachment ID " + known)
                checked_item(created, name, len(data), actor)
                items = inventory()
                require(len(items) == 1 and str(items[0]["id"]) == known, "stored upload is unconfirmed")
            require(len(items) == 1, "attachment is absent or ambiguous")
            proof = attachment_proof(site, actor, header, items[0], name, data, expected)
            if attempted:
                audit_record(file, target, 0, "stored attachment bytes and media identity verified")
            return proof
        except (Unconfirmed, OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError, http.client.HTTPException) as error:
            if attempted:
                raise UncertainWrite("attempted attachment upload requires reconciliation") from error
            raise


def find_item(document, matches):
    found = []
    for block in document["content"]:
        if block.get("type") != "expand" or block.get("attrs", {}).get("title") not in FINDINGS:
            continue
        for listing in block["content"]:
            if listing.get("type") != "bulletList":
                continue
            for item in listing["content"]:
                children = item["content"]
                if item.get("type") != "listItem" or len(children) != 1 or children[0]["type"] != "paragraph":
                    continue
                paragraph = children[0]
                prefix = paragraph.get("content", [{}])[0].get("text", "")
                if re.match(r"^\((?:bug|tests|refactor|performance|documentation)\):", prefix) and matches(paragraph):
                    found.append(item)
    require(len(found) == 1, "the approved finding must map to exactly one plain list item")
    return found[0]


def attach_media(item, media, caption):
    item["content"].extend([
        {"type": "paragraph", "content": [{"type": "text", "text": caption, "marks": [{"type": "strong"}]}]},
        {"type": "mediaSingle", "attrs": {"layout": "align-start"}, "content": [{
            "type": "media", "attrs": {"id": media["id"], "width": media["width"], "height": media["height"],
                                      "type": "file", "collection": "", "alt": caption},
        }]},
    ])

if __name__ == "__main__":
    # Read-only last check for both image and text fallback, before the comment CREATE intent.
    try:
        slug, site, key = sys.argv[1:]
        if unresolved(slug, site, key):
            raise UncertainWrite("attachment write requires reconciliation")
    except (Unconfirmed, ValueError, OSError, subprocess.SubprocessError):
        sys.exit("Jira attachment audit is unavailable or contains an unresolved write")
