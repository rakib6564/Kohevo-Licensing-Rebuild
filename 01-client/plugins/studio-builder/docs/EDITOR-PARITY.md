# Editor parity: working notes

The target is the workflow and density of a modern page-builder editor (one left panel that swaps between the element
list and an element's controls, a thin top bar, an accordion of controls with the label on the left), drawn with Kohevo's
own icons, type and accent. Nothing is copied: no assets, wording or code.

## Reference palette (sampled from reference screenshots)

| Role | Value | Token |
| --- | --- | --- |
| App bar / outer background | `#0b0d0f` | `--sbx-bg` |
| Panel surface | `#1f2125` | `--sbx-panel` |
| Control (input) surface | `#26282c` | `--sbx-input-bg`, `--sbx-panel-elevated` |
| Selected row / tile | `#333438` | `--sbx-selected` |
| Active unit pill | `#5a5c60` | `--sbx-unit-bg` |
| Body text | `#d2d4d8` | `--sbx-text` |
| Label / helper text | `#adafb3` | `--sbx-muted` |
| Divider | `#212327` to `#232428` | `--sbx-border` (7% white) |

The accent stays Kohevo violet (`--sbx-accent`).

## Measurements (reference screenshots are scaled about 1.39x from CSS pixels)

| Part | Reference (CSS px, approx.) | Kohevo now |
| --- | --- | --- |
| Left panel width | 300 | 320 (`--sbx-left-w`) |
| Top bar height | 48 | 48 (`--sbx-topbar-h`) |
| Control row pitch | 42 | about 36 to 44 |
| Input height | 30 | 28 (`--sbx-control-h`) |

## Phases

1. Design foundation: tokens, palette, guard test (this file's palette).
2. Top bar (48px, logo only) and one left panel with an Edit tab: a canvas pick or a new element opens Edit; the right-hand Inspector is gone on desktop. Done.
3. Controls (desktop): plain section headers, label-left rows for every single-line field, joined unit fields, a slim device mark. Done as one final CSS layer, "Editor controls", at the end of `builder.css`; the phone sheet keeps its 44px controls.
4. Add panel: folding groups, a New badge, quieter tiles, and the two "Container" tiles told apart. Still to do: fold the search, the six sub-tabs and the category chips into one lighter header.
5. Canvas: outline, floating toolbar, empty drop zone, layout chooser. Done.
6. Structure (Navigator) window: a floating, draggable, resizable window with the page outline, from the top bar or Ctrl/⌘ I (desktop). Done.
7. Library modal.
8. Globals: colour and font tokens.
