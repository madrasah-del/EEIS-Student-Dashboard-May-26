<?php
// EEIS Excel proxy — fetches the live OneDrive workbook (view-only share link)
// so the app can auto-sync without pickers or sign-ins. Same-origin, so no CORS.
$share = 'https://1drv.ms/x/c/d75baed8553a3b22/IQBn7j_IL-uhSo7ErF2LPDcZAcb8dVYl3JuoXOevABBOPoE';
$debug = isset($_GET['debug']);
$jar = tempnam(sys_get_temp_dir(), 'eeisck');

function fetch_url($url, $jar, $extra_headers = array()) {
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 15,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER => array_merge(array(
      'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'Accept-Language: en-GB,en;q=0.9',
    ), $extra_headers),
    CURLOPT_TIMEOUT => 90,
  ));
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
  curl_close($ch);
  return array($code, $body, $final);
}

function fail($msg, $detail, $debug) {
  http_response_code(502);
  header('Content-Type: text/plain');
  echo $msg;
  if ($debug) echo "\n--- debug ---\n" . $detail;
  exit;
}

// Step 1: load the share page — cookies from the redirect chain are needed
list($code, $html, $final) = fetch_url($share, $jar);
if ($code != 200 || !$html) fail('share page failed (' . $code . ')', 'final url: ' . $final, $debug);

$url = null;
// Preferred: fresh tokenised download URL embedded in the page
if (preg_match('/"FileGetUrl":"([^"]+)"/', $html, $m)) {
  $url = json_decode('"' . $m[1] . '"');
}
// Fallback: any download.aspx URL in the page (works with session cookies)
if (!$url && preg_match('#https://[a-z0-9.]+/personal/[a-z0-9]+/_layouts/15/download\.aspx\?[^"\'<>\s]+#i', $html, $m)) {
  $url = json_decode('"' . str_replace('"', '', $m[0]) . '"');
  $url = html_entity_decode(str_replace('&', '&', $url));
}
if (!$url) {
  fail('download url not found in share page',
    'page length: ' . strlen($html) . "\nfinal url: " . $final .
    "\nhas FileGetUrl: " . (strpos($html, 'FileGetUrl') !== false ? 'yes' : 'no') .
    "\nhas download.aspx: " . (strpos($html, 'download.aspx') !== false ? 'yes' : 'no') .
    "\nfirst 400 chars:\n" . substr(strip_tags($html), 0, 400), $debug);
}

// Step 2: download the workbook (same cookie jar)
list($code, $data, $final2) = fetch_url($url, $jar);
if ($code != 200 || !$data || substr($data, 0, 2) !== 'PK') {
  fail('workbook download failed (' . $code . ')', 'url used: ' . substr($url, 0, 120) . "\nbody starts: " . substr($data, 0, 200), $debug);
}

@unlink($jar);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Cache-Control: no-store');
header('Content-Length: ' . strlen($data));
echo $data;
