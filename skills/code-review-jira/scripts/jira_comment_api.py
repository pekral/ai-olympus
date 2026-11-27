"""Explicit operator edit of one verified PR heading. No public CLI or raw-ADF write interface."""
import hashlib
import http.client
import json
import os
import re
import stat
import subprocess
import tempfile
import time
from pathlib import Path

from jira_attachment_media import authenticated_identity, public_get, require
from jira_review_media import HERE, UncertainWrite, audit_record, locked_ledger
from jira_target import issue_origin


def read_pinned(path, digest, private=False, *, limit=65536):
    path = Path(path)
    require(path.is_absolute() and path == path.resolve() and re.fullmatch(r"[0-9a-f]{64}", digest), "invalid pinned input")
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, "rb") as source:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == os.getuid()
                and not info.st_mode & (0o077 if private else 0o022) and 0 < info.st_size <= limit, "invalid pinned file")
        data = source.read(limit + 1)
        require(len(data) == info.st_size and hashlib.sha256(data).hexdigest() == digest, "pinned content changed")
        return data


def same_json(left, right):
    return json.dumps(left, sort_keys=True, allow_nan=False) == json.dumps(right, sort_keys=True, allow_nan=False)


def heading_change(plan, previous, wiki):
    with tempfile.TemporaryDirectory() as work:
        source = Path(work) / "summary.wiki"
        source.write_bytes(wiki)
        args = ["php", str(HERE.parent.parent / "pr-summary/scripts/check-jira-comment.php"), "--criteria", "present",
                "--author", "none", "--repository", plan["repository"], "--jira-issue", plan["url"], "--revision", plan["revision"]]
        for pr in plan["pulls"]:
            args.extend(("--pull-request", pr))
        env = {k: v for k, v in os.environ.items() if k not in ("JIRA_API_TOKEN", "JIRA_API_EMAIL")}
        subprocess.run([*args, str(source)], check=True, capture_output=True, timeout=30, env=env)
        result = subprocess.run(["php", str(HERE / "wiki-markup-to-adf.php")], input=wiki, check=True, capture_output=True, timeout=30, env=env)
        document = json.loads(result.stdout)
    require(previous["type"] == "doc" and same_json(previous["version"], 1)
            and previous["content"][0]["content"][0]["text"].startswith("AI Olympus · "), "previous body is not an attributed summary")
    original, updated = previous["content"], document["content"]
    require(len(original) == len(updated), "the edit changes the document structure")
    changed = [(a, b) for a, b in zip(original, updated) if not same_json(a, b)]
    require(len(changed) == 1, "the edit must change one heading")
    old, new = changed[0]
    prefix = "PR #" + plan["heading_pr"] + " — "
    require(all(n["type"] == "heading" and n["attrs"] == {"level": 2} and n["content"][0]["text"].startswith(prefix) for n in (old, new))
            and len(new["content"]) == 1 and set(new["content"][0]) == {"type", "text"}, "the requested PR heading changed scope")
    return document


def fetch(site, path, header):
    status, redirect, body = public_get(site, path, header, 131072)
    require(status == 200 and not redirect, "Jira did not return the expected resource")
    return json.loads(body)


def comment_mismatch(comment, number, actor, document):
    if str(comment["id"]) != number or comment["author"]["accountId"] != actor:
        return "readback-identity"
    if comment.get("jsdPublic") is not True or comment.get("visibility") is not None:
        return "readback-visibility"
    return None if same_json(comment["body"], document) else "readback-body"


def check_comment(comment, number, actor, body):
    require(comment_mismatch(comment, number, actor, body) is None, "comment identity, visibility or body changed")


def readback_comment(site, path, header, number, actor, document):
    """Retry only a bounded GET of the known comment. Reasons contain no response data."""
    reason = "readback-transport"
    for delay in (0, 1, 2):
        if delay:
            time.sleep(delay)
        try:
            code, redirect, data = public_get(site, path, header, 131072)
            reason = "readback-http-" + str(code)
            if code == 200 and not redirect:
                reason = comment_mismatch(json.loads(data), number, actor, document)
                if reason is None:
                    return
        except (OSError, http.client.HTTPException):
            reason = "readback-transport"
        except (ValueError, TypeError, KeyError):
            reason = "readback-response"
    raise UncertainWrite(reason)


def put_comment(site, path, header, document):
    # http.client does not follow redirects or retry mutation requests.
    connection = http.client.HTTPSConnection(site, timeout=30)
    try:
        connection.request("PUT", path, body=json.dumps({"body": document}).encode(), headers={
            "Authorization": header, "Content-Type": "application/json", "Accept": "application/json"})
        response = connection.getresponse()
        payload = response.read(131073)
        require(response.status == 200 and not response.headers.get_all("Location") and len(payload) <= 131072, "unconfirmed Jira update")
        return json.loads(payload)
    finally:
        connection.close()


def edit_summary(plan):
    target = re.fullmatch(r"https://([a-z0-9-]+\.atlassian\.net)/browse/([A-Z][A-Z0-9_]+-[1-9][0-9]*)", plan["url"])
    require(target and plan["mode"] == "operator-edit" and plan["actor"]
            and all(re.fullmatch(r"[1-9][0-9]*", plan[k]) for k in ("issue_id", "comment_id", "heading_pr")), "invalid operator edit")
    site, key = target.groups()
    actor, number = plan["actor"], plan["comment_id"]
    previous = json.loads(read_pinned(plan["previous"], plan["previous_sha"], private=True))
    document = heading_change(plan, previous, read_pinned(plan["wiki"], plan["wiki_sha"]))
    authenticated_site, authenticated_actor, header = authenticated_identity(site, actor)
    require((site, actor) == (authenticated_site, authenticated_actor), "Jira identity changed")
    path = "/rest/api/3/issue/" + plan["issue_id"]
    issue_origin(fetch(site, path + "?fields=key", header), site, key, plan["issue_id"])
    path += "/comment/" + number
    intent = "jira operator edit " + site + "/" + key + "/" + number + " " + plan["wiki_sha"]
    with locked_ledger(plan["slug"], writable=True) as (file, ledger):
        require(not any(line.split("|")[3:4] == [intent] for line in ledger), "a previous update requires read-only reconciliation")
        check_comment(fetch(site, path, header), number, actor, previous)
        audit_record(file, intent, 3, "operator edit intent; no automatic retry")
        require(any(line.split("|")[3:4] == [intent] for line in ledger), "intent did not reach the locked audit file")
        os.fsync(ledger.fileno())
        try:
            reply = put_comment(site, path, header, document)
            require(str(reply["id"]) == number, "update response did not identify the named comment")
            audit_record(file, intent, 0, "update response identified comment " + number)
            readback_comment(site, path, header, number, actor, document)
            audit_record(file, intent, 0, "named comment and whole ADF verified")
        except UncertainWrite:
            raise
        except Exception:
            raise UncertainWrite("operator edit unconfirmed; inspect the named comment before any further write") from None
    return plan["url"] + "?focusedCommentId=" + number
