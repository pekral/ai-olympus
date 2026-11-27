"""Copy a bounded run artifact through descriptors rooted at the physical checkout."""
import os
import re
import stat
import sys

root, slug, kind, source = sys.argv[1:]
if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,99}", slug):
    sys.exit(1)

limit = 2 * 1024 * 1024 if kind == "png" else 65536
if not os.path.isabs(source):
    source = os.path.join(os.getcwd(), source)
name = os.path.basename(source)
if kind == "png":
    if not re.fullmatch(r"[a-z0-9-]{1,100}\.png", name):
        sys.exit(1)
elif kind not in ("screenshots", "screenshot-evidence") or name != slug + "." + kind + ".json":
    sys.exit(1)

for runtime in (".claude", ".codex"):
    dirs = [runtime, "run"] + ([slug + ".artifacts"] if kind == "png" else [])
    if source != os.path.join(root, *dirs, name):
        continue
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    parent = os.open(root, flags)
    try:
        for component in dirs:
            child = os.open(component, flags, dir_fd=parent)
            os.close(parent)
            parent = child
        descriptor = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        with os.fdopen(descriptor, "rb") as artifact:
            info = os.fstat(artifact.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= limit:
                sys.exit(1)
            data = artifact.read(limit + 1)
            if len(data) != info.st_size or os.fstat(artifact.fileno()).st_ctime_ns != info.st_ctime_ns:
                sys.exit(1)
            sys.stdout.buffer.write(data)
        sys.exit(0)
    except OSError:
        sys.exit(1)
    finally:
        os.close(parent)
sys.exit(1)
