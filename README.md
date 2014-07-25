# PHPUnit ROX Client

> PHPUnit listener to send results to [ROX Center](https://github.com/lotaris/rox-center).

[![PHP version](https://badge.fury.io/ph/lotaris%2Frox-client-phpunit.svg)](http://badge.fury.io/ph/lotaris%2Frox-client-phpunit)

## Installation

Add the dependency to your `composer.json` file:

```json
{
  "name": "My Project",
  "require-dev": {
    "lotaris/rox-client-phpunit": ">= 0.1.0"
  }
}
```

Update your `composer.lock` file:

    php composer.phar update lotaris/rox-client-phpunit

### Requirements

* PHP 5.3 or higher

## Usage

To track a test, you must assign it a ROX test key generated from your ROX Center server.

Test keys are assigned to a test using the `@RoxableTest` annotation:

```php
use Lotaris\RoxClientPHPUnit\RoxableTest;

/**
 * @RoxableTest(key="ed0f4c560c33")
 */
public function testTheTruth() {
  $this->assertTrue(true);
}
```

## Contributing

* [Fork](https://help.github.com/articles/fork-a-repo)
* Create a topic branch - `git checkout -b feature`
* Push to your branch - `git push origin feature`
* Create a [pull request](http://help.github.com/pull-requests/) from your branch

Please add a changelog entry with your name for new features and bug fixes.

## License

**rox-client-phpunit** is licensed under the [MIT License](http://opensource.org/licenses/MIT).
See [LICENSE.txt](LICENSE.txt) for the full text.
