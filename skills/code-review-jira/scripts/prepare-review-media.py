"""Prepare a Jira summary from verified images of the current published GitHub review."""
import hashlib
import http.client
import json
import re
import subprocess
import sys
import tempfile
from pathlib import Path

from jira_attachment_media import DestinationChanged, Unconfirmed, require, run_bytes, run_cli
from jira_review_media import HERE, SHARED, UncertainWrite, attach_media, canonical_png, confirmed_file, find_item, unresolved

GITHUB = HERE.parent.parent / "code-review-github" / "scripts"
PULL = r"https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/pull/([1-9][0-9]*)"


class StaleReview(Unconfirmed):
    """The source of the complete summary changed before publication."""


def current_head(pr, key, head):
    value = json.loads(run_cli("gh", "pr", "view", pr, "--json", "url,headRefOid,title,isCrossRepository"))
    require(value["url"] == pr and value["headRefOid"] == head and value["isCrossRepository"] is False
            and re.search(r"(?<![A-Z0-9_-])" + re.escape(key) + r"(?![A-Z0-9_-])", value["title"]), "pull request head or Jira source changed", StaleReview)


def current_review(repo, number, head, work):
    # The GitHub CLI returns review bodies via stdout. Only a private file, never jq argv, contains those bodies.
    pages = json.loads(run_cli("gh", "api", "--paginate", "--slurp", f"repos/{repo}/pulls/{number}/reviews?per_page=100"))
    reviews = [{"id": r["id"], "body": r["body"], "state": r["state"], "commit": r["commit_id"], "submittedAt": r["submitted_at"],
                "author": r["user"]["login"], "authorAssociation": r["author_association"]} for page in pages for r in page]
    pages = json.loads(run_cli("gh", "api", "--paginate", "--slurp", f"repos/{repo}/issues/{number}/comments?per_page=100"))
    comments = [{"id": c["id"], "body": c["body"], "createdAt": c["created_at"], "author": c["user"]["login"],
                 "authorAssociation": c["author_association"]} for page in pages for c in page]
    source = work / "pull.json"
    source.write_text(json.dumps({"reviews": reviews, "comments": comments}))
    selected = json.loads(run_cli("jq", "-ec", "-L", str(SHARED), 'include "review-trust"; newest_trusted_report', str(source)))
    require(selected["home"] == "review" and selected["state"] in ("APPROVED", "COMMENTED", "CHANGES_REQUESTED") and selected["commit"] == head,
            "the latest trusted review is not active on this head", StaleReview)
    return selected


def revalidate(pr, key, head, repo, number, review, work):
    try:
        current_head(pr, key, head)
        require(current_review(repo, number, head, work) == review, "the accepted review changed")
    except (Unconfirmed, KeyError, ValueError, TypeError, OSError, subprocess.SubprocessError) as reason:
        raise StaleReview("the accepted review could not be reconfirmed") from reason


def accepted_records(review, repo, number, work):
    source = work / "accepted.json"
    source.write_text(json.dumps({"reviews": [review]}))
    report = work / "review.md"
    report.write_text(run_cli("bash", str(SHARED / "cr-header.sh"), "--report", str(source)))
    lines = run_cli("bash", str(SHARED / "extract-findings.sh"), "--round", "--repo", repo, "--pr", number, "--input", str(report))
    return [json.loads(line) for line in lines.splitlines()], report.read_text()


def reviewed_asset(body, pr, repo, number, head, entry, data):
    name = head[:12] + "-" + entry["finding_id"] + "-" + entry["state"] + ".png"
    path = "pr-" + number + "/" + name
    prefix = "https://github.com/" + repo + "/blob/"
    pattern = r"^!\[" + re.escape(entry["label"]) + r"\]\((" + re.escape(prefix) + r"([0-9a-f]{40})/" + re.escape(path) + r"\?raw=true)\)$"
    urls = re.findall(pattern, body, re.M)
    require(len(urls) == 1, "the trusted review does not show the screenshot exactly once")
    url, commit = urls[0]
    run_cli("bash", str(GITHUB / "upload-review-asset.sh"), "verify", pr, url)
    endpoint = f"repos/{repo}/contents/{path}?ref={commit}"
    size = run_cli("gh", "api", endpoint, "--jq", 'select(.type=="file")|.size').strip()
    require(size == str(len(data)), "GitHub asset size differs from the approved crop")
    raw = run_bytes("gh", "api", endpoint, "-H", "Accept: application/vnd.github.raw+json")
    require(hashlib.sha256(raw).digest() == hashlib.sha256(data).digest(), "GitHub asset bytes differ from the approved crop")


def finding_item(document, pr, head, record, kind):
    require(isinstance(record["line"], int) and record["line"] > 0, "finding has no reviewed code location")
    args = ["php", str(HERE.parent.parent / "pr-summary/scripts/pr-code-link.php"), pr, record["file"], str(record["line"])]
    links = {run_cli(*args).strip(), run_cli(*args, head).strip()}

    def matches(paragraph):
        content = paragraph["content"]
        return content[0].get("text", "").startswith("(" + kind + "):") and any(
            m.get("type") == "link" and m.get("attrs", {}).get("href") in links for m in content[-1].get("marks", []))

    return find_item(document, matches)


def selection(binding, slug, manifest, document, work):
    key = binding["key"]
    root = run_cli("git", "rev-parse", "--show-toplevel").strip()
    qa_path = str(Path(manifest).with_name(slug + ".screenshot-evidence.json"))
    qa = json.loads(run_cli("python3", "-B", str(SHARED / "read-screenshot-artifact.py"), root, slug, "screenshot-evidence", qa_path))
    require(not run_cli("git", "ls-files", "--", qa_path).strip(), "capture evidence is tracked")
    require(qa.get("jira_source") == f'https://{binding["site"]}/browse/{key}', "Jira target differs from the original QA assignment")
    entries = json.loads(run_cli("bash", str(SHARED / "screenshot-guard.sh"), "manifest", manifest, slug))["accepted"]
    pr, head = qa["target"], qa["reviewed_head"]
    target = re.fullmatch(PULL, pr)
    require(target and re.fullmatch(r"[0-9a-f]{40}", head), "invalid QA target")
    repo, number = target.groups()
    run_cli("bash", str(SHARED / "assert-current-repo.sh"), pr)
    current_head(pr, key, head)
    review = current_review(repo, number, head, work)
    records, report = accepted_records(review, repo, number, work)
    visible = review["body"].split("\n<!-- cr-review-meta\n")[0]
    testing = re.search(r"^## Interactive testing\n((?:(?!^## ).)*)", report, re.M | re.S)
    require(testing, "no interactive testing section in the accepted review")
    failed = set()
    for line in testing[1].splitlines():
        cells = [cell.strip() for cell in line.split("|")]
        if len(cells) >= 5 and cells[3] in ("Failed", "Not met"):
            failed.add((cells[1], cells[2]))
    pairs = []
    default = run_cli("gh", "api", "repos/" + repo, "--jq", ".default_branch").strip()

    def approved(entry):
        fid, state, kind = entry["finding_id"], entry["state"], entry["finding_type"]
        candidates = [r for r in records if r["finding_id"] == fid and r["revision"] == head and r["status"] == "new" and r["security"] is False]
        captures = [c for c in qa["captures"] if c["finding_id"] == fid and c["state"] == state]
        require(len(candidates) == len(captures) == 1, "finding or independent capture is absent or ambiguous")
        record = candidates[0]
        require(sum(r["file"] == record["file"] and r["line"] == record["line"] for r in records) == 1,
                "more than one reviewed finding occupies this code location")
        capture = captures[0]
        step = (capture["step"], capture["viewport"])
        require(capture["finding_type"] == kind and capture["do_not_file_publicly"] is False and capture["file"] == entry["file"]
                and capture["kind"] == "ui" and capture["own_pixels"] is True and capture["visually_verified"] is True
                and capture.get("secret_free") is True
                and step in failed and step[1] in ("mobile", "desktop"), "capture is not the failed interactive step")
        if state == "head":
            require(capture["verdict"] == "failed" and capture["revision"] == head, "head capture is not a reproduced failure")
        else:
            require(entry["default_branch"] == default and capture["verdict"] == "passed" and capture["revision"] != head
                    and capture["revision"] == run_cli("gh", "api", f"repos/{repo}/branches/{default}", "--jq", ".commit.sha").strip(), "baseline has changed")
        item = finding_item(document, pr, head, candidates[0], kind)
        data = canonical_png(slug, entry["file"], capture["sha256"])
        reviewed_asset(visible, pr, repo, number, head, entry, data)
        return fid, state, step, item, data, capture["sha256"]

    for entry in entries:
        try:
            pairs.append(approved(entry))
        except (Unconfirmed, KeyError, TypeError, ValueError, OSError, subprocess.SubprocessError):
            print("Jira media: a finding lacks a unique verified capture", file=sys.stderr)
    pairs = [p for p in pairs if p[1] == "head" or any(t[0] == p[0] and t[1] == "head" and t[2] == p[2] for t in pairs)]
    require(pairs and all(any(t[0] == fid and t[1] == "head" and t[2] == step for t in pairs) for fid, _, step, *_ in pairs),
            "no matching approved HEAD capture for the tested step")
    require(len({p[0] for p in pairs}) == len({id(p[3]) for p in pairs}), "two findings point to the same summary item")
    return pr, head, repo, number, review, default, pairs


if __name__ == "__main__":
    try:
        require(len(sys.argv) == 6, "expected target, run slug, capture manifest, private destination binding, issue ID and ADF on stdin")
        key, slug, source, binding_file, issue_id = sys.argv[1:]
        require(re.fullmatch(r"[1-9][0-9]*", issue_id), "missing original issue ID", DestinationChanged)
        binding = json.loads(Path(binding_file).read_bytes())
        require(binding["key"] == key and re.fullmatch(r"[a-z0-9-]+\.atlassian\.net", binding["site"]) and binding["account"], "invalid original target")
        document = json.load(sys.stdin)
        require(document["type"] == "doc" and document["version"] == 1, "expected an ADF v1 summary")
        with tempfile.TemporaryDirectory() as temporary:
            work = Path(temporary)
            pr, head, repo, number, review, default, pairs = selection(binding, slug, source, document, work)
            profile = (binding["site"], binding["account"])
            prepared = []
            pending = unresolved(slug, binding["site"], key)
            def filename(pair):
                return f"review-{pair[0]}-{pair[1]}-{pair[5]}.png"
            require(pending.issubset({filename(pair) for pair in pairs}), "an earlier attachment lost its source proof", UncertainWrite)
            ordered = sorted(pairs, key=lambda pair: (filename(pair) not in pending, pair[1] != "head"))
            for pair in ordered:
                fid, state, _, item, data, digest = pair
                if state == "base" and not any(f == fid and s == "head" for f, s, _, _ in prepared):
                    continue
                revalidate(pr, key, head, repo, number, review, work)
                try:
                    media = confirmed_file(key, slug, filename(pair), data, digest, profile, issue_id)
                except (DestinationChanged, UncertainWrite):
                    raise
                except (Unconfirmed, OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError, http.client.HTTPException):
                    print("Jira media: verification failed before a new upload", file=sys.stderr)
                    continue
                prepared.append((fid, state, item, media))
            revalidate(pr, key, head, repo, number, review, work)
            czech = any(n.get("attrs", {}).get("title") == "⚠️ Nálezy z kontroly kódu" for n in document["content"])
            for _, state, item, media in sorted(prepared, key=lambda p: p[1] == "head"):
                if state == "base":
                    label = ("Výchozí větev" if czech else "Default branch") + " (" + default + ")"
                else:
                    label = "Tato změna" if czech else "This change"
                attach_media(item, media, label)
        json.dump({"adf": document, **binding}, sys.stdout)
    except (DestinationChanged, StaleReview, UncertainWrite) as stop:
        print("Jira media: " + str(stop), file=sys.stderr)
        sys.exit(3)
    except (Unconfirmed, KeyError, TypeError, ValueError, OSError, subprocess.SubprocessError, http.client.HTTPException) as error:
        reason = str(error) if isinstance(error, Unconfirmed) else "could not confirm source or attachment"
        print("Jira media: " + reason, file=sys.stderr)
        sys.exit(4)
