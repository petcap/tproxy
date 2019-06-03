
<?php
//Maximum internal buffer space, stop reading data if reached
define("INTERNAL_BUFFER", 4096);

//Number of bytes per socket read
define("READ_SIZE", 1024);

include("bash_color.php");
include("client.php");

/*
Run as root:

iptables -F
iptables -F -t nat
iptables -F -t mangle
iptables -P INPUT ACCEPT
iptables -P OUTPUT ACCEPT
iptables -P FORWARD ACCEPT
iptables -t mangle -N TPROXCHAIN
iptables -t mangle -A PREROUTING -p tcp -m socket -j TPROXCHAIN
iptables -t mangle -A TPROXCHAIN -j MARK --set-mark 7
iptables -t mangle -A TPROXCHAIN -j ACCEPT
ip rule add fwmark 7 lookup 7
ip route add local 0.0.0.0/0 dev lo table 7
iptables -t mangle -I PREROUTING --source 10.22.22.0/24 -p tcp -j TPROXY --on-port 1337 --on-ip 127.0.0.1 --tproxy-mark 0x7/0x7

# Optional, traditional DNS forwarding
iptables -A POSTROUTING -s 10.22.22.0/24 -p udp --dport 53 -t nat -j MASQUERADE
*/

if (posix_geteuid() != 0) {
	die("Please run as root\n(Needed for setting the IP_TRANSPARENT option on the socket)\n");
}

//Create the socket
$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

//This is a limitation in the Zend Engine, we need to pass null by reference later
$null = NULL;

socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);

//IP_FREEBIND=15 (PHP does not have a constant for it yet)
socket_set_option($sock, SOL_SOCKET, 15, 1);

//IP_TRANSPARENT=19 (PHP does not have a constant for it yet)
socket_set_option($sock, 0, 19, 1);

//IP_ORIGDSTADDR=20 (PHP does not have a constant for it yet)
socket_set_option($sock, 0, 20, 1);

//Bind the socket to port 1337
if (!socket_bind($sock, "0.0.0.0", 1337)) {
	die("Cannot bind socket\n");
}

echo "Listening for incoming connections...\n";
socket_listen($sock);

$clients = array();

for(;;) {
	//Reading socket list
	$r = array($sock);

	//Writing socket list
	$w = array();

	//Exception socket list
	$e = array($sock);

	foreach($clients as $id => $client) {

		//Clean up old connections
		if ($client -> ended()) {
			unset($clients[$id]);
			continue;
		}

		$r = array_merge($r, $client -> readable_sockets());
		$w = array_merge($w, $client -> writable_sockets());
		$e = array_merge($e, $client -> exception_sockets());
	}

	if (false === socket_select($r, $w, $e, $null)) {
		echo set_color(COLOR_RED);
		echo "[$host:$port] socket_select() failure, shutting down\n";
		echo set_color(COLOR_DEFAULT);
		die();
	}

	//Do we have an incoming connection?
	if (in_array($sock, $r)) {
		//Get the socket and both endpoint addresses/ports
		$client = socket_accept($sock);
		socket_getsockname($client, $local_addr, $local_port);
		socket_getpeername($client, $remote_addr, $remote_port);

		//Create the outer connection
		$outer = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($outer === false) continue;

		//Do not block, or other clients will have to wait
		socket_set_nonblock($outer);

		echo set_color(COLOR_GREEN);
		echo "New connection from $remote_addr:$remote_port -> $local_addr:$local_port\n";
		socket_connect($outer, $local_addr, $local_port);

		$clients[] = new Client($client, $outer, $remote_addr, $remote_port, $local_addr, $local_port);
	}

	//Check exceptions
	foreach($e as $error) {
		foreach($clients as $id => $client) {
			//check each client if the socket is associated with it
			if ($client -> has_socket($error)) {
				$client -> kill();
			}
		}
	}

	//Check reads
	foreach($r as $read) {
		foreach($clients as $id => $client) {
			//check each client if the socket is associated with it
			if ($client -> has_socket($read)) {
				$client -> read($read);
			}
		}
	}

	//Check writes
	foreach($w as $write) {
		foreach($clients as $id => $client) {
			//check each client if the socket is associated with it
			if ($client -> has_socket($write)) {
				$client -> write($write);
			}
		}
	}

}
?>
