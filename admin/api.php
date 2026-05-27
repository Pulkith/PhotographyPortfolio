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

function rational_to_float($value) {
    if (is_array($value)) {
        $value = reset($value);
    }
    if (is_string($value) && strpos($value, '/') !== false) {
        [$numerator, $denominator] = array_map('floatval', explode('/', $value, 2));
        return $denominator == 0.0 ? 0.0 : $numerator / $denominator;
    }
    return (float) $value;
}

function gps_to_decimal($coordinate, $hemisphere) {
    if (!is_array($coordinate) || count($coordinate) < 3) {
        return null;
    }
    $degrees = rational_to_float($coordinate[0]);
    $minutes = rational_to_float($coordinate[1]);
    $seconds = rational_to_float($coordinate[2]);
    $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
    if ($hemisphere === 'S' || $hemisphere === 'W') {
        $decimal *= -1;
    }
    return round($decimal, 6);
}

function exif_taken_date($exif) {
    $raw = $exif['DateTimeOriginal'] ?? $exif['DateTimeDigitized'] ?? $exif['DateTime'] ?? '';
    if (!is_string($raw) || trim($raw) === '') {
        return '';
    }
    $date = DateTime::createFromFormat('Y:m:d H:i:s', trim($raw));
    return $date ? $date->format('Y-m-d') : '';
}

function exif_gps($exif) {
    if (!isset($exif['GPSLatitude'], $exif['GPSLatitudeRef'], $exif['GPSLongitude'], $exif['GPSLongitudeRef'])) {
        return null;
    }
    $latitude = gps_to_decimal($exif['GPSLatitude'], (string) $exif['GPSLatitudeRef']);
    $longitude = gps_to_decimal($exif['GPSLongitude'], (string) $exif['GPSLongitudeRef']);
    if ($latitude === null || $longitude === null) {
        return null;
    }
    return ['latitude' => $latitude, 'longitude' => $longitude];
}

function reverse_geocode_location($latitude, $longitude) {
    $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=10&addressdetails=1&lat='
        . rawurlencode((string) $latitude) . '&lon=' . rawurlencode((string) $longitude);
    $context = stream_context_create([
        'http' => [
            'timeout' => 4,
            'header' => "User-Agent: PulkithPhotographyPortfolio/1.0 (paruchuri@pulkith.com)\r\n"
        ]
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return '';
    }
    $json = json_decode($response, true);
    $address = is_array($json) && isset($json['address']) && is_array($json['address']) ? $json['address'] : [];
    $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['municipality'] ?? $address['county'] ?? '';
    $region = $address['state'] ?? $address['region'] ?? '';
    $country = $address['country_code'] ?? $address['country'] ?? '';
    $parts = array_filter([$city, $region, is_string($country) ? strtoupper($country) : '']);
    return implode(', ', array_unique($parts));
}

function extract_photo_metadata($path) {
    $metadata = [
        'date' => '',
        'location' => '',
        'latitude' => null,
        'longitude' => null
    ];

    if (!function_exists('exif_read_data')) {
        return $metadata;
    }

    $exif = @exif_read_data($path, null, true, false);
    if (!is_array($exif)) {
        return $metadata;
    }
    $flat = [];
    foreach ($exif as $section) {
        if (is_array($section)) {
            $flat = array_merge($flat, $section);
        }
    }

    $metadata['date'] = exif_taken_date($flat);
    $gps = exif_gps($flat);
    if ($gps) {
        $metadata['latitude'] = $gps['latitude'];
        $metadata['longitude'] = $gps['longitude'];
        $metadata['location'] = reverse_geocode_location($gps['latitude'], $gps['longitude']);
    }
    return $metadata;
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
    $metadata = extract_photo_metadata($destination);
    $inputLocation = clean_text($input['location'] ?? '');
    $inputDate = clean_text($input['date'] ?? '');
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
        'location' => $inputLocation !== '' ? $inputLocation : $metadata['location'],
        'date' => $inputDate !== '' ? $inputDate : $metadata['date'],
        'priority' => number_between($input['priority'] ?? 5, 1, 10, 5),
        'locationIndex' => number_between($input['locationIndex'] ?? 50, 1, 100, 50),
        'caption' => clean_text($input['caption'] ?? ''),
        'latitude' => $metadata['latitude'],
        'longitude' => $metadata['longitude'],
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
    $landingSelected = false;
    foreach (($data['photos'] ?? []) as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $fileName = clean_text($photo['fileName'] ?? basename((string) ($photo['url'] ?? '')));
        $derivatives = ['displayFileName' => null, 'displayUrl' => photo_url($host, $fileName), 'previewFileName' => null, 'previewUrl' => photo_url($host, $fileName), 'thumbFileName' => null, 'thumbUrl' => photo_url($host, $fileName)];
        if ($fileName !== '' && is_file($photosDir . '/' . $fileName)) {
            $derivatives = ensure_derivatives($host, $photosDir, $displayDir, $thumbDir, $fileName);
        }
        $isLanding = !$landingSelected && (($photo['isLanding'] ?? false) === true);
        if ($isLanding) {
            $landingSelected = true;
        }
        $cleanPhotos[] = [
            'id' => clean_text($photo['id'] ?? bin2hex(random_bytes(8))),
            'fileName' => $fileName,
            'url' => photo_url($host, $fileName),
            'displayFileName' => $derivatives['displayFileName'],
            'displayUrl' => $derivatives['displayUrl'],
            'previewFileName' => $derivatives['previewFileName'],
            'previewUrl' => $derivatives['previewUrl'],
            'thumbFileName' => $derivatives['thumbFileName'],
            'thumbUrl' => $derivatives['thumbUrl'],
            'location' => clean_text($photo['location'] ?? ''),
            'date' => clean_text($photo['date'] ?? ''),
            'priority' => number_between($photo['priority'] ?? 5, 1, 10, 5),
            'locationIndex' => number_between($photo['locationIndex'] ?? 50, 1, 100, 50),
            'isLanding' => $isLanding,
            'caption' => clean_text($photo['caption'] ?? ''),
            'latitude' => is_numeric($photo['latitude'] ?? null) ? (float) $photo['latitude'] : null,
            'longitude' => is_numeric($photo['longitude'] ?? null) ? (float) $photo['longitude'] : null,
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

if ($action === 'deriveMetadata') {
    $changed = 0;
    $dated = 0;
    $located = 0;
    foreach ($index['photos'] as &$photo) {
        if (!is_array($photo)) {
            continue;
        }
        $fileName = clean_text($photo['fileName'] ?? basename((string) ($photo['url'] ?? '')));
        $sourcePath = $photosDir . '/' . $fileName;
        if ($fileName === '' || !is_file($sourcePath)) {
            continue;
        }

        $metadata = extract_photo_metadata($sourcePath);
        $photoChanged = false;
        if (clean_text($photo['date'] ?? '') === '' && $metadata['date'] !== '') {
            $photo['date'] = $metadata['date'];
            $photoChanged = true;
            $dated += 1;
        }
        if (clean_text($photo['location'] ?? '') === '' && $metadata['location'] !== '') {
            $photo['location'] = $metadata['location'];
            $photoChanged = true;
            $located += 1;
        }
        if (($photo['latitude'] ?? null) === null && $metadata['latitude'] !== null) {
            $photo['latitude'] = $metadata['latitude'];
            $photoChanged = true;
        }
        if (($photo['longitude'] ?? null) === null && $metadata['longitude'] !== null) {
            $photo['longitude'] = $metadata['longitude'];
            $photoChanged = true;
        }
        if ($photoChanged) {
            $changed += 1;
        }
    }
    unset($photo);
    write_index($indexPath, $index);
    $result = read_index($indexPath);
    $result['metadataDerived'] = $changed;
    $result['datesDerived'] = $dated;
    $result['locationsDerived'] = $located;
    respond($result);
}

fail('Unknown action.');
