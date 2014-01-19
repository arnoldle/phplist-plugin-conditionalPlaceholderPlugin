<?php

class cpConfig
{
	// Values set bracketing for both placeholders and keywords
 	public static $cpBrackets = array('[*','*]'); 
 	
 	// Keywords for parsing text containing placeholders
 	// Change 'ENDIF' to 'FI' if you would rather use texts like
 	// [*IF*] ... [*ELSE*] ... [*FI*]
 	public static $cpKeywords = array('IF', 'ELSE', 'ENDIF');
 	
 	// Set this value to 'false' if you would like to have an empty alternate string
 	// without an explicit 'else'. This is not recommended.  
 	public static $explicitElse = true;
}