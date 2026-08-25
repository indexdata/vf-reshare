<?php
/**
 * ReShare REST API driver
 *
 * PHP version 8
 *
 */
namespace ReShare\ILS\Driver;

use Exception;
use VuFind\Db\Service\UserServiceInterface;
use VuFind\Exception\ILS as ILSException;
use VuFind\I18n\Translator\TranslatorAwareInterface;
use VuFindHttp\HttpServiceAwareInterface as HttpServiceAwareInterface;

use Laminas\Http\Request;
use Laminas\Http\Headers;
use VuFindHttp\HttpService;
use VuFind\ILS\PaginationHelper;

/**
 * ReShare REST API driver
 */
class ReShare extends \VuFind\ILS\Driver\AbstractAPI implements
    HttpServiceAwareInterface, TranslatorAwareInterface
{
    use \VuFindHttp\HttpServiceAwareTrait;
    use \VuFind\I18n\Translator\TranslatorAwareTrait;
    use \VuFind\Log\LoggerAwareTrait {
        logWarning as warning;
        logError as error;
    }

    use \VuFind\Cache\CacheTrait {
        getCacheKey as protected getBaseCacheKey;
    }

    /**
     * Authentication tenant (X-Okapi-Tenant)
     *
     * @var string
     */
    protected $tenant = null;

    /**
     * Authentication token (X-Okapi-Token)
     *
     * @var string
     */
    protected $token = null;

    /**
     * Factory function for constructing the SessionContainer.
     *
     * @var callable
     */
    protected $sessionFactory;

    /**
     * Session cache
     *
     * @var \Laminas\Session\Container
     */
    protected $sessionCache;

    /**
     * Date converter
     *
     * @var \VuFind\Date\Converter
     */
    protected $dateConverter;

    /**
     * User database service
     *
     * @var UserServiceInterface
     */
    protected $userService;

    /**
     * Constructor
     *
     * @param \VuFind\Date\Converter $dateConverter  Date converter object
     * @param callable               $sessionFactory Factory function returning
     * SessionContainer object
     * @param UserServiceInterface   $userService    User database service
     */
    public function __construct(
        \VuFind\Date\Converter $dateConverter,
        $sessionFactory,
        UserServiceInterface $userService
    ) {
        $this->dateConverter = $dateConverter;
        $this->sessionFactory = $sessionFactory;
        $this->userService = $userService;
    }

    /**
     * Set the configuration for the driver.
     *
     * @param array $config Configuration array (usually loaded from a VuFind .ini
     * file whose name corresponds with the driver class name).
     *
     * @throws ILSException if base url excluded
     * @return void
     */
    public function setConfig($config)
    {
        parent::setConfig($config);
        $this->tenant = $this->config['API']['tenant'];
    }

    /**
     * Get the type of FOLIO ID used to match up with VuFind's bib IDs.
     *
     * @return string
     */
    protected function getBibIdType()
    {
        // Normalize string to tolerate minor variations in config file:
        return trim(strtolower($this->config['IDs']['type'] ?? 'instance'));
    }

    /**
     * Function that obscures and logs debug data
     *
     * @param string                $method      Request method
     * (GET/POST/PUT/DELETE/etc.)
     * @param string                $path        Request URL
     * @param array                 $params      Request parameters
     * @param \Laminas\Http\Headers $req_headers Headers object
     *
     * @return void
     */
    protected function debugRequest($method, $path, $params, $req_headers)
    {
        // Only log non-GET requests
        if ($method == 'GET') {
            return;
        }
        // remove passwords
        $logParams = $params;
        if (isset($logParams['password'])) {
            unset($logParams['password']);
        }
        // truncate headers for token obscuring
        $logHeaders = $req_headers->toArray();
        if (isset($logHeaders['X-Okapi-Token'])) {
            $logHeaders['X-Okapi-Token'] = substr(
                $logHeaders['X-Okapi-Token'],
                0,
                30
            ) . '...';
        }

        $this->debug(
            $method . ' request.' .
            ' URL: ' . $path . '.' .
            ' Params: ' . print_r($logParams, true) . '.' .
            ' Headers: ' . print_r($logHeaders, true)
        );
    }

    /**
     * Add instance-specific context to a cache key suffix (to ensure that
     * multiple drivers don't accidentally share values in the cache.
     *
     * @param string $key Cache key suffix
     *
     * @return string
     */
    protected function getCacheKey($key = null)
    {
        // Override the base class formatting with FOLIO-specific details
        // to ensure proper caching in a MultiBackend environment.
        return 'FOLIO-'
            . md5("{$this->tenant}|$key");
    }

    /**
     * (From AbstractAPI) Allow default corrections to all requests
     *
     * Add X-Okapi headers and Content-Type to every request
     *
     * @param \Laminas\Http\Headers $headers the request headers
     * @param object                $params  the parameters object
     *
     * @return array
     */
    public function preRequest(\Laminas\Http\Headers $headers, $params)
    {
        $headers->addHeaderLine('Accept', 'application/json');
        if (!$headers->has('Content-Type')) {
            $headers->addHeaderLine('Content-Type', 'application/json');
        }
        $headers->addHeaderLine('X-Okapi-Tenant', $this->tenant);
        if ($this->token != null) {
            $headers->addHeaderLine('X-Okapi-Token', $this->token);
        }
        return [$headers, $params];
    }

    /**
     * Login and receive a new token
     *
     * @return void
     */
    protected function renewTenantToken()
    {
        $this->token = null;
        $auth = [
            'username' => $this->config['API']['username'],
            'password' => $this->config['API']['password'],
        ];
        $response = $this->makeRequest('POST', '/authn/login', json_encode($auth));
        if ($response->getStatusCode() >= 400) {
            throw new ILSException($response->getBody());
        }
        $this->token = $response->getHeaders()->get('X-Okapi-Token')
            ->getFieldValue();
        $this->sessionCache->folio_token = $this->token;
        $this->debug(
            'Token renewed. Tenant: ' . $auth['username'] .
            ' Token: ' . substr($this->token, 0, 30) . '...'
        );
    }

    /**
     * Check if our token is still valid
     *
     * Method taken from Stripes JS (loginServices.js:validateUser)
     *
     * @return void
     */
    protected function checkTenantToken()
    {
        $response = $this->makeRequest('GET', '/users');
        if ($response->getStatusCode() >= 400) {
            $this->token = null;
            $this->renewTenantToken();
        }
    }

    /**
     * Initialize the driver.
     *
     * Check or renew our auth token
     *
     * @return void
     */
    public function init()
    {
        $factory = $this->sessionFactory;
        $this->sessionCache = $factory($this->tenant);
        if ($this->sessionCache->folio_token ?? false) {
            $this->token = $this->sessionCache->folio_token;
            $this->debug(
                'Token taken from cache: ' . substr($this->token, 0, 30) . '...'
            );
        }
        if ($this->token == null) {
            $this->renewTenantToken();
        } else {
            $this->checkTenantToken();
        }
    }

    /**
     * Given some kind of identifier (instance, holding or item), retrieve the
     * associated instance object from FOLIO.
     *
     * @param string $instanceId Instance ID, if available.
     * @param string $holdingId  Holding ID, if available.
     * @param string $itemId     Item ID, if available.
     *
     * @return object
     */
    protected function getInstanceById(
        $instanceId = null,
        $holdingId = null,
        $itemId = null
    ) {
        if ($instanceId == null) {
            if ($holdingId == null) {
                if ($itemId == null) {
                    throw new \Exception('No IDs provided to getInstanceObject.');
                }
                $response = $this->makeRequest(
                    'GET',
                    '/item-storage/items/' . $itemId
                );
                $item = json_decode($response->getBody());
                $holdingId = $item->holdingsRecordId;
            }
            $response = $this->makeRequest(
                'GET',
                '/holdings-storage/holdings/' . $holdingId
            );
            $holding = json_decode($response->getBody());
            $instanceId = $holding->instanceId;
        }
        $response = $this->makeRequest(
            'GET',
            '/inventory/instances/' . $instanceId
        );
        return json_decode($response->getBody());
    }

    /**
     * Given an instance object or identifer, or a holding or item identifier,
     * determine an appropriate value to use as VuFind's bibliographic ID.
     *
     * @param string $instanceOrInstanceId Instance object or ID (will be looked up
     * using holding or item ID if not provided)
     * @param string $holdingId            Holding-level id (optional)
     * @param string $itemId               Item-level id (optional)
     *
     * @return string Appropriate bib id retrieved from FOLIO identifiers
     */
    protected function getBibId(
        $instanceOrInstanceId = null,
        $holdingId = null,
        $itemId = null
    ) {
        $idType = $this->getBibIdType();

        // Special case: if we're using instance IDs and we already have one,
        // short-circuit the lookup process:
        if ($idType === 'instance' && is_string($instanceOrInstanceId)) {
            return $instanceOrInstanceId;
        }

        $instance = is_object($instanceOrInstanceId)
            ? $instanceOrInstanceId
            : $this->getInstanceById($instanceOrInstanceId, $holdingId, $itemId);

        switch ($idType) {
        case 'hrid':
            return $instance->hrid;
        case 'instance':
            return $instance->id;
        }

        throw new \Exception('Unsupported ID type: ' . $idType);
    }

    /**
     * Escape a string for use in a CQL query.
     *
     * @param string $in Input string
     *
     * @return string
     */
    protected function escapeCql($in)
    {
        return str_replace('"', '\"', str_replace('&', '%26', $in));
    }

    /**
     * Take an ISIL symbol and return a nice name based on a map from config.
     *
     * @param string $location ISIL Symbol location
     *
     * @return string human readable location name
     */
    protected function getNiceLocation($location)
    {
        $memberMap = $this->config['members'];
        if ( isset($memberMap[$location]) ) {
            return $memberMap[$location];
        } else {
            return $location;
	}
    }

    /**
     * Retrieve FOLIO instance using VuFind's chosen bibliographic identifier.
     *
     * @param string $bibId Bib-level id
     *
     * @return array
     */
    protected function getInstanceByBibId($bibId)
    {
	$tenant = $this->config['API']['tenant'];
        $client = $this->httpService->createClient(
            $this->config['API']['base_url'] . '/_/invoke/tenant/' . $tenant . '/reservoir/oai',
            'GET',
            (int)$this->config['API']['http_timeout']
        );
        $client->setParameterGet([
            'verb'  => 'GetRecord',
            'metadataPrefix' => 'marcxml',
            'identifier'   => $bibId,
        ]);
	$response = $client->send();
        $cluster = $response->getBody();
        if (empty($cluster)) {
            throw new ILSException("Item Not Found");
        }
        return $cluster;
    }

    /**
     * get holdings from a reservoir cluster
     *
     * @param object $cluster clusterxml
     *
     * @return array
     */
    protected function getHoldingsByCluster($cluster)
    {
	// we're assuming it's only possible to get one cluster here
	$holdingsData = [];
	$clusterxml = simplexml_load_string($cluster);
	$recordData = $clusterxml->GetRecord->record->metadata;
	$recordData->registerXPathNamespace("marc", "http://www.loc.gov/MARC21/slim");
	$item999fields = $recordData->xpath('//marc:record/marc:datafield[@tag="999" and @ind1="1" and @ind2="1"]');
	if (is_null($item999fields)) {
	    return $holdingsData;
	}
	foreach ( $item999fields as $item ) {
	    foreach ($item as $subfield) {
                switch((string) $subfield['code']) { 
                case 's': // location
                    $location = (string)$subfield ?? '';
                case 'c': // call number
                    $callnumber = (string)$subfield ?? '';
                case 'p': // lendablility
                    $availability = ((string)$subfield == "LOANABLE") ?? false;
		    $status = (string)$subfield ?? 'Unknown';
                case 'b': // local id
                    $item_id = (string)$subfield ?? '';
                }
	    }
	$holdingsData[] = [
	    'availability' => $availability,
	    'is_holdable' => $availability,
            'callnumber' => $callnumber,
            'item_id' => $item_id,
            'location' => $this->getNiceLocation($location),
            'status' => $status,
	];
	}
        return $holdingsData;
    }

    /**
     * determines if something can be lent
     *
     * @param string $bibId bib id
     *
     * @return bool
     */
    public function isRequestable($bibId)
    {
	$availability = [];
	$cluster = $this->getInstanceByBibId($bibId);
	try {
            $clusterxml = simplexml_load_string($cluster);
	} finally {
	    // Default to requestable when availability cannot be determined.
	    $availability = ["LOANABLE"];
	    return $availability;
        }
        $recordData = $clusterxml->GetRecord->record->metadata;
        $recordData->registerXPathNamespace("marc", "http://www.loc.gov/MARC21/slim");
        $item999fields = $recordData->xpath('//marc:record/marc:datafield[@tag="999" and @ind1="1" and @ind2="1"]');
        foreach ( $item999fields as $item ) {
	    foreach ($item as $subfield) {
		if ($subfield['code'] == 'p') {
                    $availability[] = $subfield;
		}
	    }
	}

	return $availability;

    }

    /**
     * Get raw object of item from inventory/items/
     *
     * @param string $itemId Item-level id
     *
     * @return array
     */
    public function getStatus($itemId)
    {
        return $this->getHolding($itemId);
    }

    /**
     * This method calls getStatus for an array of records or implement a bulk method
     *
     * @param array $idList Item-level ids
     *
     * @return array values from getStatus
     */
    public function getStatuses($idList)
    {
        $status = [];
        foreach ($idList as $id) {
            $status[] = $this->getStatus($id);
        }
        return $status;
    }

    /**
     * Retrieves renew, hold and cancel settings from the driver ini file.
     *
     * @param string $function The name of the feature to be checked
     * @param array  $params   Optional feature-specific parameters (array)
     *
     * @return array An array with key-value pairs.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getConfig($function, $params = null)
    {
        if ('getMyTransactions' === $function) {
            $functionConfig = [
                'sort' => [
                    'dateCreated;desc' => 'Date Requested - Descending',
                    'dateCreated;asc' => 'Date Requested - Ascending',
                    'title;asc' => 'Title',
                ],
                'default_sort' => 'dateCreated;desc',
            ];
        } elseif ('getMyTransactionHistory' === $function) {
            $functionConfig = [
                'sort' => [
                    'dateCreated;desc' => 'Date Requested - Descending',
                    'dateCreated;asc' => 'Date Requested - Ascending',
                    'title;asc' => 'Title',
                ],
                'default_sort' => 'dateCreated;desc',
            ];
        } elseif ('getMyHolds' === $function) {
            $functionConfig = [
                'sort' => [
                    'dateCreated;desc' => 'Date Requested - Descending',
                    'dateCreated;asc' => 'Date Requested - Ascending',
                    'title;asc' => 'Title',
                ],
                'default_sort' => 'dateCreated;desc',
            ];
        } else {
            $functionConfig = $this->config[$function] ?? false;
        }
        return $functionConfig;
    }

    /**
     * Get a Solr record.
     *
     * @param string $id ID of record to retrieve
     *
     * @return \VuFind\RecordDriver\AbstractBase
     */
    protected function getSolrRecord($id)
    {
        return $this->recordLoader->load(
            $id,
            DEFAULT_SEARCH_BACKEND,
            true    // tolerate missing records
        );
    }
    /**
     * This is responsible for retrieving the status or holdings information of a
     * certain record from a Marc Record.
     *
     * @param object $recordDriver  A RecordDriver Object
     * @param string $configSection Section of driver config containing data
     * on how to extract details from MARC.
     *
     * @return array An Array of Holdings Information
     */

    protected function getFormattedMarcDetails($recordDriver, $configSection)
    {
        $marcStatus = $this->config[$configSection] ?? false;
        if ($marcStatus) {
            $field = $marcStatus['marcField'];
            unset($marcStatus['marcField']);
            $result = $recordDriver->tryMethod(
                'getFormattedMarcDetails',
                [$field, $marcStatus]
            );
            // If the details coming back from the record driver include the
            // ID prefix, strip it off!
            $idPrefix = $this->getIdPrefix();
            if (isset($result[0]['id']) && strlen($idPrefix)
                && $idPrefix === substr($result[0]['id'], 0, strlen($idPrefix))
            ) {
                $result[0]['id'] = substr($result[0]['id'], strlen($idPrefix));
            }
            return empty($result) ? [] : $result;
        }
        return [];
    }

    /**
     * This method queries the ILS for holding information.
     *
     * @param string $bibId   Bib-level id
     * @param array  $patron  Patron login information from $this->patronLogin
     * @param array  $options Extra options (not currently used)
     *
     * @return array An array of associative holding arrays
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getHolding($bibId, ?array $patron = null, array $options = [])
    {
        $nonRequestablePrefixes = (array)(
            $this->config['Requests']['non_requestable_id_prefixes'] ?? []
        );
        foreach ($nonRequestablePrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($bibId, $prefix)) {
                return [[
                    'id' => $bibId,
                    'availability' => 1,
                    'status' => 'Available',
                    'location' => '',
                    'reserve' => 'N',
                    'number' => 1,
                ]];
            }
        }

        $cluster = $this->getInstanceByBibId($bibId);
        $items = [];

	$holdingsData = $this->getHoldingsByCluster($cluster);
        foreach ($holdingsData as $holding) {
            $items[] = $holding + [
                'id' => $bibId,
		'number' => count($items) + 1,
                'holding_id' => count($items) + 1,
                'barcode' => '',
                'reserve' => '',
                'addLink' => true
            ];

        }
        return $items;
    }

    /**
     * Support method for patronLogin(): authenticate the patron with an Okapi
     * login attempt. Returns a CQL query for retrieving more information about
     * the authenticated user.
     *
     * @param string $username The patron username
     * @param string $password The patron password
     *
     * @return string
     */
    protected function patronLoginWithOkapi($username, $password)
    {
        $tenant = $this->config['API']['tenant'];
        $credentials = compact('tenant', 'username', 'password');
        $response = $this->makeRequest(
            'POST',
            '/authn/login',
            json_encode($credentials)
        );
        $debugMsg = 'User logged in. User: ' . $username . '.';
        // We've authenticated the user with Okapi, but we only have their
        // username; set up a query to retrieve full info below.
        $query = 'username == ' . $username;
        // Replace admin with user as tenant if configured to do so:
        if ($this->config['User']['use_user_token'] ?? false) {
            $this->token = $response->getHeaders()->get('X-Okapi-Token')
                ->getFieldValue();
            $debugMsg .= ' Token: ' . substr($this->token, 0, 30) . '...';
        }
        $this->debug($debugMsg);
        return $query;
    }

    /**
     * Support method for patronLogin(): authenticate the patron with a CQL looup.
     * Returns the CQL query for retrieving more information about the user.
     *
     * @param string $username The patron username
     * @param string $password The patron password
     *
     * @return string
     */
    protected function getUserWithCql($username, $password)
    {
        $usernameField = $this->config['User']['username_field'] ?? 'username';
        $passwordField = $this->config['User']['password_field'] ?? false;
        $cql = $this->config['User']['cql']
            ?? '%%username_field%% == "%%username%%"'
            . ($passwordField ? ' and %%password_field%% == "%%password%%"' : '');
        $placeholders = [
            '%%username_field%%',
            '%%password_field%%',
            '%%username%%',
            '%%password%%',
        ];
        $values = [
            $usernameField,
            $passwordField,
            $this->escapeCql($username),
            $this->escapeCql($password),
        ];
        return str_replace($placeholders, $values, $cql);
    }

    /**
     * Given a CQL query, fetch a single user; if we get an unexpected count, treat
     * that as an unsuccessful login by returning null.
     *
     * @param string $query CQL query
     *
     * @return object
     */
    protected function fetchUserWithCql($query)
    {
        $response = $this->makeRequest('GET', '/users', compact('query'));
        $json = json_decode($response->getBody());
        return count($json->users) === 1 ? $json->users[0] : null;
    }

    /**
     * Helper function to retrieve paged results from FOLIO API
     *
     * @param string $responseKey Key containing values to collect in response
     * @param string $interface   FOLIO api interface to call
     * @param array  $query       CQL query
     *
     * @return array
     */
    protected function getPagedResults($responseKey, $interface, $query = [])
    {
        $count = 0;
        $limit = 1000;
        $offset = 0;

        do {
            $combinedQuery = array_merge($query, compact('offset', 'limit'));
            $response = $this->makeRequest(
                'GET',
                $interface,
                $combinedQuery
            );
            $json = json_decode($response->getBody());
            if (!$response->isSuccess() || !$json) {
                $msg = $json->errors[0]->message ?? json_last_error_msg();
                throw new ILSException($msg);
            }
            $total = $json->totalRecords ?? 0;
            $previousCount = $count;
            foreach ($json->$responseKey ?? [] as $item) {
                $count++;
                if ($count % $limit == 0) {
                    $offset += $limit;
                }
                yield $item ?? '';
            }
            // Continue until the count reaches the total records
            // found, if count does not increase, something has gone
            // wrong. Stop so we don't loop forever.
        } while ($count < $total && $previousCount != $count);
    }

    /**
     * Patron Login
     *
     * This is responsible for authenticating a patron against the catalog.
     *
     * @param string $username The patron username
     * @param string $password The patron password
     *
     * @return mixed Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function patronLogin($username, $password)
    {
        $profile = null;
        $doOkapiLogin = false;
        $usernameField = 'username';

        if ($doOkapiLogin) {
            try {
                $query = $this->patronLoginWithOkapi(
                    $profile->username ?? $username,
                    $password
                );
            } catch (Exception $e) {
                return null;
            }
            if (!isset($profile)) {
                $profile = $this->fetchUserWithCql($query);
                if ($profile === null) {
                    return null;
                }
            }
        }

        return [
            'id' => $username,
            'username' => $username,
            'cat_username' => $username,
            'cat_password' => $password,
            'firstname' => $profile->personal->firstName ?? null,
            'lastname' => $profile->personal->lastName ?? null,
            'email' => $profile->personal->email ?? null,
        ];
    }

    /**
     * This method queries the ILS for a patron's current profile information
     *
     * @param array $patron Patron login information from $this->patronLogin
     *
     * @return array Profile data in associative array
     */
    public function getMyProfile($patron)
    {
        $query = ['query' => 'id == "' . $patron['id'] . '"'];
        $response = $this->makeRequest('GET', '/users', $query);
        $users = json_decode($response->getBody());
        $profile = $users->users[0];
        $expiration = isset($profile->expirationDate)
            ? $this->dateConverter->convertToDisplayDate(
                "Y-m-d H:i",
                $profile->expirationDate
            )
            : null;
        return [
            'id' => $profile->id,
            'firstname' => $profile->personal->firstName ?? null,
            'lastname' => $profile->personal->lastName ?? null,
            'address1' => $profile->personal->addresses[0]->addressLine1 ?? null,
            'city' => $profile->personal->addresses[0]->city ?? null,
            'country' => $profile->personal->addresses[0]->countryId ?? null,
            'zip' => $profile->personal->addresses[0]->postalCode ?? null,
            'phone' => $profile->personal->phone ?? null,
            'mobile_phone' => $profile->personal->mobilePhone ?? null,
            'expiration_date' => $expiration,
        ];
    }

    /**
     * This method queries the ILS for a patron's current checked out items
     *
     * Input: Patron array returned by patronLogin method
     * Output: Returns an array of associative arrays.
     *         Each associative array contains these keys:
     *         duedate - The item's due date (a string).
     *         dueTime - The item's due time (a string, optional).
     *         dueStatus - A special status – may be 'due' (for items due very soon)
     *                     or 'overdue' (for overdue items). (optional).
     *         id - The bibliographic ID of the checked out item.
     *         source - The search backend from which the record may be retrieved
     *                  (optional - defaults to Solr). Introduced in VuFind 2.4.
     *         barcode - The barcode of the item (optional).
     *         renew - The number of times the item has been renewed (optional).
     *         renewLimit - The maximum number of renewals allowed
     *                      (optional - introduced in VuFind 2.3).
     *         request - The number of pending requests for the item (optional).
     *         volume – The volume number of the item (optional).
     *         publication_year – The publication year of the item (optional).
     *         renewable – Whether or not an item is renewable
     *                     (required for renewals).
     *         message – A message regarding the item (optional).
     *         title - The title of the item (optional – only used if the record
     *                                        cannot be found in VuFind's index).
     *         item_id - this is used to match up renew responses and must match
     *                   the item_id in the renew response.
     *         institution_name - Display name of the institution that owns the item.
     *         isbn - An ISBN for use in cover image loading
     *                (optional – introduced in release 2.3)
     *         issn - An ISSN for use in cover image loading
     *                (optional – introduced in release 2.3)
     *         oclc - An OCLC number for use in cover image loading
     *                (optional – introduced in release 2.3)
     *         upc - A UPC for use in cover image loading
     *               (optional – introduced in release 2.3)
     *         borrowingLocation - A string describing the location where the item
     *                         was checked out (optional – introduced in release 2.4)
     *
     * @param array $patron Patron login information from $this->patronLogin
     *
     * @return array Transactions associative arrays
     */
    private function translateStatus($statusCode) {
            $states = $this->config['states'];
            try {
                $statusName = $states[$statusCode];
            } catch (Exception $e) {
                $statusName = $statusCode;
            }
            return $statusName;
    }

    private function makePatronApiRequest($method = "GET", $path = "/", $params = [],
        $headers = [] 
    ) {
        $client = $this->httpService->createClient(
            rtrim($this->config['API']['patron_api'], '/') . $path,
            $method,
            (int)$this->config['API']['http_timeout']
        );

        $req_headers = $client->getRequest()->getHeaders();
        $req_headers->addHeaders($headers);

        if ($method == 'GET') {
            $client->setParameterGet($params);
        } else {
            if (is_string($params)) {
                $client->getRequest()->setContent($params);
            } else {
                $client->setParameterPost($params);
            }
        }
        $response = $client->send();

        return $response;
    }


    /* get max requests from settings */
    public function getMaxRequests($patron)
    {
        $institution = $this->getPatronInstitution($patron);

        $myURL = '/' . $institution . '/settings/requests/max_requests';
        $settings = [];
        $settingsResponse = $this->makePatronApiRequest(
                'GET', $myURL, [], ["x-remote-user" => $patron['username']]
        );
        if($settingsResponse->isSuccess()) {
            $settingsJSON = json_decode($settingsResponse->getBody());
            if(isset($settingsJSON[0]->value)) {
	        $max_requests = $settingsJSON[0]->value;
	    } else {
		$max_requests = 0;
	    }
	} else {
	    $max_requests = 0; 
	}
        return $max_requests;
    }


    /**
     * Get the patron's ReShare institution from their VuFind user account.
     *
     * @param array $patron Patron login information
     *
     * @return string
     */
    protected function getPatronInstitution(array $patron): string
    {
        $username = $patron['username'] ?? '';
        if ($username === '') {
            return '';
        }
        return $this->userService->getUserByUsername($username)?->getCollege() ?? '';
    }

    public function cancelHolds($cancelDetails)
    {
        $details = $cancelDetails['details'];
        $patron = $cancelDetails['patron'];
        $institution = $this->getPatronInstitution($patron);
        $cancelReason = $this->config['Requests']['cancellation_reason']
            ?? 'patron_requested';
        $payload = json_encode(['reason' => $cancelReason]);
        $count = 0;
        $cancelResult = ['items' => []];

        foreach ($details as $requestId) {
            $myURL = '/' . $institution . '/patronrequests/' . $requestId . '/cancel';
            try {
                $cancelResponse = $this->makePatronApiRequest(
                    'POST', $myURL, $payload, ["x-remote-user" => $patron['username']]
                );
                $success = $cancelResponse->getStatusCode() === 200;
            } catch (\Exception $e) {
            }
            $count += $success ? 1 : 0;
            $cancelResult['items'][$requestId] = [
                'success' => $success,
                'status' => $success ? 'hold_cancel_success' : 'hold_cancel_fail',
            ];
        }
        $cancelResult['count'] = $count;
        return $cancelResult;
    }

    /**
     * Return the request ID when the patron is allowed to cancel a request.
     *
     * @param object $trans ReShare transaction
     *
     * @return string
     */
    private function canCancel($trans)
    {
        $cancelDetails = '';
	if(count($trans->validActions) === 0) {
	    return '';
	} elseif(is_string($trans->validActions[0])) {
	    if (in_array("requesterCancel", $trans->validActions) || in_array("nonreturnableRequesterCancel", $trans->validActions)) {
                $cancelDetails = $trans->id;
            }
	} elseif(is_object($trans->validActions[0])) {
	    foreach($trans->validActions as $action) {
	        if($action->actionCode === "requesterCancel") {
                    $cancelDetails = $trans->id;
	        }
	    }
	}

	return $cancelDetails;


	
    }

    public function getReShareOpenTransactions($patron, $params = [])
    {
        $institution = $this->getPatronInstitution($patron);
        $pageSize = (int)$this->config['Requests']['page_size'];

        $myURL = '/' . $institution . '/patronrequests?fullRecord=true&perPage=' . $pageSize . '&filters=isRequester%3D%3Dtrue&filters=state.terminal%3D%3Dfalse';
        $transactions = [];
        $transactionResponse = $this->makePatronApiRequest(
                'GET', $myURL, [], ["x-remote-user" => $patron['username']]
        );
	return $transactionResponse;
    }

    public function getMyHolds($patron, $params = [])
    {

        $transactions = [];

        $transactionResponse = $this->getReShareOpenTransactions($patron, $params);	
	
        if($transactionResponse->isSuccess()) {
            $transactionJSON = json_decode($transactionResponse->getBody());
            foreach ($transactionJSON as $trans){
                $dateCreated = date_create($trans->dateCreated);
                $dateDue = $dateCreated;
                $cancel_details = $this->canCancel($trans);
                $transactions[] = [
                    'id' => $trans->bibliographicRecordId ?? '',
                    'hrid' => $trans->hrid ?? '',
                    'item_id' => $trans->bibliographicRecordId ?? '',
		    'req_num' => $trans->id,
		    'cancel_details' => $cancel_details,
                    'create' => date_format($dateDue, "j M Y"),
                    'status' => $this->translateStatus($trans->state->code) ?? 'Unknown',
                    'stage' => $trans->state->stage,
                    'barcode' => 'na',
                    'renewable' => false,
                    'cdl_url' => $trans->pickupURL ?? '',
                    'title' => $trans->title,
                ];
            }
        }
        return $transactions;
    }

    /**
     * Count requests tagged as active for the patron.
     *
     * @param array $patron Patron login information
     *
     * @return int
     */
    public function countRequests($patron)
    {
        $transactionResponse = $this->getReShareOpenTransactions($patron);
	$request_count = 0;
        if($transactionResponse->isSuccess()) {
            $transactionJSON = json_decode($transactionResponse->getBody());
            foreach ($transactionJSON as $trans){
	        if(!$trans->state->tags) {
	        } else {
                    foreach($trans->state->tags as $tag) {
                        if($tag->value === "ACTIVE_PATRON") {
                            $request_count +=1;
                        }
                    }
	        }
	    }
	}
	return $request_count;
    }

    public function getMyTransactions($patron, $params = [])
    {
        $institution = $this->getPatronInstitution($patron);
        $pageSize = (int)$this->config['Requests']['page_size'];

        $myURL = '/' . $institution . '/patronrequests?perPage=' . $pageSize . '&filters=isRequester%3D%3Dtrue&filters=state.terminal%3D%3Dfalse&sort=' . $params['sort'];
        $transactions = [];
        $transactionResponse = $this->makePatronApiRequest(
                'GET', $myURL, [], ["x-remote-user" => $patron['username']]
        );
        if($transactionResponse->isSuccess()) {
            $transactionJSON = json_decode($transactionResponse->getBody());
            foreach ($transactionJSON as $trans){
		$utc_timezone = new \DateTimeZone("UTC");
                $dateCreated = date_create($trans->dateCreated, $utc_timezone);
		$dateCreated->setTimezone(
                    new \DateTimeZone(date_default_timezone_get())
                );
                $dateDue = $dateCreated;


                $transactions[] = [
                    'duedate' => date_format($dateDue, "j M Y"),
                    'dueTime' => date_format($dateDue, "g:i:s a"),
                    'status' => $this->translateStatus($trans->state->code) ?? 'Unknown',
                    'id' => $trans->bibliographicRecordId ?? '',
		    'hrid' => $trans->hrid ?? '',
                    'item_id' => $trans->bibliographicRecordId ?? '',
                    'stage' => $trans->state->stage,
                    'barcode' => 'na',
                    'renewable' => false,
                    'cdl_url' => $trans->pickupURL ?? '',
                    'title' => $trans->title,
                ];
            }
        }
        return $transactions;
    }

    /**
     * Get Pick Up Locations
     *
     * This is responsible get a list of valid locations for holds / recall
     * retrieval
     *
     * @param array $patron   Patron information returned by $this->patronLogin
     * @param array $holdInfo Optional array, only passed in when getting a list
     * in the context of placing or editing a hold.  When placing a hold, it contains
     * most of the same values passed to placeHold, minus the patron data.  When
     * editing a hold it contains all the hold information returned by getMyHolds.
     * May be used to limit the pickup options or may be ignored.  The driver must
     * not add new options to the return array based on this data or other areas of
     * VuFind may behave incorrectly.
     *
     * @return array An array of associative arrays with locationID and
     * locationDisplay keys
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getPickupLocations($patron, $holdInfo = null)
    {
        return [];
    }

    /**
     * Get Departments
     *
     * Obtain a list of departments for use in limiting the reserves list.
     *
     * @return array An associative array with key = dept. ID, value = dept. name.
     */
    public function getDepartments()
    {
        return [];
    }

    /**
     * Get Instructors
     *
     * Obtain a list of instructors for use in limiting the reserves list.
     *
     * @return array An associative array with key = ID, value = name.
     */
    public function getInstructors()
    {
        return [];
    }

    /**
     * Get Courses
     *
     * Obtain a list of courses for use in limiting the reserves list.
     *
     * @return array An associative array with key = ID, value = name.
     */
    public function getCourses()
    {
        return [];
    }

    /**
     * Find Reserves
     *
     * Obtain information on course reserves.
     *
     * @param string $course ID from getCourses (empty string to match all)
     * @param string $inst   ID from getInstructors (empty string to match all)
     * @param string $dept   ID from getDepartments (empty string to match all)
     *
     * @return mixed An array of associative arrays representing reserve items.
     */
    public function findReserves($course, $inst, $dept)
    {
        return [];
    }

    // @codingStandardsIgnoreStart

    /**
     * Check for request blocks.
     *
     * @param array $patron The patron array with username and password
     *
     * @return array|bool Block messages or false if there are no blocks
     */
    public function getRequestBlocks($patron)
    {
        return false;
    }

    /**
     * Get recently received serial issues.
     *
     * @param string $bibID Bibliographic record ID
     *
     * @return array
     */
    public function getPurchaseHistory($bibID)
    {
        return [];
    }

    /**
     * Get the patron's current fines.
     *
     * @param array $patron Patron login information
     *
     * @return array
     */
    public function getMyFines($patron)
    {
        return [];
    }

    /**
     * Get funds available for limiting new-item searches.
     *
     * @return array
     */
    public function getFunds()
    {
        return [];
    }

    /**
     * Get the patron's completed ReShare transactions.
     *
     * @param array $patron Patron login information
     * @param array $params Sort and pagination options
     *
     * @return array
     */
    public function getMyTransactionHistory($patron, $params = [])
    {
        $institution = $this->getPatronInstitution($patron);
        $pageSize = (int)$this->config['Requests']['page_size'];

        $myURL = '/' . $institution . '/patronrequests?perPage=' . $pageSize . '&filters=isRequester%3D%3Dtrue&filters=state.stage==COMPLETED&sort=' .  $params['sort'];

        $transactions = [];
        $transactionResponse = $this->makePatronApiRequest(
                'GET', $myURL, [], ["x-remote-user" => $patron['username']]
        );
        if($transactionResponse->isSuccess()) {
            $transactionJSON = json_decode($transactionResponse->getBody());
            foreach ($transactionJSON as $trans){

                $utc_timezone = new \DateTimeZone("UTC");
                $dateCreated = date_create($trans->dateCreated, $utc_timezone);
                $dateCreated->setTimezone(
                    new \DateTimeZone(date_default_timezone_get())
                );
                $dateDue = $dateCreated;

                $transactions[] = [
                    'duedate' => date_format($dateDue, "j M Y"),
                    'dueTime' => date_format($dateDue, "g:i:s a"),
                    'id' => $trans->bibliographicRecordId ?? '',
		    'hrid' => $trans->hrid ?? '',
                    'item_id' => $trans->bibliographicRecordId ?? '',
                    'stage' => $trans->state->stage,
                    'status' => $this->translateStatus($trans->state->code) ?? 'Unknown',
                    'barcode' => 'na',
                    'renewable' => false,
                    'cdl_url' => $trans->pickupURL ?? '',
                    'title' => $trans->title ?? 'Title not available',
                ];
            }
        }
        $formatted = ['count' => count($transactions), 'transactions' => $transactions];
        return $formatted;
    }

    /**
     * Get new items.
     *
     * @param int         $page    Page number
     * @param int         $limit   Results per page
     * @param int         $daysOld Maximum item age in days
     * @param string|null $fundID  Optional fund identifier
     *
     * @return array
     */
    public function getNewItems($page, $limit, $daysOld, $fundID = null)
    {
        return [];
    }

    public function ilsAwareTest($myBibId)
    {
        return "From the ILS driver: " . $myBibId;
    }

    // @codingStandardsIgnoreEnd
}
