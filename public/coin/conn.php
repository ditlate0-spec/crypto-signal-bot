<?php
// conn.php - используем require_once для предотвращения повторных объявлений

$BASE_URL = 'https://api.binance.com/';

function sendRequest($path) {
    global $BASE_URL;
    $url = $BASE_URL . $path;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}

function signature($query_string, $secret) { return ''; }
function signedRequest($method, $path, $parameters = []) { return []; }
function buildQuery(array $params) { return ''; }
?>