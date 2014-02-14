<?php

class cpConfig
{
	// Values set bracketing for both placeholders and keywords
 	public static $cpBrackets = array('[*','*]'); 
 	
 	// Keywords for parsing text containing placeholders
 	// Change 'ENDIF' to 'FI' if you would rather use texts like
 	// [*IF*] ... [*ELSE*] ... [*FI*]
 	public static $cpKeywords = array('IF', 'ELSE', 'ENDIF', 'ELSEIF');
 	
 	// This variable defines the separator for a list of acceptable attribute values
 	// in a conditional placeholder
 	public static $listsep = ',';
 	
 	// This is the symbol representing missing values in a list or range
 	public static $ellipsis = '..';
 	
 	// This variable defines the flag used to mark a placeholder as a TEST rather than
 	// a placeholder that will actually be replaced by an attribute value
 	public static $testflag = '~';
 	
 	// Set this value to 'false' if you would like to have an empty alternate string
 	// without an explicit 'else'. This is not recommended.  
 	public static $explicitElse = true;
}