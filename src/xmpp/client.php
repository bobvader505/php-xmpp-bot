<?php

namespace xbot\xmpp;

use \Roster;
use \XMPPHP_Log;
use \XMPPHP_XMPP;
use \XMPPHP_BOSH;
use \XMPPHP_XMLStream;
use \XMPPHP_Exception;

require_once __DIR__ . '/XMPPHP/XMPP.php';
require_once 'command.php';

class Client
{
  // borrowed from XMPPHP/XMPPHP_Log.php
  const LOGLEVEL_ERROR   = 0,
        LOGLEVEL_WARNING = 1,
        LOGLEVEL_INFO    = 2,
        LOGLEVEL_DEBUG   = 3,
        LOGLEVEL_VERBOSE = 4;
  
  // XMPPHP_XMPP instance
  protected $xmpp, $info;
  
  // action-trigger
  protected $trigger = [ 'muc' => '', 'prv' => '' ];
  
  // event-handler
  protected $events = [ 'xmpphp' => [], 'custom' => [] ];
  
  // auto-handled messagetypes
  protected $handle = [];
  
  // bot-nickname
  protected $nick;
  
  // ready for actions
  protected $ready = false;
  
  // joined MUCs
  protected $mucs = [];
  
  /**
   * constructor
   * 
   * @param array $info
   */
  public function __construct(array $info)
  {
    assert(!empty($info['serv']));
    assert(!empty($info['user']));
    assert(!empty($info['pass']));
    assert(!empty($info['auth']));
    
    if (!isset($info['port']))
      if (false !== strpos($info['serv'], ':'))
        // extract port-info from serv
        list ($info['serv'], $info['port']) = explode(':', $info['serv'], 2);
      else
        // use default
        $info['port'] = 5222;
    
    $info += [
      'nick'     => $info['user'],
      'printlog' => false, 
      'loglevel' => self::LOGLEVEL_INFO 
    ];
    
    // create xmpp-instance
    $this->xmpp = new XMPPHP_XMPP(
      $info['serv'], $info['port'], 
      $info['user'], $info['pass'], 
      $info['nick'], $info['auth'], 
      $info['printlog'], 
      $info['loglevel']
    );
    
    // setup trigger
    if (isset($info['trigger']))
      if (!is_array($trg = $info['trigger']))
        // assign $trg to both "muc" and "prv"
        $this->trigger = [ 'muc' => $trg, 'prv' => $trg ];
      else
        // extend default trigger
        $this->trigger = $trg + $this->trigger;
      
    $this->nick = $info['nick'];
    $this->info = $info;
  }
  
  /**
   * connects to the given xmpp-server and starts the "main-loop"
   * 
   * @void
   */
  public function connect()
  {
    try {
      $this->xmpp->connect();
      
      // okay, looks like it worked
      return true;
    } catch (Exception $e) {
      // connection error
      return false;
    }
  }
  
  /**
   * sets auto-handled triggers
   * 
   * @param  array  $trigger
   * @return Client
   */
  public function handle(array $trigger)
  {
    $this->handle = $trigger;
    return $this;
  }
  
  /**
   * disconnects the bot
   * 
   * @return Client
   */
  public function quit($msg = null)
  {
    // TODO: send offline message
    
    $this->xmpp->disconnect();
    return $this;
  }
  
  /**
   * starts the main-loop
   * 
   * @void
   */
  public function listen()
  {
    $this->xmpp->getRoster();
    $this->xmpp->autoSubscribe();
    $this->xmpp->presence();
    
    // init plugin-server
    // $server = socket_create(AF_INET, SOCK_STREAM, 0);
    // socket_bind($server, '127.0.0.1', 56667);
    // socket_set_nonblock($server);
    // socket_listen($server);
    
    while (!$this->xmpp->isDisconnected()) {   
      $events = array_merge(
        // xmpphp events
        array_keys($this->events['xmpphp']),
        
        // internal events
        [ 'message', 'session_start', 'presence' ]
      );
      
      $paylds = $this->xmpp->processUntil($events);
      
      foreach ($paylds as $event) {
        $type = $event[0];
        $args = $event[1];
        
        // fire callbacks first
        $this->fire($type, $args);
        
        // handle internal events
        switch ($type) {
          case 'message':
            $this->message($args);
            break;
            
          case 'presence':
            $this->presence($args);
            break;
            
          case 'session_start':
            $this->ready = true;
            $this->xmpp->presence();
            sleep(1);
            
          // no default
        }
      } 
      
      // check if anything happend @ plugin-server
      // $this->recv($server);   
    }
    
    // socket_close($server);
  }
  
  /**
   * handles plugin sockets for ipc
   * 
   * @param  resource $server
   * @void
   */
  protected function recv($server)
  {
    $r = [ $server ];
    $w = [];
    $e = [];
    
    $buf = '';
    
    while (socket_select($r, $w, $e, 0) === 1) {
      $cnk = socket_read($server, 4096, PHP_BINARY_READ);
      
      if ($cnk === false) exit('plugin server error');
      if ($cnk === '') break;
      
      $buf .= $cnk;  
    }
    
    if ($buf === '') return; // nothing to do
    
    $res = json_decode($buf, true);
    unset($buf);
    
    if ($res === null) {
      $this->xmpp->getLog()->log('Some plugin returned invalid JSON data');
      return;
    }
    
    print_r($res);
  }
  
  /**
   * fires an event
   * 
   * @param  string $name
   * @param  mixed  $args 
   * @void
   */
  protected function fire($name, $args = null)
  {
    $type = strpos($name, ':') !== false 
      ? 'custom' : 'xmpphp';
    
    if (!isset($this->events[$type][$name]))
      return;
    
    foreach ($this->events[$type][$name] as $callback)
      if (false === $callback($args, $this))
        break;
  }
  
  /**
   * handles messages
   * 
   * @param  array  $args
   * @void
   */
  protected function message(array $args = null)
  {
    if (is_null($args)) return;
    
    if ($args['xml']->sub('delay') !== null)
      return; // ignore delayed messages
    
    // check if the message is from this bot
    $nick = substr($args['from'], ($pos = strrpos($args['from'], '/')) + 1);
    $room = substr($args['from'], 0, $pos);
    
    if (isset($this->mucs[$room]) && $this->mucs[$room]['nick'] === $nick)
      return; // ignore own messages
    
    switch ($args['type']) {
      case 'groupchat':
        $pre = $this->trigger[$suf = 'muc'];
        break;
        
      case 'chat':
        $pre = $this->trigger[$suf = 'prv'];
        break;
        
      default:
        return;
    }
    
    $body = $args['body'];
    
    if (($len = strlen($pre)) > 0 && substr($body, 0, $len) !== $pre) {
      if ($suf === 'muc' && (
        ($pos = stripos($body, 'http://')) !== false || 
        ($pos = stripos($body, 'https://')) !== false)
      ) {
        // handle link in message
        $link = substr($body, $pos);
        
        if (($pos = strpos($link, ' ')) !== false)
          $link = substr($link, 0, $pos);
        
        $body = "$pre link_info $link";
      } else return; // don't handle message
    }
    
    // remove trigger from message
    if ($len) $body = trim(substr($body, $len));
    
    // construct message and load handler
    $cmd = new Command($this, [
      'body' => $body, 
      'from' => $args['from'],
      'type' => $args['type']
    ]);
    
    if (in_array($suf, $this->handle) && !empty($cmd->name))
      $cmd->execute();
    
    // fire custom event
    $this->fire('trigger:' . $suf, $cmd, $this);
  }
  
  /**
   * handle presence
   * 
   * @param  array $args
   * @void
   */
  protected function presence(array $args = null)
  {
    if (is_null($args)) return;
    
    if ($args['type'] === 'available') {
      $nick = substr($args['from'], ($pos = strrpos($args['from'], '/')) + 1);
      $room = substr($args['from'], 0, $pos);
      
      if ($args['show'] === $nick) {
        // info about myself
        if (!isset($this->mucs[$room]) || $this->mucs[$room]['nick'] !== $nick)
          return; // not in room?!
        
        if (($item = $args['xml']->sub('x')->sub('item')) === null) 
          return; // weird
      
        $this->mucs[$room]['role'] = $item->attrs['role'];
        $this->xmpp->getLog()->log("Role in $room is now {$this->mucs[$room]['role']}");
      }
    }
  }
  
  /**
   * sends a message
   * 
   * @param  string $to
   * @param  string $msg
   * @param  string $type
   * @return Client
   */
  public function send($to, $msg, $type)
  {
    $this->xmpp->message($to, $msg, $type);
    return $this;
  }
  
  /**
   * joins a muc (groupchat)
   * 
   * @param  string $muc
   * @return Client
   */
  public function join($muc, $nick = null)
  {
    if (isset($this->mucs[$muc]))
      return $this;
    
    $nick = $nick ?: $this->nick;
    
    $fnc = function() use($muc, $nick) { 
      $this->xmpp->presence(null, $nick, "$muc/{$nick}", 'available');
      $this->mucs[$muc] = [ 'nick' => $nick, 'role' => 'guest' ];
      
      return true;
    };
    
    if ($this->ready === true) {
      // bot is ready
      $fnc();
      return $this;
    }
    
    // wait for "session_start"
    return $this->on('session_start', $fnc);
  }
  
  public function kick($muc, $nick, $msg)
  {
    if (!isset($this->mucs[$muc]) || $this->mucs[$muc]['role'] !== 'moderator')
      return;
    
    $req = '<iq from="' . $this->info['user'] . '@' . $this->info['auth'] . '/' . $this->nick . '" ';
    $req .= 'id=kick1" type="set" to="' . $muc . '"><query xmlns="http://jabber.org/protocol/muc#admin">';
    $req .= '<item nick="' . $nick . '" role="none"><reason>' . $msg . '</reason></item></query></iq>';
    
    $this->xmpp->send($req);
  }
  
  /**
   * leaves a muc
   * 
   * @param  string $muc
   * @return Client
   */
  public function leave($muc, $nick = null)
  {
    if (!isset($this->mucs[$muc]))
      return $this;
    
    $nick = $nick ?: $this->nick;
    
    $this->xmpp->presence(null, $nick, "$muc/{$nick}", 'unavailable');
    unset($this->mucs[$muc]);
    
    return $this;
  }
  
  /**
   * adds an event-handler
   * 
   * @param  string   $name
   * @param  callable $callback
   * @return Client
   */
  public function on($name, callable $callback)
  {
    $type = strpos($name, ':') !== false 
      ? 'custom' : 'xmpphp';
    
    if (!isset($this->events[$type][$name]))
      $this->events[$type][$name] = [];
    
    $this->events[$type][$name][] = $callback;
    return $this;
  }
  
  /**
   * removes an event-handler
   * 
   * @param  string   $name    
   * @param  callable $callback 
   * @return Client
   */
  public function off($name, callable $callback = null)
  {
    $type = strpos($name, ':') !== false 
      ? 'custom' : 'xmpphp';
    
    if (!isset($this->events[$type][$name]))
      return $this;
    
    if ($l = count($this->events[$type][$name]) === 0)
      return $this;
    
    for ($i = 0; $i < $l; ++$i)
      if ($this->events[$type][$name][$i] === $callback)
        array_splice($this->events[$type][$name], $i, 1);
      
    return $this;
  }
}

