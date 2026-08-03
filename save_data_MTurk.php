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

if (preg_match('/^study_PDM-[A-Z0-9]{12}_MTurk\.csv$/', $filename) !== 1) {
    http_response_code(400);
    echo 'Invalid data filename.';
    exit;
}

if (strlen($csvdata) > 10 * 1024 * 1024) {
    http_response_code(413);
    echo 'Data file is too large.';
    exit;
}

$data_dir = __DIR__ . '/data';
if (!file_exists($data_dir) && !mkdir($data_dir, 0700, true)) {
    http_response_code(500);
    echo 'Could not create data directory.';
    exit;
}

@chmod($data_dir, 0700);

// Deny direct web access on Apache-compatible hosts. The deployment should
// additionally place this directory outside the public web root when possible.
$access_file = $data_dir . '/.htaccess';
if (!file_exists($access_file)) {
    @file_put_contents($access_file, "Require all denied\nDeny from all\n", LOCK_EX);
}

$filepath = $data_dir . '/' . $filename;

// A completed study ID is immutable. A retry after the behavior file was
// successfully created is treated as success, but it can never replace the
// original research record with different client-supplied content.
if (file_exists($filepath)) {
    echo 'Data were already saved successfully as ' . $filename;
    exit;
}

$handle = @fopen($filepath, 'x');
if ($handle === false) {
    if (file_exists($filepath)) {
        echo 'Data were already saved successfully as ' . $filename;
        exit;
    }

    http_response_code(500);
    echo 'Could not save data.';
    exit;
}

$bytes_written = 0;
$data_length = strlen($csvdata);
while ($bytes_written < $data_length) {
    $written = fwrite($handle, substr($csvdata, $bytes_written));
    if ($written === false || $written === 0) {
        fclose($handle);
        @unlink($filepath);
        http_response_code(500);
        echo 'Could not save complete data.';
        exit;
    }
    $bytes_written += $written;
}

fflush($handle);
fclose($handle);

@chmod($filepath, 0600);

echo 'Data saved successfully as ' . $filename;
?>
