<?php
/*
 * Copyright (c) 2012 Go Daddy Operating Company, LLC
 *
 * Permission is hereby granted, free of charge, to any person obtaining a
 * copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 */

namespace GDAPI;

class Client
{
  const VERSION = "1.0.4";

  const MIME_TYPE_JSON = 'application/json';

  /*
   * An instance of a class that implements CacheInterface.  If set,
   * it will be used to cache schemas so they are not requested repeatedly.
   *
   * @see setCache
   */
  static $cache = false;

  /*
   * Default options.  Do not edit this file to change these.
   * All the options can be overridden in the Client constructor.
   */
  static $defaults = array(
    /*
     * Responses are returned as abjects.  You can choose the class that is returned
     * for each response type.  Anything that isn't mapped will be returned as
     * an instance of the default_class (below).  Classes that you do map should
     * probably be a sub-class of Error, Collection, or Resource.
     */
    'classmap'            => array(
      'collection'        => '\GDAPI\Collection',
      'error'             => '\GDAPI\Error',
    ),

    /*
     * Behavior when errors occur:
     *  true:  An APIException (or subclass) will be thrown on errors.
     *  false: A response will be returned as an instance of the 'error' type defined in the classmap.
     */
    'throw_exceptions'    => true,

    /*
     * Optional prefix to add to cache keys
     */
    'cache_namespace'     => '',

    /* --------------------*
     * Less common options *
     * ------------------- */

    /*
     * Responses will be returned as instances of this class by default
     */
    'default_class'       => '\GDAPI\Resource',

    /*
     * HTTP client class to use.  Clients must implement the RequestInterface interface
     */
     'request_class'      => '\GDAPI\CurlRequest',

    /*
     * If set, the schema definition will be loaded from this file path instead of the base URL.
     */
    'schema_file'         => '',

    /*
     * Verify SSL certificate when connecting
     */
    'verify_ssl'          => true,

    /*
     * Path to a PEM file containing certificate authorities that are trusted
     */
    'ca_cert'             => '',

    /*
     * Path to a directory containing certificate authorities that are trusted
     */
    'ca_path'             => '',

    /*
     * Timeout for establishing an initial connection to the API, in seconds.
     *  cURL >= 7.16.2 support floating-point values with millisecond resolution.
     */
    'connect_timeout'     => 5,

    /*
     * Timeout for receiving a response, in seconds.
     *  cURL >= 7.16.2 support floating-point values with millisecond resolution.
     */
    'response_timeout'    => 300,

    /*
     * Time to keep HTTP connections open, in seconds.
     */
    'keep_alive'          => 120,

    /*
     * Enable GZIP compression of responses.
     */
    'compress'            => true,

    /*
     * Network interface name, IP address, or hotname to use for HTTP requests
     */
    'interface'           => '',

    /*
     * Name => Value mapping or HTTP headers to send with every request.
     */
    'headers'             => array(
      'Accept'  => self::MIME_TYPE_JSON,
    ),

    /* -------------------------*
     * Even less common options *
     * ------------------------ */

    /*
     * Follow HTTP redirect responses
     */
    'follow_redirects'    => true,

    /*
     * Limit to how many HTTP redirects will be followed before giving up.
     */
    'max_redirects'       => 10,

    /*
     * The attribute to look at to determine the class a response should be mapped to.
     */
    'type_attr'           => 'type',

    /*
     * The maximum depth of a JSON object when parsing
     */
    'json_depth_limit'    => 50,

    /*
     * Path to a PEM file containing a client certificate to use for requests.
     */
    'client_cert'         => '',

    /*
     * Path to a PEM file containing the key for the client certificate.
     * The cert and key may be in the same file, if desired.
     */
    'client_cert_key'     => '',

    /*
     * Password for the certificate key, if needed.
     * Can be provided as a string, or an anonymous function that returns a string.
     */
    'client_cert_pass'    => '',
  );

  /*
   * An array of active clients.
   * Only one instance per [base_url,access_key,secret_key] combination will be created.
   */
  static $clients = array();

  /*
   * A unique identifier for the [base_url,access_key,secret_key] combination
   */
  protected $id;

  /*
   * The base URL for the API.  Should include a version.
   */
  protected $base_url;

  /*
   * Options for this client
   */
  protected $options;

  /*
   * The schema loaded for this API
   */
  protected $schemas;

  /*
   * An instance of a class that implements RequestInterface, to make REST requests.
   */
  protected $requestor;

  /*
   * Create a new Client.
   *
   * @param string $base_url:   The root URL for the API you want to connect to.  This should include version.
   * @param mixed  $access_key: The access key for your API user, or an anonymous function that returns it.
   * @param mixed  $secret_key: The secret key for your API user, or an anonymous function that returns it.
   * @param array  $options:    A hash map of options to override the default options (see static::$defaults).
   *
   * @returns \GDAPI\Client
   */
  public function __construct($base_url, $access_key, $secret_key, $options=array())
  { $this->id = md5($base_url);
    $this->base_url = $base_url;
    $this->options = array_replace_recursive(static::$defaults, $options);

    $request_class = $this->options['request_class'];
    $this->requestor = new $request_class($this, $this->base_url, $this->options);

    if ( $access_key && $secret_key )
    {
      $this->requestor->setAuth(static::resolvePassword($access_key), static::resolvePassword($secret_key));
    }

    $this->loadSchemas();
    
    static::$clients[$this->id] = $this;
  }
  
  /*
   * Destruct
   */
  public function __destruct()
  {
    unset(static::$clients[$this->id]);
  }

  /*
   * Returns a password from a (possible) function that returns one
   *
   * @param string $str_or_fn A password string, or a function that returns one
   *
   * @returns string Password
   */
  static function resolvePassword($str_or_fn)
  {
    if ( is_string($str_or_fn) )
    {
      return $str_or_fn;
    }
    else
    {
      return $str_or_fn();
    }
  }

  /*
   * Get an instance of a client by id
   *
   * @param string $id: The client id
   * 
   * @return object Client
   */
  static function &get($id)
  {
    if ( isset(static::$clients[$id]) )
    {
      return static::$clients[$id];
    }
    
    return null;
  }

  /*
   * Set the caching implementation
   *
   * @param object $class The caching object to use
   */
  static function setCache($class)
  {
    static::$cache = $class;
  }

  /*
   * Load a schema and create Type objects
   *
   * @param string $path The path for the schema, relative to base_url
   */
  protected function loadSchemas($path='/')
  {
    $res = false;
    $ns = $this->options['cache_namespace'];
    $cache_key = 'restclient_'. ($ns ? $ns.'_' : '') . $this->id .'_'. $path;
    $cache = static::$cache;

    // Fake a schema response with a file, if given
    if ( $this->options['schema_file'] )
    {
      $res = new Resource($this->id, json_decode(file_get_contents($this->options['schema_file'])));
    }

    // Try the cache
    if ( $cache && !$res )
    {
      $res = $cache::get($cache_key);
    }

    // Make a request for it
    if ( !$res )
    {
      $res = $this->requestor->request('GET', $path);
    }

    if ( !$res )
    {
      return $this->error('Unable to load API schema', 'schema');
    }

    $resTypes = array();
    if ( $res->metaIsSet('resourceTypes') )
    {
      $resTypes = (array)$res->getResourceTypes();  
    }

    if ( $res->getType() == 'apiversion' && $res->getLink('schemas') )
    {
      // The response was the root of a version, with a link to the schemas
      return $this->loadSchemas($res->getLink('schemas'));
    }
    elseif ( $res instanceof Collection && isset($resTypes['apiversion']) )
    {
      // The response was an API root with no version
      return $this->error('The base URL "'. $this->base_url.$path .'" does not specify an API version to use');
    }
    else if ( $res instanceof Collection && isset($resTypes['schema']) )
    {
      // The response was a list of schemas

      $this->base_url = $res->getLink('root');

      // Setup all the types
      $this->types = array();
      for ( $i = 0 ; $i < count($res) ; $i++ )
      {
        $schema = $res[$i];
        $this->types[ $schema->getId() ] = new Type($this->id, $schema);
      }

      if ( $cache )
      {
        $cache::set($cache_key, $res);
      }
    }
    else
    {
      // No idea what the response is
      return $this->error('The base URL "'. $this->base_url.$path .'" does not look like an API version','schema');
    }
  }

  /*
   * Magic method to get a type
   *
   * @param string $name The type name
   * 
   * @return object Type
   */
  public function __get($name)
  {
    if ( !isset($this->types, $this->types[$name]) )
    {
      return $this->error('There is no type for "'. $name . '" defined in the schema', 'type');
    }

    return $this->types[$name];
  }

  /*
   * Get all the types defined.
   *
   * @return array Types
   */
  public function getTypes()
  {
    return $this->types;
  }

  /*
   * Perform a request
   *
   * @see RequestInterface
   */
  public function request($method, $path, $qs=array(), $body=null, $content_type=false)
  {
    $requestor = $this->getRequestor();
    return $requestor->request($method,$path,$qs,$body,$content_type);
  }

  /*
   * Gets the requestor object
   *
   * @return object Requestor
   */
  public function getRequestor()
  {
    return $this->requestor;
  }

  /*
   * Convert responses into the appropriate classes
   *
   * @param mixed   $data   The response data to be converted
   * 
   * @return object Type
   */
  public function classify($data)
  {
    return $this->classifyRecursive($data,0,'auto');
  }

  /*
   * Convert responses into the appropriate classes
   *
   * @param mixed   $data   The response data to be converted
   * @param int     $depth  The recursion depth; do not set directly. 
   * @param string  $depth  What type to treat the data as; do not set directly.
   * 
   * @return object Type
   */
  protected function classifyRecursive($data, $depth=0, $as='auto')
  {
    if ( $as == 'auto' )
    {
      if ( is_object($data) )
      {
        $as = 'object';
      }
      elseif ( is_array($data) )
      {
        $as = 'array';
      }
      else
      {
        $as = 'scalar';
      }
    }

    if ( $as == 'object' )
    {
      $type = '';
      $type_attr = $this->options['type_attr'];
      if ( isset($data->{$type_attr}) )
      {
        $type = $data->{$type_attr};
        if ( isset( $this->options['classmap'][$type] ) )  
        {
          $type = $this->options['classmap'][$type];
        }
      }

      if ( ! class_exists($type) )
      {
        $type = $this->options['default_class'];
      }

      // Classify links, data, etc 
      foreach ( $data as $k => &$v)
      {
        if ( is_array($v) )
        {
          $data->{$k} = $this->classify($v, $depth+1);
        }
      }

      $out = new $type($this->id, $data);
    }
    elseif ( $as == 'array' )
    {
      $out = array();
      foreach ( $data as $k => &$v )
      {
        $out[$k] = $this->classifyRecursive($v,$depth+1);
      }
    }
    else
    {
      $out = $data;
    }

    return $out;
  }

  /*
   * Throw errors
   *
   * @param string $message  The error message
   * @param string $status   The error code or HTTP status
   * @param array  $body     The response or error details
   *
   * @throws APIException
   */
  public function error($message, $status, $body=array())
  {
    if ( $this->options['throw_exceptions'] )
    {
      $class = '\GDAPI\APIException';

      if ( isset(APIException::$status_map[$status]) )
      {
        $class = APIException::$status_map[$status];
      }

      if ( !is_int($status) )
      {
        $status = -1;
      }

      $err = new $class($message, $status, $body);
      throw $err;
    }
    else
    {
      return $body;
    }
  }

  /*
   * Get metadata about the last request & response
   * 
   * @returns array
   */
  public function getMeta()
  {
    return $this->requestor->getMeta();
  }
}

?>
