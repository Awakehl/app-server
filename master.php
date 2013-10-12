<?php
/**
 * Master process.
 * @usage php -f master.php [port] [php_dir]
 */
require_once 'config.php';
require_once 'console.php';

$port = isset($argv[1]) ? $argv[1] : null;
$path = isset($argv[2]) ? $argv[2] : null;

class Master {

	private $_mPort = 5190;
	//client processes, array of ProcessData, keymap ProcessName=>ProcessData
	private $_mProcesses = array();
	//all client sockets, assoc array of SocketData, keymap SocketName=>SocketData
	private $_mSockets = array();
	//all client sockets and master socket, array of socket resources, for stream_select
	private $_mAllSockets = array();

	//php interpreter direcory
	private static $_phpDir = "";

	//logging
	private $_logFile;
	private $_logEnabled;

	/**
	 * Server start static wrapper.
	 */
	public static function run() {
		$instance = new Master();
		$instance->start();
	}

	/**
	 * Server start. The main cycle.
	 */
	public function start() {

		global $port, $path;

		$this->_mPort = $port ? $port : $this->_mPort;
		self::$_phpDir = $path ? $path : self::$_phpDir;

		$this->_logFile = Config::LOG_DIR."main_log";
		if (is_writable($this->_logFile)) {
			$this->_logEnabled = true;
		} else {
			Console::Warning('Log file is not writable!');
		}
		$socket = null;
		//opening main socket
		try {
			$socket = stream_socket_server("tcp://0.0.0.0:{$this->_mPort}", $errno, $errstr);
		} catch (Exception $e) {
			// handle socket bind exception here
		}

		if (!$socket) {
			exit($errstr." (".$errno.")");
		} else {
			$this->log(PHP_EOL."Listening for connections on ".$this->_mPort);
		}

		$allSockets = &$this->_mAllSockets;
		$allSockets[] = $socket;

		register_shutdown_function(array($this, "shutdown"));

		$lastActivityCheckTime = time();

		//buffer for reading process responses, while not reached end of response
		$buff = array();

		while (1) {
			if (time() - $lastActivityCheckTime > 60) {
				$lastActivityCheckTime = time();
				$this->checkActivity();
			}

			$socketsToRead = $allSockets;
			foreach ($socketsToRead as $key => $res) {
				//user disconnected
				if ($this->isBadSocket($res)) {
					unset($socketsToRead[$key]);
				}
			}
			//if any changes in sockets?
			$write = array();
			$except = array();
			$modFd = stream_select($socketsToRead, $write, $except, 0);

			if ($modFd > 0) {
				for ($i = 0; $i < $modFd; ++$i) {
					if ($socketsToRead[$i] === $socket) { //new user
						$userSocket = stream_socket_accept($socket);
						if ($userSocket) {
							$socketData = new SocketData();
							$socketData->mSocket = $userSocket;
							$socketData->mLastActivityTime = time();
							$socketData->mName = $this->getSocketName($userSocket);
							$this->_mSockets[$socketData->mName] = $socketData;
							stream_set_blocking($socket, 0);
							$this->log("[main] client connected: ".$socketData->mName);
							$this->sendToSocket('<!DOCTYPE cross-domain-policy (View Source for full doctype...)><cross-domain-policy><allow-access-from domain="*" to-ports="*" secure="false" /></cross-domain-policy>', $userSocket);
							$allSockets[] = $userSocket;
						} else {
							$this->log("[main] invalid socket not accepted!");
						}
					} else {
						//read new data from server
						$sockData = fread($socketsToRead[$i], 4096);
						if (strlen($sockData) === 0) {
							// connection closed
							$this->closeSocket($socketsToRead[$i]);
						} else if ($sockData === false) {
							$this->log("[main] something bad happened");
							$keyToDel = array_search($socketsToRead[$i], $allSockets, true);
							unset($allSockets[$keyToDel]);
						} else {
							$this->log("[main] client: ".$sockData);
							$requests = explode("\n", $sockData);
							foreach ($requests as $request) {
								if ($request) {
									$this->request($request, $socketsToRead[$i]);
								}
							}
						}
					}
				}
			}

			//reading stdout pipes
			if (count($this->_mProcesses)) {
				foreach ($this->_mProcesses as $name => $process) {
					$data = '';
					//reading first 1kb
					$data .= fread($process->mPipeOut, 4096);
					//if not reached end of line, reading 50 k more
					if ($data) {
						$char = ord(substr($data, -1));
						$this->log("[main] last char ord: ".$char);
						if ($char != 10) {
							$this->log("[main] reading 50r more");
							$data .= fread($process->mPipeOut, 51200);
							$char = ord(substr($data, -1));
							$this->log("[main] last char ord: ".$char);
							if ($char != 10) {
								//saving part of mssage and waiting next part
								if (!isset($buff[$name])) {
									$buff[$name] = '';
								}
								$buff[$name] .= $data;
								continue;
							}
						}

						if (isset($buff[$name])) {
							$data = $buff[$name].$data;
							$buff[$name] = null;
						}

						$data = trim($data);

						$this->log("[process]: ".$data);
						$process->mLastActivityTime = time();
						$xmlparts = explode(PHP_EOL, $data);
						foreach ($xmlparts as $xmlpart) {
							$parts = explode("|", $xmlpart);
							$socketData = isset($this->_mSockets[$parts[0]]) ? $this->_mSockets[$parts[0]] : null;
							$userSocket = $socketData ? $socketData->mSocket : null;

							if ($userSocket) {
								$xmlStr = $parts[1];
								$this->sendToSocket($xmlStr, $userSocket);
							} else {
								$this->processCommand($xmlpart, $name);
							}
						}
					}
				}
			}

			//little sleep for weaking cpu usage
			usleep(10000);
		}
	}

	/**
	 * Start child process.
	 * @param string $processName
	 * @param string $file
	 */
	private function startProcess($processName, $file = 'process.php') {
		$spec = array(array("pipe", "r"), array("pipe", "w"), array("pipe", "w"));
		$masterPid = getmypid();
		$strProcess = self::$_phpDir."php -f ".dirname(__FILE__)."/{$file} {$processName} {$masterPid}";
		$e = null;
		$process = proc_open($strProcess, $spec, $p, null, $e);

		stream_set_blocking($p[0], 0); // Make stdin/stdout/stderr non-blocking
		stream_set_blocking($p[1], 0);
		stream_set_blocking($p[2], 0);

		$processData = new ProcessData();
		$processData->mName = $processName;
		$processData->mPipeIn = &$p[0];
		$processData->mPipeOut = &$p[1];
		$processData->mPipeErr = &$p[2];
		//in this version php proc_get_status returns wrong pid @see https://bugs.php.net/bug.php?id=38542
		$cmd = "ps ax | grep '[0-9] php.*{$processData->mName}'";
        $strProcesses = trim(`$cmd`);
		$parts = preg_split("/[^\d]/", $strProcesses);
		$pid = array_shift($parts);
		$processData->mPid = $pid;
		$processData->mLastActivityTime = time();
		$processData->mProcess = $process;
		$this->_mProcesses[$processName] = $processData;

		$this->log("[start_process] process started, process name: {$processName}, run str:{$strProcess}, pid {$processData->mPid}");
	}

	/**
	 * Accept request from the Client and transmit in to the related process.
	 * @param string $data
	 * @param Resource $socket
	 */
	public function request($data, $socket) {
		try {
			$socketName = $this->getSocketName($socket);
			$sockedData = $this->_mSockets[$socketName];
			try {
				$sockedData->mLastActivityTime = time();
				$processData = isset($this->_mProcesses[$sockedData->mProcessName])
					? $this->_mProcesses[$sockedData->mProcessName]
					: null;

				if (!$processData) {
					$this->clientStart($sockedData);
					$processData = $this->_mProcesses[$sockedData->mProcessName];
				}
				$result = $this->sendToProcess($data, $processData);
				if (!$result) {
					$this->sendToSocket('something wrong with process happened', $socket);
					$this->closeSocket($socket);
				}
			} catch (Exception $e) {
				$this->log("[request] ".$e->getMessage().$e->getTraceAsString());
				// Handle errors here.
				$this->sendToSocket($e->getMessage(), $socket);
				$this->closeSocket($socket);
			}
		} catch (Exception $e) {
			error_log($e->getMessage().$data.$e->getTraceAsString());
		}
	}

	/**
	 * Start process for the Client.
	 * @param SocketData $socketData
	 */
	public function clientStart(SocketData $socketData) {
		if ($socketData->mProcessName) {
			$this->log("[auth] already started");
			return;
		}
		$this->log("[auth] starting process for socket: {$socketData->mName}");
		$this->startProcess($socketData->mName);
		//local case, one socket - one process, that's why names can be the same
		$socketData->mProcessName = $socketData->mName;
	}

	/**
	 * Closes Client socket.
	 * @param Resource $socket
	 */
	private function closeSocket($socket) {
		$this->log("[main] close_socket {$socket}");
		$socketName = $this->getSocketName($socket);
		$this->log("[main] user disconnected: ".$socketName);
		if (!$socketName) {
			$this->log("[main] socket name is empty! skipping!");
		} else {
			$this->userDisconnected($socketName);
			unset($this->_mSockets[$socketName]);
		}

		if (!$socket) {
			$this->log("[main] socket is null! skipping!");
		} else {
			//key to del
			$keyToDel = array_search($socket, $this->_mAllSockets, true);
			$this->log("[main] key to del {$keyToDel}");
			if ($keyToDel === false) {
				$this->log("[main] key to del is false! skipping!");
			} else {
				unset($this->_mAllSockets[$keyToDel]);
			}
			if ($socketName) {
				fclose($socket);
			}
		}
	}

	/**
	 * Gets socket name.
	 * @param Resource $socket
	 * @return null|string
	 */
	private function getSocketName($socket) {
		if (!$socket) {
			return null;
		}
		try {
			$name = stream_socket_get_name($socket, true);
			return $name;
		} catch (Exception $e) {
			$this->log("[get_socket_name] can't get socket name, socket {$socket}");
			return null;
		}
	}

	/**
	 * Bad socked?
	 * @param Resource $res
	 * @return bool
	 */
	private function isBadSocket($res) {
		return get_resource_type($res) == 'Unknown';
	}

	/**
	 * Sends data to the child process.
	 * @param string $data
	 * @param ProcessData $processData
	 * @return bool
	 */
	private function sendToProcess($data, ProcessData $processData) {
		$data = preg_replace("/[\n\r]/", "", $data);
		$this->log("[send2game] sending to process {$processData->mName}:".$data);
		if (!$processData->mProcess) {
			//something bad with process, may be internal fatal error
			$this->log("[send2game] process not found, ignoring and destroying");
			$this->destroyProcess($processData);
			return false;
		}
		$status = proc_get_status($processData->mProcess);
		if (!$status["running"]) {
			//something bad with process
			$this->log("[send2game] process status ".print_r($status, 1));
			$this->destroyProcess($processData);
			return false;
		}

		fwrite($processData->mPipeIn, $data.PHP_EOL);
		fflush($processData->mPipeIn);
		return true;
	}

	/**
	 * Sends data to Client socket.
	 * @param string $data
	 * @param Resource $socket
	 */
	private function sendToSocket($data, $socket) {
		$this->log("[SERVER to {$socket} ".strlen($data)."] ".substr($data, 0, 64)."...");
		try {
			fwrite($socket, $data.chr(0));
		} catch (Exception $e) {
			$this->log("[ERROR] :".$e->getMessage());
		}
	}

	/**
	 * Handling command from process.
	 * @param string $request
	 * @param string $processName
	 */
	private function processCommand($request, $processName) {
		/** @var $processData string */
		$processData = $this->_mProcesses[$processName];
		// Handle process call here. You may find JSON protocol useful for data transmit.
	}

	/**
	 * Destroy process method.
	 * @param string $processName
	 */
	private function destroyProcess($processName) {
		/** @var $processData ProcessData */
		$processData = $this->_mProcesses[$processName];
		if (!$processData) {
			$this->log("[destroyProcess] process not found, ignoring");
			return;
		}
		$this->log("[destroyProcess] destroying process: {$processName}");
		fclose($processData->mPipeOut);
		fclose($processData->mPipeIn);
		fclose($processData->mPipeErr);
		// If needed, handle process killing from the System here.
		//@use $processData->mPid
		$processData = null;
		unset($this->_mProcesses[$processName]);
	}

	/**
	 * User disconnected callback.
	 * @param string $socketName
	 */
	private function userDisconnected($socketName) {
		$this->log("[main] [userDisconnected] user:{$socketName}, sending to process");
		//in this project local case, socket name = process name
		$processData = $this->_mProcesses[$socketName];
		if ($processData) {
			// handle user disconnect
		}
	}

	/**
	 * Check whether all sockets are alive.
	 */
	private function checkActivity() {
		$this->log("[check_activity] checking sockets");
		foreach ($this->_mSockets as $name => $socketData) {
			if (($diff = (time() - $socketData->mLastActivityTime)) > Config::MAX_CLIENT_INACTIVITY_TIME) {
				$this->log("[check_activity] socket {$name} inactive {$diff} s, closing socket");
				if ($this->isBadSocket($socketData->mSocket)) {
					$this->log("[check_activity] socket is bad, removing from list");
					unset($this->_mSockets[$name]);
				} else {
					$this->closeSocket($socketData->mSocket);
				}
			}
		}
		$this->log("[check_activity] checking processes");
		foreach ($this->_mProcesses as $processData) {
			if (($diff = (time() - $processData->mLastActivityTime)) > Config::MAX_PROCESS_INACTIVITY_TIME) {
				$this->log("[check_activity] process pid:{$processData->mPid} name:{$processData->mName} inactive {$diff} s, closing process");
				$pid = $processData->mPid;
				if ($pid) {
					$this->destroyProcess($processData->mName);
				} else {
					$this->log("[check_activity] pid is empty, ignoring, status: ".proc_get_status($processData->mProcess));
				}
			}
		}
	}

	/**
	 * Shutdown handler.
	 */
	public function shutdown() {
		$this->log('[shutdown] shutdown call');
		$this->log('[shutdown] closing processes');
	}

	/**
	 * Logging.
	 * @param string $message
	 */
	private function log($message) {
		$m = date("Y-m-d H:i:s")."\t".$message;
		Console::Message($m);
		if ($this->_logEnabled) {
			file_put_contents($this->_logFile, $m.PHP_EOL, FILE_APPEND);
		}
	}

}

/**
 * Process Data.
 */
class ProcessData {
	public $mName;
	public $mPid;
	public $mProcess;
	public $mPipeIn;
	public $mPipeOut;
	public $mPipeErr;
	public $mLastActivityTime;
}

/**
 * Client Socket data.
 */
class SocketData {
	public $mName;
	public $mSocket;
	public $mLastActivityTime;
	public $mProcessName;
}

Master::run();