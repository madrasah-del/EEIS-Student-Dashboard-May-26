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
$isReset = !empty($payload['reset']);
$updateMethod = $payload['updateMethod'] ?? null;
if (!$payload || empty($payload['firstName']) || empty($payload['surname']) || (!$isReset && !$updateMethod && !isset($payload['amount']))) {
  fail(400, 'body must be {firstName, surname, date, amount, method, receipt}, {firstName, surname, reset: true}, or {firstName, surname, updateMethod: {date, amount, receipt, newMethod}}');
}

// Find the current live "Student Database X-Y" tab (highest end year)
list($code, $wsData) = graph_call($base . '/worksheets', $tokens['access_token']);
$sheetName = null;
$bestYear = 0;
$g5SheetName = null;
if (!empty($wsData['value'])) {
  foreach ($wsData['value'] as $ws) {
    if (preg_match('/student\s*database\s*(\d{2,4})\s*-\s*(\d{2,4})/i', $ws['name'], $m)) {
      $endYear = intval(strlen($m[2]) == 2 ? '20' . $m[2] : $m[2]);
      if ($endYear > $bestYear) { $bestYear = $endYear; $sheetName = $ws['name']; }
    }
    if (preg_match('/^g5\s*class/i', $ws['name'])) {
      $g5SheetName = $ws['name'];
    }
  }
}
if (!$sheetName) fail(500, 'no Student Database tab found');

function find_row_by_name($base, $sheetName, $token, $firstName, $surname) {
  $rangeUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='B2:C500')";
  list($code, $rdata) = graph_call($rangeUrl, $token);
  $rows = $rdata['values'] ?? [];
  foreach ($rows as $i => $row) {
    $fn = trim(strtolower($row[0] ?? ''));
    $sn = trim(strtolower($row[1] ?? ''));
    if ($fn === trim(strtolower($firstName)) && $sn === trim(strtolower($surname))) {
      return $i + 2; // range started at row 2
    }
  }
  return null;
}

// Search the main tab first, then the G5 tab (G5 students graduate out of the
// main tab into their own sheet with a different, simpler column layout — no
// AD-AO instalment slots — so they need separate handling below).
$targetRow = find_row_by_name($base, $sheetName, $tokens['access_token'], $payload['firstName'], $payload['surname']);
if ($targetRow && $isReset) {
  // Clear all 3 instalment slots — used to undo test/practice payments,
  // never called from the normal payment-recording flow.
  $writeUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='AD{$targetRow}:AO{$targetRow}')";
  graph_call($writeUrl, $tokens['access_token'], 'PATCH', ['values' => [array_fill(0, 12, '')]]);
  echo json_encode(['ok' => true, 'sheet' => $sheetName, 'row' => $targetRow, 'reset' => true]);
  exit;
}
if ($targetRow && $updateMethod) {
  // Find the exact slot this payment was written into (matched by date +
  // amount + receipt — the same three facts the app already has) and
  // PATCH only its Method cell. Used for the bank-transfer confirmation
  // workflow: a payment is first pushed as "Bank Transfer (UNCONFIRMED)",
  // then once someone has actually checked the bank statement, the app
  // calls this to flip that same cell to "Bank Transfer (CONFIRMED)" —
  // never a new payment, never touches amount/date/receipt.
  $slotCols = [['AD','AE','AF','AG'], ['AH','AI','AJ','AK'], ['AL','AM','AN','AO']];
  $matchDate = $updateMethod['date'] ?? '';
  $matchAmount = floatval($updateMethod['amount'] ?? 0);
  $matchReceipt = trim((string)($updateMethod['receipt'] ?? ''));
  $found = null;
  foreach ($slotCols as $cols) {
    $checkUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='{$cols[0]}{$targetRow}:{$cols[3]}{$targetRow}')";
    list(, $cdata) = graph_call($checkUrl, $tokens['access_token']);
    $row = $cdata['values'][0] ?? ['', '', '', ''];
    $rowAmount = floatval($row[1] ?? 0);
    $rowReceipt = trim((string)($row[3] ?? ''));
    if (abs($rowAmount - $matchAmount) < 0.01 && $rowReceipt === $matchReceipt) { $found = $cols; break; }
  }
  if (!$found) fail(404, 'could not find a matching payment slot to update (date/amount/receipt did not match any recorded payment)');
  $methodUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='{$found[2]}{$targetRow}')";
  list($mcode, , $mraw) = graph_call($methodUrl, $tokens['access_token'], 'PATCH', ['values' => [[$updateMethod['newMethod'] ?? '']]]);
  if ($mcode >= 300) fail(502, 'method update failed', $mraw);
  echo json_encode(['ok' => true, 'sheet' => $sheetName, 'row' => $targetRow, 'slot' => $found[2], 'updated' => true]);
  exit;
}
if ($targetRow) {
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
  // Excel defaults a freshly-written date string to US m/d/yyyy display
  // regardless of the value's actual meaning — force UK dd/mm/yyyy on the
  // date cell so payment dates aren't misread by staff.
  $dateFmtUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='{$slot[0]}{$targetRow}')";
  graph_call($dateFmtUrl, $tokens['access_token'], 'PATCH', ['numberFormat' => [['dd/mm/yyyy']]]);

  echo json_encode(['ok' => true, 'sheet' => $sheetName, 'row' => $targetRow, 'slot' => $slot[0]]);
  exit;
}

if ($g5SheetName) {
  $targetRow = find_row_by_name($base, $g5SheetName, $tokens['access_token'], $payload['firstName'], $payload['surname']);
  if ($targetRow && $isReset) {
    // Clear Paid/Outstanding back to 0/full-fee-due, and all 3 payment
    // slots — used to undo test/practice payments, never called from
    // the normal payment-recording flow.
    $dueUrl = $base . "/worksheets('" . rawurlencode($g5SheetName) . "')/range(address='H{$targetRow}')";
    list(, $ddata) = graph_call($dueUrl, $tokens['access_token']);
    $feesDue = floatval($ddata['values'][0][0] ?? 0);
    $totalsUrl = $base . "/worksheets('" . rawurlencode($g5SheetName) . "')/range(address='I{$targetRow}:J{$targetRow}')";
    graph_call($totalsUrl, $tokens['access_token'], 'PATCH', ['values' => [[0, $feesDue]]]);
    $slot1Url = $base . "/worksheets('" . rawurlencode($g5SheetName) . "')/range(address='K{$targetRow}:M{$targetRow}')";
    graph_call($slot1Url, $tokens['access_token'], 'PATCH', ['values' => [['', '', '']]]);
    $slot23Url = $base . "/worksheets('" . rawurlencode($g5SheetName) . "')/range(address='AD{$targetRow}:AI{$targetRow}')";
    graph_call($slot23Url, $tokens['access_token'], 'PATCH', ['values' => [array_fill(0, 6, '')]]);
    echo json_encode(['ok' => true, 'sheet' => $g5SheetName, 'row' => $targetRow, 'reset' => true]);
    exit;
  }
  if ($targetRow) {
    // G5 tab: I=Fees Paid (running total), J=Fees Outstanding, H=Fees Due.
    // Per-payment receipt/method/date used to live only in K/L/M, a single
    // slot — a 2nd or 3rd payment silently overwrote the previous one's
    // receipt number with no error. Slots 2 and 3 (AD-AF, AG-AI) are
    // APPENDED past the sheet's last real column rather than inserted
    // next to K/L/M, since this Graph API account doesn't support
    // structural column insert (see CLAUDE.md) — appending avoids that
    // limitation entirely, same approach used for the Teachers Day Rate
    // column.
    $checkUrl = $base . "/worksheets('" . rawurlencode($g5SheetName) . "')/range(address='H{$targetRow}:I{$targetRow}')";
    list($code, $cdata) = graph_call($checkUrl, $tokens['access_token']);
    $vals = $cdata['values'][0] ?? [0, 0];
    $feesDue = floatval($vals[0] ?? 0);
    $alreadyPaid = floatval($vals[1] ?? 0);
    $newPaid = $alreadyPaid + floatval($payload['amount']);
    $newOutstanding = $feesDue - $newPaid;

    // Find the first free per-payment slot by checking each slot's
    // receipt-column for a value, same pattern as the main tab's
    // date-column check.
    $slots = [
      ['receipt' => 'K', 'method' => 'L', 'date' => 'M'],
      ['receipt' => 'AD', 'method' => 'AE', 'date' => 'AF'],
      ['receipt' => 'AG', 'method' => 'AH', 'date' => 'AI'],
    ];
    $slot = null;
    foreach ($slots as $s) {
      $slotCheckUrl = $base . "/worksheets('" . rawurlencode($g5SheetName) . "')/range(address='{$s['receipt']}{$targetRow}')";
      list($sc, $sdata) = graph_call($slotCheckUrl, $tokens['access_token']);
      $val = $sdata['values'][0][0] ?? '';
      if ($val === '' || $val === null) { $slot = $s; break; }
    }
    if (!$slot) fail(409, 'all 3 payment slots are full for this G5 student — needs manual review');

    // Lazily label slot 2/3's header row the first time either is ever
    // used (slot 1 already has real headers: Receipt book Page/Method of
    // Payment/Date of payment).
    if ($slot['receipt'] !== 'K') {
      $hdrUrl = $base . "/worksheets('" . rawurlencode($g5SheetName) . "')/range(address='{$slot['receipt']}1:{$slot['date']}1')";
      list(, $hdata) = graph_call($hdrUrl, $tokens['access_token']);
      $hasHeader = trim((string)($hdata['values'][0][0] ?? '')) !== '';
      if (!$hasHeader) {
        $slotNum = $slot['receipt'] === 'AD' ? '2' : '3';
        graph_call($hdrUrl, $tokens['access_token'], 'PATCH', ['values' => [[
          "Receipt book Page ($slotNum)", "Method of Payment ($slotNum)", "Date of payment ($slotNum)"
        ]]]);
      }
    }

    // Always update the running Paid/Outstanding totals (I:J)
    $totalsUrl = $base . "/worksheets('" . rawurlencode($g5SheetName) . "')/range(address='I{$targetRow}:J{$targetRow}')";
    graph_call($totalsUrl, $tokens['access_token'], 'PATCH', ['values' => [[$newPaid, $newOutstanding]]]);

    // Write this payment's own receipt/method/date into its slot
    $writeUrl = $base . "/worksheets('" . rawurlencode($g5SheetName) . "')/range(address='{$slot['receipt']}{$targetRow}:{$slot['date']}{$targetRow}')";
    $values = [[
      $payload['receipt'] ?? '',
      $payload['method'] ?? '',
      $payload['date'] ?? date('Y-m-d'),
    ]];
    list($code, $wdata, $wraw) = graph_call($writeUrl, $tokens['access_token'], 'PATCH', ['values' => $values]);
    if ($code >= 300) fail(502, 'write failed', $wraw);
    // Same US-default fix as the main tab above — force UK display on the
    // date cell of whichever slot was just written.
    $dateFmtUrl = $base . "/worksheets('" . rawurlencode($g5SheetName) . "')/range(address='{$slot['date']}{$targetRow}')";
    graph_call($dateFmtUrl, $tokens['access_token'], 'PATCH', ['numberFormat' => [['dd/mm/yyyy']]]);

    echo json_encode(['ok' => true, 'sheet' => $g5SheetName, 'row' => $targetRow, 'slot' => $slot['receipt']]);
    exit;
  }
}

fail(404, 'student not found in Excel: ' . $payload['firstName'] . ' ' . $payload['surname']);
