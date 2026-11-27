"""Exercise public upsert with actual Wiki/media tooling and offline tracker responses."""
import base64
from concurrent.futures import ThreadPoolExecutor
from contextlib import nullcontext
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import runpy
import struct
import subprocess
import sys
import tempfile
import zlib

ROOT = Path(__file__).resolve().parents[3]
SCRIPTS = ROOT / "skills/code-review-jira/scripts"
SHARED = ROOT / "skills/_shared"
SITE, KEY, ISSUE, ACTOR = "example.atlassian.net", "TEST-1", "123", "qa"
URL = f"https://{SITE}/browse/{KEY}"
PULL = "https://github.com/example/project/pull/7"
HEAD, BASE, ASSET = "a" * 40, "b" * 40, "c" * 40
ISSUE_PATH = f"/rest/api/3/issue/{ISSUE}"
BACKEND = "jira-prod-eu-33-as-3.prod.atl-paas.net"


def read(path):
    return json.loads(path.read_text())


def save(path, value):
    path.write_text(json.dumps(value))
    path.chmod(0o600)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def issue_result(issue=ISSUE):
    return {"id": issue, "key": KEY, "self": f"https://{BACKEND}/rest/api/3/issue/{issue}"}


def stored_comment(body, actor=ACTOR):
    return {"id": "17", "author": {"accountId": actor}, "jsdPublic": True, "body": body}


def keep_log(root, name, line):
    with (root / name).open("a") as log:
        log.write(line + "\n")


class Response:
    def __init__(self, code, value, location=None):
        self.status = code
        self.value = value if isinstance(value, bytes) else json.dumps(value).encode()
        self.headers = http.client.HTTPMessage()
        if location is not None:
            self.headers["Location"] = location

    def read(self, count):
        return self.value[:count]


class Connection:
    def __init__(self, host, timeout):
        assert host == SITE and timeout > 0
        self.root = Path(os.environ["JIRA_TEST_ROOT"])
        self.mode = os.environ["JIRA_TEST_MODE"]

    def request(self, method, path, body=None, headers=None):
        credential = base64.b64encode(b"qa@example.test:fixture-token").decode()
        assert headers["Authorization"] == "Basic " + credential
        keep_log(self.root, "calls", f"{method} {path}")
        self.reply = self.get(path) if method == "GET" else self.mutate(method, path, body)

    def get(self, path):
        if path == "/rest/api/3/myself":
            return Response(200, {"accountId": "other" if self.mode == "actor" else ACTOR})
        if path == ISSUE_PATH + "?fields=project":
            project = {"platform": "software", "platform-edit": "business", "unknown-project": "unknown"}.get(self.mode, "service_desk")
            return Response(200, {**issue_result("456" if self.mode == "issue" else ISSUE),
                                  "fields": {"project": {"projectTypeKey": project}}})
        if path == ISSUE_PATH + "?fields=attachment":
            return Response(200, {**issue_result(), "fields": {"attachment": read(self.root / "attachments")}})
        if path.startswith(ISSUE_PATH + "/comment?"):
            comments = read(self.root / "comments")
            return Response(200, {"startAt": 0, "total": len(comments) + (self.mode == "partial"), "comments": comments})
        if path == ISSUE_PATH + "/comment/17":
            comments = read(self.root / "comments")
            return Response(200, comments[0]) if comments else Response(404, {})
        content = re.fullmatch(r"/rest/api/3/attachment/content/([1-9][0-9]*)\?redirect=(false|true)", path)
        assert content, path
        number, redirect = content.groups()
        if redirect == "false":
            record = self.root / ("attachment-" + number)
            if self.mode == "late-base" and number == "40":
                observed = self.root / "baseline-observed"
                if observed.exists():
                    return Response(200, b"")
                observed.touch()
            return Response(200, record.read_bytes())
        return Response(303, b"", "https://api.media.atlassian.com/file/" + media_id(number) + "/binary?token=redacted-in-tests")

    def mutate(self, method, path, body):
        # The response deliberately drops or withholds the ID in uncertainty scenarios.
        ledger = (self.root / ".codex/run/fixture.audit").read_text()
        assert "publication reserved;" in ledger
        if path == ISSUE_PATH + "/attachments":
            assert method == "POST" and "upload intent; no automatic retry" in ledger
            attachments = read(self.root / "attachments")
            number = str(40 + len(attachments))
            name = re.search(br'filename="([^"]+)"', body)[1].decode()
            png = body.split(b"\r\n\r\n", 1)[1].rsplit(b"\r\n--", 1)[0]
            item = {"id": number, "filename": name, "size": len(png), "mimeType": "image/png",
                    "author": {"accountId": ACTOR}, "content": f"https://{BACKEND}/rest/api/3/attachment/content/{number}"}
            save(self.root / "attachments", [*attachments, item])
            (self.root / ("attachment-" + number)).write_bytes(png)
            if self.mode == "upload-lost":
                raise TimeoutError
            return Response(200, [item])
        assert path == ISSUE_PATH + ("/comment/17" if method == "PUT" else "/comment"), path
        assert "comment intent; no automatic retry" in ledger
        document = json.loads(body)
        assert set(document) == {"body"}
        saved = stored_comment(document["body"])
        if self.mode in ("platform", "platform-edit"):
            del saved["jsdPublic"]
        if self.mode == "mismatched-readback":
            saved["body"]["content"][0]["content"][0]["text"] = "Edited between write and confirmation."
        save(self.root / "comments", [saved])
        if self.mode == "create-lost":
            raise TimeoutError
        if self.mode == "edit-lost":
            return Response(502, {"private-error": "unexpected internal response"})
        return Response(200 if method == "PUT" else 201, saved, URL + "?focusedCommentId=17")

    def getresponse(self):
        return self.reply

    def close(self):
        pass


def media_id(number):
    return "12345678-9012-3456-7890-" + number.zfill(12)


def fake_github(args):
    root = Path(os.environ["JIRA_TEST_ROOT"])
    mode = os.environ["JIRA_TEST_MODE"]
    if args[:2] == ["pr", "view"]:
        assert args[2] == PULL
        previous = root / "heads"
        head = BASE if mode == "head-moved" and previous.exists() else HEAD
        previous.touch()
        print(json.dumps({"url": PULL, "headRefOid": head, "isCrossRepository": False, "title": f"{KEY} Save deliveries"}))
        return
    assert args[:3] == ["api", "--hostname", "github.com"] and "-X" not in args, args
    endpoint = next(a for a in args[1:] if a.startswith("repos/example/project"))
    if "/reviews?" in endpoint:
        review = read(root / "review")
        previous = root / "reviews"
        count = int(previous.read_text()) if previous.exists() else 0
        if (mode == "review-moved" and count > 0) or (mode == "review-before-comment" and count >= 4):
            review["id"] += 1
        previous.write_text(str(count + 1))
        print(json.dumps([[review]]))
    elif "/comments?" in endpoint:
        print(json.dumps([[read(root / "legacy-review")]] if mode == "legacy-review" else [[]]))
    elif "/compare/" in endpoint:
        print('{"status":"ahead"}')
    elif "/contents/" in endpoint:
        path = endpoint.split("/contents/")[1].split("?")[0]
        image = Path(read(root / "assets")[path]).read_bytes()
        if "Accept: application/vnd.github.raw+json" in args:
            sys.stdout.buffer.write(image)
        elif "--jq" in args:
            print(len(image))
        else:
            print(json.dumps({"type": "file", "size": len(image)}))
    elif "/branches/" in endpoint:
        assert endpoint.endswith("/release")
        print(BASE)
    else:
        assert endpoint == "repos/example/project"
        print("release")


def fake_python(script, args):
    if Path(script).name != "jira_comment_api.py":
        os.execv(sys.executable, [sys.executable, "-B", script, *args])
    http.client.HTTPSConnection = Connection
    if os.environ["JIRA_TEST_MODE"] in ("sync", "sync-edit"):
        def unsynced(fd):
            raise OSError("fixture fsync failed")
        os.fsync = unsynced
    sys.argv = [script, *args]
    sys.path.insert(0, str(SCRIPTS))
    runpy.run_path(script, run_name="__main__")


def wiki(save_word="Save", send_word="Send"):
    links = [f"[See in code →|{PULL}/files#diff-{digest(name.encode())}R{line}]" for name, line in
             (("src/Save.php", 2), ("src/Send.php", 3))]
    return f"""AI Olympus · Saving and sending deliveries fail. The change is still under review.

{{expand:⚠️ Acceptance criteria}}
# ⚠️ Staff can save and send a delivery. Both steps failed.
{{expand}}
{{expand:⚠️ Findings from the code review}}
* *(bug):* Saving a delivery fails. Keep the completed fields and let staff save again. {links[0]}
* *(bug):* Sending a delivery is a regression against the default branch. Keep the previous way to send it. {links[1]}
{{expand}}
{{expand:⚠️ Test scenarios}}
# ⚠️ {save_word} a complete delivery. It should appear on the list.
# ⚠️ {send_word} the delivery. Its status should change.
{{expand}}
{{expand:What changed and what it affects}}
* {save_word} and {send_word} are available on the delivery screen.
{{expand}}
""".encode()


def build_review(root, captured, legacy, *, include_baseline=True):
    report = f"""# Code Review

**Status:** needs-fix
**Counts:** Critical 2 · Moderate 0
**Reviewed revision:** {HEAD}
**Reviewed diff fingerprint:** 1234567890abcdef
**Last updated:** 2026-11-20T10:00:00Z

---

## Technical Review

## Findings

"""
    for number, step in enumerate(("Save", "Send"), 1):
        report += f"""### 🔴 Critical {number}. {step} delivery fails

- **Location:** `src/{step}.php:{number + 1}`
- **Rule:** observable behaviour
- **Provenance:** `regression — introduced in this revision`
- **Impact:** Staff cannot complete the delivery.
- **Faulty Example:** The completed fields disappear after the action.
- **Expected behavior:** Staff can complete the action.
- **Evidence:** The {step} scenario reproduced the failure on desktop at the reviewed revision.
- **Test hint:** Check the delivery screen.
- **Suggested fix:** Preserve the previous action.

"""
    report += """## Interactive testing

| Step | Viewport | Result | Details |
| Save | desktop | Failed | The delivery was not saved. |
| Send | desktop | Failed | The delivery was not sent. |
"""
    report_path = root / "report.md"
    report_path.write_text(report)
    records = subprocess.check_output(["bash", str(SHARED / "extract-findings.sh"), "--round", "--repo", "example/project",
                                       "--pr", "7", "--input", str(report_path)])
    findings = {Path(record["file"]).stem: record["finding_id"] for record in map(json.loads, records.splitlines())}
    manifest, evidence, assets, images = [], [], {}, []
    artifact = root / ".codex/run/fixture.artifacts"
    artifact.mkdir()
    for index, (step, state) in enumerate((("Save", "head"), ("Send", "head"), ("Send", "base"))):
        def chunk(name, data):
            return struct.pack(">I", len(data)) + name + data + struct.pack(">I", zlib.crc32(name + data))
        raw = root / "raw.png"
        raw.write_bytes(b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", 2, 1, 8, 2, 0, 0, 0))
                        + chunk(b"IDAT", zlib.compress(bytes([0, index, 100, 225, 24, 56, 78]))) + chunk(b"IEND", b""))
        image = artifact / f"{step.lower()}-{state}.png"
        subprocess.run(["php", str(SHARED / "sanitize-png.php"), str(raw), str(image)], capture_output=True, check=True)
        fid = findings[step]
        entry = dict(file=str(image), finding_id=fid, finding_type="bug", state=state, do_not_file_publicly=False)
        evidence.append(dict(entry, step=step, viewport="desktop", kind="ui", verdict="passed" if state == "base" else "failed",
                             own_pixels=True, visually_verified=True, secret_free=True, regression=step == "Send",
                             revision=BASE if state == "base" else HEAD, sha256=digest(image.read_bytes())))
        manifest.append(dict(entry, crop="element", masking="none", masked_in_capture_session=False, not_error_page=True,
                             own_data_only=True, default_branch="release" if state == "base" else None))
        key = f"pr-7/{HEAD[:12]}-{fid}-{state}.png"
        assets[key] = str(image)
        label = "Default branch (release)" if state == "base" else "This change"
        images.append(f"![{label}](https://github.com/example/project/blob/{ASSET}/{key}?raw=true)")
    run = root / ".codex/run"
    save(root / "assets", assets)
    save(run / "fixture.screenshots.json", [entry for entry in manifest if include_baseline or entry["state"] != "base"] if captured else [])
    save(run / "fixture.screenshot-evidence.json", {"jira_source": URL, "target": PULL, "reviewed_head": HEAD, "captures": evidence})
    encoded = report.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").rstrip()
    body = "\n".join([*images, "<!-- cr-review-meta", encoded, "-->", "<!-- cr-comment:actor=qa -->"])
    save(root / "review", {"id": 9, "body": body, "state": "CHANGES_REQUESTED", "commit_id": HEAD,
                          "submitted_at": "2026-11-20T10:00:00Z", "user": {"login": "qa"}, "author_association": "MEMBER"})
    if legacy:
        body = "\n".join([report, *images, "<!-- cr-comment:actor=qa -->"])
        save(root / "legacy-review", {"id": 10, "body": body, "created_at": "2026-11-21T10:00:00Z",
                                      "user": {"login": "qa"}, "author_association": "MEMBER"})
    return digest(body.encode())


def launch(root, mode, command):
    return subprocess.run([str(SCRIPTS / "upsert-comment.sh"), *command], cwd=root, capture_output=True, text=True,
                          timeout=75, env={**os.environ, "PATH": str(root / "bin") + os.pathsep + os.environ["PATH"],
                                           "JIRA_TEST_ROOT": str(root), "JIRA_TEST_MODE": mode, "TEST_PYTHON": sys.executable,
                                           "TEST_ENTRY": str(Path(__file__).resolve()), "ACLI_CONFIG_DIR": str(root / "profile"),
                                           "JIRA_API_EMAIL": "qa@example.test", "JIRA_API_TOKEN": "fixture-token",
                                           "AUDIT_TRAIL_FILE": str(root / ".codex/run/fixture.audit"), "AUDIT_TRAIL_ROLE": "april"})


def scenario(mode, receipt=None):
    with (tempfile.TemporaryDirectory(prefix="jira-publication-") if receipt is None else nullcontext(receipt)) as temp:
        root = Path(temp).resolve()
        run = root / ".codex/run"
        run.mkdir(parents=True)
        bin_dir = root / "bin"
        bin_dir.mkdir()
        (run / "fixture.audit").touch(mode=0o600)
        subprocess.run(["git", "init", "--quiet"], cwd=root, check=True)
        subprocess.run(["git", "remote", "add", "origin", "https://github.com/example/project.git"], cwd=root, check=True)
        scripts = {
            "python3": 'if [[ "$1" == -B ]]; then shift; fi\nexec "$TEST_PYTHON" -B "$TEST_ENTRY" --python "$@"\n',
            "gh": 'exec "$TEST_PYTHON" -B "$TEST_ENTRY" --gh "$@"\n',
            "acli": '''[[ "$ACLI_CONFIG_DIR" == "$JIRA_TEST_ROOT/profile" ]] || exit 91
case "$1 $2 $3" in
  'jira auth status') printf 'Site: example.atlassian.net\nEmail: qa@example.test\n' ;;
  'jira workitem search') echo '[{"fields":{"assignee":{"accountId":"qa"}}}]' ;;
  'jira workitem view') echo '{"fields":{"comment":{"startAt":0,"total":0,"comments":[]}}}' ;;
  *) echo 'legacy mutation unexpectedly attempted' >&2; exit 92 ;;
esac
''',
        }
        for name, source in scripts.items():
            path = bin_dir / name
            path.write_text("#!/usr/bin/env bash\n" + source)
            path.chmod(0o700)
        save(root / "comments", [])
        save(root / "attachments", [])
        (root / "calls").touch()
        source = root / "summary.wiki"
        source.write_bytes(wiki("Store", "Release"))
        manifest = run / "fixture.screenshots.json"
        context = run / "fixture.jira-publication.json"
        editing = mode in ("edit", "historical", "edit-lost", "mismatched-readback", "target", "actor", "issue",
                           "previous", "foreign-comment", "visibility", "no-context", "wiki-change", "manual",
                           "platform-edit", "jsm-unknown", "unknown-project", "sync-edit")
        plan = dict(mode="operator-edit" if editing else "create", actor=ACTOR, issue=URL, issue_id=ISSUE,
                    criteria="present", review_author="none", repository="example/project", revision=HEAD,
                    pull_requests=["7"], wiki_sha256=digest(source.read_bytes()))
        if editing:
            old_wiki = b"Manual note from the operator." if mode == "manual" else wiki()
            if mode == "historical":
                old_wiki = "🤖 ".encode() + old_wiki
            previous = root / "prior.json"
            converted = subprocess.run(["php", str(SCRIPTS / "wiki-markup-to-adf.php")], input=old_wiki, capture_output=True, check=True).stdout
            save(previous, json.loads(converted))
            plan.update(comment_id="17", previous_sha256=digest(previous.read_bytes()))
            old = stored_comment(read(previous), "other" if mode == "foreign-comment" else ACTOR)
            if mode == "previous":
                old["body"]["content"][0]["content"][0]["text"] += " The operator edited the note."
            if mode == "visibility":
                old["jsdPublic"] = False
            if mode in ("platform-edit", "jsm-unknown"):
                del old["jsdPublic"]
            save(root / "comments", [old])
            arguments = ["--operator-edit", "--comment-id", "17", "--previous-adf", str(previous)]
        else:
            legacy = mode == "legacy-review"
            review_sha = build_review(root, captured=mode not in ("create-lost", "text", "concurrent"),
                                      legacy=legacy, include_baseline=mode != "no-base")
            proof = run / "fixture.screenshot-evidence.json"
            plan.update(review_pr=PULL, review_id="10" if legacy else "9", qa_sha256=digest(proof.read_bytes()),
                        review_home="comment" if legacy else "review", review_body_sha256=review_sha)
            if mode == "qa-flip":
                changed = read(proof)
                capture = changed["captures"][0]
                image = Path(capture["file"])
                image.write_bytes(Path(changed["captures"][1]["file"]).read_bytes())
                capture["sha256"] = digest(image.read_bytes())
                save(proof, changed)
            arguments = ["--create", "--screenshots", str(manifest), "--slug", "fixture"]
        if mode != "no-context":
            save(context, plan)
        if mode == "wiki-change":
            source.write_bytes(wiki("Save", "Release"))
        target = "https://other-example.atlassian.net/browse/TEST-1" if mode == "target" else KEY
        command = ["--summary", *arguments, target, str(source)]
        if mode == "concurrent":
            with ThreadPoolExecutor(max_workers=2) as pool:
                futures = [pool.submit(launch, root, mode, command) for _ in range(2)]
                first, second = sorted((future.result() for future in futures), key=lambda p: p.returncode)
            assert second.returncode == 3 and "prior-attempt" in second.stderr
            result = first
        else:
            result = launch(root, mode, command)
        calls = (root / "calls").read_text().splitlines()
        mutations = [call for call in calls if not call.startswith("GET ")]

        if receipt is not None:
            save(root / "result.json", {"exit": result.returncode, "stdout": result.stdout, "stderr": result.stderr, "requests": calls})
        if mode in ("edit", "historical", "edit-lost", "media", "legacy-review", "text", "concurrent",
                    "platform", "platform-edit", "qa-flip", "no-base", "late-base"):
            assert result.returncode == 0, (mode, result.stderr, calls)
            assert result.stdout.strip() == URL + "?focusedCommentId=17"
            body = read(root / "comments")[0]["body"]
            if editing:
                assert mutations == ["PUT " + ISSUE_PATH + "/comment/17"]
                assert "Store a complete delivery" in json.dumps(body) and "Store and Release are available" in json.dumps(body)
                assert sum(call == "GET " + ISSUE_PATH + "/comment/17" for call in calls) == 3
            elif mode in ("media", "legacy-review", "platform"):
                assert mutations == ["POST " + ISSUE_PATH + "/attachments"] * 3 + ["POST " + ISSUE_PATH + "/comment"]
                findings = next(n for n in body["content"] if n.get("attrs", {}).get("title") == "⚠️ Findings from the code review")
                first, second = [item["content"] for item in findings["content"][0]["content"]]
                for item, ids, labels in ((first, ["41"], ["This change"]),
                                           (second, ["40", "42"], ["Default branch (release)", "This change"])):
                    assert len(item) == 1 + len(ids) * 2
                    for index, (number, caption) in enumerate(zip(ids, labels), 1):
                        assert item[2 * index - 1]["content"] == [{"type": "text", "text": caption, "marks": [{"type": "strong"}]}]
                        node = item[2 * index]["content"][0]
                        assert node["type"] == "media" and node["attrs"]["id"] == media_id(number)
            elif mode in ("no-base", "late-base"):
                findings = next(n for n in body["content"] if n.get("attrs", {}).get("title") == "⚠️ Findings from the code review")
                first, second = [item["content"] for item in findings["content"][0]["content"]]
                assert len(first) == 3 and len(second) == 1 and "text only" in result.stderr
                assert mutations == ["POST " + ISSUE_PATH + "/attachments"] * (3 if mode == "late-base" else 1) + ["POST " + ISSUE_PATH + "/comment"]
            else:
                assert mutations == ["POST " + ISSUE_PATH + "/comment"]
                assert "no eligible verified images" in result.stderr and "mediaSingle" not in json.dumps(body)
            assert "publication verified (exit 0)" in (run / "fixture.audit").read_text()
        elif mode in ("create-lost", "upload-lost"):
            assert result.returncode == 3 and len(mutations) == 1, (mode, result.stderr, calls)
            manifest.unlink()
            source.write_bytes(wiki("Save", "Release"))
            save(context, dict(plan, wiki_sha256=digest(source.read_bytes()), review_id="10"))
            second = launch(root, mode, command)
            assert second.returncode == 3 and "prior-attempt" in second.stderr
            context.unlink()
            legacy = launch(root, mode, ["--summary", "--create", KEY, str(source)])
            assert legacy.returncode == 3 and "reconciliation" in legacy.stderr
            assert (root / "calls").read_text().splitlines() == calls
        else:
            stages = {"target": "destination", "actor": "identity", "issue": "issue", "previous": "previous-comment",
                      "foreign-comment": "previous-comment", "review-before-comment": "review",
                      "visibility": "previous-comment", "no-context": "publication-context", "wiki-change": "approved-wiki",
                      "manual": "previous-comment", "partial": "comment-snapshot", "head-moved": "review", "review-moved": "review",
                      "sync": "media", "mismatched-readback": "readback-body id=17", "sync-edit": "comment-intent",
                      "unknown-project": "issue", "jsm-unknown": "previous-comment"}
            assert result.returncode == 3 and stages[mode] in result.stderr, (mode, result.stderr, calls)
            expected = {"mismatched-readback": ["PUT " + ISSUE_PATH + "/comment/17"],
                        "review-before-comment": ["POST " + ISSUE_PATH + "/attachments"] * 3}
            assert mutations == expected.get(mode, [])
        assert all(value not in result.stderr for value in ("fixture-token", "internal response", "AI Olympus"))


if __name__ == "__main__":
    if sys.argv[1] == "--gh":
        fake_github(sys.argv[2:])
    elif sys.argv[1] == "--python":
        fake_python(sys.argv[2], sys.argv[3:])
    else:
        scenario(sys.argv[1], sys.argv[2] if len(sys.argv) == 3 else None)
