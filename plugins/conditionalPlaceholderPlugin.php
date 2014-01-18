<?php

/**
 * conditionalPlaceholder plugin version 1.0a2
 * 
 * This plugin allows the use of conditional placeholders in PHPlist html and text messages
 * It allows standard placeholders to be used in the subject line of messages, as well
 * as conditional placeholders.
 *
 * The standard placeholder is the attribute name in uppercase and enclosed in square brackets.
 * A conditional placeholder is the same, except that it is enclosed in 'star brackets,'
 * for example: [*NAME*].
 *
 * The following syntax allows an alternative string to replace the string containing
 * the placeholder, in case the placeholder attribute has no value:
 *
 * [*IF*]This is a string for user [*FIRSTNAME*] [*LASTNAME*] [*ELSE*]Here is the alternate string [*ENDIF*].
 *
 * If either of the conditional placeholders in the first string is without a value, the 
 * alternate string replaces it. In any case, the [*IF*], [*ELSE*], and [*ENDIF*] tags are 
 * removed. If you want to have an empty alternate string, you MUST put the [*ELSE*]
 * adjacent to [*ENDIF*]. This is a change from the initial release, because if the
 * [*ELSE*] were to be omitted accidentally, the earlier version of the plugin would not have caught the error.
 * The point is that if you want an empty alternate string, you must explicitly declare it
 * to be empty by entering nothing between the [*ELSE*] and the [*ENDIF*]. However, if 
 * you want the former behavior, you can set 'explicitElse' in the config file to false.
 * This is not recommended. 
 * 
 * The presence or absence of a value for any standard placeholders in the first string
 * or the alternate string have no effect on whether the first string or the alternate
 * string appears in the message. Which string appears is determined solely by 
 * the existence or absence of a value for the conditional placeholders in the first
 * string.
 * 
 * The [*IF*]...[*ELSE*]...[*ENDIF*] construction must be well-formed, or the message
 * will not be queued. Remember the appearance of the [*ELSE*] with an alternate string is
 * NOT optional now. A conditional placeholder MUST appear inside a string preceded by an [*IF*]
 * tag. Further the first string, preceded by [*IF*] and terminated by [*ELSE*] or
 * must contain at least one conditional placeholder.
 *
 * Every star bracket conditional placeholder must actually contain a user attribute name.
 * If what is inside any such placeholder is not a user attribute name in upper case,
 * the message will not be queued.
 *
 * The syntax is configurable. If you want different bracketing other than '[*' and 
 * '*]' you can change it in the config file. If you want other keywords other than
 * 'IF', 'ELSE' and 'ENDIF', you can also change them in the config file.
 * 
 */

require_once dirname(__FILE__)."/conditionalPlaceholderPlugin/config.php";

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
    public $enabled = true;
    public $authors = 'Arnold Lesikar';
    public $description = 'Allows the use of conditional placeholders in messages';
    
    private $brackets = array(); 
    private $keywords = array();
	private $pif, $pels, $pend;
	private $checkpat,  $actionpat; // Patterns to for syntax checking and processing placeholders
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
        $this->brackets = cpConfig::cpBrackets;
        foreach ($this->brackets as &$val)
        	$val = trim ($val);
        $this->keywords = cpConfig::cpKeywords;
        foreach ($this->keywords as &$val)
        	$val = trim ($val);
        $this->needElse = cpConfig::explicitElse;
        
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
    
    public function checkConfig()
    {
    	if ((count($this->brackets) != 2) || ($this->brackets[0] == '') || ($this->brackets[1] ==''))
    		return 'Bracket definitions cannot be empty nor more than 2!';
    	if ($this->brackets[0] == $this->brackets[1])
    		return 'Left and right placeholder brackets must be distinct!';
    		
    	$badK = false;
    	foreach ($this->keywords as $val) {
    		if ($val = '') {
    			$badK = true;
    			break;
    		}
    	}
    	
    	if ($badK || (count($this->keywords) !=3))
    		return 'There must be exactly 3 non-empty keywords';
    		
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
    	$res = checkConfig();
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
  				switch($state) {
  					case 0:  		// Looking of 'if'
  					case 3:			// Looking for 'endif'
  						if ($found[$ptr] == $symbols[$state]) {
  							$ptr++;
  							$state++;
  							$state %= 4;	
  						} else
  							return "Looking for $symbols[$state] but found $found[$ptr] in $placenames[$key]!";
  						break;
  					case 1:			// Looking for one of our placeholders
  						if (in_array($enclosd[$ptr], $this->attnames) === false) 
                            return "Looking for placeholder for a known attribute but found $found[$ptr] in $placenames[$key]!";
                        else {
                        	$ptr++;
                        	$state++;
                        }
                        break;
                    case 2:			// Looking for one of our placeholders or an 'else'
                    	if ($found[$ptr] == $symbols[$state]) { 	//
  							$ptr++;
  							$state++;  	// Have 'else'. Look for 'endif'
  						} elseif (in_array($enclosd[$ptr], $this->attnames) !== false)
  							$ptr++;		// Continue in same state until we find the 'else'
  						elseif ((!$this->needElse) && ($found[$ptr] == $symbols[$state + 1])) {
  							$ptr++;		// Don't need 'else'; found 'endif'
  							$state = 0; 
  						} else 
  							return "Looking for placeholder for a known attribute or $symbols[$state] but found $found[$ptr] in $placenames[$key]!";
  					
  				}
  			}
  			if ($state != 0)
  				return "Last found $found[$ptr], but ran out of text before completing conditional expression in $placenames[$key]!"; 		
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
      			    if ($novalue)  // Oops, no value for this one
      			    	break;
        			$str = str_ireplace($pat,$v2,$str);
        			break;			// The loop is done when we've found the attribute
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
  