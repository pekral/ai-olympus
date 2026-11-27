"""Prepare a Jira summary from verified images of the current published GitHub review."""
import hashlib
import http.client
import json
import re
import subprocess
import sys
import tempfile
from pathlib import Path

from jira_attachment_media import Unconfirmed, require, run_bytes, run_cli, verified_profile
from jira_review_media import HERE, SHARED, attach_media, canonical_png, confirmed_file, find_item

GITHUB = HERE.parent.parent / "code-review-github" / "scripts"
PULL = r"https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/pull/([1-9][0-9]*)"


def current_head(pr, key, head):
    value = json.loads(run_cli("gh", "pr", "view", pr, "--json", "url,headRefOid,title,isCrossRepository"))
    require(value["url"] == pr and value["headRefOid"] == head and value["isCrossRepository"] is False
            and re.search(r"(?<![A-Z0-9_-])" + re.escape(key) + r"(?![A-Z0-9_-])", value["title"]), "pull request head or Jira source changed")


def trusted_review(pr, repo, number, work):
    source = work / "pull.json"
    source.write_text(run_cli("bash", str(GITHUB / "load-issue.sh"), pr))
    report = work / "review.md"
    report.write_text(run_cli("bash", str(SHARED / "cr-header.sh"), "--report", str(source)))
    lines = run_cli("bash", str(SHARED / "extract-findings.sh"), "--round", "--repo", repo, "--pr", number, "--input", str(report))
    visible = run_cli("jq", "-er", "-L", str(SHARED),
                      'include "review-trust"; newest_trusted_report | select(.home=="review") | .body | split("\\n<!-- cr-review-meta\\n")[0]', str(source))
    return [json.loads(line) for line in lines.splitlines()], visible, report.read_text()


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


def selection(key, slug, manifest, document, work):
    root = run_cli("git", "rev-parse", "--show-toplevel").strip()
    qa_path = str(Path(manifest).with_name(slug + ".screenshot-evidence.json"))
    qa = json.loads(run_cli("python3", "-B", str(SHARED / "read-screenshot-artifact.py"), root, slug, "screenshot-evidence", qa_path))
    require(not run_cli("git", "ls-files", "--", qa_path).strip(), "capture evidence is tracked")
    entries = json.loads(run_cli("bash", str(SHARED / "screenshot-guard.sh"), "manifest", manifest, slug))["accepted"]
    pr, head = qa["target"], qa["reviewed_head"]
    target = re.fullmatch(PULL, pr)
    require(target and re.fullmatch(r"[0-9a-f]{40}", head), "invalid QA target")
    repo, number = target.groups()
    run_cli("bash", str(SHARED / "assert-current-repo.sh"), pr)
    ticket = json.loads(run_cli("bash", str(HERE / "load-issue.sh"), key))
    require(ticket["key"] == key and any(p["url"] == pr for p in ticket["pullRequests"]), "pull request is not linked to the target issue")
    current_head(pr, key, head)
    records, visible, report = trusted_review(pr, repo, number, work)
    testing = re.search(r"^## Interactive testing\n((?:(?!^## ).)*)", report, re.M | re.S)
    require(testing, "no interactive testing section in the accepted review")
    failed = set()
    for line in testing[1].splitlines():
        cells = [cell.strip() for cell in line.split("|")]
        if len(cells) >= 5 and cells[3] in ("Failed", "Not met"):
            failed.add((cells[1], cells[2]))
    pairs = []
    default = run_cli("gh", "api", "repos/" + repo, "--jq", ".default_branch").strip()
    for entry in entries:
        fid, state, kind = entry["finding_id"], entry["state"], entry["finding_type"]
        candidates = [r for r in records if r["finding_id"] == fid and r["revision"] == head and r["status"] == "new" and r["security"] is False]
        captures = [c for c in qa["captures"] if c["finding_id"] == fid and c["state"] == state]
        require(len(candidates) == len(captures) == 1, "finding or independent capture is absent or ambiguous")
        capture = captures[0]
        step = (capture["step"], capture["viewport"])
        require(capture["finding_type"] == kind and capture["do_not_file_publicly"] is False and capture["file"] == entry["file"]
                and step in failed and step[1] in ("mobile", "desktop"), "capture is not the failed interactive step")
        if state == "head":
            require(capture["verdict"] == "failed" and capture["revision"] == head, "head capture is not a reproduced failure")
        else:
            require(entry["default_branch"] == default and capture["verdict"] == "passed" and capture["revision"] != head
                    and capture["revision"] == run_cli("gh", "api", f"repos/{repo}/branches/{default}", "--jq", ".commit.sha").strip(), "baseline has changed")
        item = finding_item(document, pr, head, candidates[0], kind)
        data = canonical_png(slug, entry["file"], capture["sha256"])
        reviewed_asset(visible, pr, repo, number, head, entry, data)
        pairs.append((fid, state, step, item, data, capture["sha256"]))
    require(pairs and all(any(t[0] == fid and t[1] == "head" and t[2] == step for t in pairs) for fid, _, step, *_ in pairs),
            "no matching approved HEAD capture for the tested step")
    require(len({p[0] for p in pairs}) == len({id(p[3]) for p in pairs}), "two findings point to the same summary item")
    return pr, head, pairs


if __name__ == "__main__":
    attempted = False
    try:
        require(len(sys.argv) == 4, "expected a target, run slug, capture manifest and ADF on stdin")
        key, slug, source = sys.argv[1:]
        document = json.load(sys.stdin)
        require(document["type"] == "doc" and document["version"] == 1, "expected an ADF v1 summary")
        with tempfile.TemporaryDirectory() as work:
            pr, head, pairs = selection(key, slug, source, document, Path(work))
        site, actor = verified_profile()
        prepared = []
        for fid, state, _, item, data, digest in sorted(pairs, key=lambda p: p[1] != "head"):
            current_head(pr, key, head)
            attempted = True
            name = f"review-{fid}-{state}-{digest}.png"
            media = confirmed_file(key, slug, name, data, digest, (site, actor))
            prepared.append((state, item, media))
        current_head(pr, key, head)
        for state, item, media in sorted(prepared, key=lambda p: p[0] == "head"):
            czech = any(n.get("attrs", {}).get("title") == "⚠️ Nálezy z kontroly kódu" for n in document["content"])
            label = ("Výchozí větev" if state == "base" else "Tato změna") if czech else ("Default branch" if state == "base" else "This change")
            attach_media(item, media, label)
        json.dump({"adf": document, "key": key, "site": site, "account": actor}, sys.stdout)
    except (Unconfirmed, KeyError, TypeError, ValueError, OSError, subprocess.SubprocessError, http.client.HTTPException) as error:
        reason = str(error) if isinstance(error, Unconfirmed) else "could not confirm source or attachment"
        print("Jira media: " + reason + "; inspect the ticket and run audit", file=sys.stderr)
        sys.exit(3 if attempted else 4)
