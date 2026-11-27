"""Read one private Jira attachment credential through a checked descriptor chain."""
import os
import stat
import sys


def read_private(path):
    path = path if os.path.isabs(path) else os.path.join(os.getcwd(), path)
    components = path.split("/")[1:]
    if any(part in ("", ".", "..") for part in components):
        raise ValueError
    flags = os.O_DIRECTORY | os.O_NOFOLLOW | os.O_RDONLY
    directory = os.open("/", flags)
    try:
        for part in components[:-1]:
            child = os.open(part, flags, dir_fd=directory)
            os.close(directory)
            directory = child
            info = os.fstat(directory)
            if info.st_uid not in (0, os.getuid()) or (info.st_mode & 0o022 and not (info.st_uid == 0 and info.st_mode & stat.S_ISVTX)):
                raise ValueError
        fd = os.open(components[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
        with os.fdopen(fd, "rb") as stream:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != os.getuid() or info.st_mode & 0o077 or not 0 < info.st_size <= 8192:
                raise ValueError
            value = stream.read(8193)
            if len(value) != info.st_size or os.fstat(fd).st_ctime_ns != info.st_ctime_ns:
                raise ValueError
            line = value.removesuffix(b"\n").removesuffix(b"\r")
            if not line or any(byte <= 32 or byte == 127 for byte in line):
                raise ValueError
            return line
    finally:
        os.close(directory)


if __name__ == "__main__":
    try:
        sys.stdout.buffer.write(read_private(sys.argv[1]))
    except (ValueError, OSError, IndexError):
        sys.exit("Jira attachment credential must be an owner-only regular file reached without symbolic links")
