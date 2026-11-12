<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

function projectUploadRoot(): string
{
    return dirname(__DIR__) . '/uploads/projects';
}

function projectHomeImageUploadRoot(): string
{
    return dirname(__DIR__) . '/uploads/projects/home';
}

function mediaCenterUploadRoot(): string
{
    return dirname(__DIR__) . '/uploads/media-center';
}

function facilityProjectUploadRoot(): string
{
    return dirname(__DIR__) . '/uploads/facility-projects';
}

function ensureUploadDirectory(string $root, string $relativeDirectory): string
{
    $target = rtrim($root, '/\\') . '/' . trim($relativeDirectory, '/');
    if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
        throw new RuntimeException('Unable to create upload storage directory.');
    }
    return $target;
}

function normalizeUploadedFiles(string $field = 'files', int $maxFiles = 10): array
{
    if (!isset($_FILES[$field])) {
        jsonResponse(['status' => 'Failed', 'message' => 'No files were uploaded.'], 422);
    }

    $source = $_FILES[$field];
    $files = [];

    if (is_array($source['name'] ?? null)) {
        foreach ($source['name'] as $index => $name) {
            $files[] = [
                'name' => (string) $name,
                'type' => (string) ($source['type'][$index] ?? ''),
                'tmp_name' => (string) ($source['tmp_name'][$index] ?? ''),
                'error' => (int) ($source['error'][$index] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($source['size'][$index] ?? 0),
            ];
        }
    } else {
        $files[] = [
            'name' => (string) ($source['name'] ?? ''),
            'type' => (string) ($source['type'] ?? ''),
            'tmp_name' => (string) ($source['tmp_name'] ?? ''),
            'error' => (int) ($source['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($source['size'] ?? 0),
        ];
    }

    $files = array_values(array_filter(
        $files,
        static fn (array $file): bool => (int) $file['error'] !== UPLOAD_ERR_NO_FILE
    ));

    if ($files === []) {
        jsonResponse(['status' => 'Failed', 'message' => 'No files were uploaded.'], 422);
    }

    $maxFiles = max(1, min(20, $maxFiles));
    if (count($files) > $maxFiles) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'You can upload a maximum of ' . $maxFiles . ' files at once.',
        ], 422);
    }

    return $files;
}

function uploadedFileMime(array $file): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            default => 'The uploaded file could not be processed.',
        };
        jsonResponse(['status' => 'Failed', 'message' => $message], 422);
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Invalid uploaded file.'], 422);
    }

    if ((int) ($file['size'] ?? 0) <= 0) {
        jsonResponse(['status' => 'Failed', 'message' => 'The uploaded file is empty.'], 422);
    }

    if (!class_exists('finfo')) {
        jsonResponse(['status' => 'Failed', 'message' => 'Server file validation support is unavailable.'], 500);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    return strtolower((string) $finfo->file($tmp));
}

function projectImageMaxFilesPerRequest(): int
{
    return max(1, min(20, (int) envValue('PROJECT_IMAGE_MAX_FILES_PER_REQUEST', 10)));
}

function projectImageMaxTotal(): int
{
    return max(1, min(100, (int) envValue('PROJECT_IMAGE_MAX_TOTAL', 30)));
}

function projectImageSpecification(array $file): array
{
    $mime = uploadedFileMime($file);
    $types = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($types[$mime])) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Project images must be JPG, PNG or WEBP.',
        ], 422);
    }

    $size = (int) ($file['size'] ?? 0);
    $maxBytes = max(1048576, (int) envValue('PROJECT_IMAGE_MAX_BYTES', 8388608));
    if ($size > $maxBytes) {
        jsonResponse(['status' => 'Failed', 'message' => 'A project image exceeds the allowed upload size.'], 413);
    }

    $tmp = (string) $file['tmp_name'];
    if (@getimagesize($tmp) === false) {
        jsonResponse(['status' => 'Failed', 'message' => 'An uploaded project image is invalid.'], 422);
    }

    return ['mime' => $mime, 'extension' => $types[$mime]];
}

function storeProjectImage(array $file): array
{
    $spec = projectImageSpecification($file);
    $directory = date('Y/m');
    $targetDirectory = ensureUploadDirectory(projectUploadRoot(), $directory);
    $randomName = bin2hex(random_bytes(24)) . '.' . $spec['extension'];
    $absolutePath = $targetDirectory . '/' . $randomName;

    if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Unable to store uploaded project image.');
    }
    @chmod($absolutePath, 0644);

    return [
        'absolute_path' => $absolutePath,
        'file_path' => 'uploads/projects/' . $directory . '/' . $randomName,
        'mime' => $spec['mime'],
        'original_name' => substr(basename((string) ($file['name'] ?? 'project-image')), 0, 255),
        'size' => (int) ($file['size'] ?? 0),
    ];
}

function storeProjectHomeImage(array $file): array
{
    $spec = projectImageSpecification($file);
    $directory = date('Y/m');
    $targetDirectory = ensureUploadDirectory(projectHomeImageUploadRoot(), $directory);
    $randomName = bin2hex(random_bytes(24)) . '.' . $spec['extension'];
    $absolutePath = $targetDirectory . '/' . $randomName;

    if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Unable to store homepage project image.');
    }
    @chmod($absolutePath, 0644);

    return [
        'absolute_path' => $absolutePath,
        'file_path' => 'uploads/projects/home/' . $directory . '/' . $randomName,
        'mime' => $spec['mime'],
        'original_name' => substr(basename((string) ($file['name'] ?? 'homepage-project-image')), 0, 255),
        'size' => (int) ($file['size'] ?? 0),
    ];
}


function facilityProjectImageSpecification(array $file): array
{
    $mime = uploadedFileMime($file);
    $types = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($types[$mime])) {
        jsonResponse(['status' => 'Failed', 'message' => 'Facility project images must be JPG, PNG or WEBP.'], 422);
    }

    $size = (int) ($file['size'] ?? 0);
    $maxBytes = max(1048576, (int) envValue('FACILITY_PROJECT_IMAGE_MAX_BYTES', 8388608));
    if ($size > $maxBytes) {
        jsonResponse(['status' => 'Failed', 'message' => 'The Facility Management project image exceeds the allowed upload size.'], 413);
    }

    if (@getimagesize((string) ($file['tmp_name'] ?? '')) === false) {
        jsonResponse(['status' => 'Failed', 'message' => 'The uploaded Facility Management project image is invalid.'], 422);
    }

    return ['mime' => $mime, 'extension' => $types[$mime]];
}

function storeFacilityProjectImage(array $file): array
{
    $spec = facilityProjectImageSpecification($file);
    $directory = date('Y/m');
    $targetDirectory = ensureUploadDirectory(facilityProjectUploadRoot(), $directory);
    $randomName = bin2hex(random_bytes(24)) . '.' . $spec['extension'];
    $absolutePath = $targetDirectory . '/' . $randomName;

    if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Unable to store uploaded Facility Management project image.');
    }
    @chmod($absolutePath, 0644);

    return [
        'absolute_path' => $absolutePath,
        'file_path' => 'uploads/facility-projects/' . $directory . '/' . $randomName,
        'mime' => $spec['mime'],
        'original_name' => substr(basename((string) ($file['name'] ?? 'facility-project-image')), 0, 255),
        'size' => (int) ($file['size'] ?? 0),
    ];
}

function mediaCenterCoverImageSpecification(array $file): array
{
    $mime = uploadedFileMime($file);
    $types = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($types[$mime])) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Media Center cover images must be JPG, PNG or WEBP.',
        ], 422);
    }

    $size = (int) ($file['size'] ?? 0);
    $maxBytes = max(1048576, (int) envValue('MEDIA_COVER_IMAGE_MAX_BYTES', 8388608));
    if ($size > $maxBytes) {
        jsonResponse(['status' => 'Failed', 'message' => 'The Media Center cover image exceeds the allowed upload size.'], 413);
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $dimensions = @getimagesize($tmp);
    if ($dimensions === false) {
        jsonResponse(['status' => 'Failed', 'message' => 'The uploaded Media Center cover image is invalid.'], 422);
    }

    $width = (int) ($dimensions[0] ?? 0);
    $height = (int) ($dimensions[1] ?? 0);
    if ($width <= 0 || $height <= 0 || $width !== $height) {
        jsonResponse([
            'status' => 'Failed',
            'message' => sprintf('Media Center cover images must be perfectly square (1:1). The uploaded image is %d × %d px.', $width, $height),
        ], 422);
    }

    return [
        'mime' => $mime,
        'extension' => $types[$mime],
        'width' => $width,
        'height' => $height,
    ];
}

function storeMediaCenterCoverImage(array $file): array
{
    $spec = mediaCenterCoverImageSpecification($file);
    $directory = 'covers/' . date('Y/m');
    $targetDirectory = ensureUploadDirectory(mediaCenterUploadRoot(), $directory);
    $randomName = bin2hex(random_bytes(24)) . '.' . $spec['extension'];
    $absolutePath = $targetDirectory . '/' . $randomName;

    if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Unable to store uploaded Media Center cover image.');
    }
    @chmod($absolutePath, 0644);

    return [
        'absolute_path' => $absolutePath,
        'file_path' => 'uploads/media-center/' . $directory . '/' . $randomName,
        'mime' => $spec['mime'],
        'original_name' => substr(basename((string) ($file['name'] ?? 'media-center-cover')), 0, 255),
        'size' => (int) ($file['size'] ?? 0),
        'width' => $spec['width'],
        'height' => $spec['height'],
    ];
}

function mediaCenterDocumentSpecification(array $file): array
{
    $mime = uploadedFileMime($file);
    $types = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/csv' => 'csv',
        'text/plain' => 'txt',
    ];

    if (!isset($types[$mime])) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Unsupported Media Center document. Use PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, CSV or TXT.',
        ], 422);
    }

    $size = (int) ($file['size'] ?? 0);
    $maxBytes = max(1048576, (int) envValue('MEDIA_DOCUMENT_MAX_BYTES', 26214400));
    if ($size > $maxBytes) {
        jsonResponse(['status' => 'Failed', 'message' => 'The Media Center document exceeds the allowed upload size.'], 413);
    }

    return ['mime' => $mime, 'extension' => $types[$mime]];
}

function storeMediaCenterDocument(array $file): array
{
    $spec = mediaCenterDocumentSpecification($file);
    $directory = date('Y/m');
    $targetDirectory = ensureUploadDirectory(mediaCenterUploadRoot(), $directory);
    $randomName = bin2hex(random_bytes(24)) . '.' . $spec['extension'];
    $absolutePath = $targetDirectory . '/' . $randomName;

    if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Unable to store uploaded Media Center document.');
    }
    @chmod($absolutePath, 0644);

    return [
        'absolute_path' => $absolutePath,
        'file_path' => 'uploads/media-center/' . $directory . '/' . $randomName,
        'mime' => $spec['mime'],
        'extension' => $spec['extension'],
        'original_name' => substr(basename((string) ($file['name'] ?? 'document')), 0, 255),
        'size' => (int) ($file['size'] ?? 0),
    ];
}

function safelyDeleteManagedUpload(?string $filePath, string $expectedPrefix, string $root): void
{
    $filePath = trim((string) $filePath);
    $normalizedPath = str_replace('\\', '/', $filePath);
    if ($filePath === '' || !str_starts_with($normalizedPath, trim($expectedPrefix, '/') . '/')) {
        return;
    }

    $resolvedRoot = realpath($root);
    $target = realpath(dirname(__DIR__) . '/' . ltrim($normalizedPath, '/'));
    if ($resolvedRoot === false || $target === false) {
        return;
    }

    $rootPrefix = rtrim(str_replace('\\', '/', $resolvedRoot), '/') . '/';
    $targetNormalized = str_replace('\\', '/', $target);
    if (!str_starts_with($targetNormalized, $rootPrefix)) {
        error_log('Lambert refused to delete a file outside the managed upload directory: ' . $targetNormalized);
        return;
    }

    if (is_file($targetNormalized) && !@unlink($targetNormalized)) {
        error_log('Lambert could not delete managed upload: ' . $targetNormalized);
    }
}

function safelyDeleteProjectImage(?string $filePath): void
{
    safelyDeleteManagedUpload($filePath, 'uploads/projects', projectUploadRoot());
}

function safelyDeleteProjectHomeImage(mysqli $conn, ?string $filePath): void
{
    $filePath = trim((string) $filePath);
    if ($filePath === '') {
        return;
    }

    // Homepage images can temporarily be shared by multiple projects during
    // migration/testing. Only remove the physical file after the last database
    // reference has gone, so replacing one project's image cannot break others.
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM project_home_images WHERE file_path = ?');
    $stmt->bind_param('s', $filePath);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int) ($row['total'] ?? 0) > 0) {
        return;
    }

    safelyDeleteManagedUpload($filePath, 'uploads/projects/home', projectHomeImageUploadRoot());
}

function safelyDeleteFacilityProjectImage(?string $filePath): void
{
    safelyDeleteManagedUpload($filePath, 'uploads/facility-projects', facilityProjectUploadRoot());
}

function safelyDeleteMediaCenterDocument(?string $filePath): void
{
    safelyDeleteManagedUpload($filePath, 'uploads/media-center', mediaCenterUploadRoot());
}

function safelyDeleteMediaCenterCoverImage(?string $filePath): void
{
    safelyDeleteManagedUpload($filePath, 'uploads/media-center', mediaCenterUploadRoot());
}

function cvUploadRoot(): string
{
    return dirname(__DIR__) . '/uploads/cv';
}

function cvUploadMaxBytes(): int
{
    return min(10485760, max(1048576, (int) envValue('CV_MAX_BYTES', 10485760)));
}

function uploadedFileExtension(array $file): string
{
    $name = strtolower(trim((string) ($file['name'] ?? '')));
    $extension = pathinfo($name, PATHINFO_EXTENSION);
    return is_string($extension) ? strtolower($extension) : '';
}

function fileStartsWith(string $path, string $signature): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $bytes = fread($handle, strlen($signature));
    fclose($handle);
    return $bytes === $signature;
}

function validDocxArchive(string $path): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }

    $valid = $zip->locateName('[Content_Types].xml') !== false
        && $zip->locateName('word/document.xml') !== false;
    $zip->close();
    return $valid;
}

function cvUploadSpecification(array $file): array
{
    $mime = uploadedFileMime($file);
    $extension = uploadedFileExtension($file);
    $allowedExtensions = ['pdf', 'doc', 'docx'];

    if (!in_array($extension, $allowedExtensions, true)) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Upload a PDF, DOC or DOCX file.',
            'field' => 'cv',
        ], 422);
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size > cvUploadMaxBytes()) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Your CV must not exceed 10 MB.',
            'field' => 'cv',
        ], 413);
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $valid = false;
    $normalizedMime = $mime;

    if ($extension === 'pdf') {
        $valid = $mime === 'application/pdf' && fileStartsWith($tmp, '%PDF-');
        $normalizedMime = 'application/pdf';
    } elseif ($extension === 'doc') {
        $oleSignature = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        $validMime = in_array($mime, [
            'application/msword',
            'application/x-ole-storage',
            'application/vnd.ms-office',
            'application/octet-stream',
        ], true);
        $valid = $validMime && fileStartsWith($tmp, $oleSignature);
        $normalizedMime = 'application/msword';
    } elseif ($extension === 'docx') {
        $validMime = in_array($mime, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ], true);
        $valid = $validMime && validDocxArchive($tmp);
        $normalizedMime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    if (!$valid) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'The uploaded CV is not a valid PDF, DOC or DOCX file.',
            'field' => 'cv',
        ], 422);
    }

    return ['mime' => $normalizedMime, 'extension' => $extension];
}

function storeCvUpload(array $file): array
{
    $spec = cvUploadSpecification($file);
    $directory = date('Y/m');
    $targetDirectory = ensureUploadDirectory(cvUploadRoot(), $directory);
    $randomName = bin2hex(random_bytes(24)) . '.' . $spec['extension'];
    $absolutePath = $targetDirectory . '/' . $randomName;

    if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Unable to store uploaded CV.');
    }
    @chmod($absolutePath, 0640);

    $originalName = basename((string) ($file['name'] ?? 'cv.' . $spec['extension']));
    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?: ('cv.' . $spec['extension']);
    $originalName = mb_substr($originalName, 0, 255);

    return [
        'absolute_path' => $absolutePath,
        'file_path' => 'uploads/cv/' . $directory . '/' . $randomName,
        'mime' => $spec['mime'],
        'extension' => $spec['extension'],
        'original_name' => $originalName,
        'size' => (int) ($file['size'] ?? 0),
        'sha256' => hash_file('sha256', $absolutePath) ?: '',
    ];
}

function safelyDeleteCvUpload(?string $filePath): void
{
    safelyDeleteManagedUpload($filePath, 'uploads/cv', cvUploadRoot());
}

function resolveCvUploadPath(string $filePath): ?string
{
    $normalizedPath = str_replace('\\', '/', trim($filePath));
    if ($normalizedPath === '' || !str_starts_with($normalizedPath, 'uploads/cv/')) {
        return null;
    }

    $root = realpath(cvUploadRoot());
    $target = realpath(dirname(__DIR__) . '/' . ltrim($normalizedPath, '/'));
    if ($root === false || $target === false || !is_file($target)) {
        return null;
    }

    $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $targetNormalized = str_replace('\\', '/', $target);
    if (!str_starts_with($targetNormalized, $rootPrefix)) {
        return null;
    }

    return $target;
}
