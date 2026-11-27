"""Prepare a single private ACLI profile for one bounded media publication."""
import json
import os
import re
import subprocess
import sys
import tempfile
from contextlib import contextmanager
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


class CheckFailed(Exception):
    """The argument is always a fixed diagnostic stage, never a tool error or private value."""


@contextmanager
def stage(code):
    try:
        yield
    except (KeyError, IndexError, OSError, TypeError, ValueError, subprocess.SubprocessError):
        raise CheckFailed(code) from None


def cli(args, directory=None):
    env = {k: v for k, v in os.environ.items() if k not in ("JIRA_API_TOKEN", "JIRA_API_EMAIL")}
    if directory is not None:
        env["ACLI_CONFIG_DIR"] = str(directory)
    return subprocess.run(args, env=env, capture_output=True, timeout=45)


def verify_site(site, directory=None):
    with stage("isolated-status"):
        status = cli(["acli", "jira", "auth", "status"], directory)
        status.check_returncode()
        reported = re.findall(r"^\s*Site:\s*([a-z0-9-]+\.atlassian\.net)\s*$", status.stdout.decode(), re.M)
        if reported != [site]:
            raise ValueError


def account_id(directory=None):
    with stage("actor-mismatch"):
        result = cli(["bash", "-c", 'source "$1"; jira_actor_account_id', "verify-jira-profile", str(Path(__file__).with_name("jira-actor.sh"))], directory)
        result.check_returncode()
        account = result.stdout.decode().strip()
        if not account or any(c.isspace() for c in account):
            raise ValueError
        return account


def pin(binding, target):
    parent = Path(os.environ.get("ACLI_CONFIG_DIR") or (Path.home() / ".config/acli"))
    with stage("source-unavailable"):
        raw = private_bytes(str(parent / "jira_config.yaml"), 1048576)
    with stage("source-layout"):
        content = selected_profile(raw.decode(), binding["site"], binding["account"])
    with stage("isolation-unsupported"):
        target.mkdir(mode=0o700)
        with tempfile.TemporaryDirectory(dir=target) as invalid:
            Path(invalid, "jira_config.yaml").write_text("current_profile: [\n")
            if cli(["acli", "jira", "auth", "status"], invalid).returncode == 0:
                raise ValueError
        cfg = target / "jira_config.yaml"
        cfg.write_text(content)
        cfg.chmod(0o400)
    verify_site(binding["site"], target)
    if account_id(target) != binding["account"]:
        raise CheckFailed("actor-mismatch")
    with stage("issue-lookup"):
        key = binding["key"]
        if not re.fullmatch(r"[A-Z][A-Z0-9_]+-[1-9][0-9]*", key):
            raise ValueError
        lookup = cli(["acli", "jira", "workitem", "search", "--jql", "key = " + key, "--fields", "key", "--json", "--limit", "2"], target)
        lookup.check_returncode()
        # ACLI returns a flat array. A page envelope cannot establish that this is the only row.
        rows = json.loads(lookup.stdout)
        if not isinstance(rows, list) or len(rows) != 1 or rows[0]["key"] != key or not re.fullmatch(r"[1-9][0-9]*", str(rows[0]["id"])):
            raise ValueError
        return str(rows[0]["id"])


def check_profile(url):
    target = re.fullmatch(r"https://([a-z0-9-]+\.atlassian\.net)/browse/([A-Z][A-Z0-9_]+-[1-9][0-9]*)", url)
    if not target:
        raise CheckFailed("issue-lookup")
    site, key = target.groups()
    verify_site(site)
    binding = {"site": site, "key": key, "account": account_id()}
    with stage("isolation-unsupported"):
        with tempfile.TemporaryDirectory() as work:
            pin(binding, Path(work) / "profile")


if __name__ == "__main__":
    diagnose = sys.argv[1:2] == ["--check-profile"]
    try:
        if diagnose:
            if len(sys.argv) != 3:
                raise CheckFailed("issue-lookup")
            check_profile(sys.argv[2])
            print("configured")
        else:
            with stage("source-unavailable"):
                data = json.loads(Path(sys.argv[1]).read_bytes())
                print(pin(data, Path(sys.argv[2])))
    except Exception as stopped:
        # Only explicit stage identifiers may leave the diagnostic process.
        if diagnose:
            print(stopped.args[0] if isinstance(stopped, CheckFailed) else "source-unavailable")
            sys.exit(3)
        sys.exit("Jira profile isolation could not be verified; no media or comment write")
