<?php
$GLOBALS['THRIFT_ROOT'] = dirname(__FILE__) . '/thrift/';
require_once $GLOBALS['THRIFT_ROOT'].'/packages/cassandra/Cassandra.php';
require_once $GLOBALS['THRIFT_ROOT'].'/transport/TSocket.php';
require_once $GLOBALS['THRIFT_ROOT'].'/protocol/TBinaryProtocol.php';
require_once $GLOBALS['THRIFT_ROOT'].'/transport/TFramedTransport.php';
require_once $GLOBALS['THRIFT_ROOT'].'/transport/TBufferedTransport.php';

/**
 * @package phpcassa
 * @subpackage connection
 */
class NoServerAvailable extends Exception { }

/**
 * @package phpcassa
 * @subpackage connection
 */
class IncompatibleAPIException extends Exception { }

class MaxRetriesException extends Exception { }

/**
 * @package phpcassa
 * @subpackage connection
 */
class Connection {

    const LOWEST_COMPATIBLE_VERSION = 17;
    public $keyspace;
    public $client;
    public $op_count;

    public function __construct($keyspace,
                                $server,
                                $credentials=null,
                                $framed_transport=True,
                                $send_timeout=null,
                                $recv_timeout=null)
    {
        $this->server = $server;
        $server = explode(':', $server);
        $host = $server[0];
        if(count($server) == 2)
            $port = (int)$server[1];
        else
            $port = 9160;
        $socket = new TSocket($host, $port);

        if($send_timeout) $socket->setSendTimeout($send_timeout);
        if($recv_timeout) $socket->setRecvTimeout($recv_timeout);

        if($framed_transport) {
            $transport = new TFramedTransport($socket, true, true);
        } else {
            $transport = new TBufferedTransport($socket, 1024, 1024);
        }

        $client = new CassandraClient(new TBinaryProtocolAccelerated($transport));
        $transport->open();

        $server_version = explode(".", $client->describe_version());
        $server_version = $server_version[0];
        if ($server_version < self::LOWEST_COMPATIBLE_VERSION) {
            $ver = self::LOWEST_COMPATIBLE_VERSION;
            throw new IncompatibleAPIException("The server's API version is too ".
                "low to be comptible with phpcassa (server: $server_version, ".
                "lowest compatible version: $ver)");
        }

        $client->set_keyspace($keyspace);

        if ($credentials) {
            $request = cassandra_AuthenticationRequest($credentials);
            $client->login($request);
        }

        $this->keyspace = $keyspace;
        $this->client = $client;
        $this->transport = $transport;
        $this->op_count = 0;
    }

    public function close() {
        $this->transport->close();
    }

}

class ConnectionPool {

    const BASE_BACKOFF = 0.1;
    const MICROS = 1000000;
    private static $default_servers = array('localhost:9160');

    public $keyspace;
    private $servers;
    private $pool_size;
    private $timeout;
    private $recycle;
    private $max_retries;
    private $credentials;
    private $framed_transport;
    private $queue;

    public function __construct($keyspace,
                                $servers=NULL,
                                $max_retries=5,
                                $send_timeout=1,
                                $recv_timeout=1,
                                $recycle=10000,
                                $credentials=NULL,
                                $framed_transport=true)
    {
        $this->keyspace = $keyspace;
        $this->send_timeout = $send_timeout;
        $this->recv_timeout = $recv_timeout;
        $this->recycle = $recycle;
        $this->max_retries = $max_retries;
        $this->credentials = $credentials;
        $this->framed_transport = $framed_transport;

        $this->stats = array(
            'created' => 0,
            'failed' => 0,
            'recycled' => 0);

        if ($servers == NULL)
            $servers = self::$default_servers;
        $this->servers = $servers;
        $this->pool_size = max(count($this->servers) * 2, 5);

        $this->queue = array();

        // Randomly permute the server list
        $n = count($servers);
        if ($n > 1) {
            foreach (range(0, $n - 1) as $i) {
                $j = rand($i, $n - 1);
                $temp = $servers[$j];
                $servers[$j] = $servers[$i];
                $servers[$i] = $temp;
            }
        }
        $this->list_position = 0;

        foreach(range(0, $this->pool_size - 1) as $i)
            $this->make_conn();
    }

    private function make_conn() {
        // Keep trying to make a new connection, stopping after we've
        // tried every server twice
        foreach(range(1, count($this->servers) * 2) as $i)
        {
            try {
                $new_conn = new Connection($this->keyspace, $this->servers[$this->list_position],
                    $this->credentials, $this->framed_transport, $this->send_timeout, $this->recv_timeout);
                array_push($this->queue, $new_conn);
                $this->list_position = ($this->list_position + 1) % count($this->servers);
                $this->stats['created'] += 1;
                return;
            } catch (TException $e) {
                $h = $this->servers[$this->list_position];
                $err = (string)$e;
                error_log("Error connecting to $h: $err", 0);
            }
        }
    }

    public function get() {
        return array_shift($this->queue);
    }

    public function return_connection($connection) {
        if ($connection->op_count >= $this->recycle) {
            $this->stats['recycled'] += 1;
            $connection->close();
            $this->make_conn();
            $connection = $this->get();
        }
        array_push($this->queue, $connection);
    }

    public function dispose() {
        foreach($this->queue as $conn)
            $conn->close();
    }

    public function close() {
        $this->dispose();
    }

    public function stats() {
        return $this->stats;
    }

    public function call() {
        $args = func_get_args(); // Get all of the args passed to this function
        $f = array_shift($args); // pull the function from the beginning
        $retry_count = 0;
        foreach (range(1, $this->max_retries) as $retry_count) {
            $conn = $this->get();

            $conn->op_count += 1;
            try {
                $resp = call_user_func_array(array($conn->client, $f), $args);
                $this->return_connection($conn);
                return $resp;
            } catch (cassandra_TimedOutException $toe) {
                $this->handle_conn_failure($conn, $f, $toe, $retry_count);
            } catch (cassandra_UnavailableException $ue) {
                $this->handle_conn_failure($conn, $f, $ue, $retry_count);
            }
        }
        throw new MaxRetriesException();
    }

    private function handle_conn_failure($conn, $f, $exc, $retry_count) {
        $err = (string)$exc;
        error_log("Error performing $f on $conn->server: $err", 0);
        $conn->close();
        $this->stats['failed'] += 1;
        usleep(self::BASE_BACKOFF * pow(2, $retry_count) * self::MICROS);
        $this->make_conn();
    }

}
?>
