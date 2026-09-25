#!/usr/bin/env bash
#
# Slate — guarded deploy over SSH.
#
# WHY THIS EXISTS
#
# rsync --delete makes the destination match the source, so it deletes
# anything on the server that is not in the ref being shipped. Aimed one
# directory too high, at a docroot shared with other sites, it does not fail —
# it succeeds, and removes them. On this account ~/public_html is a live
# WordPress install of ~19,500 files sitting directly above the app.
#
# This is not hypothetical here. Deploy #7 on 29 Aug removed
# plugins/multilang-translate/ from the server because the plugin was absent
# from the ref being shipped and --delete did what it is for.
#
# A blocklist ("not public_html") is the wrong shape: it protects the one path
# someone already thought of. These guards instead assert POSITIVELY that the
# destination is this app, and cap the blast radius if every other check is
# somehow wrong.
#
#   1. sentinel     the target must carry .slate-deploy-target naming this app
#   2. foreign app  refuse if a WordPress/Joomla/Drupal install is in there
#   3. path sanity  refuse $HOME, a bare docroot, / and other short paths
#   4. delete count a dry run counts pending deletions and refuses over a cap,
#                   before anything is transferred or removed
#   5. delete-after deletions happen only after the transfer has succeeded
#
# Usage:
#   bin/deploy.sh --dry-run                  # always start here
#   bin/deploy.sh
#   bin/deploy.sh --max-delete 800           # after reading the dry-run list
#
# First-time setup for a new target, run once, deliberately:
#   ssh <host> 'echo solaya > <path>/.slate-deploy-target'

set -euo pipefail

SSH_HOST="${DEPLOY_SSH_HOST:-rakib-server}"
DEST="${DEPLOY_PATH:-/home/rakilluy/public_html/solaya}"
APP="${DEPLOY_APP:-solaya}"
MAX_DELETE="${DEPLOY_MAX_DELETE:-200}"
DRY=0

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run)    DRY=1; shift ;;
        --max-delete) MAX_DELETE="$2"; shift 2 ;;
        --host)       SSH_HOST="$2"; shift 2 ;;
        --path)       DEST="$2"; shift 2 ;;
        *) echo "unknown argument: $1" >&2; exit 2 ;;
    esac
done

cd "$(dirname "$0")/.."

die() { printf '\nREFUSED: %s\n' "$1" >&2; exit 1; }

# ── Guard 3: path sanity ─────────────────────────────────────
# Cheap, local, and catches the empty-variable case that turns the
# destination into the user's home directory.
case "$DEST" in
    ""|"/"|"/home"|"/root")                  die "destination '$DEST' is a system path." ;;
    */public_html|*/public_html/)            die "destination '$DEST' is a bare docroot. Deploy into a subdirectory of it." ;;
    /home/*/|/home/*)
        # /home/<user> alone is 3 segments; anything shallower is not an app dir.
        segments=$(printf '%s' "${DEST%/}" | awk -F/ '{print NF-1}')
        [ "$segments" -lt 4 ] && die "destination '$DEST' is too shallow to be an app directory."
        ;;
esac
[ "${DEST%/}" = "$HOME" ] && die "destination is \$HOME."

echo "Target : $SSH_HOST:$DEST"
echo "App    : $APP"

# ── Guards 1 + 2: ask the server what is actually there ──────
# One round trip. Prints a verdict this script then acts on, so the checks
# run against the real destination rather than against what we assume.
verdict=$(ssh -o BatchMode=yes "$SSH_HOST" "
    set -u
    d='$DEST'
    [ -d \"\$d\" ] || { echo 'MISSING'; exit 0; }
    for foreign in wp-config.php wp-load.php configuration.php sites/default/settings.php; do
        [ -e \"\$d/\$foreign\" ] && { echo \"FOREIGN \$foreign\"; exit 0; }
    done
    if [ -f \"\$d/.slate-deploy-target\" ]; then
        printf 'SENTINEL '; tr -d '[:space:]' < \"\$d/.slate-deploy-target\"; echo
    elif [ -z \"\$(ls -A \"\$d\" 2>/dev/null)\" ]; then
        echo 'EMPTY'
    else
        echo 'NOSENTINEL'
    fi
")

case "$verdict" in
    MISSING)      die "'$DEST' does not exist on $SSH_HOST. Create it first." ;;
    FOREIGN*)     die "'$DEST' contains $(printf '%s' "$verdict" | cut -d' ' -f2) — that is another application, not this one." ;;
    EMPTY)        echo "Check  : target is empty (first deploy)" ;;
    NOSENTINEL)   die "'$DEST' has no .slate-deploy-target marker.
         Nothing proves this directory is $APP's, and --delete would make it
         match this checkout. If it IS the right directory, mark it once:
             ssh $SSH_HOST 'echo $APP > $DEST/.slate-deploy-target'" ;;
    "SENTINEL $APP") echo "Check  : sentinel confirms '$APP'" ;;
    SENTINEL*)    die "'$DEST' is marked for '$(printf '%s' "$verdict" | cut -d' ' -f2)', not '$APP'." ;;
    *)            die "could not read the target (got: '$verdict')." ;;
esac

# ── The transfer ─────────────────────────────────────────────
# --delete-after: deletions run only once files have transferred, so an
#   interrupted deploy leaves the site whole rather than half-deleted.
# --max-delete:   the circuit breaker. A routine deploy removes a handful of
#   files; a misdirected one removes thousands. rsync aborts at the cap.
# NOTE: --max-delete is deliberately NOT in here. These args are reused by the
# counting dry run below, and a capped dry run stops counting at the cap — it
# would report exactly $MAX_DELETE deletions no matter how many were really
# pending, so the check could never trip. The cap is added to the live
# transfer only, further down.
RSYNC_ARGS=(
    -az --delete-after --human-readable
    --exclude '.git/'          --exclude '.github/'
    --exclude '.env'           --exclude '.env.*'
    --exclude '.installed'     --exclude 'uploads/'
    --exclude 'data/'          --exclude '.claude/'
    --exclude 'scratchpad/'    --exclude 'Claude/'
    --exclude 'audit/'         --exclude 'tests/.out/'
    --exclude '*.log'          --exclude 'error_log'
    --exclude 'deploy-package/'  # carries a live .env and a DB dump
    --exclude 'archive/'         # plugins outside this build
    --exclude 'node_modules/'  --exclude '.DS_Store'
    --exclude '*.bak'          --exclude '*.bak-*'
    --exclude '.slate-deploy-target'
    --exclude '*.zip'            # local backups/exports, never app code
    --exclude 'auth.php'         # server-side devops agent, not part of this app
    --exclude '.slate_agent_config.json'  # that agent's paired cPanel credentials
    --exclude 'LICENSE-SYSTEM-MASTER-SPEC/'  # private business/licensing docs, never public
)

if [ "$DRY" -eq 1 ]; then
    echo "Mode   : DRY RUN — nothing will be transferred or deleted"
    echo
    rsync "${RSYNC_ARGS[@]}" --dry-run --itemize-changes ./ "$SSH_HOST:$DEST/"
    echo
    echo "Lines starting with '*deleting' are what would be removed."
    echo "Read them, then re-run without --dry-run."
    exit 0
fi

# ── Guard 4: count deletions BEFORE transferring anything ────
# rsync's own --max-delete stops mid-run: on a 300-file overshoot with a cap
# of 200 it deletes 200 and then gives up, which is a half-wrecked site, not a
# refusal. So the count happens here, in a dry run, where refusing costs
# nothing and touches nothing. --max-delete stays on the real transfer below
# as a backstop for anything that changes between the two passes.
echo "Check  : counting deletions..."
planned=$(rsync "${RSYNC_ARGS[@]}" --dry-run --itemize-changes ./ "$SSH_HOST:$DEST/" \
          | grep -c '^\*deleting' || true)
echo "Check  : $planned file(s) would be deleted (cap $MAX_DELETE)"

if [ "$planned" -gt "$MAX_DELETE" ]; then
    die "this deploy would delete $planned files, over the cap of $MAX_DELETE.
         NOTHING has been transferred or deleted — the count came from a dry run.
         A routine deploy removes a handful of files. A number this large
         usually means the destination is not what you think it is.
         Inspect the list first:
             bin/deploy.sh --dry-run --path $DEST
         and only if every deletion is expected:
             bin/deploy.sh --max-delete $((planned + 10)) --path $DEST"
fi

echo "Mode   : LIVE"
echo
set +e
rsync "${RSYNC_ARGS[@]}" --max-delete="$MAX_DELETE" --stats ./ "$SSH_HOST:$DEST/"
status=$?
set -e
if [ $status -ne 0 ]; then
    [ $status -eq 25 ] && die "rsync hit its --max-delete backstop. The site may be
         partially updated. Re-run --dry-run and inspect before doing anything else."
    die "rsync failed with status $status."
fi

echo
echo "Deployed to $SSH_HOST:$DEST"
