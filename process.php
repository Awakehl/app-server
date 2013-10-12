<?php
/**
 * Thread Process.
 * @param string $prompt
 * @return string
 *
 * @author AwakeHL
 */

function rline($prompt = "") {
	print $prompt;
	$out = "";
	//read from standard input (keyboard)
	$key = fgetc(STDIN);
	 //if the newline character has not yet arrived read another
	while ($key != PHP_EOL) {
		$out .= $key;
		$key = fread(STDIN, 1);
	}
	return $out;
}

$socket_name = $argv[1];

class Process {

	protected $_mProcessName;

	public static function run() {
		$instance = new Process();
		$instance->start();
	}

	/**
	 * Start the Process.
	 */
	public function start() {
		$this->_mProcessName = $GLOBALS["argv"][1];

		$this->log("pid:".getmypid());
		while (true) {
			# read incoming command
			$request = trim(rline());

			# execute incoming command
			$this->log(getmypid()." got message: {$request}");

			try {
				$this->processRequest($request);
			} catch (Exception $e) {
				$this->log($e->getMessage().$e->getTraceAsString());
			}
		}
		usleep(100);
	}

	/**
	 * Call this method to send message to client.
	 * @param $data
	 */
	public function send($data) {
		ob_get_clean();
		echo $this->_mProcessName."|".preg_replace("/[\n]/", "", $data).PHP_EOL;
		ob_start();
	}

	//---------------

	/**
	 * Use this method to handle client messages.
	 * @param $request
	 */
	protected function processRequest($request) {
		// Put your code here.
	}

	/**
	 * Implement logging there.
	 * @param string $message
	 */
	protected function log($message) {

	}
}

Process::run();