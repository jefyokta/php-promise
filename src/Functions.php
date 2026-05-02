<?php

use JefyOkta\PhpPromise\Asynchronous;
use JefyOkta\PhpPromise\Promise;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

if (! function_exists('spawn')) {

    function spawn(callable $fn): void
    {
        if (Coroutine::getCid() > 0) {
            Coroutine::create($fn);
        } else {
            run(function () use ($fn) {
                Coroutine::create($fn);
            });
        }
    }
}

if (! function_exists("async")) {
    function async(callable $cb)
    {

        return new Asynchronous($cb);
    }
    # code...
}

if (! function_exists("await")) {
    # code...
    function await(Promise $promise)
    {

        return $promise->wait();
    }
}
