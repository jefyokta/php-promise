<?php

namespace JefyOkta\PhpPromise\Exception;
class AggregateError extends \RuntimeException
{
    public function __construct(
        public readonly array $errors,
        string $message = 'All promises were rejected',
    ) {
        parent::__construct($message);
    }
};