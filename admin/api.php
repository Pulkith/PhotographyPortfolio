<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$host = 'https://photography.pulkith.com';
$photosDir = realpath(__DIR__ . '/../photos');
if ($photosDir === false) {
    $photosDir = __DIR__ . '/../photos';
    mkdir($photosDir, 0775, true);
}
$displayDir = $photosDir . '/display';
$thumbDir = $photosDir . '/thumbs';
if (!is_dir($displayDir)) {
    mkdir($displayDir, 0775, true);
}
if (!is_dir($thumbDir)) {
    mkdir($thumbDir, 0775, true);
}
$indexPath = $photosDir . '/index.json';

function is_list_array($value) {
    if (!is_array($value)) {
        return false;
    }
    return array_keys($value) === range(0, count($value) - 1);
}

function read_index($indexPath) {
    if (!is_file($indexPath)) {
        return ['updatedAt' => null, 'photos' => []];
    }
    $json = json_decode((string) file_get_contents($indexPath), true);
    if (!is_array($json)) {
        return ['updatedAt' => null, 'photos' => []];
    }
    if (is_list_array($json)) {
        return ['updatedAt' => null, 'photos' => $json];
    }
    $json['photos'] = isset($json['photos']) && is_array($json['photos']) ? $json['photos'] : [];
    return $json;
}

function write_index($indexPath, $data) {
    $data['updatedAt'] = gmdate('c');
    $data['photos'] = isset($data['photos']) && is_array($data['photos']) ? array_values($data['photos']) : [];
    file_put_contents($indexPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function respond($data) {
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function fail($message, $status = 400) {
    http_response_code($status);
    respond(['error' => $message]);
}

function number_between($value, $min, $max, $fallback) {
    if (!is_numeric($value)) {
        return $fallback;
    }
    return max($min, min($max, (int) $value));
}

function clean_text($value) {
    return trim(strip_tags((string) $value));
}

function photo_url($host, $fileName) {
    return $host . '/photos/' . rawurlencode($fileName);
}

function derivative_url($host, $folder, $fileName) {
    return $host . '/photos/' . $folder . '/' . rawurlencode($fileName);
}

function derivative_name($fileName, $suffix) {
    $base = pathinfo($fileName, PATHINFO_FILENAME);
    return $base . '-' . $suffix . '.jpg';
}

function create_image_resource($path, $mime) {
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    if ($mime === 'image/jpeg') {
        return imagecreatefromjpeg($path);
    }
    if ($mime === 'image/png') {
        return imagecreatefrompng($path);
    }
    if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        return imagecreatefromwebp($path);
    }
    if ($mime === 'image/gif') {
        return imagecreatefromgif($path);
    }
    return null;
}

function save_resized_jpeg($sourcePath, $targetPath, $maxWidth, $quality) {
    $info = getimagesize($sourcePath);
    if ($info === false) {
        return false;
    }
    $sourceWidth = (int) ($info[0] ?? 0);
    $sourceHeight = (int) ($info[1] ?? 0);
    $mime = (string) ($info['mime'] ?? '');
    if ($sourceWidth < 1 || $sourceHeight < 1) {
        return false;
    }

    $source = create_image_resource($sourcePath, $mime);
    if (!$source) {
        return false;
    }

    $targetWidth = min($maxWidth, $sourceWidth);
    $targetHeight = (int) round($sourceHeight * ($targetWidth / $sourceWidth));
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    $saved = imagejpeg($target, $targetPath, $quality);
    imagedestroy($source);
    imagedestroy($target);
    return $saved;
}

function ensure_derivatives($host, $photosDir, $displayDir, $thumbDir, $fileName) {
    $sourcePath = $photosDir . '/' . basename($fileName);
    $displayName = derivative_name($fileName, 'display-2400');
    $previewName = derivative_name($fileName, 'preview-1400');
    $thumbName = derivative_name($fileName, 'thumb-640');
    $displayPath = $displayDir . '/' . $displayName;
    $previewPath = $displayDir . '/' . $previewName;
    $thumbPath = $thumbDir . '/' . $thumbName;
    $createdDisplay = is_file($displayPath) || save_resized_jpeg($sourcePath, $displayPath, 2400, 88);
    $createdPreview = is_file($previewPath) || save_resized_jpeg($sourcePath, $previewPath, 1400, 86);
    $createdThumb = is_file($thumbPath) || save_resized_jpeg($sourcePath, $thumbPath, 640, 82);

    return [
        'displayFileName' => $createdDisplay ? $displayName : null,
        'displayUrl' => $createdDisplay ? derivative_url($host, 'display', $displayName) : photo_url($host, $fileName),
        'previewFileName' => $createdPreview ? $previewName : null,
        'previewUrl' => $createdPreview ? derivative_url($host, 'display', $previewName) : photo_url($host, $fileName),
        'thumbFileName' => $createdThumb ? $thumbName : null,
        'thumbUrl' => $createdThumb ? derivative_url($host, 'thumbs', $thumbName) : photo_url($host, $fileName)
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(read_index($indexPath));
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = strpos($contentType, 'application/json') !== false
    ? (json_decode((string) file_get_contents('php://input'), true) ?: [])
    : $_POST;

$action = (string) ($input['action'] ?? $_GET['action'] ?? '');
$index = read_index($indexPath);

if ($action === 'upload') {
    if (!isset($_FILES['photo']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
        fail('No uploaded photo found.');
    }

    $info = getimagesize($_FILES['photo']['tmp_name']);
    if ($info === false) {
        fail('Uploaded file is not a supported image.');
    }

    $original = pathinfo((string) $_FILES['photo']['name'], PATHINFO_FILENAME);
    $extension = strtolower(pathinfo((string) $_FILES['photo']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'heic', 'heif', 'tif', 'tiff'];
    if (!in_array($extension, $allowed, true)) {
        fail('Unsupported image extension.');
    }

    $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($original)) ?: 'photo';
    $fileName = $slug . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
    $destination = $photosDir . '/' . $fileName;

    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $destination)) {
        fail('Could not store uploaded photo.', 500);
    }

    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    $derivatives = ensure_derivatives($host, $photosDir, $displayDir, $thumbDir, $fileName);
    $photo = [
        'id' => bin2hex(random_bytes(8)),
        'fileName' => $fileName,
        'url' => photo_url($host, $fileName),
        'displayFileName' => $derivatives['displayFileName'],
        'displayUrl' => $derivatives['displayUrl'],
        'previewFileName' => $derivatives['previewFileName'],
        'previewUrl' => $derivatives['previewUrl'],
        'thumbFileName' => $derivatives['thumbFileName'],
        'thumbUrl' => $derivatives['thumbUrl'],
        'location' => clean_text($input['location'] ?? ''),
        'date' => clean_text($input['date'] ?? ''),
        'priority' => number_between($input['priority'] ?? 5, 1, 10, 5),
        'locationIndex' => number_between($input['locationIndex'] ?? 50, 1, 100, 50),
        'caption' => clean_text($input['caption'] ?? ''),
        'aspectRatio' => $height > 0 ? round($width / $height, 4) : null,
        'uploadedAt' => gmdate('c')
    ];

    $index['photos'][] = $photo;
    usort($index['photos'], function ($a, $b) {
        return ((int) ($a['locationIndex'] ?? 50)) <=> ((int) ($b['locationIndex'] ?? 50));
    });
    write_index($indexPath, $index);
    respond(read_index($indexPath));
}

if ($action === 'save') {
    $data = isset($input['data']) && is_array($input['data']) ? $input['data'] : $input;
    $cleanPhotos = [];
    foreach (($data['photos'] ?? []) as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $fileName = clean_text($photo['fileName'] ?? basename((string) ($photo['url'] ?? '')));
        $displayFileName = clean_text($photo['displayFileName'] ?? basename((string) ($photo['displayUrl'] ?? '')));
        $previewFileName = clean_text($photo['previewFileName'] ?? basename((string) ($photo['previewUrl'] ?? '')));
        $thumbFileName = clean_text($photo['thumbFileName'] ?? basename((string) ($photo['thumbUrl'] ?? '')));
        $displayUrl = $displayFileName !== '' ? derivative_url($host, 'display', $displayFileName) : photo_url($host, $fileName);
        $previewUrl = $previewFileName !== '' ? derivative_url($host, 'display', $previewFileName) : $displayUrl;
        $thumbUrl = $thumbFileName !== '' ? derivative_url($host, 'thumbs', $thumbFileName) : photo_url($host, $fileName);
        $cleanPhotos[] = [
            'id' => clean_text($photo['id'] ?? bin2hex(random_bytes(8))),
            'fileName' => $fileName,
            'url' => photo_url($host, $fileName),
            'displayFileName' => $displayFileName !== '' ? $displayFileName : null,
            'displayUrl' => $displayUrl,
            'previewFileName' => $previewFileName !== '' ? $previewFileName : null,
            'previewUrl' => $previewUrl,
            'thumbFileName' => $thumbFileName !== '' ? $thumbFileName : null,
            'thumbUrl' => $thumbUrl,
            'location' => clean_text($photo['location'] ?? ''),
            'date' => clean_text($photo['date'] ?? ''),
            'priority' => number_between($photo['priority'] ?? 5, 1, 10, 5),
            'locationIndex' => number_between($photo['locationIndex'] ?? 50, 1, 100, 50),
            'caption' => clean_text($photo['caption'] ?? ''),
            'aspectRatio' => is_numeric($photo['aspectRatio'] ?? null) ? (float) $photo['aspectRatio'] : null,
            'uploadedAt' => clean_text($photo['uploadedAt'] ?? gmdate('c'))
        ];
    }
    usort($cleanPhotos, function ($a, $b) {
        return ((int) $a['locationIndex']) <=> ((int) $b['locationIndex']);
    });
    write_index($indexPath, ['photos' => $cleanPhotos]);
    respond(read_index($indexPath));
}

if ($action === 'delete') {
    $id = clean_text($input['id'] ?? '');
    $fileName = basename(clean_text($input['fileName'] ?? ''));
    $index['photos'] = array_values(array_filter($index['photos'], function ($photo) use ($id) {
        return ($photo['id'] ?? '') !== $id;
    }));
    if ($fileName !== '' && is_file($photosDir . '/' . $fileName)) {
        unlink($photosDir . '/' . $fileName);
    }
    $derivativeFiles = [
        $photosDir . '/display/' . derivative_name($fileName, 'display-2400'),
        $photosDir . '/display/' . derivative_name($fileName, 'preview-1400'),
        $photosDir . '/thumbs/' . derivative_name($fileName, 'thumb-640')
    ];
    foreach ($derivativeFiles as $path) {
        if ($fileName !== '' && is_file($path)) {
            unlink($path);
        }
    }
    write_index($indexPath, $index);
    respond(read_index($indexPath));
}

if ($action === 'optimize') {
    $changed = 0;
    foreach ($index['photos'] as &$photo) {
        if (!is_array($photo)) {
            continue;
        }
        $fileName = clean_text($photo['fileName'] ?? basename((string) ($photo['url'] ?? '')));
        if ($fileName === '' || !is_file($photosDir . '/' . $fileName)) {
            continue;
        }
        $derivatives = ensure_derivatives($host, $photosDir, $displayDir, $thumbDir, $fileName);
        $photo['displayFileName'] = $derivatives['displayFileName'];
        $photo['displayUrl'] = $derivatives['displayUrl'];
        $photo['previewFileName'] = $derivatives['previewFileName'];
        $photo['previewUrl'] = $derivatives['previewUrl'];
        $photo['thumbFileName'] = $derivatives['thumbFileName'];
        $photo['thumbUrl'] = $derivatives['thumbUrl'];
        $changed += 1;
    }
    unset($photo);
    write_index($indexPath, $index);
    $result = read_index($indexPath);
    $result['optimized'] = $changed;
    respond($result);
}

fail('Unknown action.');
