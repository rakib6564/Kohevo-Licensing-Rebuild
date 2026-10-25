<?php
/**
 * Slate — Uploads.
 *
 * Generic file upload helper. No domain knowledge — plugins call
 * Uploads::handle() with their own size/type rules.
 *
 * Files land under /uploads/<folder>/ with a randomized filename. Returns the
 * relative public path on success ("/uploads/foo/...") or null on failure.
 *
 * Phase 1 A3: migrated from includes/Uploads.php into Slate\Services\Media. The
 * old global name `Uploads` resolves via a class_alias in src/compat/aliases.php.
 * Behavior identical — the only code change is qualifying the global `finfo`
 * class to `\finfo` so it resolves outside this namespace.
 */

declare(strict_types=1);

namespace Slate\Services\Media;

class Uploads
{
    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024; // 10 MB

    /** Marks the SVG sandbox block in an upload folder's .htaccess. */
    public const SVG_SANDBOX_MARKER = '# kohevo: svg sandbox';

    /** Apache rules that serve SVGs with a sandboxed, script-free CSP. */
    public static function svgSandboxRules(): string
    {
        return self::SVG_SANDBOX_MARKER . "\n" .
            "<IfModule mod_headers.c>\n" .
            "    <FilesMatch \"\\.svgz?$\">\n" .
            "        Header set Content-Security-Policy \"default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox\"\n" .
            "        Header set X-Content-Type-Options \"nosniff\"\n" .
            "    </FilesMatch>\n" .
            "</IfModule>\n";
    }

    public const IMAGE_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    ];

    public const DOC_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * Handle a single uploaded file from $_FILES[$field].
     *
     * Options:
     *   max_bytes        int    max file size (default 10MB)
     *   allowed_mimes    array  whitelist of MIME types
     *   allowed_exts     array  whitelist of extensions ('jpg', 'png', ...)
     *
     * Returns ['ok'=>true,'path'=>'/uploads/...','filename'=>...]
     *         or ['ok'=>false,'error'=>'...'].
     */
    public static function handle(string $field, string $folder, array $opts = []): array
    {
        if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
            return ['ok' => false, 'error' => __('core_err_no_file_submitted', 'No file submitted.')];
        }
        $file = $_FILES[$field];

        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE)      return ['ok' => false, 'error' => __('core_err_no_file_submitted', 'No file submitted.')];
        if ($err === UPLOAD_ERR_INI_SIZE)     return ['ok' => false, 'error' => __('core_err_file_exceeds_server_upload_size_limit', 'File exceeds server upload size limit.')];
        if ($err === UPLOAD_ERR_FORM_SIZE)    return ['ok' => false, 'error' => __('core_err_file_exceeds_form_upload_size_limit', 'File exceeds form upload size limit.')];
        if ($err !== UPLOAD_ERR_OK)           return ['ok' => false, 'error' => "Upload error ($err)."];

        $maxBytes = (int)($opts['max_bytes'] ?? self::DEFAULT_MAX_BYTES);
        if ((int)$file['size'] > $maxBytes) {
            return ['ok' => false, 'error' => 'File too large (max ' . round($maxBytes / 1024 / 1024) . ' MB).'];
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'Invalid upload.'];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';

        // Accept either 'allowed_mimes' (the documented name) or the legacy
        // 'allowed_mime' alias used in older call sites. Without both names,
        // callers using 'allowed_mime' silently bypass MIME validation.
        $allowedMimes = $opts['allowed_mimes'] ?? $opts['allowed_mime'] ?? null;
        if (is_array($allowedMimes) && !in_array($mime, $allowedMimes, true)) {
            return ['ok' => false, 'error' => __('core_err_file_type_not_allowed', 'File type not allowed.')];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = $opts['allowed_exts'] ?? null;
        if (is_array($allowedExts) && !in_array($ext, $allowedExts, true)) {
            return ['ok' => false, 'error' => __('core_err_file_extension_not_allowed', 'File extension not allowed.')];
        }

        // Sanitize folder
        $folder = trim($folder, '/');
        if (!preg_match('/^[a-zA-Z0-9_\-\/]+$/', $folder)) {
            return ['ok' => false, 'error' => __('core_err_invalid_folder_name', 'Invalid folder name.')];
        }

        $targetDir = self::publicUploadDir($folder);
        $newName   = bin2hex(random_bytes(12)) . ($ext ? '.' . $ext : '');
        $targetPath = $targetDir . '/' . $newName;

        if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['ok' => false, 'error' => __('core_err_could_not_save_uploaded_file', 'Could not save uploaded file.')];
        }
        @chmod($targetPath, 0644);

        return [
            'ok'          => true,
            'path'        => '/uploads/' . $folder . '/' . $newName,
            'filename'    => $newName,
            'original'    => $file['name'],
            'mime'        => $mime,
            'size'        => (int)$file['size'],
        ];
    }

    public static function publicUploadDir(string $folder): string
    {
        $dir = SLATE_ROOT . '/uploads/' . trim($folder, '/');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        // Drop a hardening .htaccess that disables PHP execution in this folder.
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess,
                "<IfModule mod_php.c>\n" .
                "    php_flag engine off\n" .
                "</IfModule>\n" .
                "<IfModule mod_php7.c>\n" .
                "    php_flag engine off\n" .
                "</IfModule>\n" .
                "AddHandler default-handler .php .phtml .php3 .php4 .php5 .php7 .phps\n"
            );
        }
        // An SVG opened directly is a document of this origin: serve it
        // sandboxed with no scripts, in case anything slipped past the
        // sanitizer. Added to folders made before this rule as well.
        $existing = (string) @file_get_contents($htaccess);
        if (!str_contains($existing, self::SVG_SANDBOX_MARKER)) {
            @file_put_contents($htaccess, rtrim($existing, "\n") . "\n" . self::svgSandboxRules(), LOCK_EX);
        }
        return $dir;
    }

    public static function remove(string $storedPath): bool
    {
        if ($storedPath === '') return false;
        $full = SLATE_ROOT . '/' . ltrim($storedPath, '/');
        // Refuse to delete anything outside /uploads/
        $real = realpath($full);
        if ($real === false) return false;
        $uploadRoot = realpath(SLATE_ROOT . '/uploads');
        if (!$uploadRoot || !str_starts_with($real, $uploadRoot . '/')) return false;
        return @unlink($real);
    }
}
