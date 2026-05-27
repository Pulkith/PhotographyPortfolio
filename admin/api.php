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
    $photo = [
        'id' => bin2hex(random_bytes(8)),
        'fileName' => $fileName,
        'url' => photo_url($host, $fileName),
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
        $cleanPhotos[] = [
            'id' => clean_text($photo['id'] ?? bin2hex(random_bytes(8))),
            'fileName' => $fileName,
            'url' => photo_url($host, $fileName),
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
    write_index($indexPath, $index);
    respond(read_index($indexPath));
}

fail('Unknown action.');
