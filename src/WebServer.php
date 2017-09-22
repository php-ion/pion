<?php

namespace ION;


use ION\HTTP\Request;
use ION\Server\Connect;
use ION\WebServer\Response;

class WebServer extends SocketServer
{

    /**
     * @var callable
     */
    private $_request_handler;
    /**
     * @var callable
     */
    private $_frame_handler;
    /**
     * @var Sequence
     */
    private $_when_request;
    /**
     * @var Sequence
     */
    private $_when_frame;

    private $_max_headers_size = 8 * KiB;
    private $_max_body_size    = 4 * MiB;
    private $_max_frame_size   = 1 * MiB;

    private $_ping_interval     = 30;
    private $_pong_timeout      = 20;
    private $_frame_concurrence = 10;

    public function __construct() {
        parent::__construct();
        $this->whenAccepted()->then(function (Connect $con) {
            $con->setState("headers");
            $headers = yield $con->readLine("\r\n\r\n", Stream::MODE_TRIM_TOKEN, $this->_max_headers_size);
            if (!$headers) {
                $con->setState("shutdown");
                $con->write($this->error(400));
                $con->shutdown();
                $con->release();
            }
            $request = Request::parse($headers);
            // ...
            $con->setState("headers");
            $this->_when_request->__invoke($request);
        });
    }

    public function whenRequest(callable $request_handler = null) : Sequence {
        if ($request_handler) {
            $this->_request_handler = $request_handler;
        }
        return $this->_when_request;
    }

    public function whenWSFrame(callable $frame_handler = null) : Sequence {
        if ($frame_handler) {
            $this->_frame_handler = $frame_handler;
        }
        return $this->_when_frame;
    }

    public function setMaxHeadersSize(int $size) {
        $this->_max_headers_size = $size;
        return $this;
    }

    public function setMaxBodySize(int $size) {
        $this->_max_body_size = $size;
        return $this;
    }

    public function setMaxFrameSize(int $size) {
        $this->_max_frame_size = $size;
        return $this;
    }

    public function setPingInterval(int $sec) {
        $this->_ping_interval = $sec;
        return $this;
    }

    public function getPingInterval() : int {
        return $this->_ping_interval;
    }

    public function setPongTimeout(int $sec) {
        $this->_pong_timeout = $sec;
        return $this;
    }

    public function getPongTimeout() {
        return $this->_pong_timeout;
    }

    /**
     * Set count concurrence frames per connect
     * @param int $count
     */
    public function setFrameConcurrence(int $count) {
        $this->_frame_concurrence = $count;
    }

    public function getFrameConcurrence() : int {
        return $this->_frame_concurrence;
    }

    public function error(int $code) : Response {
        $resp = new Response();
        $resp->withStatus($code);
        return $resp;
    }

}