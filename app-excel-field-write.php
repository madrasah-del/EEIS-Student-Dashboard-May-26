<?php
// Narrow App -> Excel field-sync path, called directly by index.html after
// an in-app edit to a student's details (not payments — see
// app-excel-write.php for that). Same no-admin-key, narrow-blast-radius
// posture as app-excel-write.php: this endpoint can only look up a student
// by name and update a fixed whitelist of columns.
//
// "Newer wins" conflict handling: the caller sends both the field's value
// BEFORE this edit (baseline) and the new value. If the live Excel cell no
// longer matches the baseline, someone changed it in Excel more recently
// than this app edit started, so that field is left alone (Excel wins) and
// reported back as skipped — the next Excel->App sync will pick it up.
// Fields where Excel still matches the baseline are safe to overwrite.
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
if (!$payload || empty($payload['firstName']) || empty($payload['surname']) || empty($payload['fields']) || !is_array($payload['fields'])) {
  fail(400, 'body must be {firstName, surname, fields: {FieldName: {baseline, value}, ...}}');
}

// Column letter for each syncable field, per sheet type. Class and Fees
// Paid are deliberately excluded — Class changes usually mean moving a
// student between tabs (main <-> G5), which this endpoint does not
// attempt, and Fees Paid is only ever changed via payments (see
// app-excel-write.php), never as a plain field overwrite.
$COLS = array(
  'main' => array(
    'DOB' => 'D', 'Gender' => 'F', 'Fees Due' => 'H', 'Start Date' => 'O',
    'Father Name' => 'Q', 'Father Phone' => 'R', 'Father Email' => 'S',
    'Mother Name' => 'T', 'Mother Phone' => 'U', 'Mother Email' => 'V',
    'Road' => 'X', 'Town' => 'Y', 'County' => 'Z', 'Postcode' => 'AA',
    'Allergies' => 'AB', 'Comments' => 'AC',
  ),
  'g5' => array(
    'DOB' => 'D', 'Gender' => 'F', 'Fees Due' => 'H', 'Start Date' => 'N',
    'Father Name' => 'P', 'Father Phone' => 'Q', 'Father Email' => 'R',
    'Mother Name' => 'S', 'Mother Phone' => 'T', 'Mother Email' => 'U',
    'Road' => 'W', 'Town' => 'X', 'County' => 'Y', 'Postcode' => 'Z',
    'Allergies' => 'AA', 'Comments' => 'AB',
  ),
);

function find_row_by_name($base, $sheetName, $token, $firstName, $surname) {
  $rangeUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='B2:C500')";
  list($code, $rdata) = graph_call($rangeUrl, $token);
  $rows = $rdata['values'] ?? [];
  foreach ($rows as $i => $row) {
    $fn = trim(strtolower($row[0] ?? ''));
    $sn = trim(strtolower($row[1] ?? ''));
    if ($fn === trim(strtolower($firstName)) && $sn === trim(strtolower($surname))) {
      return $i + 2;
    }
  }
  return null;
}

// Find the current live "Student Database X-Y" tab, and the G5 tab.
list($code, $wsData) = graph_call($base . '/worksheets', $tokens['access_token']);
$sheetName = null; $bestYear = 0; $g5SheetName = null;
if (!empty($wsData['value'])) {
  foreach ($wsData['value'] as $ws) {
    if (preg_match('/student\s*database\s*(\d{2,4})\s*-\s*(\d{2,4})/i', $ws['name'], $m)) {
      $endYear = intval(strlen($m[2]) == 2 ? '20' . $m[2] : $m[2]);
      if ($endYear > $bestYear) { $bestYear = $endYear; $sheetName = $ws['name']; }
    }
    if (preg_match('/^g5\s*class/i', $ws['name'])) $g5SheetName = $ws['name'];
  }
}
if (!$sheetName) fail(500, 'no Student Database tab found');

$targetSheet = null; $targetRow = null; $colMap = null;
$targetRow = find_row_by_name($base, $sheetName, $tokens['access_token'], $payload['firstName'], $payload['surname']);
if ($targetRow) { $targetSheet = $sheetName; $colMap = $COLS['main']; }
elseif ($g5SheetName) {
  $targetRow = find_row_by_name($base, $g5SheetName, $tokens['access_token'], $payload['firstName'], $payload['surname']);
  if ($targetRow) { $targetSheet = $g5SheetName; $colMap = $COLS['g5']; }
}
if (!$targetRow) fail(404, 'student not found in Excel: ' . $payload['firstName'] . ' ' . $payload['surname']);

$applied = array(); $skipped = array();
foreach ($payload['fields'] as $fieldName => $fv) {
  if (!isset($colMap[$fieldName])) { $skipped[$fieldName] = 'not a syncable field'; continue; }
  $col = $colMap[$fieldName];
  $baseline = isset($fv['baseline']) ? trim((string)$fv['baseline']) : '';
  $newValue = isset($fv['value']) ? $fv['value'] : '';

  $cellUrl = $base . "/worksheets('" . rawurlencode($targetSheet) . "')/range(address='{$col}{$targetRow}')";
  list($ccode, $cdata) = graph_call($cellUrl, $tokens['access_token']);
  $currentExcelValue = trim((string)($cdata['values'][0][0] ?? ''));

  if ($baseline !== '' && $currentExcelValue !== '' && $currentExcelValue !== $baseline) {
    // Excel has moved on since this app edit started — Excel wins for this
    // field. Do not overwrite; the next Excel->App sync will pull it in.
    $skipped[$fieldName] = 'Excel value changed since this edit started (now "' . $currentExcelValue . '") - not overwritten';
    continue;
  }

  list($wcode, $wdata, $wraw) = graph_call($cellUrl, $tokens['access_token'], 'PATCH', ['values' => [[$newValue]]]);
  if ($wcode >= 300) { $skipped[$fieldName] = 'write failed'; continue; }
  $applied[$fieldName] = $newValue;
}

echo json_encode(['ok' => true, 'sheet' => $targetSheet, 'row' => $targetRow, 'applied' => $applied, 'skipped' => $skipped]);
