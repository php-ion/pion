<?php

namespace ION\WebServer;


use ION\Server\Connect;

class Request extends \ION\HTTP\Request
{
    /**
     * @var Connect
     */
    private $_connect;
    private $_response;

    public function __construct(Connect $connect) {
        $this->_connect = $connect;
    }

    public function isWebSocket() : bool {
        return strtolower($this->hasHeader("connection")) === "upgrade"
            && strtolower($this->hasHeader("upgrade")) === "websocket";
    }

    public function isChunked() : bool {
        return $this->getHeaderLine("transfer-encoding") === "chunked";
    }

    public function isMultiParted() : bool {
        if ($this->hasHeader("content-type")) {
            return strpos($this->getHeaderLine("Content-Type"), "multipart/form-data") !== false;
        } else {
            return false;
        }
    }

    public function hasLength() {
        return $this->hasHeader("content-length");
    }

    public function getLength() : int {
        return $this->getHeaderLine("content-length");
    }

    public function isKeepAlive() : bool {
        return strtolower($this->hasHeader("connection")) === "keep-alive";
    }

    public function response() : Request {
        if ($this->_response) {

        }
    }
}