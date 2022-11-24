<?php

require_once 'modules/admin/models/RegistrarPlugin.php';

class PluginNamesilo extends RegistrarPlugin
{
    public $features = [
        'nameSuggest' => false,
        'importDomains' => true,
        'importPrices' => true,
    ];

    private $sandboxURL = 'https://sandbox.namesilo.com/api/';
    private $liveURL = 'https://www.namesilo.com/api/';
    private $apiVersion = '1';
    private $returnType = 'xml';

    private $connectionIssueCodes = [
        109,
        110,
        111,
        112,
        113,
        115,
        201,
        210
    ];

    public function getVariables()
    {
        $variables = [
            lang('Plugin Name') => [
                'type' => 'hidden',
                'description' => lang('How CE sees this plugin (not to be confused with the Signup Name)'),
                'value' => lang('NameSilo')
            ],
            lang('Use testing server') => [
                'type' => 'yesno',
                'description' => lang('Select Yes if you wish to use NameSilo\'s testing environment, so that transactions are not actually made.'),
                'value' => 0
            ],
            lang('API Key') => [
                'type' => 'text',
                'description' => lang('Enter your API Key'),
                'value' => ''
            ],
            lang('Auto Renew Domains?') => [
                'type' => 'yesno',
                'description' => lang('Select Yes if you wish to have auto renew enabled for new registrations and transfers.'),
                'value' => 0
            ],
            lang('Supported Features')  => [
                'type' => 'label',
                'description' => '* '.lang('TLD Lookup').'<br>* '.lang('Domain Registration').' <br>* '.lang('Existing Domain Importing').' <br>* '.lang('Get / Set Auto Renew Status').' <br>* '.lang('Get / Set DNS Records').' <br>* '.lang('Get / Set Nameserver Records').' <br>* '.lang('Get / Set Contact Information').' <br>* '.lang('Get / Set Registrar Lock').' <br>* '.lang('Initiate Domain Transfer').' <br>* '.lang('Automatically Renew Domain').' <br>* '.lang('Send Transfer Key'),
                'value' => ''
            ],
            lang('Actions') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain isn\'t registered)'),
                'value' => 'Register'
            ],
            lang('Registered Actions') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain is registered)'),
                'value' => 'Renew (Renew Domain),togglePrivacy (Toggle Privacy),DomainTransferWithPopup (Initiate Transfer),SendTransferKey (Send Auth Info),Cancel',
            ],
            lang('Registered Actions For Customer') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain is registered)'),
                'value' => 'togglePrivacy (Toggle Privacy),SendTransferKey (Send Auth Info)',
            ],
        ];
        return $variables;
    }

    public function getTLDsAndPrices($params)
    {
        $tlds = [];
        $response = $this->makeRequest('getPrices', [], []);
        foreach (get_object_vars($response->reply) as $tld => $obj) {
            if ($tld == 'code' || $tld == 'detail') {
                continue;
            }
            $tlds[$tld]['pricing']['register'] = $obj->registration;
            $tlds[$tld]['pricing']['transfer'] = $obj->transfer;
            $tlds[$tld]['pricing']['renew'] = $obj->renew;
        }
        return $tlds;
    }

    public function checkDomain($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = [
            'domains' => $domain
        ];

        $response = $this->makeRequest('checkRegisterAvailability', $params, $args);

        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            return [5];
        }

        $domains = [];
        $aDomain = DomainNameGateway::splitDomain($domain);
        if (isset($response->reply->available->domain)) {
            $domains[] = [
                'tld' => $aDomain[1],
                'domain' => $aDomain[0],
                'status' => 0
            ];
        } else {
            $domains[] = [
                'tld' => $aDomain[1],
                'domain' => $aDomain[0],
                'status' => 1
            ];
        }

        return ['result' => $domains];
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
        $requiredFields = [
            'RegistrantFirstName',
            'RegistrantLastName',
            'RegistrantAddress1',
            'RegistrantCity',
            'RegistrantStateProvince',
            'RegistrantPostalCode',
            'RegistrantCountry',
            'RegistrantEmailAddress',
            'RegistrantPhone'
        ];
        $errors = [];
        foreach ($requiredFields as $field) {
            if (empty($params[$field])) {
                $errors[] = $this->user->lang('%s can not be empty.', $field);
            }
        }
        if (count($errors) > 0) {
            throw new CE_Exception(implode("\n", $errors));
        }

        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = [
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
        ];

        if (isset($params['package_addons']['IDPROTECT']) && $params['package_addons']['IDPROTECT'] == 1) {
            $args['private'] = 1;
        }

        if ($this->getVariable('Auto Renew Domains?') == 1) {
            $args['auto_renew'] = 1;
        }

        if ($this->settings->get('plugin_namesilo_Use testing server')) {
            $args['ns1'] = 'NS1.NAMESILO.COM';
            $args['ns2'] = 'NS2.NAMESILO.COM';
        } elseif (isset($params['NS1'])) {
            // NameSilo allows for 13 total name servers
            for ($i = 1; $i <= 13; $i++) {
                if (isset($params["NS$i"])) {
                    $args["ns$i"] = $params["NS$i"]['hostname'];
                } else {
                    break;
                }
            }
        }

        $response = $this->makeRequest('registerDomain', $params, $args);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
    }

    public function getGeneralInfo($params)
    {
        $response = $this->getDomainInformation($params);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        $data = [];
        $data['domain'] = $domain;
        $data['expiration'] = (string)$response->reply->expires;
        $data['registrationstatus'] = (string)$response->reply->status;
        $data['purchasestatus'] = 'N/A';
        $data['autorenew'] = 0;

        if (strtolower($response->reply->auto_renew) == 'yes') {
            $data['autorenew'] = 1;
        }

        // we should also update the autorenew here:
        if ($params['userPackageId']) {
            $userPackage = new UserPackage($params['userPackageId']);
            $userPackage->setCustomField("Auto Renew", $data['autorenew']);
        }

        return $data;
    }

    public function getRegistrarLock($params)
    {
        $response = $this->getDomainInformation($params);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        if (strtolower($response->reply->locked) == 'yes') {
            return 1;
        }
        return 0;
    }

    function doSendTransferKey($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->sendTransferKey($this->buildRegisterParams($userPackage, $params));
        return 'Successfully sent auth info for ' . $userPackage->getCustomField('Domain Name');
    }

    public function sendTransferKey($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = [
            'domain' => $domain
        ];
        $response = $this->makeRequest('retrieveAuthCode', $params, $args);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
    }

    function doSetRegistrarLock($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->setRegistrarLock($this->buildLockParams($userPackage, $params));
        return "Updated Registrar Lock.";
    }

    function setRegistrarLock($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = [
            'domain' => $domain
        ];

        $command = 'domainUnlock';
        if ($params['lock'] == 1) {
            // we are locking
            $command = 'domainLock';
        }

        $response = $this->makeRequest($command, $params, $args);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
    }

    public function setAutorenew($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = [
            'domain' => $domain
        ];

        $command = 'addAutoRenewal';
        if (!$params['autorenew']) {
            $command = 'removeAutoRenewal';
        }

        $response = $this->makeRequest($command, $params, $args);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
        return lang('Domain updated successfully');
    }

    public function getContactInformation($params)
    {
        $response = $this->getDomainInformation($params);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        $contactId = $response->reply->contact_ids->registrant;
        $args = [
            'contact_id' => $contactId
        ];
        $response = $this->makeRequest('contactList', $params, $args);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        $contact = $response->reply->contact;

        $info = [];
        foreach (['Registrant'] as $type) {
            $info[$type]['OrganizationName'] = [$this->user->lang('Organization'), (string)$contact->company];
            $info[$type]['FirstName'] = [$this->user->lang('First Name'), (string)$contact->first_name];
            $info[$type]['LastName'] = [$this->user->lang('Last Name'), (string)$contact->last_name];
            $info[$type]['Address1'] = [$this->user->lang('Address').' 1', (string)$contact->address];
            $info[$type]['Address2'] = [$this->user->lang('Address').' 2', (string)$contact->address2];
            $info[$type]['City'] = [$this->user->lang('City'), (string)$contact->city];
            $info[$type]['StateProv'] = [$this->user->lang('Province').'/'.$this->user->lang('State'), (string)$contact->state];
            $info[$type]['Country']  = [$this->user->lang('Country'), (string)$contact->country];
            $info[$type]['PostalCode']  = [$this->user->lang('Postal Code'), (string)$contact->zip];
            $info[$type]['EmailAddress'] = [$this->user->lang('E-mail'), (string)$contact->email];
            $info[$type]['Phone'] = [$this->user->lang('Phone'), (string)$contact->phone];
            $info[$type]['Fax'] = [$this->user->lang('Fax'), (string)$contact->fax];
        }

        return $info;
    }

    public function setContactInformation($params)
    {
        $response = $this->getDomainInformation($params);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        $contactId = $response->reply->contact_ids->registrant;
        $args = [
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
        ];

        $response = $this->makeRequest('contactUpdate', $params, $args);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
    }

    public function getNameServers($params)
    {
        $response = $this->getDomainInformation($params);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        $info = [];
        $info['usesDefault'] = false;
        $info['hasDefault'] = false;
        foreach ($response->reply->nameservers->nameserver as $nameserver) {
            $info[] = (string)$nameserver;
        }
        return $info;
    }

    public function setNameServers($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = [
            'domain' => $domain
        ];

        foreach ($params['ns'] as $key => $value) {
            $args['ns'.$key] = $value;
        }
        $response = $this->makeRequest('changeNameServers', $params, $args);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
    }

    public function getDNS($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = [
            'domain' => $domain
        ];

        $response = $this->makeRequest('dnsListRecords', $params, $args);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        $records = [];
        foreach ($response->reply->resource_record as $r) {
            $record = [
                'id'            =>  (string)$r->record_id,
                'hostname'      =>  (string)$r->host,
                'address'       =>  (string)$r->value,
                'type'          =>  (string)$r->type
            ];
            $records[] = $record;
        }

        $types = ['A', 'MX', 'CNAME', 'TXT'];
        return ['records' => $records, 'types' => $types, 'default' => true];
    }

    public function setDNS($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = [
            'domain' => $domain
        ];

        // delete all records first
        $response = $this->makeRequest('dnsListRecords', $params, $args);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
        foreach ($response->reply->resource_record as $r) {
            $args = [
                'domain' => $domain,
                'rrid' => (string)$r->record_id
            ];
            $response = $this->makeRequest('dnsDeleteRecord', $params, $args);
        }

        // re-add any
        foreach ($params['records'] as $index => $record) {
            $args['rrhost'] = preg_replace("/\.{$domain}$/", '', $record['hostname']);
            $args['rrvalue'] = $record['address'];
            $args['rrtype'] = $record['type'];
            $response = $this->makeRequest('dnsAddRecord', $params, $args);
            if ($response->reply->code != 300) {
                CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            }
        }
    }

    public function doRenew($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->renewDomain($this->buildRenewParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar") . '-' . $params['userPackageId']);
        return $userPackage->getCustomField('Domain Name') . ' has been renewed.';
    }

    public function renewDomain($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = [
            'domain' => $domain,
            'years'  => $params['NumYears']
        ];
        $response = $this->makeRequest('renewDomain', $params, $args);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
    }

    public function doDomainTransferWithPopup($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $transferid = $this->initiateTransfer($this->buildTransferParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar") . '-' . $params['userPackageId']);
        $userPackage->setCustomField('Transfer Status', $transferid);
        return "Transfer of has been initiated.";
    }

    public function initiateTransfer($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = [
            'domain' => $domain,
            'years'  => $params['NumYears'],
            'auth'   => $params['eppCode'],
            'auto_renew' => 0
        ];
        if ($this->getVariable('Auto Renew Domains?') == 1) {
            $args['auto_renew'] = 1;
        }
        $response = $this->makeRequest('transferDomain', $params, $args);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
        return $domain;
    }

    public function getTransferStatus($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = [
            'domain' => $domain
        ];
        $response = $this->makeRequest('checkTransferStatus', $params, $args);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
        $userPackage = new UserPackage($params['userPackageId']);

        $transferStatus = (string)$response->reply->message;
        if (strpos($transferStatus, 'has completed transferring') !== false) {
            $userPackage->setCustomField('Transfer Status', 'Completed');
        }

        return $transferStatus;
    }

    public function doTogglePrivacy($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $status = $this->togglePrivacy($this->buildRegisterParams($userPackage, $params));
        return "Turned privacy {$status} for " . $userPackage->getCustomField('Domain Name') . '.';
    }

    public function togglePrivacy($params)
    {
        $response = $this->getDomainInformation($params);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        $command = 'addPrivacy';
        $returnResult = 'on';
        if (strtolower($response->reply->private) == 'yes') {
            $command = 'removePrivacy';
            $returnResult = 'off';
        }

        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = [
            'domain'        => $domain
        ];
        $response = $this->makeRequest($command, $params, $args);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
        return $returnResult;
    }

    // The following functions are not used anymore.
    // ToDo: These should not be abstract in RegistrarPlugin anymore.
    public function checkNSStatus($params)
    {
    }
    public function registerNS($params)
    {
    }
    public function editNS($params)
    {
    }
    public function deleteNS($params)
    {
    }

    private function getDomainInformation($params)
    {
        $domain = strtolower($params['sld'] . '.' . $params['tld']);
        $args = [
            'domain' => $domain
        ];

        $response = $this->makeRequest('getDomainInfo', $params, $args);

        if (in_array($response->reply->code, $this->connectionIssueCodes)) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail, EXCEPTION_CODE_CONNECTION_ISSUE);
        }

        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }

        return $response;
    }

    public function fetchDomains($params)
    {
        $response = $this->makeRequest('listDomains', $params, []);
        if ($response->reply->code != 300) {
            CE_Lib::log(4, 'NameSilo Error: ' . $response->reply->detail);
            throw new CE_Exception('NameSilo Error: ' . $response->reply->detail);
        }
        $domainsList = [];
        foreach ($response->reply->domains->domain as $domain) {
            $aDomain = DomainNameGateway::splitDomain((string)$domain);
            $data = [];
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
        return [$domainsList, []];
    }

    private function makeRequest($command, $params, $arguments)
    {
        include_once 'library/CE/NE_Network.php';

        $request = $this->liveURL;
        if ($this->settings->get('plugin_namesilo_Use testing server')) {
            $request = $this->sandboxURL;
        }
        $request .= $command;

        $arguments['key'] = $this->settings->get('plugin_namesilo_API Key');
        $arguments['version'] = $this->apiVersion;
        $arguments['type'] = $this->returnType;

        $i = 0;
        foreach ($arguments as $name => $value) {
            $value = urlencode($value);
            if ($i == 0) {
                $request .= "?$name=$value";
            } else {
                $request .= "&$name=$value";
            }
            $i++;
        }

        $response = NE_Network::curlRequest($this->settings, $request);

        if ($response instanceof CE_Error) {
            throw new CE_Exception($response);
        }

        $response = @simplexml_load_string(trim($response));
        if ($response === false) {
            throw new CE_Exception('Invalid XML from NameSilo', EXCEPTION_CODE_CONNECTION_ISSUE);
        }
        return $response;
    }

    private function validatePhone($phone)
    {
        // strip all non numerical values
        $phone = preg_replace('/[^\d]/', '', $phone);
        return $phone;
    }
}
