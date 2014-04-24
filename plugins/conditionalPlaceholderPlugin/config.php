<?php
/*
 * config.php
 *
 * @category  phplist
 * @package   conditionalPlaceholder Plugin
 * @author    Arnold V. Lesikar
 * @copyright 2014 Arnold V. Lesikar
 * @license   http://www.gnu.org/licenses/gpl.html GNU General Public License, Version 3
 * 
 * config.php is part of the conditionalPlaceholder Plugin.
 * The conditionalPlaceholder plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.

 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

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