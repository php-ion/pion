<?php

namespace ION;

use ION\Server\Connect;

class RawServer {

    /**
     * @var Listener[]
     */
    private $_listeners = [];

    private $_max_conns = PHP_INT_MAX;

    private $_idle_timeout = 30;

    /**
     * @var Sequence
     */
    private $_timeout;
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
     * @var array
     */
    private $_slots = [];

    /**
     * @var Sequence
     */
    private $_accepted;

    private $_stream_class = Connect::class;


    public function __construct() {
        $this->_pool = new \SplPriorityQueue();
        $this->_pool->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $this->_accepted   = new Sequence([$this, "_accept"]);
        $this->_close      = new Sequence();
        $this->_disconnect = new Sequence(/*[$this, "_disconnect"]*/);
        $this->_timeout    = new Sequence();
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

    public function enable() {
        foreach ($this->_listeners as $listener) {
            $listener->enable();
        }
    }

    public function disable(bool $temporary = false) {
        foreach ($this->_listeners as $listener) {
            $listener->disable();
        }
    }

    public function _accept(Connect $connect) {
        $this->_peers[$connect->getPeerName()] = $connect;
        if(count($this->_peers) >= $this->_max_conns) {
            $this->disable();
        }
        $connect->closed()->then([$this, "_disconnect"]);
        return $connect;
    }

    protected function _disconnect(Connect $connect) {
        unset($this->_peers[$connect->getPeerName()]);
        if(count($this->_peers) < $this->_max_conns) {
            $this->enable();
        }
        $this->_disconnect->__invoke($connect);
        return $connect;
    }

    public function whenAccepted() : Sequence {
        return $this->_accepted;
    }

    public function whenDisconnected() : Sequence {
        return $this->_disconnect;
    }

    public function whenTimeout() : Sequence {
        return $this->_timeout;
    }

    public function whenClose() : Sequence {
        return $this->_close;
    }

    public function getConnectionsCount() : int {
        return count($this->_peers);
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
     * @param int $max
     */
    public function setMaxConnections(int $max) {
        if($max < 0) {
            $this->_max_conns = PHP_INT_MAX;
        } else {
            $this->_max_conns = $max;
        }
    }

    /**
     * @param int $secs
     */
    public function setIdleTimeout(int $secs) {
        $this->_idle_timeout = $secs;
    }


    /**
     * @param Connect $socket
     * @param $timeout
     * @param callable $cb
     */
    public function setTimeout(Connect $socket, $timeout, callable $cb = null) {
        $timeout = -(time() + $timeout);
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
        if($cb) {
            $socket->timeout_cb = $cb;
        }
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
     * Inspect connections
     */
    public function inspect() : array {
        $time = time();
        while($this->_pool->count() && ($item = $this->_pool->top())) {
            if($time >= abs($item["priority"])) {
                $slot = $item["data"];
                /* @var \ArrayObject $slot */
                foreach((array)$slot as $peer => $socket) {
                    /* @var Connect $socket */
                    $this->unsetTimeout($socket);
                    try {
                        if($socket->timeout_cb) {
                            call_user_func($socket->timeout_cb, $socket);
                        } else {
                            $this->_timeout->__invoke($socket);
                        }
                    } catch(\Exception $e) {
                        $socket->shutdown();
                    }
                }
                $this->_pool->extract();
            } else {
                break;
            }
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