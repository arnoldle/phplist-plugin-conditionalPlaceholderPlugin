<?php

/**
 * conditionalPlaceholder plugin version 2.0a5
 * 
 * This plugin allows the use of conditional placeholders in PHPlist html and text messages
 * It allows standard placeholders to be used in the subject line of messages, as well
 * as conditional placeholders. Version 2 greatly expands the functionality of this
 * plugin over what was available in version 1.
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
    public $version = '2.0a5';
    public $enabled = false;
    public $authors = 'Arnold Lesikar';
    public $description = 'Allows the use of conditional placeholders in messages';
    
    // These variables are overridden by the values in the config file
    // They are defined here in case the config file is not installed
    private $brackets = array('[*','*]'); 
    private $keywords = array('IF', 'ELSE', 'ENDIF', 'ELSEIF');
    private $listsep = ',';
 	private $ellipsis = '..';
 	private $testflag = '~';
	private $needElse = true;
	
	private $pif, $pels, $pend, $pelsif;
	private $actionpat, $phpat, $brackpat, $syntaxpat; // Regex patterns
	private $user_att_values = array();
	private $attNames = array();
	
	// This plugin has no web pages. So make sure that nothing appears in the 
	// dashboard menu
	function adminmenu() {
    	return array ();
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
		$attkeys = array();
		
   		$att_table = $GLOBALS['tables']["attribute"];
    	$res = Sql_Query(sprintf('SELECT Name FROM %s', $att_table));
    	while ($row = Sql_Fetch_Row($res))
    		$attnames[] = $row[0];
    		
		// Stolen from phplist parsePlaceHolders function
    	## the editor turns all non-ascii chars into the html equivalent so do that as well	
    	foreach ($attnames as $aname) {
    		$attkeys[strtoupper($aname)] = 1;
			$attkeys[htmlentities(strtoupper($aname),ENT_QUOTES,'UTF-8')] = 1;
			$attkeys[str_ireplace(' ','&nbsp;',strtoupper($aname))] = 1;
		}

		return array_keys($attkeys);
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
        	
        	// Make sure everything still works even if we are using the version 1.0
        	// config file
        	if (isset(cpConfig::$listsep) && (!empty(cpConfig::$listsep)))
        		$this->listsep = cpConfig::$listsep;
 			if (isset(cpConfig::$ellipsis) && (!empty(cpConfig::$ellipsis)))
        		$this->ellipsis = cpConfig::$ellipsis;
 			if (isset(cpConfig::$testflag) && (!empty(cpConfig::$testflag)))
        		$this->testflag = cpConfig::$testflag;
        		
			$this->needElse = cpConfig::$explicitElse;
        }
      
        $this->attnames = $this->loadAttributeNames();

       // Bracket the keywords
        $ary = array ();
        foreach ($this->keywords as $key => $val)
        	$ary[$key] = $this->brackets[0] . $val . $this->brackets[1];
        list ($this->pif, $this->pels, $this->pend, $this->pelsif) = $ary;

        // Build regex pattern for placeholder processing. Note that we must
        // quote our delimiter '@' in case a user should put it into the syntax
       $mypat = preg_quote($this->pif) . '(.*)((?:' . preg_quote($this->pelsif) . '.*)*)(?:' . preg_quote($this->pels) . '(.*))?' . preg_quote($this->pend);
       $this->actionpat = '@' .str_replace('@', '\@', $mypat) .'@Us';
       
       $mypat = preg_quote($this->brackets[0]) . '(.*)' .preg_quote($this->brackets[1]);
       $this->phpat = '@' .str_replace('@', '\@', $mypat) .'@Us';
       
       $mypat = preg_quote($this->brackets[0]) . ')|(?:' .preg_quote($this->brackets[1]);
       $this->brackpat = '@(?:' . str_replace('@', '\@', $mypat) . ')@';
       
       $mypat = preg_quote($this->testflag) . ')?' . preg_quote($this->brackets[0]) . '(.*)' . preg_quote($this->brackets[1]);
       $this->syntaxpat = '@(?:' . str_replace('@', '\@', $mypat) . '@Us';
      	
       parent::__construct();
    }
    
    // This function returns true if the brackets around the conditional keywords 
    // and placeholders balance -- open and closed. Otherwise it returns false
    // It's a two-state machine.
    private function bracketsBalance($str)
    {
    	preg_match_all ($this->brackpat, $str, $match);
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
    
    // This function checks that there are no problems with the syntax symbols defined
    // in the configuration file
    private function checkConfig()
    {
    	if (count($this->brackets) != 2)
    		return 'There must be exactly 2 placeholder brackets defined in the config file!';
    	if (($this->brackets[0] == '') || ($this->brackets[1] ==''))
    		return 'Each placeholder bracket must be defined in the config file!';
    	if ($this->brackets[0] == $this->brackets[1])
    		return 'Left and right placeholder brackets must be distinct!';
    	if (count($this->keywords) != 4)
    		return 'There must be exactly 4 keywords defined in the config file!';
    		
    	$badK = false;
    	foreach ($this->keywords as $val) {
    		if ($val == '') {
    			$badK = true;
    			break;
    		}
    	}
    	if ($badK)
    		return 'A keyword cannot be an empty string in the config file!';
    		
    	if ((empty($this->testflag)) || (empty($this->listsep)) || (empty($this->ellipsis)))
    		return 'Neither $testflag nor $listsep nor $ellipsis can be empty strings in the config file!';
    		
    	$test = array_merge($this->brackets, $this->keywords, array($this->listsep, $this->ellipsis, $this->testflag));
    	foreach ($test as $str) {
    		if (preg_match('@\s@m', $str))
    			return 'The items in the config file cannot contain white space!';
    	}
    	
    	$tcnt = count($test);
    	if (count(array_flip($test)) != $tcnt)
    		return 'Each item in the config file must be unique!';
    	
    	
    	$test = $this->brackets[0] . 'TEST' . $this->brackets[1];
    	if ($test != strip_tags($test))
    		return 'Placeholder brackets must not conflict with HTML tags!';
    		
    	if ($test != str_ireplace('[TEST]','XXX',$test))
    		return 'Placeholder brackets must not conflict with standard placeholders!';
    	
    	return '';
    }
    
    // This function checks placeholders after the outer brackets have been removed
    // We make whatever general checks make sense without out knowing the attribute values
    // that will turn up during use of the plugin.
    private function check_placeholder($aplacehldr) {
    	// Be tolerant of whitespace
    	
    	$aplacehldr = trim($aplacehldr); 	
    	
    	// Remove testflag. It's not needed for this check
		$aplacehldr = preg_replace('@^\s*'.preg_quote($this->testflag) .'\s*@Us', '', $aplacehldr); 
    	
    	if ((substr_count($aplacehldr, '(') > 1) || (substr_count($aplacehldr, ')') > 1))
    		return "Cannot have more than one set of parentheses in placeholder ";
    	
    	$ppos = strpos($aplacehldr, '(');
    	if ((($ppos !== FALSE) && (strpos($aplacehldr, ')') === FALSE)) || (($ppos === FALSE) && (strpos($aplacehldr, ')') !== FALSE)))
    		return "Unbalanced parentheses in placeholder ";
    	
    	if (preg_match('@\)\s*\S+.*$@Us', $aplacehldr))  // White space after the closing paren is OK
    		return "Cannot have material after final parenthesis in placeholder ";
    		
    	if (preg_match('@\(\s*\)@Us', $aplacehldr))
    		return 'Cannot have empty parentheses in placeholder ';

    	preg_match('@(.*)(?:\((.*)\))?$@Us', $aplacehldr, $match);
    	if (!in_array(trim($match[1]),$this->attnames))
    		return "Unknown atttribute in placeholder ";
    	
    	$from0 = FALSE;
    	$toinf = FALSE;
    	if (isset($match[2])) {
    		$vals = explode($this->listsep, $match[2]);
    		foreach ($vals as $aval) {
    			$aval = trim ($aval);
    			$cnt = substr_count ($aval, $this->ellipsis);
    			if (!$cnt)
    				continue;
    			if ($aval == $this->ellipsis) // White space has been trimmed off
    				return "Must have at least a starting or ending value in range in placeholder ";
    			if ($cnt > 1)
    				return "Cannot have double range $aval in placeholder ";
    			if (strpos($aval, $this->ellipsis) == 0) {
    				if (!$from0)
    					$from0 = TRUE;
    				else 
    					return "Cannot have implied beginning in more tnan one range in placeholder ";
    			}
    			if (preg_match('@' . str_replace('@', '\@',preg_quote($this->ellipsis)) . '$@Us', $aval, $match)) {
    				if (!$toinf)
    					$toinf = TRUE;
    				else 
    					return "Cannot have implied ending in more tnan one range in placeholder ";
    			}
    		}
    	}
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
  
  		$symbols = array($this->pif, '', $this->pels, $this->pend, $this->pelsif);
   	
    	// Configuration errors in brackets and keywords are always checked whether
    	// there are placeholders in the message or not.
    	$res = $this->checkConfig();
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
  				
  			preg_match_all($this->syntaxpat, $str, $match);
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
  						if (in_array($current, $symbols))
  							return "Looking for a placeholder but found $current in $placenames[$key]!";
  						$res = $this->check_placeholder($enclosd[$ptr]); // Check what is being enclosed by the brackets
  						if ($res) 
                            return $res . "$current in $placenames[$key]!";
                        else {
                        	$ptr++;
                        	$state++;
                        }
                        break;
                    case 2:			// Looking for one of our placeholders or an 'else' or an 'elseif';
                    				// 'endif' is acceptable if $needElse is false
                    	if ($current == $symbols[$state]) { 	// Found 'else'?
  							$ptr++;
  							$state++;  	// Have 'else'. Look for 'endif'
  						} elseif ($current == $symbols[4]) {// elseif
  							$ptr++;
  							$state = 1;  // Just as if we're coming out of an 'if'
  						} elseif ($current == $symbols[$state + 1]) {
  							if (!$this->needElse) {
  								$ptr++;		// Don't need 'else' and found 'endif'
  								$state = 0;
  							} else  	// found 'endif'
  								return "Found keyword $current without preceding $this->pels in $placenames[$key]!";
  						} elseif (in_array($current, $symbols)) // Found 'if' 
  							return "Found keyword $current out of place in $placenames[$key]!";
  						else {
  							$res = $this->check_placeholder($enclosd[$ptr]); // Check what is being enclosed by the brackets
  							if ($res) 
                            	return $res . "$current in $placenames[$key]!";
  							$ptr++;		// Continue in same state until we find the 'else' or 'elseif'
  						}					
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
  * This is where we can grab user attribute values for evaluating standard placeholders in 
  * the subject line. Standard placeholders in the body of the message are already evaluated
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
 	 
 	// This function returns TRUE if $key is in the range $lowerlmt
 	// to $upperlmt inclusive. If either limit is the Boolean FALSE
 	// the corresponding limit does not apply. So for strings if there is no lower limit
 	// TRUE means that the string precedes the upper limit alphabetically, and vice versa
 	// if the lower limit exists and the upper limit is FALSE.
 	private function between($key, $lowerlmt=FALSE, $upperlmt = FALSE) {
		if (($lowerlmt === FALSE) && ($upperlmt === FALSE))
			return FALSE;
		if (is_numeric($key)) {
			if ($upperlmt === FALSE)
				return ($key >= $lowerlmt);
			elseif ($lowerlmt === FALSE)
				return ($key <= $upperlmt);
			else
				return (($key >= $lowerlmt) && ($key <= $upperlmt));
		}

		// String comparison. A numeric key can't get here
		if ($upperlmt === FALSE)
			return (strcasecmp($key, $lowerlmt) >= 0);
		elseif ($lowerlmt === FALSE)
			return (strcasecmp($key, $upperlmt) <= 0);
		else
			return ((strcasecmp($key, $lowerlmt) >= 0) && (strcasecmp($key, $upperlmt) <= 0));
	}
		 
 	// This private function does the work of replacing the conditional placeholder with the
 	// proper string.
 	 private function parseOutgoingMessage($content)
 	 {

	 	// If none of our placeholders in message, we have nothing to do
 	 	if ((!$content) || (strpos($content, $this->brackets[0]) === false))
 	 		return $content;
 	
 	 	//Anonymous function for use in array_map to check for empty elements
		$mt = function($var) { return empty($var); };
	
 	 	$atts = array();
 	 	
 	 	// Stolen from phplist parsePlaceHolders function
 	 	## the editor turns all non-ascii chars into the html equivalent so do that as well	
 	 	foreach ($this->user_att_values as $key => $val) {
   		 	$atts[strtoupper($key)] = $val;
    		$atts[htmlentities(strtoupper($key),ENT_QUOTES,'UTF-8')] = $val;
    		$atts[str_ireplace(' ','&nbsp;',strtoupper($key))] = $val;
  		}

 	 	preg_match_all($this->actionpat, $content, $match);
		$structs = $match[0];  // array of [*IF*] ... [*ENDIF*] strings
		
		$ix = 0;
		foreach($match[1] as $val) { // Array of first strings preceded by [*IF*]
			$texts[$ix][0] = $val;	// $ix indexes the list of [*IF*]...[*ENDIF*] structures
			$ix += 1;
		}
		
		// Put clauses following 'IF' and 'ENDIF' into the $texts array
		$ix = 0;
		foreach($match[2] as $val) {
			$temp = explode($this->pelsif, $val);  // Will always yield an empty string as the first element
			unset ($temp[0]);   // Get rid of the empty string
			$others = array_values($temp);	 // Array of [*ELSEIF*] strings
			$iy = 1;
			foreach ($others as $itm) {
				$texts[$ix][$iy] = $itm;
				$iy +=1;
			}
			$ix +=1;
		}
		
  		$defaults = $match[3]; // Array of [*ELSE*] strings
  
 		// Now process the placeholders by looping of the array of [*IF*]...[*ENDIF*] structures
  		$ns = count($structs);
  		for ($ix = 0; $ix < $ns; $ix++) { // Loop over list of [*IF*]...[*ENDIF*] structures
  			$replacement = "";
  			
  			// Handle each of the clauses of a particular structure in sequence
  			foreach ($texts[$ix] as $str) {
  
  				preg_match_all($this->phpat, $str, $match);
  				$orig = $match[0];		// Array of placeholders including brackets
  				$holder = $match[1];	// Array of placeholder stuff that is between the brackets
  				
  				// Handle each of the placeholders in one clause
  				// If any of the placeholders in this clause do not satisfy the required conditions,
  				// go to the next clause
  				$fail = FALSE;
  				$iy = -1;
   				foreach ($holder as $str2) {
  					$iy += 1; 
  					$val_ary = array();					
  					$str2 = trim($str2);
   					$test = (strpos($str2, $this->testflag) === 0);  //Is this a test or genuine placeholder?
  					if ($test)
  						$str2 = ltrim(substr($str2, 1));
  					$ary = explode('(', $str2);	// Separate attribute and acceptable values
  					$the_att = trim($ary[0]);	// Attribute
  					if (isset($ary[1])) {
  						$vals = substr(trim($ary[1]), 0, -1); 	// Remove trailing ')'
  						$val_ary = array_map('trim',explode($this->listsep, $vals));
  					} else
  						$val_ary = array();
  					$the_val = $atts[$the_att];
  
  					// An empty placeholder value is a failure unless an empty placeholder is explicitly specified
  
  					if (empty($the_val))
  						if ((empty($val_ary)) || (!in_array (TRUE, array_map($mt, $val_ary)))) {
  							$fail = TRUE;
  							break;
  						} else {
  							if ($test)
  								$str = str_replace($orig[$iy], '', $str);
  							else
  								$str = str_replace($orig[$iy], $the_val, $str);  // In case the value is a numeric zero
  							continue;
  						}
  						
  					// Placeholder is not empty, but if there are no comparison values or ranges,
  					// all we have to do is to replace the placeholder by its value
  					else {
  						if (empty($val_ary)) {
  							if ($test)
  								$str = str_replace($orig[$iy], '', $str);
  							else
  								$str = str_replace($orig[$iy], $the_val, $str);  // In case the value is a numeric zero
  							continue;
  						}
  					}
  						
  					// Is the placeholder value in the value list given?	 					
  					if (in_array($the_val, $val_ary)) {
  						if ($test)
  							$str = str_replace($orig[$iy], '', $str);
  						else
  							$str = str_replace($orig[$iy], $the_val, $str);
  						continue;
  					}
  					
  					// Last chance - is the placeholder value inside a range?
  					$ranges = array();
  					foreach ($val_ary as $myval) {
  						if (strpos($myval, $this->ellipsis) === FALSE)
  							continue;
  						$myary = array_map('trim', explode($this->ellipsis, $myval));
  						$myary[0] = (empty($myary[0])? FALSE: $myary[0]);
  						$myary[1] = (empty($myary[1])? FALSE: $myary[1]);
  						$ranges[] = $myary;
  					}

  					// If we get to here every test has failed. So set the fail flag
  					// to TRUE. If we find the placeholder in a range, we will reset it.
  					$fail = TRUE;
  					foreach ($ranges as $arange) {
  						if ($this->between($the_val, $arange[0], $arange[1])) {  // The placeholder fits a range
  							if ($test)
  								$str = str_replace($orig[$iy], '', $str);
  							else
  								$str = str_replace($orig[$iy], $the_val, $str);
  							$fail = FALSE;
  							break;
  						}
  					} // End of loop through the ranges
  						 
  					if ($fail)  // We have tried everything for this placeholder. So move to next clause
  						break;
  						
				} // End of loop over placeholders in a clause
				
				// If no failures in this clause it is valid. So replace the
				// entire structure with that clause and move to next structure
  				if (!$fail) {
  					$replacement = $str;
  					break;
  				}	
  				
  			} // End of loop over clauses
  			// If we have looped over all the clauses without finding one that is OK, we
  			// use the default as the  replacement for the structure
  			if ($fail)
  				$replacement = $defaults[$ix];
  			// Replace the structure in the text content
  			$content = str_replace($structs[$ix], $replacement, $content);
  
  		} // End of loop over structures

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
  	if (function_exists('parsePlaceHolders')) { // Function is not defined when system messages are mailed
  		$mail->Subject = parsePlaceHolders($mail->Subject, $this->user_att_values);
  		$mail->Subject = $this->parseOutgoingMessage($mail->Subject);
  	}
    return array(); //@@@
  }

}
  