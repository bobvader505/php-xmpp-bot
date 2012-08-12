#!/usr/bin/php -q
<?php

$argv = &$_SERVER['argv'];
$args = $argv[1];
$args = json_decode(base64_decode($args));

$ch = curl_init("http://board.raidrush.ws/search.php?do=process");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

$query = 's=1&do=process&sortby=lastpost&order=descending&query=';
$query .= urlencode($args[0]);

curl_setopt($ch, CURLOPT_POSTFIELDS, $query);

$body = curl_exec($ch);
curl_close($ch);

if (($pos = stripos($body, ' id="thread_title_')) === false)
  exit(':nothing found');

$thdid = substr($body, $pos + 18);
$thdid = substr($thdid, 0, strpos($thdid, '"'));

$title = substr($body, $pos + 18);
$title = substr($title, 0, strpos($title, '<'));
$title = substr($title, strpos($title, '>') + 1);
$title = utf8_encode($title);
$title = html_entity_decode($title, ENT_COMPAT | ENT_HTML401, 'UTF-8');

exit(":$title -> http://board.raidrush.ws/showthread.php?t=$thdid");
