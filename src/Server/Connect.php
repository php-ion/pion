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
     * @var array
     */
    public $requests;
    public $request;
    public $concurrency = 0;
    public $busy = false;

    /**
     * @var string
     */
    public $state = 'none';


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
        $this->busy = true;
        if ($this->server) {
            $this->server->reserve($this);
        }
    }

    public function isBusy() {
        return $this->busy;
    }

    public function release()
    {
        $this->busy = false;
        if ($this->server) {
            $this->server->release($this);
        }
    }

    public function getServer() {
        return $this->server;
    }

    public function setState(string $state) {
        $this->state = $state;
    }

    public function getState() {
        return $this->state;
    }

    public function isState(string $state) {
        return $this->state == $state;
    }

    public function __debugInfo(): array
    {
        $info = parent::__debugInfo();
        $info["concurrency"] = $this->concurrency;
        return $info;
    }

}