<?php

namespace ION\Server;

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


    public function __construct() {
        $this->ts = microtime(1);
    }

    public function getConnectTimeStamp() : float {
        return $this->ts;
    }
}