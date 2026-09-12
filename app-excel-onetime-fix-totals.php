<?php
// One-off cleanup: clear the stray leftover formulas at AE109:AQ109
// (Hadi Culasy's row — should have no payments yet) and AP111:AQ111
// (older cruft from before he was added), then re-write the correct
// TOTALS formulas at row 110 with the right formula strings so they
// self-heal to accurate values.
$share = 'https://1drv.ms/x/c/d75baed8553a3b22/IQBn7j_IL-uhSo7ErF2LPDcZAcb8dVYl3JuoXOevABBOPoE';
$TOKEN_STORE = __DIR__ . '/ms_tokens.json';
header('Content-Type: application/json');
function fail($code,$msg,$detail=null){http_response_code($code);echo json_encode(['ok'=>false,'error'=>$msg,'detail'=>$detail]);exit;}
$CONFIG_FILE = __DIR__ . '/ms_config.php';
$config = require $CONFIG_FILE;
$CLIENT_ID = $config['client_id']; $CLIENT_SECRET = $config['client_secret'];
$tokens = json_decode(file_get_contents($TOKEN_STORE), true);
function refresh_access_token($tokens, $CLIENT_ID, $CLIENT_SECRET, $TOKEN_STORE) {
  $ch = curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');
  curl_setopt_array($ch, array(CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,
    CURLOPT_POSTFIELDS=>http_build_query(array('client_id'=>$CLIENT_ID,'client_secret'=>$CLIENT_SECRET,
      'refresh_token'=>$tokens['refresh_token'],'grant_type'=>'refresh_token','scope'=>'offline_access Files.ReadWrite User.Read'))));
  $response = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $data = json_decode($response, true);
  if ($code != 200 || empty($data['access_token'])) fail(502,'token refresh failed',$response);
  $new = array('access_token'=>$data['access_token'],'refresh_token'=>!empty($data['refresh_token'])?$data['refresh_token']:$tokens['refresh_token'],'expires_at'=>time()+intval($data['expires_in'])-60);
  file_put_contents($TOKEN_STORE, json_encode($new, JSON_PRETTY_PRINT));
  return $new;
}
if (empty($tokens['access_token']) || time() >= $tokens['expires_at']) $tokens = refresh_access_token($tokens,$CLIENT_ID,$CLIENT_SECRET,$TOKEN_STORE);
function graph_call($url,$accessToken,$method='GET',$body=null){
  $ch=curl_init($url);
  $opts=array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>array('Authorization: Bearer '.$accessToken,'Content-Type: application/json'),CURLOPT_CUSTOMREQUEST=>$method);
  if($body!==null)$opts[CURLOPT_POSTFIELDS]=json_encode($body);
  curl_setopt_array($ch,$opts);
  $resp=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  return array($code,json_decode($resp,true),$resp);
}
$b64 = rtrim(strtr(base64_encode($share), '+/', '-_'), '=');
$shareId = 'u!' . $b64;
list(,$rdata) = graph_call('https://graph.microsoft.com/v1.0/shares/' . $shareId . '/driveItem?$select=id,parentReference', $tokens['access_token']);
$driveId = $rdata['parentReference']['driveId']; $itemId = $rdata['id'];
$base = "https://graph.microsoft.com/v1.0/drives/$driveId/items/$itemId/workbook";
$sheetName = 'Student Database 26-27';

$results = [];
// 1. Clear stray reconciliation formulas on Hadi's row (109) — he has no payments.
list($c1,,$r1) = graph_call($base."/worksheets('".rawurlencode($sheetName)."')/range(address='AE109:AQ109')", $tokens['access_token'], 'PATCH', ['values'=>[array_fill(0,13,'')]]);
$results['clear_109'] = $c1 < 300 ? 'ok' : $r1;

// 2. Clear stray cruft on row 111 (AP111:AQ111 — leftover, not a real row).
list($c2,,$r2) = graph_call($base."/worksheets('".rawurlencode($sheetName)."')/range(address='AP111:AQ111')", $tokens['access_token'], 'PATCH', ['values'=>[['','']]]);
$results['clear_111'] = $c2 < 300 ? 'ok' : $r2;

// 3. Re-affirm the real TOTALS row (110) formulas with the correct range.
$totalsRow = 110; $lastDataRow = 109;
$formulas = [
  'H'=>"=SUM(H2:H{$lastDataRow})",'I'=>"=SUM(I2:I{$lastDataRow})",'J'=>"=SUM(J2:J{$lastDataRow})",'K'=>"=SUM(K2:K{$lastDataRow})",
  'AE'=>"=SUM(AE2:AE{$lastDataRow})",'AI'=>"=SUM(AI2:AI{$lastDataRow})",'AM'=>"=SUM(AM2:AM{$lastDataRow})",
  'AP'=>"=AE{$totalsRow}+AI{$totalsRow}+AM{$totalsRow}",
  'AQ'=>"=IF(ROUND(AP{$totalsRow}-I{$totalsRow},2)=0,\"✓ MATCH\",\"⚠ MISMATCH £\"&TEXT(AP{$totalsRow}-I{$totalsRow},\"0.00\"))",
];
foreach ($formulas as $col => $f) {
  graph_call($base."/worksheets('".rawurlencode($sheetName)."')/range(address='{$col}{$totalsRow}')", $tokens['access_token'], 'PATCH', ['formulas'=>[[$f]]]);
}
$results['totals_row_rewritten'] = $totalsRow;

echo json_encode(['ok'=>true,'results'=>$results]);
