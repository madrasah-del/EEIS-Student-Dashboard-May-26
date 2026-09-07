<?php
// Narrow App -> Excel write path, called directly by index.html (public
// client-side JS) after a successful payment save to Google Sheets.
// Deliberately does NOT use the powerful admin_key from excel-admin.php —
// this endpoint can only do one thing: find a student by name and append
// one payment into their first free instalment slot. That narrow scope
// keeps the blast radius small even though, like excel-proxy.php, it has
// no strong auth (this is a same-origin, same-risk-profile companion to
// the existing public read proxy).
$share = 'https://1drv.ms/x/c/d75baed8553a3b22/IQBn7j_IL-uhSo7ErF2LPDcZAcb8dVYl3JuoXOevABBOPoE';
$TOKEN_STORE = __DIR__ . '/ms_tokens.json';

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
if (!$payload || empty($payload['firstName']) || empty($payload['surname']) || !isset($payload['amount'])) {
  fail(400, 'body must be {firstName, surname, date, amount, method, receipt}');
}

// Find the current live "Student Database X-Y" tab (highest end year)
list($code, $wsData) = graph_call($base . '/worksheets', $tokens['access_token']);
$sheetName = null;
$bestYear = 0;
if (!empty($wsData['value'])) {
  foreach ($wsData['value'] as $ws) {
    if (preg_match('/student\s*database\s*(\d{2,4})\s*-\s*(\d{2,4})/i', $ws['name'], $m)) {
      $endYear = intval(strlen($m[2]) == 2 ? '20' . $m[2] : $m[2]);
      if ($endYear > $bestYear) { $bestYear = $endYear; $sheetName = $ws['name']; }
    }
  }
}
if (!$sheetName) fail(500, 'no Student Database tab found');

// Read First Name / Surname columns to find the matching row
$rangeUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='B2:C500')";
list($code, $rdata2) = graph_call($rangeUrl, $tokens['access_token']);
$rows = $rdata2['values'] ?? [];
$targetRow = null;
foreach ($rows as $i => $row) {
  $fn = trim(strtolower($row[0] ?? ''));
  $sn = trim(strtolower($row[1] ?? ''));
  if ($fn === trim(strtolower($payload['firstName'])) && $sn === trim(strtolower($payload['surname']))) {
    $targetRow = $i + 2; // range started at row 2
    break;
  }
}
if (!$targetRow) fail(404, 'student not found in Excel: ' . $payload['firstName'] . ' ' . $payload['surname']);

// Find the first free payment slot (AD/AH/AL date cells empty)
$slotCols = [['AD','AE','AF','AG'], ['AH','AI','AJ','AK'], ['AL','AM','AN','AO']];
$slot = null;
foreach ($slotCols as $cols) {
  $checkUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='{$cols[0]}{$targetRow}')";
  list($code, $cdata) = graph_call($checkUrl, $tokens['access_token']);
  $val = $cdata['values'][0][0] ?? '';
  if ($val === '' || $val === null) { $slot = $cols; break; }
}
if (!$slot) fail(409, 'all 3 payment slots are full for this student — needs manual review');

$writeRange = "{$slot[0]}{$targetRow}:{$slot[3]}{$targetRow}";
$writeUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='" . $writeRange . "')";
$values = [[
  $payload['date'] ?? date('Y-m-d'),
  floatval($payload['amount']),
  $payload['method'] ?? '',
  $payload['receipt'] ?? '',
]];
list($code, $wdata, $wraw) = graph_call($writeUrl, $tokens['access_token'], 'PATCH', ['values' => $values]);
if ($code >= 300) fail(502, 'write failed', $wraw);

echo json_encode(['ok' => true, 'sheet' => $sheetName, 'row' => $targetRow, 'slot' => $slot[0]]);
