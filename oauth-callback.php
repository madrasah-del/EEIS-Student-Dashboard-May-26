<?php
// One-time OAuth callback: exchanges the authorization code Microsoft
// redirects back with for a refresh token, then stores it so
// excel-proxy.php can silently refresh access tokens forever after.
// This file is only ever visited once (or again if the refresh token
// needs re-consenting) — it is not part of the normal sync flow.

header('Content-Type: text/html; charset=utf-8');

$CONFIG_FILE = __DIR__ . '/ms_config.php';
if (!file_exists($CONFIG_FILE)) {
    echo '<h2>Server not configured</h2><p>ms_config.php is missing on the server. Copy ms_config.example.php to ms_config.php and fill in the client secret.</p>';
    exit;
}
$config = require $CONFIG_FILE;
$CLIENT_ID     = $config['client_id'];
$CLIENT_SECRET = $config['client_secret'];
$REDIRECT_URI  = $config['redirect_uri'];
$TOKEN_STORE   = __DIR__ . '/ms_tokens.json';

if (isset($_GET['error'])) {
    echo '<h2>Sign-in was not completed</h2><p>' . htmlspecialchars($_GET['error_description'] ?? $_GET['error']) . '</p>';
    exit;
}

if (!isset($_GET['code'])) {
    echo '<h2>No authorization code received</h2><p>Please use the sign-in link provided rather than visiting this page directly.</p>';
    exit;
}

$ch = curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'client_id'     => $CLIENT_ID,
        'client_secret' => $CLIENT_SECRET,
        'code'          => $_GET['code'],
        'redirect_uri'  => $REDIRECT_URI,
        'grant_type'    => 'authorization_code',
        'scope'         => 'offline_access Files.Read User.Read',
    ]),
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);

if ($httpCode !== 200 || empty($data['refresh_token'])) {
    echo '<h2>Token exchange failed</h2><pre>' . htmlspecialchars($response) . '</pre>';
    exit;
}

$store = [
    'access_token'  => $data['access_token'],
    'refresh_token' => $data['refresh_token'],
    'expires_at'    => time() + intval($data['expires_in']) - 60,
];

file_put_contents($TOKEN_STORE, json_encode($store, JSON_PRETTY_PRINT));

echo '<h2>&#9989; OneDrive connected successfully</h2><p>The dashboard can now sync the Excel spreadsheet automatically. You can close this page.</p>';
