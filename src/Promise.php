<?php

namespace JefyOkta\PhpPromise;

use BadMethodCallException;
use JefyOkta\PhpPromise\Exception\AggregateError;
use JefyOkta\PhpPromise\Exception\PromiseException;
use Throwable;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

use function Swoole\Coroutine\run;

/**
 * @template T
 */
class Promise
{
    private PromiseState $state = PromiseState::Pending;

    /** @var T|null */
    private mixed $value = null;

    /** @var mixed */
    private mixed $reason = null;

    /** @var array<int, array{onFulfilled: callable, onRejected: callable, next: Promise}> */
    private array $handlers = [];

    /**
     * @param null|callable(callable(T):void, callable(mixed):void):void $executor
     */
    public function __construct(?callable $executor = null)
    {
        if (!$executor) return;

        spawn(function () use ($executor) {
            try {
                $executor(
                    fn($value)  => $this->fulfill($value),
                    fn($reason) => $this->rejectInstance($reason)
                );
            } catch (Throwable $e) {
                $this->rejectInstance($e);
            }
        });
    }

    /**
     * @param T|Promise<T> $value
     */
    private function fulfill(mixed $value): void
    {
        if ($this->state !== PromiseState::Pending) return;

        if ($value instanceof self) {
            $value->then(
                fn($v) => $this->fulfill($v),
                fn($r) => $this->rejectInstance($r)
            );
            return;
        }

        $this->state = PromiseState::Fulfilled;
        $this->value = $value;

        $this->flush();
    }

    /**
     * @param mixed $reason
     */
    private function rejectInstance(mixed $reason): void
    {
        if ($this->state !== PromiseState::Pending) return;

        $this->state = PromiseState::Rejected;
        $this->reason = $reason;

        $this->flush();
    }

    private function flush(): void
    {
        foreach ($this->handlers as $handler) {
            $this->dispatch($handler);
        }
        $this->handlers = [];
    }

    /**
     * @param array{onFulfilled: callable, onRejected: callable, next: Promise} $handler
     */
    private function dispatch(array $handler): void
    {
        ['onFulfilled' => $onFulfilled, 'onRejected' => $onRejected, 'next' => $next] = $handler;


        spawn(function () use ($onFulfilled, $onRejected, $next) {
            try {
                if ($this->state === PromiseState::Fulfilled) {
                    $result = $onFulfilled($this->value);
                } else {
                    $result = $onRejected($this->reason);
                }

                $next->fulfill($result);
            } catch (Throwable $e) {
                $next->rejectInstance($e);
            }
        });
    }

    /**
     * @template U
     * @param null|callable(T):U|Promise<U> $onFulfilled
     * @param null|callable(mixed):U|Promise<U> $onRejected
     * @return Promise<U>
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): self
    {
        $onFulfilled ??= fn($v) => $v;

        $onRejected ??= function ($r) {
            throw $r instanceof Throwable
                ? $r
                : new PromiseException((string)$r);
        };

        $next = new self();

        $handler = [
            'onFulfilled' => $onFulfilled,
            'onRejected'  => $onRejected,
            'next'        => $next
        ];

        if ($this->state === PromiseState::Pending) {
            $this->handlers[] = $handler;
        } else {
            $this->dispatch($handler);
        }

        return $next;
    }

    /**
     * @param callable(mixed):T|Promise<T> $onRejected
     * @return Promise<T>
     */
    public function catch(callable $onRejected): self
    {
        return $this->then(null, $onRejected);
    }

    /**
     * @param callable():mixed $onFinally
     * @return Promise<T>
     */
    public function finally(callable $onFinally): self
    {
        return $this->then(
            function ($value) use ($onFinally) {
                $result = $onFinally();

                return $result instanceof self
                    ? $result->then(fn() => $value)
                    : $value;
            },
            function ($reason) use ($onFinally) {
                $result = $onFinally();

                if ($result instanceof self) {
                    return $result->then(function () use ($reason) {
                        throw $reason instanceof Throwable
                            ? $reason
                            : new PromiseException((string)$reason);
                    });
                }

                throw $reason instanceof Throwable
                    ? $reason
                    : new PromiseException((string)$reason);
            }
        );
    }

    /**
     * Wait for the promise to settle and return its value.
     *
     * A channel is used here because promise callbacks are dispatched
     * into detached coroutines. Coroutine::join / waitGroup would not
     * reliably capture the callback result without changing the dispatcher.
     *
     * @return mixed
     * @throws PromiseException
     */
    public function wait(): mixed
    {
        $channel = new Channel(1);

        $this->then(
            fn($value) => $channel->push(['ok', $value]),
            fn($reason) => $channel->push(['err', $reason])
        );

        $getResult = function () use ($channel) {
            [$status, $payload] = $channel->pop();
            $channel->close();

            return ['status' => $status, 'payload' => $payload];
        };

        if (Coroutine::getCid() > 0) {
            $result = $getResult();
        } else {
            $result = null;
            run(function () use ($getResult, &$result) {
                $result = $getResult();
            });
        }

        if ($result['status'] === 'err') {
            throw $result['payload'] instanceof Throwable
                ? $result['payload']
                : new PromiseException((string)$result['payload']);
        }

        return $result['payload'];
    }

    /**
     * @template V
     * @param V|Promise<V> $value
     * @return Promise<V>
     */
    public static function resolve(mixed $value = null): self
    {
        if ($value instanceof self) return $value;

        return new self(fn($resolve) => $resolve($value));
    }

    /**
     * @return Promise<never>
     */
    private static function rejectStatic(mixed $reason = null): self
    {
        return new self(fn($_, $reject) => $reject($reason));
    }

    /**
     * @template V
     * @param array<int, Promise<V>|V> $promises
     * @return Promise<array<int, V>>
     */
    public static function all(array $promises): self
    {
        return new self(function ($resolve, $reject) use ($promises) {

            if (!$promises) {
                $resolve([]);
                return;
            }

            $total = count($promises);
            $results = array_fill(0, $total, null);
            $resolved = 0;
            $done = false;

            foreach ($promises as $i => $p) {
                self::resolve($p)->then(
                    function ($v) use ($i, $total, &$results, &$resolved, &$done, $resolve) {
                        if ($done) return;

                        $results[$i] = $v;

                        if (++$resolved === $total) {
                            $done = true;
                            $resolve($results);
                        }
                    },
                    function ($r) use (&$done, $reject) {
                        if ($done) return;
                        $done = true;
                        $reject($r);
                    }
                );
            }
        });
    }

    /**
     * @template V
     * @param array<int, Promise<V>|V> $promises
     * @return Promise<V>
     */
    public static function race(array $promises): self
    {
        return new self(function ($resolve, $reject) use ($promises) {
            foreach ($promises as $p) {
                self::resolve($p)->then($resolve, $reject);
            }
        });
    }

    /**
     * @template V
     * @param array<int, Promise<V>|V> $promises
     * @return Promise<V>
     */
    public static function any(array $promises): self
    {
        return new self(function ($resolve, $reject) use ($promises) {

            if (!$promises) {
                $reject(new AggregateError([]));
                return;
            }

            $total = count($promises);
            $errors = [];
            $rejected = 0;

            foreach ($promises as $i => $p) {
                self::resolve($p)->then(
                    $resolve,
                    function ($r) use (&$errors, &$rejected, $total, $reject, $i) {
                        $errors[$i] = $r;

                        if (++$rejected === $total) {
                            $reject(new AggregateError($errors));
                        }
                    }
                );
            }
        });
    }

    public function __call(string $name, mixed $arguments)
    {
        if ($name !== 'reject') {
            throw new BadMethodCallException;
        }
        return $this->rejectInstance(...$arguments);
    }

    public static function __callStatic(string $name, mixed $arguments)
    {
        if ($name !== 'reject') {
            throw new BadMethodCallException;
        }

        return self::rejectStatic(...$arguments);
    }

    public function __toString()
    {
        return self::class . " { <" . strtolower($this->state->name) . "> } ";
    }
}
