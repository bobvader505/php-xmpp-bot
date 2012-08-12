#!/usr/bin/php
<?php

namespace xbot;

use xbot\xmpp\Client;
use xbot\xmpp\Message;

// define your stuff here
$nick = '~rrbot';
$user = 'quote2';
$pass = file_get_contents(__DIR__ . '/pass');
$auth = 'jabber.ccc.de';
$serv = 'jabber.ccc.de:5222';
      
require 'xmpp/assert.php'; // optional: you can define your own assert-setup right here if you want
require 'xmpp/client.php';

$xmpp = new Client([
  // server/auth info
  'serv' => $serv, 
  'user' => $user, 
  'pass' => $pass, 
  'nick' => $nick, 
  'auth' => $auth,
  
  // logging
  'loglevel' => Client::LOGLEVEL_INFO,
  'printlog' => true,
  
  // trigger: muc = multi user chat, prv = private messages
  'trigger' => [ 'muc' => '#!', 'prv' => '' ]
]);

if ($xmpp->connect() !== true)
  exit('unable to connect (see log)');

$xmpp
  ->join('#rr-coding@conference.jabber.ccc.de')
  ->handle([ 'muc', 'prv' ])
  ->listen();
  
