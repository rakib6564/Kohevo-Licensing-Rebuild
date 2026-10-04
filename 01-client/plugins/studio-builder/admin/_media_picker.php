<?php
/**
 * Kohevo Studio — Bridge to Built-in Slate Media Library (Médiathèque).
 *
 * Connects all media fields to the built-in core Media Library via SlateMedia.open().
 * Reuses the native picker assets (/plugins/media-library/assets/js/picker.js)
 * with zero redundant modals and zero emojis.
 */

declare(strict_types=1);

if (!function_exists('sb_media_picker_field')) {
    /**
     * Render an interactive media picker field with live preview box and "Browse Library" button.
     *
     * @param array{
     *   id: string,
     *   name: string,
     *   label: string,
     *   value?: string,
     *   placeholder?: string,
     *   required?: bool,
     *   help?: string,
     *   variant?: 'standard'|'avatar'|'compact'
     * } $opts
     */
    function sb_media_picker_field(array $opts): string
    {
        $id          = htmlspecialchars($opts['id'] ?? 'sb_media_' . bin2hex(random_bytes(4)), ENT_QUOTES);
        $name        = htmlspecialchars($opts['name'] ?? 'image_url', ENT_QUOTES);
        $label       = htmlspecialchars($opts['label'] ?? 'Image Asset', ENT_QUOTES);
        $val         = htmlspecialchars($opts['value'] ?? '', ENT_QUOTES);
        $placeholder = htmlspecialchars($opts['placeholder'] ?? 'https://... or choose from Media Library', ENT_QUOTES);
        $required    = !empty($opts['required']);
        $help        = htmlspecialchars($opts['help'] ?? '', ENT_QUOTES);
        $variant     = $opts['variant'] ?? 'standard';

        $hasVal = $val !== '';
        $avatarClass = $variant === 'avatar' ? ' sb-media-preview--avatar' : '';

        $html = '<div class="sb-media-field-group" data-sb-media-field="' . $id . '">';
        $html .= '<label class="field-label" for="' . $id . '">' . $label . ($required ? ' <span class="field-required">*</span>' : '') . '</label>';
        
        $html .= '<div class="sb-media-input-composite">';
        
        // Thumbnail preview container
        $html .= '<div class="sb-media-preview-box' . $avatarClass . '" id="preview_' . $id . '" title="Image Preview">';
        if ($hasVal) {
            $html .= '<img src="' . $val . '" alt="Preview" class="sb-media-preview-img" onerror="this.style.display=\'none\';">';
        } else {
            $html .= '<div class="sb-media-preview-empty">' . sb_svg('media', 18) . '</div>';
        }
        $html .= '</div>';

        // URL input + Action Buttons
        $html .= '<div class="sb-media-controls">';
        $html .= '<div class="sb-media-input-wrapper">';
        $html .= '<input type="url" name="' . $name . '" id="' . $id . '" value="' . $val . '" ' . ($required ? 'required ' : '') . 'placeholder="' . $placeholder . '" class="form-control sb-media-url-input" oninput="SbMediaPicker.updatePreview(\'' . $id . '\', this.value);">';
        $html .= '</div>';

        $html .= '<div class="sb-media-btn-group">';
        $html .= '<button type="button" class="btn btn-outline sb-btn-browse-media" onclick="SbMediaPicker.open(\'' . $id . '\');">';
        $html .= sb_svg('media', 14);
        $html .= '<span>Browse Library</span>';
        $html .= '</button>';

        $html .= '<button type="button" class="btn btn-outline sb-btn-clear-media" id="clear_' . $id . '" onclick="SbMediaPicker.clearField(\'' . $id . '\');" style="' . ($hasVal ? '' : 'display: none;') . '" title="Clear selected image">';
        $html .= sb_svg('close', 12);
        $html .= '</button>';
        $html .= '</div>'; // .sb-media-btn-group

        $html .= '</div>'; // .sb-media-controls
        $html .= '</div>'; // .sb-media-input-composite

        if ($help !== '') {
            $html .= '<div class="field-help" style="margin-top: 6px; font-size: 0.78rem; color: #64748b;">' . $help . '</div>';
        }

        $html .= '</div>'; // .sb-media-field-group

        return $html;
    }
}

if (!function_exists('sb_render_media_picker_modal')) {
    /**
     * Enqueues the built-in Slate Media Library picker scripts/styles and initializes the bridge.
     */
    function sb_render_media_picker_modal(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        $pickerCss = plugin_url('media-library', 'assets/css/picker.css');
        $pickerJs  = plugin_url('media-library', 'assets/js/picker.js');
        ?>
        <!-- Built-in Slate Media Library Picker Assets & Bridge -->
        <link rel="stylesheet" href="<?= e($pickerCss) ?>">
        <script src="<?= e($pickerJs) ?>"></script>

        <style>
            /* ── Media Field Group Styles ── */
            .sb-media-field-group {
                margin-bottom: 16px;
            }
            .sb-media-input-composite {
                display: flex;
                gap: 12px;
                align-items: center;
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                padding: 10px 14px;
                transition: border-color 0.15s ease, box-shadow 0.15s ease;
            }
            .sb-media-input-composite:focus-within {
                border-color: #6366f1;
                box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
            }
            .sb-media-preview-box {
                width: 52px;
                height: 52px;
                min-width: 52px;
                background: #f1f5f9;
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                overflow: hidden;
                display: flex;
                align-items: center;
                justify-content: center;
                position: relative;
            }
            .sb-media-preview--avatar {
                border-radius: 50%;
            }
            .sb-media-preview-img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
            }
            .sb-media-preview-empty {
                color: #94a3b8;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .sb-media-controls {
                flex: 1;
                display: flex;
                gap: 10px;
                align-items: center;
                flex-wrap: wrap;
            }
            .sb-media-input-wrapper {
                flex: 1;
                min-width: 200px;
            }
            .sb-media-url-input {
                width: 100% !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 6px !important;
                padding: 8px 12px !important;
                font-size: 0.88rem !important;
                background: #f8fafc !important;
                font-family: monospace !important;
            }
            .sb-media-url-input:focus {
                background: #ffffff !important;
            }
            .sb-media-btn-group {
                display: flex;
                gap: 6px;
                align-items: center;
            }
            .sb-btn-browse-media {
                display: inline-flex !important;
                align-items: center !important;
                gap: 6px !important;
                padding: 8px 14px !important;
                font-size: 0.85rem !important;
                font-weight: 600 !important;
                background: #ffffff !important;
                border-color: #cbd5e1 !important;
                color: #334155 !important;
                cursor: pointer !important;
                border-radius: 6px !important;
                white-space: nowrap !important;
            }
            .sb-btn-browse-media:hover {
                border-color: #6366f1 !important;
                color: #6366f1 !important;
                background: #f5f3ff !important;
            }
            .sb-btn-clear-media {
                padding: 8px 10px !important;
                border-radius: 6px !important;
                color: #ef4444 !important;
                border-color: #fecaca !important;
                background: #fff5f5 !important;
                cursor: pointer !important;
            }
            .sb-btn-clear-media:hover {
                background: #fee2e2 !important;
                border-color: #f87171 !important;
            }

            /* Ensure built-in picker overlay is elevated above admin headers */
            .mlp-overlay {
                z-index: 99999 !important;
            }
        </style>

        <script>
        (function() {
            window.SbMediaPicker = {
                /**
                 * Opens the built-in Slate Media Library picker and sets the selected asset on target input.
                 */
                open: function(fieldId) {
                    if (window.SlateMedia && typeof window.SlateMedia.open === 'function') {
                        window.SlateMedia.open({
                            types: 'image',
                            multiple: false,
                            onPick: function(item) {
                                if (!item) return;
                                var url = item.url || (item.path ? ('/' + item.path.replace(/^\/+/, '')) : '');
                                var input = document.getElementById(fieldId);
                                if (input) {
                                    input.value = url;
                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                    input.dispatchEvent(new Event('change', { bubbles: true }));
                                }

                                var idInput = document.getElementById(fieldId + '_id') || document.querySelector('[data-sb-id-for="' + fieldId + '"]');
                                if (idInput && item.id) {
                                    idInput.value = item.id;
                                    idInput.dispatchEvent(new Event('input', { bubbles: true }));
                                    idInput.dispatchEvent(new Event('change', { bubbles: true }));
                                }

                                SbMediaPicker.updatePreview(fieldId, url);
                            }
                        });
                    } else if (window.MediaPicker && typeof window.MediaPicker.open === 'function') {
                        window.MediaPicker.open({
                            mode: 'single',
                            onPick: function(path, item) {
                                var url = (item && item.url) ? item.url : (path ? ('/' + path.replace(/^\/+/, '')) : '');
                                var input = document.getElementById(fieldId);
                                if (input) {
                                    input.value = url;
                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                    input.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                                SbMediaPicker.updatePreview(fieldId, url);
                            }
                        });
                    } else {
                        alert('Built-in Media Library picker is loading, please try again.');
                    }
                },

                /**
                 * Opens the built-in Slate Media Library picker for updating an existing card in one click.
                 */
                openForCard: function(itemIndex, itemType) {
                    var onSelect = function(url) {
                        var form = document.createElement('form');
                        form.method = 'POST';
                        form.style.display = 'none';

                        var fAction = document.createElement('input');
                        fAction.type = 'hidden'; fAction.name = 'action_update_item_image'; fAction.value = '1';
                        form.appendChild(fAction);

                        var fCsrf = document.createElement('input');
                        fCsrf.type = 'hidden'; fCsrf.name = 'csrf_token'; fCsrf.value = <?= json_encode(csrf_token()) ?>;
                        form.appendChild(fCsrf);

                        var fType = document.createElement('input');
                        fType.type = 'hidden'; fType.name = 'item_type'; fType.value = itemType;
                        form.appendChild(fType);

                        var fIdx = document.createElement('input');
                        fIdx.type = 'hidden'; fIdx.name = 'item_index'; fIdx.value = itemIndex;
                        form.appendChild(fIdx);

                        var fUrl = document.createElement('input');
                        fUrl.type = 'hidden'; fUrl.name = 'image_url'; fUrl.value = url;
                        form.appendChild(fUrl);

                        document.body.appendChild(form);
                        form.submit();
                    };

                    if (window.SlateMedia && typeof window.SlateMedia.open === 'function') {
                        window.SlateMedia.open({
                            types: 'image',
                            multiple: false,
                            onPick: function(item) {
                                if (!item) return;
                                var url = item.url || (item.path ? ('/' + item.path.replace(/^\/+/, '')) : '');
                                onSelect(url);
                            }
                        });
                    } else if (window.MediaPicker && typeof window.MediaPicker.open === 'function') {
                        window.MediaPicker.open({
                            mode: 'single',
                            onPick: function(path, item) {
                                var url = (item && item.url) ? item.url : (path ? ('/' + path.replace(/^\/+/, '')) : '');
                                onSelect(url);
                            }
                        });
                    }
                },

                updatePreview: function(fieldId, url) {
                    var previewBox = document.getElementById('preview_' + fieldId);
                    var clearBtn = document.getElementById('clear_' + fieldId);
                    url = (url || '').trim();

                    if (previewBox) {
                        if (url) {
                            previewBox.innerHTML = '<img src="' + url + '" class="sb-media-preview-img" alt="Preview" onerror="this.style.display=\'none\';">';
                        } else {
                            previewBox.innerHTML = '<div class="sb-media-preview-empty">' + <?= json_encode(sb_svg('media', 18)) ?> + '</div>';
                        }
                    }

                    if (clearBtn) {
                        clearBtn.style.display = url ? 'inline-flex' : 'none';
                    }
                },

                clearField: function(fieldId) {
                    var input = document.getElementById(fieldId);
                    if (input) {
                        input.value = '';
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    var idInput = document.getElementById(fieldId + '_id') || document.querySelector('[data-sb-id-for="' + fieldId + '"]');
                    if (idInput) {
                        idInput.value = '';
                        idInput.dispatchEvent(new Event('input', { bubbles: true }));
                        idInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    this.updatePreview(fieldId, '');
                }
            };
        })();
        </script>
        <?php
    }
}
