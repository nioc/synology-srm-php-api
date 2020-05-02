<?php
use SynologySRM\SrmClient;
use Analog\Logger;
use Analog\Handler\EchoConsole;

require_once __DIR__ . '/vendor/autoload.php';

$username = 'myuser';
$password = 'mypassword';
$hostname = '10.0.0.1';
$port = '8001';
$https = true;
$sid = null;

$logger = new Logger;
Analog::$format = "%s - %s - %s - %s\n";
$logger->handler(
    Analog\Handler\Threshold::init(
        Analog\Handler\LevelName::init(
            EchoConsole::init()
        ),
        Analog::DEBUG
    )
);

try {
    $client = new SrmClient($logger, $username, $password, $hostname, $port, $https, true, $sid);
    $logger->info("sid: {$client->getSid()}");

    $wanStatus = $client->getWanStatus();
    $logger->info("WAN status: {$wanStatus}");

    $devicesTraffic = $client->getTraffic('live');
    foreach ($devicesTraffic as $deviceTraffic) {
        $logger->info("{$deviceTraffic->deviceID} => down: {$deviceTraffic->download}, up: {$deviceTraffic->upload}");
    }

    $networks = $client->getNetworkUtilization();
    foreach ($networks as $network) {
        $logger->info("{$network->device} => rx: {$network->rx}, tx: {$network->tx}");
    }

    $devicesWifi = $client->getWifiDevices();
    $logger->info(json_encode($devicesWifi));

    $nodes = $client->getMeshNodes();
    $logger->info(json_encode($nodes));

    $devices = $client->getDevices();
    $logger->info(json_encode($devices));

    $client->addWakeOnLanDevice('01:23:45:67:89:AB');

    $wakeOnLanDevices = $client->getWakeOnLanDevices();
    $logger->info(json_encode($wakeOnLanDevices));

    $result = $client->wakeOnLanDevice('01:23:45:67:89:AB');
    $logger->info("wakeOnLan: {$result}");
} catch (Exception $e) {
    $logger->error($e->getMessage());
}
