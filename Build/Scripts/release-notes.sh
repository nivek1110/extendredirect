#!/usr/bin/env bash
#
# Prints the CHANGELOG.md section of one version - without its "## [x.y.z]" heading - which
# the release workflow publishes as the text of the GitHub release.
#
#   bash Build/Scripts/release-notes.sh 1.1.0
#   bash Build/Scripts/release-notes.sh 1.1.0 path/to/CHANGELOG.md
#
# Exits non-zero (and prints nothing on stdout) if the changelog has no section for the version.

set -euo pipefail

version="${1:?usage: release-notes.sh <version> [changelog-file]}"
file="${2:-$(dirname "$0")/../../CHANGELOG.md}"

# From the heading of this exact version up to (not including) the next "## [" heading.
notes="$(awk -v v="${version}" '
    /^## \[/ { if (found) exit; if (index($0, "## [" v "]") == 1) { found = 1; next } }
    found { print }
' "${file}")"

# Trim leading blank lines ($(...) already dropped the trailing ones).
notes="${notes#"${notes%%[![:space:]]*}"}"

if [ -z "${notes}" ]; then
    echo "No CHANGELOG section found for version ${version} in ${file}" >&2
    exit 1
fi

printf '%s\n' "${notes}"
