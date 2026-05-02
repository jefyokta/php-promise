<?php

namespace JefyOkta\PhpPromise;

/**
 * @template TReturn
 * @template TArgs
 */
class Asynchronous
{
    /** @var callable(...TArgs): TReturn */
    private $handler;

    /**
     * @param callable(...TArgs): TReturn $handler
     */
    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    /**
     * @param TArgs $args
     * @return Promise<TReturn>
     */
    public function __invoke(mixed ...$args): Promise
    {
        return new Promise(function ($resolve, $reject) use ($args) {
            try {
                $resolve(($this->handler)(...$args));
            } catch (\Throwable $th) {
                $reject($th);
            }
        });
    }
}