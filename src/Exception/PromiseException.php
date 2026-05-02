<?php

namespace JefyOkta\PhpPromise\Exception;

class PromiseException extends \Exception
{
    public function __construct($message, ...$args)
    {
        parent::__construct("Uncaught in promise: {$message}", ...$args);
    }
}