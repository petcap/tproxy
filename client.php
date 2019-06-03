<?php
class Client {
  //Inner socket (on our LAN)
  private $inner_socket;
  private $inner_closed = false;

  //Outer socket (on the internet)
  private $outer_socket;
  private $outer_closed = false;

  //Outgoing buffer (LAN -> Internet)
  private $outgoing_buffer;

  //Incoming buffer (Internet -> LAN)
  private $incoming_buffer;

  //Endpoints and ports
  private $remote_addr;
  private $remote_port;
  private $local_addr;
  private $local_port;

  //Indicates that we want to kill this connection
  private $shutdown = false;

  //Constructor
  function __construct($inner_socket, $outer_socket, $remote_addr, $remote_port, $local_addr, $local_port) {
    $this -> inner_socket = $inner_socket;
    $this -> outer_socket = $outer_socket;
    $this -> remote_addr = $remote_addr;
    $this -> remote_port = $remote_port;
    $this -> local_addr = $local_addr;
    $this -> local_port = $local_port;
  }

  //Check if $sock is in this object (either as inner or outer)
  public function has_socket($sock) {
    return (($sock === $this -> inner_socket) || ($sock === $this -> outer_socket));
  }

  //Check if this object is ready to be unloaded from memory
  public function ended() {
    return ($this -> inner_closed && $this -> outer_closed);
  }

  //Called when the object should be shut down gracefully
  public function kill() {
    $this -> shutdown = true;

    //Give peridical a chance to clean up right away
    $this -> periodical();
  }

  //Return an array of socket that are ready to be read from
  public function readable_sockets() {
    if ($this -> inner_closed || $this -> outer_closed) return array();
    $ret = array();

    //Make sure we don't overflow our buffers
    if (strlen($this -> outgoing_buffer) < INTERNAL_BUFFER) $ret[] = $this -> inner_socket;
    if (strlen($this -> incoming_buffer) < INTERNAL_BUFFER) $ret[] = $this -> outer_socket;

    return $ret;
  }

  //Return an array of socket that are ready to be written to
  public function writable_sockets() {
    if ($this -> inner_closed || $this -> outer_closed) return array();
    $ret = array();
    if (!empty($this -> incoming_buffer)) $ret[] = $this -> inner_socket;
    if (!empty($this -> outgoing_buffer)) $ret[] = $this -> outer_socket;
    return $ret;
  }

  //Return an array of socket that should be watched for exceptions
  public function exception_sockets() {
    if ($this -> inner_closed || $this -> outer_closed) return array();
    return array(
      $this -> inner_socket,
      $this -> outer_socket
    );
  }

  //Data available for reading in $sock
  public function read($sock) {
    if ($this -> shutdown) return;
    $read = @socket_read($sock, READ_SIZE);

    //Did we fail to read?
    if ($read === false || strlen($read) == 0) {
      if ($sock === $this -> inner_socket) {
        $this -> inner_closed = true;
      } else {
        $this -> outer_closed = true;
      }
      echo set_color(COLOR_RED);
      echo "Connection closed: ".$this -> get_endpoint()."\n";
      echo set_color(COLOR_DEFAULT);
      $this -> kill();
      return;
    }

    //Save to local buffer (depending on from which socket the data came)
    if ($sock === $this -> inner_socket) {
      $this -> outgoing_buffer .= $read;
    } else {
      $this -> incoming_buffer .= $read;
    }
  }

  //Data available for writing to $sock
  public function write($sock) {

    //Attempt to write the buffer
    $written = @socket_write($sock,
      ($sock === $this->inner_socket ? $this->incoming_buffer : $this->outgoing_buffer)
    );

    //Did we fail to write?
    if ($written === false) {
      if ($sock === $this -> inner_socket) {
        $this -> inner_closed = true;
      } else {
        $this -> outer_closed = true;
      }
      echo set_color(COLOR_RED);
      echo "Write failed, killing ".$this -> get_endpoint()."\n";
      echo set_color(COLOR_DEFAULT);
      if ($this->inner_socket === $sock) {
        $this -> incoming_buffer = "";
      } else {
        $this -> outgoing_buffer = "";
      }
      $this -> kill();
      return;
    }

    //Remove the written data from the buffer
    if ($this->inner_socket === $sock) {
      $this -> incoming_buffer = substr($this -> incoming_buffer, $written, 0);
    } else {
      $this -> outgoing_buffer = substr($this -> outgoing_buffer, $written, 0);
    }
  }

  //Runs periodically, used to clean up etc
  public function periodical() {
    //Are we scheduled to shut down this connection?
    if ($this -> shutdown) {
      //Only close sockets once there is nothing more in the buffers
      if (strlen($this->incoming_buffer) == 0 && strlen($this->outgoing_buffer) == 0) {
        if (!$this -> inner_closed) socket_close($this -> inner_socket);
        if (!$this -> outer_closed) socket_close($this -> outer_socket);
        $this -> inner_closed = $this -> outer_closed = true;
      }
    }

    //TODO: Implement timeouts
  }

  private function get_endpoint() {
    return $this -> remote_addr . ":" . $this -> remote_port .
      " -> " . $this -> local_addr . ":" . $this -> local_port;
  }

}
?>
