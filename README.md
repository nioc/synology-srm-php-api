# Synology SRM PHP API

[![license: AGPLv3](https://img.shields.io/badge/license-AGPLv3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![GitHub release](https://img.shields.io/github/release/nioc/synology-srm-php-api.svg)](https://github.com/nioc/synology-srm-php-api/releases/latest)

API wrapper for Synology Router Manager (SRM).

## Key features
-    get WAN status,
-    get network utilization,
-    get devices with status, IP, etc... ,
-    get wifi devices with link quality, signal strength, max rate, band used, etc... ,
-    get devices traffic usage (live, day, week, month),
-    get mesh nodes with status, connected devices, etc... ,
-    get wake-on-lan devices,
-    add wake-on-lan on a device,
-    wake-on-lan a device.

## Installation

To install with composer:
```shell
composer require nioc/synology-srm-php-api
```

Or download [latest release](https://github.com/nioc/synology-srm-php-api/releases/latest) zip archive,

## Usage

### Create client

#### Create a simple client

```php
$client = new SrmClient(null, $username, $password, $hostname, $port, $https, false);
```

#### Create a client with session keeped

If you can store session id, you can pass it value to constructor. if value is `null`, client will execute a login request.

```php
$client = new SrmClient(null, $username, $password, $hostname, $port, $https, true, $sid);
```

To retrieve session id value in order to store it:
```php
$sid = $client->getSid();
```

#### Create a client with logger

Constructor first parameter is `LoggerInterface`, allowing you to use any PSR-3 compliant logger library (like [Monolog](https://github.com/Seldaek/monolog) or [Analog](https://github.com/jbroadway/analog)).

Following a basic console logger with Analog:
```php
use Analog\Logger;
use Analog\Handler\EchoConsole;

$logger = new Logger;
Analog::$format = "%s - %s - %s - %s\n";
$logger->handler(
    Analog\Handler\Threshold::init(
        Analog\Handler\LevelName::init(
            EchoConsole::init()
        ),
        Analog::INFO
    )
);

$client = new SrmClient($logger, $username, $password, $hostname, $port, $https, true, $sid);
```
### Get devices, mesh, traffic, ...

Simply call the requested client method, exemple with traffic:

```php
$devicesTraffic = $client->getTraffic('live');
```

You can see a full exemple [here](https://github.com/nioc/synology-srm-php-api/blob/master/example.php).

## Versioning

This library is maintained under the [semantic versioning](https://semver.org/) guidelines.

See the [releases](https://github.com/nioc/synology-srm-php-api/releases) on this repository for changelog.

## Contributing

If you have a suggestion, please submit a [feature request](https://github.com/nioc/synology-srm-php-api/issues/new?labels=enhancement).
Pull requests are welcomed.

## Credits

* **[Nioc](https://github.com/nioc/)** - *Initial work*

See also the list of [contributors](https://github.com/nioc/synology-srm-php-api/contributors) to this project.

## License

This project is licensed under the GNU Affero General Public License v3.0 - see the [LICENSE](LICENSE.md) file for details
