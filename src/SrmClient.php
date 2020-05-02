<?php
namespace SynologySRM;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SrmClient
{
    private $logger;
    private $username;
    private $password;
    private $hostname;
    private $port;
    private $protocol;
    private $isKeepingSession;
    private $sid = null;
    private $curlHandler;
    private $ignoreSslError = true;
    private $errorCodes = [
        100 => 'Unknown error',
        101 => 'Invalid parameters',
        102 => 'API does not exist',
        103 => 'Method does not exist',
        104 => 'This API version is not supported',
        105 => 'Insufficient user privilege',
        106 => 'Connection time out',
        107 => 'Multiple login detected',
        117 => 'Need manager rights for operation',
        119 => 'Missing SID',
        400 => 'Invalid credentials',
        401 => 'Account disabled',
        402 => 'Permission denied',
        403 => '2-step verification code required',
        404 => 'Failed to authenticate 2-step verification code',
    ];

    /**
     * Create a new SRM client and login to router.
     *
     * @param LoggerInterface $logger Logger object, may be set to null
     * @param string $username Username (ie: 'admin')
     * @param string $password User password
     * @param string $hostname Router IP address or hostname
     * @param integer $port Router port (default 8001)
     * @param boolean $isHttps Does API calls use HTTPS (default true)
     * @param boolean $isKeepingSession Does session is keeped when script ends or automatic logout (default false, logout)
     * @param boolean $sid Session id from a previous login (default null)
     * @return self
     */
    public function __construct($logger, $username, $password, $hostname, $port = 8001, $isHttps = true, $isKeepingSession = false, $sid = null)
    {
        // set logger if provided
        if ($logger instanceof LoggerInterface) {
            $this->logger = $logger;
        } else {
            $this->logger = new NullLogger();
        }

        // set clients configuration with provided information
        $this->username = $username;
        $this->password = $password;
        $this->hostname = $hostname;
        $this->port = $port;
        $this->protocol = $isHttps ? 'https' : 'http';
        $this->isKeepingSession = $isKeepingSession;
        $this->sid = $sid;

        // create curl object
        if (!function_exists('curl_init')) {
            throw new Exception('php cURL extension must be installed and enabled');
        }
        $this->curlHandler = curl_init();
        curl_setopt($this->curlHandler, CURLOPT_RETURNTRANSFER, true);

        //allow insecure connection
        if ($this->ignoreSslError) {
            curl_setopt($this->curlHandler, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($this->curlHandler, CURLOPT_SSL_VERIFYPEER, false);
        }

        // login
        if ($this->sid === null) {
            $this->login();
        }
        $this->logger->debug('Client created');
    }

    /**
     * Logout to router.
     */
    public function __destruct()
    {
        if (!$this->isKeepingSession) {
            try {
                $this->logout();
                $this->logger->debug('Logout');
            } catch (\Exception $e) {
                $this->logger->error("Error during client destruct: {$e->getMessage()}");
            }
        }
        $this->logger->debug('Client destructed');
    }

    /**
     * Execute an API call.
     *
     * @param string $path Path of the API (ie: 'entry.cgi')
     * @param string $api API name (ie: 'SYNO.Core.NGFW.Traffic')
     * @param string $params API params
     * @throws Exception if credentials are missing or an error occurs
     * @return object API response
     */
    private function _request($path, $api, $params = [])
    {
        // check session id
        if ($path !== 'auth.cgi' && $this->sid === null) {
            throw new \Exception('This API require login');
        }

        // prepare params
        $params = array_merge([
          'api' => $api,
        ], $params);
        if ($this->sid) {
            curl_setopt($this->curlHandler, CURLOPT_COOKIE, "id={$this->sid}");
        }

        // construct url
        $queryString = http_build_query($params);
        $url = "{$this->protocol}://{$this->hostname}:{$this->port}/webapi/{$path}?{$queryString}";
        curl_setopt($this->curlHandler, CURLOPT_URL, $url);
        if ($path !== 'auth.cgi') {
            $this->logger->debug("HTTP request: {$url}");
        }

        // execute request
        $result = curl_exec($this->curlHandler);

        // handle curl error
        if (curl_errno($this->curlHandler)) {
            $curlError = curl_error($this->curlHandler);
            throw new \Exception("HTTP error: {$curlError}");
        }

        // handle curl response
        $httpCode = curl_getinfo($this->curlHandler, CURLINFO_HTTP_CODE);
        if ($path !== 'auth.cgi') {
            $this->logger->debug("HTTP code: {$httpCode} - response: {$result}");
        }
        try {
            $response = json_decode($result);
        } catch (\Exception $e) {
            throw new \Exception('Invalid JSON response');
        }

        // handle SRM error code
        if (!is_object($response) || !\property_exists($response, 'success')) {
            throw new \Exception('Invalid response, missing `success` attribute');
        }
        if (!$response->success) {
            if ($response->error->code) {
                if (array_key_exists($response->error->code, $this->errorCodes)) {
                    throw new \Exception($this->errorCodes[$response->error->code]);
                }
                throw new \Exception("Error code: {$response->error->code}");
            }
            throw new \Exception('Unknown SRM error');
        }

        return $response;
    }

    /**
     * Login to router and set session id
     * @throws Exception if credentials are missing/invalid or an error occurs
     */
    private function login()
    {
        $params = [
          'account' => $this->username,
          'passwd' => $this->password,
          'method' => 'Login',
          'version' => 2
        ];
        try {
            $response = $this->_request('auth.cgi', 'SYNO.API.Auth', $params);
        } catch (\Exception $e) {
            throw new \Exception("Could not login ({$e->getMessage()})");
        }
        if (!\property_exists($response, 'data') || !\property_exists($response->data, 'sid')) {
            throw new \Exception('No session id returned');
        }
        $this->sid = $response->data->sid;
        $this->logger->info('Login successful');
    }

    /**
     * Logout to router and clear session id
     */
    private function logout()
    {
        $this->_request('auth.cgi', 'SYNO.API.Auth', ['method' => 'Logout']);
        $this->sid = null;
    }

    /**
     * Return session id for future uses
     *
     * @return string Session id
     */
    public function getSid()
    {
        return $this->sid;
    }

    /**
     * Return network traffic usage (download/upload and packets) by devices.
     *
     * @param string $interval must be in ['live', 'day', 'week', 'month']
     * @throws Exception if an error occurs
     * @return array An array of devices (object)
     */
    public function getTraffic($interval = 'live')
    {
        if (!in_array($interval, ['live', 'day', 'week', 'month'])) {
            throw new \Exception('Invalid interval');
        }
        $params = [
            'method' => 'get',
            'version' => 1,
            'mode' => 'net',
            'interval' => $interval,
        ];
        try {
            $response = $this->_request('entry.cgi', 'SYNO.Core.NGFW.Traffic', $params);
        } catch (\Exception $e) {
            throw new \Exception("Could not get traffic ({$e->getMessage()})");
        }
        $devicesTraffic = $response->data;
        $this->logger->info('Get traffic successful');
        return $devicesTraffic;
    }

    /**
     * Return WAN status.
     *
     * @throws Exception if an error occurs
     * @return boolean Does WAN is ok
     */
    public function getWanStatus()
    {
        $params = [
            'method' => 'get',
            'version' => 1,
        ];
        try {
            $response = $this->_request('entry.cgi', 'SYNO.Mesh.Network.WANStatus', $params);
        } catch (\Exception $e) {
            throw new \Exception("Could not get WAN status ({$e->getMessage()})");
        }
        if (!\property_exists($response, 'data') || !\property_exists($response->data, 'wan_connected')) {
            throw new \Exception('WAN status not returned');
        }
        $this->logger->info('Get WAN status successful');
        return $response->data->wan_connected;
    }

    /**
     * Return network utilization.
     *
     * @throws Exception if an error occurs
     * @return array An array of network interfaces with received/transmitted (object)
     */
    public function getNetworkUtilization()
    {
        $params = [
            'method' => 'get',
            'version' => 1,
            'resource' => \json_encode(['network'])
        ];
        try {
            $response = $this->_request('entry.cgi', 'SYNO.Core.System.Utilization', $params);
        } catch (\Exception $e) {
            throw new \Exception("Could not get network utilization ({$e->getMessage()})");
        }
        if (!\property_exists($response, 'data') || !\property_exists($response->data, 'network')) {
            throw new \Exception('Network utilization not returned');
        }
        $this->logger->info('Get network utilization successful');
        return $response->data->network;
    }

    /**
     * Return knowned devices by router and information like IP, signal, etc...
     *
     * @param string $interval must be in 'all'
     * @throws Exception if an error occurs
     * @return array An array of devices (object)
     */
    public function getDevices($conntype = 'all')
    {
        if (!in_array($conntype, ['all'])) {
            throw new \Exception('Invalid connection type');
        }
        // [{"api":"SYNO.Core.Network.NSM.Beamforming","version":1,"method":"get"},{"api":"SYNO.Core.NGFW.QoS.Priority","version":1,"method":"list_high"},{"api":"SYNO.Core.NGFW.QoS.Priority","version":1,"method":"list_low"},{"api":"SYNO.Core.NGFW.QoS.Rules","version":1,"method":"get"},{"api":"SYNO.Core.Network.Router.BanDevice","version":1,"method":"get","recordtype":"all"},{"api":"SYNO.Core.Network.NSM.Device","version":4,"method":"get","conntype":"all"}]
        $params = [
            'method' => 'get',
            'version' => 4,
            'conntype' => $conntype
        ];
        try {
            $response = $this->_request('entry.cgi', 'SYNO.Core.Network.NSM.Device', $params);
        } catch (\Exception $e) {
            throw new \Exception("Could not get devices ({$e->getMessage()})");
        }
        if (!\property_exists($response, 'data') || !\property_exists($response->data, 'devices')) {
            throw new \Exception('No devices returned');
        }
        $this->logger->info('Get devices successful');
        return $response->data->devices;
    }

    /**
     * Return devices connected to Wifi and information like max rate, signal, etc...
     *
     * @throws Exception if an error occurs
     * @return array An array of devices (object)
     */
    public function getWifiDevices()
    {
        $params = [
            'method' => 'get',
            'version' => 1,
        ];
        try {
            $response = $this->_request('entry.cgi', 'SYNO.Mesh.Network.WifiDevice', $params);
        } catch (\Exception $e) {
            throw new \Exception("Could not get wifi devices ({$e->getMessage()})");
        }
        if (!\property_exists($response, 'data') || !\property_exists($response->data, 'devices')) {
            throw new \Exception('No devices returned');
        }
        $this->logger->info('Get Wifi devices successful');
        return $response->data->devices;
    }

    /**
     * Return mesh nodes and information like max rate, status, number of connected devices, etc...
     *
     * @throws Exception if an error occurs
     * @return array An array of mesh nodes (object)
     */
    public function getMeshNodes()
    {
        $params = [
            'method' => 'get',
            'version' => 3,
        ];
        try {
            $response = $this->_request('entry.cgi', 'SYNO.Mesh.Node.List', $params);
        } catch (\Exception $e) {
            throw new \Exception("Could not get mesh nodes ({$e->getMessage()})");
        }
        if (!\property_exists($response, 'data') || !\property_exists($response->data, 'nodes')) {
            throw new \Exception('No mesh node returned');
        }
        $this->logger->info('Get mesh nodes successful');
        return $response->data->nodes;
    }

    /**
     * Return devices where wake on lan is configured.
     *
     * @throws Exception if an error occurs
     * @return array An array devices (object)
     */
    public function getWakeOnLanDevices()
    {
        $params = [
            'method' => 'get_devices',
            'findhost' => false,
            'client_list' => \json_encode([]),
            'version' => 1,
        ];
        try {
            $response = $this->_request('entry.cgi', 'SYNO.Core.Network.WOL', $params);
        } catch (\Exception $e) {
            throw new \Exception("Could not get wake on lan devices ({$e->getMessage()})");
        }
        $this->logger->info('Get wake on lan devices successful');
        return $response->data;
    }

    /**
     * Add wake on lan to the provided device
     *
     * @param string $mac Device MAC address
     * @param string $host Device hostname
     * @throws Exception if an error occurs
     * @return boolean Result of request
     */
    public function addWakeOnLanDevice($mac, $host = null)
    {
        $params = [
            'method' => 'add_device',
            'mac' => \json_encode($mac),
            'version' => 1,
        ];
        if ($host !== null) {
            $params['host'] = \json_encode($host);
        }
        try {
            $response = $this->_request('entry.cgi', 'SYNO.Core.Network.WOL', $params);
        } catch (\Exception $e) {
            throw new \Exception("Could not add wake on lan to device {$mac} ({$e->getMessage()})");
        }
        $this->logger->info("Add wake on lan on device {$mac} successful");
        return $response->success;
    }

    /**
     * Wake on lan provided device
     *
     * @param string $mac Device MAC address
     * @throws Exception if an error occurs
     * @return boolean Result of request
     */
    public function wakeOnLanDevice($mac)
    {
        $params = [
            'method' => 'wake',
            'mac' => \json_encode($mac),
            'version' => 1,
        ];
        try {
            $response = $this->_request('entry.cgi', 'SYNO.Core.Network.WOL', $params);
        } catch (\Exception $e) {
            throw new \Exception("Could not wake on lan device {$mac} ({$e->getMessage()})");
        }
        $this->logger->info("Wake on lan on device {$mac} successful");
        return $response->success;
    }
}
