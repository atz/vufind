<?php
/**
 * EDS API Backend
 *
 * PHP version 5
 *
 * Copyright (C) EBSCO Industries 2013
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  Search
 * @author   Michelle Milton <mmilton@epnet.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org
 */

namespace VuFindSearch\Backend\EDS;

use EBSCO\EdsApi\Zend2 as ApiClient;
use EBSCO\EdsApi\EdsApi_REST_Base;
use EBSCO\EdsApi\EbscoEdsApiException;
use EBSCO\EdsApi\SearchRequestModel as SearchRequestModel;

use VuFindSearch\Query\AbstractQuery;

use VuFindSearch\ParamBag;

use VuFindSearch\Response\RecordCollectionInterface;
use VuFindSearch\Response\RecordCollectionFactoryInterface as
 RecordCollectionFactoryInterface;

use VuFindSearch\Backend\AbstractBackend as AbstractBackend;
use VuFindSearch\Backend\Exception\BackendException;

use Zend\Log\LoggerInterface;
use VuFindSearch\Backend\EDS\Response\RecordCollection;
use VuFindSearch\Backend\EDS\Response\RecordCollectionFactory;

use Zend\ServiceManager\ServiceLocatorInterface;

/**
 *  EDS API Backend
 *
 * @category VuFind2
 * @package  Search
 * @author   Michelle Milton <mmilton@epnet.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org
 */
class Backend extends AbstractBackend
{
    /**
     * Client user to make the actually requests to the EdsApi
     * @var ApiClient
     */
    protected $client;

    /**
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * User name for EBSCO EDS API account if using UID Authentication
     * @var string
     */
    protected $userName = null;

    /**
     * Password for EBSCO EDS API account if using UID Authentication
     * @var string
     */
    protected $password = null;

    /**
     * Profile for EBSCO EDS API account
     * @var string
     */
    protected $profile = null;

    /**
     * Whether or not to use IP Authentication for communication with the EDS API
     * @var boolean
     */
    protected $ipAuth = false;

    /**
     * Organization EDS API requests are being made for
     * @var string
     */
    protected $orgid = null;

    /**
     * Superior service manager.
     *
     * @var ServiceLocatorInterface
     */
    protected $serviceLocator;

    /**
     * Constructor.
     *
     * @param ApiClient                        $client  EdsApi client to use
     * @param RecordCollectionFactoryInterface $factory Record collection factory
     *
     * @return void
     */
    public function __construct(ApiClient $client,
            RecordCollectionFactoryInterface $factory, array $account) {
        $this->setRecordCollectionFactory($factory);
        $this->client = $client;
        $this->identifier = null;
        $this->userName = isset($account['username']) ? $account['username'] : null;
        $this->password = isset($account['password']) ? $account['password'] : null;
        $this->ipAuth   = isset($account['ipauth'  ]) ? $account['ipauth'  ] : null;
        $this->profile  = isset($account['profile' ]) ? $account['profile' ] : null;
        $this->orgId    = isset($account['orgid'   ]) ? $account['orgid'   ] : null;
    }

    /**
     * Sets the superior service locator
     *
     * @param ServiceLocatorInterface $serviceLocator Superior service locator
     */
    public function setServiceLocator($serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }

    /**
     * gets the superior service locator
     *
     * @return ServiceLocatorInterface Superior service locator
     */
    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }

     /**
     * Perform a search and return record collection.
     *
     * @param AbstractQuery $query  Search query
     * @param integer       $offset Search offset
     * @param integer       $limit  Search limit
     * @param ParamBag      $params Search backend parameters
     *
     *@return \VuFindSearch\Response\RecordCollectionInterface
     **/
    public function search(AbstractQuery $query, $offset, $limit,
        ParamBag $params = null) {
        //create query parameters from VuFind data
        $queryString = !empty($query) ? $query->getAllTerms() : '';
        $paramsString = implode('&', $params->request());
        $this->debugPrint("Query: $queryString, Limit: $limit, Offset: $offset, Params: $paramsString ");

        $authenticationToken = $this->getAuthenticationToken();
        $sessionToken = $this->getSessionToken();
        $this->debugPrint("Authentication Token: $authenticationToken, SessionToken: $sessionToken");

        $baseParams = $this->getQueryBuilder()->build($query);
        $paramsString = implode('&', $baseParams->request());
        $this->debugPrint("BaseParams: $paramsString ");
        if (null !== $params) {
            $baseParams->mergeWith($params);
        }
        $baseParams->set('resultsPerPage', $limit);
        $page = $limit > 0 ? floor($offset / $limit) + 1 : 1;
        $baseParams->set('pageNumber', $page);

        $searchModel = $this->paramBagToEBSCOSearchModel($baseParams);
        $qs = $searchModel->convertToQueryString();
        $this->debugPrint("Search Model query string: $qs");
        try {
            $response = $this->client->search($searchModel, $authenticationToken, $sessionToken);
        } catch (\EbscoEdsApiException $e) {
            $response = array();
            try {  // if the auth token or session token was invalid, replace it and retry once more
                if ( $e->getApiErrorCode() == 104 ) {
                    $authenticationToken = $this->getAuthenticationToken(true);
                    $response = $this->client->search($searchModel, $authenticationToken, $sessionToken);
                } else if (108 == $e->getApiErrorCode() || 109 == $e->getApiErrorCode() ) {
                    $sessionToken = $this->getSessionToken(true);
                    $response = $this->client->search($searchModel, $authenticationToken, $sessionToken);
                } else {
                    $this->debugPrint("EbscoEdsApiException suppressed: " . $e->getMessage());
                }
            } catch (Exception $e) {
                throw new BackendException($e->getMessage(), $e->getCode(), $e);
            }
        } catch (Exception $e) {
            $this->debugPrint("Exception found: " . $e->getMessage());
            throw new BackendException($e->getMessage(), $e->getCode(), $e);
        }
        $collection = $this->createRecordCollection($response);
        $this->injectSourceIdentifier($collection);
        return $collection;
    }

    /**
     * Retrieve a single document.
     *
     * @param string   $id     Document identifier
     * @param ParamBag $params Search backend parameters
     *
     * @return \VuFindSearch\Response\RecordCollectionInterface
     */
    public function retrieve($id, ParamBag $params = null)
    {
        try {
            $authenticationToken = $this->getAuthenticationToken();
            //check to see if the profile is overriden
            $overrideProfile =  $params->get('profile');
            if(isset($overrideProfile))
                $this->profile = $overrideProfile;
            $sessionToken = $this->getSessionToken();

            //not sure how $an and dbid will be coming through. could have the id be the
            //query string to identify the record retrieval
            //or maybe $id = [dbid],[an]
            $seperator = ',';
            $pos = strpos($id, $seperator);
            if ($pos === false){
                throw new BackendException('Retrieval id is not in the correct format.');
            }
            $dbId = substr($id, 0, $pos);
            $an   = substr($id, $pos+1);
            $highlightTerms = '';//$params['highlight'];
            $response = $this->client->retrieve($an, $dbId, $highlightTerms,$authenticationToken, $sessionToken);
        } catch (\EbscoEdsApiException $e) {
            if( $e->getApiErrorCode == 104 )
            {
                try {
                    $authenticationToken = $this->getAuthenticationToken(true);
                    $response = $this->client->retrieve($an, $dbId, $highlightTerms,$authenticationToken, $sessionToken);
                } catch(Exception $e) {
                    throw new BackendException(
                        $e->getMessage(),
                        $e->getCode(),
                        $e);
                }
            }
            else
                throw $e;
        }
        $collection = $this->createRecordCollection(array('Records'=> $response));
        $this->injectSourceIdentifier($collection);
        return $collection;
    }

    /**
     * Convert a ParamBag to a EdsApi Search request object.
     *
     * @param ParamBag $params ParamBag to convert
     *
     * @return SearchRequestModel
     */
    protected function paramBagToEBSCOSearchModel(ParamBag $params)
    {
        $params= $params->getArrayCopy();
        // Convert the options:
        //$paramContents = explode('&', $params);
        //$this->debugPrint("ParamBag Contents: $paramContents");
        $options = array();
        // Most parameters need to be flattened from array format, but a few
        // should remain as arrays:
        $arraySettings = array('query', 'facets', 'filters', 'groupFilters', 'rangeFilters', 'limiters');
        foreach ($params as $key => $param) {
            $options[$key] = in_array($key, $arraySettings) ? $param : $param[0];
        }
        return new SearchRequestModel($options);
    }

    /**
     * Return the record collection factory.
     *
     * Lazy loads a generic collection factory.
     *
     * @return RecordCollectionFactoryInterface
     */
    public function getRecordCollectionFactory()
    {
        return $this->collectionFactory;
    }

    /**
     * Return query builder.
     *
     * Lazy loads an empty QueryBuilder if none was set.
     *
     * @return QueryBuilder
     */
    public function getQueryBuilder()
    {
        if (!$this->queryBuilder) {
            $this->queryBuilder = new QueryBuilder();
        }
        return $this->queryBuilder;
    }

    /**
     * Set the query builder.
     *
     * @param QueryBuilder $queryBuilder Query builder
     *
     * @return void
     *
     */
    public function setQueryBuilder(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }

    /**
     * Create record collection.
     *
     * @param array $records Records to process
     *
     * @return RecordCollectionInterface
     */
    protected function createRecordCollection($records)
    {
        return $this->getRecordCollectionFactory()->factory($records);
    }

    /**
     * Obtain the authentication to use with the EDS API from cache if it exists. If not,
     * then generate a new one.
     *
     * @param bool $isInvalid whether or not the the current token is invalid
     * @param string $password EBSCO EDS API password
     * @return string
     */
    protected function getAuthenticationToken($isInvalid = false)
    {
        $token = null;
        if(!empty($this->ipAuth) && true == $this->ipAuth)
            return $token;
        $cache = $this->getServiceLocator()->get('VuFind\CacheManager')->getCache('object');
        if($isInvalid)
            $cache->setItem('edsAuthenticationToken', null);
        $authTokenData = $cache->getItem('edsAuthenticationToken');
        if(isset($authTokenData)){
            $currentToken   = isset($authTokenData['token']     ) ? $authTokenData['token']      : '';
            $expirationTime = isset($authTokenData['expiration']) ? $authTokenData['expiration'] :  0;
            $this->debugPrint("Cached Authentication data: $currentToken, expiration time: $expirationTime");

            //check to see if the token expiration time is greater than the current time.
            //if the token is expired or within 5 minutes of expiring,
            //generate a new one
            if( !empty($currentToken) && (time() <= ($expirationTime - (60*5))) )
                return $currentToken;
        }

        $username = $this->userName;
        $password = $this->password;
        $orgId = $this->orgId;
        if(!empty($username) && !empty($password))
        {
            $this->debugPrint("Calling Authenticate with username: $username, password: $password, orgid: $orgId ");
            $results = $this->client->authenticate($username, $password, $orgId);
            $token   = $results['AuthToken'];
            $timeout = $results['AuthTimeout'] + time();
            $authTokenData = array('token' => $token, 'expiration' => $timeout);
            $cache->setItem('edsAuthenticationToken', $authTokenData);
        }
        return $token;
    }


    /**
     * Print a message if debug is enabled.
     *
     * @param string $msg Message to print
     *
     * @return void
     */
    protected function debugPrint($msg)
    {
        if ($this->logger) {
            $this->logger->debug("$msg\n");
        } else {
            parent::debugPrint($msg);
        }
    }


    /**
     * Obtain the session token from the Session container. If it doesn't exist, generate a new one.
     *@param boolean $isInvalid If a session token is invalid, generate a new one regardless of what is in the session container
     *
     */
    public function getSessionToken($isInvalid = false)
    {
        $sessionToken = '';
        $container = new \Zend\Session\Container('EBSCO');
        if (!$isInvalid && !empty($container->sessionID))
            $sessionToken = $container->sessionID;
        else
        {
            $sessionToken = $this->createEBSCOSession();
            //When creating a new session, also call the INFO mehtod to pull the available search
            //criteria for this profile
            $this->createSearchCriteria($sessionToken);
        }

        $this->debugPrint("SessionToken to use: $sessionToken");
        return $sessionToken;
    }

    /**
     * Generate a new session token and store it in the Session container.
     *
     */
    protected function createEBSCOSession()
    {
        //If the user is not logged in, the treat them as a guest
        $isGuest = 'y';
        $container = new \Zend\Session\Container('EBSCO');

        //if there is no profile passed, use the one set in the configuration file
        $profile = $this->profile;
        if(null == $profile)
        {
            $config = $this->getServiceLocator()->get('VuFind\Config')->get('EDS');
            if (isset($config->EBSCO_Account->profile)) {
                $profile = $config->EBSCO_Account->profile;
            }
        }
        $session = $this->createSession($isGuest, $profile);
        $container->sessionID = $session;
        $container->profileID = $profile;
        return $container->sessionID;
    }

    /**
     * Obtain the session to use with the EDS API from cache if it exists. If not,
     * then generate a new one.
     *
     * @param string $authToken Authentication to use for generating a new session if necessary
     * @return string
     */
    public function createSession($isGuest = 'y', $profile='')
    {
        try {
            $authToken = $this->getAuthenticationToken();
            $results = $this->client->createSession($profile, $isGuest, $authToken);
        }catch(\EbscoEdsApiException $e){
            $errorCode = $e->getApiErrorCode();
            $desc = $e->getApiErrorDescription();
            $this->debugPrint("Error in create session request. Error code: $errorCode, message: $desc, e: $e");
            if( $e->getApiErrorCode() == 104 )
            {
                try {
                    $authToken = $this->getAuthenticationToken(true);
                    $results = $this->client->createSession($this->profile,  $isguest, $authToken);
                } catch(Exception $e) {
                    throw new BackendException(
                        $e->getMessage(),
                        $e->getCode(),
                        $e);
                }
            }
            else
                throw $e;
        }
        $sessionToken = $results['SessionToken'];
        return $sessionToken;
    }

    /**
     * Obtain data from the INFO method
     * @param unknown $params
     */
    public function getInfo($sessionToken = null ){
        $authenticationToken = $this->getAuthenticationToken();
        if(null == $sessionToken)
            $sessionToken = $this->getSessionToken();
        try {
            $response = $this->client->info( $authenticationToken, $sessionToken);
        } catch (\EbscoEdsApiException $e) {
            if( $e->getApiErrorCode() == 104 )
            {
                try {
                    $authenticationToken = $this->getAuthenticationToken(true);
                    $response = $this->client->info($searchModel, $authenticationToken, $sessionToken);
                } catch(Exception $e) {
                    throw new BackendException(
                        $e->getMessage(),
                        $e->getCode(),
                        $e);
                }
            } else {
                $response = array();
            }
        }
        return $response;
    }

    /**
     * Obtain available search criteria from the info method and store it in the session container
     *
     *@param string $sessionToken Session token to use to call the INFO method.
     *@return array
    */
    protected function createSearchCriteria($sessionToken){

        $container = new \Zend\Session\Container('EBSCO');
        $info = $this->getInfo($sessionToken);
        $container->info = $info;
        return $container->info;
    }

}
