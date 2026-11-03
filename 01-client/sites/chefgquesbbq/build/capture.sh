#!/usr/bin/env bash
# Capture step: public files only. Saves the site's pages and REST data to data/, the photos to media/ (longest side
# 1800px) and the files whose old addresses stay to media/legacy/.
#
#   bash build/capture.sh      (from the bundle folder)
set -euo pipefail
SITE=https://chefgquesbbq.com
mkdir -p data media/legacy
curl -sS -o data/home.html "$SITE/"
curl -sS -o data/menu.html "$SITE/menu/"
curl -sS -o data/pages.json "$SITE/wp-json/wp/v2/pages?per_page=100"
curl -sS -o data/media.json "$SITE/wp-json/wp/v2/media?per_page=100"
while read -r name path; do
  [ -s "media/$name" ] && continue
  curl -sS -o "media/$name" "$SITE/wp-content/uploads/$path"
  case "$name" in *.jpg) sips -Z 1800 -s format jpeg -s formatOptions 82 "media/$name" >/dev/null ;; esac
done < build/media.txt
while read -r path; do
  mkdir -p "media/legacy/$(dirname "$path")"
  [ -s "media/legacy/$path" ] || curl -sS -o "media/legacy/$path" "$SITE/wp-content/uploads/$path"
done <<'L'
2026/09/COUNTER-MENU-NEW-page-1-SIDE-9-24-26.jpg
2026/09/COUNTER-MENU-NEW-page-2-SIDE-9-24-26.jpg
2025/01/BBQ-smokehouse-menu-2024.pdf
2024/07/Online-odering-Qr-Code.png
L
