<?php

use JefyOkta\PhpPromise\Promise;
use JefyOkta\PhpPromise\Asynchronous;
use JefyOkta\PhpPromise\Exception\AggregateError;
use JefyOkta\PhpPromise\Exception\PromiseException;
use Swoole\Coroutine;

test('promise constructor without executor', function () {
    $promise = new Promise();

    expect($promise)->toBeInstanceOf(Promise::class);
});

test('promise constructor with executor', function () {
    $executed = false;

    $promise = new Promise(function ($resolve) use (&$executed) {
        $executed = true;
        $resolve('done');
    });

    $promise->wait();
    expect($executed)->toBeTrue();
});

test('promise executor exception handling', function () {
    $promise = new Promise(function () {
        throw new Exception('executor error');
    });

    expect(fn() => $promise->wait())->toThrow(Exception::class);
});

test('then callback receives fulfilled value', function () {
    $received = null;

    $promise = Promise::resolve('test value');
    $promise->then(function ($value) use (&$received) {
        $received = $value;
    })->wait();

    expect($received)->toBe('test value');
});

test('then callback transforms value', function () {
    $promise = Promise::resolve(5);
    $result = $promise->then(fn($x) => $x * 2)->wait();

    expect($result)->toBe(10);
});

test('then callback can return promise', function () {
    $promise = Promise::resolve(1);
    $result = $promise->then(fn() => Promise::resolve(2))->wait();

    expect($result)->toBe(2);
});

test('catch callback receives rejection reason', function () {
    $received = null;

    $promise = Promise::reject('test error');
    $promise->catch(function ($reason) use (&$received) {
        $received = $reason;
        return 'recovered';
    })->wait();

    expect($received)->toBe('test error');
});

test('catch recovers from rejection', function () {
    $promise = Promise::reject('error');
    $result = $promise->catch(fn() => 'recovered')->wait();

    expect($result)->toBe('recovered');
});

test('finally callback executes on fulfillment', function () {
    $executed = false;
    $callbackValue = null;

    $promise = Promise::resolve('success');
    $promise->finally(function () use (&$executed, &$callbackValue) {
        $executed = true;
        $callbackValue = 'finally result';
    })->wait();

    expect($executed)->toBeTrue();
    expect($callbackValue)->toBe('finally result');
});

test('finally callback executes on rejection', function () {
    $executed = false;

    $promise = Promise::reject('error');
    try {
        $promise->finally(function () use (&$executed) {
            $executed = true;
        })->wait();
    } catch (PromiseException) {
        // Expected
    }

    expect($executed)->toBeTrue();
});

test('finally callback returning promise', function () {
    $promise = Promise::resolve('value');
    $result = $promise->finally(fn() => Promise::resolve('ignored'))->wait();

    expect($result)->toBe('value');
});

test('wait blocks until resolution', function () {
    $resolved = false;

    $promise = new Promise(function ($resolve) use ($resolved) {
        spawn(function () use ($resolve, &$resolved) {
            Coroutine::sleep(0.01);
            $resolved = true;
            $resolve('async result');
        });
    });

    $result = $promise->wait();

    expect($resolved)->toBeTrue();
    expect($result)->toBe('async result');
});

test('wait throws on rejection', function () {
    $promise = Promise::reject('wait error');

    expect(fn() => $promise->wait())->toThrow(PromiseException::class);
});

test('resolve static method with non-promise', function () {
    $promise = Promise::resolve('direct value');

    expect($promise->wait())->toBe('direct value');
});

test('resolve static method with promise', function () {
    $inner = new Promise(fn($resolve) => $resolve('inner'));
    $outer = Promise::resolve($inner);

    expect($outer)->toBe($inner);
});

test('reject static method', function () {
    $promise = Promise::reject('static rejection');

    expect(fn() => $promise->wait())->toThrow(PromiseException::class);
});

test('all static method empty array', function () {
    $result = Promise::all([])->wait();

    expect($result)->toBe([]);
});

test('all static method with values', function () {
    $result = Promise::all([1, 'two', 3.0])->wait();

    expect($result)->toBe([1, 'two', 3.0]);
});

test('all static method with promises', function () {
    $promises = [
        Promise::resolve('a'),
        Promise::resolve('b'),
        Promise::resolve('c'),
    ];

    $result = Promise::all($promises)->wait();

    expect($result)->toBe(['a', 'b', 'c']);
});

test('all static method preserves order', function () {
    $promises = [
        new Promise(fn($resolve) => spawn(fn() => Coroutine::sleep(0.1) && $resolve(1))),
        new Promise(fn($resolve) => spawn(fn() => Coroutine::sleep(0.05) && $resolve(2))),
        new Promise(fn($resolve) => spawn(fn() => Coroutine::sleep(0.01) && $resolve(3))),
    ];

    $result = Promise::all($promises)->wait();

    expect($result)->toBe([1, 2, 3]);
});

test('all static method rejects on first rejection', function () {
    $promises = [
        Promise::resolve(1),
        Promise::reject('error'),
        Promise::resolve(3),
    ];

    expect(fn() => Promise::all($promises)->wait())->toThrow(PromiseException::class);
});

test('race static method with immediate resolve', function () {
    $promises = [
        Promise::resolve('first'),
        new Promise(fn() => Coroutine::sleep(0.1)),
    ];

    $result = Promise::race($promises)->wait();

    expect($result)->toBe('first');
});

test('race static method with immediate reject', function () {
    $promises = [
        Promise::reject('first error'),
        Promise::resolve('second'),
    ];

    expect(fn() => Promise::race($promises)->wait())->toThrow(PromiseException::class);
});

test('any static method with first resolve', function () {
    $promises = [
        Promise::reject('error1'),
        Promise::resolve('success'),
        Promise::reject('error2'),
    ];

    $result = Promise::any($promises)->wait();

    expect($result)->toBe('success');
});

test('any static method with all rejects', function () {
    $promises = [
        Promise::reject('error1'),
        Promise::reject('error2'),
    ];

    expect(fn() => Promise::any($promises)->wait())->toThrow(AggregateError::class);
});

test('any static method aggregate error contains all errors', function () {
    $promises = [
        Promise::reject('error1'),
        Promise::reject('error2'),
    ];

    try {
        Promise::any($promises)->wait();
    } catch (AggregateError $e) {
        expect($e->errors)->toBe(['error1', 'error2']);
    }
});

test('Asynchronous class wraps callable', function () {
    $async = new Asynchronous(fn($x) => $x * 2);

    $promise = $async(5);
    $result = $promise->wait();

    expect($result)->toBe(10);
});

test('Asynchronous handles exceptions', function () {
    $async = new Asynchronous(function () {
        throw new Exception('async exception');
    });

    $promise = $async();

    expect(fn() => $promise->wait())->toThrow(Exception::class);
});

test('promise toString representation', function () {
    $pending = new Promise();
    $fulfilled = Promise::resolve('ok');
    $rejected = Promise::reject('fail');

    expect((string)$pending)->toBe('JefyOkta\PhpPromise\Promise { <pending> } ');
    expect((string)$fulfilled)->toBe('JefyOkta\PhpPromise\Promise { <fulfilled> } ');
    expect((string)$rejected)->toBe('JefyOkta\PhpPromise\Promise { <rejected> } ');
});

test('promise chaining maintains context', function () {
    $promise = Promise::resolve('start');

    $steps = [];
    $promise
        ->then(function ($v) use (&$steps) {
            $steps[] = 'step1';
            return $v . ' -> step1';
        })
        ->then(function ($v) use (&$steps) {
            $steps[] = 'step2';
            return $v . ' -> step2';
        })
        ->wait();

    expect($steps)->toBe(['step1', 'step2']);
});

test('promise multiple then handlers', function () {
    $promise = Promise::resolve('shared');

    $results = [];
    $p1 = $promise->then(function ($v) use (&$results) {
        $results[] = 'handler1: ' . $v;
        return 'result1';
    });

    $p2 = $promise->then(function ($v) use (&$results) {
        $results[] = 'handler2: ' . $v;
        return 'result2';
    });

    $p1->wait();
    $p2->wait();

    expect($results)->toBe(['handler1: shared', 'handler2: shared']);
});

test('promise late then handler', function () {
    $executed = false;

    $promise = new Promise(function ($resolve) use (&$executed) {
        spawn(function () use ($resolve, &$executed) {
            Coroutine::sleep(0.01);
            $executed = true;
            $resolve('late');
        });
    });

    Coroutine::sleep(0.02); // Wait for resolution

    $result = $promise->then(fn($v) => $v . ' processed')->wait();

    expect($executed)->toBeTrue();
    expect($result)->toBe('late processed');
});
