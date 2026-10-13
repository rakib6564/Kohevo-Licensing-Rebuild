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
| Left panel width | 300 | 347 |
| Top bar height | 48 | 56 |
| Control row pitch | 42 | about 36 to 44 |
| Input height | 30 | 28 (`--sbx-control-h`) |

## Phases

1. Design foundation: tokens, palette, guard test (this file's palette).
2. Top bar and one left panel that swaps between elements and edit.
3. Controls: label-left rows, device marks, unit fields.
4. Add panel: tile grid, search, groups.
5. Canvas: outline, floating toolbar, empty drop zone, layout chooser.
6. Structure (Navigator) window.
7. Library modal.
8. Globals: colour and font tokens.
