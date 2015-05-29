<?php

namespace Craft;

use Guzzle\Http\Client;
use Guzzle\Http\Message\EntityEnclosingRequest;
use Guzzle\Http\Exception\RequestException;

class Placid_RequestsService extends PlacidService
{

  protected $requestRecord;
  protected $placid_settings;
  protected $segments;
  protected $cache;
  protected $cacheLength;
  protected $method;
  protected $query;
  private $token;

  public function __construct($record = null)
  {

    parent::__construct();

    $this->record = $record;
    $this->model = new Placid_RequestsModel;

    if(is_null($this->record))
    {
      $this->record = Placid_RequestsRecord::model();
    }
    // Get the plugin settings
    $this->placid_settings = $this->settings;
  }


  public function getOptions($options = array())
  {
    $this->segments = $this->_setOption($options, 'segments');
    $this->method = $this->_setOption($options, 'method', 'GET');
    $this->cacheLength = $this->_setOption($options, 'duration');
    $this->query =  $this->_setOption($options, 'query');
    $this->query = $this->_setOption($options, 'params', $this->query); // This needs to be deprecated
    $this->cache = $this->_setOption($options, 'cache', $this->placid_settings['cache']);

    return $this;
  }

  public function _setOption($options, $key, $default = null)
  {
    $option = (array_key_exists($key, $options) ? $options[$key] : $default);
    return $option;
  }
  /**
  * Make the request
  *
  * This method will create a new client and get a response from a Guzzle request
  *                                   
  * @param string|null      $handle     The handle of the request record
  *
  * @return array   the req
  */

  public function request($handle)
  {

    $client = new Client();

    $record = $this->findRequestByHandle($handle);
    
    // Get a cached request

    $request = $this->_createRequest($client, $record);
    $cachedRequest = craft()->placid_cache->get(base64_encode( urlencode( $request->getUrl() ) ));


    Craft::import('plugins.placid.events.PlacidBeforeRequestEvent');

    $event = new PlacidBeforeRequestEvent($this, array('request' => $request));

    craft()->placid_requests->onBeforeRequest($event);

    if($event->makeRequest)
    {

      if( (! $this->cache || ! $cachedRequest) && ! $event->bypassCache)
      {

        $response = $this->_getResponse($client, $request);
      }
      else
      {
        $response = $cachedRequest;
      }
    }
    else {
      return false;
    }

    Craft::import('plugins.placid.events.PlacidAfterRequestEvent');

    $event = new PlacidAfterRequestEvent($this, array('response' => $response));

    craft()->placid_requests->onAfterRequest($event);

    return $response;
    
  }
  
  /**
  * Create a new model object of a request
  *                                   
  * @param array     $attributes  The attributes to save against the model 
  *
  * @return model    returns Placid_RequestsModel object
  *          
  */

  public function newRequest($attributes = array())
  {

    // Create the new Placid_RequestsModel
    // -----------------------------------------------------------------------------
    $model = new Placid_RequestsModel();

    // Set the attributes from the array
    $model->setAttributes($attributes);

    // Return the Placid_RequestsModel model
    return $model;
  }


  /**
   * Get all placid requests
   *
   * @return requests model object 
   */

  public function getAllRequests()
  {
    $args = array('order' => 't.id');
    $records = $this->record->findAll($args);
    return Placid_RequestsModel::populateModels($records, 'id');
  }

  /**
  * Find request by ID
  *
  * @param string $id 
  * @deprecated Deprecated in 1.3. Use {@link AppBehavior::getBuild() craft()->placid_requests->getById()} instead. All these sort of methods are being combined for a more streamlined, DRY API.
  * @return request model object
  */
  public function findRequestById($id)
  {

   // Determine if there is a request record and return it
   // -----------------------------------------------------------------------------

   if($record = $this->record->findByPk($id))
   {
     $params = $record->getAttribute('params');
     $decodedParams = unserialize(base64_decode($params));
     $record->setAttribute('params', $decodedParams);
     return Placid_RequestsModel::populateModel($record);
   }
 }

  /**
  * Return the request
  *
  * @param string $handle
  *
  * @param array $options
  *
  * @throws Exception
  *
  * @return mixed
  */

  public function findRequestByHandle($handle)
  {
    Craft::log(__METHOD__, LogLevel::Info, true);

    // Get the request record by its handle
    // ---------------------------------------------

    $record =  $this->record->find(
      'handle=:handle',
      array(
        ':handle' => $handle
        )
      );

    if($record)
    {
      return Placid_RequestsModel::populateModel($record);
    }
    else
    {
      throw new Exception(Craft::t('Can\'t find request with handle "{handle}"', array('handle' => $handle)));
    }
  }

  /**
  * Create a new request object
  *                                   
  * @param array     $attributes  The attributes to save against the model 
  *
  * @return object    returns EntityEnclosingRequest $request
  *          
  */
  private function _createRequest($client, $record)
  {
    $request = $client->createRequest($this->method, $record->getAttribute('url'));

    if($this->segments)
    {
      $request->setPath($this->segments);
    }

    $query = $request->getQuery();

    // Get the parameters from the record
    $cpQuery = json_decode($record->getAttribute('params'));
    
    // If they exist, add them to the query
    if($cpQuery)
    {
      foreach($cpQuery as $k => $q)
      {
        $query->set($q['key'], $q['value']);
      }
    } 
    elseif($this->query)
    {
      foreach($this->query as $key => $value)
      {
        $query->set($key, $value);
      }
    }

    if($provider = $record->getAttribute('oauth'))
    {
      $this->_authenticate($request,$provider);
    }

    if($tokenId = $record->getAttribute('tokenId'))
    {
      $tokenModel = craft()->placid_token->findTokenById($tokenId);
      $token = $tokenModel->getAttribute('encoded_token');
      $query->set('access_token', $token);
    }

    
    return $request;
  }

  /**
   * Get the response from a client and request
   *
   * @param Client $client a guzzle client
   * @param object $request a guzzle request object
   * @return array the response
   */

  private function _getResponse(Client $client, $request)
  {
    try {
      $response = $client->send($request);
    } catch(RequestException $e) {
      // If we are in devmode, return the error message
      Craft::log('Placid - ' . $e->getMessage(), LogLevel::Error);

      $message = array('failed' => true);

      if(method_exists($e, 'getResponse'))
      {
        $response = $e->getResponse();
        $message['statusCode'] = $response->getStatusCode();
      }

      if(craft()->request->isAjaxRequest())
      {
        return $message;
      }
      else {
        return false;
      }
      
    }

    if($this->cache)
    {
      craft()->placid_cache->set($request->getUrl(), $response->json(), $this->cacheLength);
    }

    return $response->json();
  }
  /**
   * Authenticate the request, used if OAuth provider is chosen on request creation
   *
   * @param string $auth
   * @param object $client
   * @return boolean
   */

  private function _authenticate($client, $auth)
  {

    $provider = craft()->oauth->getProvider($auth);
    $token = craft()->placid_oAuth->getToken($auth);
    
    $provider->setToken($token);

    $subscriber = $provider->getSubscriber();

    $client->addSubscriber($subscriber);

  }

  // Record Methods
  // =============================================================================

  /**
   * Save a request
   *
   * @param object RequestsModel object
   *
   * @return bool true or false if request has been saved
   */

  public function saveRequest(Placid_RequestsModel &$model)
  {
    // Determine whether this is an existing request or if we need to create a new one
    // --------------------------------------------------------------------------------

    if($id = $model->getAttribute('id')) 
    {
      $record = $this->record->findByPk($id);
    } 
    else 
    {
      $record = $this->record->create();
    }

    // Get the attributes from the passed model
    $attributes = $model->getAttributes();

    // Set the new attributes to the record
    $record->setAttributes($attributes, false);

    // Save the new request
    // -----------------------------------------------------------------------------

    if($record->save())
    {
      $model->setAttribute('id', $record->getAttribute('id'));
      return true;
    } 
    else 
    {
      $model->addErrors($record->getErrors());
      return false;
    }
  } 

  /**
  * Get the token from a provider
  *                                   
  * @param string|null      $provider     The handle of the provider
  *
  * @return string   the token if the method was successful. 
  * A null value will be returned if no token exists
  */

  public function getToken($provider = null)
  {

    if($this->token)
    {
      return $this->token;
    }
    else
    {
          // get settings
      $settings = $this->placid_settings;

          // get tokenId
      $tokenId = $settings[$provider];

          // get token
      $token = craft()->oauth->getTokenById($tokenId);
      if($token && $token->token)
      {
        $this->token = $token;
        return $this->token;
      }

      return null;
    }
  }
  /**
   * Delete a request from the database.
   *
   * @param  int $id
   * @return int The number of rows affected
   */
  public function deleteRecordById($id)
  {
    return $this->record->deleteByPk($id);
  }

  // Dukt OAuth Methods
  // =============================================================================

  /**
   * Save the token
   *                                   
   * @param string          $token  The token which needs to be saved
   *
   * @param string|null     $provider The provider handle    
   *
   * @return boolean        true if token is saved
   *          
   */

  public function saveToken($token, $provider = null)
  {
      // get plugin
    $plugin = craft()->plugins->getPlugin('placid');

      // get settings
    $settings = $this->placid_settings;

      // get tokenId
    $tokenId = $settings[$provider];

      // get token
    $model = craft()->oauth->getTokenById($tokenId);

      // populate token model
    if(!$model)
    {
      $model = new Oauth_TokenModel;
    }

    $model->providerHandle = $provider;
    $model->pluginHandle = 'placid';
    $model->encodedToken = craft()->oauth->encodeToken($token);

      // save token
    craft()->oauth->saveToken($model);

      // set token ID
    $settings[$provider] = $model->id;

      // save plugin settings
    craft()->plugins->savePluginSettings($plugin, $settings);

    return true;
  }

  // Events
  // =============================================================================

  /**
  * Fires an 'onBeforeRequest' event.
  *
  * @param PlacidBeforeRequestEvent $event
  */
  public function onBeforeRequest(PlacidBeforeRequestEvent $event)
  {
    $this->raiseEvent('onBeforeRequest', $event);
  }

  /**
  * Fires an 'onAfterRequest' event.
  *
  * @param PlacidAfterRequestEvent $event
  */
  public function onAfterRequest(PlacidAfterRequestEvent $event)
  {
    $this->raiseEvent('onAfterRequest', $event);
  }

}