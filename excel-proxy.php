<?php
// EEIS Excel proxy — fetches the live OneDrive workbook via the Microsoft
// Graph API using a stored refresh token (see oauth-callback.php for the
// one-time sign-in that created it). Replaces the old anonymous-share-link
// scraping approach, which Microsoft blocks for programmatic downloads.
$share = 'https://1drv.ms/x/c/d75baed8553a3b22/IQBn7j_IL-uhSo7ErF2LPDcZAcb8dVYl3JuoXOevABBOPoE';

$TOKEN_STORE   = __DIR__ . '/ms_tokens.json';
$debug = isset($_GET['debug']);

function fail($msg, $detail, $debug) {
  http_response_code(502);
  header('Content-Type: text/plain');
  echo $msg;
  if ($debug) echo "\n--- debug ---\n" . $detail;
  exit;
}

$CONFIG_FILE = __DIR__ . '/ms_config.php';
if (!file_exists($CONFIG_FILE)) {
  fail('Server not configured', 'ms_config.php is missing on the server. Copy ms_config.example.php to ms_config.php and fill in the client secret.', $debug);
}
$config = require $CONFIG_FILE;
$CLIENT_ID     = $config['client_id'];
$CLIENT_SECRET = $config['client_secret'];

if (!file_exists($TOKEN_STORE)) {
  fail('OneDrive not connected yet', 'ms_tokens.json is missing — complete the one-time sign-in via oauth-callback.php first.', $debug);
}

$tokens = json_decode(file_get_contents($TOKEN_STORE), true);
if (!$tokens || empty($tokens['refresh_token'])) {
  fail('OneDrive token store is corrupt', 'ms_tokens.json did not contain a refresh_token.', $debug);
}

function refresh_access_token($tokens, $CLIENT_ID, $CLIENT_SECRET, $TOKEN_STORE, $debug) {
  $ch = curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');
  curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_POSTFIELDS => http_build_query(array(
      'client_id'     => $CLIENT_ID,
      'client_secret' => $CLIENT_SECRET,
      'refresh_token' => $tokens['refresh_token'],
      'grant_type'    => 'refresh_token',
      'scope'         => 'offline_access Files.Read User.Read',
    )),
  ));
  $response = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $data = json_decode($response, true);
  if ($code != 200 || empty($data['access_token'])) {
    fail('Could not refresh OneDrive access token — the connection may need to be re-authorised', 'refresh response: ' . $response, $debug);
  }
  $new = array(
    'access_token'  => $data['access_token'],
    'refresh_token' => !empty($data['refresh_token']) ? $data['refresh_token'] : $tokens['refresh_token'],
    'expires_at'    => time() + intval($data['expires_in']) - 60,
  );
  file_put_contents($TOKEN_STORE, json_encode($new, JSON_PRETTY_PRINT));
  return $new;
}

// Refresh if we don't have a still-valid access token
if (empty($tokens['access_token']) || empty($tokens['expires_at']) || time() >= $tokens['expires_at']) {
  $tokens = refresh_access_token($tokens, $CLIENT_ID, $CLIENT_SECRET, $TOKEN_STORE, $debug);
}

// Encode the share URL the way Microsoft Graph's /shares endpoint requires
$b64 = base64_encode($share);
$b64 = rtrim(strtr($b64, '+/', '-_'), '=');
$shareId = 'u!' . $b64;

function graph_get($url, $accessToken) {
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 90,
    CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $accessToken),
  ));
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return array($code, $body);
}

list($code, $data) = graph_get(
  'https://graph.microsoft.com/v1.0/shares/' . $shareId . '/driveItem/content',
  $tokens['access_token']
);

// If the access token was rejected outright, try one silent refresh-and-retry
if ($code === 401) {
  $tokens = refresh_access_token($tokens, $CLIENT_ID, $CLIENT_SECRET, $TOKEN_STORE, $debug);
  list($code, $data) = graph_get(
    'https://graph.microsoft.com/v1.0/shares/' . $shareId . '/driveItem/content',
    $tokens['access_token']
  );
}

if ($code != 200 || !$data || substr($data, 0, 2) !== 'PK') {
  fail('workbook download failed via Graph API (' . $code . ')', 'body starts: ' . substr($data, 0, 300), $debug);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Cache-Control: no-store');
header('Content-Length: ' . strlen($data));
echo $data;
