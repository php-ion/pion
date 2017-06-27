<?php

namespace ION\Server;

use ION\SocketServer;
use ION\Stream;

class Connect extends Stream
{
    /**
     * @var callable
     */
    public $timeout_cb;
    /**
     * @var float
     */
    public $ts;

    /**
     * @var int
     */
    public $timeout;

    /**
     * @var \ArrayObject
     */
    public $slot;
    /**
     * @var SocketServer
     */
    public $server;
    /**
     * @var mixed
     */
    public $request;


    public function __construct()
    {
        $this->ts = microtime(1);
    }

    public function getConnectTime() : float
    {
        return $this->ts;
    }

    public function setup(SocketServer $server)
    {
        $this->server = $server;
        return $this;
    }

    public function busy() {
        if ($this->server) {
            $this->server->reserve($this);
        }
    }

    public function release()
    {
        if ($this->server) {
            $this->server->release($this);
        }
    }

    public function getServer() {
        return $this->server;
    }

}