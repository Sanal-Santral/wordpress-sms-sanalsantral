#!/usr/bin/env bash
# Builds the two plugins for distribution.
#
#   bin/build.sh github [out-dir]   zips + release.json for GitHub Releases
#                                   (self-updating through Update URI)
#   bin/build.sh wporg  [out-dir]   unzipped plugin folders for the
#                                   WordPress.org SVN (no updater)
#
# Both plugins are released in lockstep: their Version headers, SS_SMS_*_VERSION
# constants and readme.txt "Stable tag" must agree. When RELEASE_TAG is set
# (CI sets it from the git tag, e.g. v1.2.0) it must match as well.
set -euo pipefail

FLAVOR="${1:-github}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${2:-$ROOT/dist/$FLAVOR}"
REPO="Sanal-Santral/wordpress-sms-sanalsantral"
PLUGINS=(sanalsantral-sms sanalsantral-sms-otp)

case "$FLAVOR" in github|wporg) ;; *) echo "usage: $0 github|wporg [out-dir]" >&2; exit 2 ;; esac

header() { sed -n "s/^ \* $2:[[:space:]]*//p" "$1" | head -n1 | tr -d '\r'; }

VERSION=""
for slug in "${PLUGINS[@]}"; do
	main="$ROOT/$slug/$slug.php"
	v="$(header "$main" Version)"
	stable="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$ROOT/$slug/readme.txt" | tr -d '\r')"
	const="$(grep -oE "define\( 'SS_SMS(_OTP)?_VERSION', '[^']+'" "$main" | grep -oE "'[0-9][^']*'" | tr -d "'")"
	if [[ "$v" != "$stable" || "$v" != "$const" ]]; then
		echo "error: $slug version mismatch: header=$v stable_tag=$stable constant=$const" >&2
		exit 1
	fi
	if [[ -n "$VERSION" && "$v" != "$VERSION" ]]; then
		echo "error: plugins are released together; $slug is $v, expected $VERSION" >&2
		exit 1
	fi
	VERSION="$v"
done
if [[ -n "${RELEASE_TAG:-}" && "${RELEASE_TAG#v}" != "$VERSION" ]]; then
	echo "error: tag $RELEASE_TAG does not match plugin version $VERSION" >&2
	exit 1
fi

rm -rf "$OUT"
mkdir -p "$OUT"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

for slug in "${PLUGINS[@]}"; do
	cp -R "$ROOT/$slug" "$STAGE/$slug"
	find "$STAGE/$slug" -name '.*' -prune -exec rm -rf {} +
	if [[ "$FLAVOR" == wporg ]]; then
		# WordPress.org updates hosted plugins itself and does not allow
		# bundled updaters.
		rm -f "$STAGE/$slug/includes/class-ss-sms-updater.php"
		sed -i.bak '/^ \* Update URI:/d' "$STAGE/$slug/$slug.php" && rm -f "$STAGE/$slug/$slug.php.bak"
		cp -R "$STAGE/$slug" "$OUT/$slug"
	else
		python3 - "$STAGE" "$slug" "$OUT/$slug.zip" <<'PY'
import os, sys, zipfile
stage, slug, dest = sys.argv[1:]
with zipfile.ZipFile(dest, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(os.path.join(stage, slug)):
        dirs.sort()
        for f in sorted(files):
            path = os.path.join(root, f)
            z.write(path, os.path.relpath(path, stage))
PY
	fi
done

if [[ "$FLAVOR" == github ]]; then
	TAG="${RELEASE_TAG:-v$VERSION}"
	python3 - "$ROOT" "$OUT" "$REPO" "$TAG" "$VERSION" <<'PY'
import json, re, sys, datetime
root, out, repo, tag, version = sys.argv[1:]

def readme(slug):
    text = open(f"{root}/{slug}/readme.txt", encoding="utf-8").read()
    head = dict(re.findall(r"^([A-Za-z ]+):[ \t]*(.+)$", text.split("\n\n", 1)[0], re.M))
    name = re.search(r"^=== (.+) ===", text, re.M).group(1)
    short = text.split("\n\n")[1].strip()
    log = re.search(r"== Changelog ==\n(.*?)(?:\n== |\Z)", text, re.S)
    return name, head, short, (log.group(1).strip() if log else "")

plugins = {}
for slug in ("sanalsantral-sms", "sanalsantral-sms-otp"):
    name, head, short, changelog = readme(slug)
    plugins[slug] = {
        "name": name,
        "version": version,
        "requires": head.get("Requires at least", ""),
        "tested": head.get("Tested up to", ""),
        "requires_php": head.get("Requires PHP", ""),
        "homepage": f"https://github.com/{repo}",
        "package": f"https://github.com/{repo}/releases/download/{tag}/{slug}.zip",
        "description": short,
        "changelog": changelog,
        "released": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
    }
json.dump({"tag": tag, "plugins": plugins}, open(f"{out}/release.json", "w", encoding="utf-8"), ensure_ascii=False, indent=2)
PY
fi

echo "built $FLAVOR $VERSION into $OUT:"
ls -1 "$OUT"
