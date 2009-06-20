<?php
/**

 PHP APNS Service with Queue
 (C) 2009 Alessandro Segala (alessandro.segala@letsdev.it)
 
 This library is free software; you can redistribute it and/or
 modify it under the terms of the GNU Lesser General Public
 License as published by the Free Software Foundation; either
 version 2.1 of the License, or (at your option) any later version.
 
 This library is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 Lesser General Public License for more details.

 You should have received a copy of the GNU Lesser General Public
 License along with this library; if not, write to the Free Software
 Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 
 */
 
// Some settings
$cache_server = 'localhost';
$cache_port = 22201;
$queue_id = 'PushQueue';
$check_interval = 10;
$php_executable = 'php';
$inactivity_timeout = 600; // Quits the connection with APNS after X seconds of inactivity
$debug = false; // Set to true to display verbose information

// We want this process to run forever... Well, at least until the user stops it!
set_time_limit(0);

// Settings for child processes
$descriptorspec = array(
	0 => array('pipe', 'r'), // stdin
	1 => array('pipe', 'w'), // stdout
	2 => array('pipe', 'w') // stderr
);

$process = false;

// Changes the working directory so it matches this script's directory
chdir(dirname(__FILE__));

// Opens a connection to the MemcacheQ daemon, used for queueing requests
$cache = new Memcache();
if(!$cache->connect($cache_server, $cache_port))
{
	echo "Error while connecting to cache server\n";
	exit;
}

// Application's loop
while(true)
{
	$value = $cache->get($queue_id);
	if(!$value)
	{
		if($debug)
		{
			echo "No data: sleeping for $check_interval seconds\n";
		}
		
		// Kills the process after X seconds of inactivity
		if($process)
		{
			if($process->time < (time() - $inactivity_timeout))
			{
				if($debug)
				{
					echo "Quitting connection with Apple for inactivity\n";
				}
				$process->kill();
				$process = false;
			}
		}
		
		sleep($check_interval);
		continue;
	}
	
	if($debug)
	{
		var_dump($value);
	}
	
	set_error_handler(create_function('', 'error_log(date(\'Y-m-d H:i\')." - Error while unserializing\n\n", 3, \'PushErrors.log\');'));
	$unserialized = unserialize($value);
	restore_error_handler();
	if(!$unserialized)
	{
		continue;
	}
	list($deviceToken, $value) = $unserialized;
	
	// Try to send the message 3 times
	for($i = 0; $i < 3; $i++)
	{
		if($debug)
		{
			echo "Sending message\n";
		}
		
		if(!$process)
		{
			$process = new Process(1153 + $i);
			if(!$process->init())
			{
				echo "Error while starting child process\n";
				
				$process->kill();
				$process = false;
				if($debug)
				{
					echo "Sleeping 10 seconds before trying again\n";
				}
				
				sleep(10);
				continue;
			}
		}
		
		$result = $process->sendMessage($deviceToken, $value);
		
		if($result)
		{
			if($debug)
			{
				echo "Message sent\n\n";
			}
			break;
		}
		elseif($debug)
		{
			echo "Attempt ",$i+1," failed\n";
		}
	}
	
	// If all 3 attempts failed...
	if($i == 3)
	{
		if($process)
		{
			$process->kill();
			$process = false;
		}
		error_log(date('Y-m-d H:i')." - Cannot send message:\n".$message."\nTo device: ".$deviceToken."\n\n", 3, 'PushErrors.log');
	}
}

class Process
{
	private $pointer;
	private $pipes;
	private $port;
	public $time;
	
	public function __construct($port = 1153)
	{
		$this->port = $port;
	}
	
	public function init()
	{
		global $descriptorspec, $php_executable;
		
		$this->pointer = proc_open($php_executable.' -f PushService.php -- -p'.$this->port, $descriptorspec, $this->pipes);
		if(!$this->pointer)
		{
			return false;
		}
		
		$this->time = time();
		
		// Wait until the process is fully ready
		$read = fgets($this->pipes[1]);
		if(trim($read) != 'LISTENING '.$this->port)
		{
			global $debug;
			if($debug)
			{
				echo "Error while starting child process.\n";
			}
			return false;
		}
		
		return true;
	}
	
	public function sendMessage($deviceToken, $message)
	{
		if(!($conn = fsockopen('localhost', $this->port, $errno, $errstr, 5)))
		{
			return false;
		}
		
		$read = fgets($conn);
		if(trim($read) != 'OK')
		{
			fclose($conn);
			
			return false;
		}
		
		fwrite($conn, $deviceToken."\n");
		fwrite($conn, $message."\n");
		
		$read = fgets($conn);
		if(trim($read) != 'SENT')
		{
			fclose($conn);
			return false;
		}
		
		fclose($conn);
		
		$this->time = time();
		
		return true;
	}
	
	public function kill()
	{
		global $debug;
		
		$status = proc_get_status($this->pointer);
		
		if($status['running'])
		{
			fclose($this->pipes[0]);
			fclose($this->pipes[1]);
			fclose($this->pipes[2]);
			
			$ppid = $status['pid'];
			$pids = preg_split('/\s+/', `ps -o pid --no-heading --pid $ppid`);
			foreach($pids as $pid)
			{
				if(is_numeric($pid))
				{
					if($debug)
					{
						echo "Killing $pid\n";
					}
					posix_kill($pid, 9); //9 is the SIGKILL signal
				}
			}
			return (proc_close($this->pointer) == 0);
		}
	}
}
?>
