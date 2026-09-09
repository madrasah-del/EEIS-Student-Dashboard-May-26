<?php
// Narrow App -> Excel write path for the Teachers 26-27 tab. Same
// no-admin-key, narrow-blast-radius posture as app-excel-write.php /
// app-excel-field-write.php: this endpoint can only look up a staff
// member by name (in the "ACTIVE PAID TEACHING STAFF" or "VOLUNTEERS"
// sections) and write into a single fixed column — "Day Rate" — appended
// after the existing data (column P) rather than inserted, since this
// Microsoft Graph account doesn't support structural column insert.
$share = 'https://1drv.ms/x/c/d75baed8553a3b22/IQBn7j_IL-uhSo7ErF2LPDcZAcb8dVYl3JuoXOevABBOPoE';
$TOKEN_STORE = __DIR__ . '/ms_tokens.json';
$DAY_RATE_COL = 'P';
$DAY_RATE_HEADER = 'Day Rate (2026-27)';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://madrasah.eeis.store');

function fail($code, $msg, $detail = null) {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg, 'detail' => $detail]);
  exit;
}

$CONFIG_FILE = __DIR__ . '/ms_config.php';
if (!file_exists($CONFIG_FILE)) fail(500, 'ms_config.php missing');
$config = require $CONFIG_FILE;
$CLIENT_ID = $config['client_id'];
$CLIENT_SECRET = $config['client_secret'];

if (!file_exists($TOKEN_STORE)) fail(500, 'not connected');
$tokens = json_decode(file_get_contents($TOKEN_STORE), true);
if (!$tokens || empty($tokens['refresh_token'])) fail(500, 'token store corrupt');

function refresh_access_token($tokens, $CLIENT_ID, $CLIENT_SECRET, $TOKEN_STORE) {
  $ch = curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');
  curl_setopt_array($ch, array(
    CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
    CURLOPT_POSTFIELDS => http_build_query(array(
      'client_id' => $CLIENT_ID, 'client_secret' => $CLIENT_SECRET,
      'refresh_token' => $tokens['refresh_token'], 'grant_type' => 'refresh_token',
      'scope' => 'offline_access Files.ReadWrite User.Read',
    )),
  ));
  $response = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $data = json_decode($response, true);
  if ($code != 200 || empty($data['access_token'])) fail(502, 'token refresh failed', $response);
  $new = array(
    'access_token' => $data['access_token'],
    'refresh_token' => !empty($data['refresh_token']) ? $data['refresh_token'] : $tokens['refresh_token'],
    'expires_at' => time() + intval($data['expires_in']) - 60,
  );
  file_put_contents($TOKEN_STORE, json_encode($new, JSON_PRETTY_PRINT));
  return $new;
}

if (empty($tokens['access_token']) || empty($tokens['expires_at']) || time() >= $tokens['expires_at']) {
  $tokens = refresh_access_token($tokens, $CLIENT_ID, $CLIENT_SECRET, $TOKEN_STORE);
}

function graph_call($url, $accessToken, $method = 'GET', $body = null) {
  $ch = curl_init($url);
  $opts = array(
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'),
    CURLOPT_CUSTOMREQUEST => $method,
  );
  if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
  curl_setopt_array($ch, $opts);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return array($code, json_decode($resp, true), $resp);
}

$b64 = rtrim(strtr(base64_encode($share), '+/', '-_'), '=');
$shareId = 'u!' . $b64;
list($rcode, $rdata, $rraw) = graph_call('https://graph.microsoft.com/v1.0/shares/' . $shareId . '/driveItem?$select=id,parentReference', $tokens['access_token']);
if ($rcode >= 300 || empty($rdata['id'])) fail(502, 'could not resolve drive item', $rraw);
$driveId = $rdata['parentReference']['driveId'];
$itemId = $rdata['id'];
$base = "https://graph.microsoft.com/v1.0/drives/$driveId/items/$itemId/workbook";

$payload = json_decode(file_get_contents('php://input'), true);
if (!$payload || empty($payload['rates']) || !is_array($payload['rates'])) {
  fail(400, 'body must be {rates: [{name, dayRate}, ...]}');
}

// Find the Teachers tab (name carries the academic year, e.g. "Teachers 26-27")
list($code, $wsData) = graph_call($base . '/worksheets', $tokens['access_token']);
$sheetName = null;
if (!empty($wsData['value'])) {
  foreach ($wsData['value'] as $ws) {
    if (preg_match('/^teachers\s*\d{2,4}\s*-\s*\d{2,4}/i', $ws['name'])) { $sheetName = $ws['name']; break; }
  }
}
if (!$sheetName) fail(500, 'no Teachers tab found');

// Read column A (names) for the whole used range once, to find every row.
$rangeUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='A1:A100')";
list($code, $rdata2) = graph_call($rangeUrl, $tokens['access_token']);
$names = $rdata2['values'] ?? [];

function find_row($names, $target) {
  $t = trim(strtolower($target));
  foreach ($names as $i => $row) {
    $v = trim(strtolower($row[0] ?? ''));
    if ($v === $t) return $i + 1; // 1-indexed row number
  }
  return null;
}

// Ensure the header exists in row 2 (Active section) and row 17 (Volunteers
// section) — both tables repeat their own header row in this sheet.
foreach ([2, 17] as $headerRow) {
  $cellUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='{$DAY_RATE_COL}{$headerRow}')";
  list(, $hdata) = graph_call($cellUrl, $tokens['access_token']);
  $current = trim((string)($hdata['values'][0][0] ?? ''));
  if ($current === '') {
    graph_call($cellUrl, $tokens['access_token'], 'PATCH', ['values' => [[$DAY_RATE_HEADER]]]);
  }
}

$applied = array(); $skipped = array();
foreach ($payload['rates'] as $r) {
  if (empty($r['name'])) continue;
  $row = find_row($names, $r['name']);
  if (!$row) { $skipped[$r['name']] = 'not found in Teachers tab'; continue; }
  $cellUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='{$DAY_RATE_COL}{$row}')";
  $value = isset($r['dayRate']) ? $r['dayRate'] : '';
  list($wcode, , $wraw) = graph_call($cellUrl, $tokens['access_token'], 'PATCH', ['values' => [[$value]]]);
  if ($wcode >= 300) { $skipped[$r['name']] = 'write failed'; continue; }
  $applied[$r['name']] = $value;
}

echo json_encode(['ok' => true, 'sheet' => $sheetName, 'applied' => $applied, 'skipped' => $skipped]);
