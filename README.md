# Utopia Cache

[![Build Status](https://travis-ci.org/utopia-php/cache.svg?branch=master)](https://travis-ci.com/utopia-php/cache)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/cache.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia framework cache library is simple and lite library for managing application cache storing, loading and purging. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:
```bash
composer require utopia-php/cache
```

**File System Adapter**

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Filesystem;

$cache  = new Cache(new Filesystem('/cache-dir'));
$key    = 'data-from-example.com';

$data   = $cache->load($key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

if(!$data) {
    $data = file_get_contents('https://example.com');
    
    $cache->save($key, $data);
}

echo $data;
```

## Contribute

Currently we support only a Filesystem adapter for usage as a cache storage, send a pull request to add redis, memcached or any other storage adapter you might need to use with this library.

## System Requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Tests

To run all unit tests, use the following Docker command:

`docker-compose exec tests vendor/bin/phpunit --configuration phpunit.xml tests`

To run static code analysis, use the following Psalm command:

`docker-compose exec tests vendor/bin/psalm --show-info=true`


## Authors

**Eldad Fux**

+ [https://twitter.com/eldadfux](https://twitter.com/eldadfux)
+ [https://github.com/eldadfux](https://github.com/eldadfux)

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
