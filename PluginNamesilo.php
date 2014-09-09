<?php

require_once 'modules/admin/models/RegistrarPlugin.php';
require_once 'modules/domains/models/ICanImportDomains.php';

class PluginNamesilo extends RegistrarPlugin implements ICanImportDomains
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
                                'description'   => '* '.lang('TLD Lookup').'<br>* '.lang('Domain Registration').' <br>* '.lang('Existing Domain Importing').' <br>* '.lang('Get / Set Auto Renew Status').' <br>* '.lang('Get / Set DNS Records').' <br>* '.lang('Get / Set Nameserver Records').' <br>* '.lang('Get / Set Contact Information').' <br>* '.lang('Get / Set Registrar Lock').' <br>* '.lang('Initiate Domain Transfer').' <br>* '.lang('Automatically Renew Domain').' <br>* '.lang('Send Transfer Key'),
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
                                'value'         => 'Renew (Renew Domain),togglePrivacy (Toggle Privacy),DomainTransferWithPopup (Initiate Transfer),SendTransferKey (Send Auth Info),Cancel',
                                ),
            lang('Registered Actions For Customer') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
                                'value'         => 'togglePrivacy (Toggle Privacy),SendTransferKey (Send Auth Info)',
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

    function doRegister($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->registerDomain($this->buildRegisterParams($userPackage, $params));
        $userPackage->setCustomField('Registrar Order Id', $userPackage->getCustomField("Registrar") . '-' . $params['userPackageId']);
        return $userPackage->getCustomField('Domain Name') . ' has been registered.';
    }

    public function registerDomain($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = array(
            'domain'        => $domain,
            'years'         => $params['NumYears'],
            'private'       => 0,
            'auto_renew'    => 0,
            'fn'            => $params['RegistrantFirstName'],
            'ln'            => $params['RegistrantLastName'],
            'ad'            => $params['RegistrantAddress1'],
            'cy'            => $params['RegistrantCity'],
            'st'            => $params['RegistrantStateProvince'],
            'zp'            => $params['RegistrantPostalCode'],
            'ct'            => $params['RegistrantCountry'],
            'em'            => $params['RegistrantEmailAddress'],
            'ph'            => $this->validatePhone($params['RegistrantPhone']),
            'cp'            => $params['RegistrantOrganizationName']
        );

        if ( $this->settings->get('plugin_namesilo_Use testing server') ){
            $args['ns1'] = 'NS1.NAMESILO.COM';
            $args['ns2'] = 'NS2.NAMESILO.COM';
        } else if ( isset($params['NS1']) ) {
            // NameSilo allows for 13 total name servers
            for ($i = 1; $i <= 13; $i++) {
                if (isset($params["NS$i"])) {
                    $args["ns$i"] = $params["ns$i"]['hostname'];
                } else {
                    break;
                }
            }
        }

        $response = $this->makeRequest('registerDomain', $params, $args);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
    }

    public function getGeneralInfo($params)
    {
        $response = $this->getDomainInformation($params);
        $connectionIssueCodes = array(
            109,
            110,
            111,
            112,
            113,
            115,
            201,
            210
        );

        if ( in_array($response->reply->code, $connectionIssueCodes) ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail, EXCEPTION_CODE_CONNECTION_ISSUE);
        }

        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        $data = array();
        $data['domain'] = $domain;
        $data['expiration'] = (string)$response->reply->expires;
        $data['registrationstatus'] = (string)$response->reply->status;
        $data['purchasestatus'] = 'N/A';
        $data['autorenew'] = 0;

        if ( strtolower($response->reply->auto_renew) == 'yes' ) {
            $data['autorenew'] = 1;
        }

        // we should also update the autorenew here:
        if ( $params['userPackageId'] ) {
            $userPackage = new UserPackage($params['userPackageId']);
            $userPackage->setCustomField("Auto Renew", $data['autorenew']);
        }

        return $data;
    }

    public function getRegistrarLock($params)
    {
        $response = $this->getDomainInformation($params);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        if ( strtolower($response->reply->locked) == 'yes' ) {
            return 1;
        }
        return 0;
    }

    function doSendTransferKey($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->sendTransferKey($this->buildRegisterParams($userPackage,$params));
        return 'Successfully sent auth info for ' . $userPackage->getCustomField('Domain Name');
    }

    public function sendTransferKey($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = array(
            'domain' => $domain
        );
        $response = $this->makeRequest('retrieveAuthCode', $params, $args);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
    }

    function doSetRegistrarLock($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->setRegistrarLock($this->buildLockParams($userPackage,$params));
        return "Updated Registrar Lock.";
    }

    function setRegistrarLock($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = array(
            'domain' => $domain
        );

        $command = 'domainUnlock';
        if ( $params['lock'] == 1 ) {
            // we are locking
            $command = 'domainLock';
        }

        $response = $this->makeRequest($command, $params, $args);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
    }

    public function setAutorenew($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = array(
            'domain' => $domain
        );

        $command = 'addAutoRenewal';
        if ( !$params['autorenew'] ) {
            $command = 'removeAutoRenewal';
        }

        $response = $this->makeRequest($command, $params, $args);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
        return lang('Domain updated successfully');
    }

    public function getContactInformation($params)
    {
        $response = $this->getDomainInformation($params);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        $contactId = $response->reply->contact_ids->registrant;
        $args = array(
            'contact_id' => $contactId
        );
        $response = $this->makeRequest('contactList', $params, $args);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        $contact = $response->reply->contact;

        $info = array();
        foreach (array('Registrant') as $type) {
            $info[$type]['OrganizationName'] = array($this->user->lang('Organization'), (string)$contact->company);
            $info[$type]['FirstName'] = array($this->user->lang('First Name'), (string)$contact->first_name);
            $info[$type]['LastName'] = array($this->user->lang('Last Name'), (string)$contact->last_name);
            $info[$type]['Address1'] = array($this->user->lang('Address').' 1', (string)$contact->address);
            $info[$type]['Address2'] = array($this->user->lang('Address').' 2', (string)$contact->address2);
            $info[$type]['City'] = array($this->user->lang('City'), (string)$contact->city);
            $info[$type]['StateProv'] = array($this->user->lang('Province').'/'.$this->user->lang('State'), (string)$contact->state);
            $info[$type]['Country']  = array($this->user->lang('Country'), (string)$contact->country);
            $info[$type]['PostalCode']  = array($this->user->lang('Postal Code'), (string)$contact->zip);
            $info[$type]['EmailAddress'] = array($this->user->lang('E-mail'), (string)$contact->email);
            $info[$type]['Phone'] = array($this->user->lang('Phone'), (string)$contact->phone);
            $info[$type]['Fax'] = array($this->user->lang('Fax'), (string)$contact->fax);
        }

        return $info;
    }

    public function setContactInformation($params)
    {
        $response = $this->getDomainInformation($params);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        $contactId = $response->reply->contact_ids->registrant;
        $args = array (
            'contact_id'    => (int)$contactId,
            'fn'            => $params['Registrant_FirstName'],
            'ln'            => $params['Registrant_LastName'],
            'ad'            => $params['Registrant_Address1'],
            'cy'            => $params['Registrant_City'],
            'st'            => $params['Registrant_StateProv'],
            'zp'            => $params['Registrant_PostalCode'],
            'ct'            => $params['Registrant_Country'],
            'em'            => $params['Registrant_EmailAddress'],
            'ph'            => $this->validatePhone($params['Registrant_Phone']),
            'cp'            => $params['Registrant_OrganizationName']

        );

        $response = $this->makeRequest('contactUpdate', $params, $args);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
    }

    public function getNameServers($params)
    {
        $response = $this->getDomainInformation($params);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        $info = array();
        $info['usesDefault'] = false;
        $info['hasDefault'] = false;
        foreach ( $response->reply->nameservers->nameserver as $nameserver ) {
            $info[] = (string)$nameserver;
        }
        return $info;
    }

    public function setNameServers($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = array(
            'domain' => $domain
        );

        foreach ($params['ns'] as $key => $value) {
            $args['ns'.$key] = $value;
        }
        $response = $this->makeRequest('changeNameServers', $params, $args);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
    }

    public function getDNS($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = array(
            'domain' => $domain
        );
        $response = $this->makeRequest('dnsListRecords', $params, $args);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        $records = array();
        foreach ( $response->reply->resource_record as $r ) {
            $record = array(
                'id'            =>  (string)$r->record_id,
                'hostname'      =>  (string)$r->host,
                'address'       =>  (string)$r->value,
                'type'          =>  (string)$r->type
            );
            $records[] = $record;
        }

        $types = array('A', 'MX', 'CNAME', 'TXT');
        return array('records' => $records, 'types' => $types, 'default' => true);
    }

    public function setDNS($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = array(
            'domain' => $domain
        );
        foreach ($params['records'] as $index => $record) {
            $args['rrhost'] = $record['hostname'];
            $args['rrvalue'] = $record['address'];

            if ( $record['new'] == true ) {
                $args['rrtype'] = $record['type'];
                $command = 'dnsAddRecord';
            } else {
                $args['rrid'] = $record['id'];
                $command = 'dnsUpdateRecord';
            }

            $response = $this->makeRequest($command, $params, $args);
            if ( $response->reply->code != 300 ) {
                CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
                throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
            }
        }
    }

    public function doRenew($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->renewDomain($this->buildRenewParams($userPackage,$params));
        $userPackage->setCustomField("Registrar Order Id",$userPackage->getCustomField("Registrar") . '-' . $params['userPackageId']);
        return $userPackage->getCustomField('Domain Name') . ' has been renewed.';
    }

    public function renewDomain($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = array(
            'domain' => $domain,
            'years'  => $params['NumYears']
        );
        $response = $this->makeRequest('renewDomain', $params, $args);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
    }

    public function doDomainTransferWithPopup($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $transferid = $this->initiateTransfer($this->buildTransferParams($userPackage,$params));
        $userPackage->setCustomField("Registrar Order Id",$userPackage->getCustomField("Registrar") . '-' . $params['userPackageId']);
        $userPackage->setCustomField('Transfer Status', $transferid);
        return "Transfer of has been initiated.";
    }

    public function initiateTransfer($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = array(
            'domain' => $domain,
            'years'  => $params['NumYears'],
            'auth'   => $params['eppCode']

        );
        $response = $this->makeRequest('transferDomain', $params, $args);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
        return $domain;
    }

    public function getTransferStatus($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = array(
            'domain' => $domain
        );
        $response = $this->makeRequest('checkTransferStatus', $params, $args);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);

        }
        $userPackage = new UserPackage($params['userPackageId']);

        $transferStatus = (string)$response->reply->message;
        if ( $transferStatus == 'Transfer Completed' ) {
            $userPackage->setCustomField('Transfer Status', 'Completed');
        }

        return $transferStatus;
    }

    public function doTogglePrivacy($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $status = $this->togglePrivacy($this->buildRegisterParams($userPackage,$params));
        return "Turned privacy {$status} for " . $userPackage->getCustomField('Domain Name') . '.';
    }

    public function togglePrivacy($params)
    {
        $response = $this->getDomainInformation($params);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        $command = 'addPrivacy';
        $returnResult = 'on';
        if ( strtolower($response->reply->private) == 'yes' ) {
            $command = 'removePrivacy';
            $returnResult = 'off';
        }

        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = array(
            'domain'        => $domain
        );
        $response = $this->makeRequest($command, $params, $args);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
        return $returnResult;
    }

    // The following functions are not used anymore.
    // ToDo: These should not be abstract in RegistrarPlugin anymore.
    public function checkNSStatus($params){}
    public function registerNS($params){}
    public function editNS($params){}
    public function deleteNS($params){}

    private function getDomainInformation($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = array(
            'domain'        => $domain
        );

        $response = $this->makeRequest('getDomainInfo', $params, $args);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        return $response;
    }

    public function fetchDomains($params)
    {
        $args = array();
        $response = $this->makeRequest('listDomains', $params, $args);
        if ( $response->reply->code != 300 ) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
        $domainsList = array();
        foreach ( $response->reply->domains->domain as $domain ) {
            $aDomain = DomainNameGateway::splitDomain((string)$domain);
            $data = array();
            $data['id'] = (string)$domain->domain;
            $data['sld'] = $aDomain[0];
            $data['tld'] = $aDomain[1];

            // get expiration date
            $params['sld'] = $aDomain[0];
            $params['tld'] = $aDomain[1];
            $expResponse = $this->getDomainInformation($params);
            $data['exp'] = (string)$expResponse->reply->expires;

            $domainsList[] = $data;
        }
        return array($domainsList, array());
    }

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

    private function validatePhone($phone)
    {
        // strip all non numerical values
        $phone = preg_replace('/[^\d]/', '', $phone);
        return $phone;
    }
}