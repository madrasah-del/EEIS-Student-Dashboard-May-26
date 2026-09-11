<?php
// One-time (re-runnable, idempotent) builder: adds payment-slot totals and
// a three-way reconciliation check to the Student Database tab's existing
// TOTALS row, and a matching totals row to the G5 tab.
//
// The Student Database tab already has a real "TOTALS" row (row 109 as of
// Sept 2026, found dynamically below — never hardcoded) with working SUM
// formulas for Fees Due/Paid/Outstanding/Prior Year Balance (columns
// H/I/J/K). What it never had: a total for the three individual payment-
// amount columns (AE/AI/AM — Payment 1/2/3 Amount), so there was no way to
// see at a glance whether the sum of every individual payment recorded
// actually matches the Fees Paid column's own total. This adds exactly
// that, as real Excel formulas (not values) so they self-update:
//   AE<row> = SUM of Payment 1 Amount    AI<row> = SUM of Payment 2 Amount
//   AM<row> = SUM of Payment 3 Amount    AP<row> = AE+AI+AM (all payments)
//   AQ<row> = match/mismatch check against I<row> (Fees Paid total)
// Same idea added fresh to the G5 tab (which had no totals row at all).
$share = 'https://1drv.ms/x/c/d75baed8553a3b22/IQBn7j_IL-uhSo7ErF2LPDcZAcb8dVYl3JuoXOevABBOPoE';
$TOKEN_STORE = __DIR__ . '/ms_tokens.json';

header('Content-Type: application/json');

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

// Find the existing "TOTALS" row on the main tab by scanning column G —
// never hardcode the row number, the sheet grows over time.
function find_totals_row($base, $sheet, $token, $labelCol, $maxScan = 500) {
  $rangeUrl = $base . "/worksheets('" . rawurlencode($sheet) . "')/range(address='{$labelCol}1:{$labelCol}{$maxScan}')";
  list(, $rdata) = graph_call($rangeUrl, $token);
  foreach (($rdata['values'] ?? []) as $i => $row) {
    if (strtoupper(trim((string)($row[0] ?? ''))) === 'TOTALS') return $i + 1;
  }
  return null;
}
function last_student_row($base, $sheet, $token, $maxScan = 500) {
  $rangeUrl = $base . "/worksheets('" . rawurlencode($sheet) . "')/range(address='B1:B{$maxScan}')";
  list(, $rdata) = graph_call($rangeUrl, $token);
  $last = 1;
  foreach (($rdata['values'] ?? []) as $i => $row) {
    if (trim((string)($row[0] ?? '')) !== '') $last = $i + 1;
  }
  return $last;
}

$mainTotalsRow = find_totals_row($base, $sheetName, $tokens['access_token'], 'G');
if (!$mainTotalsRow) fail(500, 'no existing TOTALS row found on ' . $sheetName . ' — expected one in column G');
$lastDataRow = $mainTotalsRow - 1;

// Header labels for the new columns (lazy — only write if blank)
$hdrUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='AP1:AQ1')";
list(, $hdata) = graph_call($hdrUrl, $tokens['access_token']);
if (trim((string)($hdata['values'][0][0] ?? '')) === '') {
  graph_call($hdrUrl, $tokens['access_token'], 'PATCH', ['values' => [['Total Payments (3 slots)', 'Reconcile vs Fees Paid']]]);
}

// The three per-slot totals + combined total + reconciliation check, as
// real formulas so they self-update as new payments are added.
$formulas = [
  'AE' . $mainTotalsRow => "=SUM(AE2:AE{$lastDataRow})",
  'AI' . $mainTotalsRow => "=SUM(AI2:AI{$lastDataRow})",
  'AM' . $mainTotalsRow => "=SUM(AM2:AM{$lastDataRow})",
  'AP' . $mainTotalsRow => "=AE{$mainTotalsRow}+AI{$mainTotalsRow}+AM{$mainTotalsRow}",
  'AQ' . $mainTotalsRow => "=IF(ROUND(AP{$mainTotalsRow}-I{$mainTotalsRow},2)=0,\"✓ MATCH\",\"⚠ MISMATCH £\"&TEXT(AP{$mainTotalsRow}-I{$mainTotalsRow},\"0.00\"))",
];
foreach ($formulas as $cell => $f) {
  $cellUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='{$cell}')";
  graph_call($cellUrl, $tokens['access_token'], 'PATCH', ['formulas' => [[$f]]]);
}
// Currency format on the three slot totals + combined total.
$fmtUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='AE{$mainTotalsRow}:AE{$mainTotalsRow}')";
foreach (['AE', 'AI', 'AM', 'AP'] as $col) {
  graph_call($base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='{$col}{$mainTotalsRow}')", $tokens['access_token'], 'PATCH', ['numberFormat' => [['"£"#,##0.00']]]);
}

$result = ['ok' => true, 'main_sheet' => $sheetName, 'main_totals_row' => $mainTotalsRow];

// G5 tab: no totals row exists yet — add one right after the last student.
if ($g5SheetName) {
  $g5Last = last_student_row($base, $g5SheetName, $tokens['access_token']);
  $g5TotalsRow = $g5Last + 1;
  $existingLabel = null;
  $chkUrl = $base . "/worksheets('" . rawurlencode($g5SheetName) . "')/range(address='G{$g5TotalsRow}')";
  list(, $chkData) = graph_call($chkUrl, $tokens['access_token']);
  $existingLabel = trim((string)($chkData['values'][0][0] ?? ''));
  if ($existingLabel === '' || strtoupper($existingLabel) === 'TOTALS') {
    graph_call($chkUrl, $tokens['access_token'], 'PATCH', ['values' => [['TOTALS']]]);
    $g5f = [
      'H' . $g5TotalsRow => "=SUM(H2:H" . $g5Last . ")",
      'I' . $g5TotalsRow => "=SUM(I2:I" . $g5Last . ")",
      'J' . $g5TotalsRow => "=SUM(J2:J" . $g5Last . ")",
    ];
    foreach ($g5f as $cell => $f) {
      graph_call($base . "/worksheets('" . rawurlencode($g5SheetName) . "')/range(address='{$cell}')", $tokens['access_token'], 'PATCH', ['formulas' => [[$f]]]);
    }
    foreach (['H', 'I', 'J'] as $col) {
      graph_call($base . "/worksheets('" . rawurlencode($g5SheetName) . "')/range(address='{$col}{$g5TotalsRow}')", $tokens['access_token'], 'PATCH', ['numberFormat' => [['"£"#,##0.00']]]);
    }
    $result['g5_sheet'] = $g5SheetName;
    $result['g5_totals_row'] = $g5TotalsRow;
  } else {
    $result['g5_sheet'] = $g5SheetName;
    $result['g5_totals_row_skipped'] = "row {$g5TotalsRow} already has content (\"{$existingLabel}\") — not overwritten, check manually";
  }
}

echo json_encode($result);
