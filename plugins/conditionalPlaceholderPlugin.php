<?php

/**
 * conditionalPlaceholder plugin version 1.0a2
 * 
 * This plugin allows the use of conditional placeholders in PHPlist html and text messages
 * It allows standard placeholders to be used in the subject line of messages, as well
 * as conditional placeholders.
 *
 * For more information about how to use this plugin, see
 * http://resources.phplist.com/plugins/conditionalplaceholder .
 * 
 */

// The config file may be missing because of the failure of the phplist installer to install it.
$configFile = dirname(__FILE__)."/conditionalPlaceholderPlugin/config.php";
if (file_exists($configFile))
	include_once $configFile;

/**
 * Registers the plugin with phplist
 * 
 * @category  phplist
 * @package   conditionalPlaceholderPlugin
 */

class conditionalPlaceholderPlugin extends phplistPlugin
{
    /*
     *  Inherited variables
     */
    public $name = 'Conditional Placeholder Plugin';
    public $version = '1.0a2';
    public $enabled = false;
    public $authors = 'Arnold Lesikar';
    public $description = 'Allows the use of conditional placeholders in messages';
    
    private $brackets = array('[*','*]'); 
    private $keywords = array('IF', 'ELSE', 'ENDIF');
    private $needElse = true;
	private $pif, $pels, $pend;
	private $actionpat; // Pattern for replacing placeholders
	private $user_att_values = array();
	private $attNames = array();
	
	private function loadTemplate($tid)
	{
		if ($tid) {
    		$req = Sql_Fetch_Row_Query("select template from {$GLOBALS['tables']['template']} where id = {$tid}");
    		return stripslashes($req[0]);
    	} else
    		return '';
	}

	// Get names of user attributes in upper case form
	private function loadAttributeNames()
	{
		$attnames = array();
   		$att_table = $GLOBALS['tables']["attribute"];
    	$res = Sql_Query(sprintf('SELECT Name FROM %s', $att_table));
    	while ($row = Sql_Fetch_Row($res))
    		$attnames[] = $row[0];
    		
    	foreach ($attnames as &$aname) 
    		$aname = strtoupper($aname);
    	unset ($aname);  // Not sure if this is needed. The reference should disappear when $attnames goes out of scope.
    	
    	return $attnames;
       
	}
            	
	public function __construct()
    {

        $this->coderoot = dirname(__FILE__) . '/conditionalPlaceholderPlugin/';
        
        // Load syntax parameters
        if (class_exists('cpConfig')) {  // The config file defining the cpConfig class may be missing.
        	$this->brackets = cpConfig::$cpBrackets;
        	foreach ($this->brackets as &$val)
        		$val = trim ($val);
			unset($val);
        	$this->keywords = cpConfig::$cpKeywords;        
    
        	foreach ($this->keywords as &$val)
        		$val = trim ($val);
        	unset ($val);
        	$this->needElse = cpConfig::$explicitElse;
        }
      
        $this->attnames = $this->loadAttributeNames();

       // Bracket the keywords
        $ary = array ();
        foreach ($this->keywords as $key => $val)
        	$ary[$key] = $this->brackets[0] . $val . $this->brackets[1];
        list ($this->pif, $this->pels, $this->pend) = $ary;

        // Build regex pattern for placeholder processing
       $this->actionpat = '@' . preg_quote($this->pif) . '(.*)(?:' . preg_quote($this->pels) . '(.*))?' . preg_quote($this->pend) .'@Ums';
        
       parent::__construct();
    }
    
    // This function returns true if the brackets around the conditional keywords 
    // and placeholders balance -- open and closed. Otherwise it returns false
    // It's a two-state machine.
    private function bracketsBalance($str)
    {
    	$pat = '@(?:' . preg_quote($this->brackets[0]) . ')|(?:' .preg_quote($this->brackets[1]) . ')@m';
    	preg_match_all ($pat, $str, $match);
    	$brckts = $match[0];
    	$n = count($brckts);
    	$state = 0;
    	$ptr = 0;
    	while ($ptr < $n) {
    		if ($brckts[$ptr] == $this->brackets[$state]) {
    			$ptr++;
    			$state++;
    			$state %= 2;
    		}
    		else
    			return false;
    	}
    	return ($state == 0);
    }
    
    private function checkConfig()
    {
    	if (count($this->brackets) != 2)
    		return 'There must be exactly 2 placeholder brackets defined in the config file!';
    	if (($this->brackets[0] == '') || ($this->brackets[1] ==''))
    		return 'Each placeholder bracket must be defined in the config file!';
    	if ($this->brackets[0] == $this->brackets[1])
    		return 'Left and right placeholder brackets must be distinct!';
    	if (count($this->keywords) !=3)
    		return 'There must be exactly 3 keywords defined in the config file!';
    		
    	$badK = false;
    	foreach ($this->keywords as $val) {
    		if ($val == '') {
    			$badK = true;
    			break;
    		}
    	}
    	if ($badK)
    		return 'A keyword cannot be an empty string in the config file!';
    	
    	$test = $this->brackets[0] . 'TEST' . $this->brackets[1];
    	if ($test != strip_tags($test))
    		return 'Placeholder brackets must not conflict with HTML tags!';
    		
    	if ($test != preg_replace('@\[[A-Za-z0-9_ ]+\]@U','',$test))
    		return 'Placeholder brackets must not conflict with standard placeholders!';
    	
    	return '';
    }

    
/* allowMessageToBeQueued
   * called to verify that the message can be added to the queue
   * @param array messagedata - associative array with all data for campaign
   * @return empty string if allowed, or error string containing reason for not allowing
   * 
   * Here is where we check that the conditional placeholders are well formed.
   *
   */

	public function allowMessageToBeQueued($messagedata = array()) {
		$places = array($messagedata['message'], $messagedata['textmessage'], $messagedata['subject'], $this->loadTemplate($messagedata['template']));
  		$placenames = array('message', 'text message', 'subject line', 'template');
  		$pat = '@' . preg_quote($this->brackets[0]) . '(.*)' . preg_quote($this->brackets[1]) . '@Ums';
    	$symbols = array($this->pif, '', $this->pels, $this->pend);
   	
    	// Configuration errors in brackets and keywords are always checked whether
    	// there are placeholders in the message or not.
    	//$res = $this->checkConfig();
    	if ($res)
    		return "Config Error: $res";
    		
  		// Check that the syntax for the conditional placeholders is correct
  		// Do the check with a four-state machine.
  
  		foreach ($places as $key => $str) {
  			if (!$str)
  				continue;
  				
  			// Make sure that we have no open brackets
  			if (!$this->bracketsBalance($str))
  				return "Conditional brackets do not balance in $placenames[$key]!";
  				
  			preg_match_all($pat, $str, $match);
  			$found = $match[0]; 	// The symbols we found
  			$enclosd = $match[1]; 	// What's between the brackets
    		$n = count($found);
  			$state = 0;
  			$ptr = 0;
 			while ($ptr < $n) {
 				$current = $found[$ptr];
  				switch($state) {
  					case 0:  		// Looking of 'if'
  					case 3:			// Looking for 'endif'
  						if ($current == $symbols[$state]) {
  							$ptr++;
  							$state++;
  							$state %= 4;	
  						} else
  							return "Looking for $symbols[$state] but found $current in $placenames[$key]!";
  						break;
  					case 1:			// Looking for one of our placeholders
  						if (in_array($enclosd[$ptr], $this->attnames) === false) 
                            return "Looking for placeholder for a known attribute but found $current in $placenames[$key]!";
                        else {
                        	$ptr++;
                        	$state++;
                        }
                        break;
                    case 2:			// Looking for one of our placeholders or an 'else'
                    	if ($current == $symbols[$state]) { 	//
  							$ptr++;
  							$state++;  	// Have 'else'. Look for 'endif'
  						} elseif (in_array($enclosd[$ptr], $this->attnames) !== false)
  							$ptr++;		// Continue in same state until we find the 'else'
  						elseif ((!$this->needElse) && ($current == $symbols[$state + 1])) {
  							$ptr++;		// Don't need 'else'; found 'endif'
  							$state = 0; 
  						} else 
  							return "Looking for placeholder for a known attribute or $symbols[$state] but found $current in $placenames[$key]!";					
  				}
  			} 
  			if ($state != 0)
  				return "Last found $current, but ran out of text before completing conditional expression in $placenames[$key]!"; 		
  		} 
 		return '';
  }

 /* setFinalDestinationEmail
  * purpose: change the actual recipient based on user Attribute values:
  * parameters: 
  * messageid: message being sent 
  * uservalues: array of "attributename" => "attributevalue" of all user attributes
  * email: email that this message is current set to go out to
  * returns: email that it should go out to
  *
  * This is where we can grab user attribute values for evaluating placeholders in 
  * the subject line. Place holders in the body of the message are already evaluated
  * by PHP list. 
  *
  * Fortunately this is called just before we have to use these values to parse
  * conditional placeholders.
  * 
 */
  
	public function setFinalDestinationEmail($messageid, $uservalues, $email) { 
		$this->user_att_values = $uservalues;
    	return $email;
 	 }
	 
 	// This private function does the work of replacing the conditional placeholder with the
 	// proper string.
 	 private function parseOutgoingMessage($content)
 	 {
 	 	if ((!$content) || (strpos($content, $this->brackets[0]) === false))
 	 		return $content;
 	 	
 	 	$atts = array();
 	 	// Stolen from phplist parsePlaceHolders function
 	 	## the editor turns all non-ascii chars into the html equivalent so do that as well
  		foreach ($this->user_att_values as $key => $val) {
   		 	$atts[strtoupper($key)] = $val;
    		$atts[htmlentities(strtoupper($key),ENT_QUOTES,'UTF-8')] = $val;
    		$atts[str_ireplace(' ','&nbsp;',strtoupper($key))] = $val;
  		}

 	 	preg_match_all($this->actionpat, $content, $match);
		$holders = $match[0];  // array of [*IF*] ... [*ENDIF*] strings
		$orig = $match[1]; // Array of 'original' strings containing conditional placeholders
		$repl = $match[2]; // Array of substitute strings
		
  		foreach ($holders as $key => $val) { // For each [*IF*] string
  			$str = $orig[$key];
  			// Substitute values for the conditional place holder
  			foreach ($atts as $k2 => $v2) {
  				$pat = $this->brackets[0] . $k2 .  $this->brackets[1];
      			if (stripos($str, $pat) !== false) { // found one?   
      			    $novalue = empty($v2);
      			    if ($novalue)  // Oops, no value for this one, quit looking
      			    	break;
        			$str = str_ireplace($pat,$v2,$str);
      			}
      		}
     		if ($novalue)
     			$replacement = $repl[$key];
			else
				$replacement = $str;
			$len = strlen($val);
			$pos = strpos($content, $val);
			$content = substr_replace($content, $replacement, $pos, $len); // Can't use str_replace here because of the possibility of multiple replacements. We must do only one at a time.
  		}
		return $content; 
 	 }
   
    /* 
   * parseOutgoingTextMessage
   * @param integer messageid: ID of the message
   * @param string  content: entire text content of a message going out
   * @param string  destination: destination email
   * @param array   userdata: associative array with data about user
   * @return string parsed content
   */
	public function parseOutgoingTextMessage($messageid, $content, $destination, $userdata = null) {
		return $this->parseOutgoingMessage($content);
 	 }

  /* 
   * parseOutgoingHTMLMessage
   * @param integer messageid: ID of the message
   * @param string  content: entire text content of a message going out
   * @param string  destination: destination email
   * @param array   userdata: associative array with data about user
   * @return string parsed content
   */
	public function parseOutgoingHTMLMessage($messageid, $content, $destination, $userdata = null) {
    	return $this->parseOutgoingMessage($content);
  	}
 
  /* messageHeaders  -- The original purpose of this function is:
   *
   * return headers for the message to be added, as "key => val"
   *
   * @param object $mail
   * @return array (headeritem => headervalue)
   *
   *
   * This is the last point at which we can reach into the queue processing and
   * modify the subject line.
   *
 */
  
  public function messageHeaders($mail)
  {
  	$mail->Subject = parsePlaceHolders($mail->Subject, $this->user_att_values);
  	$mail->Subject = $this->parseOutgoingMessage($mail->Subject);
    return array(); //@@@
  }

}
  