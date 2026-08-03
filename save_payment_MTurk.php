<?php
header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Only POST requests are allowed.';
    exit;
}

function post_value($key) {
    return trim((string)($_POST[$key] ?? ''));
}

function valid_optional_mturk_id($value) {
    return $value === '' || preg_match('/^[A-Za-z0-9_-]{1,128}$/', $value) === 1;
}

function valid_worker_id($value) {
    return preg_match('/^A[A-Z0-9]{1,63}$/', $value) === 1;
}

function csv_boolean($value, $field_name) {
    $normalized = strtolower(trim((string)$value));
    if ($normalized === 'true') {
        return true;
    }
    if ($normalized === 'false') {
        return false;
    }
    throw new RuntimeException('Invalid boolean in ' . $field_name . '.');
}

function csv_integer($value, $field_name) {
    $text = trim((string)$value);
    if (preg_match('/^-?\d+$/', $text) !== 1) {
        throw new RuntimeException('Invalid integer in ' . $field_name . '.');
    }
    return (int)$text;
}

function verify_behavior_points($filepath, $expected_study_id) {
    $handle = @fopen($filepath, 'r');
    if ($handle === false) {
        throw new RuntimeException('Behavior file is missing.');
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        throw new RuntimeException('Behavior file has no header.');
    }

    $columns = array_flip($header);
    $required_columns = [
        'study_id', 'event', 'trial_index', 'practice', 'condition_name',
        'choice_number', 'correct', 'points_awarded', 'total_points',
        'final_total_points'
    ];
    foreach ($required_columns as $column) {
        if (!array_key_exists($column, $columns)) {
            fclose($handle);
            throw new RuntimeException('Behavior file is missing column ' . $column . '.');
        }
    }

    $trials = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < count($header)) {
            $row = array_pad($row, count($header), '');
        }

        $value = function($name) use ($row, $columns) {
            return $row[$columns[$name]] ?? '';
        };

        if (trim((string)$value('study_id')) !== $expected_study_id) {
            fclose($handle);
            throw new RuntimeException('Behavior study ID mismatch.');
        }

        $event = trim((string)$value('event'));
        if ($event !== 'choice' && $event !== 'iti') {
            continue;
        }

        $practice_text = strtolower(trim((string)$value('practice')));
        $condition_name = trim((string)$value('condition_name'));
        if ($practice_text !== 'false' || preg_match('/^cond_[1-4]_/', $condition_name) !== 1) {
            continue;
        }

        $trial_index = csv_integer($value('trial_index'), 'trial_index');
        if ($trial_index < 1) {
            fclose($handle);
            throw new RuntimeException('Invalid main-task trial index.');
        }

        if (!isset($trials[$trial_index])) {
            $trials[$trial_index] = [
                'choices' => [],
                'points_awarded' => null,
                'running_total' => null,
                'reported_final' => null
            ];
        }

        if ($event === 'choice') {
            $choice_number = csv_integer($value('choice_number'), 'choice_number');
            if ($choice_number < 1 || $choice_number > 4) {
                fclose($handle);
                throw new RuntimeException('Choice number is outside 1-4.');
            }
            $trials[$trial_index]['choices'][] = [
                'number' => $choice_number,
                'correct' => csv_boolean($value('correct'), 'correct')
            ];
        } else {
            if ($trials[$trial_index]['points_awarded'] !== null) {
                fclose($handle);
                throw new RuntimeException('Duplicate scored row for a main-task trial.');
            }
            $trials[$trial_index]['points_awarded'] = csv_integer(
                $value('points_awarded'),
                'points_awarded'
            );
            $trials[$trial_index]['running_total'] = csv_integer(
                $value('total_points'),
                'total_points'
            );
            $trials[$trial_index]['reported_final'] = csv_integer(
                $value('final_total_points'),
                'final_total_points'
            );
        }
    }
    fclose($handle);

    if (count($trials) !== 480) {
        throw new RuntimeException('Expected 480 completed main-task trials.');
    }

    $verified_total = 0;
    foreach ($trials as $trial) {
        $choices = $trial['choices'];
        if ($trial['points_awarded'] === null || count($choices) < 1 || count($choices) > 4) {
            throw new RuntimeException('A main-task trial is incomplete.');
        }

        foreach ($choices as $index => $choice) {
            if ($choice['number'] !== $index + 1) {
                throw new RuntimeException('Main-task choice sequence is invalid.');
            }
            if ($index < count($choices) - 1 && $choice['correct']) {
                throw new RuntimeException('A trial continued after a correct choice.');
            }
        }

        $last_choice = $choices[count($choices) - 1];
        if (!$last_choice['correct'] && $last_choice['number'] !== 4) {
            throw new RuntimeException('An incorrect trial ended before four choices.');
        }

        $expected_points = $last_choice['correct'] ? 5 - $last_choice['number'] : 0;
        if ($trial['points_awarded'] !== $expected_points) {
            throw new RuntimeException('A main-task score does not match its choices.');
        }

        $verified_total += $expected_points;
        if ($trial['running_total'] !== $verified_total) {
            throw new RuntimeException('Running total is inconsistent with trial scores.');
        }
    }

    foreach ($trials as $trial) {
        if ($trial['reported_final'] !== $verified_total) {
            throw new RuntimeException('Final total is inconsistent with trial scores.');
        }
    }

    return $verified_total;
}

$study_id = post_value('study_id');
$worker_id = post_value('worker_id');
$mturk_url_worker_id = post_value('mturk_url_worker_id');
$assignment_id = post_value('assignment_id');
$hit_id = post_value('hit_id');
$completion_code = post_value('completion_code');
$consent_participate = post_value('consent_participate');
$consent_date = post_value('consent_date');
$us_location_confirmed = post_value('us_location_confirmed');
$client_saved_at = post_value('client_saved_at');
$points_text = post_value('final_total_points');

if (preg_match('/^PDM-[A-Z0-9]{12}$/', $study_id) !== 1) {
    http_response_code(400);
    echo 'Invalid study ID.';
    exit;
}

if (!valid_worker_id($worker_id)) {
    http_response_code(400);
    echo 'Invalid Worker ID.';
    exit;
}

if (($mturk_url_worker_id !== '' && !valid_worker_id($mturk_url_worker_id)) ||
    !valid_optional_mturk_id($assignment_id) ||
    !valid_optional_mturk_id($hit_id)) {
    http_response_code(400);
    echo 'Invalid MTurk identifier.';
    exit;
}

if ($mturk_url_worker_id !== '' && $worker_id !== $mturk_url_worker_id) {
    http_response_code(409);
    echo 'Worker ID mismatch.';
    exit;
}

if (preg_match('/^PDM-[A-Z0-9]{6}$/', $completion_code) !== 1) {
    http_response_code(400);
    echo 'Invalid completion code.';
    exit;
}

if ($consent_participate !== 'yes' || $consent_date === '' || $us_location_confirmed !== 'yes') {
    http_response_code(400);
    echo 'Missing consent or US-location confirmation.';
    exit;
}

if (filter_var($points_text, FILTER_VALIDATE_INT) === false) {
    http_response_code(400);
    echo 'Invalid points value.';
    exit;
}

$final_total_points = (int)$points_text;
if ($final_total_points < 0 || $final_total_points > 1920) {
    http_response_code(400);
    echo 'Points value is outside the valid range.';
    exit;
}

$behavior_dir = __DIR__ . '/data';
$behavior_filename = 'study_' . $study_id . '_MTurk.csv';
$behavior_filepath = $behavior_dir . '/' . $behavior_filename;

try {
    $verified_behavior_points = verify_behavior_points($behavior_filepath, $study_id);
} catch (RuntimeException $error) {
    error_log('PDM-WM behavior verification failed for ' . $study_id . ': ' . $error->getMessage());
    http_response_code(409);
    echo 'Behavior data could not be verified for payment. Please contact the research team.';
    exit;
}

if ($verified_behavior_points !== $final_total_points) {
    http_response_code(409);
    echo 'Submitted points do not match the saved behavior data.';
    exit;
}

// These server-side values must stay synchronized with CONFIG in index_MTurk.html.
$baseline_compensation_dollars = 12.00;
$bonus_points_per_unit = 200;
$bonus_dollars_per_unit = 0.50;
$completed_bonus_units = intdiv($final_total_points, $bonus_points_per_unit);
$bonus_earned_dollars = $completed_bonus_units * $bonus_dollars_per_unit;
$total_compensation_dollars = $baseline_compensation_dollars + $bonus_earned_dollars;

$payment_dir = __DIR__ . '/payment_data';
if (!file_exists($payment_dir) && !mkdir($payment_dir, 0700, true)) {
    http_response_code(500);
    echo 'Could not create payment data directory.';
    exit;
}

@chmod($payment_dir, 0700);

// Deny direct web access on Apache-compatible hosts. The deployment should
// additionally place this directory outside the public web root when possible.
$access_file = $payment_dir . '/.htaccess';
if (!file_exists($access_file)) {
    @file_put_contents($access_file, "Require all denied\nDeny from all\n", LOCK_EX);
}

$filepath = $payment_dir . '/payment_' . $study_id . '_MTurk.json';
$existing = [];

if (file_exists($filepath)) {
    $existing_text = file_get_contents($filepath);
    $decoded = json_decode($existing_text, true);
    if (is_array($decoded)) {
        $existing = $decoded;
    }

    $identity_fields = [
        'study_id' => $study_id,
        'worker_id' => $worker_id,
        'completion_code' => $completion_code
    ];

    foreach ($identity_fields as $key => $value) {
        if (isset($existing[$key]) && $existing[$key] !== $value) {
            http_response_code(409);
            echo 'Existing payment record does not match this submission.';
            exit;
        }
    }
}

$new_values = [
    'study_id' => $study_id,
    'worker_id' => $worker_id,
    'mturk_url_worker_id' => $mturk_url_worker_id,
    'assignment_id' => $assignment_id,
    'hit_id' => $hit_id,
    'completion_code' => $completion_code,
    'consent_participate' => $consent_participate,
    'consent_date' => $consent_date,
    'us_location_confirmed' => $us_location_confirmed,
    'final_total_points' => $final_total_points,
    'behavior_points_verified' => true,
    'behavior_file' => $behavior_filename,
    'baseline_compensation_dollars' => number_format($baseline_compensation_dollars, 2, '.', ''),
    'bonus_points_per_unit' => $bonus_points_per_unit,
    'bonus_dollars_per_unit' => number_format($bonus_dollars_per_unit, 2, '.', ''),
    'bonus_earned_dollars' => number_format($bonus_earned_dollars, 2, '.', ''),
    'total_compensation_dollars' => number_format($total_compensation_dollars, 2, '.', ''),
    'data_saved' => true,
    'client_saved_at' => $client_saved_at,
    'server_saved_at' => gmdate('c')
];

$record = array_merge($existing, $new_values);
$record['assignment_approved'] = $existing['assignment_approved'] ?? false;
$record['bonus_sent'] = $existing['bonus_sent'] ?? false;

$json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($filepath, $json . "\n", LOCK_EX) === false) {
    http_response_code(500);
    echo 'Could not save payment record.';
    exit;
}

@chmod($filepath, 0600);
echo 'Payment record saved successfully.';
?>
