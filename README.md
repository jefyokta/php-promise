# PHP Promise

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jefyokta/php-promise.svg?style=flat-square)](https://packagist.org/packages/jefyokta/php-promise)
[![Tests](https://img.shields.io/github/actions/workflow/status/jefyokta/php-promise/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/jefyokta/php-promise/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/jefyokta/php-promise.svg?style=flat-square)](https://packagist.org/packages/jefyokta/php-promise)

A PHP Promise implementation following the Promises/A+ specification, built on top of Swoole coroutines for true asynchronous programming.

## Features

- ✅ Promises/A+ specification compliant
- ✅ Built on Swoole coroutines for high-performance async operations
- ✅ Full promise chaining with `then()`, `catch()`, and `finally()`
- ✅ Static methods: `Promise::all()`, `Promise::race()`, `Promise::any()`
- ✅ Async/await syntax support with `async()` and `await()` functions
- ✅ Type-safe with PHP 8.1+ features
- ✅ Comprehensive test suite

## Requirements

- PHP 8.1 or higher
- Swoole extension (recommended 5.0+)

## Installation

Install via Composer:

```bash
composer require jefyokta/php-promise
```

## Basic Usage

### Creating and Resolving Promises

```php
use JefyOkta\PhpPromise\Promise;

$promise = new Promise(function ($resolve, $reject) {
    // Do some async work
    $resolve('Success!');
});

$result = $promise->wait(); // 'Success!'
```

### Promise Chaining

```php
Promise::resolve(2)
    ->then(fn($x) => $x * 3)
    ->then(fn($x) => $x + 1)
    ->then(fn($x) => "Result: $x")
    ->wait(); // 'Result: 7'
```

### Error Handling

```php
Promise::resolve(10)
    ->then(fn($x) => $x / 0) // This will throw DivisionByZeroError
    ->catch(fn($error) => "Caught: " . $error->getMessage())
    ->wait(); // 'Caught: Division by zero'
```

### Async/Await Syntax

```php
use function JefyOkta\PhpPromise\async;
use function JefyOkta\PhpPromise\await;

async(function () {
    $result1 = await(async(fn() => 42)());
    $result2 = await(async(fn() => 58)());

    return $result1 + $result2;
})()->wait(); // 100
```

### Promise.all()

```php
$promises = [
    async(fn() => 1)(),
    async(fn() => 2)(),
    async(fn() => 3)(),
];

$result = Promise::all($promises)->wait(); // [1, 2, 3]
```

### Promise.race()

```php
$promises = [
    async(function () {
        Swoole\Coroutine::sleep(0.1);
        return 'slow';
    })(),
    async(function () {
        Swoole\Coroutine::sleep(0.05);
        return 'fast';
    })(),
];

$result = Promise::race($promises)->wait(); // 'fast'
```

### Promise.any()

```php
$promises = [
    Promise::reject('error1'),
    Promise::resolve('success'),
    Promise::reject('error2'),
];

$result = Promise::any($promises)->wait(); // 'success'
```

## API Reference

### Promise Class

#### Constructor
```php
new Promise(?callable $executor = null)
```

Creates a new Promise. The executor function receives `resolve` and `reject` callbacks.

#### Instance Methods

- `then(?callable $onFulfilled, ?callable $onRejected): Promise` - Chains fulfillment/rejection handlers
- `catch(callable $onRejected): Promise` - Chains rejection handler
- `finally(callable $onFinally): Promise` - Chains cleanup handler
- `wait(): mixed` - Blocks until promise settles and returns value

#### Static Methods

- `Promise::resolve(mixed $value): Promise` - Creates a resolved promise
- `Promise::reject(mixed $reason): Promise` - Creates a rejected promise
- `Promise::all(array $promises): Promise` - Waits for all promises to resolve
- `Promise::race(array $promises): Promise` - Resolves/rejects with first settled promise
- `Promise::any(array $promises): Promise` - Resolves with first fulfilled promise

### Functions

#### async(callable $fn): Asynchronous
```php
$asyncFn = async(fn($x) => $x * 2);
$promise = $asyncFn(5); // Promise that resolves to 10
```

#### await(Promise $promise): mixed
```php
$result = await($promise); // Wait for promise resolution
```

## Swoole Integration

This library leverages Swoole coroutines for true asynchronous execution. Make sure Swoole is installed and the coroutine environment is available.

```php
// Inside a coroutine context
Swoole\Coroutine\run(function () {
    $promise = async(function () {
        Swoole\Coroutine::sleep(1);
        return 'done';
    })();

    $result = await($promise);
    echo $result; // 'done' after 1 second
});
```

## Testing

```bash
composer test
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request. Make sure to:

1. Add tests for new features
2. Follow PSR-12 coding standards
3. Update documentation as needed

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Credits

- [Jepi Oktamipa](https://github.com/jefyokta) - Creator
- Built with [Swoole](https://www.swoole.com/) coroutines
- Inspired by JavaScript Promises/A+ specification