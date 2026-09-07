<?php
// TEMPLATE — copy this file to ms_config.php on the server (same folder,
// public_html/madrasah/) and fill in the real values below. ms_config.php
// is listed in .gitignore and must NEVER be committed to git — it holds
// a live credential for the EEIS OneDrive account.
return [
    'client_id'     => 'fdbed784-5a7c-4012-ac0f-50a8c75c39fc',
    'client_secret' => 'PASTE-THE-REAL-CLIENT-SECRET-HERE',
    'redirect_uri'  => 'https://madrasah.eeis.store/oauth-callback.php',
];
