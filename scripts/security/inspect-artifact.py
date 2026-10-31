#!/usr/bin/env python3
"""Fail closed on unexpected deployment roots, secret files, and escaping links."""

import pathlib
import sys


def inspect(root: pathlib.Path) -> list[str]:
    root = root.resolve(strict=True)
    allowed = {"composer.json", "composer.lock", "vendor", "config", "recipes", "patches", "hooks", "docroot"}
    failures = []
    for entry in root.iterdir():
        if entry.name not in allowed:
            failures.append(str(entry.relative_to(root)))
    for entry in root.rglob("*"):
        relative = entry.relative_to(root)
        name = entry.name.lower()
        if (name in {"salt.txt", "id_rsa", "id_ed25519", "settings.local.php", "local.settings.php"}
                or name.startswith((".env", "ssh-keys-github"))
                or ".git" in relative.parts or "node_modules" in relative.parts
                or name.endswith((".key", ".pem"))):
            failures.append(str(relative))
        if entry.is_symlink():
            try:
                entry.resolve(strict=True).relative_to(root)
            except (OSError, RuntimeError, ValueError):
                failures.append(str(relative))
        elif not entry.is_dir() and not entry.is_file():
            failures.append(str(relative))
    return sorted(set(failures))


if __name__ == "__main__":
    failures = inspect(pathlib.Path(sys.argv[1]))
    if failures:
        # Print paths only, never file contents.
        sys.exit("Rejected artifact paths:\n" + "\n".join(failures))
    print("Artifact path and symlink inspection passed.")
