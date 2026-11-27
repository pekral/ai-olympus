"""Check a Jira resource against the tenant, key and immutable ID of a pinned ACLI session."""
import json
import re
import sys
from urllib.parse import urlsplit


def issue_origin(view, site, key, expected_id=None):
    number = str(view["id"])
    if view["key"] != key or not re.fullmatch(r"[1-9][0-9]*", number) or (expected_id is not None and number != expected_id):
        raise ValueError
    url = urlsplit(view["self"])
    # A service origin identifies a resource path, never a tenant. Accept it only after the
    # isolated session independently resolved this exact key/ID pair, and never request it.
    backend = expected_id and re.fullmatch(r"jira-prod-[a-z0-9-]+\.prod\.atl-paas\.net", url.netloc)
    if (url.scheme != "https" or url.query or url.fragment or not (url.netloc == site or backend)
            or url.path not in [f"{prefix}/rest/api/{version}/issue/{number}" for prefix in ("", "/jira") for version in ("2", "3")]):
        raise ValueError
    return url.netloc


if __name__ == "__main__":
    try:
        raw = sys.stdin.buffer.read()
        issue_origin(json.loads(raw), *sys.argv[1:])
        sys.stdout.buffer.write(raw)
    except (KeyError, TypeError, ValueError):
        sys.exit(1)
