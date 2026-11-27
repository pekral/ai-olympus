"""Resolve the ADF media identity of an issue-owned PNG through Jira's public attachment routes."""
import base64
import hashlib
import http.client
import json
import os
import re
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlsplit


class Unconfirmed(Exception):
    """A public response did not establish the expected attachment identity."""


class DestinationChanged(Unconfirmed):
    """The authenticated tenant or account differs from the original publication target."""


def require(condition, message, refusal=Unconfirmed):
    if not condition:
        raise refusal(message)


def media_uuid(location):
    require(location and location.isascii() and not any(ord(c) <= 32 or ord(c) == 127 for c in location), "invalid media redirect")
    target = urlsplit(location)
    match = re.fullmatch(r"/file/([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})/binary", target.path)
    require(target.scheme == "https" and target.netloc == "api.media.atlassian.com" and match and not target.fragment, "unsupported media redirect")
    return match[1]


def public_get(site, path, authorization, limit):
    connection = http.client.HTTPSConnection(site, timeout=20)
    try:
        connection.request("GET", path, headers={"Authorization": authorization, "Accept-Encoding": "identity"})
        response = connection.getresponse()
        body = response.read(limit + 1)
        require(len(body) <= limit, "attachment response exceeded its limit")
        locations = response.headers.get_all("Location") or []
        return response.status, locations, body
    finally:
        connection.close()


def attachment_identity(site, authorization, item, expected):
    attachment_id = str(item["id"])
    path = "/rest/api/3/attachment/content/" + attachment_id
    code, locations, data = public_get(site, path + "?redirect=false", authorization, item["size"])
    require(code == 200 and not locations and len(data) == item["size"] and hashlib.sha256(data).hexdigest() == expected, "attachment bytes differ")
    require(data.startswith(b"\x89PNG\r\n\x1a\n") and data[12:16] == b"IHDR", "attachment is not a PNG")
    width = int.from_bytes(data[16:20], "big")
    height = int.from_bytes(data[20:24], "big")
    require(width > 0 and height > 0, "PNG dimensions are unavailable")
    code, locations, _ = public_get(site, path + "?redirect=true", authorization, 65536)
    require(code == 303 and len(locations) == 1, "Jira returned no unique media redirect")
    return {"id": media_uuid(locations[0]), "width": width, "height": height}


def authorization_for(email):
    provider = Path(__file__).resolve().with_name("jira-rest.sh")
    # The existing attachment credential provider sends its result over a private pipe.
    program = 'source "$1"; jira_rest_resolve_auth || exit 2; printf "%s\\n%s" "$JIRA_REST_EMAIL" "$JIRA_REST_TOKEN"'
    resolved = subprocess.run(["bash", "-c", program, "jira-attachment-media", str(provider)], capture_output=True, timeout=10)
    if resolved.returncode:
        raise Unconfirmed("missing: configure JIRA_API_EMAIL and JIRA_API_TOKEN or an email:token line in ~/.config/acli/jira_api_token (chmod 600)")
    token_email, token = resolved.stdout.decode().split("\n", 1)
    require(token_email.casefold() == email.casefold() and token and not any(x.isspace() for x in token), "mismatch: configure the same account for acli and the attachment helper")
    return "Basic " + base64.b64encode((token_email + ":" + token).encode()).decode()


def run_bytes(*args):
    env = {k: v for k, v in os.environ.items() if k not in ("JIRA_API_TOKEN", "JIRA_API_EMAIL")}
    return subprocess.run(args, env=env, capture_output=True, check=True, timeout=45).stdout


def run_cli(*args):
    return run_bytes(*args).decode()


def target_key(key):
    require(re.fullmatch(r"[A-Z][A-Z0-9_]+-[1-9][0-9]*", key), "invalid Jira target")


def authenticated_identity(expected_site=None, expected_actor=None):
    auth = run_cli("acli", "jira", "auth", "status")
    site = re.findall(r"^\s*Site:\s*([a-z0-9-]+\.atlassian\.net)\s*$", auth, re.M)
    email = re.findall(r"^\s*Email:\s*(\S+@\S+)\s*$", auth, re.M)
    require(len(site) == len(email) == 1, "cannot establish the authenticated Jira Cloud site and account")
    require(expected_site is None or expected_site == site[0], "the Jira site changed", DestinationChanged)
    actor_file = Path(__file__).resolve().with_name("jira-actor.sh")
    account = run_cli("bash", "-c", 'source "$1"; jira_actor_account_id', "jira-attachment-media", str(actor_file)).strip()
    require(account and not any(c.isspace() for c in account), "cannot establish the acli account ID")
    require(expected_actor is None or expected_actor == account, "the Jira account changed", DestinationChanged)
    header = authorization_for(email[0])
    code, locations, payload = public_get(site[0], "/rest/api/3/myself", header, 65536)
    require(code == 200 and not locations and account and json.loads(payload)["accountId"] == account, "mismatch: the attachment credential did not confirm the acli account")
    return site[0], account, header


def verified_profile(expected_site=None, expected_actor=None):
    site, actor, _ = authenticated_identity(expected_site, expected_actor)
    return site, actor


def attachment_inventory(key, site):
    target_key(key)
    view = json.loads(run_cli("acli", "jira", "workitem", "view", key, "--fields", "attachment", "--json"))
    issue_id = str(view["id"])
    require(re.fullmatch(r"[1-9][0-9]*", issue_id) and view["key"] == key
            and view["self"] in [f"https://{site}{prefix}/rest/api/{version}/issue/{issue_id}"
                                for prefix in ("", "/jira") for version in ("2", "3")]
            and isinstance(view["fields"]["attachment"], list), "cannot confirm target attachment inventory", DestinationChanged)
    for item in view["fields"]["attachment"]:
        number = str(item["id"])
        require(re.fullmatch(r"[1-9][0-9]*", number) and item["content"] in [
            f"https://{site}{prefix}/rest/api/3/attachment/content/{number}" for prefix in ("", "/jira")
        ], "attachment inventory does not belong to the authenticated site", DestinationChanged)
    return view["fields"]["attachment"]


def resolve(key, attachment_id, expected, filename, binding=()):
    require(re.fullmatch(r"[1-9][0-9]*", attachment_id), "invalid attachment ID")
    require(re.fullmatch(r"[0-9a-f]{64}", expected) and re.fullmatch(r"[a-z0-9-]{1,140}\.png", filename), "expected the reviewed PNG digest and filename")
    site, account, header = authenticated_identity(*binding)
    items = [a for a in attachment_inventory(key, site) if str(a["id"]) == attachment_id]
    require(len(items) == 1, "attachment is absent or ambiguous on this ticket")
    item = items[0]
    require(account and item["author"]["accountId"] == account, "attachment author differs from the authenticated account")
    require(item["filename"] == filename and item["mimeType"] == "image/png" and 0 < item["size"] <= 2097152, "unexpected attachment metadata")
    return attachment_identity(site, header, item, expected)


if __name__ == "__main__":
    try:
        if sys.argv[1:] == ["--check-auth"]:
            authenticated_identity()
            print("configured")
            sys.exit(0)
        require(len(sys.argv) == 5, "use <KEY> <attachment-id> <reviewed-sha256> <expected-filename.png>")
        print(json.dumps(resolve(*sys.argv[1:])))
    except Unconfirmed as refused:
        sys.exit("jira-attachment-media: " + str(refused))
    except (OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError, http.client.HTTPException):
        sys.exit("jira-attachment-media: unable to verify the target attachment")
