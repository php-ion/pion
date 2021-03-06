<?php

namespace ION;

use ION\Server\Connect;

class SocketServer {
    const STATUS_DISABLED = 1;

    const STATE_IDLE = 'idle';
    /**
     * @var Listener[]
     */
    private $_listeners = [];

    private $_max_conns = PHP_INT_MAX;

    /**
     * @var float
     */
    private $_idle_timeout = 30;

    /**
     * @var float
     */
    private $_request_timeout = 0;

    /**
     * @var Sequence
     */
    private $_accepted;
    /**
     * @var Sequence
     */
    private $_when_idle_timeout;
    /**
     * @var Sequence
     */
    private $_when_req_timeout;
    /**
     * @var Sequence
     */
    private $_disconnect;
    /**
     * @var Sequence
     */
    private $_close;

    /**
     * @var Connect[]
     */
    private $_peers = [];

    private $_stats = [
        "pool_size" => 0,
        "peers" => 0
    ];

    /**
     * @var \SplPriorityQueue
     */
    private $_pool;

    /**
     * @var \ArrayObject[]
     */
    private $_slots = [];

    private $_stream_class = Connect::class;

    private $_flags = 0;


    public function __construct() {
        $this->_pool              = new \SplPriorityQueue();
        $this->_accepted          = new Sequence([$this, "_accept"]);
        $this->_close             = new Sequence();
        $this->_disconnect        = new Sequence();
        $this->_when_idle_timeout = new Sequence();
        $this->_when_req_timeout  = new Sequence();

        $this->_pool->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    protected function _setConnectionClass(string $class) {
        if (!is_subclass_of($class, Connect::class)) {
            throw new \InvalidArgumentException("Connection class have to extends " . Connect::class);
        }
        foreach ($this->_listeners as $listener) {
            $listener->setStreamClass($class);
        }
    }

    /**
     * Enable server's listeners
     */
    public function enable() {
        foreach ($this->_listeners as $listener) {
            $listener->enable();
        }
        $this->_flags &= ~self::STATUS_DISABLED;
    }

    /**
     * Disable server's listeners
     */
    public function disable() {
        foreach ($this->_listeners as $listener) {
            $listener->disable();
        }
        $this->_flags |= self::STATUS_DISABLED;
    }

    /**
     * @param Connect $connect
     *
     * @return Connect
     */
    protected function _accept(Connect $connect) {
        $connect->setup($this);
        $this->_peers[$connect->getPeerName()] = $connect;
        if(count($this->_peers) >= $this->_max_conns) {
            $this->disable();
        }
        $connect->closed()->then([$this, "_disconnect"]);
        $connect->incoming()->then([$this, "_data"]);
        $connect->release();
        return $connect;
    }

    private function _data(Connect $connect) {
        if($connect) {
            $connect->busy();
        }
        yield \ION::promise($this->_request($connect));
        $connect->release();
    }

    /**
     * @param Connect $connect
     * @return \Generator|Promise|mixed
     */
    protected function _request(Connect $connect) {

    }

    /**
     * @param Connect $connect
     *
     * @return Connect
     */
    protected function _disconnect(Connect $connect) {
        unset($this->_peers[$connect->getPeerName()]);
        if(count($this->_peers) < $this->_max_conns) {
            $this->enable();
        }
        $this->_disconnect->__invoke($connect);
    }

    /**
     * @param Connect $connect
     */
    protected function _timeout(Connect $connect) {
        if($connect->isBusy()) {
            if($this->_when_req_timeout) {
               $this->_when_req_timeout->__invoke($connect);
            }
        } else {
            if($this->_when_idle_timeout) {
               $this->_when_idle_timeout->__invoke($connect);
            }
        }
    }

    /**
     *
     * @return Sequence
     */
    public function whenAccepted() : Sequence {
        return $this->_accepted;
    }

    /**
     * @return Sequence
     */
    public function whenDisconnected() : Sequence {
        return $this->_disconnect;
    }

    /**
     * @return Sequence
     */
    public function whenIdleTimeout() : Sequence {
        return $this->_when_idle_timeout;
    }

    /**
     * @return Sequence
     */
    public function whenRequestTimeout() : Sequence {
        return $this->_when_req_timeout;
    }

    /**
     * @return Sequence
     */
    public function whenClose() : Sequence {
        return $this->_close;
    }

    /**
     * Listen address
     * @param string $address
     * @param int $back_log
     *
     * @return Listener
     */
    public function listen(string $address, int $back_log = -1) : Listener {
        $listener = $this->_listeners[$address] = new Listener($address, $back_log);
        $listener->whenAccepted()->then($this->_accepted);
        $listener->setStreamClass($this->_stream_class);
        return $listener;
    }


    /**
     * @param string $address
     *
     * @return Listener
     */
    public function getListener(string $address) : Listener {
        return $this->_listeners[$address];
    }

    /**
     * @param string $peer
     *
     * @return bool
     */
    public function hasConnection(string $peer) : bool {
        return isset($this->_peers[$peer]);
    }

    /**
     * @param string $peer
     *
     * @return Connect|null
     */
    public function getConnection(string $peer) {
        return $this->_peers[$peer] ?? null;
    }

    /**
     * Get all connections
     * @return Connect[]
     */
    public function getConnections() : array {
        return $this->_peers;
    }

    /**
     * @return int
     */
    public function getConnectionsCount() : int {
        return count($this->_peers);
    }


    /**
     * @param int $max
     */
    public function setMaxConnections(int $max) {
        if($max < 0) {
            $this->_max_conns = PHP_INT_MAX;
        } else {
            $this->_max_conns = $max;
        }
        if($this->_flags & self::STATUS_DISABLED) {
            $this->enable();
        }
    }

    public function getMaxConnections() : int {
        return $this->_max_conns;
    }

    /**
     * @param float $secs
     */
    public function setIdleTimeout(float $secs) {
        $this->_idle_timeout = $secs;
    }

    /**
     * @return float
     */
    public function getIdleTimeout() : float {
        return $this->_idle_timeout;
    }

     /**
     * @param float $secs
     */
    public function setRequestTimeout(float $secs) {
        $this->_request_timeout = $secs;
    }

    /**
     * @return float
     */
    public function getRequestTimeout() : float {
        return $this->_request_timeout;
    }


    /**
     * @param Connect $socket
     * @param float $timeout
     */
    public function setTimeout(Connect $socket, float $timeout) {
        $timeout = -(microtime(true) + $timeout);
        $this->unsetTimeout($socket);
        if(!isset($this->_slots[$timeout])) {
            $this->_slots[$timeout] = $slot = new \ArrayObject();
            $slot->timeout = $timeout;
            $this->_pool->insert($slot, $timeout);
        } else {
            $slot = $this->_slots[$timeout];
        }
        $socket->timeout = -$timeout;
        $slot[$socket->getPeerName()] = $socket;
        $socket->slot = $slot;
    }

    /**
     * Remove timeout for connect
     * @param Connect $socket
     */
    public function unsetTimeout(Connect $socket) {
        if(isset($socket->slot)) {
            unset($socket->slot[$socket->getPeerName()]);
            if(!$socket->slot->count()) {
                unset($this->_slots[$socket->slot->timeout]);
            }
            unset($socket->slot, $socket->timeout_cb);
        }
    }

    /**
     * @param Connect $connect
     */
    public function release(Connect $connect) {
        $connect->resume();
        if ($this->_idle_timeout > 0) {
            $this->setTimeout($connect, $this->_idle_timeout);
        } elseif ($this->_idle_timeout === 0) {
            $connect->shutdown();
        }
    }

    /**
     * @param Connect $connect
     */
    public function reserve(Connect $connect) {
        $connect->suspend();
        if ($this->_request_timeout) {
            $this->setTimeout($connect, $this->_request_timeout);
        } else {
            $this->unsetTimeout($connect);
        }

    }

    /**
     * Inspect connections
     */
    public function inspect() : array {
        $time = microtime(true);
        $e    = null;
        while($this->_pool->count() && ($item = $this->_pool->top())) {
            if($time >= abs($item["priority"])) {
                $slot = $item["data"];
                /* @var \ArrayObject $slot */
                foreach((array)$slot as $peer => $socket) {
                    /* @var Connect $socket */
                    $this->unsetTimeout($socket);
                    try {
                        $this->_timeout($socket);
                    } catch(\Throwable $e) {
                        $socket->shutdown();
                    }
                }
                $this->_pool->extract();
            } else {
                break;
            }
        }
        if($e) {
            throw new \RuntimeException(
                "During the inspection there were errors. Last saved as the previous exception.", 0, $e);
        }
        $this->_stats["pool_size"] = $this->_pool->count();
        $this->_stats["peers"] = count($this->_peers);
        return $this->_stats;
    }

    public function shutdown() {
        foreach ($this->_listeners as $listener) {
            $listener->shutdown();
        }
        $this->_listeners = [];
    }

    public function __destruct()
    {
        $this->shutdown();
    }
}