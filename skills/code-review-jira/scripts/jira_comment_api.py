"""Publish authorized Wiki summaries on one fixed Jira Cloud site."""
from copy import deepcopy
import hashlib
import http.client
import json
import os
import re
import stat
import subprocess
import sys
import tempfile
import time
from pathlib import Path

from jira_attachment_media import DestinationChanged, Unconfirmed, attachment_inventory, authenticated_identity, public_get, require, run_bytes
from jira_review_media import (HERE, UncertainWrite, attachment_proof, attach_media, audit_record, confirmed_file,
                               durable_intent, durable_record, locked_ledger, pending_uploads)
from jira_review_selection import StaleReview, baseline_revision, default_branch, revalidate, selection, source_review
from jira_target import issue_origin


def read_pinned(path, digest, private=False, *, limit=65536):
    require(re.fullmatch(r"[0-9a-f]{64}", digest), "invalid pinned input")
    data = read_file(path, private, limit)
    require(hashlib.sha256(data).hexdigest() == digest, "pinned content changed")
    return data


def read_file(path, private, limit):
    path = Path(path)
    require(path.is_absolute() and path == path.resolve(), "invalid publication file")
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, "rb") as source:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == os.getuid()
                and not info.st_mode & (0o077 if private else 0o022) and 0 < info.st_size <= limit, "invalid pinned file")
        data = source.read(limit + 1)
        require(len(data) == info.st_size and os.fstat(fd).st_ctime_ns == info.st_ctime_ns, "publication file changed")
        return data


def same_json(left, right):
    return json.dumps(left, sort_keys=True, allow_nan=False) == json.dumps(right, sort_keys=True, allow_nan=False)


def heading_change(plan, previous, wiki):
    require(previous["type"] == "doc" and same_json(previous["version"], 1)
            and previous["content"][0]["content"][0]["text"].startswith("AI Olympus · "), "previous body is not an attributed summary")
    env = {k: v for k, v in os.environ.items() if k not in ("JIRA_API_TOKEN", "JIRA_API_EMAIL")}
    result = subprocess.run(["php", str(HERE / "wiki-markup-to-adf.php")], input=wiki, check=True, capture_output=True, timeout=30, env=env)
    document = json.loads(result.stdout)
    prefix = "PR #" + plan["heading_pr"] + " — "
    if plan.get("plain_h3_heading") is True:
        expected = deepcopy(previous)
        headings = [node for node in expected["content"] if node["type"] == "heading"
                    and "".join(child.get("text", "") for child in node["content"]).startswith(prefix)]
        require(len(headings) == 1 and same_json(headings[0]["attrs"]["level"], 2), "expected one named H2")
        heading = headings[0]
        require(all(node["type"] == "text" and isinstance(node["text"], str)
                    and set(node) <= {"type", "text", "marks"} and node.get("marks", []) in ([], [{"type": "code"}])
                    for node in heading["content"]), "expected a plain or code-marked PR title")
        heading["attrs"]["level"] = 3
        heading["content"] = [{"type": "text", "text": "".join(node["text"] for node in heading["content"])}]
        require(same_json(document, expected), "only the level and code styling of the named PR heading may change")
        return document
    with tempfile.TemporaryDirectory() as work:
        source = Path(work) / "summary.wiki"
        source.write_bytes(wiki)
        args = ["php", str(HERE.parent.parent / "pr-summary/scripts/check-jira-comment.php"), "--criteria", "present",
                "--author", "none", "--repository", plan["repository"], "--jira-issue", plan["url"], "--revision", plan["revision"]]
        for pr in plan["pulls"]:
            args.extend(("--pull-request", pr))
        subprocess.run([*args, str(source)], check=True, capture_output=True, timeout=30, env=env)
    original, updated = previous["content"], document["content"]
    require(len(original) == len(updated), "the edit changes the document structure")
    changed = [(a, b) for a, b in zip(original, updated) if not same_json(a, b)]
    require(len(changed) == 1, "the edit must change one heading")
    old, new = changed[0]
    require(all(n["type"] == "heading" and n["attrs"] == {"level": 3} and n["content"][0]["text"].startswith(prefix) for n in (old, new))
            and len(new["content"]) == 1 and set(new["content"][0]) == {"type", "text"}, "the requested PR heading changed scope")
    return document


def fetch(site, path, header, *, limit=131072):
    status, redirect, body = public_get(site, path, header, limit)
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
        except (Unconfirmed, ValueError, TypeError, KeyError):
            reason = "readback-response"
    raise CommentReadbackError(reason)


class CommentReadbackError(UncertainWrite):
    """Bounded known-ID readback did not verify the publication."""


class CommentResponseError(UncertainWrite):
    def __init__(self, status):
        self.status = status


def send_comment(site, path, header, document, method, status):
    # http.client does not follow redirects or retry mutation requests.
    connection = http.client.HTTPSConnection(site, timeout=30)
    try:
        connection.request(method, path, body=json.dumps({"body": document}).encode(), headers={
            "Authorization": header, "Content-Type": "application/json", "Accept": "application/json"})
        response = connection.getresponse()
        payload = response.read(131073)
        if response.status != status or len(payload) > 131072:
            raise CommentResponseError(response.status)
        try:
            return json.loads(payload)
        except ValueError:
            raise CommentResponseError(response.status) from None
    finally:
        connection.close()


def put_comment(site, path, header, document):
    return send_comment(site, path, header, document, "PUT", 200)


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


ISSUE = r"https://([a-z0-9-]+\.atlassian\.net)/browse/([A-Z][A-Z0-9_]+-[1-9][0-9]*)"
ID = r"[1-9][0-9]*"
DIGEST = r"[0-9a-f]{64}"
FAILED_READ = (Unconfirmed, OSError, KeyError, ValueError, TypeError, subprocess.SubprocessError, http.client.HTTPException)


def context_file(ledger):
    return ledger.with_suffix(".jira-publication.json")


def publication_context(path):
    require(not run_bytes("git", "ls-files", "--", str(path)).strip(), "publication context is tracked")
    return json.loads(read_file(path, True, 16384))


def publication_guard(ledger, site, key, target=None, issue_id=None):
    prefix = f"jira summary publication {site}/"
    reserving = set()
    completed = set()
    require(not pending_uploads(ledger, site, key), "prior attachment pending", UncertainWrite)
    ledger.seek(0)
    for line in ledger:
        _, _, event, name, detail = line.rstrip("\n").split("|")
        if name.startswith(f"jira media summary {site}/{key} ") and event == "note":
            raise UncertainWrite("prior legacy comment pending")
        if name.startswith(prefix):
            previous_key, previous_id, _ = name[len(prefix):].split("/", 2)
            if previous_key != key and previous_id != issue_id:
                continue
            if event == "note" and "publication reserved; " in detail:
                reserving.add(name)
            if event == "external-write" and detail == "publication verified (exit 0)":
                completed.add(name)
    require(not (reserving - completed) and target not in completed, "prior publication pending or already verified", UncertainWrite)


def guard_legacy(site, key):
    slug = Path(os.environ["AUDIT_TRAIL_FILE"]).stem
    with locked_ledger(slug) as (file, ledger):
        publication_guard(ledger, site, key)
        context = context_file(file)
        if context.exists() or context.is_symlink():
            # An in-flight API dispatch cannot be redirected into the text-only ACLI path.
            plan = publication_context(context)
            require(plan["issue"] != f"https://{site}/browse/{key}", "Jira publication context requires API")


def valid_adf(body):
    return isinstance(body, dict) and body.get("type") == "doc" and type(body.get("version")) is int and body["version"] == 1 and isinstance(body.get("content"), list)


def wiki_document(wiki, plan, work):
    source = work / "summary.wiki"
    source.write_bytes(wiki)
    checks = HERE.parent.parent / "pr-summary" / "scripts"
    args = ["php", str(checks / "check-jira-comment.php"), "--criteria", plan["criteria"],
            "--author", plan["review_author"], "--repository", plan["repository"],
            "--jira-issue", plan["issue"], "--revision", plan["revision"]]
    for number in plan["pull_requests"]:
        args.extend(("--pull-request", number))
    run_bytes(*args, str(source))
    document = json.loads(run_bytes("php", str(HERE / "wiki-markup-to-adf.php"), data=wiki))
    require(valid_adf(document), "invalid converted summary")
    for node in document["content"]:
        if node["type"] == "heading":
            title = "".join(child.get("text", "") for child in node["content"])
            if title.startswith("PR #"):
                require(node["attrs"]["level"] == 3 and len(title) <= 255 and all(
                    text["type"] == "text" and not text.get("marks") for text in node["content"]), "invalid PR heading")
    return document


def expected_summary(body):
    if not valid_adf(body) or not body["content"]:
        return False
    opening = body["content"][0]
    byline = (opening.get("content") or [{}])[0].get("text", "") if opening["type"] == "paragraph" else ""
    titles = [node.get("attrs", {}).get("title", "") for node in body["content"] if node["type"] == "expand"]
    return (byline.removeprefix("🤖 ").startswith("AI Olympus · ")
            and any(title.endswith(("Acceptance criteria", "Akceptační kritéria")) for title in titles)
            and any(title.endswith(("Findings from the code review", "Nálezy z kontroly kódu")) for title in titles))


def comment_snapshot(site, path, header):
    comments = []
    seen = set()
    total = None
    while True:
        offset = len(comments)
        page = fetch(site, f"{path}?startAt={offset}&maxResults=100&orderBy=created", header, limit=2097152)
        batch = page["comments"]
        count = page["total"]
        require(type(page["startAt"]) is int and page["startAt"] == offset and type(count) is int
                and offset <= count <= 2000 and (total is None or total == count)
                and isinstance(batch, list) and len(batch) <= 100 and offset + len(batch) <= count
                and (batch or offset == count), "incomplete Jira comment snapshot")
        total = count
        for comment in batch:
            number = str(comment["id"])
            require(re.fullmatch(ID, number) and number not in seen and valid_adf(comment["body"])
                    and re.fullmatch(r"[A-Za-z0-9:_-]+", comment["author"]["accountId"]), "invalid comment snapshot")
            seen.add(number)
        comments.extend(batch)
        if len(comments) == total:
            return comments, seen


def reject_duplicate(comments, document):
    run_bytes("jq", "-e", "-L", str(HERE), 'include "summary-content"; (.document | summary_content) as $source '
              '| all(.comments[]; (.body | summary_content) != $source)',
              data=json.dumps({"comments": comments, "document": document}).encode())


def check_baseline(repo, branch, revision):
    try:
        require(default_branch(repo) == branch and baseline_revision(repo, branch) == revision, "baseline changed")
    except FAILED_READ as error:
        raise StaleReview("the regression baseline could not be reconfirmed") from error


def prepare_media(binding, slug, manifest, document, work, source, session, issue_id, locked, before_upload):
    pr, head, repo, number, review = source
    try:
        *_, default, pairs = selection(binding, slug, manifest, document, work, source=source)
    except (StaleReview, DestinationChanged):
        raise
    except FAILED_READ:
        print("jira-summary: no eligible verified images", file=sys.stderr)
        return None

    site, actor, header = session
    prepared = []
    baseline = None
    for fid, state, _, item, data, digest, revision in sorted(pairs, key=lambda pair: pair[1] != "head"):
        if state == "base" and not any(f == fid for f, _, *_ in prepared):
            continue
        if state == "base":
            check_baseline(repo, default, revision)
        name = f"review-{fid}-{state}-{digest}.png"
        try:
            media = confirmed_file(binding["key"], slug, name, data, digest, (site, actor), issue_id,
                                   session=session, locked=locked, before_upload=before_upload)
        except (StaleReview, DestinationChanged, UncertainWrite):
            raise
        except FAILED_READ:
            print("jira-summary: capture verification unavailable before upload", file=sys.stderr)
            continue
        if state == "base":
            baseline = (repo, default, revision)
        prepared.append((fid, state, item, name, data, digest, media))

    if prepared:
        inventory = attachment_inventory(binding["key"], site, issue_id, header=header)
        czech = any(n.get("attrs", {}).get("title") == "⚠️ Nálezy z kontroly kódu" for n in document["content"])
        for _, state, item, name, data, digest, media in sorted(prepared, key=lambda pair: pair[1] == "head"):
            matches = [entry for entry in inventory if entry["filename"] == name]
            require(len(matches) == 1 and attachment_proof(site, actor, header, matches[0], name, data, digest) == media,
                    "attachment proof changed")
            label = (("Výchozí větev" if czech else "Default branch") + " (" + default + ")"
                     if state == "base" else ("Tato změna" if czech else "This change"))
            attach_media(item, media, label)
    revalidate(pr, binding["key"], head, repo, number, review, work)
    return baseline


def publish(input_target, wiki_path, comment_id, previous_path, manifest, slug):
    stage = "publication-context"
    known = ""
    response_status = ""
    action = "updated" if comment_id else "created"
    try:
        slug = slug or Path(os.environ["AUDIT_TRAIL_FILE"]).stem
        with locked_ledger(slug, writable=True) as locked, tempfile.TemporaryDirectory() as temporary:
            file, ledger = locked
            work = Path(temporary)
            plan = publication_context(context_file(file))
            common = {"mode", "issue", "issue_id", "actor", "wiki_sha256", "repository", "pull_requests", "revision", "criteria", "review_author"}
            edit = {"comment_id", "previous_sha256"}
            create = {"review_pr", "review_home", "review_id", "review_body_sha256"}
            require(set(plan) == common | (edit if comment_id else create), "unexpected publication context fields")
            destination = re.fullmatch(ISSUE, plan["issue"])
            issue_id, actor = plan["issue_id"], plan["actor"]
            require(destination and re.fullmatch(ID, issue_id) and re.fullmatch(r"[A-Za-z0-9:_-]+", actor)
                    and plan.get("comment_id", "") == comment_id
                    and plan["mode"] == ("operator-edit" if comment_id else "create"), "invalid publication context")
            if comment_id:
                require(re.fullmatch(ID, comment_id) and re.fullmatch(DIGEST, plan["previous_sha256"]), "invalid edit binding")
                operation = f'edit/{comment_id}/{plan["previous_sha256"]}'
            else:
                require(plan["review_home"] in ("review", "comment") and re.fullmatch(ID, plan["review_id"]),
                        "invalid create binding")
                operation = f'create/{plan["review_home"]}/{plan["review_id"]}'
            site, key = destination.groups()
            stage = "destination"
            require(input_target in (key, plan["issue"]), "Jira target differs from dispatch")
            target = f"jira summary publication {site}/{key}/{issue_id}/{operation}"
            stage = "prior-attempt"
            publication_guard(ledger, site, key, target, issue_id)
            stage = "approved-wiki"
            if wiki_path == "-":
                wiki = sys.stdin.buffer.read(65537)
                require(0 < len(wiki) <= 65536 and hashlib.sha256(wiki).hexdigest() == plan["wiki_sha256"],
                        "stdin differs from publication context")
            else:
                wiki = read_pinned(Path(wiki_path).absolute(), plan["wiki_sha256"])
            document = wiki_document(wiki, plan, work)
            stage = "identity"
            session = authenticated_identity(site, actor)
            require(session[:2] == (site, actor), "unexpected Jira session")
            _, _, header = session
            stage = "issue"
            issue_path = "/rest/api/3/issue/" + issue_id
            issue_origin(fetch(site, issue_path + "?fields=key", header), site, key, issue_id)
            path = issue_path + "/comment"
            reserved = False
            evidence = "wiki SHA-256 " + plan["wiki_sha256"]
            source = None

            def reserve():
                nonlocal reserved
                if not reserved:
                    try:
                        durable_intent(file, ledger, target, "publication reserved; " + evidence)
                    except FAILED_READ as error:
                        raise UncertainWrite("publication intent could not be made durable") from error
                    reserved = True

            before = set()
            if comment_id:
                stage = "previous-comment"
                path += "/" + comment_id
                previous = json.loads(read_pinned(Path(previous_path).absolute(), plan["previous_sha256"], True))
                require(expected_summary(previous) and not same_json(previous, document), "prior body is not a changed summary")
                check_comment(fetch(site, path, header), comment_id, actor, previous)
                evidence += " prior SHA-256 " + plan["previous_sha256"]
            else:
                stage = "review"
                review_pr = plan["review_pr"]
                require(review_pr in [f'https://github.com/{plan["repository"]}/pull/{n}' for n in plan["pull_requests"]],
                        "review PR differs from dispatch", StaleReview)
                source = source_review(review_pr, key, plan["revision"], work)
                review = source[-1]
                require(str(review["id"]) == plan["review_id"] and review["home"] == plan["review_home"]
                        and hashlib.sha256(review["body"].encode()).hexdigest() == plan["review_body_sha256"],
                        "latest trusted review differs from dispatch", StaleReview)
                evidence += f' review {review["home"]}/{review["id"]} at {source[1]}'

                stage = "comment-snapshot"
                comments, _ = comment_snapshot(site, path, header)
                reject_duplicate(comments, document)
                stage = "media"

                def before_upload():
                    pr, head, repo, number, original = source
                    revalidate(pr, key, head, repo, number, original, work)
                    reserve()

                baseline = prepare_media({"site": site, "key": key, "account": actor}, slug, manifest, document, work,
                                         source, session, issue_id, locked, before_upload)
                stage = "comment-snapshot"
                comments, before = comment_snapshot(site, path, header)
                reject_duplicate(comments, document)
                stage = "review"
                pr, head, repo, number, original = source
                if baseline is not None:
                    check_baseline(*baseline)
                revalidate(pr, key, head, repo, number, original, work)

            stage = "comment-intent"
            reserve()
            durable_intent(file, ledger, target, action + " comment intent; no automatic retry")
            if comment_id:
                stage = "previous-comment"
                check_comment(fetch(site, path, header), comment_id, actor, previous)
                known = comment_id
            stage = "comment-response"
            try:
                reply = (put_comment(site, path, header, document) if comment_id
                         else send_comment(site, path, header, document, "POST", 201))
                candidate = str(reply["id"])
                require(re.fullmatch(ID, candidate) and candidate not in before and (not comment_id or candidate == comment_id),
                        "Jira response did not identify the expected comment")
            except FAILED_READ as error:
                if isinstance(error, CommentResponseError):
                    response_status = str(error.status)
                if not comment_id:
                    raise
                stage = "known-comment"
                durable_record(file, ledger, target, 3, "response unconfirmed for named comment " + known
                               + ((" HTTP " + response_status) if response_status else ""))
            else:
                known = candidate
                stage = "known-comment"
                durable_record(file, ledger, target, 0, "response identified comment " + known)
            if not comment_id:
                path += "/" + known
            stage = "comment-readback"
            readback_comment(site, path, header, known, actor, document)
            link = plan["issue"] + "?focusedCommentId=" + known
            durable_record(file, ledger, link, 0, action + ", exact ADF verified")
            durable_record(file, ledger, target, 0, "publication verified")
            print(link)
            print("action=" + action + " id=" + known, file=sys.stderr)
            return 0
    except StaleReview:
        stage = "review"
    except CommentReadbackError as error:
        stage = str(error)
    except FAILED_READ:
        pass
    print("jira-summary: " + stage + ((" status=" + response_status) if response_status else "")
          + ((" id=" + known) if known else ""), file=sys.stderr)
    return 3


if __name__ == "__main__":
    if len(sys.argv) == 4 and sys.argv[1] == "--guard-legacy":
        try:
            guard_legacy(*sys.argv[2:])
        except FAILED_READ:
            sys.exit(3)
    elif len(sys.argv) == 7:
        sys.exit(publish(*sys.argv[1:]))
    else:
        sys.exit(1)
