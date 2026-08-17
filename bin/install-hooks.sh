#!/usr/bin/env bash
#
# Installs the versioned git hooks from bin/hooks/ into .git/hooks/.
#
# Git does not version .git/hooks/, so this script symlinks each hook in
# bin/hooks/ into .git/hooks/<name>, making it executable. Re-run after a
# fresh clone or whenever a hook is added/renamed.
#
# Usage: php bin/install-hooks.sh   (or: bash bin/install-hooks.sh)
set -euo pipefail

root="$(git rev-parse --show-toplevel 2>/dev/null)" || {
    echo "install-hooks: not inside a git repository." >&2
    exit 1
}
src="$root/bin/hooks"
dst="$root/.git/hooks"

if [ ! -d "$src" ]; then
    echo "install-hooks: no $src directory — nothing to install." >&2
    exit 1
fi

mkdir -p "$dst"
installed=0
for hook in "$src"/*; do
    [ -f "$hook" ] || continue
    name="$(basename "$hook")"
    target="$dst/$name"
    # Remove an existing regular file or stale symlink, but leave a real
    # (non-symlink) hook alone with a warning — the user may have customized it.
    if [ -e "$target" ] && [ ! -L "$target" ]; then
        echo "install-hooks: $name already exists in .git/hooks/ and is not a symlink — leaving it untouched." >&2
        continue
    fi
    ln -sf "$hook" "$target"
    chmod +x "$hook"
    echo "install-hooks: linked .git/hooks/$name -> bin/hooks/$name"
    installed=$((installed + 1))
done

if [ "$installed" -eq 0 ]; then
    echo "install-hooks: no hooks were installed." >&2
else
    echo "install-hooks: done ($installed hook(s)). Bypass any hook with 'git <cmd> --no-verify'."
fi
