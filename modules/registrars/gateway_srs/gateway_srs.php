<?php
    /*
     * Approved by ModulesGarden
     *
     * This modules allows you to transfer, register, check availability and
     * use other fucntions for domains within whmcs.
     *
     * The modules files must be stored in: modules/registrars/
     * The modules name must always be lowercase and start with a letter
     * and only use letter and numbers for the modules name.
     *
     * The modules functions all must start with
     * the modules name followed by an understcore
     * for example ModuleName_FunctionName(){
     *}
     * @copyright Copyright (c) DNS Africa 2023
     * @author  David Peall <david@dns.business>
     */

    if (!defined('WHMCS')) {
        die('This file cannot be accessed directly');
    }

    require_once __DIR__ . '/lib/bootstrap.php';

    use \WHMCS\Module\Registrar\gateway_srs\Module;
    use \WHMCS\Module\Registrar\gateway_srs\RuntimeState;
    use \WHMCS\Module\Registrar\gateway_srs\RestAPIException;

    use \WHMCS\Domains\DomainLookup\ResultsList;
    use \WHMCS\Domains\DomainLookup\SearchResult;
    use \WHMCS\Exception\Module\InvalidConfiguration;
    use \WHMCS\Module\Registrar\gateway_srs\DNSAPI;
    use \WHMCS\Database\Capsule;
    use \WHMCS\Domain\TopLevel\ImportItem;


    // Require any libraries needed for the modules to function.

    /**
     * Define modules related metadata
     *
     * Provide some modules information including
     * the display name and API Version to
     * determine the method of decoding the input values.
     *
     * @return array
     */

    function gateway_srs_MetaData()
    {
        $meta = json_decode(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . "whmcs.json"), true);
        return [
            'DisplayName' => 'Gateway SRS ' . Module::version()['version'],
            'APIVersion' => '1.1',
        ];
    }

    /**
     * Define registrar configuration options.
     *
     * configuration file can be found in whmcs->
     * Setup->Products/Services->Domain Registrars->Your ModuleName -> Configure
     *
     * The following field types are supported:
     *  * Text
     *  * Password
     *  * Yes/No Checkboxes
     *  * Dropdown Menus
     *  * Radio Buttons
     *  * Text Areas
     */

    function gateway_srs_getConfigArray()
    {
        $meta = json_decode(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . "whmcs.json"), true);

        return [
            'FriendlyName' => [
                'Type' => 'System',
                'Value' => 'Gateway SRS ' . Module::version()['version']
            ],
            'Description' => [
                'Type' => 'System',
                'Value' => $meta['description']['tagline']
            ],
            'Authentication' => [
                'FriendlyName' => 'Authentication', 'Type' => 'dropdown',
                'Options' => ['password' => 'Portal username and password', 'jwt' => 'Portal-issued JWT'],
                'Default' => 'password', 'Description' => 'Existing installations continue to use portal credentials.'
            ],
            'JWT_Token' => [
                'FriendlyName' => 'Portal-issued JWT', 'Type' => 'password', 'Size' => '60', 'Default' => '',
                'Description' => 'Paste the API-user access token from the portal. Expiry warnings start at 30 days and become urgent at 7 days.'
            ],
            'Module_Status' => [
                'Type' => 'System', 'Value' => '<a href="addonmodules.php?module=gateway_srs_manager">Module status and updates</a> (activate Gateway SRS Manager under Addon Modules).'
            ],
            'Portal_Username' => [
                'FriendlyName' => 'Portal Username',
                'Type' => 'text',
                'Size' => '25',
                'Default' => '',
                'Description' => 'Username for https://portal.gatewaysrs.com/'
            ],
            'Portal_Password' => [
                'FriendlyName' => 'Password',
                'Type' => 'password',
                'Size' => '25',
                'Default' => '',
                'Description' => ''
            ],
            // the dropdown field type renders a select menu of options
            'AccountMode' => [
                'FriendlyName' => 'Account Mode',
                'Type' => 'dropdown',
                'Size' => '25',
                'Options' => [
                    'live' => 'Live',
                    'ote' => 'Testing (OTE Account)',
                    'dev' => 'Development (DEV Account)'
                ],
                'Description' => 'Choose one',
            ],
            'debug' => [
                'FriendlyName' => 'Debug',
                'Type' => 'yesno',
                'Description' => 'Log HTTP metadata only. Credentials and API bodies are never logged.',
            ],
        ];
    }

    /**
     * @throws InvalidConfiguration
     */
    function gateway_srs_config_validate($params)
    {
        try {
            Module::api($params)->validateConnection();
        } catch (\Throwable $e) {
            throw new InvalidConfiguration($e->getMessage());
        }
        // Keep the old name migration idempotent and restricted to known legacy names.
        $state = new RuntimeState();
        $lock = $state->lock('legacy-migration');
        try {
            if (empty($state->read('legacy-migration')['complete'])) {
                Capsule::connection()->transaction(static function () {
                    Capsule::table('tbldomains')->whereIn('registrar', ['dns_gateway', 'ns_gateway'])->update(['registrar' => 'gateway_srs']);
                    Capsule::table('tbldomainpricing')->whereIn('autoreg', ['dns_gateway', 'ns_gateway'])->update(['autoreg' => 'gateway_srs']);
                });
                $state->write('legacy-migration', ['complete' => true]);
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        return null;
    }


    # Function to register domain
    /*
     * Called when the registration of a new domain is initiated within WHMCS.
     *
     * Function will trigger a payment is made or a new domain is registered
     */

    function gateway_srs_RegisterDomain($params)
    {
        try {
            // Registration parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;
            $registrationPeriod = $params['regperiod'];

            //Nameservers
            $nameservers = [];
            for ($n = 1; $n <= 5; $n++) {
                if (isset($params['ns' . $n]) && $params['ns' . $n] != '') {
                    $nameservers[] = [
                        'hostname' => $params['ns' . $n],
                    ];
                }
            }

            // Connect To API
            $api = Module::api($params);

            // Registrant information
            $registrant_info = [
                'id' => $api->generate_id($domain),
                'phone' => $params['fullphonenumber'],
                'fax' => NULL,
                'email' => $params['email'],
                'contact_address' => [
                    [
                        'real_name' => $params['fullname'],
                        'org' => $params['companyname'],
                        'street' => $params['address1'],
                        'street2' => $params['address2'],
                        'street3' => NULL,
                        'city' => $params['city'],
                        'province' => $params['state'],
                        'code' => $params['postcode'],
                        'country' => $params['countrycode'],
                        'type' => 'loc'
                    ],
                    [
                        'real_name' => iconv("UTF-8", "ASCII//TRANSLIT", $params['fullname']),
                        'org' => iconv("UTF-8", "ASCII//TRANSLIT", $params['companyname']),
                        'street' => iconv("UTF-8", "ASCII//TRANSLIT", $params['address1']),
                        'street2' => iconv("UTF-8", "ASCII//TRANSLIT", $params['address2']),
                        'street3' => NULL,
                        'city' => iconv("UTF-8", "ASCII//TRANSLIT", $params['city']),
                        'province' => iconv("UTF-8", "ASCII//TRANSLIT", $params['state']),
                        'code' => $params['postcode'],
                        'country' => $params['countrycode'],
                        'type' => 'int'
                    ]
                ]
            ];

            // Admin contact information.
            $admin_info = [
                'id' => $api->generate_id($domain),
                'phone' => $params['adminfullphonenumber'],
                'fax' => NULL,
                'email' => $params['adminemail'],
                'contact_address' => [
                    [
                        'real_name' => $params['adminfirstname'] .
                            ' ' . $params['adminlastname'],
                        'org' => $params['admincompanyname'],
                        'street' => $params['adminaddress1'],
                        'street2' => $params['adminaddress2'],
                        'street3' => NULL,
                        'city' => $params['admincity'],
                        'province' => $params['adminstate'],
                        'code' => $params['adminpostcode'],
                        'country' => $params['admincountry'],
                        'type' => 'loc'
                    ],
                    [
                        'real_name' => iconv("UTF-8", "ASCII//TRANSLIT", $params['adminfirstname']) .
                            ' ' . iconv("UTF-8", "ASCII//TRANSLIT", $params['adminlastname']),
                        'org' => iconv("UTF-8", "ASCII//TRANSLIT", $params['admincompanyname']),
                        'street' => iconv("UTF-8", "ASCII//TRANSLIT", $params['adminaddress1']),
                        'street2' => iconv("UTF-8", "ASCII//TRANSLIT", $params['adminaddress2']),
                        'street3' => NULL,
                        'city' => iconv("UTF-8", "ASCII//TRANSLIT", $params['admincity']),
                        'province' => iconv("UTF-8", "ASCII//TRANSLIT", $params['adminstate']),
                        'code' => $params['adminpostcode'],
                        'country' => $params['admincountry'],
                        'type' => 'int'
                    ]
                ]
            ];

            // Billing contact information.
            $billing_info = $admin_info;
            $billing_info["id"] = $api->generate_id($domain);

            // Tech contact information.
            $tech_info = $admin_info;
            $tech_info["id"] = $api->generate_id($domain);

            /**
             * Premium domain parameters.
             *
             * Premium domains enabled informs you
             * if the admin user has enabled
             * the selling of premium domain names.
             * If this domain is a premium name,
             * `premiumCost` will contain the cost
             * price retrieved at the time of
             * the order being placed. The premium
             * order should only be processed
             * if the cost price now matches the
             * previously fetched amount.
             */
            $premiumDomainsEnabled = !empty($params['premiumEnabled']);
            $premiumDomainsCost = $params['premiumCost'] ?? null;

            // Create Contacts
            $registrant_contact = $api->setcontact($registrant_info);
            $admin_contact = $api->setcontact($admin_info);
            $billing_contact = $api->setcontact($billing_info);
            $tech_contact = $api->setcontact($tech_info);

            // Register Domain
            $domain = [
                'name' => $domain,
                'period' => $registrationPeriod,
                'period_unit' => 'y',
                'autorenew' => false,
                'authinfo' => $api->generate_password($domain),
                'hosts' => $nameservers,
                'contacts' => [
                    [
                        'type' => 'registrant',
                        'contact' => [
                            'id' => $registrant_contact
                        ]
                    ],
                    [
                        'type' => 'admin',
                        'contact' => [
                            'id' => $admin_contact
                        ]
                    ],
                    [
                        'type' => 'billing',
                        'contact' => [
                            'id' => $billing_contact
                        ]
                    ],
                    [
                        'type' => 'tech',
                        'contact' => [
                            'id' => $tech_contact
                        ]
                    ]
                ]
            ];

            //  Add Premium Charge If Applicable
            if ($premiumDomainsEnabled && $premiumDomainsCost) {
                $domain['charge'] = array('price' => $premiumDomainsCost);
            }

            //  Call The Register Domain Function
            $api->register_domain($domain);

            return [
                'success' => true
            ];

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to transfer a domain
    /*
     * Called when a domain transfer request is initiated within WHMCS.
     *
     * Function is triggered when a peding domain trasfer is accpeted,
     * when a payment is recieved for a domains transfer,
     * manual transfer by admin.
     */

    function gateway_srs_TransferDomain($params)
    {
        // Registration parameters
        $sld = $params['sld'];
        $tld = ltrim($params['tld'], '.');
        $domain = $sld . '.' . $tld;
        $registrationPeriod = $params['regperiod'];
        $eppCode = htmlspecialchars_decode($params['eppcode']);
        if ($tld == 'co.za') {
            $eppCode = 'coza';
        }

        // Connect To API
        try {
            $api = Module::api($params);
            // Check if domain is already pending transfer
            $domain_transfer_in = $api->list_domain_transfer_in($domain);

            if (isset($domain_transfer_in[0]['wid'])) {
                return ['success' => true];
            }

            /**
             * Premium domain parameters.
             *
             * Premium domains enabled informs you if
             * the admin user has enabled
             * the selling of premium domain names.
             * If this domain is a premium name,
             * `premiumCost` will contain the cost price
             * retrieved at the time of
             * the order being placed.
             * The premium order should
             * only be processed
             * if the cost price now
             * matches the previously fetched amount.
             */
            $premiumDomainsEnabled = !empty($params['premiumEnabled']);
            $premiumDomainsCost = $params['premiumCost'] ?? null;

            // Transfer Domain
            $domain_array = [
                'name' => $domain,
                'authinfo' => $eppCode,
                'period' => $registrationPeriod
            ];

            // Add Premium Charge If Applicable
            if ($premiumDomainsEnabled && $premiumDomainsCost) {
                $domain_array['charge']['price'] = $premiumDomainsCost;
            }

            //  Call The Register Domain Function
            $api->transfer_domain($domain_array);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    # Function to renew domain
    /*
     * Called when a request to renew a domain is initiated within WHMCS.
     *
     * Function is trigered when a payment is recvied for a domain renew order,
     * pending renew order is accepted,
     * manual renewal by admin.
     *
     * Attempt to renew/extend a domain for a given number of years.
     */

    function gateway_srs_RenewDomain($params)
    {
        try {
            // Registration parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;
            $registrationPeriod = $params['regperiod'];


            $api = Module::api($params);


            // Get Domain info
            $domain_list = $api->list_domains($domain);

            // Renew Domain
            $renew_array = [
                'name' => $domain,
                'period' => $registrationPeriod,
                'period_unit' => 'y',
                'curExpDate' => $domain_list[0]['curExpDate']
            ];

            $api->renew_domain($renew_array);

            return [
                'success' => true
            ];

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch current nameservers.
     *
     * This function should return an array of nameservers for a given domain.
     *
     * Called when a domain is viewed within WHMCS. It can
     * return up to 5 nameservers that are set for the domain.
     *
     * $sld = eg. yourdomain
     * $tld = eg. .com
     */

    function gateway_srs_GetNameservers($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Connect To API
            $api = Module::api($params);


            $domain_info = $api->get_domain_nameservers($domain);

            $count = 0;
            $nameservers = [];
            foreach ($domain_info['nameservers'] as $namserver) {
                $count++;
                $nameservers['ns' . $count] = $namserver['hostname'];
            }
            return $nameservers;

        } catch (\Exception $e) {
            if (strpos($e->getMessage(), '404')) {
                return NULL;
            }
            return [
                'error' => $e->getMessage()
            ];
        }
    }


    # Function to save set of nameservers
    /*
     * Called when a change is submitted for a domains nameservers.
     *
     * $sld = eg. yourdomain
     * $tld = eg. .com
     */

    function gateway_srs_SaveNameservers($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;
            $registrationPeriod = (
            $params['regperiod'] > 0 ?
                $params['regperiod'] :
                1
            );

            // Connect To API
            $api = Module::api($params);


            // Grab Domain Info
            $domain_view = $api->get_domain_nameservers($domain);


            // Handle Nameservers
            $hosts = $domain_view['nameservers'];
            $new_hosts = [];

            for ($n = 1; $n <= 5; $n++) {
                if (isset($params['ns' . $n]) && $params['ns' . $n] != '') {
                    // Compare Nameservers
                    $host_record = [
                        'hostname' => $params['ns' . $n]
                    ];
                    foreach ($hosts as $host_key => $host) {
                        if ($host['hostname'] == $params['ns' . $n]) {
                            $host_record['glue'] = $host['glue'] ?? [];
                        }
                    }
                    // Add Nameserver
                    $new_hosts[] = $host_record;
                }
            }

            // Update Domain
            $update_array = [
                'name' => $domain,
                'period' => $registrationPeriod,
                'period_unit' => 'y',
                'hosts' => $new_hosts,
                'contacts' => $api->get_domain_contacts($domain)["contacts"],
            ];
            $api->update_domain($update_array, $domain);
            return ['success' => 'success'];

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }


    # Function to grab contact details
    /*
     * Called when the WHOIS information is displayed within WHMCS.
     *
     * Should return a multi-level array of the contacts and name/address
     * fields that be modified.
     */

    function gateway_srs_GetContactDetails($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Connect To API
            $api = Module::api($params);


            // Grab Domain Info
            $domain_list = $api->list_domains($domain);
            $domain_view = $api->view_domain($domain);

            // Contacts Section
            $contacts = [];
            foreach ($domain_view['contacts'] as $contact) {
                // Grab Contact Info
                $contact_info = $api->contact_info($contact['contact']['id']);

                // Grab Contact Name And Divide It
                $name_parts = explode(
                    ' ',
                    $contact_info['contact_address'][0]['real_name']
                );
                $last_name = end($name_parts);
                array_pop($name_parts);
                $first_name = implode(' ', $name_parts);

                // Build Contact ID
                $contacts[ucfirst($contact['type'])] = [
                    'First Name' => $first_name,
                    'Last Name' => $last_name,
                    'Company Name' => $contact_info['contact_address'][0]['org'],
                    'Email Address' => $contact_info['email'],
                    'Address 1' => $contact_info['contact_address'][0]['street'],
                    'Address 2' => $contact_info['contact_address'][0]['street2'],
                    'City' => $contact_info['contact_address'][0]['city'],
                    'State' => $contact_info['contact_address'][0]['province'],
                    'Postcode' => $contact_info['contact_address'][0]['code'],
                    'Country' => $contact_info['contact_address'][0]['country'],
                    'Phone Number' => $contact_info['phone'],
                    'Fax Number' => $contact_info['fax']
                ];
            }
            return $contacts;

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to save contact details
    /*
     * Called when revised WHOIS information is submitted.
     * Update the WHOIS Contact Information for a given domain.
     */

    function gateway_srs_SaveContactDetails($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Connect To API
            $api = Module::api($params);


            // Grab Domain Info
            $domain_view = $api->get_domain_contacts($domain);

            // Loop Contacts
            foreach ($domain_view['contacts'] as $contact) {
                // Updated Contact information
                $new_details = $params['contactdetails'][ucfirst($contact['type'])];

                // Contact information.
                $contact_info = [
                    'phone' => $new_details['Phone Number'],
                    'email' => $new_details['Email Address'],
                    'contact_address' => [
                        [
                            'real_name' => $new_details['First Name'] .
                                ' ' . $new_details['Last Name'],
                            'org' => $new_details['Company Name'],
                            'street' => $new_details['Address 1'],
                            'street2' => $new_details['Address 2'],
                            'street3' => NULL,
                            'city' => $new_details['City'],
                            'province' => $new_details['State'],
                            'code' => $new_details['Postcode'],
                            'country' => $new_details['Country'],
                            'type' => 'loc'
                        ]
                    ]
                ];

/*                foreach ($contact_info['contact_address'][0] as $key => $contact_address) {
                    if (strlen($contact_address) < 1){
                        unset($contact_info['contact_address'][0][$key]);
                    }
                }*/

                if (isset($new_details['Fax Number']) && !empty($new_details['Fax Number'])) {
                    $contact_info['fax'] = $new_details['Fax Number'];
                }

                // Grab API Side Contact Info
                $api_contact = $api->contact_info($contact['contact']['id']);

                // Update Contact
                $api->update_contact($contact_info, $contact['contact']['id']);
            }

            return ['success' => 'success'];

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to check a domains availability and premium status
    /*
     * Check Domain Availability.
     * Determine if a domain is availability for registration or transfer.
     *
     * Check is the domain name is premium
     */
    function gateway_srs_CheckAvailability($params)
    {
        try {
            // Connect To API
            $api = Module::api($params);

            // Initiate Results List
            $results = new ResultsList();

            // Loop Through Domains To Check Availability
            foreach ($params['tldsToInclude'] as $tld) {
                $searchResult = new SearchResult($params['sld'], $tld);
                $domain_check = $api->domain_check($params['sld'] . '.' . ltrim($tld, '.'));

                $reason = strtolower($domain_check['results'][0]['reason']);
                $available = (int)$domain_check['results'][0]['avail'];

                $status = SearchResult::STATUS_NOT_REGISTERED;
                if ($available == 0) {
                    if ($reason == "in use" or $reason == "domain exists") {
                        $status = SearchResult::STATUS_REGISTERED;

                    } elseif (strpos($reason, "domain reserved") !== false) {
                        $status = SearchResult::STATUS_RESERVED;
                    } else {
                        $status = SearchResult::STATUS_UNKNOWN;
                    }
                } elseif ($available > 1) {
                    $status = SearchResult::STATUS_UNKNOWN;
                }

                $searchResult->setStatus($status);

                // Return premium information if applicable
                if (isset($domain_check['charge']['category']) && $domain_check['charge']['category'] !== 'standard') {
                    $searchResult->setPremiumDomain(true);
                    $domaincheckaction = $domain_check['charge']['action'];
                    $searchResult->setPremiumCostPricing([
                        'register' => $domaincheckaction['create'] ?? null,
                        'renew' => $domaincheckaction['renew'] ?? null,
                        'transfer' => $domaincheckaction['transfer'] ?? null,
                        'CurrencyCode' => $domain_check['charge']['currency'] ?? 'USD'
                    ]);
                }
                $results->append($searchResult);
            }

            return $results;

        } catch (\Exception $e) {
            return array(
                'error' => $e->getMessage()
            );
        }
    }

    # Function to Check if Registrar is locked
    /*
     * Called when a domains details are viewed within WHMCS.
     * It should return the current lock status of a domain.
     *
     * If a domain is locked it will be unable to-
     * Update
     * Transfer or
     * Delete
     */

    function gateway_srs_GetRegistrarLock($params)
    {

        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Call API
            $api = Module::api($params);

            $domain_lock = false;
            $domain_sync = $api->domain_sync($domain);
            if (isset($domain_sync['statuses'])) {
                foreach ($domain_sync['statuses'] as $val) {
                    if ($val['code'] == 'clientTransferProhibited') {
                        $domain_lock = true;
                    }
                }
            }


            if ($domain_lock == true) {
                return 'locked';
            } else {
                return 'unlocked';
            }

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to save Registrar Lock
    /*
     * Called when the lock status setting is toggled within WHMCS.
     */

    function gateway_srs_SaveRegistrarLock($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            $api = Module::api($params);

            if ($params['lockenabled'] == 'locked') {
                $api->lock($domain);
            } else {
                $api->unlock($domain);
            }
            return ['success' => 'success'];
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Get epp code
    /*
     * Called when the EPP Code is requested for a transfer out.
     *
     * Can display EPP code directly to the user or
     * indicate that the code will be emailed to the registrant.
     */

    function gateway_srs_GetEPPCode($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            if (
                $tld != 'co.za' &&
                $tld != 'net.za' &&
                $tld != 'org.za' &&
                $tld != 'web.za'
            ) {
                $dnsapi = Module::api($params);
                // Call api
                $values["eppcode"] = $dnsapi->domain_info($domain)["authinfo"];

                return $values;
            } else {
                return [
                    'error' => 'This TLD Does Not Support EPP Codes'
                ];
            }

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Request deletion of the domain name
    /*
     * Trigers when a domain deletion request comes from WHMCS or
     * manual deletion from admin. *
     */

    function gateway_srs_RequestDelete($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Call api
            $api = Module::api($params);

            $api->delete_domain($domain);

            return array(
                'success' => 'success',
            );

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to register nameserver
    /*
     * Called when a child nameserver registration request comes from WHMCS
     */

    function gateway_srs_RegisterNameserver($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;
            $registrationPeriod = (
            $params['regperiod'] > 0 ?
                $params['regperiod'] :
                1
            );

            // Nameserver parameters
            $nameserver = $params['nameserver'];
            $ipAddress = $params['ipaddress'];

            // Connect To API
            $api = Module::api($params);


            // Grab Domain Info
            $domain_list = $api->list_domains($domain);
            $domain_view = $api->view_domain($domain);

            // Prepare Hosts
            $hosts = $domain_view['hosts'];
            $hosts[] = [
                'hostname' => $params['nameserver'],
                'glue' => [
                    [
                        'ip' => $params['ipaddress'],
                        'class_field' => filter_var($params['ipaddress'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'v6' : 'v4'
                    ]
                ]
            ];

            // Update Domain & Hosts
            $update_array = [
                'name' => $domain,
                'period' => $registrationPeriod,
                'period_unit' => 'y',
                'hosts' => $hosts,
                'contacts' => $domain_view['contacts'],
            ];

            $api->update_domain($update_array, $domain);

            return true;

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Modify nameserver
    /*
     * Called when a child nameserver modification request comes from WHMCS
     *
     * Modifies the IP of a child nameserver.
     */

    function gateway_srs_ModifyNameserver($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;
            $registrationPeriod = (
            $params['regperiod'] > 0 ?
                $params['regperiod'] :
                1
            );

            // Nameserver parameters
            $nameserver = $params['nameserver'];
            $CurrentIpAddress = $params['currentipaddress'];
            $NewIpAddress = $params['newipaddress'];

            // Connect To API
            $api = Module::api($params);

            // Grab Domain Info
            $domain_list = $api->list_domains($domain);
            $domain_view = $api->view_domain($domain);

            // Prepare Hosts
            $hosts = $domain_view['hosts'];
            foreach ($hosts as $host_key => $host) {
                if ($host['hostname'] == $nameserver) {
                    // Go Through Glue Records
                    foreach ($host['glue'] as $glue_key => $glue) {
                        if ($glue['ip'] == $CurrentIpAddress) {
                            // Update Domain & Hosts
                            $hosts[$host_key]['glue'][$glue_key]['ip'] = $NewIpAddress;
                            $hosts[$host_key]['glue'][$glue_key]['class_field'] = filter_var($NewIpAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'v6' : 'v4';

                            $update_array = [
                                'name' => $domain,
                                'period' => $registrationPeriod,
                                'period_unit' => 'y',
                                'hosts' => $hosts,
                                'contacts' => $domain_view['contacts'],
                            ];

                            $api->update_domain($update_array, $domain);

                            return true;
                        }
                    }
                }
            }

            // Return Error If No Matching Hostname Was Found
            return [
                'error' => 'No Matching Hostname Was Found'
            ];

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Delete nameserver
    /*
     * Called when a child nameserver deletion request comes from WHMCS.
     */

    function gateway_srs_DeleteNameserver($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;


            // Nameserver parameters
            $nameserver = $params['nameserver'];

            // Connect To API
            $api = Module::api($params);

            // Grab Domain Info
            $domain_list = $api->list_domains($domain);
            $domain_view = $api->view_domain($domain);

            // Prepare Hosts
            $hosts = $domain_view['hosts'];
            $new_hosts = [];
            foreach ($hosts as $host_key => $host) {
                if ($host['hostname'] != $nameserver) {
                    $new_hosts[] = $host;
                }
            }
            // Update Domain & Hosts
            $update_array = [
                'name' => $domain,
                'period_unit' => 'y',
                'hosts' => $new_hosts,
                'contacts' => $domain_view['contacts'],
            ];
            $api->update_domain($update_array, $domain);

            return true;

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Sync Domain Status & Expiration Date.
     *
     * To ensure expiry date and status changes made
     * directly at the domain register are in sync with WHMCS.
     */

    function gateway_srs_Sync($params)
    {
        try {
            $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');
            $info = Module::api($params)->domain_sync($domain);
            $expiry = isset($info['expiry']) ? strtotime($info['expiry']) : false;
            if ($expiry === false) { return ['error' => 'Gateway SRS returned no valid domain expiry.']; }
            return ['expirydate' => gmdate('Y-m-d', $expiry), 'active' => $expiry > time(),
                'expired' => $expiry <= time(), 'transferredAway' => false];
        } catch (\Throwable $e) {
            // A 404 or an authentication failure is not evidence that the domain transferred away.
            return ['error' => $e->getMessage()];
        }
    }

    /*
     * Update Domain Auto-renew
     */

    function gateway_srs_update_autorenew($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Connect To API
            $api = Module::api($params);


            // Grab Domain Info
            $domain_list = $api->list_domains($domain);
            $domain_view = $api->view_domain($domain);

            // Update Domain
            $update_array = [
                'name' => $domain,
                'period' => 1,
                'period_unit' => 'y',
                'hosts' => $domain_view['hosts'],
                'contacts' => $domain_view['contacts'],
                'autorenew' => false
            ];
            $api->update_domain($update_array, $domain);

            return true;

        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /*
     * Update EPP Key
     */

    function gateway_srs_Update_EPP_key($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;
            $registrationPeriod = (
            $params['regperiod'] > 0 ?
                $params['regperiod'] :
                1
            );

            // Connect To API
            $api = Module::api($params);

            // Grab Domain Info
            $domain_list = $api->list_domains($domain);
            $domain_view = $api->view_domain($domain);

            // Update Domain
            $update_array = [
                'name' => $domain,
                'period' => $registrationPeriod,
                'period_unit' => 'y',
                'authinfo' => $api->generate_password($domain),
                'hosts' => $domain_view['hosts'],
                'contacts' => $domain_view['contacts'],
            ];
            $api->update_domain($update_array, $domain);

            return true;

        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }


    /*
     * Incoming Domain Transfer Sync.
     *
     * This function will be called for every domain in the
     * Pending Transfer status when the domain sync cron runs.
     *
     * Check status of incoming domain transfers and notify end-user upon
     * completion. This function is called daily for incoming domains.
     */

    function gateway_srs_TransferSync($params)
    {
        try {
            $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');
            $api = Module::api($params);
            $pending = $api->list_domain_transfer_in($domain);
            if ($pending) { return []; }
            $domains = $api->list_domains($domain);
            if (!$domains) { return []; }
            $info = $api->view_domain($domain);
            $expiry = isset($info['expiry']) ? strtotime($info['expiry']) : false;
            if ($expiry === false) { return ['error' => 'Gateway SRS returned no valid transfer expiry.']; }
            return ['completed' => true, 'expirydate' => gmdate('Y-m-d', $expiry)];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    # Function to cancel domain transfer
    /*
     *
     */

    function gateway_srs_Cancel_Transfer($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Connect To API
            $api = Module::api($params);


            // Build post data
            $domain_info = [
                'name' => $domain
            ];

            $api->cancel_domain_transfer($domain_info);

            return true;

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to reject domain transfer
    /*
     *
     */

    function gateway_srs_Reject_Transfer($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Connect To API
            $api = Module::api($params);

            // Get WID
            $domain_search = $api->list_domains($domain);
            $domain_wid = $domain_search[0]['wid'];

            // Submit Rejection
            $domain_info = [
                'name' => $domain
            ];
            $domain_reject = $api->reject_transfer_domain($domain_info, $domain_wid);

            return true;

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to Approve domain transfer
    /*
     *
     */

    function gateway_srs_Approve_Transfer($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Connect To API
            $api = Module::api($params);


            // Get WID
            $domain_search = $api->list_domains($domain);
            $domain_wid = $domain_search[0]['wid'];

            // Submit Rejection
            $domain_info = [
                'name' => $domain
            ];

            $domain_approve = $api->approve_transfer_domain(
                $domain_info,
                $domain_wid
            );

            return $domain_approve;

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to lock domain
    /*
     *
     */

    function gateway_srs_Lock_Domain($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Call API
            $api = Module::api($params);

            $api->lock($domain);


        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to unlock domain
    /*
     *
     */

    function gateway_srs_Unlock_Domain($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            //  Call API
            $api = Module::api($params);

            $api->unlock($domain);

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    function gateway_srs_Block_Domain($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Call API
            $api = Module::api($params);

            $api->block($domain);


        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    function gateway_srs_Unblock_Domain($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Call API
            $api = Module::api($params);

            $api->unblock($domain);


        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }
    # Function custom button in admin area
    /*
     *
     */

    function gateway_srs_AdminCustomButtonArray($params)
    {
        $buttons = ['Update EPP Key' => 'Update_EPP_key'];
        try {
            $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');
            $api = Module::api(array_merge(Module::settings(), $params));
            $codes = array_column($api->domain_sync($domain)['statuses'] ?? [], 'code');
            if (in_array('pendingTransfer', $codes, true)) {
                $buttons += ['Approve Transfer' => 'Approve_Transfer', 'Reject Transfer' => 'Reject_Transfer', 'Cancel Transfer' => 'Cancel_Transfer'];
            }
            foreach ([
                ['clientTransferProhibited', 'Unlock Transfers', 'Unlock_Domain', 'Lock Transfers', 'Lock_Domain'],
                ['clientHold', 'Unsuspend', 'Unsuspend_Domain', 'Suspend', 'Suspend_Domain'],
                ['clientUpdateProhibited', 'Unblock Updates', 'Unblock_Domain', 'Block Updates', 'Block_Domain'],
            ] as [$status, $onLabel, $onAction, $offLabel, $offAction]) {
                if (in_array($status, $codes, true)) { $buttons[$onLabel] = $onAction; }
                else { $buttons[$offLabel] = $offAction; }
            }
        } catch (\Throwable $e) {
            // An expired token must not prevent WHMCS from displaying the domain admin page.
        }
        return $buttons;
    }

    # Function update domain status for transfer
    /*
     * Called when API returns "transferPending".
     *
     * Function will update domain status in WHMCS database.
     */

    function gateway_srs_Suspend_Domain($params)
    {
        try {

            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            $api = Module::api($params);
            $result = $api->suspend($domain);

            return $result;

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    function gateway_srs_Unsuspend_Domain($params)
    {
        try {

            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            $api = Module::api($params);
            $result = $api->unsuspend($domain);

            return $result;

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    function gateway_srs_TransferStatusUpdate($domainID, $message)
    {
        if (strpos($message, 'pendingTransfer') !== false) {
            gateway_srs_updateDomainDatabaseStatus($domainID, 'Pending Transfer');
            return ['error' => 'Domain status "Pending Transfer"'];
        }
        return ['error' => $message];
    }

    function gateway_srs_GetTldPricing($params)
    {
        try {
            $rows = Module::api($params)->tld_pricing();
            $grouped = [];
            foreach ($rows as $row) {
                if (!isset($row['tld'], $row['action'], $row['price'], $row['currency']) || !is_numeric($row['price'])) {
                    throw new \RuntimeException('Gateway SRS returned invalid pricing data.');
                }
                $action = $row['action'];
                // Exclude premium/closed/special products from standard retail cost imports.
                if (!in_array($action, ['new', 'renew', 'transfer', 'redeem'], true)
                    || ($row['product_code'] ?? '') !== $row['tld'] . '_' . $action) { continue; }
                $tld = $row['tld'];
                if (isset($grouped[$tld]['currency']) && $grouped[$tld]['currency'] !== $row['currency']) {
                    throw new \RuntimeException('Gateway SRS returned mixed currencies for one extension.');
                }
                $grouped[$tld]['currency'] = $row['currency'];
                $grouped[$tld][$action] = (float) $row['price'];
            }
            $results = new \WHMCS\Results\ResultsList();
            foreach ($grouped as $tld => $prices) {
                if (!isset($prices['new'])) { continue; }
                $item = (new ImportItem())->setExtension($tld)->setMinYears(1)->setMaxYears(1)
                    ->setRegisterPrice($prices['new'])->setRenewPrice($prices['renew'] ?? null)
                    ->setTransferPrice($prices['transfer'] ?? null)->setRedemptionFeePrice($prices['redeem'] ?? null)
                    ->setCurrency($prices['currency'])->setEppRequired(!in_array($tld, ['co.za', 'net.za', 'org.za', 'web.za'], true));
                $results[] = $item;
            }
            return $results;
        } catch (\Throwable $e) { return ['error' => $e->getMessage()]; }
    }

    # Function update domain status in WHMCS database
    /*
     *
     */

    function gateway_srs_updateDomainDatabaseStatus($domainId, $status)
    {
        return Capsule::table('tbldomains')
            ->where('id', $domainId)
            ->update(array(
                    'status' => $status
                )
            );
    }


    function gateway_srs_GetDomainInformation($params)
    {
        try {
            $name = $params['sld'] . '.' . ltrim($params['tld'], '.');
            $info = Module::api($params)->view_domain($name);
            $nameservers = [];
            foreach ($info['hosts'] ?? $info['nameservers'] ?? [] as $host) {
                $nameservers['ns' . (count($nameservers) + 1)] = is_array($host) ? $host['hostname'] : $host;
            }
            $statuses = array_column($info['statuses'] ?? [], 'code');
            $expiry = isset($info['expiry']) ? \WHMCS\Carbon::parse($info['expiry']) : null;
            $status = $expiry && $expiry->isPast() ? 'Expired' : 'Active';
            if (array_intersect(['clientHold', 'serverHold'], $statuses)) { $status = 'Suspended'; }
            return (new \WHMCS\Domain\Registrar\Domain())->setDomain($name)->setNameservers($nameservers)
                ->setExpiryDate($expiry)->setRegistrationStatus($status)
                ->setTransferLock(in_array('clientTransferProhibited', $statuses, true));
        } catch (\Throwable $e) { return ['error' => $e->getMessage()]; }
    }
