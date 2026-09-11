<?php
// One-time (re-runnable) builder: groups students into families by shared
// parent contact details (phone/email), then lists every recorded payment
// for families with 2+ children, flagging same-day payments across
// siblings — the case a parent pays one combined amount at the till but
// the app/Excel end up with it split into separate per-child entries, so
// there's no single place today that shows "this family paid £X total,
// across these children, on this date." Flags for staff review rather
// than guessing whether same-day sibling payments really were one
// transaction — same "never silently merge" posture as the rest of this
// project's reconciliation tooling.
// Writes/refreshes its own dedicated "Family Payments Log" tab; safe to
// re-run any time after new payments land.
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
$sheetName = null; $bestYear = 0; $g5SheetName = null; $famSheet = null;
if (!empty($wsData['value'])) {
  foreach ($wsData['value'] as $ws) {
    if (preg_match('/student\s*database\s*(\d{2,4})\s*-\s*(\d{2,4})/i', $ws['name'], $m)) {
      $endYear = intval(strlen($m[2]) == 2 ? '20' . $m[2] : $m[2]);
      if ($endYear > $bestYear) { $bestYear = $endYear; $sheetName = $ws['name']; }
    }
    if (preg_match('/^g5\s*class/i', $ws['name'])) $g5SheetName = $ws['name'];
    if (strcasecmp(trim($ws['name']), 'Family Payments Log') === 0) $famSheet = $ws['name'];
  }
}
if (!$sheetName) fail(500, 'no Student Database tab found');

if (!$famSheet) {
  list($ac, $adata) = graph_call($base . '/worksheets/add', $tokens['access_token'], 'POST', ['name' => 'Family Payments Log']);
  if ($ac >= 300) fail(502, 'could not create Family Payments Log tab', $adata);
  $famSheet = 'Family Payments Log';
}

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
function norm_contact($v) {
  $v = trim((string)$v);
  if ($v === '') return '';
  // phone-looking value: strip everything but digits
  $digits = preg_replace('/[^0-9]/', '', $v);
  if (strlen($digits) >= 9) return 'ph:' . $digits;
  return 'em:' . strtolower($v);
}
function norm_date($v) {
  if (is_numeric($v)) { $ts = ($v - 25569) * 86400; return gmdate('Y-m-d', intval($ts)); }
  $t = strtotime($v);
  return $t ? date('Y-m-d', $t) : (string)$v;
}

// Pull one tab's students: name, contacts, fees due, and every recorded
// payment (date, amount).
function load_tab($base, $token, $sheet, $isG5) {
  $usedUrl = $base . "/worksheets('" . rawurlencode($sheet) . "')/usedRange(valuesOnly=true)?\$select=rowCount";
  list(, $ru) = graph_call($usedUrl, $token);
  $lastRow = max(2, intval($ru['rowCount'] ?? 500));
  $names = find_row_names($base, $sheet, $token, $lastRow);
  $due = col_range($base, $sheet, $token, 'H', $lastRow);
  if ($isG5) {
    $fPhone = col_range($base, $sheet, $token, 'Q', $lastRow);
    $fEmail = col_range($base, $sheet, $token, 'R', $lastRow);
    $mPhone = col_range($base, $sheet, $token, 'T', $lastRow);
    $mEmail = col_range($base, $sheet, $token, 'U', $lastRow);
    $paid = col_range($base, $sheet, $token, 'I', $lastRow);
    $dK = col_range($base, $sheet, $token, 'M', $lastRow);
    $dAF = col_range($base, $sheet, $token, 'AF', $lastRow);
    $dAI = col_range($base, $sheet, $token, 'AI', $lastRow);
  } else {
    $fPhone = col_range($base, $sheet, $token, 'R', $lastRow);
    $fEmail = col_range($base, $sheet, $token, 'S', $lastRow);
    $mPhone = col_range($base, $sheet, $token, 'U', $lastRow);
    $mEmail = col_range($base, $sheet, $token, 'V', $lastRow);
    $slotPairs = [['AD','AE'], ['AH','AI'], ['AL','AM']];
    $slotData = [];
    foreach ($slotPairs as $p) $slotData[] = [col_range($base, $sheet, $token, $p[0], $lastRow), col_range($base, $sheet, $token, $p[1], $lastRow)];
  }

  $students = [];
  foreach ($names as $i => $nr) {
    $fn = trim($nr[0] ?? ''); $sn = trim($nr[1] ?? '');
    if ($fn === '' || $sn === '') continue;
    $contacts = array_filter([
      norm_contact($fPhone[$i][0] ?? ''), norm_contact($fEmail[$i][0] ?? ''),
      norm_contact($mPhone[$i][0] ?? ''), norm_contact($mEmail[$i][0] ?? ''),
    ]);
    $payments = [];
    if ($isG5) {
      $filledDates = array_filter([$dK[$i][0] ?? '', $dAF[$i][0] ?? '', $dAI[$i][0] ?? ''], fn($v) => $v !== '' && $v !== null);
      $paidAmt = floatval($paid[$i][0] ?? 0);
      if (count($filledDates) === 1 && $paidAmt > 0) {
        $payments[] = ['date' => norm_date(reset($filledDates)), 'amount' => $paidAmt];
      }
    } else {
      foreach ($slotData as $pair) {
        $d = $pair[0][$i][0] ?? ''; $a = $pair[1][$i][0] ?? '';
        if ($d !== '' && $d !== null && $a !== '' && $a !== null) {
          $payments[] = ['date' => norm_date($d), 'amount' => floatval($a)];
        }
      }
    }
    $students[] = [
      'name' => "$fn $sn",
      'due' => floatval($due[$i][0] ?? 0),
      'contacts' => array_values($contacts),
      'payments' => $payments,
    ];
  }
  return $students;
}

$students = load_tab($base, $tokens['access_token'], $sheetName, false);
if ($g5SheetName) $students = array_merge($students, load_tab($base, $tokens['access_token'], $g5SheetName, true));

// Group students into families via union-find over shared contact values.
$parent = [];
foreach ($students as $i => $s) $parent[$i] = $i;
function find($parent, $x) { while ($parent[$x] != $x) $x = $parent[$x]; return $x; }
function union(&$parent, $a, $b) { $ra = find($parent, $a); $rb = find($parent, $b); if ($ra != $rb) $parent[$ra] = $rb; }

$contactOwner = [];
foreach ($students as $i => $s) {
  foreach ($s['contacts'] as $c) {
    if (isset($contactOwner[$c])) union($parent, $i, $contactOwner[$c]);
    else $contactOwner[$c] = $i;
  }
}
$groups = [];
foreach ($students as $i => $s) { $groups[find($parent, $i)][] = $i; }

// Only families with 2+ children AND at least one recorded payment matter here.
$rows = [['Family', 'Children', 'Date', 'Paid This Date', 'Paid By', 'Note']];
$familyNum = 0;
foreach ($groups as $idxs) {
  if (count($idxs) < 2) continue;
  $famStudents = array_map(fn($i) => $students[$i], $idxs);
  $anyPayments = array_filter($famStudents, fn($s) => !empty($s['payments']));
  if (empty($anyPayments)) continue;
  $familyNum++;
  $childNames = implode(', ', array_map(fn($s) => $s['name'], $famStudents));
  // Flatten all payments across this family's children with who-paid.
  $byDate = [];
  foreach ($famStudents as $s) {
    foreach ($s['payments'] as $p) {
      $byDate[$p['date']][] = ['who' => $s['name'], 'amount' => $p['amount']];
    }
  }
  ksort($byDate);
  $first = true;
  foreach ($byDate as $date => $entries) {
    $total = array_sum(array_column($entries, 'amount'));
    $who = implode(' + ', array_map(fn($e) => $e['who'] . ' (£' . number_format($e['amount'], 2) . ')', $entries));
    $note = count($entries) > 1
      ? '⚠ Same-day payment across ' . count($entries) . ' siblings — confirm with receipt/bank record whether this was one combined transaction'
      : '';
    $rows[] = [
      $first ? "Family $familyNum" : '',
      $first ? $childNames : '',
      $date,
      $total,
      $who,
      $note,
    ];
    $first = false;
  }
}

if (count($rows) === 1) {
  echo json_encode(['ok' => true, 'sheet' => $famSheet, 'families_with_payments' => 0, 'note' => 'no sibling family has a recorded payment yet']);
  exit;
}

$totalRows = count($rows);
$dataRange = "A1:F{$totalRows}";
$clearUrl = $base . "/worksheets('" . rawurlencode($famSheet) . "')/range(address='A1:F500')/clear";
graph_call($clearUrl, $tokens['access_token'], 'POST', ['applyTo' => 'All']);
$writeUrl = $base . "/worksheets('" . rawurlencode($famSheet) . "')/range(address='{$dataRange}')";
list($wc, , $wraw) = graph_call($writeUrl, $tokens['access_token'], 'PATCH', ['values' => $rows]);
if ($wc >= 300) fail(502, 'write failed', $wraw);

// UK date + currency formatting.
$dateRange = "C2:C{$totalRows}";
$amtRange = "D2:D{$totalRows}";
graph_call($base . "/worksheets('" . rawurlencode($famSheet) . "')/range(address='{$dateRange}')", $tokens['access_token'], 'PATCH', ['numberFormat' => array_fill(0, $totalRows - 1, ['dd/mm/yyyy'])]);
graph_call($base . "/worksheets('" . rawurlencode($famSheet) . "')/range(address='{$amtRange}')", $tokens['access_token'], 'PATCH', ['numberFormat' => array_fill(0, $totalRows - 1, ['"£"#,##0.00'])]);

$flaggedCount = 0;
foreach ($rows as $r) { if (!empty($r[5])) $flaggedCount++; }

echo json_encode(['ok' => true, 'sheet' => $famSheet, 'families_with_payments' => $familyNum, 'rows_written' => $totalRows - 1, 'flagged_same_day' => $flaggedCount]);
