<?php
// EEIS Excel proxy — fetches the live OneDrive workbook (view-only share link)
// so the app can auto-sync without pickers or sign-ins. Same-origin, so no CORS.
$share = 'https://1drv.ms/x/c/d75baed8553a3b22/IQBn7j_IL-uhSo7ErF2LPDcZAcb8dVYl3JuoXOevABBOPoE';

function fetch_url($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
    CURLOPT_TIMEOUT => 90,
  ));
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return array($code, $body);
}

// Step 1: load the share page — it embeds a fresh short-lived download URL
list($code, $html) = fetch_url($share);
if ($code != 200 || !$html) { http_response_code(502); exit('share page failed (' . $code . ')'); }

if (!preg_match('/"FileGetUrl":"([^"]+)"/', $html, $m)) {
  http_response_code(502); exit('download url not found in share page');
}
$url = json_decode('"' . $m[1] . '"'); // decodes & etc.

// Step 2: download the workbook
list($code, $data) = fetch_url($url);
if ($code != 200 || !$data || substr($data, 0, 2) !== 'PK') {
  http_response_code(502); exit('workbook download failed (' . $code . ')');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Cache-Control: no-store');
header('Content-Length: ' . strlen($data));
echo $data;
