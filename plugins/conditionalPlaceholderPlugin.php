<?php

/**
 * conditionalPlaceholder plugin version 1.0a1
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
 * removed. If the [*ELSE*] clause is missing, the alternate string is taken to be the
 * empty string.
 * 
 * The presence or absence of a value for any standard placeholders in the first string
 * or the alternate string have no effect on whether the first string or the alternate
 * string appears in the message. Which string appears is determined solely by 
 * the existence or absence of a value for the conditional placeholders in the first
 * string.
 * 
 * The [*IF*]...[*ELSE*]...[*ENDIF*] construction must be well-formed, or the message
 * will not be queued. Remember the appearance of the [*ELSE*] with an alternate string is
 * optional. A conditional placeholder MUST appear inside a string preceded by an [*IF*]
 * tag. Further the first string, preceded by [*IF*] and terminated by [*ELSE*] or
 * [*ENDIF*] must contain at least one conditional placeholder.
 *
 * Every star bracket conditional placeholder must actually contain a user attribute name.
 * If what is inside any such placeholder is not a user attribute name in upper case,
 * the message will not be queued.
 * 
 */

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
    public $version = '1.0a1';
    public $enabled = true;
    public $authors = 'Arnold Lesikar';
    public $description = 'Allows the use of conditional placeholders in messages';
    
    private $symbols = array ('[*IF*]', '[*ELSE*]', '[*ENDIF*]');
	private $brackets = array('[*', '', '*]');
	private $brackpat;
	private $sympat;
	private $user_att_values = array();
	private $attNames = array();
	
	private function buildPattern ($syms) { // build a regex pattern to find the elements of $syms in a string
		$pat = '';
		foreach ($syms as $val) {
			$pat .= preg_quote($val);
			if ($val)
				$pat .= '|';
		}
		return substr($pat, 0, -1);
    } 
    
/* Check the tag syntax with a three state machine.
 * $tags is the sequential array of symbols picked out from the text with a 
 * regular expression. The symbols should alternate: 'if', 'else', 'endif'..
 * 'if', 'else', 'endif'. The 'endif' symbol must be the last in the array. The
 * 'if' symbol must be the first in the array. The 'else' symbol is always
 * optional between 'if' and 'endif'
 *
 * This machine also can check that all star brackets are closed.
 * 
 * Returns Boolean true if everything OK. Otherwise, return the index of the incorrect 
 * tag in the $tags array
 */
	private function checkSyntax($tags, $symbols) {
		$n = count($tags);
		$state = 0;
		$ptr = 0;
		$error = false;
		while ($ptr < $n) {
			if ($symbols[$state] == $tags[$ptr]) {  // Correct tag?
				$state++;							// Go to next state
				$state %= 3;   						// States; 0, 1, or 2
				$ptr++;								// Ready for the next tag
			} 
			else if (($state == 1) && ($symbols[2] == $tags[$ptr])){  // 'Else' tag is optional; could hit 'endif' tag instead
				$state = 0;							// If it's an 'endif' tag, go back to starting state
				$ptr++;								// Ready for the next tag
			}
			else {									// Wrong tag for this state
				$error = true;
				break;
			}
		}
	
		if ($error)				// If we had an error, return the index of the incorrect tag
			return $ptr;
		elseif ($state <> 0)    // $test not closed with $end
			return --$ptr;		// $ptr was incremented to point to nonexistent next tag
		else
			return true;		// If everything OK, return a Boolean value
	}
	
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
        
        $this->brackpat = $this->buildPattern($this->brackets);
        $this->sympat = $this->buildPattern($this->symbols);
        
        $this->attnames = $this->loadAttributeNames();
        
	  	parent::__construct();
    }
    
/* allowMessageToBeQueued
   * called to verify that the message can be added to the queue
   * @param array messagedata - associative array with all data for campaign
   * @return empty string if allowed, or error string containing reason for not allowing
   * 
   * Here is where we check that the conditional placeholders are well formed.
   */
	public function allowMessageToBeQueued($messagedata = array()) {
		$places = array($messagedata['message'], $messagedata['textmessage'], $messagedata['subject'], $this->loadTemplate($messagedata['template']));
  		$placenames = array('message', 'text message', 'subject line', 'template');
  		
  		// Check that star brackets and [*IF*]...[*ELSE*]...[*ENDIF*] constructions are
  		// well-formed
  		$patterns = array ($this->brackpat, $this->sympat);
  		$tags2ck = array ($this->brackets, $this->symbols);
  		foreach ($places as $key => $str) {
  			if (!$str)
  				continue;
  			foreach ($patterns as $ndx => $pat) {
  				preg_match_all("@$pat@", $str, $match);
				$tags = $match[0];
				$result = $this->checkSyntax($tags, $tags2ck[$ndx]);
				if ($result !== true)
					return "$tags[$result] out of place in $placenames[$key]!"; 
			}
  		}
  		
  		// Now that every original string beginning with [*IF*] contains a
  		// conditional placeholder. Also check that no star brackets in alternate string.
  		foreach ($places as $key => $str) {
  			preg_match_all('@\[\*IF\*\](.*)(?:\[\*ELSE\*\](.*))?\[\*ENDIF\*\]@Ums', $str, $match);
  			$holders = $match[0];
  			$orig = $match[1];  // The array of 'original strings'
  			$alt = $match[2]; 	// The array of 'alternate strings'
			foreach ($orig as $key2 => $val) { // Star bracket in each one?
				if (strpos($val, '[*') === false)
					return "No conditional place holder in -> $holders[$key2]<br \>in $placenames[$key]!"; 
				if (strpos($alt[$key2], '[*') !== false)
					return "Alternate string holds conditional placeholder in -> $holders[$key2]<br \>in $placenames[$key]!";
			}
		}
		
		// Check for any 'loose' conditional placeholders not in the proper '[*IF*] string'
		foreach ($places as $key => $str) {
  			$str = preg_replace('@\[\*IF\*\](?:.*)(?:\[\*ELSE\*\](?:.*))?\[\*ENDIF\*\]@Ums','', $str); // Remove [*IF*] strings
  			if (strpos($str, '[*') !== false) // Look for star bracket in what's left
				return "Conditional place holder outside [*IF*] clause<br \>in $placenames[$key]!"; 
		}
		
	// Check that all the star bracket placeholders actually represent user attributes
		foreach ($places as $key => $str) {
			preg_match_all('@\[\*(.*)\*\]@Ums', $str, $match);
			$holders = $match[0];
  			$atts = $match[1];
			foreach ($atts as $key2 => $val) {
				if (in_array($val, $this->attnames) === false) 
					return "Star bracket {$holders[$key2]} in $placenames[$key]<br \>does not correspond to known user attribute!";
			}
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
 	 	if ((!$content) || (strpos($content, '[*') === false))
 	 		return $content;
 	 	
 	 	$atts = array();
 	 	// Stolen from phplist parsePlaceHolders function
 	 	## the editor turns all non-ascii chars into the html equivalent so do that as well
  		foreach ($this->user_att_values as $key => $val) {
   		 	$atts[strtoupper($key)] = $val;
    		$atts[htmlentities(strtoupper($key),ENT_QUOTES,'UTF-8')] = $val;
    		$atts[str_ireplace(' ','&nbsp;',strtoupper($key))] = $val;
  		}

 	 	preg_match_all('@\[\*IF\*\](.*)(?:\[\*ELSE\*\](.*))?\[\*ENDIF\*\]@Ums', $content, $match);
		$holders = $match[0];  // array of [*IF*] ... [*ENDIF*] strings
		$orig = $match[1]; // Array of 'original' strings containing conditional placeholders
		$repl = $match[2]; // Array of substitute strings
		
  		foreach ($holders as $key => $val) { // For each [*IF*] string
  			$str = $orig[$key];
  			// Substitute values for the conditional place holder
  			foreach ($atts as $k2 => $v2) {
      			if (stripos($str,'[*'. $k2 .'*]') !== false) { // found one?
      			    $novalue = empty($v2);
      			    if ($novalue)  // Oops, no value for this one
      			    	break;
        			$str = str_ireplace('[*'. $k2 .'*]',$v2,$str);
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
  