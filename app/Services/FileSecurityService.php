<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class FileSecurityService
{
    // Dangerous file extensions that should never be allowed
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar',
        'exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'vbs', 'vbe', 'js', 'jse',
        'ws', 'wsf', 'wsc', 'wsh', 'ps1', 'ps1xml', 'ps2', 'ps2xml', 'psc1', 'psc2',
        'msc', 'msp', 'mst', 'cpl', 'dll', 'sys', 'drv',
        'sh', 'bash', 'zsh', 'csh', 'ksh',
        'htaccess', 'htpasswd',
        'asp', 'aspx', 'cer', 'csr', 'jsp', 'jspx',
        'swf', 'jar', 'war', 'ear',
    ];

    // MIME types that indicate executable content
    private const BLOCKED_MIME_TYPES = [
        'application/x-php',
        'application/x-httpd-php',
        'application/x-executable',
        'application/x-msdownload',
        'application/x-msdos-program',
        'application/x-sh',
        'application/x-shellscript',
        'text/x-php',
        'text/x-shellscript',
    ];

    // Maximum file size (10MB default)
    private const MAX_FILE_SIZE = 10485760;

    public function validateUpload(UploadedFile $file, array $allowedTypes = []): array
    {
        $errors = [];

        // Check file validity
        if (!$file->isValid()) {
            $errors[] = 'File upload failed: ' . $file->getErrorMessage();
            return $errors;
        }

        // Check extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (in_array($extension, self::BLOCKED_EXTENSIONS)) {
            $errors[] = "File type '.{$extension}' is not allowed.";
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        if (in_array($mimeType, self::BLOCKED_MIME_TYPES)) {
            $errors[] = "File type '{$mimeType}' is not allowed.";
        }

        // Check for double extensions (e.g., file.php.jpg)
        $filename = $file->getClientOriginalName();
        if ($this->hasDoubleExtension($filename)) {
            $errors[] = 'Files with multiple extensions are not allowed.';
        }

        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $maxMB = self::MAX_FILE_SIZE / 1048576;
            $errors[] = "File size exceeds maximum of {$maxMB}MB.";
        }

        // Validate against allowed types if specified
        if (!empty($allowedTypes) && !$this->matchesAllowedTypes($file, $allowedTypes)) {
            $errors[] = 'File type is not in the allowed list.';
        }

        // Check for null bytes in filename (path traversal attempt)
        if (str_contains($filename, "\0")) {
            $errors[] = 'Invalid filename.';
        }

        return $errors;
    }

    public function sanitizeFilename(string $filename): string
    {
        // Remove path components
        $filename = basename($filename);

        // Remove null bytes
        $filename = str_replace("\0", '', $filename);

        // Remove directory traversal attempts
        $filename = str_replace(['../', '..\\', '..'], '', $filename);

        // Remove potentially dangerous characters
        $filename = preg_replace('/[^\w\-\.\s]/', '_', $filename);

        // Limit length
        if (strlen($filename) > 200) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $filename = substr($name, 0, 200 - strlen($ext) - 1) . '.' . $ext;
        }

        return $filename;
    }

    private function hasDoubleExtension(string $filename): bool
    {
        $parts = explode('.', $filename);
        if (count($parts) <= 2) {
            return false;
        }

        // Check if any middle part is a dangerous extension
        array_pop($parts); // Remove last extension
        array_shift($parts); // Remove filename

        foreach ($parts as $part) {
            if (in_array(strtolower($part), self::BLOCKED_EXTENSIONS)) {
                return true;
            }
        }

        return false;
    }

    private function matchesAllowedTypes(UploadedFile $file, array $allowedTypes): bool
    {
        $extension = '.' . strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();

        foreach ($allowedTypes as $allowed) {
            // Extension match
            if (str_starts_with($allowed, '.') && strtolower($allowed) === $extension) {
                return true;
            }

            // Exact MIME match
            if ($allowed === $mimeType) {
                return true;
            }

            // Wildcard MIME match (e.g., image/*)
            if (str_ends_with($allowed, '/*')) {
                $baseType = str_replace('/*', '', $allowed);
                if (str_starts_with($mimeType, $baseType . '/')) {
                    return true;
                }
            }
        }

        return false;
    }
}
