<?php

namespace ION;


use ION;
use ION\Server\Connect;
use PHPUnit\Framework\TestCase;

class SocketServerTest extends TestCase
{
    const SERVER_ADDR = "127.0.0.1:8967";
    public $data = [];
    /**
     * @var SocketServer
     */
    public $server;

    public static function microWait() : Deferred
    {
        return ION::await(0.02);
    }

    public static function secondWait() : Deferred
    {
        return ION::await(1.0);
    }

    public function setUp()
    {
        parent::setUp();
        $this->data = [];
        $this->server = new SocketServer();
        $this->server->listen(self::SERVER_ADDR);
    }

    public function tearDown()
    {
        $this->server->shutdown();
        $this->server = null;
        parent::tearDown();
    }

    public function out($str) {
        var_dump($str);
        ob_flush();
    }


    public function testAccept()
    {
        $server = $this->server;
        $server->whenAccepted()->then(function (Connect $con) {
            $this->data["connect"] = $con->getPeerName();
            $this->data["request"] = yield $con->read(4);
            yield $con->write("PONG")->flush();
            $con->shutdown(true);
        })->onFail(function (\Throwable $e) {
            $this->data["server_error"] = $e->getMessage();
            ION::stop();
        });

        ION::promise(function () {
            $socket = Stream::socket(self::SERVER_ADDR);
            $socket->write("PING");
            $this->data["response"] = yield $socket->readAll();
            ION::stop();
        })->onFail(function (\Throwable $e) {
            $this->data["client_error"] = $e->getMessage();
            ION::stop();
        });;

        ION::dispatch();

        $this->assertArrayNotHasKey("client_error", $this->data, var_export($this->data, true));
        $this->assertArrayNotHasKey("server_error", $this->data, var_export($this->data, true));
        $this->assertArrayHasKey("connect", $this->data);
        $this->assertStringMatchesFormat("127.0.0.1:%i", $this->data["connect"]);
        $this->assertArrayHasKey("request", $this->data);
        $this->assertSame("PING", $this->data["request"]);
        $this->assertArrayHasKey("response", $this->data);
        $this->assertSame("PONG", $this->data["response"]);
    }


    public function testMaxConnections() {
        $server = $this->server;
        $server->setMaxConnections(3);
        $this->data["connects"] = [];

        $server->whenAccepted()->then(function(Connect $connect) {
            $this->data["connects"][$connect->getPeerName()] = $connect;
        });

        $server->whenDisconnected()->then(function(Connect $connect) {
            unset($this->data["connects"][$connect->getPeerName()]);
        });

        ION::promise(function() use ($server) {
            $this->assertCount(0, $this->data["connects"]);

            $socket1 = Stream::socket(self::SERVER_ADDR);
            $socket2 = Stream::socket(self::SERVER_ADDR);

            yield self::microWait();

            // in pool - 2 conns
            $this->assertCount(2, $this->data["connects"]);
            $this->assertSame(2, $server->getConnectionsCount());
            $this->assertArrayHasKey($socket1->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket2->getLocalName(), $this->data["connects"]);
            $socket3 = Stream::socket(self::SERVER_ADDR);

            yield self::microWait();

            // in pool - 3 conns
            $this->assertCount(3, $this->data["connects"]);
            $this->assertSame(3, $server->getConnectionsCount());
            $this->assertArrayHasKey($socket1->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket2->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket3->getLocalName(), $this->data["connects"]);
            $socket4 = Stream::socket(self::SERVER_ADDR);

            yield self::microWait();

            // in pool - 3 conns; in backlog - 1 conn
            $this->assertCount(3, $this->data["connects"]);
            $this->assertSame(3, $server->getConnectionsCount());
            $this->assertArrayHasKey($socket1->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket2->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket3->getLocalName(), $this->data["connects"]);
            $this->assertArrayNotHasKey($socket4->getLocalName(), $this->data["connects"]);
            $socket1->shutdown(true);

            yield self::microWait();

            // closed - 1 conn; in pool - 3 conns;
            $this->assertCount(3, $this->data["connects"]);
            $this->assertSame(3, $server->getConnectionsCount());
            $this->assertArrayNotHasKey($socket1->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket2->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket3->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket4->getLocalName(), $this->data["connects"]);
            $socket5 = Stream::socket(self::SERVER_ADDR);

            yield self::microWait();

            // closed - 1 conn; in pool - 3 conns; in backlog - 1 conn
            $this->assertCount(3, $this->data["connects"]);
            $this->assertSame(3, $server->getConnectionsCount());
            $this->assertArrayNotHasKey($socket1->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket2->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket3->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket4->getLocalName(), $this->data["connects"]);
            $this->assertArrayNotHasKey($socket5->getLocalName(), $this->data["connects"]);
            $server->setMaxConnections(4);

            yield self::microWait();

            // closed - 1 conn; in pool - 4 conns
            $this->assertCount(4, $this->data["connects"]);
            $this->assertSame(4, $server->getConnectionsCount());
            $this->assertArrayNotHasKey($socket1->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket2->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket3->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket4->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket5->getLocalName(), $this->data["connects"]);
            $socket2->shutdown(true);
            $socket3->shutdown(true);

            yield self::microWait();

            // closed - 3 conns; in pool - 2 conns
            $this->assertCount(2, $this->data["connects"]);
            $this->assertSame(2, $server->getConnectionsCount());
            $this->assertArrayNotHasKey($socket1->getLocalName(), $this->data["connects"]);
            $this->assertArrayNotHasKey($socket2->getLocalName(), $this->data["connects"]);
            $this->assertArrayNotHasKey($socket3->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket4->getLocalName(), $this->data["connects"]);
            $this->assertArrayHasKey($socket5->getLocalName(), $this->data["connects"]);
            $socket4->shutdown(true);
            $socket5->shutdown(true);

            yield self::microWait();

            // closed - 5 conns
            $this->assertCount(0, $this->data["connects"]);
            $this->assertSame(0, $server->getConnectionsCount());
            $this->assertArrayNotHasKey($socket1->getLocalName(), $this->data["connects"]);
            $this->assertArrayNotHasKey($socket2->getLocalName(), $this->data["connects"]);
            $this->assertArrayNotHasKey($socket3->getLocalName(), $this->data["connects"]);
            $this->assertArrayNotHasKey($socket4->getLocalName(), $this->data["connects"]);
            $this->assertArrayNotHasKey($socket5->getLocalName(), $this->data["connects"]);

            ION::stop();
        })->onFail(function (\Throwable $e) {
            $this->data["error"] = $e;
            ION::stop();
        });

        ION::dispatch();

        if(isset($this->data["error"])) {
            throw $this->data["error"];
        }
    }

    public function testReserveRelease() {

    }

    public function testTimeout() {
        $server = $this->server;
        $server->setIdleTimeout(1);

        $server->whenAccepted()->then(function(Connect $connect) {
            $this->data["connects"][$connect->getPeerName()] = $connect;
            $connect->release();
        });

        $server->whenDisconnected()->then(function(Connect $connect) {
            unset($this->data["connects"][$connect->getPeerName()]);
        });

        $server->whenTimeout()->then(function (Connect $connect) {
            $this->data["timeouts"][$connect->getPeerName()] = time();
        });

        ION::promise(function() use ($server) {
            $socket1 = Stream::socket(self::SERVER_ADDR);

            yield self::microWait();

        });

        $this->assertTrue(true);

    }

}