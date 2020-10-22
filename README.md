# Wordpress Message Repository for EventSauce

based on https://github.com/EventSaucePHP/DoctrineMessageRepository for [EventSauce](https://eventsauce.io).
A pragmatic event sourcing library for PHP with a focus on developer experience.

## install

`composer require eventsauce/wordpress-message-repository`

## local development

if you user `Visual Studio Code` the `.devcontainer` will setup the enviroment automatically.

## Tests

`vendor/bin/phpunit --coverage-text tests/WordpressIntegrationTestCase.php`

## debugging tests

run this on the command line and all further calls of test will stop in the open xdebugger.
`export XDEBUG_CONFIG="idekey=123"`

## Contributing

Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details on our code of conduct, and the process for submitting pull requests to us.

## Versioning

We use [SemVer](http://semver.org/) for versioning. 

## Authors

* **Andreas Heissenberger** - *Initial work* - [Github](https://github.com/aheissenberger) | [Heissenberger Laboratory](https://www.heissenberger.at)


## License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details
