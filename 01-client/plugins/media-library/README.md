# Media library (Slate plugin)

Browse, upload, and reuse images across the Shop. Adds a picker modal
to the product editor so admins can select existing uploads instead of
re-uploading.

## Requirements

- Slate core ≥ 1.0.0
- Shop plugin (for picker integration; the media browse page works
  without Shop, it just won't have anywhere meaningful to use the
  picked paths)
- The patched `shop/admin/products.php` from this archive (the picker
  buttons live there; without the patch, the library is browse-only)

## What you get

### Admin → Media

A page listing every image in `uploads/shop/products/` (and its
`gallery/` and `variants/` subfolders). Each item shows:

- thumbnail
- filename (truncated, full on hover)
- dimensions + size in KB
- "Used (N)" badge or "Unused" badge — which products/variants use it
- "Copy path" button
- Delete (only enabled for unused files)

Filter tabs: All / In use / Unused. Pagination at 24/page.

### Direct upload from the media page

A drag/drop file input. Multi-file. All files go through
`Uploads::handle()` (MIME-sniffed, random-renamed, size-capped at 5MB,
extension-whitelisted) and land in `uploads/shop/products/`.

### Picker modal in the product editor

After installing this plugin AND applying the patched `products.php`:

- "Or pick from media library…" buttons appear next to:
  - The main product image input
  - The gallery upload input (append mode)
  - Each variant's image input
  - The new-variant image input
- Clicking opens a modal showing all images. Click → highlight → "Use
  this" → modal closes, the input is populated.
- For the gallery, picked images are appended to the gallery list on
  save (saved as `gallery_existing[]` hidden fields).
- For single-image fields, the preview updates immediately.
- Double-click an image to pick + commit in one motion.

## What this plugin deliberately does NOT do

- **No folders/tags/categories.** Flat list. With ~30 products and
  ~150 images, browse+search is fine. Revisit at ~500 images.
- **No alt-text per image.** Useful for SEO but requires touching every
  rendering site to pull alt text from media metadata. Out of scope.
- **No image versions (thumb/medium/large auto-generation).** Storefront
  CSS already handles display sizing.
- **No in-place crop or edit.** Use whatever your usual image tool is.
- **No drag-drop reorder.** The Shop's gallery UI has its own ordering;
  the picker just adds to it.
- **No bulk delete / bulk actions.** Single delete only, with usage
  check.
- **No replacement of branding logo through the picker.** Branding
  lives in Slate core's settings page, which this plugin doesn't
  touch.

## How usage tracking works

When you delete an image, the plugin queries:

```sql
SELECT image_url, gallery_urls FROM shop_products WHERE tenant_id = ?
SELECT v.image_url FROM shop_product_variants v
  JOIN shop_products p ON p.id = v.product_id WHERE p.tenant_id = ?
```

…and sums references. If anything references the path, delete refuses
with a "Used by N product(s)" message. **Remove the references first**
(edit the products that use it, swap their image) before deleting.

The usage check is exact-string equality on the stored path, so an
image referenced as `/uploads/shop/products/abc.webp` matches the file
on disk at the same path. If you've manually edited any of those
fields to alternative shapes (full URLs, etc.) the check may
under-count — be careful around hand-edited paths.

## Where files come from

The library scans three folders:

```
uploads/shop/products/             # main product images
uploads/shop/products/gallery/     # product gallery images
uploads/shop/products/variants/    # per-variant images
```

These are the folders the Shop plugin's `Uploads::handle()` calls
write into. We do NOT scan `uploads/branding/`, plugin-private
folders, or anything else.

## Pre-existing files

The cache table (`medialibrary_files`) is **not** prefilled on
install. Pre-existing files in the scan folders appear in the library
immediately — the scan reads them from disk — and dimensions are
computed lazily via `getimagesize()` the first time each one is
listed. New uploads through the library (or via the patched product
editor) get a cache row right away.

This means installing the plugin on an existing store with 150
already-uploaded images just works. No migration step. The first page
load is slightly slower while dimensions are computed; subsequent
loads use the populated cache.

## Permissions

Three new permissions:

- `media.view` — see the library, use the picker
- `media.upload` — upload new files
- `media.delete` — delete (still refuses if files are in use)

Super admins get all three by default.

## Uninstalling

Drops the `medialibrary_files` cache table. **Does NOT touch your
image files on disk.** Those belong to your products. Reinstalling
re-populates the cache lazily on first use.

If you uninstall and someone has the patched `products.php` deployed,
the picker buttons just disappear (gated on `class_exists('MediaLibrary')`)
— the file uploads still work the same way they did before.
