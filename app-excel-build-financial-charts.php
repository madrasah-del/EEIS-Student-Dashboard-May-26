<?php
// One-time (re-runnable) builder: writes a chart-ready payments ledger onto
// the "Financial Log" tab and creates two charts from it —
//   1. "Payments Received" — one bar per real payment, labelled with who
//      paid and when.
//   2. "Collections Rising vs Debt Falling" — cumulative collected (rising)
//      and cumulative outstanding (falling) over time, same date axis.
// Source data is read live from the "Student Database 26-27" and
// "G5 Class 26-27" tabs' own payment-slot columns (the same ones
// app-excel-write.php writes into) — never hand-maintained, so re-running
// this after new payments come in refreshes both tables and charts.
// Idempotent: clears its own working range and deletes/recreates its own
// two named charts each run, so it never duplicates data or charts.
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

// Find the current live "Student Database X-Y" tab, the G5 tab, and the
// "Financial Log" tab.
list($code, $wsData) = graph_call($base . '/worksheets', $tokens['access_token']);
$sheetName = null; $bestYear = 0; $g5SheetName = null; $finSheet = null;
if (!empty($wsData['value'])) {
  foreach ($wsData['value'] as $ws) {
    if (preg_match('/student\s*database\s*(\d{2,4})\s*-\s*(\d{2,4})/i', $ws['name'], $m)) {
      $endYear = intval(strlen($m[2]) == 2 ? '20' . $m[2] : $m[2]);
      if ($endYear > $bestYear) { $bestYear = $endYear; $sheetName = $ws['name']; }
    }
    if (preg_match('/^g5\s*class/i', $ws['name'])) $g5SheetName = $ws['name'];
    if (strcasecmp(trim($ws['name']), 'Financial Log') === 0) $finSheet = $ws['name'];
  }
}
if (!$sheetName) fail(500, 'no Student Database tab found');
if (!$finSheet) fail(500, 'no Financial Log tab found');

function find_row_names($base, $sheetName, $token, $lastRow) {
  $rangeUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='B2:C{$lastRow}')";
  list(, $rdata) = graph_call($rangeUrl, $token);
  return $rdata['values'] ?? [];
}
function col_range($base, $sheetName, $token, $col, $lastRow) {
  $rangeUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/range(address='{$col}2:{$col}{$lastRow}')";
  list(, $rdata) = graph_call($rangeUrl, $token);
  return $rdata['values'] ?? [];
}

// Read main tab: names + payment slots (date, amount) at AD/AE, AH/AI, AL/AM.
$mainUsedUrl = $base . "/worksheets('" . rawurlencode($sheetName) . "')/usedRange(valuesOnly=true)?\$select=rowCount";
list(, $mru) = graph_call($mainUsedUrl, $tokens['access_token']);
$mainLastRow = max(2, intval($mru['rowCount'] ?? 500));

$names = find_row_names($base, $sheetName, $tokens['access_token'], $mainLastRow);
$dueCol = col_range($base, $sheetName, $tokens['access_token'], 'H', $mainLastRow);
$slotCols = [];
foreach ([['AD','AE'], ['AH','AI'], ['AL','AM']] as $pair) {
  $slotCols[] = [col_range($base, $sheetName, $tokens['access_token'], $pair[0], $mainLastRow),
                 col_range($base, $sheetName, $tokens['access_token'], $pair[1], $mainLastRow)];
}

$totalDue = 0.0;
foreach ($dueCol as $r) { $v = $r[0] ?? ''; if ($v !== '' && $v !== null) $totalDue += floatval($v); }

$payments = [];
foreach ($names as $i => $nr) {
  $fn = trim($nr[0] ?? ''); $sn = trim($nr[1] ?? '');
  if ($fn === '' || $sn === '') continue;
  foreach ($slotCols as $pair) {
    $date = $pair[0][$i][0] ?? '';
    $amount = $pair[1][$i][0] ?? '';
    if ($date !== '' && $date !== null && $amount !== '' && $amount !== null) {
      $payments[] = ['date' => $date, 'student' => "$fn $sn", 'amount' => floatval($amount)];
    }
  }
}

// Read G5 tab: names + Fees Due (H) + one filled slot => amount = Fees Paid (I)
if ($g5SheetName) {
  $g5UsedUrl = $base . "/worksheets('" . rawurlencode($g5SheetName) . "')/usedRange(valuesOnly=true)?\$select=rowCount";
  list(, $gru) = graph_call($g5UsedUrl, $tokens['access_token']);
  $g5LastRow = max(2, intval($gru['rowCount'] ?? 100));
  $g5Names = find_row_names($base, $g5SheetName, $tokens['access_token'], $g5LastRow);
  $g5Due = col_range($base, $g5SheetName, $tokens['access_token'], 'H', $g5LastRow);
  $g5Paid = col_range($base, $g5SheetName, $tokens['access_token'], 'I', $g5LastRow);
  $g5DateK = col_range($base, $g5SheetName, $tokens['access_token'], 'M', $g5LastRow);
  $g5DateAF = col_range($base, $g5SheetName, $tokens['access_token'], 'AF', $g5LastRow);
  $g5DateAI = col_range($base, $g5SheetName, $tokens['access_token'], 'AI', $g5LastRow);
  foreach ($g5Names as $i => $nr) {
    $fn = trim($nr[0] ?? ''); $sn = trim($nr[1] ?? '');
    if ($fn === '' || $sn === '') continue;
    $due = floatval($g5Due[$i][0] ?? 0); if ($due) $totalDue += $due;
    $dates = array_filter([$g5DateK[$i][0] ?? '', $g5DateAF[$i][0] ?? '', $g5DateAI[$i][0] ?? ''], fn($v) => $v !== '' && $v !== null);
    $paid = floatval($g5Paid[$i][0] ?? 0);
    if (count($dates) === 1 && $paid > 0) {
      $payments[] = ['date' => reset($dates), 'student' => "$fn $sn", 'amount' => $paid];
    }
  }
}

// Normalise date strings (Graph range API returns dates as serial numbers
// or ISO strings depending on cell format) into Y-m-d for sorting/display.
function norm_date($v) {
  if (is_numeric($v)) {
    // Excel serial date (days since 1899-12-30)
    $ts = ($v - 25569) * 86400;
    return gmdate('Y-m-d', intval($ts));
  }
  $t = strtotime($v);
  return $t ? date('Y-m-d', $t) : (string)$v;
}
foreach ($payments as &$p) { $p['date'] = norm_date($p['date']); }
unset($p);
usort($payments, fn($a, $b) => strcmp($a['date'], $b['date']));

if (empty($payments)) fail(200, 'no real payments recorded yet — nothing to chart');

// Table A: Payment (Student / Date) | Amount
$tableA = [['Payment (Student / Date)', 'Amount']];
foreach ($payments as $p) {
  $d = DateTime::createFromFormat('Y-m-d', $p['date']);
  $label = $p['student'] . ' (' . $d->format('d/m') . ')';
  $tableA[] = [$label, $p['amount']];
}

// Table B: Date | Cumulative Collected | Cumulative Outstanding (one row
// per unique date, running totals)
$byDate = [];
foreach ($payments as $p) { $byDate[$p['date']] = ($byDate[$p['date']] ?? 0) + $p['amount']; }
ksort($byDate);
$tableB = [['Date', 'Cumulative Collected', 'Cumulative Outstanding']];
$running = 0;
foreach ($byDate as $d => $amt) {
  $running += $amt;
  $tableB[] = [$d, $running, $totalDue - $running];
}

$aRows = count($tableA); $bRows = count($tableB);
$aRange = "A5:B" . (4 + $aRows);
$bRange = "D5:F" . (4 + $bRows);
$clearRange = "A4:H60";

// --- Write the tables onto the Financial Log tab ---
$clearUrl = $base . "/worksheets('" . rawurlencode($finSheet) . "')/range(address='{$clearRange}')/clear";
graph_call($clearUrl, $tokens['access_token'], 'POST', ['applyTo' => 'All']);

$labelUrl = $base . "/worksheets('" . rawurlencode($finSheet) . "')/range(address='A4:D4')";
graph_call($labelUrl, $tokens['access_token'], 'PATCH', ['values' => [[
  'Individual Payments Received', '', '', 'Collections Rising vs Debt Falling (cumulative)'
]]]);

$aUrl = $base . "/worksheets('" . rawurlencode($finSheet) . "')/range(address='{$aRange}')";
list($ac) = graph_call($aUrl, $tokens['access_token'], 'PATCH', ['values' => $tableA]);

$bUrl = $base . "/worksheets('" . rawurlencode($finSheet) . "')/range(address='{$bRange}')";
list($bc) = graph_call($bUrl, $tokens['access_token'], 'PATCH', ['values' => $tableB]);

// Formats: currency on amount columns, UK date on the date column.
$fmtAmountA = "B6:B" . (4 + $aRows);
$fmtDateB = "D6:D" . (4 + $bRows);
$fmtAmountB = "E6:F" . (4 + $bRows);
graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/range(address='{$fmtAmountA}')", $tokens['access_token'], 'PATCH', ['numberFormat' => array_fill(0, $aRows - 1, ['"£"#,##0.00'])]);
graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/range(address='{$fmtDateB}')", $tokens['access_token'], 'PATCH', ['numberFormat' => array_fill(0, $bRows - 1, ['dd/mm/yyyy'])]);
graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/range(address='{$fmtAmountB}')", $tokens['access_token'], 'PATCH', ['numberFormat' => array_fill(0, $bRows - 1, ['"£"#,##0.00', '"£"#,##0.00'])]);

// --- Charts: delete any existing ones with our names, then recreate ---
list(, $chartsData) = graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/charts", $tokens['access_token']);
foreach (($chartsData['value'] ?? []) as $ch) {
  if (in_array($ch['name'], ['PaymentsReceivedChart', 'CollectionsTrendChart'])) {
    graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/charts('" . rawurlencode($ch['name']) . "')", $tokens['access_token'], 'DELETE');
  }
}

$result = ['ok' => true, 'sheet' => $finSheet, 'payments_charted' => count($payments), 'total_due' => $totalDue];

// Chart 1: column chart of individual payments
list($c1code, $c1data, $c1raw) = graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/charts/add", $tokens['access_token'], 'POST', [
  'type' => 'ColumnClustered',
  'sourceData' => "'" . $finSheet . "'!" . $aRange,
  'seriesBy' => 'Columns',
]);
if ($c1code < 300 && !empty($c1data['name'])) {
  $c1name = $c1data['name'];
  graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/charts('" . rawurlencode($c1name) . "')", $tokens['access_token'], 'PATCH', ['name' => 'PaymentsReceivedChart']);
  graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/charts('PaymentsReceivedChart')/title", $tokens['access_token'], 'PATCH', ['text' => 'Payments Received (who paid, and when)', 'visible' => true]);
  graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/charts('PaymentsReceivedChart')/legend", $tokens['access_token'], 'PATCH', ['visible' => false]);
  graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/charts('PaymentsReceivedChart')/setPosition", $tokens['access_token'], 'POST', ['startCell' => 'A18', 'endCell' => 'H33']);
  $result['chart1'] = 'ok';
} else {
  $result['chart1'] = 'failed: ' . $c1raw;
}

// Chart 2: line chart, collections rising vs outstanding falling
list($c2code, $c2data, $c2raw) = graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/charts/add", $tokens['access_token'], 'POST', [
  'type' => 'Line',
  'sourceData' => "'" . $finSheet . "'!" . $bRange,
  'seriesBy' => 'Columns',
]);
if ($c2code < 300 && !empty($c2data['name'])) {
  $c2name = $c2data['name'];
  graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/charts('" . rawurlencode($c2name) . "')", $tokens['access_token'], 'PATCH', ['name' => 'CollectionsTrendChart']);
  graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/charts('CollectionsTrendChart')/title", $tokens['access_token'], 'PATCH', ['text' => 'Collections Rising vs Debt Falling', 'visible' => true]);
  graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/charts('CollectionsTrendChart')/legend", $tokens['access_token'], 'PATCH', ['visible' => true]);
  graph_call($base . "/worksheets('" . rawurlencode($finSheet) . "')/charts('CollectionsTrendChart')/setPosition", $tokens['access_token'], 'POST', ['startCell' => 'A35', 'endCell' => 'H50']);
  $result['chart2'] = 'ok';
} else {
  $result['chart2'] = 'failed: ' . $c2raw;
}

echo json_encode($result);
