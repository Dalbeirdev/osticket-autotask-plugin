#!/bin/bash
# ---------------------------------------------------------------------------
# Deploy the Autotask integration to a live osTicket (Hostinger-friendly).
#
#   bash deploy-live.sh                 # deploy using the default target
#   DEST=/path/to/osticket bash deploy-live.sh
#
# Copies ONLY the integration's own files — the plugin, the scp/ endpoints and
# the four core time-tracking files. Everything else in the live installation
# (and always include/ost-config.php) is left untouched.
# ---------------------------------------------------------------------------
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Target: pass DEST=... explicitly, otherwise try the usual Hostinger layouts.
if [ -z "${DEST:-}" ]; then
    for c in "$HOME/public_html/autotask" \
             "$HOME/domains/autotask.foxeraclub.com/public_html" \
             "$HOME/domains/foxeraclub.com/public_html/autotask"; do
        if [ -f "$c/include/class.osticket.php" ]; then DEST="$c"; break; fi
    done
fi
DEST="${DEST:-$HOME/public_html/autotask}"
STAMP="$(date +%F-%H%M)"
BACKUP="$HOME/deploy-backups/$STAMP"

echo "==> source : $SRC"
echo "==> target : $DEST"

# --- sanity: is the target really an osTicket root? ------------------------
for f in include/class.osticket.php scp/index.php include/ost-config.php; do
    if [ ! -f "$DEST/$f" ]; then
        echo "!! $DEST does not look like an osTicket install (missing $f)"; exit 1
    fi
done

# --- back up every file we are about to touch ------------------------------
mkdir -p "$BACKUP"
for f in include/class.thread.php include/class.ticket.php \
         include/staff/ticket-view.inc.php include/staff/footer.inc.php \
         scp/autotask-panel.php scp/autotask.php scp/autotask-docs.php \
         scp/autotask-timefields.php; do
    [ -f "$DEST/$f" ] && { mkdir -p "$BACKUP/$(dirname "$f")"; cp "$DEST/$f" "$BACKUP/$f"; }
done
for d in "$DEST"/include/plugins/*/; do
    [ -f "$d/plugin.php" ] || continue
    if grep -qs "Autotask Integration" "$d/plugin.php"; then
        cp -r "$d" "$BACKUP/plugin-previous-$(basename "$d")"
    fi
done
echo "==> backup : $BACKUP"

# --- 1. plugin -------------------------------------------------------------
# Deploy INTO the folder osTicket already has installed (often "autoatsk").
# A second copy under a different name makes PHP fatal with
# "Cannot declare class ... already in use", so never create one blindly.
PLUGDIRS=()
for d in "$DEST"/include/plugins/*/; do
    [ -f "$d/plugin.php" ] || continue
    grep -qs "Autotask Integration" "$d/plugin.php" && PLUGDIRS+=("$(basename "$d")")
done
case "${#PLUGDIRS[@]}" in
    0) PLUGNAME="autotask-plugin" ;;                    # first install
    1) PLUGNAME="${PLUGDIRS[0]}" ;;                     # update in place
    *) echo "!! Multiple Autotask plugin folders found: ${PLUGDIRS[*]}"
       echo "!! Keep only the installed one (Admin Panel -> Manage -> Plugins) and re-run."
       exit 1 ;;
esac
echo "==> plugin folder: include/plugins/$PLUGNAME"
mkdir -p "$DEST/include/plugins/$PLUGNAME"
if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete --exclude '.git' --exclude 'logs/*.log' \
        "$SRC/include/plugins/autotask-plugin/" "$DEST/include/plugins/$PLUGNAME/"
else
    cp -r "$SRC/include/plugins/autotask-plugin/." "$DEST/include/plugins/$PLUGNAME/"
fi

# --- 2. webroot endpoints (these never update themselves) ------------------
cp "$SRC/scp/autotask-panel.php"      "$DEST/scp/autotask-panel.php"
cp "$SRC/scp/autotask.php"            "$DEST/scp/autotask.php"
cp "$SRC/scp/autotask-docs.php"       "$DEST/scp/autotask-docs.php"
cp "$SRC/scp/autotask-timefields.php" "$DEST/scp/autotask-timefields.php"

# --- 3. core time-tracking mod + footer link -------------------------------
cp "$SRC/include/class.thread.php"            "$DEST/include/class.thread.php"
cp "$SRC/include/class.ticket.php"            "$DEST/include/class.ticket.php"
cp "$SRC/include/staff/ticket-view.inc.php"   "$DEST/include/staff/ticket-view.inc.php"
cp "$SRC/include/staff/footer.inc.php"        "$DEST/include/staff/footer.inc.php"

echo "==> files deployed"

# --- 4. health check -------------------------------------------------------
PHP_BIN="$(command -v php || echo /usr/bin/php)"
if [ -x "$PHP_BIN" ]; then
    echo "==> selftest"
    "$PHP_BIN" "$DEST/include/plugins/$PLUGNAME/selftest.php" || {
        echo "!! selftest FAILED — restore with:  cp -r $BACKUP/* $DEST/"; exit 1; }
else
    echo "(php CLI not found — run selftest.php manually)"
fi

echo
echo "DONE. Database migrations apply on the next page load."
echo "Now: hard-refresh a ticket (Ctrl+F5) and purge LiteSpeed cache if pages look stale."
echo "Rollback if needed:  cp -r $BACKUP/* $DEST/"
