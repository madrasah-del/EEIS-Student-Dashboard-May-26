<?php
// EEIS Excel admin — narrow, key-protected write operations against the
// live OneDrive workbook via the Microsoft Graph Excel Workbook API.
// This is NOT a public endpoint: every request must carry the correct
// admin_key (stored only in ms_config.php, never in git). Only a small,
// whitelisted set of actions is supported — this is not a generic
// arbitrary-write API, to keep the blast radius small if the key ever
// leaked.
$share = 'https://1drv.ms/x/c/d75baed8553a3b22/IQBn7j_IL-uhSo7ErF2LPDcZAcb8dVYl3JuoXOevABBOPoE';
$TOKEN_STORE = __DIR__ . '/ms_tokens.json';

header('Content-Type: application/json');

function fail($code, $msg, $detail = null) {
  http_response_code($code);
  echo json_encode(['error' => $msg, 'detail' => $detail]);
  exit;
}

$CONFIG_FILE = __DIR__ . '/ms_config.php';
if (!file_exists($CONFIG_FILE)) fail(500, 'ms_config.php missing');
$config = require $CONFIG_FILE;

if (empty($config['admin_key']) || !isset($_GET['key']) || !hash_equals($config['admin_key'], $_GET['key'])) {
  fail(403, 'invalid or missing key');
}

$CLIENT_ID = $config['client_id'];
$CLIENT_SECRET = $config['client_secret'];

if (!file_exists($TOKEN_STORE)) fail(500, 'not connected — run oauth-callback.php first');
$tokens = json_decode(file_get_contents($TOKEN_STORE), true);
if (!$tokens || empty($tokens['refresh_token'])) fail(500, 'token store corrupt');

function refresh_access_token($tokens, $CLIENT_ID, $CLIENT_SECRET, $TOKEN_STORE) {
  $ch = curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');
  curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_POSTFIELDS => http_build_query(array(
      'client_id' => $CLIENT_ID,
      'client_secret' => $CLIENT_SECRET,
      'refresh_token' => $tokens['refresh_token'],
      'grant_type' => 'refresh_token',
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

$b64 = base64_encode($share);
$b64 = rtrim(strtr($b64, '+/', '-_'), '=');
$shareId = 'u!' . $b64;

function graph_call($url, $accessToken, $method = 'GET', $body = null, $retry_tokens = null) {
  $ch = curl_init($url);
  $headers = array('Authorization: Bearer ' . $accessToken, 'Content-Type: application/json');
  $opts = array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_CUSTOMREQUEST => $method,
  );
  if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
  curl_setopt_array($ch, $opts);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return array($code, json_decode($resp, true), $resp);
}

// Resolve the share link to a direct drive+item id — the Workbook API
// isn't supported when addressed via /shares/{id}/driveItem/workbook for
// personal Microsoft accounts, but works via /drives/{id}/items/{id}.
list($rcode, $rdata, $rraw) = graph_call('https://graph.microsoft.com/v1.0/shares/' . $shareId . '/driveItem?$select=id,parentReference', $tokens['access_token']);
if ($rcode >= 300 || empty($rdata['id']) || empty($rdata['parentReference']['driveId'])) {
  fail(502, 'could not resolve drive item', $rraw);
}
$driveId = $rdata['parentReference']['driveId'];
$itemId = $rdata['id'];
$base = "https://graph.microsoft.com/v1.0/drives/$driveId/items/$itemId/workbook";

$action = $_GET['action'] ?? '';

if ($action === 'resolve') {
  echo json_encode(['driveId' => $driveId, 'itemId' => $itemId]);
  exit;
}

if ($action === 'list_worksheets') {
  list($code, $data, $raw) = graph_call($base . '/worksheets', $tokens['access_token']);
  echo $raw;
  exit;
}

if ($action === 'ensure_sheet') {
  $name = $_GET['name'] ?? fail(400, 'missing name');
  // Check if it already exists
  list($code, $data) = graph_call($base . '/worksheets', $tokens['access_token']);
  $exists = false;
  if (!empty($data['value'])) {
    foreach ($data['value'] as $ws) {
      if ($ws['name'] === $name) { $exists = true; break; }
    }
  }
  if (!$exists) {
    list($code, $data, $raw) = graph_call($base . '/worksheets/add', $tokens['access_token'], 'POST', ['name' => $name]);
    if ($code >= 300) fail(502, 'failed to create sheet', $raw);
    echo json_encode(['created' => true, 'sheet' => $data]);
  } else {
    echo json_encode(['created' => false, 'already_existed' => true]);
  }
  exit;
}

if ($action === 'write_range') {
  $raw_body = file_get_contents('php://input');
  $payload = json_decode($raw_body, true);
  if (!$payload || empty($payload['sheet']) || empty($payload['range']) || !isset($payload['values'])) {
    fail(400, 'body must be {sheet, range, values}');
  }
  $url = $base . "/worksheets('" . rawurlencode($payload['sheet']) . "')/range(address='" . $payload['range'] . "')";
  list($code, $data, $rresp) = graph_call($url, $tokens['access_token'], 'PATCH', ['values' => $payload['values']]);
  if ($code >= 300) fail(502, 'write failed', $rresp);
  echo $rresp;
  exit;
}

if ($action === 'apply_style') {
  $raw_body = file_get_contents('php://input');
  $payload = json_decode($raw_body, true);
  if (!$payload || empty($payload['sheet']) || empty($payload['range'])) {
    fail(400, 'body must include {sheet, range, ...style fields}');
  }
  $rangeUrl = $base . "/worksheets('" . rawurlencode($payload['sheet']) . "')/range(address='" . $payload['range'] . "')";
  $results = array();

  if (isset($payload['number_format'])) {
    // numberFormat expects a 2D array matching the range shape; a single
    // string is broadcast by the caller pre-building the 2D array.
    list($code, $data, $raw) = graph_call($rangeUrl, $tokens['access_token'], 'PATCH', ['numberFormat' => $payload['number_format']]);
    $results['number_format'] = ($code < 300) ? 'ok' : $raw;
  }
  if (isset($payload['horizontal_alignment']) || isset($payload['vertical_alignment'])) {
    $alignBody = array();
    if (isset($payload['horizontal_alignment'])) $alignBody['horizontalAlignment'] = $payload['horizontal_alignment'];
    if (isset($payload['vertical_alignment'])) $alignBody['verticalAlignment'] = $payload['vertical_alignment'];
    list($code, $data, $raw) = graph_call($rangeUrl . '/format', $tokens['access_token'], 'PATCH', $alignBody);
    $results['alignment'] = ($code < 300) ? 'ok' : $raw;
  }
  if (isset($payload['fill_color'])) {
    list($code, $data, $raw) = graph_call($rangeUrl . '/format/fill', $tokens['access_token'], 'PATCH', ['color' => $payload['fill_color']]);
    $results['fill_color'] = ($code < 300) ? 'ok' : $raw;
  }
  if (isset($payload['font_name']) || isset($payload['font_size']) || isset($payload['font_color'])) {
    $fontBody = array();
    if (isset($payload['font_name'])) $fontBody['name'] = $payload['font_name'];
    if (isset($payload['font_size'])) $fontBody['size'] = $payload['font_size'];
    if (isset($payload['font_color'])) $fontBody['color'] = $payload['font_color'];
    list($code, $data, $raw) = graph_call($rangeUrl . '/format/font', $tokens['access_token'], 'PATCH', $fontBody);
    $results['font'] = ($code < 300) ? 'ok' : $raw;
  }
  echo json_encode(['results' => $results]);
  exit;
}

if ($action === 'set_tab_color') {
  $raw_body = file_get_contents('php://input');
  $payload = json_decode($raw_body, true);
  if (!$payload || empty($payload['sheet']) || !isset($payload['color'])) {
    fail(400, 'body must be {sheet, color}');
  }
  $url = $base . "/worksheets('" . rawurlencode($payload['sheet']) . "')";
  list($code, $data, $raw) = graph_call($url, $tokens['access_token'], 'PATCH', ['tabColor' => $payload['color']]);
  if ($code >= 300) fail(502, 'set tab color failed', $raw);
  echo $raw;
  exit;
}

if ($action === 'set_position') {
  $raw_body = file_get_contents('php://input');
  $payload = json_decode($raw_body, true);
  if (!$payload || empty($payload['sheet']) || !isset($payload['position'])) {
    fail(400, 'body must be {sheet, position}');
  }
  $url = $base . "/worksheets('" . rawurlencode($payload['sheet']) . "')";
  list($code, $data, $raw) = graph_call($url, $tokens['access_token'], 'PATCH', ['position' => $payload['position']]);
  if ($code >= 300) fail(502, 'set position failed', $raw);
  echo $raw;
  exit;
}

if ($action === 'freeze_panes') {
  $raw_body = file_get_contents('php://input');
  $payload = json_decode($raw_body, true);
  if (!$payload || empty($payload['sheet'])) fail(400, 'body must include {sheet, rows?, columns?}');
  $rows = $payload['rows'] ?? 0;
  $cols = $payload['columns'] ?? 0;
  $wsUrl = $base . "/worksheets('" . rawurlencode($payload['sheet']) . "')";
  $results = array();
  if ($rows > 0) {
    list($code, $data, $raw) = graph_call($wsUrl . '/freezePanes/freezeRows', $tokens['access_token'], 'POST', ['count' => $rows]);
    $results['rows'] = ($code < 300) ? 'ok' : $raw;
  }
  if ($cols > 0) {
    list($code, $data, $raw) = graph_call($wsUrl . '/freezePanes/freezeColumns', $tokens['access_token'], 'POST', ['count' => $cols]);
    $results['columns'] = ($code < 300) ? 'ok' : $raw;
  }
  echo json_encode(['results' => $results]);
  exit;
}

if ($action === 'add_table') {
  $raw_body = file_get_contents('php://input');
  $payload = json_decode($raw_body, true);
  if (!$payload || empty($payload['sheet']) || empty($payload['range'])) {
    fail(400, 'body must be {sheet, range, has_headers?, style?}');
  }
  $addr = "'" . $payload['sheet'] . "'!" . $payload['range'];
  list($code, $data, $raw) = graph_call($base . '/tables/add', $tokens['access_token'], 'POST', [
    'address' => $addr,
    'hasHeaders' => $payload['has_headers'] ?? true,
  ]);
  if ($code >= 300) fail(502, 'add table failed', $raw);
  if (!empty($payload['style']) && !empty($data['id'])) {
    graph_call($base . "/tables('" . $data['id'] . "')", $tokens['access_token'], 'PATCH', ['style' => $payload['style']]);
  }
  echo json_encode(['table' => $data]);
  exit;
}

if ($action === 'add_conditional_format') {
  $raw_body = file_get_contents('php://input');
  $payload = json_decode($raw_body, true);
  if (!$payload || empty($payload['sheet']) || empty($payload['range']) || empty($payload['type'])) {
    fail(400, 'body must be {sheet, range, type, ...type-specific fields}');
  }
  $rangeUrl = $base . "/worksheets('" . rawurlencode($payload['sheet']) . "')/range(address='" . $payload['range'] . "')";
  list($code, $data, $raw) = graph_call($rangeUrl . '/conditionalFormats/add', $tokens['access_token'], 'POST', ['type' => $payload['type']]);
  if ($code >= 300) fail(502, 'add conditional format failed', $raw);
  $cfId = $data['id'] ?? null;
  $cfUrl = $rangeUrl . "/conditionalFormats('" . $cfId . "')";

  if ($payload['type'] === 'CellValue' && $cfId) {
    list($c2, $d2, $r2) = graph_call($cfUrl . '/cellValue', $tokens['access_token'], 'PATCH', [
      'rule' => ['formula1' => $payload['formula1'], 'operator' => $payload['operator']],
      'format' => ['fill' => ['color' => $payload['fill_color']], 'font' => ['color' => $payload['font_color'] ?? '#000000']],
    ]);
    if ($c2 >= 300) fail(502, 'cellValue conditional format failed', $r2);
  } elseif ($payload['type'] === 'DataBar' && $cfId) {
    $dbBody = array();
    if (!empty($payload['positive_color'])) $dbBody['positiveFormat'] = ['fillColor' => $payload['positive_color']];
    list($c2, $d2, $r2) = graph_call($cfUrl . '/dataBar', $tokens['access_token'], 'PATCH', $dbBody);
    if ($c2 >= 300) fail(502, 'dataBar conditional format failed', $r2);
  }
  echo json_encode(['conditionalFormat' => $data]);
  exit;
}

if ($action === 'add_image') {
  $raw_body = file_get_contents('php://input');
  $payload = json_decode($raw_body, true);
  if (!$payload || empty($payload['sheet']) || empty($payload['base64'])) {
    fail(400, 'body must be {sheet, base64, left?, top?, width?, height?}');
  }
  $betaBase = str_replace('/v1.0/', '/beta/', $base);
  $url = $betaBase . "/worksheets('" . rawurlencode($payload['sheet']) . "')/images/add";
  $body = ['base64Image' => 'data:image/jpeg;base64,' . $payload['base64']];
  list($code, $data, $raw) = graph_call($url, $tokens['access_token'], 'POST', $body);
  if ($code >= 300) fail(502, 'add image failed', $raw);
  $imgId = $data['id'] ?? null;
  if ($imgId) {
    $updateBody = array();
    if (isset($payload['left'])) $updateBody['left'] = $payload['left'];
    if (isset($payload['top'])) $updateBody['top'] = $payload['top'];
    if (isset($payload['width'])) $updateBody['width'] = $payload['width'];
    if (isset($payload['height'])) $updateBody['height'] = $payload['height'];
    if ($updateBody) {
      graph_call($base . "/worksheets('" . rawurlencode($payload['sheet']) . "')/images('" . $imgId . "')", $tokens['access_token'], 'PATCH', $updateBody);
    }
  }
  echo json_encode(['image' => $data]);
  exit;
}

if ($action === 'set_view_options') {
  $raw_body = file_get_contents('php://input');
  $payload = json_decode($raw_body, true);
  if (!$payload || empty($payload['sheet'])) fail(400, 'body must include {sheet, showGridlines?}');
  $url = $base . "/worksheets('" . rawurlencode($payload['sheet']) . "')";
  $body = array();
  if (isset($payload['showGridlines'])) $body['showGridlines'] = $payload['showGridlines'];
  list($code, $data, $raw) = graph_call($url, $tokens['access_token'], 'PATCH', $body);
  if ($code >= 300) fail(502, 'set view options failed', $raw);
  echo $raw;
  exit;
}

if ($action === 'list_tables') {
  list($code, $data, $raw) = graph_call($base . '/tables', $tokens['access_token']);
  echo $raw;
  exit;
}

if ($action === 'delete_table') {
  $raw_body = file_get_contents('php://input');
  $payload = json_decode($raw_body, true);
  if (!$payload || empty($payload['id'])) fail(400, 'body must be {id}');
  list($code, $data, $raw) = graph_call($base . "/tables('" . $payload['id'] . "')", $tokens['access_token'], 'DELETE');
  echo json_encode(['deleted' => $code < 300, 'detail' => $raw]);
  exit;
}

if ($action === 'sort_range') {
  $raw_body = file_get_contents('php://input');
  $payload = json_decode($raw_body, true);
  if (!$payload || empty($payload['sheet']) || empty($payload['range']) || empty($payload['fields'])) {
    fail(400, 'body must be {sheet, range, fields: [{key, ascending}], has_headers?}');
  }
  $rangeUrl = $base . "/worksheets('" . rawurlencode($payload['sheet']) . "')/range(address='" . $payload['range'] . "')";
  list($code, $data, $raw) = graph_call($rangeUrl . '/sort/apply', $tokens['access_token'], 'POST', [
    'fields' => $payload['fields'],
    'matchCase' => false,
    'hasHeaders' => $payload['has_headers'] ?? true,
  ]);
  if ($code >= 300) fail(502, 'sort failed', $raw);
  echo json_encode(['sorted' => true]);
  exit;
}

if ($action === 'get_range') {
  $sheet = $_GET['sheet'] ?? fail(400, 'missing sheet');
  $range = $_GET['range'] ?? fail(400, 'missing range');
  $url = $base . "/worksheets('" . rawurlencode($sheet) . "')/range(address='" . $range . "')";
  list($code, $data, $raw) = graph_call($url, $tokens['access_token']);
  if ($code >= 300) fail(502, 'get range failed', $raw);
  echo json_encode(['values' => $data['values'] ?? null, 'text' => $data['text'] ?? null]);
  exit;
}

fail(400, 'unknown action');
