<?php
header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Only POST requests are allowed.';
    exit;
}

$filename = $_POST['filename'] ?? '';
$csvdata = $_POST['csvdata'] ?? '';

$filename = basename($filename);
$filename = preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename);

if ($filename === '' || $csvdata === '') {
    http_response_code(400);
    echo 'Missing filename or csvdata.';
    exit;
}

if (substr(strtolower($filename), -4) !== '.csv') {
    $filename .= '.csv';
}

$data_dir = __DIR__ . '/data';
if (!file_exists($data_dir) && !mkdir($data_dir, 0777, true)) {
    http_response_code(500);
    echo 'Could not create data directory.';
    exit;
}

$filepath = $data_dir . '/' . $filename;
if (file_put_contents($filepath, $csvdata, LOCK_EX) === false) {
    http_response_code(500);
    echo 'Could not save data.';
    exit;
}

echo 'Data saved successfully as ' . $filename;
?>
