<?php

namespace Lotaris\RoxClientPHPUnit;

use \Exception;

/**
 * Generic RoxClientException
 *
 * @author Francois Vessaz <francois.vessaz@lotaris.com>
 */
class RoxClientException extends Exception {
	
	private static $exceptionOccured = false;

	public function __construct($message) {
		self::$exceptionOccured = true;
		parent::__construct($message);
	}
	
	public static function exceptionOccured(){
		return self::$exceptionOccured;
	}

}
