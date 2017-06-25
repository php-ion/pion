<?php

namespace ION\Server;

use ION\RawServer;
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
     * @var RawServer
     */
    public $server;


    public function __construct()
    {
        $this->ts = microtime(1);
    }

    public function getConnectTime() : float
    {
        return $this->ts;
    }

    public function setup(RawServer $server)
    {
        $this->server = $server;
        return $this;
    }

    public function release()
    {
        if ($this->server) {
            $this->server->release($this);
        }
    }

}