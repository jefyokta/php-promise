<?php

use JefyOkta\PhpPromise\Promise;
use JefyOkta\PhpPromise\PromiseState;
use JefyOkta\PhpPromise\Exception\AggregateError;
use JefyOkta\PhpPromise\Exception\PromiseException;
use Swoole\Coroutine;


use function Swoole\Coroutine\run;

test('basic promise resolution', function () {
    $promise = new Promise(function ($resolve) {
        $resolve('success');
    });

    expect($promise->wait())->toBe('success');
});

test('basic promise rejection', function () {
    $promise = new Promise(function ($resolve, $reject) {
        $reject('error');
    });

    expect(fn() => $promise->wait())->toThrow(PromiseException::class);
});

test('promise then chaining', function () {
    $promise = new Promise(function ($resolve) {
        $resolve(2);
    });

    $result = $promise
        ->then(fn($x) => $x * 3)
        ->then(fn($x) => $x + 1)
        ->wait();

    expect($result)->toBe(7);
});

test('promise catch handling', function () {
    $promise = new Promise(function ($resolve, $reject) {
        $reject('original error');
    });

    $result = $promise
        ->catch(fn($error) => 'caught: ' . $error)
        ->wait();

    expect($result)->toBe('caught: original error');
});

test('promise finally execution', function () {
    $executed = false;

    $promise = new Promise(function ($resolve) {
        $resolve('done');
    });

    $result = $promise
        ->finally(function () use (&$executed) {
            $executed = true;
        })
        ->wait();

    expect($result)->toBe('done');
    expect($executed)->toBeTrue();
});

test('promise finally with rejection', function () {
    $executed = false;

    $promise = new Promise(function ($resolve, $reject) {
        $reject('error');
    });

    try {
        $promise
            ->finally(function () use (&$executed) {
                $executed = true;
            })
            ->wait();
    } catch (PromiseException) {
        // Expected
    }

    expect($executed)->toBeTrue();
});

test('Promise::resolve with value', function () {
    $promise = Promise::resolve('immediate');

    expect($promise->wait())->toBe('immediate');
});

test('Promise::resolve with promise', function () {
    $inner = new Promise(fn($resolve) => $resolve('nested'));
    $promise = Promise::resolve($inner);

    expect($promise->wait())->toBe('nested');
});

test('Promise::reject static method', function () {
    $promise = Promise::reject('static reject');

    expect(fn() => $promise->wait())->toThrow(PromiseException::class);
});

test('Promise::all with resolved promises', function () {
    $promises = [
        Promise::resolve(1),
        Promise::resolve(2),
        Promise::resolve(3),
    ];

    $result = Promise::all($promises)->wait();

    expect($result)->toBe([1, 2, 3]);
});

test('Promise::all with mixed values and promises', function () {
    $promises = [
        1,
        Promise::resolve(2),
        3,
    ];

    $result = Promise::all($promises)->wait();

    expect($result)->toBe([1, 2, 3]);
});

test('Promise::all with empty array', function () {
    $result = Promise::all([])->wait();

    expect($result)->toBe([]);
});

test('Promise::all with rejection', function () {
    $promises = [
        Promise::resolve(1),
        Promise::reject('error'),
        Promise::resolve(3),
    ];

    expect(fn() => Promise::all($promises)->wait())->toThrow(PromiseException::class);
});

test('Promise::race with resolved promises', function () {
    async(function () {
        $async = async(function (int $delay) {
            Coroutine::sleep($delay / 1000);
            return $delay;
        });

        $result = await(Promise::race([
            $async(100),
            $async(50),
            $async(200),
        ]));

        expect($result)->toBe(50);
    })();
});

test('Promise::race with rejection', function () {
    $promises = [
        new Promise(function ($resolve) {
            // This will never resolve
        }),
        Promise::reject('fast error'),
        new Promise(function ($resolve) {
            // This will never resolve
        }),
    ];

    expect(fn() => Promise::race($promises)->wait())->toThrow(PromiseException::class);
});

test('Promise::any with resolved promises', function () {
    $promises = [
        Promise::reject('error1'),
        Promise::resolve(2),
        Promise::reject('error3'),
    ];

    $result = Promise::any($promises)->wait();

    expect($result)->toBe(2);
});

test('Promise::any with all rejections', function () {
    $promises = [
        Promise::reject('error1'),
        Promise::reject('error2'),
        Promise::reject('error3'),
    ];

    expect(fn() => Promise::any($promises)->wait())->toThrow(AggregateError::class);
});

test('Promise::any with empty array', function () {
    expect(fn() => Promise::any([])->wait())->toThrow(AggregateError::class);
});

test('async function wrapper', function () {
    $asyncFn = async(function ($x) {
        return $x * 2;
    });

    $promise = $asyncFn(5);
    $result = $promise->wait();

    expect($result)->toBe(10);
});

test('async function with exception', function () {
    $asyncFn = async(function () {
        throw new Exception('async error');
    });

    $promise = $asyncFn();

    expect(fn() => $promise->wait())->toThrow(Exception::class);
});

test('await function', function () {
    $promise = Promise::resolve('awaited');

    $result = await($promise);

    expect($result)->toBe('awaited');
});

test('await with rejection', function () {
    $promise = Promise::reject('await error');

    expect(fn() => await($promise))->toThrow(PromiseException::class);
});

test('promise chaining with promise returns', function () {
    $promise = Promise::resolve(1);

    $result = $promise
        ->then(fn($x) => Promise::resolve($x + 1))
        ->then(fn($x) => $x * 2)
        ->wait();

    expect($result)->toBe(4);
});

test('promise error propagation', function () {
    $promise = Promise::resolve(1);

    $result = $promise
        ->then(fn($x) => $x + 1)
        ->then(function ($x) {
            if ($x === 2) {
                throw new Exception('chained error');
            }
            return $x;
        })
        ->catch(fn($error) => 'caught: ' . $error->getMessage())
        ->wait();

    expect($result)->toBe('caught: chained error');
});

test('finally returns original value', function () {
    $promise = Promise::resolve('original');

    $result = $promise
        ->finally(fn() => 'ignored')
        ->wait();

    expect($result)->toBe('original');
});

test('finally returns original rejection', function () {
    $promise = Promise::reject('original error');

    try {
        $promise
            ->finally(fn() => 'ignored')
            ->wait();
    } catch (PromiseException $e) {
        expect($e->getMessage())->toBe('Uncaught in promise: original error');
    }
});

test('finally with promise in callback', function () {
    $promise = Promise::resolve('start');

    $result = $promise
        ->finally(fn() => Promise::resolve('finally done'))
        ->wait();

    expect($result)->toBe('start');
});

test('complex async scenario', function () {
    async(function () {
        $start = microtime(true);

        $task1 = async(function () {
            Coroutine::sleep(0.1);
            return 'task1';
        });

        $task2 = async(function () {
            Coroutine::sleep(0.05);
            return 'task2';
        });

        $results = await(Promise::all([
            $task1(),
            $task2(),
        ]));

        $took = microtime(true) - $start;

        expect($results)->toBe(['task1', 'task2']);
        expect($took)->toBeLessThan(0.15);
    })();
});

test('promise toString', function () {
    $pending = new Promise();
    $fulfilled = Promise::resolve('done');
    $rejected = Promise::reject('error');

    expect((string)$pending)->toContain('pending');
    expect((string)$fulfilled)->toContain('fulfilled');
    expect((string)$rejected)->toContain('rejected');
});
