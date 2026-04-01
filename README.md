<?php
// =============================================================
// api/tile.php - Map tile proxy to avoid CORS issues
// Proxies ArcGIS tile requests through our server
// =============================================================

// Validate parameters
$z = filter_input(INPUT_GET, 'z', FILTER_VALIDATE_INT);
$y = filter_input(INPUT_GET, 'y', FILTER_VALIDATE_INT);
$x = filter_input(INPUT_GET, 'x', FILTER_VALIDATE_INT);
$layer = filter_input(INPUT_GET, 'l', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'satellite';

if ($z === false || $z === null || $y === false || $y === null || $x === false || $x === null) {
    http_response_code(400);
    exit;
}

// Restrict zoom levels (0-19)
if ($z < 0 || $z > 19 || $y < 0 || $x < 0) {
    http_response_code(400);
    exit;
}

// Only allow known layer types
$layers = [
    'satellite' => 'World_Imagery',
    'street'    => 'World_Street_Map',
];
$service = $layers[$layer] ?? $layers['satellite'];

// Build upstream URL
$url = "https://server.arcgisonline.com/ArcGIS/rest/services/{$service}/MapServer/tile/{$z}/{$y}/{$x}";

// Cache directory
$cacheDir = __DIR__ . '/../cache/tiles/' . $layer . '/' . $z . '/' . $y;
$cacheFile = $cacheDir . '/' . $x . '.jpg';

// Serve from cache if exists and fresh (30 days)
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 2592000) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=2592000');
    header('X-Tile-Cache: HIT');
    readfile($cacheFile);
    exit;
}

// Fetch from upstream
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'AttendanceSystem/1.0',
]);
$data = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($httpCode !== 200 || empty($data)) {
    http_response_code(502);
    exit;
}

// Cache tile to disk
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
@file_put_contents($cacheFile, $data);

// Serve
header('Content-Type: ' . ($contentType ?: 'image/jpeg'));
header('Cache-Control: public, max-age=2592000');
header('X-Tile-Cache: MISS');
echo $data;
