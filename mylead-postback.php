<?php
header('Content-Type: text/plain');

// ─── CONFIG ───
$SUPABASE_URL = 'https://rbniyexnrfupubykwezg.supabase.co';
$SUPABASE_SERVICE_KEY = getenv('SUPABASE_SERVICE_KEY') ?: '';
$CONVERSION_RATE = 500000;

// ─── GET PARAMS ───
$player_id   = $_GET['player_id']   ?? ($_GET['ml_sub1'] ?? '');
$payout      = $_GET['payout_decimal'] ?? ($_GET['payout'] ?? 0);
$status      = $_GET['status']      ?? '';
$trans_id    = $_GET['transaction_id'] ?? ($_GET['transactionId'] ?? '');
$currency    = $_GET['currency']    ?? 'USD';

// ─── VALIDATE ───
if (empty($player_id)) {
    http_response_code(400);
    echo "ERROR: Missing player_id / ml_sub1";
    exit;
}
if (empty($trans_id)) {
    http_response_code(400);
    echo "ERROR: Missing transaction_id";
    exit;
}
if (empty($payout) || floatval($payout) <= 0) {
    echo "OK: Zero payout, nothing to credit";
    exit;
}

// Only credit approved/completed leads
$valid_statuses = ['approved', 'completed', '1', 'active', 'accepted', 'available to pay'];
if (!in_array(strtolower($status), $valid_statuses)) {
    echo "OK: Status '$status' not eligible for credit";
    exit;
}

// ─── CALCULATE POINTS ───
$points = intval(floatval($payout) * $CONVERSION_RATE);

// ─── SUPABASE REST CALL ───
function supabase_request($url, $key, $method, $path, $body = null) {
    $ch = curl_init($url . '/rest/v1/' . $path);
    $headers = [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } else if ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } else if ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpcode, 'body' => $response];
}

// ─── CHECK FOR DUPLICATE ───
$dup_check = supabase_request(
    $SUPABASE_URL,
    $SUPABASE_SERVICE_KEY,
    'GET',
    'mylead_completions?trans_id=eq.' . urlencode($trans_id) . '&select=id'
);
$dup_data = json_decode($dup_check['body'], true);
if (!empty($dup_data) && is_array($dup_data) && count($dup_data) > 0) {
    echo "OK: Duplicate transaction_id, already credited";
    exit;
}

// ─── FIND USER BY ID ───
$user_lookup = supabase_request(
    $SUPABASE_URL,
    $SUPABASE_SERVICE_KEY,
    'GET',
    'profiles?id=eq.' . urlencode($player_id) . '&select=id,points,username'
);
$user_data = json_decode($user_lookup['body'], true);
if (empty($user_data) || !is_array($user_data) || count($user_data) === 0) {
    http_response_code(404);
    echo "ERROR: User not found for player_id=$player_id";
    exit;
}

$user = $user_data[0];
$new_points = ($user['points'] ?? 0) + $points;

// ─── LOG COMPLETION ───
supabase_request(
    $SUPABASE_URL,
    $SUPABASE_SERVICE_KEY,
    'POST',
    'mylead_completions',
    [
        'user_id'     => $user['id'],
        'trans_id'    => $trans_id,
        'reward_points' => $points,
        'usd_value'   => floatval($payout),
        'status'      => 'completed'
    ]
);

// ─── CREDIT POINTS ───
$update = supabase_request(
    $SUPABASE_URL,
    $SUPABASE_SERVICE_KEY,
    'PATCH',
    'profiles?id=eq.' . urlencode($user['id']),
    ['points' => $new_points]
);

if ($update['code'] >= 200 && $update['code'] < 300) {
    echo "OK: Credited $points points to user " . $user['id'] . " (payout: $$payout)";
} else {
    http_response_code(500);
    echo "ERROR: Failed to update points. HTTP " . $update['code'];
}
?>
