<?php
/**
 * Shared helpers: name formatting, Philippine address, profile photo upload.
 */

const PROFILE_PHOTO_DIR_REL = 'assets/uploads/profiles/';   // relative to project root
const PROFILE_PHOTO_MAX_BYTES = 2 * 1024 * 1024;            // 2 MB
const NAME_EXTENSIONS = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];

function gl_root_path(): string
{
    return dirname(__DIR__);
}

/** "Juan M. Dela Cruz Jr." style full name. */
function formatFullName($first, $middle = '', $last = '', $extension = '', bool $middleInitialOnly = false): string
{
    $first = trim((string) $first);
    $middle = trim((string) $middle);
    $last = trim((string) $last);
    $extension = trim((string) $extension);

    if ($middleInitialOnly && $middle !== '') {
        $middle = mb_strtoupper(mb_substr($middle, 0, 1)) . '.';
    }

    return trim(implode(' ', array_filter([$first, $middle, $last, $extension], fn($p) => $p !== '')));
}

function cleanExtensionName($value): string
{
    $value = trim((string) $value);
    return in_array($value, NAME_EXTENSIONS, true) ? $value : '';
}

/** Combine the structured parts into one string kept in the legacy `address` column. */
function composeAddress($street, $subdivision, $barangay, $municipality, $province): string
{
    $parts = [];
    foreach ([$street, $subdivision] as $p) {
        $p = trim((string) $p);
        if ($p !== '') {
            $parts[] = $p;
        }
    }
    $barangay = trim((string) $barangay);
    if ($barangay !== '') {
        $parts[] = (stripos($barangay, 'brgy') === 0 || stripos($barangay, 'barangay') === 0) ? $barangay : 'Brgy. ' . $barangay;
    }
    foreach ([$municipality, $province] as $p) {
        $p = trim((string) $p);
        if ($p !== '') {
            $parts[] = $p;
        }
    }
    return implode(', ', $parts);
}

/** Check the chosen province / municipality / barangay really exist in the bundled data. */
function validatePhAddress(string $province, string $municipality, string $barangay): bool
{
    $base = gl_root_path() . '/assets/data/ph-address/';
    $provinces = json_decode((string) @file_get_contents($base . 'provinces.json'), true);
    if (!is_array($provinces)) {
        return true; // data missing: do not block the user
    }

    $code = null;
    foreach ($provinces as $p) {
        if ($p['n'] === $province) {
            $code = $p['c'];
            break;
        }
    }
    if ($code === null || !preg_match('/^[A-Za-z0-9]+$/', $code)) {
        return false;
    }

    $munis = json_decode((string) @file_get_contents($base . 'prov/' . $code . '.json'), true);
    if (!is_array($munis)) {
        return true;
    }
    foreach ($munis as $m) {
        if ($m['n'] === $municipality) {
            return in_array($barangay, $m['b'], true);
        }
    }
    return false;
}

/** Public URL (relative to the current page) of a stored profile photo, or null. */
function profilePhotoUrl($filename, string $prefix = '../'): ?string
{
    $filename = trim((string) $filename);
    if ($filename === '') {
        return null;
    }
    $filename = basename($filename);
    if (!is_file(gl_root_path() . '/' . PROFILE_PHOTO_DIR_REL . $filename)) {
        return null;
    }
    return $prefix . PROFILE_PHOTO_DIR_REL . rawurlencode($filename);
}

function deleteProfilePhotoFile($filename): void
{
    $filename = basename(trim((string) $filename));
    if ($filename === '') {
        return;
    }
    $path = gl_root_path() . '/' . PROFILE_PHOTO_DIR_REL . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Validate and store an uploaded profile picture.
 * Returns ['ok' => bool, 'filename' => ?string, 'error' => ?string, 'uploaded' => bool]
 * 'uploaded' is false when no file was chosen (not an error).
 */
function handleProfilePhotoUpload(array $file, string $prefix, int $userId): array
{
    $result = ['ok' => true, 'filename' => null, 'error' => null, 'uploaded' => false];

    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return $result;
    }

    $result['uploaded'] = true;

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'filename' => null, 'error' => 'The profile picture is too large. Maximum size is 2 MB.', 'uploaded' => true];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'filename' => null, 'error' => 'The profile picture could not be uploaded. Please try again.', 'uploaded' => true];
    }
    if ($file['size'] > PROFILE_PHOTO_MAX_BYTES) {
        return ['ok' => false, 'filename' => null, 'error' => 'The profile picture is too large. Maximum size is 2 MB.', 'uploaded' => true];
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = '';
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    }
    $info = @getimagesize($file['tmp_name']);
    if ($mime === '' && $info) {
        $mime = $info['mime'];
    }
    if (!$info || !isset($allowed[$mime])) {
        return ['ok' => false, 'filename' => null, 'error' => 'Profile picture must be a JPG, PNG, or WEBP image.', 'uploaded' => true];
    }

    $dir = gl_root_path() . '/' . PROFILE_PHOTO_DIR_REL;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'filename' => null, 'error' => 'Upload folder is not available on the server.', 'uploaded' => true];
    }

    $filename = $prefix . '_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        return ['ok' => false, 'filename' => null, 'error' => 'Could not save the profile picture. Please try again.', 'uploaded' => true];
    }

    return ['ok' => true, 'filename' => $filename, 'error' => null, 'uploaded' => true];
}
