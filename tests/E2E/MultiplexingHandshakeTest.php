<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Utopia\Cache\Adapter\Redis\ConnectionException;
use Utopia\Cache\Adapter\Redis\Multiplexing as RedisMultiplexing;
use Utopia\Cache\Adapter\Redis\TimeoutException;

use function Swoole\Coroutine\run;

/**
 * What the AUTH/SELECT handshake is allowed to wait for, and what it reports
 * when it gets nothing.
 *
 * The server here is a socket this test drives byte by byte rather than the
 * compose Redis: the failures being pinned down — a reply that is slow but
 * arrives, a peer that closes mid-handshake, a peer that dribbles bytes which
 * never complete a frame — are not ones a real server can be asked for.
 */
final class MultiplexingHandshakeTest extends TestCase
{
    private ?RedisMultiplexing $adapter = null;

    /** @var list<Socket> */
    private array $listeners = [];

    private function runCo(callable $fn): void
    {
        $error = null;
        run(function () use ($fn, &$error): void {
            try {
                $fn();
            } catch (\Throwable $th) {
                $error = $th;
            } finally {
                if ($this->adapter instanceof RedisMultiplexing) {
                    $this->adapter->disconnect();
                    $this->adapter = null;
                }
                foreach ($this->listeners as $listener) {
                    $listener->close();
                }
                $this->listeners = [];
            }
        });
        if ($error instanceof \Throwable) {
            throw $error;
        }
    }

    /**
     * Listens on an ephemeral port and hands every accepted connection to
     * $handler in its own coroutine. Returns the port to dial.
     *
     * @param  callable(Socket): void  $handler
     */
    private function listen(callable $handler): int
    {
        $listener = new Socket(AF_INET, SOCK_STREAM, 0);
        $this->assertTrue($listener->bind('127.0.0.1', 0), 'Failed to bind the fake Redis listener.');
        $this->assertTrue($listener->listen(16), 'Failed to listen on the fake Redis listener.');
        $this->listeners[] = $listener;

        $name = $listener->getsockname();
        $port = \is_array($name) ? $name['port'] ?? null : null;
        if (! \is_int($port)) {
            $this->fail('The fake Redis listener did not report a port.');
        }

        Coroutine::create(function () use ($listener, $handler): void {
            while (true) {
                $connection = $listener->accept(-1);
                if (! $connection instanceof Socket) {
                    return;
                }

                Coroutine::create(function () use ($connection, $handler): void {
                    try {
                        $handler($connection);
                    } finally {
                        $connection->close();
                    }
                });
            }
        });

        return $port;
    }

    /**
     * The Sentry regression: a server that answers AUTH slower than the
     * fail-fast per-call deadline, but well inside the connect budget.
     *
     * Before the fix the handshake read on $readTimeout — 0.25s by default, a
     * quarter of the connect timeout — so a connection that would have been
     * perfectly usable was abandoned, and the ConnectionException escaped the
     * constructor uncaught.
     */
    public function testHandshakeWaitsOutTheConnectBudgetNotThePerCallDeadline(): void
    {
        $this->runCo(function (): void {
            $port = $this->listen(function (Socket $connection): void {
                $connection->recv(65536, 1.0);
                Coroutine::sleep(0.3);
                $connection->send("+OK\r\n");
                // Stay open so the reader coroutine has a live socket.
                Coroutine::sleep(2.0);
            });

            $this->adapter = new RedisMultiplexing(
                '127.0.0.1',
                $port,
                timeout: 1.0,
                readTimeout: 0.05,
                auth: 'secret',
            );

            $this->assertSame('redis-multiplexing', $this->adapter->getName());
        });
    }

    /**
     * A peer that closes mid-handshake is a connection verdict, not a slow one.
     * Reporting it as "Timed out waiting for Redis response" sent everyone
     * reading the error after a latency problem that was not there.
     */
    public function testHandshakeClosedByPeerIsNotReportedAsATimeout(): void
    {
        $this->runCo(function (): void {
            $port = $this->listen(function (Socket $connection): void {
                $connection->recv(65536, 1.0);
                // No reply, no FIN delay: just go.
            });

            try {
                $this->adapter = new RedisMultiplexing(
                    '127.0.0.1',
                    $port,
                    timeout: 1.0,
                    readTimeout: 0.05,
                    auth: 'secret',
                );
                $this->fail('Expected the handshake to fail when the peer closed the socket.');
            } catch (ConnectionException $error) {
                $this->assertNotInstanceOf(TimeoutException::class, $error);
                $this->assertStringContainsString('Redis connection lost during handshake', $error->getMessage());
                $this->assertStringNotContainsString('Timed out', $error->getMessage());
            }
        });
    }

    /**
     * Silence past the connect budget is a timeout, and says which budget it
     * spent — the bare message carried no number, so the only way to tell which
     * deadline had expired was to read this file.
     */
    public function testHandshakeSilenceReportsTheBudgetItSpent(): void
    {
        $this->runCo(function (): void {
            $port = $this->listen(function (Socket $connection): void {
                $connection->recv(65536, 1.0);
                Coroutine::sleep(2.0);
            });

            try {
                $this->adapter = new RedisMultiplexing(
                    '127.0.0.1',
                    $port,
                    timeout: 0.3,
                    readTimeout: 0.05,
                    auth: 'secret',
                );
                $this->fail('Expected the handshake to time out against a silent server.');
            } catch (TimeoutException $timeout) {
                $this->assertStringContainsString('after 0.300s', $timeout->getMessage());
            }
        });
    }

    /**
     * The budget covers the whole reply, not one recv().
     *
     * A peer sending bytes that never complete a RESP frame re-armed the
     * per-recv deadline on every chunk, so the handshake had no bound at all:
     * here it only gave up once the dribble stopped, a full second late.
     */
    public function testHandshakeBudgetIsNotRearmedByEveryPartialChunk(): void
    {
        $this->runCo(function (): void {
            $port = $this->listen(function (Socket $connection): void {
                $connection->recv(65536, 1.0);
                // A frame that never terminates, dribbled for a second.
                for ($i = 0; $i < 20; $i++) {
                    $connection->send('+partial');
                    Coroutine::sleep(0.05);
                }
                Coroutine::sleep(2.0);
            });

            $started = microtime(true);

            try {
                $this->adapter = new RedisMultiplexing(
                    '127.0.0.1',
                    $port,
                    timeout: 0.3,
                    readTimeout: 0.25,
                    auth: 'secret',
                );
                $this->fail('Expected the handshake to time out on an incomplete frame.');
            } catch (TimeoutException $timeout) {
                $elapsed = microtime(true) - $started;
                $this->assertStringContainsString('after 0.300s', $timeout->getMessage());
                $this->assertLessThan(
                    0.8,
                    $elapsed,
                    'The handshake budget must bound the whole reply, not each recv().',
                );
            }
        });
    }
}
