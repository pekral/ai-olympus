"""Read a bounded owner-only file without following a symbolic link."""
import os
import stat


def private_bytes(path, limit):
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
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != os.getuid() or info.st_mode & 0o077 or not 0 < info.st_size <= limit:
                raise ValueError
            value = stream.read(limit + 1)
            if len(value) != info.st_size or os.fstat(fd).st_ctime_ns != info.st_ctime_ns:
                raise ValueError
            return value
    finally:
        os.close(directory)
