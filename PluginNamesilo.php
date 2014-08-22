<?php

require_once 'modules/admin/models/RegistrarPlugin.php';

class PluginNamesilo extends RegistrarPlugin
{
    public $supportsNamesuggest = false;

    private $sandboxURL = 'http://sandbox.namesilo.com/api/';
    private $liveURL = 'https://www.namesilo.com/api/';
    private $apiVersion = '1';
    private $returnType = 'xml';

    public function getVariables()
    {
        $variables = array(
            lang('Plugin Name') => array (
                                'type'          =>'hidden',
                                'description'   =>lang('How CE sees this plugin (not to be confused with the Signup Name)'),
                                'value'         =>lang('NameSilo')
                               ),
            lang('Use testing server') => array(
                                'type'          =>'yesno',
                                'description'   =>lang('Select Yes if you wish to use NameSilo\'s testing environment, so that transactions are not actually made.'),
                                'value'         =>0
                               ),
            lang('API Key') => array(
                                'type'          =>'text',
                                'description'   =>lang('Enter your API Key'),
                                'value'         =>''
                               ),
            lang('Supported Features')  => array(
                                'type'          => 'label',
                                'description'   => '',
                                'value'         => ''
                                ),
            lang('Actions') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain isn\'t registered)'),
                                'value'         => 'Register'
                                ),
            lang('Registered Actions') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
                                'value'         => '',
                                ),
            lang('Registered Actions For Customer') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
                                'value'         => '',
            )
        );
        return $variables;
    }

    public function checkDomain($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = array(
            'domains' => $domain
        );

        $response = $this->makeRequest('checkRegisterAvailability', $params, $args);

        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4,'NameSilo Error: ' . $response->reply->detail);
            return array(5);
        }

        $domains = array();
        $aDomain = DomainNameGateway::splitDomain($domain);
        if ( isset($response->reply->available->domain) ) {
            $domains[] = array(
                'tld' => $aDomain[1],
                'domain' => $aDomain[0],
                'status' => 0
            );
        } else {
            $domains[] = array(
                'tld' => $aDomain[1],
                'domain' => $aDomain[0],
                'status' => 1
            );
        }

        return array('result' => $domains);
    }

    public function registerDomain($params){}
    public function getContactInformation($params){}
    public function setContactInformation($params){}
    public function getNameServers($params){}
    public function setNameServers($params){}
    public function checkNSStatus($params){}
    public function registerNS($params){}
    public function editNS($params){}
    public function deleteNS($params){}
    public function getGeneralInfo($params){}
    public function setAutorenew($params){}
    public function getRegistrarLock($params){}
    public function setRegistrarLock($params){}
    public function sendTransferKey($params){}






    private function makeRequest($command, $params, $arguments)
    {
        require_once 'library/CE/NE_Network.php';

        $request = $this->liveURL;
        if ( $this->settings->get('plugin_namesilo_Use testing server') ){
            $request = $this->sandboxURL;
        }
        $request .= $command;

        $arguments['key'] = $this->settings->get('plugin_namesilo_API Key');
        $arguments['version'] = $this->apiVersion;
        $arguments['type'] = $this->returnType;

        $i = 0;
        foreach ($arguments as $name => $value) {
            $value = urlencode($value);
            if ( $i == 0 ) $request .= "?$name=$value";
            else $request .= "&$name=$value";
            $i++;
        }

        $response = NE_Network::curlRequest($this->settings, $request);
        if ( $response instanceof CE_Error ) {
            throw new CE_Exception ($response);
        }

        $response = simplexml_load_string($response);
        return $response;
    }
}