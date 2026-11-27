"""Prepare a single private ACLI profile for one bounded media publication."""
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

from jira_private_file import private_bytes


def selected_profile(source, site, account):
    # ACLI 1.3 writes a simple YAML list of profiles. Reject unfamiliar layouts rather than broadening the snapshot.
    current = re.findall(r'^current_profile: *["\']?([a-zA-Z0-9:_-]+)["\']? *$', source, re.M)
    sections = re.split(r"(?m)^profiles:\s*\n", source)
    if len(current) != 1 or len(sections) != 2 or not current[0].endswith(":" + account):
        raise ValueError
    profile_text = re.split(r"(?m)^\w", sections[1], maxsplit=1)[0]
    entries = re.split(r"(?m)^(?= *- +site:)", profile_text)
    matches = []
    for entry in entries:
        if not entry.strip():
            continue
        values = {}
        for field in ("site", "cloud_id", "account_id"):
            found = re.findall(r"(?m)^\s*(?:-\s+)?" + field + r":\s*[\"']?([a-zA-Z0-9._:-]+)[\"']?\s*$", entry)
            if len(found) != 1:
                raise ValueError
            values[field] = found[0]
        if values["site"] == site and values["account_id"] == account and values["cloud_id"] + ":" + account == current[0]:
            matches.append(entry)
    if len(matches) != 1:
        raise ValueError
    return "current_profile: " + current[0] + "\nprofiles:\n" + matches[0].rstrip() + "\n"


def cli(args, directory):
    env = {k: v for k, v in os.environ.items() if k not in ("JIRA_API_TOKEN", "JIRA_API_EMAIL")}
    env["ACLI_CONFIG_DIR"] = str(directory)
    return subprocess.run(args, env=env, capture_output=True, timeout=45)


def pin(binding, target):
    parent = Path(os.environ.get("ACLI_CONFIG_DIR") or (Path.home() / ".config/acli"))
    content = selected_profile(private_bytes(str(parent / "jira_config.yaml"), 1048576).decode(), binding["site"], binding["account"])
    target.mkdir(mode=0o700)
    with tempfile.TemporaryDirectory(dir=target) as invalid:
        Path(invalid, "jira_config.yaml").write_text("current_profile: [\n")
        if cli(["acli", "jira", "auth", "status"], invalid).returncode == 0:
            raise ValueError
    cfg = target / "jira_config.yaml"
    cfg.write_text(content)
    cfg.chmod(0o400)
    status = cli(["acli", "jira", "auth", "status"], target)
    site = re.findall(r"^\s*Site:\s*([a-z0-9-]+\.atlassian\.net)\s*$", status.stdout.decode(), re.M)
    actor = cli(["bash", "-c", 'source "$1"; jira_actor_account_id', "verify-jira-profile", str(Path(__file__).with_name("jira-actor.sh"))], target)
    if status.returncode or actor.returncode or site != [binding["site"]] or actor.stdout.decode().strip() != binding["account"]:
        raise ValueError

    key = binding["key"]
    if not re.fullmatch(r"[A-Z][A-Z0-9_]+-[1-9][0-9]*", key):
        raise ValueError
    lookup = cli(["acli", "jira", "workitem", "search", "--jql", "key = " + key, "--fields", "key", "--json", "--limit", "2"], target)
    lookup.check_returncode()
    page = json.loads(lookup.stdout)
    rows = page if isinstance(page, list) else page["issues"]
    if len(rows) != 1 or rows[0]["key"] != key or not re.fullmatch(r"[1-9][0-9]*", str(rows[0]["id"])):
        raise ValueError
    return str(rows[0]["id"])


if __name__ == "__main__":
    try:
        data = json.loads(Path(sys.argv[1]).read_bytes())
        print(pin(data, Path(sys.argv[2])))
    except (KeyError, IndexError, OSError, TypeError, ValueError, subprocess.SubprocessError):
        sys.exit("Jira profile isolation could not be verified; no media or comment write")
