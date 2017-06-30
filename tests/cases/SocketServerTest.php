<?php

namespace ION;


use ION;
use ION\Server\Connect;
use PHPUnit\Framework\TestCase;

class SocketServerTest extends TestCase
{
    const SERVER_ADDR = "127.0.0.1:8967";

    const MICRO_WAIT_TIME = 0.02;
    const MINI_WAIT_TIME = 0.05;

    const TIME_DELTA = 0.025;

    public $data = [];
    /**
     * @var SocketServer
     */
    public $server;

    /**
     * Wait 0.02 sec
     * @return Deferred
     */
    public static function microWait() : Deferred
    {
        return ION::await(self::MICRO_WAIT_TIME);
    }

    /**
     * Wait 0.05 sec
     * @return Deferred
     */
    public static function miniWait() : Deferred
    {
        return ION::await(self::MINI_WAIT_TIME);
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

    public function testTimeout() {
        $server = $this->server;
        $server->setIdleTimeout(0.1);
        $server->setRequestTimeout(0.2);
        $this->assertEquals(0.1, $server->getIdleTimeout());
        $this->assertEquals(0.2, $server->getRequestTimeout());\

        ION::promise(function() use ($server) {
            /* @var Connect $con */

            // checks idle timeout
            $start = microtime(true);
            $socket1 = Stream::socket(self::SERVER_ADDR);
            yield self::microWait();
            $this->assertSame(1, $server->getConnectionsCount());
            $con = yield $server->whenIdleTimeout();
            $checkpoint = microtime(true) - $start;
            $this->assertSame($socket1->getLocalName(), $con->getPeerName());
            $this->assertEquals(0.1, $checkpoint, '', self::TIME_DELTA);
            $this->assertSame(1, $server->getConnectionsCount());

            // checks request timeout
            $start = microtime(true);
            $this->assertSame(1, $server->getConnectionsCount());
            $con->busy();
            $con = yield $server->whenRequestTimeout();
            $checkpoint = microtime(true) - $start;
            $this->assertSame($socket1->getLocalName(), $con->getPeerName());
            $this->assertEquals(0.2, $checkpoint, '', self::TIME_DELTA);
            $this->assertSame(1, $server->getConnectionsCount());

            // checks 0.25 request timeout and full idle timeout
            $start = microtime(true);
            $this->assertSame(1, $server->getConnectionsCount());
            $con->busy();
            yield self::miniWait();
            $con->release();
            $con = yield $server->whenIdleTimeout();
            $checkpoint = microtime(true) - $start;
            $this->assertSame($socket1->getLocalName(), $con->getPeerName());
            $this->assertEquals(0.15, $checkpoint, '', self::TIME_DELTA);
            $this->assertSame(1, $server->getConnectionsCount());

            ION::stop();
        })->onFail(function (\Throwable $e) {
            $this->data["error"] = $e;
            ION::stop();
        });

        ION::interval(0.05, "phpunit")->then([$server, "inspect"])->onFail(function (\Throwable $e) {
            $this->data["error"] = $e;
            ION::stop();
        });

        ION::dispatch();

        ION::cancelInterval("phpunit");
        if(isset($this->data["error"])) {
            throw $this->data["error"];
        }
    }

}