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
import tempfile
from pathlib import Path

from jira_attachment_media import DestinationChanged, Unconfirmed, attachment_identity, attachment_inventory, authenticated_identity, require, run_cli, target_key

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


def upload(site, key, header, name, data):
    boundary = secrets.token_hex(24)
    part = ('--' + boundary + '\r\nContent-Disposition: form-data; name="file"; filename="' + name
            + '"\r\nContent-Type: image/png\r\n\r\n').encode() + data + ('\r\n--' + boundary + '--\r\n').encode()
    connection = http.client.HTTPSConnection(site, timeout=30)
    try:
        connection.request("POST", "/rest/api/3/issue/" + key + "/attachments", body=part, headers={
            "Authorization": header, "Content-Type": "multipart/form-data; boundary=" + boundary,
            "X-Atlassian-Token": "no-check", "Accept": "application/json",
        })
        response = connection.getresponse()
        payload = response.read(65537)
        require(response.status == 200 and len(payload) <= 65536, "unconfirmed attachment upload; inspect the ticket")
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


def confirmed_file(key, slug, name, data, expected, binding, issue_id):
    target_key(key)
    require(re.fullmatch(r"[a-z0-9-]{1,140}\.png", name) and hashlib.sha256(data).hexdigest() == expected
            and 0 < len(data) <= 2097152, "invalid reviewed PNG")
    root = Path(run_cli("git", "rev-parse", "--show-toplevel").strip()).resolve()
    file = Path(os.environ.get("AUDIT_TRAIL_FILE", ""))
    require(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,99}", slug)
            and file in [root / r / "run" / (slug + ".audit") for r in (".codex", ".claude")]
            and file.resolve() == file, "the existing audit ledger for this run is required")
    target = "jira attachment " + binding[0] + "/" + key + "/" + name
    # Read the intent before inventory or identity checks: an older POST remains unresolved
    # until the original attachment can be positively identified and all bytes verified.
    fd = os.open(file, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd) as ledger:
        require(stat.S_ISREG(os.fstat(fd).st_mode) and os.fstat(fd).st_nlink == 1, "invalid run ledger")
        fcntl.flock(fd, fcntl.LOCK_EX)
        attempted = any(line.split("|")[3:4] == [target] for line in ledger)
        try:
            site, actor, header = authenticated_identity(*binding)
            require((site, actor) == binding, "active Jira profile changed before attaching the screenshot", DestinationChanged)
            def inventory():
                return [i for i in attachment_inventory(key, site, issue_id) if i["filename"] == name]

            items = inventory()
            if not items:
                require(not attempted, "previous upload attempt requires reconciliation")
                audit_record(file, target, 3, "upload intent; no automatic retry")
                attempted = True
                created = upload(site, key, header, name, data)
                checked_item(created, name, len(data), actor)
                audit_record(file, target, 0, "upload acknowledged, attachment ID " + str(created["id"]))
                items = inventory()
                require(len(items) == 1 and str(items[0]["id"]) == str(created["id"]), "stored upload is unconfirmed")
            require(len(items) == 1, "attachment is absent or ambiguous")
            item = items[0]
            checked_item(item, name, len(data), actor)
            return {"attachment_id": str(item["id"]), **attachment_identity(site, header, item, expected)}
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
        {"type": "paragraph", "content": [{"type": "text", "text": caption}]},
        {"type": "mediaSingle", "attrs": {"layout": "align-start"}, "content": [{
            "type": "media", "attrs": {"id": media["id"], "width": media["width"], "height": media["height"],
                                      "type": "file", "collection": "", "alt": caption},
        }]},
    ])
