#!/usr/bin/env bash
#
# Build the two deployment packages (client + central) from a git ref.
#
#   scripts/build-release.sh [--ref main] [--out .] [--version 1.6]
#
# Output:
#   KOHEVO-CLIENT-V<version>-DEPLOYMENT-READY.zip    (from 01-client/)
#   KOHEVO-CENTRAL-V<version>-DEPLOYMENT-READY.zip   (from 02-licensing/)
#
# Reproducible: the zip is built from `git archive` of the ref, never from the
# working tree, so uncommitted or ignored local files can not leak in.
#
# PHPMailer is not tracked in git. It is added to the package from, in order:
#   1. $PHPMAILER_SRC   a directory holding PHPMailer.php / SMTP.php / Exception.php ...
#   2. composer         `composer require phpmailer/phpmailer` into a temp dir
#
# The packages ship NO .env: copy .env.example to .env on the server.
# See docs/06-operations/RELEASING.md.

set -euo pipefail

REF="main"
OUT="."
VERSION=""

while [ $# -gt 0 ]; do
  case "$1" in
    --ref)     REF="$2"; shift 2 ;;
    --out)     OUT="$2"; shift 2 ;;
    --version) VERSION="$2"; shift 2 ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done

REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO"

git rev-parse --verify --quiet "$REF^{commit}" >/dev/null || { echo "Unknown git ref: $REF" >&2; exit 1; }
SHA="$(git rev-parse "$REF")"
SHORT="$(git rev-parse --short "$REF")"

# Default the package version from the tree's VERSION file: x.y.0 -> "x.y" (1.6.0 -> 1.6), a patch release keeps its
# full number (1.6.1 -> 1.6.1), so a patch package never reuses the name of the release before it.
if [ -z "$VERSION" ]; then
  FULL="$(git show "$REF:VERSION" | tr -d '[:space:]')"
  case "$FULL" in *.0) VERSION="${FULL%.*}" ;; *) VERSION="$FULL" ;; esac
fi

# The version in code must agree with the VERSION file at that ref, or the package would lie about itself.
FULL="$(git show "$REF:VERSION" | tr -d '[:space:]')"
for f in 01-client/config.php 01-client/install.php 02-licensing/config.php 02-licensing/install.php; do
  # Capture first, then grep: `git show | grep -q` under pipefail fails at random (grep -q exits on the first match,
  # git gets SIGPIPE, and the pipeline reports failure even though the file is right).
  content="$(git show "$REF:$f")"
  grep -q "'SLATE_VERSION', '$FULL'" <<<"$content" \
    || { echo "SLATE_VERSION in $f does not match VERSION ($FULL) at $REF" >&2; exit 1; }
done

OUT="$(mkdir -p "$OUT" && cd "$OUT" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# ── PHPMailer ────────────────────────────────────────────────────────────────
PM="$WORK/phpmailer-src"
mkdir -p "$PM"
if [ -n "${PHPMAILER_SRC:-}" ]; then
  cp "$PHPMAILER_SRC"/*.php "$PM/"
  PM_LABEL="PHPMailer (from PHPMAILER_SRC)"
else
  command -v composer >/dev/null || { echo "Set PHPMAILER_SRC=<dir> or install composer." >&2; exit 1; }
  (cd "$WORK" && mkdir pmc && cd pmc && composer require phpmailer/phpmailer --no-interaction --no-progress --quiet)
  cp "$WORK"/pmc/vendor/phpmailer/phpmailer/src/*.php "$PM/"
  PM_VER="$(cd "$WORK/pmc" && composer show phpmailer/phpmailer 2>/dev/null | awk '/^versions/{print $4}')"
  PM_LABEL="PHPMailer ${PM_VER:-}"
fi
for need in PHPMailer.php SMTP.php Exception.php; do
  [ -f "$PM/$need" ] || { echo "PHPMailer source is missing $need" >&2; exit 1; }
done

# ── Build one package ────────────────────────────────────────────────────────
build() {
  local label="$1" tree="$2" zipname="$3"
  local stage="$WORK/stage-$label"
  mkdir -p "$stage"
  git archive "$REF:$tree" | tar -x -C "$stage"

  # Development-only material never ships.
  rm -rf "$stage/tests" "$stage/Claude" "$stage/.env" "$stage/data/slate.log"
  rm -f  "$stage"/SKILL_studio_plugin*.md "$stage/STUDIO_BUILD_BRIEF.md" "$stage/README-PATCH.md" "$stage/dev-server.php"
  find "$stage" -name '.gitkeep' -type f -delete

  # PHPMailer + a deny-all .htaccess for vendor/.
  mkdir -p "$stage/vendor/phpmailer/phpmailer/src"
  cp "$PM"/*.php "$stage/vendor/phpmailer/phpmailer/src/"
  printf 'Require all denied\n' > "$stage/vendor/.htaccess"
  printf '%s (LGPL-2.1) — https://github.com/PHPMailer/PHPMailer\nBundled so SMTP works without composer. Files copied unmodified.\n' "$PM_LABEL" > "$stage/vendor/phpmailer/README.txt"

  cat > "$stage/BUILD-INFO.txt" <<EOF
Kohevo $label package V$VERSION ($FULL) — built from $REF @ $SHORT ($SHA)
Built: $(date -u '+%Y-%m-%d %H:%M UTC')
Source tree: $tree
Excluded on purpose: tests/, .gitkeep placeholders, .env and data/slate.log (dev-only), Claude/, SKILL_studio_plugin*.md,
STUDIO_BUILD_BRIEF.md, README-PATCH.md, dev-server.php.
Added: vendor/phpmailer (SMTP), vendor/.htaccess (denies web access).
Deploy: copy .env.example to .env and set production values — this package ships NO .env. See INSTALL.md.
EOF

  rm -f "$OUT/$zipname"
  (cd "$stage" && zip -rqX "$OUT/$zipname" .)
  unzip -tq "$OUT/$zipname" >/dev/null
  printf '  %-52s %10s bytes\n' "$zipname" "$(wc -c < "$OUT/$zipname" | tr -d ' ')"
}

echo "Building V$VERSION ($FULL) from $REF @ $SHORT"
build client  01-client    "KOHEVO-CLIENT-V${VERSION}-DEPLOYMENT-READY.zip"
build central 02-licensing "KOHEVO-CENTRAL-V${VERSION}-DEPLOYMENT-READY.zip"
echo "Done. Zips are git-ignored; do not commit them."
