# Vendored QR library

The digital member card (`/member?view=card`) renders its QR code client-side
from a small **MIT-licensed** library that is *not* committed here (it's a
third-party file). The card page enqueues it only when present, and shows the
member number with a graceful fallback until it's added.

Add it once with:

```bash
curl -fsSL https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js \
  -o plugins/membership/assets/js/qrcode-generator.js
```

That file (`qrcode-generator` by Kazuhiko Arase, MIT) exposes a global
`qrcode(typeNumber, errorCorrectionLevel)` with `.addData()`, `.make()`,
`.createImgTag(cellSize, margin)` and `.createDataURL(cellSize, margin)` — the
exact API `views/card.php` calls. No code changes are needed after dropping it
in; the QR appears automatically.

Pin the version (don't track `@latest`) so the asset is reproducible.
