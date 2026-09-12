<?php
// Narrow App -> Excel write path for enrolling a brand-new student (from
// the waiting list) onto the live "Student Database X-Y" tab. Public,
// narrow scope: can only append one new student row — never touches an
// existing student's data.
//
// The tricky part: this Graph API account doesn't support structural row
// insert, and the sheet's real "TOTALS" row (with live SUM formulas) sits
// immediately after the last student — so a naive append would land the
// new student AFTER the totals row and never be counted. Instead: the new
// student's data overwrites the current TOTALS row's position, and the
// TOTALS row (with its formula ranges extended by one) is rewritten one
// row further down. Net effect looks exactly like an insert, with no
// structural row-insert operation ever used.
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
if (!$payload || empty($payload['firstName']) || empty($payload['surname']) || empty($payload['class'])) {
  fail(400, 'body must include at least {firstName, surname, class, ...}');
}

list($code, $wsData) = graph_call($base . '/worksheets', $tokens['access_token']);
$sheetName = null; $bestYear = 0;
if (!empty($wsData['value'])) {
  foreach ($wsData['value'] as $ws) {
    if (preg_match('/student\s*database\s*(\d{2,4})\s*-\s*(\d{2,4})/i', $ws['name'], $m)) {
      $endYear = intval(strlen($m[2]) == 2 ? '20' . $m[2] : $m[2]);
      if ($endYear > $bestYear) { $bestYear = $endYear; $sheetName = $ws['name']; }
    }
  }
}
if (!$sheetName) fail(500, 'no Student Database tab found');

// Find the TOTALS row (never hardcode — the sheet grows).
$labelColUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='G1:G500')";
list(, $lcdata) = graph_call($labelColUrl, $tokens['access_token']);
$totalsRow = null;
foreach (($lcdata['values'] ?? []) as $i => $row) {
  if (strtoupper(trim((string)($row[0] ?? ''))) === 'TOTALS') { $totalsRow = $i + 1; break; }
}
if (!$totalsRow) fail(500, 'no existing TOTALS row found on ' . $sheetName);

// Duplicate-guard: refuse if this exact name is already on the sheet.
$namesUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='B2:C" . ($totalsRow - 1) . "')";
list(, $ndata) = graph_call($namesUrl, $tokens['access_token']);
foreach (($ndata['values'] ?? []) as $row) {
  if (trim(strtolower($row[0] ?? '')) === trim(strtolower($payload['firstName']))
      && trim(strtolower($row[1] ?? '')) === trim(strtolower($payload['surname']))) {
    fail(409, 'a student with this exact name already exists on ' . $sheetName . ' — not adding a duplicate');
  }
}

$newStudentRow = $totalsRow;   // student takes over the old totals row's position
$newTotalsRow = $totalsRow + 1;
$uniqueId = preg_replace('/[^A-Za-z]/', '', $payload['firstName']) . preg_replace('/[^A-Za-z]/', '', $payload['surname']);
$feeDue = floatval($payload['feesDue'] ?? 280);

$g = fn($k, $d = '') => $payload[$k] ?? $d;

// A:AC — the new student's full record. L/M/N (legacy single receipt
// cols) and AD:AO (payment slots) are deliberately left blank — no
// payments recorded yet.
$rowValues = [[
  $uniqueId, $g('firstName'), $g('surname'), $g('dob'), $g('age'), $g('gender'),
  $g('class'), $feeDue, 0, -$feeDue, 0,
  '', '', '',
  $g('startDate'), '',
  $g('fatherName'), $g('fatherPhone'), $g('fatherEmail'),
  $g('motherName'), $g('motherPhone'), $g('motherEmail'),
  '', $g('road'), $g('town'), $g('county'), $g('postcode'),
  $g('allergies'), 'Enrolled from waiting list ' . date('Y-m-d'),
]];
$writeRange = "A{$newStudentRow}:AC{$newStudentRow}";
list($wc, , $wraw) = graph_call($base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='{$writeRange}')", $tokens['access_token'], 'PATCH', ['values' => $rowValues]);
if ($wc >= 300) fail(502, 'student row write failed', $wraw);

// Explicitly blank the payment-slot + reconciliation columns for this row
// (they belonged to the old TOTALS row a moment ago) and format the new
// due/date cells. AD:AQ is 14 columns (AD..AQ) — a mismatched array size
// here silently failed the clear once already (30 values into a 14-wide
// range), leaving the old TOTALS formulas sitting on a real student's
// row and making the new totals row double-count everything.
list($clrCode, , $clrRaw) = graph_call($base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='AD{$newStudentRow}:AQ{$newStudentRow}')", $tokens['access_token'], 'PATCH', ['values' => [array_fill(0, 14, '')]]);
if ($clrCode >= 300) fail(502, 'failed to clear payment-slot columns on the new student row — aborting before writing TOTALS to avoid a double-count', $clrRaw);
graph_call($base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='D{$newStudentRow}')", $tokens['access_token'], 'PATCH', ['numberFormat' => [['dd/mm/yyyy']]]);
graph_call($base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='O{$newStudentRow}')", $tokens['access_token'], 'PATCH', ['numberFormat' => [['dd/mm/yyyy']]]);
graph_call($base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='H{$newStudentRow}:J{$newStudentRow}')", $tokens['access_token'], 'PATCH', ['numberFormat' => array_fill(0, 3, ['"£"#,##0.00'])]);

// Rewrite the TOTALS row one further down, formula ranges extended to
// cover the new student — same formula set app-excel-add-reconciliation.php
// established (H/I/J/K sums, AE/AI/AM payment-slot sums, AP combined,
// AQ match-check).
$lastDataRow = $newStudentRow;
graph_call($base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='G{$newTotalsRow}')", $tokens['access_token'], 'PATCH', ['values' => [['TOTALS']]]);
$totalsFormulas = [
  'H' => "=SUM(H2:H{$lastDataRow})", 'I' => "=SUM(I2:I{$lastDataRow})",
  'J' => "=SUM(J2:J{$lastDataRow})", 'K' => "=SUM(K2:K{$lastDataRow})",
  'AE' => "=SUM(AE2:AE{$lastDataRow})", 'AI' => "=SUM(AI2:AI{$lastDataRow})", 'AM' => "=SUM(AM2:AM{$lastDataRow})",
  'AP' => "=AE{$newTotalsRow}+AI{$newTotalsRow}+AM{$newTotalsRow}",
  'AQ' => "=IF(ROUND(AP{$newTotalsRow}-I{$newTotalsRow},2)=0,\"✓ MATCH\",\"⚠ MISMATCH £\"&TEXT(AP{$newTotalsRow}-I{$newTotalsRow},\"0.00\"))",
];
foreach ($totalsFormulas as $col => $f) {
  graph_call($base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='{$col}{$newTotalsRow}')", $tokens['access_token'], 'PATCH', ['formulas' => [[$f]]]);
}
foreach (['H', 'I', 'J', 'K', 'AE', 'AI', 'AM', 'AP'] as $col) {
  graph_call($base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='{$col}{$newTotalsRow}')", $tokens['access_token'], 'PATCH', ['numberFormat' => [['"£"#,##0.00']]]);
}

echo json_encode(['ok' => true, 'sheet' => $sheetName, 'student_row' => $newStudentRow, 'totals_row' => $newTotalsRow]);
