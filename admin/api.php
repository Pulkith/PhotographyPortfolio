<?php
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Content-Type: application/json; charset=utf-8');
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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
$authPath = $photosDir . '/.admin-sessions.json';
$adminPassword = env_value('ADMIN_PASSWORD', __DIR__ . '/../.env');

$optimizationTargets = [
    'display' => ['folder' => 'display', 'suffix' => 'display-1080', 'width' => 1080, 'quality' => 72],
    'preview' => ['folder' => 'display', 'suffix' => 'preview-540', 'width' => 540, 'quality' => 62],
    'thumb' => ['folder' => 'thumbs', 'suffix' => 'thumb-220', 'width' => 220, 'quality' => 52]
];

$legacyDerivativeSuffixes = [
    'display-720', 'display-1080', 'display-1440', 'display-1800', 'display-2400',
    'preview-360', 'preview-540', 'preview-720', 'preview-960', 'preview-1400',
    'thumb-140', 'thumb-220', 'thumb-320', 'thumb-480', 'thumb-640'
];

function auth_cookie_name() {
    return 'photography_admin_auth';
}

function env_value($key, $envPath) {
    $systemValue = getenv($key);
    if (is_string($systemValue) && $systemValue !== '') {
        return $systemValue;
    }
    if (!is_file($envPath) || !is_readable($envPath)) {
        return '';
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return '';
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        if (trim($name) !== $key) {
            continue;
        }
        $value = trim($value);
        if (
            strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        return $value;
    }

    return '';
}

function read_auth_sessions($authPath) {
    if (!is_file($authPath)) {
        return [];
    }
    $json = json_decode((string) file_get_contents($authPath), true);
    return is_array($json) ? $json : [];
}

function write_auth_sessions($authPath, $sessions) {
    $now = time();
    $clean = [];
    foreach ($sessions as $token => $expires) {
        if (is_string($token) && is_numeric($expires) && (int) $expires > $now) {
            $clean[$token] = (int) $expires;
        }
    }
    file_put_contents($authPath, json_encode($clean, JSON_PRETTY_PRINT), LOCK_EX);
}

function create_auth_token($authPath) {
    $expires = time() + 86400;
    $token = bin2hex(random_bytes(32));
    $sessions = read_auth_sessions($authPath);
    $sessions[$token] = $expires;
    write_auth_sessions($authPath, $sessions);
    setcookie(auth_cookie_name(), $token, [
        'expires' => $expires,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    return $token;
}

function clear_auth_cookie($authPath) {
    $token = $_COOKIE[auth_cookie_name()] ?? '';
    if (is_string($token) && $token !== '') {
        $sessions = read_auth_sessions($authPath);
        unset($sessions[$token]);
        write_auth_sessions($authPath, $sessions);
    }
    setcookie(auth_cookie_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
}

function is_authenticated($authPath, $input = []) {
    $token = '';
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (is_string($authorization) && stripos($authorization, 'Bearer ') === 0) {
        $token = trim(substr($authorization, 7));
    }
    if ($token === '') {
        $token = $_GET['token'] ?? $_POST['token'] ?? '';
    }
    if ($token === '' && is_array($input)) {
        $token = $input['token'] ?? '';
    }
    if ($token === '') {
        $token = $_COOKIE[auth_cookie_name()] ?? '';
    }
    if (!is_string($token) || $token === '') {
        return false;
    }
    $sessions = read_auth_sessions($authPath);
    $expires = $sessions[$token] ?? 0;
    if (!is_numeric($expires) || (int) $expires < time()) {
        return false;
    }
    return true;
}

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

function photo_date_value($photo) {
    $date = $photo['date'] ?? '';
    if (!is_string($date) || trim($date) === '') {
        return PHP_INT_MIN;
    }
    $timestamp = strtotime($date . ' 00:00:00');
    return $timestamp === false ? PHP_INT_MIN : $timestamp;
}

function compare_photos($a, $b) {
    $dateCompare = photo_date_value($b) <=> photo_date_value($a);
    if ($dateCompare !== 0) {
        return $dateCompare;
    }
    $indexCompare = ((int) ($a['locationIndex'] ?? 50)) <=> ((int) ($b['locationIndex'] ?? 50));
    if ($indexCompare !== 0) {
        return $indexCompare;
    }
    return ((int) ($b['priority'] ?? 5)) <=> ((int) ($a['priority'] ?? 5));
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

function cleanup_photo_files($photosDir, $fileName) {
    $fileName = basename(clean_text($fileName));
    if ($fileName === '') {
        return;
    }

    global $legacyDerivativeSuffixes;
    $paths = [$photosDir . '/' . $fileName];
    foreach ($legacyDerivativeSuffixes as $suffix) {
        $folder = strpos($suffix, 'thumb-') === 0 ? 'thumbs' : 'display';
        $paths[] = $photosDir . '/' . $folder . '/' . derivative_name($fileName, $suffix);
    }
    foreach ($paths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
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
    if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        return imagecreatefromjpeg($path);
    }
    if ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        return imagecreatefrompng($path);
    }
    if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        return imagecreatefromwebp($path);
    }
    if ($mime === 'image/gif' && function_exists('imagecreatefromgif')) {
        return imagecreatefromgif($path);
    }
    return null;
}

function save_resized_with_imagick($sourcePath, $targetPath, $maxWidth, $quality) {
    if (!class_exists('Imagick')) {
        return false;
    }
    try {
        $image = new Imagick($sourcePath);
        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        $image->thumbnailImage($maxWidth, 0);
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality($quality);
        $saved = $image->writeImage($targetPath);
        $image->clear();
        $image->destroy();
        return $saved && is_file($targetPath);
    } catch (Throwable $error) {
        return false;
    }
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
    if (!is_dir(dirname($targetPath)) || !is_writable(dirname($targetPath))) {
        return false;
    }

    if (save_resized_with_imagick($sourcePath, $targetPath, $maxWidth, $quality)) {
        return true;
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

function derivative_failure_reason($sourcePath, $targetPath) {
    $info = getimagesize($sourcePath);
    if ($info === false) {
        return 'getimagesize failed';
    }
    $mime = (string) ($info['mime'] ?? '');
    if (!is_dir(dirname($targetPath))) {
        return 'target directory missing';
    }
    if (!is_writable(dirname($targetPath))) {
        return 'target directory not writable';
    }
    if (class_exists('Imagick')) {
        return 'Imagick write failed';
    }
    if (!function_exists('imagecreatetruecolor')) {
        return 'GD missing';
    }
    if ($mime === 'image/jpeg' && !function_exists('imagecreatefromjpeg')) {
        return 'GD JPEG read missing';
    }
    if (!function_exists('imagejpeg')) {
        return 'GD JPEG write missing';
    }
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        return 'unsupported mime ' . $mime;
    }
    return 'GD resize/write failed';
}

function ensure_derivatives($host, $photosDir, $displayDir, $thumbDir, $fileName) {
    global $optimizationTargets;
    $sourcePath = $photosDir . '/' . basename($fileName);
    $displayName = derivative_name($fileName, $optimizationTargets['display']['suffix']);
    $previewName = derivative_name($fileName, $optimizationTargets['preview']['suffix']);
    $thumbName = derivative_name($fileName, $optimizationTargets['thumb']['suffix']);
    $displayPath = $displayDir . '/' . $displayName;
    $previewPath = $displayDir . '/' . $previewName;
    $thumbPath = $thumbDir . '/' . $thumbName;
    $createdDisplay = is_file($displayPath) || save_resized_jpeg($sourcePath, $displayPath, $optimizationTargets['display']['width'], $optimizationTargets['display']['quality']);
    $createdPreview = is_file($previewPath) || save_resized_jpeg($sourcePath, $previewPath, $optimizationTargets['preview']['width'], $optimizationTargets['preview']['quality']);
    $createdThumb = is_file($thumbPath) || save_resized_jpeg($sourcePath, $thumbPath, $optimizationTargets['thumb']['width'], $optimizationTargets['thumb']['quality']);

    return [
        'displayFileName' => $createdDisplay ? $displayName : null,
        'displayUrl' => $createdDisplay ? derivative_url($host, 'display', $displayName) : photo_url($host, $fileName),
        'previewFileName' => $createdPreview ? $previewName : null,
        'previewUrl' => $createdPreview ? derivative_url($host, 'display', $previewName) : photo_url($host, $fileName),
        'thumbFileName' => $createdThumb ? $thumbName : null,
        'thumbUrl' => $createdThumb ? derivative_url($host, 'thumbs', $thumbName) : photo_url($host, $fileName),
        'createdDisplay' => $createdDisplay,
        'createdPreview' => $createdPreview,
        'createdThumb' => $createdThumb,
        'failureReason' => (!$createdDisplay ? 'display: ' . derivative_failure_reason($sourcePath, $displayPath) : '')
            . (!$createdPreview ? ' preview: ' . derivative_failure_reason($sourcePath, $previewPath) : '')
            . (!$createdThumb ? ' thumb: ' . derivative_failure_reason($sourcePath, $thumbPath) : '')
    ];
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = strpos($contentType, 'application/json') !== false
    ? (json_decode((string) file_get_contents('php://input'), true) ?: [])
    : $_POST;

$action = (string) ($input['action'] ?? $_GET['action'] ?? '');
$index = read_index($indexPath);

if ($action === 'authStatus') {
    respond(['authenticated' => is_authenticated($authPath, $input)]);
}

if ($action === 'login') {
    if ($adminPassword === '') {
        fail('Admin password is not configured.', 500);
    }
    $password = (string) ($input['password'] ?? '');
    if (!hash_equals($adminPassword, $password)) {
        fail('Invalid password.', 401);
    }
    $token = create_auth_token($authPath);
    respond(['authenticated' => true, 'expiresInHours' => 24, 'token' => $token, 'cookieAttempted' => true]);
}

if ($action === 'logout') {
    clear_auth_cookie($authPath);
    respond(['authenticated' => false]);
}

$publicActions = ['', 'list'];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($action, $publicActions, true)) {
    respond(read_index($indexPath));
}

$protectedActions = ['upload', 'replace', 'save', 'delete', 'optimize', 'clientOptimize', 'diagnostics', 'deriveMetadata'];
if (in_array($action, $protectedActions, true) && !is_authenticated($authPath, $input)) {
    fail('Authentication required.', 401);
}

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
    usort($index['photos'], 'compare_photos');
    write_index($indexPath, $index);
    respond(read_index($indexPath));
}

if ($action === 'replace') {
    $id = clean_text($input['id'] ?? '');
    if ($id === '') {
        fail('Missing photo id.');
    }
    if (!isset($_FILES['photo']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
        fail('No replacement photo found.');
    }

    $photoIndex = null;
    foreach ($index['photos'] as $indexKey => $photo) {
        if (($photo['id'] ?? '') === $id) {
            $photoIndex = $indexKey;
            break;
        }
    }
    if ($photoIndex === null) {
        fail('Photo not found.', 404);
    }

    $info = getimagesize($_FILES['photo']['tmp_name']);
    if ($info === false) {
        fail('Replacement file is not a supported image.');
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
        fail('Could not store replacement photo.', 500);
    }

    $oldFileName = clean_text($index['photos'][$photoIndex]['fileName'] ?? basename((string) ($index['photos'][$photoIndex]['url'] ?? '')));
    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    $derivatives = ensure_derivatives($host, $photosDir, $displayDir, $thumbDir, $fileName);

    $index['photos'][$photoIndex]['fileName'] = $fileName;
    $index['photos'][$photoIndex]['url'] = photo_url($host, $fileName);
    $index['photos'][$photoIndex]['displayFileName'] = $derivatives['displayFileName'];
    $index['photos'][$photoIndex]['displayUrl'] = $derivatives['displayUrl'];
    $index['photos'][$photoIndex]['previewFileName'] = $derivatives['previewFileName'];
    $index['photos'][$photoIndex]['previewUrl'] = $derivatives['previewUrl'];
    $index['photos'][$photoIndex]['thumbFileName'] = $derivatives['thumbFileName'];
    $index['photos'][$photoIndex]['thumbUrl'] = $derivatives['thumbUrl'];
    $index['photos'][$photoIndex]['aspectRatio'] = $height > 0 ? round($width / $height, 4) : null;

    cleanup_photo_files($photosDir, $oldFileName);
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
    usort($cleanPhotos, 'compare_photos');
    write_index($indexPath, ['photos' => $cleanPhotos]);
    respond(read_index($indexPath));
}

if ($action === 'delete') {
    $id = clean_text($input['id'] ?? '');
    $fileName = basename(clean_text($input['fileName'] ?? ''));
    $index['photos'] = array_values(array_filter($index['photos'], function ($photo) use ($id) {
        return ($photo['id'] ?? '') !== $id;
    }));
    cleanup_photo_files($photosDir, $fileName);
    write_index($indexPath, $index);
    respond(read_index($indexPath));
}

if ($action === 'optimize') {
    $changed = 0;
    $failed = 0;
    $failures = [];
    foreach ($index['photos'] as &$photo) {
        if (!is_array($photo)) {
            continue;
        }
        $fileName = clean_text($photo['fileName'] ?? basename((string) ($photo['url'] ?? '')));
        if ($fileName === '' || !is_file($photosDir . '/' . $fileName)) {
            $failed += 1;
            $failures[] = $fileName !== '' ? $fileName . ': source file missing' : 'missing filename';
            continue;
        }
        $derivatives = ensure_derivatives($host, $photosDir, $displayDir, $thumbDir, $fileName);
        $isOptimized = $derivatives['createdDisplay'] && $derivatives['createdPreview'] && $derivatives['createdThumb'];
        if (!$isOptimized) {
            $failed += 1;
            $reason = trim($derivatives['failureReason'] ?? 'derivative generation failed');
            $failures[] = $fileName . ': ' . $reason;
            continue;
        }
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
    $result['optimizeFailed'] = $failed;
    $result['optimizeFailures'] = array_slice($failures, 0, 8);
    respond($result);
}

if ($action === 'clientOptimize') {
    global $optimizationTargets;
    $id = clean_text($input['id'] ?? '');
    if ($id === '') {
        fail('Missing photo id.');
    }

    $photoIndex = null;
    foreach ($index['photos'] as $indexKey => $photo) {
        if (($photo['id'] ?? '') === $id) {
            $photoIndex = $indexKey;
            break;
        }
    }
    if ($photoIndex === null) {
        fail('Photo not found.', 404);
    }

    $fileName = clean_text($index['photos'][$photoIndex]['fileName'] ?? basename((string) ($index['photos'][$photoIndex]['url'] ?? '')));
    if ($fileName === '') {
        fail('Photo filename is missing.');
    }

    $uploads = [
        'display' => [$displayDir, derivative_name($fileName, $optimizationTargets['display']['suffix'])],
        'preview' => [$displayDir, derivative_name($fileName, $optimizationTargets['preview']['suffix'])],
        'thumb' => [$thumbDir, derivative_name($fileName, $optimizationTargets['thumb']['suffix'])]
    ];

    foreach ($uploads as $field => [$targetDir, $targetName]) {
        if (!isset($_FILES[$field]) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
            fail('Missing optimized ' . $field . ' file.');
        }
        $info = getimagesize($_FILES[$field]['tmp_name']);
        if ($info === false || (string) ($info['mime'] ?? '') !== 'image/jpeg') {
            fail('Optimized ' . $field . ' file must be a JPEG.');
        }
        $targetPath = $targetDir . '/' . $targetName;
        if (!move_uploaded_file($_FILES[$field]['tmp_name'], $targetPath)) {
            fail('Could not store optimized ' . $field . ' file.', 500);
        }
    }

    $displayName = derivative_name($fileName, $optimizationTargets['display']['suffix']);
    $previewName = derivative_name($fileName, $optimizationTargets['preview']['suffix']);
    $thumbName = derivative_name($fileName, $optimizationTargets['thumb']['suffix']);
    $index['photos'][$photoIndex]['displayFileName'] = $displayName;
    $index['photos'][$photoIndex]['displayUrl'] = derivative_url($host, 'display', $displayName);
    $index['photos'][$photoIndex]['previewFileName'] = $previewName;
    $index['photos'][$photoIndex]['previewUrl'] = derivative_url($host, 'display', $previewName);
    $index['photos'][$photoIndex]['thumbFileName'] = $thumbName;
    $index['photos'][$photoIndex]['thumbUrl'] = derivative_url($host, 'thumbs', $thumbName);

    write_index($indexPath, $index);
    respond(read_index($indexPath));
}

if ($action === 'diagnostics') {
    respond([
        'photosDir' => $photosDir,
        'displayDir' => $displayDir,
        'thumbDir' => $thumbDir,
        'photosDirWritable' => is_writable($photosDir),
        'displayDirWritable' => is_writable($displayDir),
        'thumbDirWritable' => is_writable($thumbDir),
        'gdAvailable' => function_exists('imagecreatetruecolor'),
        'jpegAvailable' => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
        'pngAvailable' => function_exists('imagecreatefrompng'),
        'webpAvailable' => function_exists('imagecreatefromwebp'),
        'imagickAvailable' => class_exists('Imagick'),
        'displayFileCount' => count(glob($displayDir . '/*') ?: []),
        'thumbFileCount' => count(glob($thumbDir . '/*') ?: [])
    ]);
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
