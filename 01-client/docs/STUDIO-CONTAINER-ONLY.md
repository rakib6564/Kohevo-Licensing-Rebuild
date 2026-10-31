# Kohevo Studio: Container-only page structure

Status: design for branch `feat/studio-container-only` (built on PR #209).

## The model

A page is a list of top-level Containers. Containers nest in Containers. Each is Flexbox or Grid. No Section exists in the
product or in the stored format, and widgets cannot sit at the top level: the builder wraps them in a new Container.

```json
{ "schema_version": "2.0", "document_type": "page", "template_key": "default",
  "settings": {}, "seo": {}, "containers": [ { "id": "blk_…", "type": "core.container", "children": [] } ] }
```

A top-level Container is an ordinary `core.container` block. There is one node type, one inspector, one set of operations.

## Why no inner wrapper

The Container already draws its boxed width on one element (`style.layout.content_width: boxed`, side padding worked out
by `StyleSurface::emitBoxed`), and its Flexbox, Grid, responsive and live-canvas code all assume that one element. A
second element like Elementor's `.e-con-inner` would need every layout rule redirected to it, in PHP and in the canvas.
So a Section's two elements become one, and the padding formula gives the same content box.

For padding `pl`/`pr`, gutter `g` and limit `L`, the old inner box and the new element agree:

    padding-left: calc(pl + g + max(0px, (100% - pl - pr - L) / 2))

## New style fields

| Field | Values | Meaning |
|---|---|---|
| `style.layout.content_size` | `narrow`, `normal`, `wide`, `full` | Boxed to a site Layout width. Absent means the site's content width, or `content_max` when that is set. |
| `style.layout.gutter` | `site` | Adds the site's section gutter to both sides. |
| `style.layout.padding_y` | spacing scale | Top and bottom padding in the site's spacing tokens, per device too. `style.padding` wins. |
| `responsive.<device>.style.layout.content_max` | length | A device's own boxed width (written as `--sb-box`). |

## What a Section becomes

| Section | Container |
|---|---|
| `id: sec_x` | `id: blk_x`, so the id stays stable while upgrades run in memory |
| `label`, `locked` | `metadata.label`, `metadata.locked` |
| `global_ref` | `global_ref` (a new block key, top level only; the block owns no children) |
| `blocks` | `children` |
| `<section>` | `tag: section` |
| `layout.background_token` | `style.surface_token` |
| `layout.gap` | `props.gap` |
| `layout.columns` (capped at the block count) | `style.layout.display: grid`, `style.layout.columns`, `responsive.tablet/mobile.layout.columns` |
| `layout.padding_y` | `style.layout.padding_y`, per device in `responsive` |
| `layout.width` | `style.layout.content_width: boxed` + `content_size`, or `content_max` for custom |
| `layout.custom_width` | `content_max`, per device in `responsive` |
| `style.background`, `style.padding`, border | `style.*` |
| `visibility`, `animation`, `interactions` | same keys |
| (always) | `style.layout.gutter: site`, `classNames: ['sb-section']` for sites whose CSS targets that class |

A `layout.section` block becomes a `core.container` the same way. `section_preset` becomes `container_preset` in both
`page_type` and `template_type` (`StudioSchemaManager` migrates the rows), and in stored `document_type`.

Limits rise to absorb the extra level: 300 blocks, nesting depth 7, 50 top-level Containers.

## Upgrade

`DocumentUpgrader::structure()` needs no registry and is idempotent. It runs on every read and in
`DocumentNormalizer::validateAndNormalize`, so 1.0 documents, revisions, packages and templates keep working. The
validator accepts 2.0 only. Stored revisions are not rewritten; the next save writes 2.0. `core.container` goes to version
2 with an identity upgrade so the compilation fingerprint changes and every page recompiles (the `.sb-section__inner`
CSS is gone).

## Operations

Every `*_section*` operation is removed. `insert_block`, `move_block` take `parent_id: null` for the top level, where only
a `core.container` is valid; the other block operations work on any node. `update_block_meta` carries label and lock. Old
operation names fail as unknown, with a message naming the replacement. MCP tools follow.

## Known differences

- A Section whose blocks all render nothing now leaves no box on a public page, like any Container.
- `.sb-section__inner` no longer exists, so site CSS aimed at it stops applying. `.sb-section` still matches upgraded
  Containers, but a Section's padding no longer carries `!important`.
- A nested `layout.section` set to full width no longer breaks out to 100vw, and its boxed background now fills the row.
- Built-in Container presets stack their children, where section presets made equal columns.
- Duplicating a top-level Container no longer adds " (Copy)" to its name.
- A `sm`-only (640px) column or padding value has no device in the panel; it folds into the phone's value.
