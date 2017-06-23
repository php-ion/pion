<?php

namespace ION;


use ION\HTTP\Request;
use ION\Server\Connect;

class HTTPServer extends RawServer
{

    /**
     * @var Sequence
     */
    private $_when_request;

    private $_max_headers_size = 8 * KiB;

    public function __construct() {
        parent::__construct();
        $this->whenAccepted()->then(function (Connect $con) {
            $headers = $con->readLine("\r\n\r\n", Stream::MODE_TRIM_TOKEN, $this->_max_headers_size);
            $request = Request::parse($headers);

            // ...

            $this->_when_request->__invoke($request);
        });
    }

    public function whenRequest() : Sequence {
        return $this->_when_request;
    }
}