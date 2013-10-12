<?php
/**
 * Helper for terminal messages.
 *
 * @author AwakeHL
 */
class Console {

	/**
	 * Ask for input.
	 * @param string $message
	 * @return string
	 */
	public static function Ask($message = '') {
		echo $message.PHP_EOL;
		$o = "";
		$c = "";
		while ($c != "\n") {
			$o .= $c;
			$c = fread(STDIN, 1);
		}
		$o = trim($o);
		return $o;
	}

	/**
	 * Show message.
	 * @param string $message
	 * @param bool $withLineBreak
	 */
	public static function Message($message, $withLineBreak = true) {
		echo $message.($withLineBreak ? PHP_EOL : '');
	}

	/**
	 * Show OK.
	 */
	public static function Ok() {
		echo '[OK]'.PHP_EOL;
	}

	/**
	 * Show WARNING message.
	 * @param string $message
	 */
	public static function Warning($message) {
		echo '[WARNING] '.$message.PHP_EOL;
	}

	/**
	 * Show ERROR message.
	 * @param string $message
	 */
	public static function Error($message) {
		echo '[ERROR] '.$message.PHP_EOL;
	}
}