<?php
/**
 * Kohevo Studio — Universal Media Picker Component.
 *
 * Provides:
 * - `sb_media_picker_field(array $opts)`: Renders an interactive media picker form field with live preview.
 * - `sb_render_media_picker_modal()`: Injects the accessible Media Picker modal dialog, styling, and JS.
 *
 * Strict zero-emoji policy: only clean SVG stroke outline icons.
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
        $placeholder = htmlspecialchars($opts['placeholder'] ?? 'https://... or choose from library', ENT_QUOTES);
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
        $html .= '<input type="url" name="' . $name . '" id="' . $id . '" value="' . $val . '" ' . ($required ? 'required ' : '') . 'placeholder="' . $placeholder . '" class="form-control sb-media-url-input" oninput="SbMediaPicker.updateFieldPreview(\'' . $id . '\', this.value);">';
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
     * Renders the universal Media Picker modal, stylesheet, and JS controller once per page.
     */
    function sb_render_media_picker_modal(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        $apiEndpoint = defined('SLATE_URL') 
            ? rtrim(SLATE_URL, '/') . '/admin/plugins/studio-builder/api-media.php' 
            : 'api-media.php';
        ?>
        <!-- Studio Builder Universal Media Picker Modal -->
        <style>
            /* ── Media Field Styles ── */
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

            /* ── Media Picker Modal Overlay ── */
            .sb-mp-modal-backdrop {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(15, 23, 42, 0.65);
                backdrop-filter: blur(4px);
                -webkit-backdrop-filter: blur(4px);
                z-index: 99999;
                align-items: center;
                justify-content: center;
                padding: 20px;
                opacity: 0;
                transition: opacity 0.2s ease;
            }
            .sb-mp-modal-backdrop.is-active {
                display: flex;
                opacity: 1;
            }
            .sb-mp-modal {
                background: #ffffff;
                width: 100%;
                max-width: 980px;
                max-height: 88vh;
                border-radius: 16px;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
                display: flex;
                flex-direction: column;
                overflow: hidden;
                border: 1px solid #e2e8f0;
                animation: sbMpSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            }
            @keyframes sbMpSlideUp {
                from { transform: translateY(20px) scale(0.98); opacity: 0; }
                to { transform: translateY(0) scale(1); opacity: 1; }
            }

            /* Header */
            .sb-mp-header {
                padding: 16px 24px;
                border-bottom: 1px solid #e2e8f0;
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: #f8fafc;
            }
            .sb-mp-title-wrap {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .sb-mp-title {
                font-size: 1.15rem;
                font-weight: 700;
                color: #0f172a;
                margin: 0;
            }
            .sb-mp-close {
                background: transparent;
                border: none;
                color: #64748b;
                cursor: pointer;
                padding: 6px;
                border-radius: 6px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .sb-mp-close:hover {
                background: #e2e8f0;
                color: #0f172a;
            }

            /* Navigation Tabs */
            .sb-mp-tabs {
                display: flex;
                background: #f1f5f9;
                padding: 0 24px;
                border-bottom: 1px solid #e2e8f0;
                gap: 8px;
            }
            .sb-mp-tab {
                padding: 12px 18px;
                font-size: 0.88rem;
                font-weight: 600;
                color: #64748b;
                border: none;
                background: transparent;
                cursor: pointer;
                border-bottom: 2px solid transparent;
                display: flex;
                align-items: center;
                gap: 8px;
                transition: color 0.15s ease, border-color 0.15s ease;
            }
            .sb-mp-tab:hover {
                color: #0f172a;
            }
            .sb-mp-tab.is-active {
                color: #6366f1;
                border-bottom-color: #6366f1;
                background: rgba(99, 102, 241, 0.04);
            }

            /* Main Layout */
            .sb-mp-content-area {
                display: flex;
                flex: 1;
                overflow: hidden;
                min-height: 420px;
            }
            .sb-mp-main-pane {
                flex: 1;
                padding: 20px 24px;
                overflow-y: auto;
                display: flex;
                flex-direction: column;
            }
            .sb-mp-sidebar {
                width: 280px;
                border-left: 1px solid #e2e8f0;
                background: #f8fafc;
                padding: 20px;
                display: flex;
                flex-direction: column;
                overflow-y: auto;
            }

            /* Search / Toolbar */
            .sb-mp-toolbar {
                display: flex;
                gap: 12px;
                margin-bottom: 18px;
                align-items: center;
            }
            .sb-mp-search-input {
                flex: 1;
                padding: 8px 14px;
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                font-size: 0.88rem;
                outline: none;
            }
            .sb-mp-search-input:focus {
                border-color: #6366f1;
            }

            /* Grid of Images */
            .sb-mp-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
                gap: 14px;
            }
            .sb-mp-item {
                position: relative;
                aspect-ratio: 1 / 1;
                border-radius: 10px;
                border: 2px solid #e2e8f0;
                overflow: hidden;
                cursor: pointer;
                background: #f1f5f9;
                transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
            }
            .sb-mp-item:hover {
                border-color: #94a3b8;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            }
            .sb-mp-item.is-selected {
                border-color: #6366f1;
                box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.35);
            }
            .sb-mp-item-img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
            }
            .sb-mp-item-badge {
                position: absolute;
                top: 6px;
                right: 6px;
                background: #6366f1;
                color: #ffffff;
                width: 22px;
                height: 22px;
                border-radius: 50%;
                display: none;
                align-items: center;
                justify-content: center;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            }
            .sb-mp-item.is-selected .sb-mp-item-badge {
                display: flex;
            }
            .sb-mp-item-caption {
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
                background: linear-gradient(to top, rgba(0, 0, 0, 0.8) 0%, transparent 100%);
                color: #ffffff;
                font-size: 0.72rem;
                padding: 16px 8px 6px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            /* Dropzone / Upload Pane */
            .sb-mp-upload-zone {
                border: 2px dashed #cbd5e1;
                border-radius: 12px;
                padding: 48px 24px;
                text-align: center;
                background: #f8fafc;
                cursor: pointer;
                transition: border-color 0.15s ease, background-color 0.15s ease;
                margin-top: 10px;
            }
            .sb-mp-upload-zone:hover,
            .sb-mp-upload-zone.is-dragover {
                border-color: #6366f1;
                background: #f5f3ff;
            }
            .sb-mp-upload-icon {
                color: #6366f1;
                margin-bottom: 12px;
            }

            /* Sidebar Details */
            .sb-mp-sidebar-empty {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                height: 100%;
                color: #94a3b8;
                font-size: 0.85rem;
                text-align: center;
                padding: 20px;
            }
            .sb-mp-detail-thumb {
                width: 100%;
                height: 160px;
                border-radius: 8px;
                object-fit: cover;
                border: 1px solid #cbd5e1;
                margin-bottom: 14px;
            }
            .sb-mp-detail-meta {
                font-size: 0.8rem;
                color: #64748b;
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .sb-mp-detail-meta strong {
                color: #0f172a;
            }

            /* Footer */
            .sb-mp-footer {
                padding: 14px 24px;
                background: #f8fafc;
                border-top: 1px solid #e2e8f0;
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 12px;
            }
            .sb-mp-selected-summary {
                font-size: 0.85rem;
                color: #475569;
                max-width: 450px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .sb-mp-footer-actions {
                display: flex;
                gap: 10px;
            }
        </style>

        <div class="sb-mp-modal-backdrop" id="sbMediaPickerBackdrop">
            <div class="sb-mp-modal" role="dialog" aria-modal="true" aria-labelledby="sbMpTitle">
                <!-- Header -->
                <div class="sb-mp-header">
                    <div class="sb-mp-title-wrap">
                        <?= sb_svg('media', 20) ?>
                        <h3 class="sb-mp-title" id="sbMpTitle">Media Library & Asset Picker</h3>
                    </div>
                    <button type="button" class="sb-mp-close" onclick="SbMediaPicker.close();" aria-label="Close">
                        <?= sb_svg('close', 18) ?>
                    </button>
                </div>

                <!-- Tabs -->
                <div class="sb-mp-tabs">
                    <button type="button" class="sb-mp-tab is-active" data-sb-tab="library" onclick="SbMediaPicker.switchTab('library');">
                        <?= sb_svg('media', 15) ?>
                        <span>Uploaded Media</span>
                    </button>
                    <button type="button" class="sb-mp-tab" data-sb-tab="presets" onclick="SbMediaPicker.switchTab('presets');">
                        <?= sb_svg('sparkles', 15) ?>
                        <span>Showcase Presets</span>
                    </button>
                    <button type="button" class="sb-mp-tab" data-sb-tab="upload" onclick="SbMediaPicker.switchTab('upload');">
                        <?= sb_svg('upload', 15) ?>
                        <span>Upload File</span>
                    </button>
                    <button type="button" class="sb-mp-tab" data-sb-tab="custom_url" onclick="SbMediaPicker.switchTab('custom_url');">
                        <?= sb_svg('link', 15) ?>
                        <span>Direct URL</span>
                    </button>
                </div>

                <!-- Content Area -->
                <div class="sb-mp-content-area">
                    <!-- Main Left Pane -->
                    <div class="sb-mp-main-pane">
                        <!-- Tab 1: Library -->
                        <div id="sbMpTab_library" class="sb-mp-tab-panel">
                            <div class="sb-mp-toolbar">
                                <input type="search" class="sb-mp-search-input" id="sbMpSearchInput" placeholder="Search assets by filename or category..." oninput="SbMediaPicker.filterGrid(this.value);">
                                <button type="button" class="btn btn-sm btn-outline" onclick="SbMediaPicker.loadAssets();" title="Refresh Assets">
                                    <?= sb_svg('refresh', 14) ?>
                                    <span>Refresh</span>
                                </button>
                            </div>
                            <div class="sb-mp-grid" id="sbMpGrid">
                                <!-- Populated dynamically by JS -->
                            </div>
                            <div id="sbMpEmpty" style="display: none; padding: 40px; text-align: center; color: #94a3b8;">
                                <?= sb_svg('media', 36) ?>
                                <p style="margin-top: 10px; font-size: 0.95rem;">No media assets found matching your criteria.</p>
                            </div>
                        </div>

                        <!-- Tab 2: Presets -->
                        <div id="sbMpTab_presets" class="sb-mp-tab-panel" style="display: none;">
                            <p style="margin: 0 0 14px 0; font-size: 0.88rem; color: #64748b;">
                                High-resolution showcase assets curated for freelancer portfolios, enterprise SaaS dashboards, and executive avatars.
                            </p>
                            <div class="sb-mp-grid" id="sbMpPresetsGrid">
                                <!-- Populated dynamically by JS -->
                            </div>
                        </div>

                        <!-- Tab 3: Upload -->
                        <div id="sbMpTab_upload" class="sb-mp-tab-panel" style="display: none;">
                            <div class="sb-mp-upload-zone" id="sbMpDropZone" onclick="document.getElementById('sbMpFileInput').click();">
                                <div class="sb-mp-upload-icon"><?= sb_svg('upload', 42) ?></div>
                                <h4 style="margin: 0 0 6px 0; color: #0f172a; font-size: 1.1rem;">Drop media files here or click to browse</h4>
                                <p style="margin: 0 0 16px 0; color: #64748b; font-size: 0.85rem;">Supports JPG, PNG, WEBP, GIF, SVG (up to 15MB)</p>
                                <button type="button" class="btn btn-primary" style="pointer-events: none;">
                                    <?= sb_svg('plus', 14) ?>
                                    <span>Select File From Device</span>
                                </button>
                                <input type="file" id="sbMpFileInput" style="display: none;" accept="image/*" onchange="SbMediaPicker.handleFileSelect(this.files);">
                            </div>
                            <div id="sbMpUploadProgress" style="display: none; margin-top: 20px; padding: 14px; background: #e0e7ff; border-radius: 8px; color: #3730a3; font-size: 0.9rem;">
                                Uploading asset to library...
                            </div>
                        </div>

                        <!-- Tab 4: Direct URL -->
                        <div id="sbMpTab_custom_url" class="sb-mp-tab-panel" style="display: none;">
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px;">
                                <label class="field-label" style="font-weight: 600; margin-bottom: 6px;">External Image URL</label>
                                <input type="url" id="sbMpCustomUrlInput" class="form-control" placeholder="https://images.unsplash.com/..." style="width: 100%; margin-bottom: 12px;" oninput="SbMediaPicker.previewCustomUrl(this.value);">
                                <p style="margin: 0; font-size: 0.8rem; color: #64748b;">Paste any direct CDN or Unsplash URL. It will be immediately previewed in the sidebar.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Right Sidebar / Preview Drawer -->
                    <div class="sb-mp-sidebar" id="sbMpSidebar">
                        <div class="sb-mp-sidebar-empty" id="sbMpSidebarEmpty">
                            <?= sb_svg('media', 32) ?>
                            <p style="margin-top: 10px;">Click an image in the grid to view details and select it.</p>
                        </div>
                        <div id="sbMpSidebarActive" style="display: none;">
                            <img src="" id="sbMpSidebarImg" class="sb-mp-detail-thumb" alt="Preview">
                            <div class="sb-mp-detail-meta">
                                <div><strong>Name:</strong> <span id="sbMpDetailName"></span></div>
                                <div id="sbMpDetailDimsRow"><strong>Dimensions:</strong> <span id="sbMpDetailDims">-</span></div>
                                <div id="sbMpDetailSizeRow"><strong>File Size:</strong> <span id="sbMpDetailSize">-</span></div>
                                <div><strong>URL:</strong> <span id="sbMpDetailUrl" style="word-break: break-all; font-family: monospace; font-size: 0.75rem;"></span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="sb-mp-footer">
                    <div class="sb-mp-selected-summary" id="sbMpSelectedSummary">
                        No asset selected
                    </div>
                    <div class="sb-mp-footer-actions">
                        <button type="button" class="btn btn-outline" onclick="SbMediaPicker.close();">Cancel</button>
                        <button type="button" class="btn btn-primary" id="sbMpInsertBtn" disabled onclick="SbMediaPicker.commitSelection();">
                            <?= sb_svg('check', 14) ?>
                            <span>Insert Selected Media →</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var API_URL = <?= json_encode($apiEndpoint) ?>;
            var CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

            var activeTargetId = null;
            var allItems = [];
            var allPresets = [];
            var selectedAsset = null;

            window.SbMediaPicker = {
                open: function(targetInputId) {
                    activeTargetId = targetInputId;
                    selectedAsset = null;
                    var modal = document.getElementById('sbMediaPickerBackdrop');
                    if (!modal) return;

                    modal.classList.add('is-active');
                    this.switchTab('library');
                    this.updateSidebar(null);

                    // Check if current target already has a value
                    var targetInput = document.getElementById(targetInputId);
                    if (targetInput && targetInput.value) {
                        this.selectByUrl(targetInput.value);
                    }

                    if (allItems.length === 0) {
                        this.loadAssets();
                    }
                },

                close: function() {
                    var modal = document.getElementById('sbMediaPickerBackdrop');
                    if (modal) modal.classList.remove('is-active');
                    activeTargetId = null;
                    selectedAsset = null;
                    this.activeCardUpdate = null;
                },

                switchTab: function(tabKey) {
                    var tabs = document.querySelectorAll('.sb-mp-tab');
                    tabs.forEach(function(t) {
                        t.classList.toggle('is-active', t.getAttribute('data-sb-tab') === tabKey);
                    });

                    var panels = document.querySelectorAll('.sb-mp-tab-panel');
                    panels.forEach(function(p) {
                        p.style.display = 'none';
                    });

                    var activePanel = document.getElementById('sbMpTab_' + tabKey);
                    if (activePanel) activePanel.style.display = 'block';
                },

                loadAssets: function() {
                    var grid = document.getElementById('sbMpGrid');
                    var presetsGrid = document.getElementById('sbMpPresetsGrid');
                    if (!grid) return;

                    grid.innerHTML = '<div style="grid-column: 1/-1; padding: 20px; color: #94a3b8;">Loading media library assets...</div>';

                    fetch(API_URL + '?action=list', {
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                    })
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        if (data && data.ok) {
                            allItems = data.items || [];
                            allPresets = data.presets || [];
                            SbMediaPicker.renderGrid(allItems, grid);
                            SbMediaPicker.renderGrid(allPresets, presetsGrid);
                        } else {
                            grid.innerHTML = '<div style="grid-column: 1/-1; color: #ef4444;">Failed to load assets: ' + (data.error || 'Server error') + '</div>';
                        }
                    })
                    .catch(function(err) {
                        grid.innerHTML = '<div style="grid-column: 1/-1; color: #ef4444;">Error connecting to media API.</div>';
                    });
                },

                renderGrid: function(items, container) {
                    if (!container) return;
                    container.innerHTML = '';

                    if (!items || items.length === 0) {
                        var emptyEl = document.getElementById('sbMpEmpty');
                        if (emptyEl && container.id === 'sbMpGrid') emptyEl.style.display = 'block';
                        return;
                    }

                    var emptyEl = document.getElementById('sbMpEmpty');
                    if (emptyEl && container.id === 'sbMpGrid') emptyEl.style.display = 'none';

                    items.forEach(function(it) {
                        var itemEl = document.createElement('div');
                        itemEl.className = 'sb-mp-item';
                        itemEl.setAttribute('data-url', it.url);
                        itemEl.setAttribute('data-id', it.id);

                        itemEl.innerHTML = 
                            '<img src="' + it.url + '" class="sb-mp-item-img" alt="' + (it.name || '') + '" loading="lazy">' +
                            '<div class="sb-mp-item-badge">' + <?= json_encode(sb_svg('check', 12)) ?> + '</div>' +
                            '<div class="sb-mp-item-caption">' + (it.name || 'Image') + '</div>';

                        itemEl.addEventListener('click', function() {
                            SbMediaPicker.selectAsset(it, itemEl);
                        });

                        container.appendChild(itemEl);
                    });
                },

                filterGrid: function(query) {
                    query = (query || '').toLowerCase().trim();
                    var filtered = allItems.filter(function(it) {
                        return (it.name || '').toLowerCase().indexOf(query) !== -1 ||
                               (it.path || '').toLowerCase().indexOf(query) !== -1;
                    });
                    this.renderGrid(filtered, document.getElementById('sbMpGrid'));
                },

                selectAsset: function(asset, cardEl) {
                    selectedAsset = asset;
                    document.querySelectorAll('.sb-mp-item').forEach(function(el) {
                        el.classList.remove('is-selected');
                    });
                    if (cardEl) {
                        cardEl.classList.add('is-selected');
                    }
                    this.updateSidebar(asset);

                    var insertBtn = document.getElementById('sbMpInsertBtn');
                    if (insertBtn) insertBtn.disabled = false;
                },

                selectByUrl: function(url) {
                    var found = allItems.find(function(it) { return it.url === url; }) ||
                                allPresets.find(function(it) { return it.url === url; });
                    if (found) {
                        this.selectAsset(found, null);
                    }
                },

                previewCustomUrl: function(url) {
                    url = (url || '').trim();
                    if (!url) {
                        this.updateSidebar(null);
                        return;
                    }
                    var customAsset = {
                        id: 0,
                        name: url.split('/').pop().split('?')[0] || 'Custom Image',
                        url: url,
                        kind: 'image',
                        size_formatted: 'Remote CDN',
                        width: null,
                        height: null
                    };
                    selectedAsset = customAsset;
                    this.updateSidebar(customAsset);
                    var insertBtn = document.getElementById('sbMpInsertBtn');
                    if (insertBtn) insertBtn.disabled = false;
                },

                updateSidebar: function(asset) {
                    var emptyEl = document.getElementById('sbMpSidebarEmpty');
                    var activeEl = document.getElementById('sbMpSidebarActive');
                    var summaryEl = document.getElementById('sbMpSelectedSummary');
                    var insertBtn = document.getElementById('sbMpInsertBtn');

                    if (!asset) {
                        if (emptyEl) emptyEl.style.display = 'flex';
                        if (activeEl) activeEl.style.display = 'none';
                        if (summaryEl) summaryEl.textContent = 'No asset selected';
                        if (insertBtn) insertBtn.disabled = true;
                        return;
                    }

                    if (emptyEl) emptyEl.style.display = 'none';
                    if (activeEl) activeEl.style.display = 'block';

                    var imgEl = document.getElementById('sbMpSidebarImg');
                    if (imgEl) imgEl.src = asset.url;

                    var nameEl = document.getElementById('sbMpDetailName');
                    if (nameEl) nameEl.textContent = asset.name || 'Image';

                    var dimsEl = document.getElementById('sbMpDetailDims');
                    if (dimsEl) dimsEl.textContent = asset.width && asset.height ? (asset.width + ' × ' + asset.height + ' px') : 'Natural scale';

                    var sizeEl = document.getElementById('sbMpDetailSize');
                    if (sizeEl) sizeEl.textContent = asset.size_formatted || 'Standard';

                    var urlEl = document.getElementById('sbMpDetailUrl');
                    if (urlEl) urlEl.textContent = asset.url;

                    if (summaryEl) summaryEl.textContent = 'Selected: ' + (asset.name || asset.url);
                    if (insertBtn) insertBtn.disabled = false;
                },

                openForCard: function(itemIndex, itemType) {
                    this.activeCardUpdate = { index: itemIndex, type: itemType };
                    this.open(null);
                },

                commitSelection: function() {
                    if (!selectedAsset) return;

                    if (this.activeCardUpdate) {
                        var cardInfo = this.activeCardUpdate;
                        this.activeCardUpdate = null;
                        var form = document.createElement('form');
                        form.method = 'POST';
                        form.style.display = 'none';

                        var fAction = document.createElement('input');
                        fAction.type = 'hidden'; fAction.name = 'action_update_item_image'; fAction.value = '1';
                        form.appendChild(fAction);

                        var fCsrf = document.createElement('input');
                        fCsrf.type = 'hidden'; fCsrf.name = 'csrf_token'; fCsrf.value = CSRF_TOKEN;
                        form.appendChild(fCsrf);

                        var fType = document.createElement('input');
                        fType.type = 'hidden'; fType.name = 'item_type'; fType.value = cardInfo.type;
                        form.appendChild(fType);

                        var fIdx = document.createElement('input');
                        fIdx.type = 'hidden'; fIdx.name = 'item_index'; fIdx.value = cardInfo.index;
                        form.appendChild(fIdx);

                        var fUrl = document.createElement('input');
                        fUrl.type = 'hidden'; fUrl.name = 'image_url'; fUrl.value = selectedAsset.url;
                        form.appendChild(fUrl);

                        document.body.appendChild(form);
                        form.submit();
                        this.close();
                        return;
                    }

                    if (!activeTargetId) return;

                    var targetInput = document.getElementById(activeTargetId);
                    if (targetInput) {
                        targetInput.value = selectedAsset.url;
                        // Fire input & change events for reactive bindings
                        targetInput.dispatchEvent(new Event('input', { bubbles: true }));
                        targetInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }

                    // Check if an associated ID input exists
                    var idInput = document.getElementById(activeTargetId + '_id') || document.querySelector('[data-sb-id-for="' + activeTargetId + '"]');
                    if (idInput && selectedAsset.id) {
                        idInput.value = selectedAsset.id;
                        idInput.dispatchEvent(new Event('input', { bubbles: true }));
                        idInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }

                    this.updateFieldPreview(activeTargetId, selectedAsset.url);
                    this.close();
                },

                updateFieldPreview: function(fieldId, url) {
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
                    var targetInput = document.getElementById(fieldId);
                    if (targetInput) {
                        targetInput.value = '';
                        targetInput.dispatchEvent(new Event('input', { bubbles: true }));
                        targetInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    this.updateFieldPreview(fieldId, '');
                },

                handleFileSelect: function(files) {
                    if (!files || files.length === 0) return;
                    var file = files[0];

                    var progressEl = document.getElementById('sbMpUploadProgress');
                    if (progressEl) {
                        progressEl.style.display = 'block';
                        progressEl.textContent = 'Uploading "' + file.name + '" to Media Library...';
                    }

                    var formData = new FormData();
                    formData.append('action', 'upload');
                    formData.append('file', file);
                    formData.append('csrf_token', CSRF_TOKEN);

                    fetch(API_URL, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                    })
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        if (progressEl) progressEl.style.display = 'none';
                        if (data && data.ok && data.item) {
                            allItems.unshift(data.item);
                            SbMediaPicker.renderGrid(allItems, document.getElementById('sbMpGrid'));
                            SbMediaPicker.switchTab('library');
                            SbMediaPicker.selectAsset(data.item, null);
                        } else {
                            alert('Upload error: ' + (data.error || 'Server rejected file.'));
                        }
                    })
                    .catch(function(err) {
                        if (progressEl) progressEl.style.display = 'none';
                        alert('Upload failed: ' + (err.message || 'Network error'));
                    });
                }
            };

            // Setup Drag & Drop handlers
            document.addEventListener('DOMContentLoaded', function() {
                var dropZone = document.getElementById('sbMpDropZone');
                if (dropZone) {
                    ['dragenter', 'dragover'].forEach(function(eventName) {
                        dropZone.addEventListener(eventName, function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            dropZone.classList.add('is-dragover');
                        }, false);
                    });
                    ['dragleave', 'drop'].forEach(function(eventName) {
                        dropZone.addEventListener(eventName, function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            dropZone.classList.remove('is-dragover');
                        }, false);
                    });
                    dropZone.addEventListener('drop', function(e) {
                        var dt = e.dataTransfer;
                        var files = dt.files;
                        SbMediaPicker.handleFileSelect(files);
                    }, false);
                }

                // Close on backdrop click
                var backdrop = document.getElementById('sbMediaPickerBackdrop');
                if (backdrop) {
                    backdrop.addEventListener('click', function(e) {
                        if (e.target === backdrop) SbMediaPicker.close();
                    });
                }

                // Keyboard escape to close
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && backdrop && backdrop.classList.contains('is-active')) {
                        SbMediaPicker.close();
                    }
                });
            });
        })();
        </script>
        <?php
    }
}
