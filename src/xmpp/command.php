<?php

namespace xbot\xmpp;

use xbot\xmpp\Client;

class Command 
{
  // basic informations
  public $from, $body, $type, $room = null;
  
  // trigger and parsed arguments
  public $name, $args = [];
  
  protected $xbot;
  
  /**
   * constructor
   * 
   * @param Client $bot
   * @param array  $msg
   */
  public function __construct(Client $xbot, array $msg)
  {
    $this->xbot = &$xbot;
    
    $this->from = $msg['from'];
    $this->body = $msg['body'];
    $this->type = $msg['type'];
    
    if ($this->type === 'groupchat')
      // make sure response goes to the groupchat
      $this->room = substr($this->from, 0, strrpos($this->from, '/'));
    
    // parse body
    $this->parse();
  }
  
  /**
   * parses the message-body
   * 
   * @void
   */
  protected function parse()
  {
    list ($this->name, $rest) = explode(' ', $this->body . ' ', 2);
    $this->name = basename($this->name); // because fuck you, thats why.
    
    if (($len = strlen($rest = trim($rest))) === 0)
      return;
    
    // parse args
    $str = false; 
    $buf = '';
    
    for ($i = 0; $i < $len; ++$i) {
      $chr = $rest[$i];
      
      // § if a string-literal is open
      if ($str === true) {
        // § if the next char is a quotation mark
        if ($chr === '"') {
          // § close the string-literal and wait for a whitespace char, quotation mark or EOF
          $str = false;
          continue;
        }
        
        // § append char to buffer and continue with next char
        $buf .= $chr;
        continue;
      }
      
      // § if a string-literal is not open and the next char is a quotation mark
      if ($chr === '"') {
        // § open string-literal
        $str = true;
        
        // § if the current buffer is not empty
        if (!empty($buf)) {
          // § push it onto the stack
          $this->args[] = $buf;
          $buf = '';
        }
        
        // § continue with next char
        continue;
      }
      
      // § if a string-literal is not open and the next char is a whitespace char
      if ($chr === ' ') {
        // § if the current buffer is not empty
        if (!empty($buf)) {
          // § push it onto the stack
          $this->args[] = $buf;
          $buf = '';
        }
        
        // § continue with next char
        continue;
      }
      
      // § if a string-literal is not open and next char is not a whitespace char
      // § add it to the current buffer
      $buf .= $chr;
    }
    
    // § if EOF is reached and buffer is not empty
    if (!empty($buf))
      // § append it onto the stack
      $this->args[] = $buf;
    
    // § done
  }
  
  /**
   * respond to message
   * 
   * @param  string $text
   * @return Message
   */
  public function respond($text) 
  {
    $to = $this->room ?: $this->from;
    
    // forward to xbot
    $this->xbot->send($this->room ?: $this->from, $text, $this->type);
    return $this;
  }
  
  /**
   * loads and executes the plugin
   * 
   * @void
   */
  public function execute()
  {
    $exec = $this->name;
    $exec = str_replace('-', '_', $exec);
    $exec = __DIR__ . '/../plugins/' . $exec . '.php';
    
    if (!file_exists($exec)) return false;
    print "executing $exec\n";
    
    $args = base64_encode(json_encode($this->args));
    $resp = `php -q -f $exec -- "$args"`;
    
    if (strlen($resp) && $resp[0] === ':') 
      $this->respond(substr($resp, 1));
  }
}
