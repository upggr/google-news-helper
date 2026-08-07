#!/usr/bin/env bash
#
# Syntax-check every PHP file in the plugin.
#
# Falls back to linting over SSH on the WordPress host when no local php exists,
# so the check still runs on machines that never had PHP installed.
#
# Usage: ./bin/lint.sh
#        GNH_LINT_HOST=root@1.2.3.4 ./bin/lint.sh   # force remote lint

set -euo pipefail

cd "$(dirname "$0")/.."

files=()
while IFS= read -r f; do
    files+=("$f")
done < <(find . -name '*.php' -not -path './.git/*' | sort)

if [ ${#files[@]} -eq 0 ]; then
    echo "No PHP files found."
    exit 0
fi

lint_local() {
    local failed=0
    for f in "${files[@]}"; do
        if ! out=$(php -l "$f" 2>&1); then
            echo "FAIL $f"
            echo "$out"
            failed=1
        fi
    done
    return $failed
}

lint_remote() {
    local host="$1"
    local tmp="/tmp/gnh-lint-$$"
    # shellcheck disable=SC2064
    trap "ssh -o BatchMode=yes '$host' 'rm -rf $tmp' >/dev/null 2>&1 || true" EXIT

    ssh -o BatchMode=yes -o ConnectTimeout=10 "$host" "mkdir -p $tmp"
    tar czf - "${files[@]}" | ssh -o BatchMode=yes "$host" "tar xzf - -C $tmp"
    ssh -o BatchMode=yes "$host" "cd $tmp && fail=0; for f in \$(find . -name '*.php' | sort); do out=\$(php -l \"\$f\" 2>&1) || { echo \"FAIL \$f\"; echo \"\$out\"; fail=1; }; done; exit \$fail"
}

if command -v php >/dev/null 2>&1; then
    echo "Linting ${#files[@]} files with $(php -r 'echo PHP_VERSION;')"
    if lint_local; then
        echo "OK — no syntax errors."
    else
        echo "Lint failed."
        exit 1
    fi
elif [ -n "${GNH_LINT_HOST:-}" ]; then
    echo "No local php; linting ${#files[@]} files on ${GNH_LINT_HOST}"
    if lint_remote "$GNH_LINT_HOST"; then
        echo "OK — no syntax errors."
    else
        echo "Lint failed."
        exit 1
    fi
else
    echo "No local php found and GNH_LINT_HOST is not set." >&2
    echo "Install php (brew install php) or set GNH_LINT_HOST=user@host." >&2
    exit 127
fi
