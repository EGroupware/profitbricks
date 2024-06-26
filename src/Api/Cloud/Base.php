<?php
/**
 * EGroupware - IONOS Cloud API base class
 *
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @package profitbricks
 * @copyright (c) 2017-23 by Ralf Becker <rb-AT-egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Profitbricks\Api\Cloud;

use EGroupware\Api;

/**
 * Base class for IONOS Cloud API
 */
abstract class Base implements \JsonSerializable
{
	const APP = 'profitbricks';

	const CLOUD_API = 'https://api.ionos.com/cloudapi/v6/';

	/**
	 * URL relative to CLOUD_API
	 */
	const BASE = '';

	/**
	 * Unique attribute, which can be used as value in get instead of the UUID
	 */
    const UNIQ_ATTR = null;

	/**
	 * @var string UUID of item
	 */
	protected string $id;
	/**
	 * @var string type of item
	 */
	protected string $type;
	/**
	 * @var string URL of item
	 */
	protected string $href;

	/**
	 * @var array with values for keys "etag", "createdDate", ...
	 */
    protected array $metadata;

	/**
	 * @var array name => type pairs
	 */
	static protected array $properties = [];
	/**
	 * @var array name => value pairs
	 */
	static protected array $defaults = [];

	/**
	 * @var ?string path for logging requests and responses
	 */
	static public $log = null;//'/var/lib/egroupware/default/ionos-api.log';

 	protected function __construct(array $attrs, bool $check=false)
	{
		foreach($attrs+static::$defaults as $name => $value)
		{
			if ($name === 'properties')
			{
                if ($check)
                {
                    static::checkProperties($value);
                }
				foreach($value as $n => $v)
				{
                    if ($check && !property_exists($this, $n))
					{
						throw new \InvalidArgumentException("Unknown property '$name/$n'!");
					}
					$this->$n = $v;
				}
				continue;
			}
			if ($check && !property_exists($this, $name))
			{
				throw new \InvalidArgumentException("Unknown property '$name'!");
			}
			$this->$name = $value;
		}
	}

	/**
	 * Check properties are defined and of correct type
	 *
	 * @param array $attrs
	 * @throws \InvalidArgumentException for unknown or wrong typed properties
	 */
	protected static function checkProperties(array $attrs)
	{
		foreach($attrs as $name => $value)
		{
			if (!isset(static::$properties[$name]))
			{
				throw new \InvalidArgumentException("Unknown property '$name'!");
			}
			switch($type=static::$properties[$name])
			{
				default:
					$null_ok = $type[0] === '?';
					$check = 'is_'.substr($type, (int)$null_ok);
					if (!$check($value) && !($null_ok && !isset($value)))
					{
						throw new \InvalidArgumentException("Property '$name' is NO $type, got a ".gettype($value)."!");
					}
					break;
			}
		}
	}

	/**
	 * Return properties used e.g. json-serialized as body of PUT or POST requests
	 *
	 * @return array[]
	 */
	public function jsonSerialize(): array
	{
		$properties = [];
		foreach(static::$properties as $name => $type)
		{
            // set all non-null properties, plus the required ones ($type[0] !== '?')
            if (isset($this->$name) || $type[0] !== '?')
            {
                $properties[$name] = $this->$name;
            }
		}
		return (!empty($this->id) ? ['id' => $this->id] : [])+['properties' => $properties];
	}

	/**
	 * List all objects
	 *
	 * @param int $depth
	 * @param int $offset
	 * @param int $limit
	 * @return static[]
	 * @throws Api\Exception\NotFound
	 */
	static function index(int $depth=1, int $offset=0, int $limit=100) : array
	{
		$items = [];
		foreach(self::call(static::BASE, [
			'depth' => $depth,
			'offset' => $offset,
			'limit' => $limit,
		]) as $item)
		{
			$items[] = new static($item);
		}
		return $items;
	}

	/**
	 * Get item specified by its Id or another uniq attribute
     *
	 * @param string $id
	 * @param int $depth
	 * @return static
	 * @throws Api\Exception\NotFound
	 */
	static function get(string $id, int $depth=1) : static
	{
		if (!self::isUuid($id))
		{
            if (!empty($id) && !empty($attr=static::UNIQ_ATTR))
            {
	            foreach(self::call(static::BASE, ['depth' => $depth, 'filter.'.$attr => $id]) as $item)
	            {
					return new static($item);
	            }
            }
			throw new Api\Exception\NotFound("Invalid value for id: '$id' --> NOT found!");
		}
		return new static(self::call(static::BASE.'/'.$id, ['depth' => $depth]));
	}

	/**
	 * Delete item
	 *
	 * @return void
	 */
	public function delete()
	{
		if (empty($this->id))
		{
			throw new Api\Exception\NotFound("Item with id '$this->id' not found!");
		}
		self::call(static::BASE.'/'.$this->id, [], [], 'DELETE');
	}

	/**
	 * Add an item
	 * @param array $attrs
	 * @return static
	 * @throws Api\Exception\NotFound
	 */
	static function add(array $attrs) : static
	{
		// check attributes exist
		$item = new static($attrs, true);

		$data = self::call(static::BASE, [], [], 'POST', $item);

		$item->__construct($data);
		return $item;
	}

	/**
	 * Update the item
	 *
	 * @param array $attrs
	 * @return bool|resource
	 */
	public function update(array $attrs)
	{
		// check attributes
		$this->__construct($attrs, true);

		return self::call(static::BASE.'/'.$this->id, [], $this);
	}

	/**
     * Make protected properties public readonly available
     *
	 * @param string $name
	 * @return void
	 */
	public function __get(string $name)
	{
        return $this->$name;
	}

	/**
	 * Check given string is a UUID
	 *
	 * @param string $str
	 * @return bool
	 */
	static function isUuid(string $str) : bool
	{
		return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $str);
	}

	/**
	 * Log a message/request to self::$log, if set, or error_log() for $level==="error", if not
	 *
	 * @param string $message
	 * @param string $level "error" or "info"
	 * @return void
	 */
	protected static function log($message, $level='error')
	{
		if (!empty(self::$log) && ($fp = fopen(self::$log, 'a')))
		{
			fwrite($fp, date('Y-m-d H:i:s  ').strtoupper($level).' '.$message."\n");
			fclose($fp);
		}
		elseif ($level === 'error')
		{
			error_log($message);
		}
	}

	/**
	 * Get items from API
	 *
	 * @param string $what eg. "datacenters" or "datacenters/$id/servers" or full URL eg. self::AUTH_API.'token/generate'
	 * @param array $get_params
	 * @param array $headers
	 * @param string $method "GET" (default), "POST", "PUT" or "DELETE"
	 * @param string|array|object $body
	 * @return array|string with items, for collection or just response data otherwise, or empty body for DELETE or 204 No Content
	 * @throws Api\Exception on connection error
	 * @throws Api\Exception\NotFound on non 2xx status
	 */
	protected static function call(string $what, array $get_params=[], array $headers=[], $method='GET', $body='', float $timeout=2)
	{
		$url = (substr($what, 0,8) === 'https://'?'':self::CLOUD_API).$what;
		foreach($get_params+['depth'=>0] as $name => $value)
		{
			$url .= (strpos($url, '?') === false ? '?' : '&').$name.'='.urlencode($value);
		}
		if (in_array($method, ['POST', 'PUT', 'PATCH']))
		{
			if (!is_string($body))
			{
				$body = json_encode($body);
				$headers['Content-Type'] = 'application/json';
			}
		}
		else
		{
			$body = '';
		}
		if (!($f = self::httpOpen($url, $method, $body, $headers, $timeout)))
		{
			self::log("Request to '$url' failed", 'error');
			throw new Api\Exception("Request to '$url' failed", 2);
		}
		$response = '';
		do {
			$response .= stream_get_contents($f);
		}
		while (!feof($f));
		fclose($f);
		$response_body = self::parseResponse($response, $response_headers);
		// empty body and non 2xx http-status --> throw
		$http_status = preg_match('#^HTTP/\d\.\d (\d+) #', $response_headers[0], $matches) ? (int)$matches[1] : null;
		if ($response_body === '' && ($http_status < 200 || $http_status >= 300))
		{
			self::log("Request to '$url' failed with $response_headers[0]", 'error');
			throw new Api\Exception\NotFound("Request to '$url' failed with $response_headers[0]", $http_status);
		}
		// empty body and DELETE or 204 No Content return empty body
		if ($response_body === '' && ($method === 'DELETE' || $http_status === 204 /* No Content */))
		{
			return $response_body;
		}
		// otherwise decode JSON
		if (!($data = json_decode($response_body, true)) ||
			empty($data['type']) && empty($data['token']) ||
			$data['type'] === 'collection' && (!isset($data['items']) || !is_array($data['items'])))
		{
			self::log("Request to '$url' failed: ".$response, 'error');
			if (isset($data['messages']))
            {
                $messages = ': '.implode(', ', array_map(static function(array $message)
                {
                    return $message['message'].' ('.$message['errorCode'].')';
                }, $data['messages']));
            }
			self::log("Request to '$url' failed".($messages??'!'), 'error');
			throw new Api\Exception\NotFound("Request to '$url' failed".($messages??'!'), $data['httpStatus'] ?? 2);
		}

		return $data['type'] == 'collection' ? $data['items'] : $data;
	}

	/**
	 * Open HTTP request
	 *
	 * @param string|array $url string with url or already passed like return from parse_url
	 * @param string $method ='GET'
	 * @param string $body =''
	 * @param array $header =array() additional header like array('Authentication' => 'basic xxxx')
	 * @param float $timeout =2 0 for async connection
	 * @return resource|boolean socket still in blocking mode
	 */
	protected static function httpOpen($url, $method='GET', $body='', array $header=array(), float $timeout=2)
	{
		if (empty($header['Authorization']) &&
			(empty(self::$config['username']) || empty(self::$config['password'])) &&
			empty(self::$config['token']))
		{
			Api\Egw::redirect_link('/index.php','menuaction=admin.admin_config.index&appname=profitbricks&ajax=true', 'admin');
		}
		// add default authentication header
		$header += ['Authorization' => !empty(self::$config['token']) ? 'Bearer '.self::$config['token'] :
			'Basic '.base64_encode(self::$config['username'].':'.self::$config['password'])];

		$parts = is_array($url) ? $url : parse_url($url);
		$addr = ($parts['scheme'] == 'https'?'ssl://':'tcp://').$parts['host'].':';
		$addr .= isset($parts['port']) ? (int)$parts['port'] : ($parts['scheme'] == 'https' ? 443 : 80);
		$errno = $errstr = null;
		if (!($sock = stream_socket_client($addr, $errno, $errstr, $timeout,
			$timeout ? STREAM_CLIENT_CONNECT : STREAM_CLIENT_ASYNC_CONNECTC)))
		{
			self::log(__METHOD__."('$url', ...) stream_socket_client('$addr', ...) $errstr ($errno)", 'error');
			return false;
		}
		$request = $method.' '.$parts['path'].(empty($parts['query'])?'':'?'.$parts['query'])." HTTP/1.1\r\n".
			"Host: ".$parts['host'].(empty($parts['port'])?'':':'.$parts['port'])."\r\n".
			"User-Agent: ".__CLASS__."\r\n".
			"Accept: application/json\r\n".
			"Cache-Control: no-cache\r\n".
			"Pragma:no-cache\r\n".
			"Connection: close\r\n";

		// Content-Length header is required for methods containing a body
		if (in_array($method, array('PUT','POST','PATCH')))
		{
			$header['Content-Length'] = strlen($body);
		}
		foreach($header as $name => $value)
		{
			$request .= $name.': '.$value."\r\n";
		}
		$request .= "\r\n";

		// do NOT log content of Authorization header
		self::log(preg_replace('/^Authorization: ([^ ]+) .*$/mi', 'Authorization: $1 ********', $request), 'info');

		if (fwrite($sock, $request.$body) === false)
		{
			self::log(__METHOD__."('$url', ...) error sending request!", 'error');
			fclose($sock);
			return false;
		}
		return $sock;
	}

	/**
	 * Parse body from HTTP response and de-chunk it if necessary
	 *
	 * @param string $response
	 * @param ?array& $headers headers on return, lowercase name => value pairs
	 * @return string body of response
	 */
	protected static function parseResponse($response, array &$headers=null)
	{
		list($header, $body) = explode("\r\n\r\n", $response, 2);
		$headers = array();
		foreach(explode("\r\n", $header) as $line)
		{
			$parts = preg_split('/:\s*/', $line, 2);
			if (count($parts) == 2)
			{
				$headers[strtolower($parts[0])] = $parts[1];
			}
			else
			{
				$headers[] = $parts[0];
			}
		}
		// de-chunk body if necessary
		if (isset($headers['transfer-encoding']) && $headers['transfer-encoding'] == 'chunked')
		{
			$chunked = $body;
			$body = '';
			while($chunked && (list($size, $chunked) = explode("\r\n", $chunked, 2)) && $size)
			{
				$body .= substr($chunked, 0, hexdec($size));
				if (true) $chunked = substr($chunked, hexdec($size)+2);	// +2 for "\r\n" behind chunk
			}
		}
		// log the de-chunked body
		self::log($header."\r\n\r\n".$body, 'info');
		return $body;
	}

	protected static array $config;

	/**
	 * Initialize our static variables / config
	 *
	 * @throws Api\Exception\WrongParameter
	 */
	public static function initStatic()
	{
		self::$config = Api\Config::read(self::APP);

		// generate or renew token, if it's about to expire
		if (!empty(self::$config['username']) && !empty(self::$config['password']) && empty(self::$config['token']) ||
			!empty(self::$config['token']) && \profitbricks_api::jwtExpires(self::$config['token'], '2month'))
		{
			if (($token = \profitbricks_api::tokenGenerate()))
			{
				Api\Config::save_value('token', self::$config['token']=$token, self::APP);
				Api\Config::save_value('password', self::$config['password']=null, self::APP);
			}
		}
	}
}

Base::initStatic();