<?php
namespace JefyOkta\PhpPromise;


enum PromiseState
{
    case Pending;
    case Fulfilled;
    case Rejected;
};
