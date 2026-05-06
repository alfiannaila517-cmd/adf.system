<?php
$host = 'guangmao.iixcp.rumahweb.net';
$user = 'adfb2574';
$pass = '@Nnoc2026';

$cmd = "/usr/bin/perl -0pi -e 's/\\$stmt->execute\\(\\[\\$today\\]\\);/\\$stmt->execute([\\$today]);\\n    \\$inHouseGuests = \\$stmt->fetchAll(PDO::FETCH_ASSOC);/' /home/adfb2574/public_html/modules/frontdesk/breakfast.php";

$url = 'https://' . $host . ':2083/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Cron&cpanel_jsonapi_func=add_line&minute=*' .
    '&hour=*' .
    '&day=*' .
    '&month=*' .
    '&weekday=*' .
    '&command=' . urlencode($cmd);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP: $http\n";
if ($error) echo "ERR: $error\n";
echo $response;
