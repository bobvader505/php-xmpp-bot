#!/usr/bin/php
<?php

namespace xbot;

use xbot\xmpp\Client;
use xbot\xmpp\Message;

// define your stuff here
$nick = '~rrbot';
$user = 'quote2';
$pass = 'testing-1234';
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
  ->on('trigger:muc', 'xbot\handle_trigger_muc') // trigger in multi user chat
  ->on('trigger:prv', 'xbot\handle_trigger_prv') // trigger in private message
  ->join('#rr-coding@conference.jabber.ccc.de')
  ->listen();

// --------------------------------

function handle_trigger_muc(Message $msg, Client $bot) {
  if ($msg->exec) $msg->execute();
}

function handle_trigger_prv(Message $msg, Client $bot) {
  
}
