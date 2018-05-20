<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/libs/helpers/autoload.php');

/**
 * Class GoogleTraffic
 * IP-Symcon google traffic module
 *
 * @version     1.1
 * @category    Symcon
 * @package     de.codeking.symcon.googletraffic
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKing/de.codeking.symcon.googletraffic
 *
 */
class GoogleTraffic extends Module
{
    const api = 'https://maps.googleapis.com/maps/api/distancematrix/json';
    const params = [
        'departure_time' => '',
        'origins' => '',
        'destinations' => '',
        'language' => '',
        'key' => '',
        'units' => '',
        'traffic_model' => 'best_guess',
        'avoid' => 'ferries'
    ];

    protected $prefix = 'GoogleTraffic';
    public $data = [];

    private $api_key;
    private $destinations;

    protected $profile_mappings = [
        'Destination' => '~String',
        'Duration' => '~String',
        'Distance' => '~String',
        'Traffic' => 'PlusMinutes'
    ];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // register public properties
        $this->RegisterPropertyString('api_key', '');
        $this->RegisterPropertyString('destinations', '[]');
        $this->RegisterPropertyInteger('interval', 300);

        // register update timer
        $this->RegisterTimer('UpdateData', 0, $this->_getPrefix() . '_Update($_IPS[\'TARGET\']);');
    }

    /**
     * execute, when kernel is ready
     */
    protected function onKernelReady()
    {
        // update timer
        $this->SetTimerInterval('UpdateData', $this->ReadPropertyInteger('interval') * 1000);

        // update api
        $this->Update();
    }

    /**
     * Read config
     */
    private function ReadConfig()
    {
        $this->api_key = $this->ReadPropertyString('api_key');
        $this->destinations = json_decode($this->ReadPropertyString('destinations'), true);

        if ($this->api_key) {
            $this->SetStatus(102);
        }
    }

    /**
     * Update Google Traffic
     * @return bool|void
     */
    public function Update()
    {
        // read config
        $this->ReadConfig();

        // update  data
        if ($this->destinations && $this->api_key) {
            // get destinations
            $destinations = [];
            foreach ($this->destinations AS $destination) {
                $destinations[] = $destination['destination'];
            }

            // update data
            if ($response = $this->Api($destinations)) {
                $this->data['Start Address'] = $response['origin_addresses'][0];

                if (isset($response['rows'][0]['elements']) && is_array($response['rows'][0]['elements'])) {
                    foreach ($response['rows'][0]['elements'] AS $key => $data) {
                        $name = $this->destinations[$key]['name'];

                        if ($data['status'] == 'OK') {
                            $diff = $data['duration_in_traffic']['value'] - $data['duration']['value'];
                            $minutes = round(floatval($diff / 60), 1);

                            if ($minutes > 1) {
                                $data['duration_in_traffic']['text'] .= ', +' . $minutes . ' ' . $this->Translate('Minutes');
                            }

                            $this->data[$name] = [
                                'Distance' => $data['distance']['text'],
                                'Duration' => $data['duration_in_traffic']['text'],
                                'Traffic' => (float)($minutes > 0 ? $minutes : 0)
                            ];
                        } else {
                            echo $name . ': ' . $data['status'] . "\r\n";
                        }
                    }
                }
            }

            // save data
            $this->SaveData();
        }
    }

    /**
     * save data to variables
     */
    private function SaveData()
    {
        // loop data and add variables to category
        $position = 0;
        foreach ($this->data AS $key => $value) {
            if (is_array($value)) {
                // get category id by key
                $category_id = $this->CreateCategoryByIdentifier($this->InstanceID, $key, $key);

                // loop data and add variables to category
                $pos = 0;
                foreach ($value AS $k => $v) {
                    $this->CreateVariableByIdentifier([
                        'parent_id' => $category_id,
                        'name' => $k,
                        'value' => $v,
                        'position' => $pos
                    ]);
                    $pos++;

                }
            } else {
                $this->CreateVariableByIdentifier([
                    'parent_id' => $this->InstanceID,
                    'name' => $key,
                    'value' => $value,
                    'position' => $position
                ]);
                $position++;
            }
        }
    }

    /**
     * Google Maps / Traffic API
     * @param array $destinations
     * @return array|bool
     */
    private function Api(array $destinations)
    {
        // verify destinations
        if (!$destinations) {
            $this->SetStatus(203);
            return false;
        }

        // get lat / lon by location module
        $location_id = $this->_getLocationId();

        // get lat / lon on symcon 5.x
        if (IPS_GetKernelVersion() >= 5) {
            $location = json_decode(IPS_GetProperty($location_id, 'Location'), true);
            $location_lat = isset($location['latitude']) ? $location['latitude'] : false;
            $location_lon = isset($location['longitude']) ? $location['longitude'] : false;
        } // get lat / lon on symcon 4.x
        else {
            $location_lat = str_replace(',', '.', IPS_GetProperty($location_id, 'Latitude'));
            $location_lon = str_replace(',', '.', IPS_GetProperty($location_id, 'Longitude'));
        }

        if (!$location_lat || !$location_lon) {
            $this->SetStatus(202);
            return false;
        }

        // build url
        $url = self::api . '?';

        // update params
        $params = self::params;
        $params['departure_time'] = time();
        $params['origins'] = $location_lat . ',' . $location_lon;
        $params['destinations'] = implode('|', $destinations);
        $params['language'] = $this->_getIpsLocale();
        $params['units'] = $this->_getIpsUnits();
        $params['key'] = $this->api_key;

        // append params to url
        $url .= http_build_query($params);

        // curl options
        $curlOptions = [
            CURLOPT_TIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: IP_Symcon'
            ]
        ];

        // call api
        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200) {
            $response = json_decode($response, true);
            if ($response['status'] == 'OK') {
                return $response;
            } else {
                $this->_log('Google Traffic', $response['status']);
                $this->SetStatus(201);
                return false;
            }
        } else {
            $this->SetStatus(200);
            return false;
        }
    }

    /**
     * create custom variable profile
     * @param string $profile_id
     * @param string $name
     */
    protected function CreateCustomVariableProfile(string $profile_id, string $name)
    {
        switch ($name):
            case 'PlusMinutes':
                IPS_CreateVariableProfile($profile_id, 2); // float
                IPS_SetVariableProfileDigits($profile_id, 0); // 0 decimals
                IPS_SetVariableProfileText($profile_id, '+', ' ' . $this->Translate('Minutes')); // Minutes
                IPS_SetVariableProfileIcon($profile_id, 'Clock');
                break;
        endswitch;
    }
}