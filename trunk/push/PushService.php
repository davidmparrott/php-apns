<?php

/**

 PHP APNS Service
 Created by Luke Rhodes
 
 Released into the public domain
 
 */

$port = 0;
foreach($argv as $a)
{
	if($a[0] == '-' && $a[1] == 'p' && !empty($a[2]))
	{
		$port = intval(substr($a, 2));
	}
}

$pushNotifications = new pushNotifications($port);

class pushNotifications
{
	private $apnsHost = 'gateway.push.apple.com';
	private $apnsPort = '2195';
	private $sslPem = 'apns.pem';
	private $passPhrase = '';
	
	private $serviceHost = '127.0.0.1';
	private $servicePort = '1153';
	
	private $apnsConnection;
	private $serviceConnection;
	
	function __construct($port = 0)
	{
		if($port)
		{
			$this->servicePort = $port;
		}
		$this->connectToAPNS();
		$this->listenForClients();
		$this->closeConnections();
	}
	
	function connectToAPNS()
	{
		$streamContext = stream_context_create();
		stream_context_set_option($streamContext, 'ssl', 'local_cert', $this->sslPem);
		stream_context_set_option($streamContext, 'ssl', 'passphrase', $this->passPhrase);
		    
		$this->apnsConnection = stream_socket_client('ssl://'.$this->apnsHost.':'.$this->apnsPort, $error, $errorString, 60, STREAM_CLIENT_CONNECT, $streamContext);
		if($this->apnsConnection == false)
		{
			print "Failed to connect {$error} {$errorString}\n";
			$this->closeConnections();
		}
	}
	
	function listenForClients()
	{
		$this->serviceConnection = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		socket_bind($this->serviceConnection, $this->serviceHost, $this->servicePort);
		socket_listen($this->serviceConnection, 10);
		
		echo 'LISTENING ',$this->servicePort,"\n";
		
		while($clientSocket = socket_accept($this->serviceConnection))
		{
			socket_write($clientSocket, "OK\n");
			
			$deviceToken = trim(socket_read($clientSocket, 1024, PHP_NORMAL_READ));
			$message = trim(socket_read($clientSocket, 1024, PHP_NORMAL_READ));
			
			if(!empty($deviceToken) && !empty($message))
			{
				$this->sendNotification($deviceToken, $message);
				socket_write($clientSocket, "SENT\n");
			}
			else
			{
				socket_write($clientSocket, "ERROR\n");
			}
			socket_close($clientSocket);
		}
	}
	
	function sendNotification($deviceToken, $message)
	{
		$apnsMessage = chr(0) . chr(0) . chr(32) . pack('H*', str_replace(' ', '', $deviceToken)) . chr(0) . chr(strlen($message)) . $message;
		
		fwrite($this->apnsConnection, $apnsMessage);
	}
	
	function closeConnections()
	{
		socket_close($this->serviceConnection);
		fclose($this->apnsConnection);     
	}
}

?>
