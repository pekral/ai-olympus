"""Read one private Jira attachment credential for the existing credential provider."""
import sys

from jira_private_file import private_bytes


def read_private(path):
    line = private_bytes(path, 8192).removesuffix(b"\n").removesuffix(b"\r")
    if not line or any(byte <= 32 or byte == 127 for byte in line):
        raise ValueError
    return line


if __name__ == "__main__":
    try:
        sys.stdout.buffer.write(read_private(sys.argv[1]))
    except (ValueError, OSError, IndexError):
        sys.exit("Jira attachment credential must be an owner-only regular file reached without symbolic links")
